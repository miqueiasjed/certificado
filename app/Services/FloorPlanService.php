<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Device;
use App\Models\DevicePosition;
use App\Models\FloorPlan;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Planta versionada de um endereço e posição de cada dispositivo sobre ela
 * (Plano 21, Task 21.4).
 *
 * Quatro decisões sustentam o resto:
 *
 * - **Versão anterior nunca é apagada nem tem posição movida.** Trocar de
 *   planta cria uma linha nova em `floor_plans` com `versao` maior; a
 *   anterior fica com `ativa = false` e `substituida_em` preenchido, mas
 *   continua no banco com as próprias `device_positions` intactas. Um
 *   relatório emitido há um ano precisa continuar refletindo o layout
 *   daquela época.
 * - **Substituir copia as posições, não começa do zero.** Um endereço com 40
 *   dispositivos posicionados não pode virar 40 cliques perdidos porque o
 *   layout mudou um pouco. `substituir()` copia a posição de cada
 *   dispositivo da versão anterior para a nova, e o usuário ajusta o que
 *   mudou.
 * - **Tipo de arquivo é validado pelo conteúdo, nunca pela extensão.** Mesmo
 *   cuidado de segurança já usado em `WorkOrderPhotoController`: o Service
 *   confere `UploadedFile::getMimeType()`, que o Symfony resolve a partir do
 *   conteúdo real (fileinfo), e não confia na extensão nem no mimetype que o
 *   cliente informou.
 * - **Dispositivo substituído (Plano 11) herda a posição do anterior.** O
 *   ponto físico de instalação é o mesmo depois da troca; `herdarPosicao()` é
 *   chamado por `DeviceReplacementService::substituir()` assim que o
 *   dispositivo novo é criado.
 *
 * Este Service não consulta `Auth` nem resolve empresa: quem chama entrega os
 * models já escopados, e o isolamento entre empresas vem do escopo global de
 * `FloorPlan`/`DevicePosition`/`Device` (`BelongsToCompany`).
 */
class FloorPlanService
{
    /**
     * Disco de armazenamento do arquivo da planta. Mesmo disco público usado
     * por `WorkOrderPhotoController` para foto de OS, servido por
     * `php artisan storage:link`.
     */
    public const DISCO = 'public';

    /**
     * Tamanho máximo do arquivo da planta, em bytes: 10 MB. Espelha
     * `StoreFloorPlanRequest::TAMANHO_MAXIMO_KB`; conferido de novo aqui
     * porque o Service processa arquivo mesmo quando chamado fora de uma
     * requisição HTTP validada por aquele FormRequest.
     */
    public const TAMANHO_MAXIMO_BYTES = 10 * 1024 * 1024;

    /**
     * Tipos aceitos, por tipo MIME real do conteúdo (não pela extensão),
     * mapeados para a extensão gravada no disco.
     *
     * @var array<string, string>
     */
    private const TIPOS_ACEITOS = [
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
        'application/pdf' => 'pdf',
    ];

    /**
     * Envia a planta de um endereço.
     *
     * Sem planta com este nome ainda para o endereço, nasce a versão 1. Já
     * existindo, nasce a próxima versão e a anterior fica com `ativa = false`
     * e `substituida_em` preenchido - sem apagar nada e sem mexer nas
     * posições que a versão anterior já tinha.
     *
     * @param  array{nome: string, observacao?: ?string}  $dados
     *
     * @throws ValidationException Nome em branco, arquivo de tipo não aceito,
     *                             maior que 10 MB, ou PDF de mais de uma
     *                             página.
     */
    public function enviar(Address $endereco, UploadedFile $arquivo, array $dados): FloorPlan
    {
        $nome = trim((string) ($dados['nome'] ?? ''));

        if ($nome === '') {
            throw ValidationException::withMessages([
                'nome' => 'Informe o nome da planta.',
            ]);
        }

        return DB::transaction(function () use ($endereco, $arquivo, $dados, $nome): FloorPlan {
            // Trava a última versão existente (se houver) antes de decidir o
            // número da versão nova: duas requisições enviando a mesma planta
            // ao mesmo tempo não podem gerar duas linhas com a mesma
            // `versao`, mesma preocupação de
            // `DeviceReplacementService::substituir()`.
            $anterior = FloorPlan::query()
                ->where('address_id', $endereco->getKey())
                ->where('nome', $nome)
                ->orderByDesc('versao')
                ->lockForUpdate()
                ->first();

            $novaVersao = $this->criarVersao(
                $endereco->getKey(),
                $nome,
                $arquivo,
                $dados['observacao'] ?? null,
                ($anterior?->versao ?? 0) + 1
            );

            if ($anterior !== null) {
                $anterior->update(['ativa' => false, 'substituida_em' => now()]);
            }

            return $novaVersao;
        });
    }

