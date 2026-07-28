<?php

namespace App\Http\Requests\Plataforma;

use App\Support\BusinessDate;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação das ações de módulo (Plano 6) na área da plataforma:
 * sincronização dos módulos de um plano e liberação/bloqueio/remoção de regra
 * pontual de um módulo num tenant.
 *
 * As duas ações têm corpo bem diferente: `salvarPlano` recebe a lista inteira
 * de módulos marcados (`module_ids`), porque a tela sincroniza a pivot inteira
 * de uma vez; as ações pontuais recebem um módulo só (`module_id`), mais
 * `motivo`/`liberado_ate`. Por isso as regras variam pela rota, no mesmo
 * critério já usado por `TenantRequest` (regras que mudam conforme o contexto
 * da requisição, dentro do mesmo FormRequest), em vez de um FormRequest por
 * ação.
 *
 * `authorize()` devolve true porque toda rota deste grupo já está atrás do
 * middleware `platform.admin` (ver routes/web.php): não é rota de
 * autoatendimento nem de portal do cliente.
 */
class ModuleAssignmentRequest extends FormRequest
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
        if ($this->routeIs('plataforma.planos.modulos.update')) {
            return [
                'module_ids' => ['array'],
                'module_ids.*' => ['integer', 'exists:modules,id'],
            ];
        }

        $regras = [
            'module_id' => ['required', 'integer', 'exists:modules,id'],
        ];

        if ($this->routeIs('plataforma.tenants.modulos.liberar')) {
            // Dia puro, comparado contra o fuso do negócio (BusinessDate),
            // mesmo critério de `TenantRequest::trial_ends_at`: um "after:today"
            // comum compararia contra o dia em UTC do servidor.
            $regras['liberado_ate'] = [
                'nullable',
                'date',
                'after:'.BusinessDate::hoje()->toDateString(),
            ];
            $regras['motivo'] = ['nullable', 'string', 'max:500'];
        }

        if ($this->routeIs('plataforma.tenants.modulos.bloquear')) {
            // Bloqueio pontual exige motivo: quem for reativar o módulo meses
            // depois precisa saber por que ele foi bloqueado (regra explícita
            // da Task 6.7).
            $regras['motivo'] = ['required', 'string', 'min:3', 'max:500'];
        }

        return $regras;
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'module_ids.array' => 'Os módulos selecionados precisam ser uma lista.',
            'module_ids.*.integer' => 'Um dos módulos selecionados é inválido.',
            'module_ids.*.exists' => 'Um dos módulos selecionados não existe.',
            'module_id.required' => 'Selecione o módulo.',
            'module_id.integer' => 'O módulo selecionado é inválido.',
            'module_id.exists' => 'O módulo selecionado não existe.',
            'liberado_ate.date' => 'Informe uma data válida.',
            'liberado_ate.after' => 'A liberação até precisa ser uma data futura.',
            'motivo.required' => 'Informe o motivo do bloqueio, para quem for reativar o módulo depois saber por quê.',
            'motivo.min' => 'Escreva o motivo com pelo menos 3 caracteres.',
            'motivo.max' => 'O motivo pode ter no máximo 500 caracteres.',
        ];
    }
}
