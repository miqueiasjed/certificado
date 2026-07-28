<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do lote de sincronização do aplicativo do técnico (Plano 12,
 * Task 12.5).
 *
 * `authorize() => true`: a rota já está inteiramente protegida por
 * `auth:sanctum`, e a única decisão de acesso que falta (o técnico só alcança
 * a própria ordem de serviço) fica com `WorkOrderAccessService`, dentro de
 * `AppSyncService::aplicar()`. Este Request cuida só do formato do lote: toda
 * decisão de domínio (idempotência, conflito, recusa) fica no Service, nunca
 * aqui.
 */
class SyncBatchRequest extends FormRequest
{
    /**
     * Tamanho máximo do lote por chamada.
     *
     * O técnico volta de um dia inteiro sem sinal com centenas de operações
     * na fila local. Um lote maior que este estoura o tempo limite da
     * requisição e é reenviado inteiro pelo aplicativo, em ciclo.
     */
    public const MAXIMO_DE_OPERACOES_POR_LOTE = 50;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'operacoes' => ['required', 'array', 'min:1', 'max:'.self::MAXIMO_DE_OPERACOES_POR_LOTE],

            // Gerado no celular, no instante do registro: é a chave da
            // idempotência que `AppSyncService::aplicar()` usa para reconhecer
            // o reenvio de uma operação cuja resposta se perdeu na rede.
            'operacoes.*.uuid' => ['required', 'uuid'],

            // evento_dispositivo, avistamento, adequacao, foto, conclusao_os.
            'operacoes.*.tipo' => ['required', 'string', 'max:255'],

            'operacoes.*.work_order_id' => ['nullable', 'integer'],

            // Instante do celular, distinto do instante do servidor.
            'operacoes.*.registrada_em' => ['required', 'date'],

            // `updated_at` da ordem de serviço que o aplicativo tinha na
            // última carga. Ausente quando a operação não depende de OS.
            'operacoes.*.updated_at_conhecido' => ['nullable', 'date'],

            'operacoes.*.payload' => ['required', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'operacoes.required' => 'Informe ao menos uma operação para sincronizar.',
            'operacoes.array' => 'O lote de operações precisa ser uma lista.',
            'operacoes.min' => 'Informe ao menos uma operação para sincronizar.',
            'operacoes.max' => 'O lote aceita no máximo '.self::MAXIMO_DE_OPERACOES_POR_LOTE.' operações por chamada.',
            'operacoes.*.uuid.required' => 'Cada operação precisa de um identificador único (uuid).',
            'operacoes.*.uuid.uuid' => 'O identificador de cada operação precisa ser um uuid válido.',
            'operacoes.*.tipo.required' => 'Cada operação precisa informar o tipo.',
            'operacoes.*.work_order_id.integer' => 'O identificador da ordem de serviço precisa ser numérico.',
            'operacoes.*.registrada_em.required' => 'Cada operação precisa informar quando foi registrada no aparelho.',
            'operacoes.*.registrada_em.date' => 'A data de registro de uma das operações é inválida.',
            'operacoes.*.updated_at_conhecido.date' => 'A data conhecida da ordem de serviço é inválida em uma das operações.',
            'operacoes.*.payload.required' => 'Cada operação precisa do conteúdo (payload) registrado.',
            'operacoes.*.payload.array' => 'O conteúdo (payload) de cada operação precisa ser um objeto.',
        ];
    }
}