    /**
     * Substitui a planta ativa por uma versão nova, copiando as posições da
     * anterior para o usuário ajustar em vez de recomeçar do zero.
     *
     * As posições da versão anterior nunca são movidas nem apagadas: a cópia
     * cria linhas novas em `device_positions`, presas à versão nova.
     *
     * @throws ValidationException `$planta` não é mais a versão ativa (outra
     *                             substituição já aconteceu), ou o arquivo é
     *                             inválido.
     */
    public function substituir(FloorPlan $planta, UploadedFile $arquivo): FloorPlan
    {
        return DB::transaction(function () use ($planta, $arquivo): FloorPlan {
            // Trava a linha antes de decidir qualquer coisa, mesma cautela de
            // `enviar()`.
            $emBanco = FloorPlan::query()
                ->whereKey($planta->getKey())
                ->lockForUpdate()
                ->first() ?? $planta;

            if (! $emBanco->ativa) {
                throw ValidationException::withMessages([
                    'planta' => 'Esta versão da planta não é mais a versão ativa. '
                        .'Atualize a página e substitua a partir da versão atual.',
                ]);
            }

            $proximaVersao = (int) FloorPlan::query()
                ->where('address_id', $emBanco->address_id)
                ->where('nome', $emBanco->nome)
                ->max('versao') + 1;

            $novaVersao = $this->criarVersao(
                $emBanco->address_id,
                $emBanco->nome,
                $arquivo,
                $emBanco->observacao,
                $proximaVersao
            );

            $emBanco->update(['ativa' => false, 'substituida_em' => now()]);

            $this->copiarPosicoes($emBanco, $novaVersao);

            return $novaVersao;
        });
    }

