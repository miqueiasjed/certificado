<?php

namespace App\Http\Requests\Portal;

use App\Models\ClientUser;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação da abertura de solicitação de atendimento pelo portal (Plano 15,
 * Task 15.5): assunto, descrição e endereço opcional.
 *
 * Sem campo de prioridade de propósito: o formulário nunca aceita esse dado
 * do cliente (`ClientRequestService::abrir()` sempre grava `normal`), então
 * nem existe regra para ele aqui.
 *
 * `authorize()` sempre `true`: a rota já está inteiramente atrás de
 * `AutenticarCliente` (sessão do guard `cliente`) e de
 * `EnsurePortalModuleIsActive`, e esta ação cria um registro novo - não há
 * "dono de um recurso existente" para conferir, ao contrário de
 * `GET /portal/solicitacoes/{id}` (escopado em
 * `ClientRequestService::minha()`). O único id vindo do corpo é
 * `address_id`, opcional, e ele é escopado direto na regra de validação
 * (`Rule::exists` com `company_id` e `client_id` do cliente autenticado),
 * cumprindo a exigência da skill de multiempresa para id vindo do corpo sem
 * precisar de uma checagem de autorização à parte.
 */
class AbrirSolicitacaoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var ClientUser $cliente */
        $cliente = $this->user('cliente');

        return [
            'assunto' => ['required', 'string', 'max:255'],
            'descricao' => ['required', 'string', 'max:5000'],
            'address_id' => [
                'nullable',
                'integer',
                Rule::exists('addresses', 'id')->where(function ($query) use ($cliente): void {
                    $query->where('company_id', $cliente->company_id)
                        ->where('client_id', $cliente->client_id);
                }),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'assunto.required' => 'Informe o assunto da solicitação.',
            'assunto.max' => 'O assunto pode ter no máximo 255 caracteres.',
            'descricao.required' => 'Descreva o que você precisa.',
            'descricao.max' => 'A descrição pode ter no máximo 5000 caracteres.',
            'address_id.integer' => 'Endereço inválido.',
            'address_id.exists' => 'O endereço selecionado não é válido.',
        ];
    }
}
