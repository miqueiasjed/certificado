<?php

namespace App\Http\Requests;

use App\Models\PaymentGatewayConfig;
use App\Services\Payments\ReguaDeCobrancaService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação de `PUT /cobrancas/configuracao` (Plano 19, Task 19.6): a
 * credencial do gateway de pagamento do tenant e a régua de cobrança, no
 * mesmo formulário.
 *
 * `authorize()` confere `cobranca-configurar` de propósito, além do
 * `permission:cobranca-configurar` já aplicado na rota
 * (`routes/web.php`): a regra de negócio "usuário sem
 * `cobranca-configurar` não salva credencial mesmo tentando direto pelo
 * endpoint" (Task 19.6) precisa continuar valendo mesmo que algum dia a
 * rota perca o middleware por descuido - duas barreiras independentes para
 * a ação mais sensível deste plano (grava o segredo que fala com o dinheiro
 * do tenant).
 *
 * Todo campo é opcional: a tela salva credencial e régua juntas, mas nada
 * impede salvar só uma das duas (por exemplo, só desligar a régua, sem
 * reenviar a credencial - o controller nunca sobrescreve `credenciais` com
 * um array vazio quando o campo não veio, ver `ChargeController::configuracaoAtualizar()`).
 */
class AtualizarConfiguracaoDeCobrancaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('cobranca-configurar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'gateway' => ['nullable', 'string', 'max:50'],
            'ambiente' => ['nullable', 'string', 'in:'.implode(',', PaymentGatewayConfig::AMBIENTES)],
            'credenciais' => ['nullable', 'array'],
            'ativo' => ['nullable', 'boolean'],
            'regua_ativa' => ['nullable', 'boolean'],
            'marcos_ativos' => ['nullable', 'array'],
            'marcos_ativos.*' => ['string', 'in:'.implode(',', ReguaDeCobrancaService::marcos())],
            'antecedencia_emissao_dias' => ['nullable', 'integer', 'min:1', 'max:60'],
            'emissao_pelo_cliente_habilitada' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'ambiente.in' => 'Ambiente inválido. Use sandbox ou produção.',
            'marcos_ativos.*.in' => 'Marco da régua de cobrança inválido.',
            'antecedencia_emissao_dias.min' => 'A antecedência mínima é de 1 dia.',
            'antecedencia_emissao_dias.max' => 'A antecedência máxima é de 60 dias.',
        ];
    }
}