    /**
     * Grava em lote a posição de cada dispositivo sobre a planta.
     *
     * Cada `device_id` precisa pertencer ao mesmo endereço da planta - o
     * escopo global por empresa já impede o id de outro tenant, e a checagem
     * aqui cobre o endereço errado dentro da mesma empresa. `x` e `y`
     * precisam ser estritamente maiores que 0 e menores que 1, mesma
     * definição de `DevicePosition`.
     *
     * Tudo ou nada: se qualquer posição do lote for inválida, nenhuma é
     * gravada.
     *
     * @param  array<int, array{device_id: int|string, x: int|float|string, y: int|float|string, rotulo_visivel?: bool}>  $posicoes
     * @return Collection<int, DevicePosition>
     *
     * @throws ValidationException Dispositivo fora do endereço da planta, ou
     *                             `x`/`y` fora do intervalo aberto (0, 1).
     */
    public function salvarPosicoes(FloorPlan $planta, array $posicoes): Collection
    {
        if ($posicoes === []) {
            throw ValidationException::withMessages([
                'posicoes' => 'Informe ao menos uma posição para salvar.',
            ]);
        }

        $deviceIds = collect($posicoes)
            ->pluck('device_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $dispositivosDoEndereco = Device::query()
            ->whereIn('id', $deviceIds)
            ->where('address_id', $planta->address_id)
            ->pluck('id');

        $foraDoEndereco = $deviceIds->diff($dispositivosDoEndereco);

        if ($foraDoEndereco->isNotEmpty()) {
            throw ValidationException::withMessages([
                'posicoes' => sprintf(
                    'O dispositivo %s não pertence ao endereço desta planta.',
                    $foraDoEndereco->implode(', ')
                ),
            ]);
        }

        foreach ($posicoes as $posicao) {
            $this->garantirFracaoValida($posicao['x'] ?? null, 'x');
            $this->garantirFracaoValida($posicao['y'] ?? null, 'y');
        }

        return DB::transaction(function () use ($planta, $posicoes): Collection {
            return collect($posicoes)->map(
                fn (array $posicao): DevicePosition => DevicePosition::query()->updateOrCreate(
                    [
                        'floor_plan_id' => $planta->getKey(),
                        'device_id' => (int) $posicao['device_id'],
                    ],
                    [
                        'x' => $posicao['x'],
                        'y' => $posicao['y'],
                        'rotulo_visivel' => array_key_exists('rotulo_visivel', $posicao)
                            ? (bool) $posicao['rotulo_visivel']
                            : true,
                    ]
                )
            )->values();
        });
    }

    /**
     * Dispositivos ativos do endereço sem posição na versão ATIVA da planta.
     *
     * Recebe qualquer versão de `FloorPlan` (não precisa ser a ativa): quando
     * `$planta` não é a versão corrente, a lista é montada contra a versão
     * ativa de mesmo `nome`/endereço, para nunca avaliar "não posicionado"
     * contra um layout que já foi substituído.
     *
     * @return Collection<int, Device>
     */
    public function dispositivosNaoPosicionados(FloorPlan $planta): Collection
    {
        $plantaAtiva = $planta->ativa
            ? $planta
            : FloorPlan::query()
                ->where('address_id', $planta->address_id)
                ->where('nome', $planta->nome)
                ->where('ativa', true)
                ->first() ?? $planta;

        $idsPosicionados = $plantaAtiva->devicePositions()->pluck('device_id');

        return Device::query()
            ->active()
            ->byAddress($planta->address_id)
            ->whereNotIn('id', $idsPosicionados)
            ->get();
    }

    /**
     * Remove a posição de um dispositivo nesta versão da planta, sem afetar
     * nenhuma outra versão (a posição de uma versão substituída nunca é
     * tocada por uma ação na versão ativa). Dispositivo sem posição nesta
     * planta não é erro, é o estado que a remoção deveria produzir mesmo.
     */
    public function removerPosicao(FloorPlan $planta, Device $dispositivo): void
    {
        DevicePosition::query()
            ->where('floor_plan_id', $planta->getKey())
            ->where('device_id', $dispositivo->getKey())
            ->delete();
    }

    /**
     * Dispositivo substituído (Plano 11) herda a posição do anterior em toda
     * planta ativa do endereço em que ele estava posicionado.
     *
     * Chamado por `DeviceReplacementService::substituir()` logo depois de
     * criar o dispositivo novo, dentro da mesma transação: o ponto físico é
     * o mesmo, e reposicionar a mão a cada troca de armadilha seria trabalho
     * repetido sem valor. Sem posição alguma para herdar (dispositivo nunca
     * foi posicionado em nenhuma planta), o método não faz nada - não é erro,
     * é o estado normal de um endereço que ainda não tem planta.
     */
    public function herdarPosicao(Device $anterior, Device $novo): void
    {
        $posicoes = DevicePosition::query()
            ->where('device_id', $anterior->getKey())
            ->whereHas('floorPlan', fn ($consulta) => $consulta->where('ativa', true))
            ->get();

        foreach ($posicoes as $posicao) {
            DevicePosition::query()->updateOrCreate(
                [
                    'floor_plan_id' => $posicao->floor_plan_id,
                    'device_id' => $novo->getKey(),
                ],
                [
                    'x' => $posicao->x,
                    'y' => $posicao->y,
                    'rotulo_visivel' => $posicao->rotulo_visivel,
                ]
            );
        }
    }

    /**
     * Processa o arquivo, grava no disco e cria a linha da versão nova.
     * Reaproveitado por `enviar()` (sem versão anterior ou com ela) e por
     * `substituir()` - o que muda entre os dois é só quem decide o número da
     * versão e se há posição para copiar depois.
     */
    private function criarVersao(int $addressId, string $nome, UploadedFile $arquivo, ?string $observacao, int $versao): FloorPlan
    {
        [$conteudo, $extensao, $largura, $altura] = $this->processarArquivo($arquivo);

        $caminho = $this->armazenar($addressId, $nome, $versao, $extensao, $conteudo);

        return FloorPlan::query()->create([
            'address_id' => $addressId,
            'versao' => $versao,
            'nome' => $nome,
            'arquivo_path' => $caminho,
            'largura_px' => $largura,
            'altura_px' => $altura,
            'ativa' => true,
            'substituida_em' => null,
            'observacao' => $observacao,
        ]);
    }

    /**
     * Copia cada posição de `$origem` para `$destino`. `$origem` não é tocada:
     * a leitura é uma consulta nova, sem reaproveitar coleção que algum
     * chamador já tenha em memória, e a gravação cria linhas novas em
     * `$destino`.
     */
    private function copiarPosicoes(FloorPlan $origem, FloorPlan $destino): void
    {
        $origem->devicePositions()->get()->each(
            fn (DevicePosition $posicao) => DevicePosition::query()->create([
                'floor_plan_id' => $destino->getKey(),
                'device_id' => $posicao->device_id,
                'x' => $posicao->x,
                'y' => $posicao->y,
                'rotulo_visivel' => $posicao->rotulo_visivel,
            ])
        );
    }

    /**
     * Confere o tamanho e o tipo real do arquivo, e devolve o conteúdo já
     * pronto para gravar em disco, com a extensão e as dimensões em pixel.
     *
     * @return array{0: string, 1: string, 2: int, 3: int} conteúdo binário,
     *                                                     extensão,
     *                                                     largura,
     *                                                     altura
     *
     * @throws ValidationException
     */
    private function processarArquivo(UploadedFile $arquivo): array
    {
        if ($arquivo->getSize() === false || $arquivo->getSize() > self::TAMANHO_MAXIMO_BYTES) {
            throw ValidationException::withMessages([
                'arquivo' => 'O arquivo da planta não pode passar de 10 MB.',
            ]);
        }

        // Tipo real do conteúdo, resolvido pelo Symfony via fileinfo - nunca a
        // extensão do nome original nem o Content-Type que o navegador
        // mandou. Um .txt renomeado para .png é recusado aqui mesmo que já
        // tenha passado pela regra `mimes` do FormRequest, porque o Service
        // também é chamado fora de uma requisição HTTP validada.
        $mime = (string) $arquivo->getMimeType();

        if (! array_key_exists($mime, self::TIPOS_ACEITOS)) {
            throw ValidationException::withMessages([
                'arquivo' => 'Envie a planta em PNG, JPEG ou PDF de uma página.',
            ]);
        }

        if ($mime === 'application/pdf') {
            return $this->converterPdfParaImagem($arquivo);
        }

        $caminhoTemporario = $arquivo->getRealPath();
        $dimensoes = $caminhoTemporario === false ? false : @getimagesize($caminhoTemporario);
        $conteudo = $caminhoTemporario === false ? false : file_get_contents($caminhoTemporario);

        if ($dimensoes === false || $conteudo === false) {
            throw ValidationException::withMessages([
                'arquivo' => 'Não foi possível ler a imagem enviada.',
            ]);
        }

        return [$conteudo, self::TIPOS_ACEITOS[$mime], (int) $dimensoes[0], (int) $dimensoes[1]];
    }

    /**
     * Converte a primeira página do PDF em PNG.
     *
     * Recusa PDF com mais de uma página: a planta é uma imagem só, e um PDF
     * de vistoria com várias páginas quase certamente não é a planta do
     * endereço, e sim outro documento anexado por engano.
     *
     * Depende da extensão `imagick` do PHP. Sem ela disponível no servidor, a
     * recusa é explícita em vez de uma exceção fatal.
     *
     * @return array{0: string, 1: string, 2: int, 3: int}
     *
     * @throws ValidationException
     */
    private function converterPdfParaImagem(UploadedFile $arquivo): array
    {
        if (! class_exists(\Imagick::class)) {
            throw ValidationException::withMessages([
                'arquivo' => 'Este servidor não está preparado para converter PDF em imagem. '
                    .'Envie a planta em PNG ou JPEG, ou exporte o PDF como imagem antes de enviar.',
            ]);
        }

        $caminho = $arquivo->getRealPath();

        if ($caminho === false) {
            throw ValidationException::withMessages([
                'arquivo' => 'Não foi possível ler o PDF enviado.',
            ]);
        }

        $imagick = new \Imagick;

        try {
            $imagick->setResolution(150, 150);
            $imagick->readImage($caminho);

            if ($imagick->getNumberImages() > 1) {
                throw ValidationException::withMessages([
                    'arquivo' => 'O PDF da planta precisa ter uma página só.',
                ]);
            }

            $imagick->setIteratorIndex(0);
            $imagick->setImageFormat('png');
            $imagick->setImageBackgroundColor(new \ImagickPixel('white'));
            $imagick->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);

            $conteudo = $imagick->getImageBlob();
            $largura = $imagick->getImageWidth();
            $altura = $imagick->getImageHeight();

            return [$conteudo, 'png', (int) $largura, (int) $altura];
        } finally {
            $imagick->clear();
        }
    }

