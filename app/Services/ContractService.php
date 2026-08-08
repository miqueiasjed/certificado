<?php

namespace App\Services;

use App\Exceptions\ContratoEmAssinaturaException;
use App\Models\Address;
use App\Models\Contract;
use App\Support\BusinessDate;
use App\Support\PeriodicidadeDeContrato;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;

class ContractService
{
    /**
     * Campos que definem o calendário de visitas. Mudança em qualquer um deles
     * obriga a reprogramar as visitas futuras não executadas.
     *
     * `visit_frequency` (texto livre legado) entra na lista porque continua
     * sendo o que o formulário atual grava. `visit_frequency_valor` e
     * `visit_frequency_unidade` entram porque, a partir da Task 9.7, este
     * Service preenche as duas a partir da escolha do usuário em
     * `visit_frequency` (ver `comPeriodicidadeResolvida`), fechando a dívida
     * registrada na Task 9.5: antes, mudar a frequência na tela disparava a
     * reprogramação sem alterar o calendário, porque o cálculo lê só as
     * colunas novas e o formulário só gravava a antiga.
     */
    private const CAMPOS_DO_CALENDARIO = [
        'start_date',
        'end_date',
        'service_type',
        'visit_frequency',
        'visit_frequency_valor',
        'visit_frequency_unidade',
    ];

    public function __construct(
        private readonly ManutencaoDeVisitasService $manutencaoDeVisitas,
    ) {
    }

    /**
     * Cria o contrato do endereço informado.
     *
     * Endereço tem no máximo um contrato: `updateOrCreate` por `address_id`
     * mantém esse invariante e evita duplicidade se o formulário for
     * reenviado.
     *
     * @param  array<string, mixed>  $dados  Já validados pelo controller.
     */
    public function criar(Address $address, array $dados): Contract
    {
        $dados = $this->comPeriodicidadeResolvida($dados);
        $dados = $this->comNumeroDeContrato($address->id, $dados);

        return $address->contract()->updateOrCreate(
            ['address_id' => $address->id],
            $dados
        );
    }

    /**
     * Cria um contrato novo para o endereço, sempre em uma linha nova
     * (`Contract::create`), nunca atualizando um contrato existente do mesmo
     * endereço.
     *
     * Reaproveitado por `ContractRenewalService` (Task 23.4): no momento da
     * renovação o endereço já tem uma linha em `contracts`, e o
     * `updateOrCreate` de `criar()` sobrescreveria o contrato anterior em vez
     * de preservá-lo como histórico.
     *
     * Ao contrário de `criar()`, não passa os dados por
     * `comPeriodicidadeResolvida`: quem chama aqui já entrega
     * `visit_frequency_valor`/`visit_frequency_unidade` prontos, copiados do
     * contrato anterior, e reprocessar o texto livre de `visit_frequency`
     * arriscaria recalcular um valor diferente do que foi copiado de
     * propósito.
     *
     * @param  array<string, mixed>  $dados  Dados já resolvidos do contrato
     *                                       novo, exceto `address_id` e
     *                                       (quando ausente) `contract_number`.
     */
    public function criarNovo(Address $address, array $dados): Contract
    {
        $dados = $this->comNumeroDeContrato($address->id, $dados);
        $dados['address_id'] = $address->id;

        return Contract::create($dados);
    }

    /**
     * Preenche `contract_number` com o padrão `CONT-000000-AAAAMMDD` quando
     * ainda não veio definido. Extraído de `criar()` para `criarNovo()`
     * reaproveitar a mesma regra sem duplicá-la.
     */
    private function comNumeroDeContrato(int $addressId, array $dados): array
    {
        if (empty($dados['contract_number'])) {
            $dados['contract_number'] = 'CONT-' . str_pad((string) $addressId, 6, '0', STR_PAD_LEFT) . '-' . date('Ymd');
        }

        return $dados;
    }

    /**
     * Atualiza o contrato e, quando a mudança afeta o calendário, reprograma
     * as visitas futuras não executadas.
     *
     * Update e reprogramação na mesma transação: contrato salvo com calendário
     * antigo na agenda é pior que nenhum dos dois, porque a divergência não
     * aparece em lugar nenhum.
     *
     * @param  array<string, mixed>  $dados  Já validados pelo controller.
     * @return array{success: bool, message: string, data: array{contrato: Contract, reprogramacao: array|null}}
     */
    public function atualizar(Contract $contrato, array $dados): array
    {
        $this->exigirContratoForaDeAssinatura($contrato);

        return DB::transaction(function () use ($contrato, $dados): array {
            $dados = $this->comPeriodicidadeResolvida($dados);

            $contrato->update($dados);

            $reprogramacao = $contrato->wasChanged(self::CAMPOS_DO_CALENDARIO)
                ? $this->manutencaoDeVisitas->reprogramar($contrato)
                : null;

            return [
                'success' => true,
                'message' => $this->mensagemDaReprogramacao($reprogramacao),
                'data' => [
                    'contrato' => $contrato,
                    'reprogramacao' => $reprogramacao,
                ],
            ];
        });
    }

