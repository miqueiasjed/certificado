<?php

namespace App\Http\Requests;

use App\Support\TenantAtual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validação da tela de configuração de disponibilidade e agendamento online
 * (Plano 16, Task 16.7): dias de atendimento, teto de visitas por período,
 * antecedência, janela, chave de ligar/desligar o agendamento online e o
 * slug da página pública.
 *
 * `authorize()` sempre `true`: a rota (`PUT /settings/disponibilidade`) já
 * está inteiramente protegida por `permission:empresa-configurar`, mesmo
 * critério de `ConfirmarAppointmentRequestRequest`.
 *
 * `slug_publico` é único na tabela `companies` inteira (sem composição com
 * `company_id`: `companies` já é a própria linha do tenant, não um cadastro
 * escopado por empresa - mesmo raciocínio de `cnpj` em `TenantRequest`), e
 * `->ignore()` usa o id da empresa corrente para a própria empresa poder
 * salvar de novo sem trocar de slug.
 */
class AtualizarDisponibilidadeRequest extends FormRequest
{
    /**
     * Formato aceito: minúsculas, dígitos e hífen simples entre blocos, sem
     * espaço e sem acento. O mesmo padrão é conferido no frontend antes do
     * envio (Task 16.7); esta regra é a segunda barreira.
     */
    public const REGRA_DE_FORMATO_DO_SLUG = '/^[a-z0-9]+(-[a-z0-9]+)*$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'dias_da_semana' => ['required', 'array', 'min:1'],
            'dias_da_semana.*' => ['integer', 'between:1,7'],

            'visitas_por_periodo' => ['required', 'integer', 'min:1', 'max:50'],
            'antecedencia_minima_dias' => ['required', 'integer', 'min:0', 'max:365'],
            'janela_maxima_dias' => ['required', 'integer', 'min:1', 'max:365'],

            'aceita_agendamento_online' => ['nullable', 'boolean'],

            // Obrigatório quando a chave está ligada: agendamento online
            // ativo sem slug é uma página pública sem endereço para
            // divulgar. Fora disso, opcional - a empresa pode preparar o
            // slug antes de ligar a chave.
            // `max:60`: mesmo teto do `where('slug', ...)` da rota pública
            // (`routes/publico.php`, `/agendar/{slug}`). Slug maior que isso
            // salvaria aqui e nunca abriria lá.
            'slug_publico' => [
                $this->boolean('aceita_agendamento_online') ? 'required' : 'nullable',
                'string',
                'max:60',
                'regex:'.self::REGRA_DE_FORMATO_DO_SLUG,
                Rule::unique('companies', 'slug_publico')->ignore(TenantAtual::exigirId()),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'dias_da_semana.required' => 'Selecione ao menos um dia de atendimento.',
            'dias_da_semana.min' => 'Selecione ao menos um dia de atendimento.',
            'dias_da_semana.*.between' => 'Dia da semana inválido.',
            'visitas_por_periodo.required' => 'Informe o teto de visitas por período.',
            'visitas_por_periodo.min' => 'O teto de visitas por período precisa ser maior que zero.',
            'antecedencia_minima_dias.required' => 'Informe a antecedência mínima em dias.',
            'janela_maxima_dias.required' => 'Informe a janela máxima em dias.',
            'janela_maxima_dias.min' => 'A janela máxima precisa ser maior que zero.',
            'slug_publico.required' => 'Defina o endereço da página pública antes de ligar o agendamento online.',
            'slug_publico.regex' => 'O endereço só pode ter letras minúsculas, números e hífen, sem espaço e sem acento.',
            'slug_publico.unique' => 'Este endereço já está em uso por outra empresa. Escolha outro.',
        ];
    }
}
