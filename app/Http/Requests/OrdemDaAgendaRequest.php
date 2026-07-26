<?php

namespace App\Http\Requests;

use App\Models\WorkOrder;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base das requisições que alteram o agendamento de uma ordem de serviço pelo
 * calendário (reagendar e atribuir técnico).
 *
 * Existe para que a recusa de ordem finalizada fique escrita uma vez só. Regra
 * de segurança repetida em dois arquivos é regra que um dia é corrigida em um
 * e esquecida no outro.
 *
 * Autorização
 * -----------
 * `authorize()` devolve `true` porque a rota já exige
 * `permission:ordem-servico-editar`, e a checagem por registro (técnico só
 * mexe na própria ordem de serviço) fica no controller, em
 * `garantirAcesso()`, exatamente como o `WorkOrderController` faz em todos os
 * métodos. Concentrar a regra em um lugar só vale mais do que espalhá-la.
 */
abstract class OrdemDaAgendaRequest extends FormRequest
{
    /**
     * Situações que tiram a ordem de serviço do planejamento.
     *
     * Concluída não volta para a agenda por arrastar e soltar: reabrir é outro
     * fluxo, com regra própria. Cancelada, pela mesma razão.
     */
    public const SITUACOES_FINALIZADAS = ['completed', 'cancelled'];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Ordem de serviço vinda do route model binding, já dentro do escopo de
     * empresa (o binding não encontra ordem de outra empresa e responde 404).
     */
    protected function ordem(): ?WorkOrder
    {
        $ordem = $this->route('workOrder');

        return $ordem instanceof WorkOrder ? $ordem : null;
    }

    /**
     * @param  \Illuminate\Validation\Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $this->recusarOrdemFinalizada($validator);
            $this->regrasAdicionais($validator);
        });
    }

    /**
     * Ponto de extensão para as regras próprias de cada requisição filha.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     */
    protected function regrasAdicionais($validator): void
    {
        //
    }

    /**
     * @param  \Illuminate\Validation\Validator  $validator
     */
    private function recusarOrdemFinalizada($validator): void
    {
        $ordem = $this->ordem();

        if ($ordem === null || ! in_array($ordem->status, self::SITUACOES_FINALIZADAS, true)) {
            return;
        }

        $validator->errors()->add('status', $ordem->status === 'cancelled'
            ? 'Esta ordem de serviço está cancelada. Cancelada não volta para a agenda: reabra a ordem de serviço antes de agendar.'
            : 'Esta ordem de serviço já foi concluída e não pode ser reagendada. Reabrir a ordem é outro fluxo, com regra própria.');
    }
}
