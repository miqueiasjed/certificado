<?php

namespace App\Http\Requests\Portal;

use App\Models\Charge;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação de `POST /portal/faturas/{parcela}/cobranca` (Plano 19, Task
 * 19.6): o cliente final escolhe boleto ou Pix para a própria fatura.
 *
 * `authorize()` sempre `true`, mesmo critério de
 * `App\Http\Requests\Portal\AbrirSolicitacaoRequest`: a rota já está atrás de
 * `AutenticarCliente` (sessão do guard `cliente`) e de
 * `EnsurePortalModuleIsActive`. As demais condições desta ação - módulo
 * `cobranca_recorrente` ativo para o tenant, emissão pelo cliente habilitada
 * pela empresa (`CompanyBillingSetting`), fatura dona do cliente autenticado
 * - dependem do estado do banco e da instância, e por isso são conferidas em
 * `App\Http\Controllers\Portal\PortalPagamentoController`, não aqui.
 */
class GerarCobrancaNoPortalRequest extends FormRequest
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
        return [
            'tipo' => ['required', 'string', 'in:'.implode(',', Charge::TIPOS)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Informe o tipo de cobrança: boleto ou pix.',
            'tipo.in' => 'Tipo de cobrança inválido. Use boleto ou pix.',
        ];
    }
}