    /**
     * Preenche `visit_frequency_valor` e `visit_frequency_unidade` a partir
     * da escolha do usuário em `visit_frequency`, usando o mesmo mapa de
     * `PeriodicidadeDeContrato` usado pelo backfill (Task 9.2), para as duas
     * fontes nunca divergirem sobre o que uma frequência significa.
     *
     * Fecha a dívida registrada na Task 9.5: sem isso, o formulário grava só
     * o texto livre e o cálculo do calendário, que lê apenas as colunas
     * novas, nunca enxerga a escolha do usuário.
     *
     * Não altera nada quando `visit_frequency` não está presente nos dados
     * (edição de outro campo) ou quando o valor não é reconhecido pelo mapa:
     * a pendência é a mesma do backfill, resolvida manualmente, nunca
     * chutada.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function comPeriodicidadeResolvida(array $dados): array
    {
        if (! array_key_exists('visit_frequency', $dados)) {
            return $dados;
        }

        $mapeado = PeriodicidadeDeContrato::mapear($dados['visit_frequency']);

        if ($mapeado === null) {
            return $dados;
        }

        $dados['visit_frequency_valor'] = $mapeado['valor'];
        $dados['visit_frequency_unidade'] = $mapeado['unidade'];

        return $dados;
    }

    /**
     * Encerra o contrato para efeito de agenda: cancela toda visita futura não
     * executada, com o motivo registrado na OS, e fecha a vigência gravando
     * `end_date`.
     *
     * Visita já executada e visita vencida não executada continuam como estão.
     *
     * Cancelamento e fechamento de vigência na mesma transação: sem
     * `end_date` gravado, o contrato continua "vigente" para
     * `PendenciasDeContratoService::listar()` (critério de lá é `end_date`
     * nulo ou >= hoje) e, conforme as datas que acabaram de ser canceladas
     * aqui forem vencendo, o painel de conformidade passa a apontá-las como
     * pendência de um contrato que já foi encerrado de propósito.
     *
     * Contrato em assinatura não pode ser encerrado pela mesma razão que não
     * pode ser editado: encerrar grava `end_date`, ou seja, muda a vigência do
     * contrato enquanto o cliente lê e assina um PDF que ainda traz a vigência
     * antiga. O documento oponível passaria a divergir do registro, que é
     * exatamente o que a invariante existe para impedir.
     *
     * @return array{success: bool, message: string, data: array{canceladas: int, executadas_preservadas: int, passadas_preservadas: int}}
     */
    public function encerrar(Contract $contrato, ?string $motivo = null): array
    {
        $this->exigirContratoForaDeAssinatura($contrato);

        return DB::transaction(function () use ($contrato, $motivo): array {
            $resumo = $this->manutencaoDeVisitas->cancelarFuturas(
                $contrato,
                $motivo ?: ManutencaoDeVisitasService::MOTIVO_ENCERRAMENTO
            );

            $this->fecharVigencia($contrato);

            return [
                'success' => true,
                'message' => "Contrato encerrado. {$resumo['canceladas']} visita(s) futura(s) cancelada(s).",
                'data' => $resumo,
            ];
        });
    }

    /**
     * Fecha a vigência do contrato em hoje, no fuso do negócio.
     *
     * Só grava quando a vigência atual é nula ou ainda está no futuro. Um
     * contrato cujo `end_date` já é hoje ou passado não pode ser alongado por
     * este método: encerrar não é reabrir vigência, é fechá-la o quanto antes.
     */
    private function fecharVigencia(Contract $contrato): void
    {
        $hoje = BusinessDate::hoje();
        $atual = BusinessDate::paraFusoNegocio($contrato->end_date);

        if ($atual !== null && ! $atual->greaterThan($hoje)) {
            return;
        }

        $contrato->end_date = $hoje->toDateString();
        $contrato->save();
    }

    /**
     * Quantas visitas futuras não executadas seriam canceladas se o contrato
     * fosse encerrado agora. Puramente informativo: usado pelo front
     * (Contracts/Index.vue e Contracts/Show.vue) para avisar o impacto antes
     * da confirmação, sem alterar nada.
     *
     * Mesmo critério de `ManutencaoDeVisitasService::cancelarFuturas`
     * (privado lá, por isso repetido aqui): OS gerada pelo contrato, ainda em
     * aberto ('scheduled' ou 'pending'), com data de hoje em diante.
     */
    public function contarVisitasFuturas(Contract $contrato): int
    {
        return $contrato->workOrders()
            ->where('origem', 'contrato')
            ->whereIn('status', ['scheduled', 'pending'])
            ->where('scheduled_date', '>=', BusinessDate::hoje()->toDateString())
            ->count();
    }

