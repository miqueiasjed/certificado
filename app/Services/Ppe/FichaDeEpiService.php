<?php

namespace App\Services\Ppe;

use App\Models\Company;
use App\Models\PersonalProtectiveEquipment;
use App\Models\PpeDelivery;
use App\Models\Technician;
use App\Support\BusinessDate;
use Barryvdh\DomPDF\Facade\Pdf as FacadePdf;
use Barryvdh\DomPDF\PDF;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Ficha de EPI em PDF e extração de entregas por período (Plano 28, Task 28.5).
 *
 * As duas saídas exigidas pela NR-6: a ficha individual do trabalhador, que é o
 * registro do item 6.5.1, e a relação por período em CSV, que é o que o item
 * 6.5.1.1 cobra para aceitar o registro eletrônico no lugar do papel. É o que se
 * entrega ao fiscal e o que o empregador junta em reclamatória trabalhista.
 *
 * O CA impresso é o da entrega, nunca o do cadastro
 * ------------------------------------------------
 * Todo dado de certificado sai de `ppe_deliveries.numero_ca` e
 * `ppe_deliveries.validade_ca`, a cópia gravada no ato da entrega pela Task
 * 28.2. A relação `personalProtectiveEquipment` é lida apenas para nome, tipo e
 * fabricante. Ler o CA pela relação faria a ficha de 2025 exibir o certificado
 * renovado em 2026: o documento deixaria de dizer o que foi entregue naquele
 * dia, que é a única coisa que ele existe para dizer. Documento emitido não se
 * recalcula.
 *
 * Estorno aparece, nunca some
 * ---------------------------
 * A entrega é imutável e a correção é estorno com motivo. A entrega estornada
 * sai marcada na ficha e na extração, com o motivo e a data. Omitir o estorno do
 * documento seria apagar o rastro do próprio erro — adulteração de registro, não
 * limpeza de tela.
 *
 * O que falta também é informação
 * -------------------------------
 * Entrega sem `assinado_em`/`assinatura_path` sai marcada como **pendente de
 * assinatura**, em vez de sair em branco: a confirmação do recebimento é
 * justamente o que a norma pede, e a ausência dela é a pendência que o documento
 * precisa denunciar. Já `numero_ca` vazio e `trocar_ate` nulo são estados
 * neutros — "não informado" e "sem troca programada" —, nunca irregularidade
 * pintada de vermelho, na mesma regra do checklist do Plano 24.
 *
 * A ficha sobrevive ao técnico
 * ----------------------------
 * Técnico inativo continua tendo ficha. Desligamento não apaga registro cujo
 * arquivamento recomendado é de vinte anos, e é exatamente a ficha de quem já
 * saiu que costuma ser pedida depois.
 *
 * O documento não certifica a empresa
 * -----------------------------------
 * O rodapé afirma o que o documento é — registro de fornecimento de EPI nos
 * termos do item 6.5.1 da NR-6 — e nada além disso. Treinamento, uso efetivo em
 * campo, higienização e guarda o sistema não enxerga, e afirmar conformidade a
 * partir do que está cadastrado seria assumir responsabilidade que não é da
 * plataforma. Mesma ressalva obrigatória do checklist do Plano 24.
 *
 * Datas: toda formatação acontece aqui, no fuso do negócio, via `BusinessDate`.
 * A view Blade recebe texto pronto e nunca formata data, conforme a skill
 * `datas-timezone`.
 */
class FichaDeEpiService
{
    /**
     * Os rótulos de tipo e de motivo moram nos models, colados nos enums que
     * descrevem: `PersonalProtectiveEquipment::ROTULOS_DE_TIPO` e
     * `PpeDelivery::ROTULOS_DE_MOTIVO`. Havia cópia aqui e cópia no comando de
     * aviso, e as duas divergiram — o e-mail dizia "protetor auricular" e a
     * ficha, "Protetor auricular", para o mesmo item.
     */

    /**
     * Texto exibido no lugar de um dado que o tenant não preencheu. Estado
     * neutro: campo em branco não é irregularidade.
     */
    private const NAO_INFORMADO = '—';

