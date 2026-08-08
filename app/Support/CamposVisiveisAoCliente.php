<?php

namespace App\Support;

use App\Models\Certificate;
use App\Models\Charge;
use App\Models\Contract;
use App\Models\PaymentDetail;
use App\Models\WorkOrder;
use App\Models\WorkOrderAdequation;

/**
 * Lista de permissão dos campos que saem para o cliente no portal (Plano 15,
 * Task 15.3).
 *
 * ## Lista de permissão, nunca de proibição
 *
 * Cada entidade tem uma constante com as chaves que o portal pode devolver, e
 * um método estático que monta o array campo a campo, direto dos atributos do
 * model. Nenhum método aqui faz `$model->toArray()` (ou `toJson()`) seguido de
 * `Arr::except()`: filtrar depois de serializar tudo significa que um atributo
 * novo, criado meses depois no Model por outro motivo qualquer, nasceria
 * visível ao cliente por padrão. Construir campo a campo garante o oposto:
 * campo novo nasce invisível até alguém decidir, aqui, que ele pode sair.
 *
 * ## Nunca exposto
 *
 * Custo, margem, comissão, observação interna, dado de outro cliente, e
 * qualquer informação de técnico além do nome. Por isso os relacionamentos
 * (serviço, técnico, endereço) são achatados em string/valor simples, nunca o
 * model relacionado inteiro.
 *
 * ## Datas
 *
 * Campo que representa um dia (`scheduled_date`, `execution_date`,
 * `warranty`, `deadline`, `payment_due_date`...) sai como `Y-m-d`, sem
 * conversão de fuso, igual à regra da skill de datas e timezone. Campo que
 * representa um instante (`start_time`, `end_time`) é convertido para o fuso
 * do negócio antes de virar string, porque aqui é ponto de exibição ao
 * cliente.
 *
 * ## Decisões de design não explícitas na Task 15.3 (documentadas)
 *
 * - `Certificate.status` bruto hoje só vale `active`/`expired`/`cancelled` (a
 *   migration `2025_08_26_200507_update_certificates_status_values` já
 *   consolidou `draft` e `issued` em `active`, ver o cabeçalho de
 *   `PortalService` para o achado completo). O valor exposto como `status`
 *   é mesmo assim o calculado (`calculated_status`), não o bruto, para ficar
 *   idêntico ao que a tela interna usa para colorir o status, e para que o
 *   dia em que `draft`/`issued` voltarem ao enum não exija mexer aqui.
 * - `Contract.service_value` é exposto: é o valor contratado que o próprio
 *   cliente paga, não custo interno, margem ou comissão da operação.
 *   `Contract.payment_details` (dados bancários da empresa para receber) fica
 *   de fora por não ser conteúdo do contrato em si.
 * - `PaymentDetail` expõe um único campo `amount` (o valor final da ordem de
 *   serviço à qual a parcela pertence, já que os valores de
 *   `payment_details.total_cost/discount_amount/final_amount` são resquícios
 *   de uma migração anterior, ver o comentário de
 *   `PaymentDetail::getPaymentStatusAttribute()`) em vez de replicar as três
 *   colunas antigas, para não expor nomes de coluna que soam a "custo".
 */
final class CamposVisiveisAoCliente
{
    /**
     * @var array<int, string>
     */
    public const VISITA = [
        'id',
        'order_number',
        'scheduled_date',
        'start_time',
        'end_time',
        'status',
        'status_text',
        'description',
        'service',
        'technician',
        'address',
    ];

    /**
     * @var array<int, string>
     */
    public const CERTIFICADO = [
        'id',
        'certificate_number',
        'execution_date',
        'warranty',
        'status',
        'status_text',
        'procedure_used',
        'service',
        'address',
    ];

    /**
     * @var array<int, string>
     */
    public const CONTRATO = [
        'id',
        'contract_number',
        'start_date',
        'end_date',
        'service_type',
        'visit_frequency_valor',
        'visit_frequency_unidade',
        'visit_count',
        'pest_target',
        'service_value',
        'payment_method',
        'additional_clause',
        'jurisdiction',
        'address',
    ];

    /**
     * @var array<int, string>
     */
    public const ADEQUACAO = [
        'id',
        'type',
        'description',
        'priority',
        'status',
        'status_text',
        'deadline',
        'visit_order_number',
        'visit_scheduled_date',
    ];

    /**
     * @var array<int, string>
     */
    public const FATURA = [
        'id',
        'amount',
        'amount_paid',
        'payment_due_date',
        'payment_date',
        'payment_status',
        'payment_status_text',
        'payment_method',
        'payment_method_text',
        'is_partial_payment',
        'visit_order_number',
    ];

