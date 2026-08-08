<?php

namespace App\Http\Requests;

use App\Models\SignatureSigner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Validação de `POST /contratos/{contract}/assinatura` (Plano 26, Task 26.4).
 *
 * `authorize()` confere `contrato-enviar-assinatura` de propósito, além do
 * `permission:` já aplicado na rota: enviar contrato é ação de efeito
 * **externo** — o documento sai para o e-mail do cliente e não há como
 * recolher — e a regra precisa continuar valendo mesmo que a rota perca o
 * middleware por descuido. Mesmo critério de
 * `AtualizarConfiguracaoDeCobrancaRequest` (Plano 19).
 *
 * As duas regras de negócio que moram aqui, e não no Service, existem porque
 * são sobre o **formato do pedido**, não sobre o estado do contrato:
 *
 * - Pelo menos um contratante e um contratada. Contrato assinado por uma
 *   parte só não é contrato, e descobrir isso depois do envio custa um
 *   documento no provedor e um e-mail ao cliente.
 * - Nenhum e-mail repetido. Dois signatários com o mesmo e-mail quebrariam o
 *   casamento por e-mail que `SignatureRequestService::atualizarSignatarios()`
 *   usa para saber quem assinou, e a trilha de auditoria acabaria atribuída à
 *   pessoa errada.
 */
class EnviarContratoParaAssinaturaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('contrato-enviar-assinatura') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'signatarios' => ['required', 'array', 'min:1', 'max:10'],
            'signatarios.*.nome' => ['required', 'string', 'max:255'],
            'signatarios.*.email' => ['required', 'email:rfc', 'max:255'],
            'signatarios.*.papel' => ['required', 'string', 'in:'.implode(',', SignatureSigner::PAPEIS)],
            'signatarios.*.ordem' => ['nullable', 'integer', 'min:1', 'max:10'],
            'signatarios.*.documento' => ['nullable', 'string', 'max:20'],

            // O provedor conta o prazo em dias a partir do envio. Mínimo de
            // 1 e máximo de 90: prazo maior que isso, na prática, é contrato
            // esquecido, e o pedido em aberto trava a edição do contrato o
            // tempo todo.
            'dias_para_expirar' => ['nullable', 'integer', 'min:1', 'max:90'],
            'mensagem' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function withValidator(Validator $validador): void
    {
        $validador->after(function (Validator $validador): void {
            $signatarios = $this->input('signatarios');

            if (! is_array($signatarios) || $signatarios === []) {
                return;
            }

            $papeis = array_column($signatarios, 'papel');

            foreach (['contratante', 'contratada'] as $papelObrigatorio) {
                if (! in_array($papelObrigatorio, $papeis, true)) {
                    $validador->errors()->add(
                        'signatarios',
                        "O pedido precisa de pelo menos um signatário com o papel \"{$papelObrigatorio}\": "
                        .'contrato assinado por uma parte só não é contrato.'
                    );
                }
            }

            $emails = array_map(
                static fn (mixed $signatario): string => is_array($signatario)
                    ? mb_strtolower(trim((string) ($signatario['email'] ?? '')))
                    : '',
                $signatarios
            );

            if (count($emails) !== count(array_unique($emails))) {
                $validador->errors()->add(
                    'signatarios',
                    'Dois signatários não podem ter o mesmo e-mail: é por ele que o sistema sabe quem assinou.'
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'signatarios.required' => 'Informe pelo menos um signatário.',
            'signatarios.*.email.email' => 'Informe um e-mail válido para cada signatário: é para ele que o contrato vai.',
            'signatarios.*.papel.in' => 'Papel de signatário inválido. Use contratante, contratada ou testemunha.',
            'dias_para_expirar.max' => 'O prazo máximo de assinatura é de 90 dias.',
        ];
    }
}
