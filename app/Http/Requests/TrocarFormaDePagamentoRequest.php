<?php

namespace App\Http\Requests;

use App\Services\SubscriptionService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação da troca de forma de pagamento da assinatura do tenant
 * (Plano 7, Task 7.8).
 *
 * `forma` valida contra `SubscriptionService::FORMAS`, a mesma fonte que o
 * Service usa para recusar forma desconhecida: duplicar a lista aqui com
 * literais soltos é o que faria as duas divergirem na próxima forma de
 * pagamento adicionada.
 *
 * `cartao_cifrado` só é exigido quando a forma é `cartao`, e mesmo assim o
 * Service confere de novo (`exigirCartaoQuandoNecessario()`): esta validação
 * dá a mensagem em português cedo, na tela; a de lá é a barreira que vale
 * também para quem chamar o Service fora de uma requisição HTTP.
 *
 * `authorize()` devolve true porque a rota já está atrás do middleware
 * `permission:assinatura-gerenciar` (ver routes/web.php): não é rota de
 * autoatendimento sem permissão nem de portal do cliente.
 */
class TrocarFormaDePagamentoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'forma' => ['required', 'string', Rule::in(SubscriptionService::FORMAS)],
            'cartao_cifrado' => [
                'nullable',
                'string',
                Rule::requiredIf($this->input('forma') === SubscriptionService::FORMA_CARTAO),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'forma.required' => 'Selecione a forma de pagamento.',
            'forma.in' => 'Forma de pagamento inválida.',
            'cartao_cifrado.required' => 'Cartão obrigatório para pagamento no cartão.',
        ];
    }
}