    /**
     * Cobrança (boleto ou Pix, Plano 19) ativa de uma fatura, exibida ao
     * cliente no portal (Task 19.6) para ele pagar. Nunca inclui
     * `gateway`/`gateway_charge_id` (referência interna do provedor, sem
     * valor para o cliente final) nem qualquer dado do gateway em si.
     *
     * @var array<int, string>
     */
    public const COBRANCA = [
        'id',
        'tipo',
        'situacao',
        'vencimento',
        'valor',
        'link_pagamento',
        'linha_digitavel',
        'qr_code_pix',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function visita(WorkOrder $visita): array
    {
        return [
            'id' => $visita->id,
            'order_number' => $visita->order_number,
            'scheduled_date' => $visita->scheduled_date?->toDateString(),
            'start_time' => self::instante($visita->start_time),
            'end_time' => self::instante($visita->end_time),
            'status' => $visita->status,
            'status_text' => $visita->status_text,
            'description' => $visita->description,
            'service' => $visita->service?->name,
            'technician' => $visita->technician?->name,
            'address' => $visita->address?->full_address,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function certificado(Certificate $certificado): array
    {
        return [
            'id' => $certificado->id,
            'certificate_number' => $certificado->certificate_number,
            'execution_date' => $certificado->execution_date?->toDateString(),
            'warranty' => $certificado->warranty?->toDateString(),
            'status' => $certificado->calculated_status,
            'status_text' => $certificado->status_text,
            'procedure_used' => $certificado->procedure_used,
            'service' => $certificado->service?->name,
            'address' => $certificado->address?->full_address,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function contrato(Contract $contrato): array
    {
        return [
            'id' => $contrato->id,
            'contract_number' => $contrato->contract_number,
            'start_date' => $contrato->start_date?->toDateString(),
            'end_date' => $contrato->end_date?->toDateString(),
            'service_type' => $contrato->service_type,
            'visit_frequency_valor' => $contrato->visit_frequency_valor,
            'visit_frequency_unidade' => $contrato->visit_frequency_unidade,
            'visit_count' => $contrato->visit_count,
            'pest_target' => $contrato->pest_target,
            'service_value' => $contrato->service_value === null ? null : (float) $contrato->service_value,
            'payment_method' => $contrato->payment_method,
            'additional_clause' => $contrato->additional_clause,
            'jurisdiction' => $contrato->jurisdiction,
            'address' => $contrato->address?->full_address,
            // Assinatura eletrônica (Plano 26, Task 26.4). Só a situação e a
            // data: o cliente precisa saber que o contrato está em assinatura,
            // ou que já foi assinado e a via está disponível.
            //
            // O link do provedor **não** entra aqui, de propósito. Quem
            // autentica o signatário é o provedor, pelo convite que ele mesmo
            // manda; republicar esse link numa tela autenticada por outra
            // credencial (a do portal) permitiria que qualquer pessoa com
            // acesso ao portal do cliente assinasse em nome de quem foi
            // convidado, e é exatamente a comprovação de autoria que dá valor
            // ao contrato assinado a distância.
            'situacao_assinatura' => $contrato->situacao_assinatura,
            'assinado_em' => $contrato->assinado_em?->toIso8601String(),
            // Verdadeiro quando existe arquivo assinado arquivado, e portanto
            // quando o download faz sentido na tela.
            'tem_via_assinada' => $contrato->situacao_assinatura === 'assinado'
                && $contrato->signatureRequests()
                    ->where('situacao', 'assinado')
                    ->whereNotNull('arquivo_assinado_path')
                    ->exists(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function adequacao(WorkOrderAdequation $adequacao): array
    {
        return [
            'id' => $adequacao->id,
            'type' => $adequacao->type,
            'description' => $adequacao->description,
            'priority' => $adequacao->priority,
            'status' => $adequacao->status,
            'status_text' => self::textoDaAdequacao($adequacao->status),
            'deadline' => $adequacao->deadline?->toDateString(),
            'visit_order_number' => $adequacao->workOrder?->order_number,
            'visit_scheduled_date' => $adequacao->workOrder?->scheduled_date?->toDateString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function fatura(PaymentDetail $fatura): array
    {
        return [
            'id' => $fatura->id,
            'amount' => self::valorDaFatura($fatura),
            'amount_paid' => (float) ($fatura->amount_paid ?? 0),
            'payment_due_date' => $fatura->payment_due_date?->toDateString(),
            'payment_date' => $fatura->payment_date?->toDateString(),
            'payment_status' => $fatura->payment_status,
            'payment_status_text' => $fatura->payment_status_text,
            'payment_method' => $fatura->payment_method,
            'payment_method_text' => $fatura->payment_method_text,
            'is_partial_payment' => (bool) $fatura->is_partial_payment,
            'visit_order_number' => $fatura->workOrder?->order_number,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function cobranca(Charge $cobranca): array
    {
        return [
            'id' => $cobranca->id,
            'tipo' => $cobranca->tipo,
            'situacao' => $cobranca->situacao,
            'vencimento' => $cobranca->vencimento?->toDateString(),
            'valor' => (float) $cobranca->valor,
            'link_pagamento' => $cobranca->link_pagamento,
            'linha_digitavel' => $cobranca->linha_digitavel,
            'qr_code_pix' => $cobranca->qr_code_pix,
        ];
    }

    /**
     * O total cobrado vive em `work_orders` desde a migração de valores (ver
     * `PaymentDetail::getPaymentStatusAttribute()`). `payment_details` guarda
     * as mesmas colunas por herança do schema antigo, mas não são a fonte de
     * verdade: cair para elas só quando a ordem de serviço não estiver
     * carregada evita mostrar um valor desatualizado ao cliente.
     */
    private static function valorDaFatura(PaymentDetail $fatura): float
    {
        $ordem = $fatura->workOrder;

        $valor = $ordem?->final_amount ?? $ordem?->total_cost
            ?? $fatura->final_amount ?? $fatura->total_cost ?? 0;

        return (float) $valor;
    }

    private static function textoDaAdequacao(?string $status): string
    {
        return match ($status) {
            'pendente' => 'Pendente',
            'concluido' => 'Concluído',
            default => 'Não definido',
        };
    }

    /**
     * Converte um instante para o fuso do negócio antes de virar string. Este
     * é um ponto de exibição ao cliente, então a conversão é obrigatória (a
     * skill de datas e timezone trata backend em UTC e exibição sempre em
     * America/Sao_Paulo).
     */
    private static function instante(mixed $valor): ?string
    {
        return BusinessDate::paraFusoNegocio($valor)?->toDateTimeString();
    }
}
