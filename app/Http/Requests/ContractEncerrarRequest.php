<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do encerramento de contrato.
 *
 * `ContractService::encerrar()` já existia, mas sem rota nem tela: a recusa
 * de `ContractService::excluir()` (contrato com visita já executada) mandava
 * o usuário para uma ação que ele não tinha como executar. Esta classe fecha
 * essa ponta.
 *
 * `authorize()` devolve true pelo mesmo motivo de `ContractRequest`: a rota
 * já é inteiramente protegida por permissão do Spatie (`contrato-editar`, ver
 * routes/web.php), e não é rota de autoatendimento nem de portal do cliente.
 */
class ContractEncerrarRequest extends FormRequest
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
            // Opcional, mas quando informado é o que explica, meses depois,
            // por que as visitas futuras daquele contrato foram canceladas: é
            // anexado ao histórico de cada OS cancelada.
            'motivo' => 'nullable|string|max:1000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'motivo.string' => 'O motivo deve ser um texto.',
            'motivo.max' => 'O motivo pode ter no máximo 1000 caracteres.',
        ];
    }
}