    /**
     * Exclui o contrato, cancelando antes as visitas futuras.
     *
     * Recusa a exclusão quando existe visita já executada vinculada. A foreign
     * key de `work_orders.contract_id` é `nullOnDelete`: apagar o contrato
     * desligaria da sua origem OS que já viraram documento entregue ao
     * cliente, e isso é mexer em histórico por via indireta. Nesse caso o
     * caminho é encerrar o contrato, não excluir.
     *
     * @return array{success: bool, message: string, data: array}
     */
    public function excluir(Contract $contrato): array
    {
        $this->exigirContratoForaDeAssinatura($contrato);

        $executadas = $contrato->workOrders()
            ->where('origem', 'contrato')
            ->whereIn('status', ['completed', 'in_progress'])
            ->count();

        if ($executadas > 0) {
            return [
                'success' => false,
                'message' => "Este contrato tem {$executadas} visita(s) já executada(s) e não pode ser excluído. Encerre o contrato para cancelar apenas as visitas futuras.",
                'data' => ['executadas' => $executadas],
            ];
        }

        return DB::transaction(function () use ($contrato): array {
            $resumo = $this->manutencaoDeVisitas->cancelarFuturas($contrato, 'Exclusão do contrato');

            $contrato->delete();

            return [
                'success' => true,
                'message' => "Contrato excluído. {$resumo['canceladas']} visita(s) futura(s) cancelada(s).",
                'data' => $resumo,
            ];
        });
    }

    /**
     * Mensagem para o usuário, dizendo o que aconteceu com a agenda. Sem
     * reprogramação, só confirma a atualização.
     *
     * @param  array{canceladas: int, movidas: int, criadas: int, renumeradas: int}|null  $resumo
     */
    private function mensagemDaReprogramacao(?array $resumo): string
    {
        if ($resumo === null) {
            return 'Contrato atualizado com sucesso!';
        }

        return sprintf(
            'Contrato atualizado. Visitas reprogramadas: %d criada(s), %d remarcada(s), %d cancelada(s).',
            $resumo['criadas'],
            $resumo['movidas'],
            $resumo['canceladas']
        );
    }

    /**
     * Recusa a operação quando o contrato está em assinatura (Plano 26,
     * Task 26.3).
     *
     * Contrato em assinatura é imutável: alterar o texto, encerrar a vigência
     * ou apagar o contrato enquanto o cliente lê o PDF já enviado faria a
     * assinatura valer para uma versão diferente da aceita, e isso não tem
     * conserto depois de assinado. A checagem fica aqui, no ponto único de
     * gravação, e não no controller: `ContractRenewalService` e qualquer
     * endpoint futuro passam por `atualizar()`/`encerrar()`/`excluir()` e
     * herdam a mesma proteção sem precisar lembrar dela.
     *
     * @throws ContratoEmAssinaturaException
     */
    private function exigirContratoForaDeAssinatura(Contract $contrato): void
    {
        if ($contrato->situacao_assinatura === 'em_assinatura') {
            throw ContratoEmAssinaturaException::naoPodeSerAlterado($contrato);
        }
    }

    /**
     * PDF do contrato em bytes, para ser enviado ao provedor de assinatura
     * eletrônica (Plano 26, Task 26.3) ou arquivado.
     *
     * Monta exatamente a mesma view e os mesmos dados que
     * `ContractController::generatePDF()` já usa na tela — inclusive
     * `address` e `client`, que o controller acrescenta por fora de
     * `preparePdfData()` — para que o documento enviado para assinatura seja,
     * byte a byte, o mesmo que a empresa vê ao imprimir. Layout de documento
     * emitido tem valor perante fiscalização (CLAUDE.md), e duas montagens
     * diferentes acabariam divergindo com o tempo.
     *
     * Devolve os bytes em vez de gravar: quem chama decide se arquiva, se
     * manda ao provedor ou os dois.
     */
    public function renderizarPdf(Contract $contrato): string
    {
        $contrato->loadMissing('address.client');

        $dados = $this->preparePdfData($contrato);
        $dados['address'] = $contrato->address;
        $dados['client'] = $contrato->address?->client;

        return Pdf::loadView('pdf.contract', $dados)
            ->setPaper('A4', 'portrait')
            ->output();
    }

    /**
     * Prepare data for PDF generation, including Base64 images.
     */
    public function preparePdfData(Contract $contract): array
    {
        $company = \App\Models\Company::current();

        return [
            'contract' => $contract,
            'company' => $company,
            'currentDate' => now()->format('d/m/Y'),
            'currentTime' => now()->format('H:i'),
            'logoSrc' => $this->convertStorageFileToBase64($company->logo_path),
        ];
    }

    /**
     * Convert a stored image file to a Base64 string.
     */
    private function convertStorageFileToBase64(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $fullPath = storage_path('app/public/' . $path);

        if (!file_exists($fullPath)) {
            return null;
        }

        $extension = pathinfo($fullPath, PATHINFO_EXTENSION);
        $data = file_get_contents($fullPath);

        $mime = match (strtolower($extension)) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}
