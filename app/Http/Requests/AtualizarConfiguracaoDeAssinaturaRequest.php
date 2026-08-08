<?php

namespace App\Http\Requests;

use App\Models\SignatureProviderConfig;
use App\Services\Signature\ResolvedorDeProvedor;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação de `PUT /assinaturas/configuracao` (Plano 26, Task 26.4): a
 * credencial do provedor de assinatura eletrônica do tenant.
 *
 * `authorize()` confere `assinatura-eletronica-configurar` de propósito, além
 * do `permission:` já aplicado na rota — duas barreiras independentes para a
 * ação que grava um segredo capaz de assinar contrato em nome da empresa.
 * Mesmo critério de `AtualizarConfiguracaoDeCobrancaRequest` (Plano 19).
 *
 * Todo campo é opcional: a tela permite, por exemplo, só desligar a
 * integração sem reenviar a credencial. O controller nunca sobrescreve
 * `credenciais` com um array vazio quando o campo não veio.
 */
class AtualizarConfiguracaoDeAssinaturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('assinatura-eletronica-configurar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provedor' => ['nullable', 'string', 'in:'.implode(',', ResolvedorDeProvedor::provedoresConhecidos())],
            'ambiente' => ['nullable', 'string', 'in:'.implode(',', SignatureProviderConfig::AMBIENTES)],
            'credenciais' => ['nullable', 'array'],
            'credenciais.token' => ['nullable', 'string', 'max:255'],
            'credenciais.webhook_secret' => ['nullable', 'string', 'max:255'],
            'ativo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'provedor.in' => 'Provedor de assinatura desconhecido.',
            'ambiente.in' => 'Ambiente inválido. Use sandbox ou produção.',
        ];
    }
}
