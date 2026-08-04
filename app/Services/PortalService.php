<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\Charge;
use App\Models\ClientUser;
use App\Models\Contract;
use App\Models\PaymentDetail;
use App\Models\ReceivableInstallment;
use App\Models\WorkOrder;
use App\Models\WorkOrderAdequation;
use App\Support\BusinessDate;
use App\Support\CamposVisiveisAoCliente;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Consultas do portal do cliente (Plano 15, Task 15.3): tudo que o cliente
 * final enxerga sobre a própria conta, sempre a partir do `ClientUser`
 * autenticado.
 *
 * ## Escopo duplo, a regra central desta classe
 *
 * Toda consulta aqui filtra por `company_id` **e** por `client_id`, mesmo com
 * o escopo global do Plano 4 (`App\Models\Concerns\BelongsToCompany`) já
 * ativo nos models envolvidos. A redundância é deliberada: o escopo global
 * separa empresas, mas não separa dois clientes da mesma empresa, e este é o
 * primeiro acesso externo do sistema. Se o escopo global falhar por qualquer
 * motivo (bug, contexto de tenant não resolvido, chamada feita de dentro de
 * `TenantAtual::semEscopo()`), os `where` explícitos de `client_id` e
 * `company_id` escritos nesta classe continuam filtrando, porque não
 * dependem do scope automático do Eloquent para existir.
 *
 * ## Entidades sem `client_id` direto
 *
 * `WorkOrder` e `Certificate` têm `client_id` na própria tabela. `Contract`,
 * `WorkOrderAdequation` e `PaymentDetail` não têm: o vínculo com o cliente
 * passa pelo pai (`Contract` → `Address.client_id`, `WorkOrderAdequation` e
 * `PaymentDetail` → `WorkOrder.client_id`). Nesses três casos o filtro por
 * cliente é um `whereHas()` com um `where()` explícito dentro do closure, não
 * apenas a presença do relacionamento: um `whereHas()` sem condição própria
 * dependeria do escopo global do model pai para filtrar, o que anularia
 * exatamente a redundância que esta classe existe para garantir. Com a
 * condição explícita dentro do closure, mesmo um `Contract` ou uma
 * `PaymentDetail` lidos com o escopo global desligado continuam presos ao
 * `client_id` do endereço/ordem de serviço a que pertencem.
 *
 * ## Documento em rascunho (achado ao implementar, registrado aqui)
 *
 * A Task 15.3 cita "certificado com status = 'draft', se existir esse
 * conceito" como o exemplo de documento em rascunho. Ele existiu: a migration
 * `2024_10_15_175932_create_certificates_table` criou `status` como enum
 * `draft/issued/expired/cancelled`. Mas a migration seguinte,
 * `2025_08_26_200507_update_certificates_status_values`, **consolidou**
 * `draft` e `issued` em `active` e trocou o enum para
 * `active/expired/cancelled`. Conferido tanto no histórico de migrations
 * quanto no schema de fato (`SHOW COLUMNS`) e nos scopes do model
 * (`Certificate::scopeActive/scopeExpired/scopeCancelled`, sem
 * `scopeDraft`). Ou seja: **nenhuma das cinco entidades que este Service
 * expõe tem hoje um estado de rascunho vivo no banco** (conferi `WorkOrder`,
 * `Contract`, `WorkOrderAdequation` e `PaymentDetail` do mesmo jeito, nenhuma
 * tem enum ou coluna equivalente).
 *
 * A consulta de certificados (`consultaDeCertificados()`) mantém o
 * `where('status', '!=', 'draft')` mesmo assim: é inofensivo hoje (nenhuma
 * linha pode ter esse valor, o enum do banco nem aceita) e vira a defesa
 * pronta se o enum ganhar `draft`/equivalente de volta no futuro, sem
 * exigir lembrar de mexer aqui na hora. Por não haver hoje uma linha real de
 * rascunho para persistir (o enum atual rejeita o valor), a Task 15.3 valida
 * essa exclusão inspecionando a consulta montada
 * (`consultaDeCertificados()->toSql()`/`getBindings()`), não com um registro
 * de rascunho de verdade no banco de teste.
 *
 * ## 404, nunca 403
 *
 * Toda busca por id que não encontra o registro (inexistente, de outro
 * cliente, de outra empresa, ou, no caso de certificado, em rascunho) lança
 * `ModelNotFoundException`, que o Laravel traduz em 404. Nunca é lançada uma
 * `AuthorizationException`/403: para o cliente do portal, dizer "isso existe
 * mas não é seu" já vaza a existência do registro de outro cliente.
 *
 * ## `documento()`: quais tipos ele aceita (decisão de design)
 *
 * A Task 15.3 não enumera os valores aceitos por `documento(string $tipo, int
 * $id)`. Optei por `'visita'`, `'certificado'`, `'contrato'` e `'fatura'`,
 * espelhando os quatro documentos citados no CLAUDE.md do projeto ("certificado,
 * OS, contrato, recibo"; `'fatura'` cobre o recibo/comprovante, via
 * `PaymentDetail`). Um tipo fora dessa lista também devolve
 * `ModelNotFoundException` (404), em vez de um erro de validação separado:
 * não há necessidade de distinguir, para quem consome a rota, "tipo que não
 * existe" de "documento que não existe".
 */
