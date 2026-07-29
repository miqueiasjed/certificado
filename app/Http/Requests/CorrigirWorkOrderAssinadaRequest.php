<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação da correção justificada de uma ordem de serviço já assinada
 * (Plano 13, Task 13.4).
 *
 * Campos alteráveis: um subconjunto deliberadamente restrito do que
 * `WorkOrderRequest`/`WorkOrderController::update` aceitam. Ficam de fora
 * `client_id`, `address_id`, `service_id`, `technician_id`, `status`,
 * `rooms`, `devices` e `products`: são dado estrutural de execução, cada um
 * já com endpoint dedicado (ex.: `work-orders.rooms.add`), e mudar o
 * `status` de uma OS assinada é reabrir a execução, não corrigir um dado
 * dela. O que fica aqui é o texto e a classificação do documento em si, que é
 * o que de fato costuma precisar de ajuste depois que o cliente já assinou.
 */
class CorrigirWorkOrderAssinadaRequest extends FormRequest
{
    /**
     * Mesmo mínimo de `WorkOrderSignatureService::JUSTIFICATIVA_MINIMA`.
     * Duplicado aqui de propósito: o FormRequest devolve 422 com mensagem
     * amigável antes de chegar ao Service, que segue validando de novo como
     * última garantia para quem chamar `corrigirComJustificativa()` fora do
     * painel.
     */
    private const JUSTIFICATIVA_MINIMA = 20;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'justificativa' => ['required', 'string', 'min:'.self::JUSTIFICATIVA_MINIMA],
            'description' => 'sometimes|nullable|string|max:1000',
            'observations' => 'sometimes|nullable|string|max:1000',
            'completion_notes' => 'sometimes|nullable|string|max:1000',
            'priority_level' => 'sometimes|in:low,medium,high,urgent,emergency',
            'scheduled_date' => 'sometimes|date',
            'start_time' => 'sometimes|nullable|date_format:Y-m-d\TH:i',
            'end_time' => 'sometimes|nullable|date_format:Y-m-d\TH:i|after:start_time',
        ];
    }

    public function messages(): array
    {
        return [
            'justificativa.required' => 'Informe a justificativa da correção.',
            'justificativa.min' => 'A justificativa da correção precisa ter pelo menos '
                .self::JUSTIFICATIVA_MINIMA.' caracteres, para explicar de verdade o motivo a quem for auditar depois.',
            'priority_level.in' => 'O nível de prioridade selecionado é inválido.',
            'scheduled_date.date' => 'A data agendada deve ser uma data válida.',
            'end_time.after' => 'O horário de término deve ser posterior ao horário de início.',
        ];
    }
}