    /**
     * Marcação obrigatória da entrega sem confirmação de recebimento.
     */
    public const PENDENTE_DE_ASSINATURA = 'Pendente de assinatura';

    /**
     * `trocar_ate` nulo significa que o modelo de EPI não tem vida útil
     * cadastrada — e não um prazo padrão inventado, que viraria alerta falso.
     */
    public const SEM_TROCA_PROGRAMADA = 'Sem troca programada';

    /**
     * Colunas da extração por período, na ordem em que saem no CSV.
     *
     * São as mesmas informações da ficha, mais o técnico: a ficha é de um
     * trabalhador só, a extração é de todos.
     *
     * @var array<int, string>
     */
    public const COLUNAS_DA_EXTRACAO = [
        'Técnico',
        'Situação do técnico',
        'Data da entrega',
        'EPI',
        'Tipo',
        'Fabricante',
        'Quantidade',
        'Número do CA',
        'Validade do CA',
        'Motivo',
        'Trocar até',
        'Devolvido em',
        'Motivo da devolução',
        'Assinatura',
        'Situação',
        'Motivo do estorno',
        'Registrado por',
    ];

    /**
     * Separador do CSV.
     *
     * Ponto e vírgula porque o Excel em português usa a vírgula como separador
     * decimal e, com vírgula, joga a planilha inteira em uma coluna só. O
     * destinatário deste arquivo abre no Excel, não em um parser.
     */
    private const SEPARADOR_CSV = ';';

    // -----------------------------------------------------------------
    // Ficha individual em PDF
    // -----------------------------------------------------------------

    /**
     * Dados já formatados da ficha de um técnico.
     *
     * Público de propósito: é por aqui que o teste confere CA, marcação de
     * pendência e estorno sem precisar abrir o binário do PDF, e é daqui que a
     * tela da Task 28.6 pode montar a prévia sem duplicar regra.
     *
     * @return array<string, mixed>
     */
    public function dadosDaFicha(Technician $tecnico): array
    {
        $empresa = $this->empresaEmitente($tecnico);

        $entregas = PpeDelivery::query()
            ->where('technician_id', $tecnico->getKey())
            ->with(['personalProtectiveEquipment:id,nome,tipo,fabricante', 'user:id,name'])
            // Ordem crescente: a ficha é um histórico e se lê da primeira
            // entrega para a última. A relação `Technician::ppeDeliveries` usa
            // a ordem inversa porque serve à tela, onde o que importa é o que
            // aconteceu por último.
            ->orderBy('entregue_em')
            ->orderBy('id')
            ->get();

        $linhas = $entregas->map(fn (PpeDelivery $entrega): array => $this->formatarEntrega($entrega))->all();

        return [
            'empresa' => [
                'nome' => $empresa->name,
                'cnpj' => $empresa->cnpj,
                'endereco' => $empresa->full_address,
                'telefone' => $empresa->phone,
            ],
            'logoSrc' => $this->arquivoDoDiscoEmBase64($empresa->logo_path),
            'tecnico' => [
                'nome' => $tecnico->name,
                'registro' => $tecnico->registration_number ?: self::NAO_INFORMADO,
                'especialidade' => $tecnico->specialty ?: self::NAO_INFORMADO,
                'email' => $tecnico->email ?: self::NAO_INFORMADO,
                'telefone' => $tecnico->phone ?: self::NAO_INFORMADO,
                // Técnico desligado continua tendo ficha; a situação sai
                // impressa para que o leitor saiba que a ficha está encerrada.
                'situacao' => $tecnico->is_active ? 'Ativo' : 'Inativo',
            ],
            'entregas' => $linhas,
            'resumo' => [
                'total' => count($linhas),
                'validas' => count(array_filter($linhas, fn (array $l): bool => ! $l['estornada'])),
                'estornadas' => count(array_filter($linhas, fn (array $l): bool => $l['estornada'])),
                'pendentes_de_assinatura' => count(array_filter(
                    $linhas,
                    fn (array $l): bool => ! $l['estornada'] && ! $l['assinada']
                )),
            ],
            'geradoEm' => BusinessDate::agora()->format('d/m/Y \à\s H\hi'),
        ];
    }

