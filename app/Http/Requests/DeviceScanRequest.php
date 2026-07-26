<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação da leitura de um código de dispositivo.
 *
 * Valida apenas `work_order_id`, que chega pela query string. O código em si,
 * que vem no caminho da rota, NÃO é validado aqui, e isso é decisão de projeto:
 * código malformado tem que sair como `nao_encontrado` com 200, igualzinho a
 * código inexistente, e uma regra de validação o transformaria em 422. Um 422
 * distinguível seria um oráculo de formato para quem varre códigos alheios. Quem
 * recusa o formato é `App\Support\CodigoDeDispositivo::valido()`, dentro do
 * Service, antes de qualquer consulta ao banco.
 *
 * `work_order_id` sem `exists:work_orders,id` pelo mesmo motivo que aparece em
 * `StoreDeviceBatchRequest`: a regra crua varre a tabela sem escopo e enxergaria
 * a ordem de serviço de outro tenant. A ordem é buscada pelo model, com o escopo
 * global por empresa ativo, dentro de `DeviceScanService`, e id que não resolve
 * ali vira 404.
 *
 * `authorize()` devolve true porque a rota inteira está protegida por
 * `permission:dispositivo-ver` (ver routes/web.php), e o vínculo do técnico com
 * a ordem de serviço informada é verificado por `WorkOrderAccessService` no
 * Service, que é onde essa autorização depende da instância.
 */
class DeviceScanRequest extends FormRequest
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
            'work_order_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'work_order_id.integer' => 'A ordem de serviço informada é inválida.',
            'work_order_id.min' => 'A ordem de serviço informada é inválida.',
        ];
    }

    /**
     * Id da ordem de serviço em execução, ou null para leitura avulsa.
     */
    public function ordemDeServicoId(): ?int
    {
        $id = $this->validated('work_order_id');

        return $id === null ? null : (int) $id;
    }
}
