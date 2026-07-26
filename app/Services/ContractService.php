<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Contract;
use App\Support\BusinessDate;
use App\Support\PeriodicidadeDeContrato;
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

        if (empty($dados['contract_number'])) {
            $dados['contract_number'] = 'CONT-' . str_pad((string) $address->id, 6, '0', STR_PAD_LEFT) . '-' . date('Ymd');
        }

        return $address->contract()->updateOrCreate(
            ['address_id' => $address->id],
            $dados
        );
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
     * @return array{success: bool, message: string, data: array{canceladas: int, executadas_preservadas: int, passadas_preservadas: int}}
     */
    public function encerrar(Contract $contrato, ?string $motivo = null): array
    {
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