    /**
     * A ficha do técnico pronta para download.
     *
     * Devolve o PDF em vez da resposta HTTP para que o controller decida entre
     * `stream()` (abrir no navegador) e `download()`, e para que o teste possa
     * conferir o documento sem passar por uma rota.
     */
    public function gerarFichaEmPdf(Technician $tecnico): PDF
    {
        return FacadePdf::loadView('pdf.ppe-record', $this->dadosDaFicha($tecnico))
            ->setPaper('A4', 'portrait');
    }

    /**
     * Nome do arquivo da ficha, com o técnico identificado: o destinatário
     * costuma baixar a ficha de vários trabalhadores na mesma pasta.
     */
    public function nomeDoArquivoDaFicha(Technician $tecnico): string
    {
        $identificacao = Str::slug((string) $tecnico->name) ?: 'tecnico-'.$tecnico->getKey();

        return 'ficha-de-epi-'.$identificacao.'.pdf';
    }

    // -----------------------------------------------------------------
    // Extração por período
    // -----------------------------------------------------------------

    /**
     * Relação de entregas de todos os técnicos entre duas datas, em CSV.
     *
     * Uma linha por entrega, inclusive as estornadas — marcadas na coluna
     * "Situação", com o motivo. O recorte é por `entregue_em`, o dia do fato, e
     * as duas pontas entram (relação "de 1º a 31" inclui o dia 31).
     *
     * O arquivo sai com BOM UTF-8 e separado por ponto e vírgula porque quem
     * recebe abre no Excel: sem o BOM, "Óculos" e "Substituição" chegam
     * corrompidos, e o documento perde justamente a legibilidade que a
     * fiscalização exige.
     *
     * @param  string  $inicio  Primeiro dia do período, em `Y-m-d`.
     * @param  string  $fim  Último dia do período, em `Y-m-d`, incluído.
     *
     * @throws InvalidArgumentException Quando o período é inválido.
     */
    public function montarCsvDeEntregas(string $inicio, string $fim): string
    {
        [$de, $ate] = $this->periodo($inicio, $fim);

        $entregas = PpeDelivery::query()
            ->whereBetween('entregue_em', [$de, $ate])
            ->with([
                'technician:id,name,is_active',
                'personalProtectiveEquipment:id,nome,tipo,fabricante',
                'user:id,name',
            ])
            ->orderBy('entregue_em')
            ->orderBy('technician_id')
            ->orderBy('id')
            ->get();

        $arquivo = fopen('php://temp', 'r+');

        if ($arquivo === false) {
            throw new RuntimeException('Não foi possível montar o arquivo da extração de EPI.');
        }

        $this->escreverLinha($arquivo, self::COLUNAS_DA_EXTRACAO);

        foreach ($entregas as $entrega) {
            $this->escreverLinha($arquivo, $this->linhaDaExtracao($entrega));
        }

        rewind($arquivo);
        $conteudo = (string) stream_get_contents($arquivo);
        fclose($arquivo);

        // BOM UTF-8: é ele que faz o Excel reconhecer a acentuação em vez de
        // interpretar o arquivo como Latin-1.
        return "\u{FEFF}".$conteudo;
    }