class PortalService
{
    public function __construct(
        private readonly ClientUser $clientUser,
    ) {}

    /**
     * Todas as visitas (ordens de serviço) do cliente, mais recentes primeiro.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visitas(): array
    {
        return $this->consultaDeVisitas()
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (WorkOrder $visita): array => CamposVisiveisAoCliente::visita($visita))
            ->all();
    }

    /**
     * Uma visita específica do cliente.
     *
     * @throws ModelNotFoundException Visita inexistente ou de outro cliente/empresa.
     */
    public function visita(int $id): array
    {
        return CamposVisiveisAoCliente::visita($this->buscarVisita($id));
    }

    /**
     * Certificados do cliente. Rascunho nunca aparece aqui (ver o cabeçalho
     * da classe).
     *
     * @return array<int, array<string, mixed>>
     */
    public function certificados(): array
    {
        return $this->consultaDeCertificados()
            ->orderByDesc('execution_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Certificate $certificado): array => CamposVisiveisAoCliente::certificado($certificado))
            ->all();
    }

    /**
     * Contratos vinculados aos endereços do cliente.
     *
     * @return array<int, array<string, mixed>>
     */
    public function contratos(): array
    {
        return $this->consultaDeContratos()
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Contract $contrato): array => CamposVisiveisAoCliente::contrato($contrato))
            ->all();
    }

    /**
     * Visitas ainda não realizadas, da mais próxima para a mais distante.
     *
     * "Próxima" é hoje ou depois no fuso do negócio (`BusinessDate::hoje()`),
     * nunca `now()` em UTC: `scheduled_date` é um dia sem hora, e comparar com
     * o instante em UTC pode incluir ou excluir o dia de hoje errado conforme
     * a hora da requisição.
     *
     * @return array<int, array<string, mixed>>
     */
    public function proximasVisitas(): array
    {
        return $this->consultaDeVisitas()
            ->whereIn('status', ['pending', 'scheduled'])
            ->where('scheduled_date', '>=', BusinessDate::hoje()->toDateString())
            ->orderBy('scheduled_date')
            ->orderBy('id')
            ->get()
            ->map(fn (WorkOrder $visita): array => CamposVisiveisAoCliente::visita($visita))
            ->all();
    }

    /**
     * Adequações em aberto (`status = 'pendente'`) das visitas do cliente.
     *
     * @return array<int, array<string, mixed>>
     */
    public function adequacoesEmAberto(): array
    {
        return $this->consultaDeAdequacoes()
            ->where('status', 'pendente')
            ->orderBy('deadline')
            ->orderBy('id')
            ->get()
            ->map(fn (WorkOrderAdequation $adequacao): array => CamposVisiveisAoCliente::adequacao($adequacao))
            ->all();
    }

    /**
     * Faturas (parcelas de pagamento) das visitas do cliente.
     *
     * @return array<int, array<string, mixed>>
     */
    public function faturas(): array
    {
        return $this->consultaDeFaturas()
            ->orderByDesc('payment_due_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PaymentDetail $fatura): array => CamposVisiveisAoCliente::fatura($fatura))
            ->all();
    }

    /**
     * Faturas do cliente que ainda pedem atenção: sem `payment_date`
     * gravada, cobrindo tanto a pendente quanto a já vencida (as duas únicas
     * situações em que `PaymentDetail::getPaymentStatusAttribute()` devolve
     * `pending`/`overdue` sem pagamento nenhum registrado). Acrescentada na
     * Task 15.4 para o painel do portal ("faturas vencendo"), no mesmo
     * espírito de `proximasVisitas()`/`adequacoesEmAberto()`: um recorte de
     * negócio sobre a consulta já escopada, não uma listagem nova.
     *
     * @return array<int, array<string, mixed>>
     */
    public function faturasVencendo(): array
    {
        return $this->consultaDeFaturas()
            ->whereNull('payment_date')
            ->orderBy('payment_due_date')
            ->orderBy('id')
            ->get()
            ->map(fn (PaymentDetail $fatura): array => CamposVisiveisAoCliente::fatura($fatura))
            ->all();
    }

    /**
     * Cobrança ativa (boleto ou Pix, Plano 19) da parcela a receber ligada à
     * fatura informada, já no formato visível ao cliente, ou `null` quando
     * não há nenhuma - fatura sem parcela do Plano 18 vinculada (fatura
     * antiga, pré-migração) ou parcela sem cobrança emitida ainda. Acrescido
     * na Task 19.6 para `GET /portal/faturas` mostrar link, linha digitável
     * e QR code de cada fatura, sem o controller do portal precisar montar
     * consulta Eloquent própria (a regra de ouro desta classe, ver o
     * cabeçalho).
     *
     * `parcela_existe` diferencia "sem cobrança porque ninguém emitiu ainda"
     * de "sem cobrança porque esta fatura não tem parcela do Plano 18 para
     * cobrar" - o segundo caso é o que impede `PortalPagamentoController` de
     * oferecer "gerar cobrança" numa fatura que não tem como virar uma.
     *
     * @return array{cobranca: array<string, mixed>|null, parcela_existe: bool}
     */
    public function situacaoDeCobrancaDaFatura(int $faturaId): array
    {
        $parcela = $this->parcelaDaFatura($faturaId);

        if (! $parcela instanceof ReceivableInstallment) {
            return ['cobranca' => null, 'parcela_existe' => false];
        }

        $cobranca = $parcela->charges()
            ->whereIn('situacao', Charge::SITUACOES_ATIVAS)
            ->latest('id')
            ->first();

        return [
            'cobranca' => $cobranca instanceof Charge ? CamposVisiveisAoCliente::cobranca($cobranca) : null,
            'parcela_existe' => true,
        ];
    }

    /**
     * Parcela a receber (Plano 18) ligada à fatura informada, pronta para
     * `App\Services\ChargeService::emitir()` (Task 19.6,
     * `POST /portal/faturas/{parcela}/cobranca`).
     *
     * `parcelaDaFatura()` já é escopada por empresa e cliente (ver
     * `consultaDeParcelas()`), então fatura de outro cliente ou de outra
     * empresa não localiza parcela nenhuma aqui, mesmo critério de 404 do
     * resto da classe.
     *
     * @throws ModelNotFoundException Fatura sem parcela do Plano 18 vinculada, ou fatura que não existe, não é do cliente, ou não é da empresa.
     */
    public function parcelaParaCobranca(int $faturaId): ReceivableInstallment
    {
        return $this->parcelaDaFatura($faturaId)
            ?? throw (new ModelNotFoundException)->setModel(ReceivableInstallment::class, [$faturaId]);
    }

    /**
     * Acesso direto a um documento por tipo e id, para a rota que serve ou
     * baixa o arquivo (fora do escopo desta task, Task 15.4/15.5). Ver o
     * cabeçalho da classe para os tipos aceitos e o porquê.
     *
     * @return array<string, mixed>
     *
     * @throws ModelNotFoundException Tipo desconhecido, documento inexistente, de outro cliente/empresa, ou (para certificado) em rascunho.
     */
    public function documento(string $tipo, int $id): array
    {
        return match ($tipo) {
            'visita' => CamposVisiveisAoCliente::visita($this->buscarVisita($id)),
            'certificado' => CamposVisiveisAoCliente::certificado($this->buscarCertificado($id)),
            'contrato' => CamposVisiveisAoCliente::contrato($this->buscarContrato($id)),
            'fatura' => CamposVisiveisAoCliente::fatura($this->buscarFatura($id)),
            default => throw (new ModelNotFoundException)->setModel(static::class, [$id]),
        };
    }

    /**
     * Mesma resolução de `documento()`, mas devolvendo o model Eloquent
     * completo, em vez do array já filtrado por `CamposVisiveisAoCliente`.
     *
     * Uso exclusivo de quem monta o PDF do documento (Task 15.4,
     * `PortalDocumentController`): a geração do PDF reaproveita o
     * `preparePdfData()`/`prepareReceiptData()` de cada Service de domínio
     * (`CertificateService`, `WorkOrderService`, `ContractService`), e esses
     * métodos esperam o model inteiro, não a lista de campos permitida ao
     * cliente. A validação de dono é idêntica à de `documento()`: os mesmos
     * `buscarX()` privados, então "aparece no documento()" e "aparece aqui"
     * nunca divergem.
     *
     * @throws ModelNotFoundException Mesmas condições de `documento()`.
     */
    public function modeloDoDocumento(string $tipo, int $id): Model
    {
        return match ($tipo) {
            'visita' => $this->buscarVisita($id),
            'certificado' => $this->buscarCertificado($id),
            'contrato' => $this->buscarContrato($id),
            'fatura' => $this->buscarFatura($id),
            default => throw (new ModelNotFoundException)->setModel(static::class, [$id]),
        };
    }

    // -----------------------------------------------------------------
    // Consultas escopadas (empresa + cliente, sempre os dois)
    // -----------------------------------------------------------------

    private function consultaDeVisitas(): Builder
    {
        return WorkOrder::query()
            ->where('company_id', $this->clientUser->company_id)
            ->where('client_id', $this->clientUser->client_id);
    }

    private function consultaDeCertificados(): Builder
    {
        return Certificate::query()
            ->where('company_id', $this->clientUser->company_id)
            ->where('client_id', $this->clientUser->client_id)
            ->where('status', '!=', 'draft');
    }

    /**
     * `contracts` não tem `client_id` próprio: o vínculo passa por
     * `address_id`. O `where` de `client_id` (e o de `company_id`, de novo)
     * fica dentro do closure do `whereHas`, como condição explícita da
     * subconsulta, e não como um `whereHas('address')` solto. Ver o
     * cabeçalho da classe.
     */
    private function consultaDeContratos(): Builder
    {
        return Contract::query()
            ->where('company_id', $this->clientUser->company_id)
            ->whereHas('address', function (Builder $consulta): void {
                $consulta->where('company_id', $this->clientUser->company_id)
                    ->where('client_id', $this->clientUser->client_id);
            });
    }

    /**
     * `work_order_adequations` não tem `client_id` próprio: o vínculo passa
     * por `work_order_id`. Mesmo raciocínio de `consultaDeContratos()`.
     */
    private function consultaDeAdequacoes(): Builder
    {
        return WorkOrderAdequation::query()
            ->where('company_id', $this->clientUser->company_id)
            ->whereHas('workOrder', function (Builder $consulta): void {
                $consulta->where('company_id', $this->clientUser->company_id)
                    ->where('client_id', $this->clientUser->client_id);
            });
    }

    /**
     * `payment_details` não tem `client_id` próprio: o vínculo passa por
     * `work_order_id`. Mesmo raciocínio de `consultaDeContratos()`.
     */
    private function consultaDeFaturas(): Builder
    {
        return PaymentDetail::query()
            ->where('company_id', $this->clientUser->company_id)
            ->whereHas('workOrder', function (Builder $consulta): void {
                $consulta->where('company_id', $this->clientUser->company_id)
                    ->where('client_id', $this->clientUser->client_id);
            });
    }

    /**
     * `receivable_installments` não tem `client_id` próprio: o vínculo passa
     * por `receivable_id`. Mesmo raciocínio de `consultaDeContratos()`.
     * Acrescida na Task 19.6 (Plano 19), para achar a parcela a receber por
     * trás de uma fatura do portal.
     */
    private function consultaDeParcelas(): Builder
    {
        return ReceivableInstallment::query()
            ->where('company_id', $this->clientUser->company_id)
            ->whereHas('receivable', function (Builder $consulta): void {
                $consulta->where('company_id', $this->clientUser->company_id)
                    ->where('client_id', $this->clientUser->client_id);
            });
    }

    /**
     * `payment_detail_id` liga a parcela nova (Plano 18) ao dado antigo que o
     * portal usa como "fatura" (`PaymentDetail`, ver o docblock da classe).
     * `null` quando a fatura não tem parcela vinculada (fatura anterior à
     * migração da Task 18.2) - não é erro, só significa "esta fatura não tem
     * como virar cobrança".
     */
    private function parcelaDaFatura(int $faturaId): ?ReceivableInstallment
    {
        return $this->consultaDeParcelas()->where('payment_detail_id', $faturaId)->first();
    }

    // -----------------------------------------------------------------
    // Busca por id: 404 (ModelNotFoundException), nunca 403, nunca null
    // -----------------------------------------------------------------

    private function buscarVisita(int $id): WorkOrder
    {
        return $this->consultaDeVisitas()->find($id)
            ?? throw (new ModelNotFoundException)->setModel(WorkOrder::class, [$id]);
    }

    private function buscarCertificado(int $id): Certificate
    {
        return $this->consultaDeCertificados()->find($id)
            ?? throw (new ModelNotFoundException)->setModel(Certificate::class, [$id]);
    }

    private function buscarContrato(int $id): Contract
    {
        return $this->consultaDeContratos()->find($id)
            ?? throw (new ModelNotFoundException)->setModel(Contract::class, [$id]);
    }

    private function buscarFatura(int $id): PaymentDetail
    {
        return $this->consultaDeFaturas()->find($id)
            ?? throw (new ModelNotFoundException)->setModel(PaymentDetail::class, [$id]);
    }
}
