<?php

namespace App\Http\Requests;

use App\Models\NormativeReference;
use App\Support\TenantAtual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação do cadastro da referência normativa do tenant (Plano 24,
 * Task 24.5).
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:conformidade-gerenciar` e `module:conformidade`, e porque não há
 * identificador de dono no corpo: a referência criada aqui pertence sempre ao
 * tenant corrente (o controller preenche `company_id`, nunca a requisição). O
 * registro alterado ou removido chega por route-model binding, e a checagem de
 * dono dele fica no controller, que é onde o motivo pode ser explicado ao
 * usuário — o escopo global não protege este model, pelo motivo registrado em
 * `NormativeReference`.
 *
 * A unique é composta `[company_id, chave]`, e a regra abaixo reproduz isso:
 * o tenant não pode ter duas referências da mesma chave, mas pode ter uma
 * chave que outro tenant também tem, e pode ter a mesma chave que a referência
 * padrão da plataforma (`company_id` nulo) — sobrescrevê-la é justamente para
 * isso que este cadastro existe.
 */
class NormativeReferenceRequest extends FormRequest
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
        $empresa = TenantAtual::id();
        $referencia = $this->route('referencia');

        return [
            'chave' => [
                'required',
                'string',
                'max:60',
                'regex:/^[a-z0-9_]+$/',
                Rule::unique('normative_references', 'chave')
                    ->where('company_id', $empresa)
                    ->ignore($referencia?->id),
            ],
            'texto' => ['required', 'string', 'max:255'],
            'texto_curto' => ['nullable', 'string', 'max:255'],
            // Dia em que a resolução passou a valer: campo `date`, sem hora.
            'vigente_desde' => ['nullable', 'date_format:Y-m-d'],
            'ativo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'chave' => 'chave',
            'texto' => 'texto da referência',
            'texto_curto' => 'texto curto',
            'vigente_desde' => 'vigente desde',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'chave.required' => 'Informe a chave da referência.',
            'chave.regex' => 'A chave aceita apenas letras minúsculas, números e sublinhado (ex.: '
                .NormativeReference::CHAVE_PRINCIPAL.').',
            'chave.unique' => 'Esta empresa já tem uma referência normativa com esta chave.',
            'texto.required' => 'Informe o texto da referência, como ele deve sair nos documentos '
                .'(ex.: "RDC nº 622, de 9 de março de 2022, da Anvisa").',
            'vigente_desde.date_format' => 'A data de vigência precisa estar no formato AAAA-MM-DD.',
        ];
    }
}