    /**
     * Nome do arquivo da extração, com o período no próprio nome: o fiscal
     * costuma receber vários recortes e o arquivo precisa se identificar
     * sozinho depois de salvo.
     */
    public function nomeDoArquivoDaExtracao(string $inicio, string $fim): string
    {
        [$de, $ate] = $this->periodo($inicio, $fim);

        return 'entregas-de-epi-'.$de.'-a-'.$ate.'.csv';
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Uma entrega, com todo dado já formatado para impressão.
     *
     * `numero_ca` e `validade_ca` saem **da entrega**, nunca de
     * `$entrega->personalProtectiveEquipment`: ver o cabeçalho da classe.
     *
     * @return array<string, mixed>
     */
    private function formatarEntrega(PpeDelivery $entrega): array
    {
        $epi = $entrega->personalProtectiveEquipment;
        $estornada = $entrega->estornada_em !== null;
        $assinada = $entrega->assinado_em !== null || filled($entrega->assinatura_path);

        return [
            'entregue_em' => $this->dia($entrega->entregue_em),
            'epi' => $epi?->nome ?? self::NAO_INFORMADO,
            'tipo' => $this->rotuloDeTipo($epi),
            'fabricante' => $epi?->fabricante ?: null,
            'quantidade' => (int) $entrega->quantidade,
            // Cópia do certificado no dia da entrega. CA em branco é estado
            // neutro, nunca irregularidade.
            'numero_ca' => $entrega->numero_ca ?: self::NAO_INFORMADO,
            'validade_ca' => $this->dia($entrega->validade_ca) ?? self::NAO_INFORMADO,
            'motivo' => PpeDelivery::ROTULOS_DE_MOTIVO[$entrega->motivo] ?? (string) $entrega->motivo,
            // Nulo é "sem troca programada", não pendência. A marca separada
            // existe para a view não comparar texto para decidir a cor.
            'tem_troca_programada' => $entrega->trocar_ate !== null,
            'trocar_ate' => $this->dia($entrega->trocar_ate) ?? self::SEM_TROCA_PROGRAMADA,
            'devolvido_em' => $this->dia($entrega->devolvido_em),
            'motivo_devolucao' => $entrega->motivo_devolucao ?: null,
            'assinada' => $assinada,
            'assinatura_src' => $this->arquivoDoDiscoEmBase64($entrega->assinatura_path),
            'assinado_em' => $this->instante($entrega->assinado_em),
            'estornada' => $estornada,
            'estornada_em' => $this->instante($entrega->estornada_em),
            'motivo_estorno' => $entrega->motivo_estorno ?: null,
            'registrado_por' => $entrega->user?->name,
        ];
    }

    /**
     * Uma entrega como linha do CSV, nas colunas de `COLUNAS_DA_EXTRACAO`.
     *
     * @return array<int, string>
     */
    private function linhaDaExtracao(PpeDelivery $entrega): array
    {
        $dados = $this->formatarEntrega($entrega);
        $tecnico = $entrega->technician;

        return [
            $tecnico?->name ?? self::NAO_INFORMADO,
            // Técnico ausente é dado que falta, não trabalhador desligado:
            // imprimir "Inativo" aqui afirmaria ao fiscal uma situação
            // cadastral que ninguém registrou.
            $tecnico === null ? self::NAO_INFORMADO : ($tecnico->is_active ? 'Ativo' : 'Inativo'),
            (string) $dados['entregue_em'],
            (string) $dados['epi'],
            (string) $dados['tipo'],
            (string) ($dados['fabricante'] ?? self::NAO_INFORMADO),
            (string) $dados['quantidade'],
            (string) $dados['numero_ca'],
            (string) $dados['validade_ca'],
            (string) $dados['motivo'],
            (string) $dados['trocar_ate'],
            (string) ($dados['devolvido_em'] ?? self::NAO_INFORMADO),
            (string) ($dados['motivo_devolucao'] ?? self::NAO_INFORMADO),
            $dados['assinada']
                ? 'Assinada em '.($dados['assinado_em'] ?? 'data não registrada')
                : self::PENDENTE_DE_ASSINATURA,
            // A entrega estornada permanece na extração, marcada. Tirá-la seria
            // apagar o rastro do erro.
            $dados['estornada']
                ? 'Estornada em '.($dados['estornada_em'] ?? 'data não registrada')
                : 'Válida',
            (string) ($dados['motivo_estorno'] ?? self::NAO_INFORMADO),
            (string) ($dados['registrado_por'] ?? self::NAO_INFORMADO),
        ];
    }

    /**
     * Escreve uma linha do CSV.
     *
     * `escape: ''` desliga o escape de barra invertida do PHP, que não faz
     * parte do CSV e corromperia texto livre como o motivo do estorno. A quebra
     * de linha é CRLF, o que o Excel espera.
     *
     * @param  resource  $arquivo
     * @param  array<int, string>  $colunas
     */
    private function escreverLinha($arquivo, array $colunas): void
    {
        fputcsv($arquivo, $colunas, self::SEPARADOR_CSV, '"', '', "\r\n");
    }

    /**
     * Normaliza o período informado e recusa o intervalo invertido.
     *
     * A validação de formato é do FormRequest; o que se defende aqui é a regra:
     * relação de período com fim antes do início devolveria um arquivo vazio, e
     * arquivo vazio entregue ao fiscal parece ausência de entregas.
     *
     * @return array{0: string, 1: string} Início e fim, em `Y-m-d`.
     *
     * @throws InvalidArgumentException
     */
    private function periodo(string $inicio, string $fim): array
    {
        $de = BusinessDate::diaDe(trim($inicio));
        $ate = BusinessDate::diaDe(trim($fim));

        if ($de === null || $ate === null) {
            throw new InvalidArgumentException('Informe a data inicial e a data final do período.');
        }

        if ($de > $ate) {
            throw new InvalidArgumentException('A data final do período não pode ser anterior à data inicial.');
        }

        return [$de, $ate];
    }

    /**
     * Empresa que assina o cabeçalho da ficha: sempre a dona do técnico.
     *
     * O escopo global de `BelongsToCompany` já impede alcançar o técnico de
     * outro tenant, e esta conferência é a segunda tranca do mesmo portão —
     * emitir ficha de um trabalhador com o CNPJ de outra empresa produziria um
     * documento falso, e documento emitido aqui tem valor perante fiscalização.
     * Falhar é o comportamento desejado.
     *
     * @throws RuntimeException Quando o técnico não é da empresa do contexto.
     */
    private function empresaEmitente(Technician $tecnico): Company
    {
        $empresa = Company::current();

        if ((int) $tecnico->company_id !== (int) $empresa->getKey()) {
            throw new RuntimeException(
                "O técnico {$tecnico->getKey()} não pertence à empresa {$empresa->getKey()}, "
                .'e a ficha de EPI não pode ser emitida com o cabeçalho de outra empresa.'
            );
        }

        return $empresa;
    }

    /**
     * Rótulo do tipo do EPI, tolerante a tipo fora do catálogo.
     */
    private function rotuloDeTipo(?PersonalProtectiveEquipment $epi): string
    {
        if ($epi === null) {
            return self::NAO_INFORMADO;
        }

        return PersonalProtectiveEquipment::ROTULOS_DE_TIPO[$epi->tipo] ?? (string) $epi->tipo;
    }

    /**
     * Dia no fuso do negócio, em `dd/mm/aaaa`. Campo `date` não sofre conversão
     * de fuso: converter é o que produz o erro de um dia.
     */
    private function dia(mixed $valor): ?string
    {
        return BusinessDate::paraFusoNegocio($valor)?->format('d/m/Y');
    }

    /**
     * Instante gravado em UTC, convertido para o fuso do negócio só aqui, na
     * exibição.
     */
    private function instante(mixed $valor): ?string
    {
        return BusinessDate::paraFusoNegocio($valor)?->format('d/m/Y \à\s H\hi');
    }

    /**
     * Converte a imagem gravada no disco em Base64, para o dompdf embutir a logo
     * e a assinatura sem depender de acesso HTTP ao storage. Mesmo padrão de
     * `WorkOrderService`, `CertificateService` e `ProductBatchController`.
     */
    private function arquivoDoDiscoEmBase64(?string $caminho): ?string
    {
        if (! $caminho) {
            return null;
        }

        $completo = storage_path('app/public/'.$caminho);

        if (! is_file($completo)) {
            return null;
        }

        $conteudo = file_get_contents($completo);

        if ($conteudo === false) {
            return null;
        }

        $mime = match (strtolower((string) pathinfo($completo, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return 'data:'.$mime.';base64,'.base64_encode($conteudo);
    }
}
