<?php

namespace App\Http\Requests;

use App\Models\PersonalProtectiveEquipment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Cadastro do modelo de EPI que a empresa compra (Plano 28, Task 28.4).
 *
 * É o cadastro, não a ficha: a entrega ao técnico é `PpeDeliveryRequest`.
 *
 * Aqui só entra o que é **forma** do dado. As regras de negócio ficam no
 * `PpeService` (Task 28.2), que também é chamado fora de requisição HTTP:
 * normalização do nome, o padrão `outro` para o tipo ausente, a distinção entre
 * vida útil ausente e zero e a conversão de `validade_ca` para um dia no fuso do
 * negócio.
 *
 * Campo em branco é estado neutro, nunca irregularidade
 * ----------------------------------------------------
 * `numero_ca` e `validade_ca` são opcionais de propósito: a empresa cadastra o
 * EPI antes de ter o certificado em mãos, e exigir o CA aqui empurraria o
 * usuário a inventar um número para conseguir salvar. O EPI sem CA fica fora da
 * varredura de vencimento (Task 28.3) em vez de virar alerta falso — só não pode
 * ser entregue com o CA **vencido**, que é outra coisa e é recusa do
 * `PpeDeliveryService`.
 *
 * `vida_util_dias` em branco significa "sem troca programada", e não um prazo
 * padrão inventado. Por isso `nullable` e não um `default` de validação: prazo
 * inventado vira alerta falso, e alerta falso faz o usuário desligar o alerta
 * inteiro.
 *
 * Não há `Rule::unique` neste cadastro, e é deliberado: a tabela não tem unique
 * de `nome` nem de `numero_ca` (ver a migration da Task 28.1). O mesmo número de
 * CA cobre modelos diferentes do mesmo fabricante, e recusar o segundo obrigaria
 * o usuário a mentir no cadastro.
 *
 * Nenhum campo aqui é id de outro registro, então não há o que escopar ao
 * tenant: o vínculo com a empresa é gravado pela trait `BelongsToCompany` do
 * model, e o `{epi}` da rota de edição chega por route-model binding, que não
 * encontra registro de outra empresa e responde 404.
 */
class PersonalProtectiveEquipmentRequest extends FormRequest
{
    /**
     * A rota inteira já está sob `module:epi` + `permission:epi-gerenciar`, e o
     * escopo global por empresa responde 404 para EPI de outro tenant.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $obrigatorio = $this->isMethod('POST') ? 'required' : 'sometimes|required';

        return [
            'nome' => $obrigatorio.'|string|max:255',
            'tipo' => ['nullable', Rule::in(PersonalProtectiveEquipment::TIPOS)],
            'fabricante' => 'nullable|string|max:255',
            'numero_ca' => 'nullable|string|max:255',
            'validade_ca' => 'nullable|date',
            'vida_util_dias' => 'nullable|integer|min:0|max:36500',
            'obrigatorio' => 'boolean',
            'ativo' => 'boolean',
            'observacoes' => 'nullable|string|max:5000',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'Informe o nome do EPI.',
            'nome.max' => 'O nome do EPI pode ter no máximo 255 caracteres.',
            'tipo.in' => 'Tipo de EPI inválido.',
            'validade_ca.date' => 'Informe uma data válida para a validade do CA.',
            'vida_util_dias.integer' => 'A vida útil precisa ser um número de dias.',
            'vida_util_dias.min' => 'A vida útil em dias não pode ser negativa. '
                .'Deixe em branco quando o EPI não tem troca programada.',
            'vida_util_dias.max' => 'A vida útil em dias está fora de qualquer prazo real de troca.',
        ];
    }
}