    /**
     * Grava o conteúdo processado no disco público, no mesmo padrão de
     * `WorkOrderPhotoController` (disco `public`, servido por
     * `storage:link`).
     *
     * O nome do arquivo carrega um componente aleatório de 40 caracteres,
     * mesmo critério de `WorkOrderPhoto` (`UploadedFile::store()` já hasheia
     * o nome sozinho): o disco `public` é servido pelo webserver direto pelo
     * symlink `storage:link`, sem middleware, permissão nem escopo de
     * empresa no meio, então é o espaço grande do nome aleatório - e não o
     * `address_id` ou o slug do nome da planta, ambos previsíveis - que
     * impede alguém de enumerar e baixar a planta de outro tenant sem
     * autenticação. Mesmo raciocínio já registrado em
     * `DominioMultiempresa::UNIQUES_GLOBAIS_MANTIDOS` para os tokens de
     * convite e de pesquisa de satisfação.
     */
    private function armazenar(int $addressId, string $nome, int $versao, string $extensao, string $conteudo): string
    {
        $slug = Str::slug($nome) ?: 'planta';
        $caminho = sprintf('floor-plans/%d/%s-v%d-%s.%s', $addressId, $slug, $versao, Str::random(40), $extensao);

        Storage::disk(self::DISCO)->put($caminho, $conteudo);

        return $caminho;
    }

    /**
     * `x`/`y` precisam ser numéricos e estritamente maiores que 0 e menores
     * que 1: fração da largura/altura da planta, nunca pixel, e nunca a
     * borda exata (0 ou 1), onde o marcador não teria onde desenhar a metade
     * que sairia da imagem.
     *
     * @throws ValidationException
     */
    private function garantirFracaoValida(mixed $valor, string $eixo): void
    {
        if (! is_numeric($valor)) {
            throw ValidationException::withMessages([
                'posicoes' => sprintf('A posição "%s" precisa ser um número.', $eixo),
            ]);
        }

        $numero = (float) $valor;

        if ($numero > 0.0 && $numero < 1.0) {
            return;
        }

        throw ValidationException::withMessages([
            'posicoes' => sprintf(
                'A posição "%s" precisa ser um número entre 0 e 1 (fração da largura/altura da planta).',
                $eixo
            ),
        ]);
    }
}
