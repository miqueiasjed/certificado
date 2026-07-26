<?php

namespace App\Http\Requests;

use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Reagendamento de uma ordem de serviço pelo calendário (arrastar e soltar ou
 * formulário do painel lateral).
 *
 * Fuso horário: a mesma convenção do formulário de ordem de serviço
 * -----------------------------------------------------------------
 * `start_time` e `end_time` são campos `datetime`, gravados no fuso da
 * aplicação (UTC). Quem converte a hora de parede digitada pelo usuário é o
 * **frontend**, com `inputDateTimeParaUtc()` de `resources/js/utils/formatDate.js`,
 * exatamente como `WorkOrders/Edit.vue` já faz hoje. O backend recebe a string
 * já convertida, no formato `Y-m-d\TH:i` sem deslocamento, valida o formato e
 * entrega ao Eloquent, que a lê no fuso da aplicação. Nenhuma conversão é
 * inventada aqui, e é assim que o sistema já funciona sem bug.
 *
 * `scheduled_date` é campo `date`, ou seja, um dia sem hora: chega e sai como
 * `Y-m-d`, sem conversão nenhuma.
 *
 * Por que os três campos são obrigatórios na requisição
 * -----------------------------------------------------
 * `start_time` e `end_time` aceitam nulo, mas precisam vir na requisição
 * (`present`). Se pudessem ser omitidos, mudar só o dia deixaria os horários
 * gravados apontando para o dia antigo, e a ordem ficaria com `scheduled_date`
 * em um dia e `start_time` em outro. Além de exibir horário errado no
 * calendário, isso furaria a checagem de conflito, que compara instantes: a
 * verificação passaria limpa porque os intervalos estariam em dias diferentes.
 * O reagendamento define o horário inteiro da visita, então ele vem inteiro.
 *
 * A regra `horarioNoDiaAgendado()` fecha o mesmo buraco pelo outro lado,
 * recusando horário que, no fuso do negócio, cai fora do dia agendado.
 */
class ReagendamentoRequest extends OrdemDaAgendaRequest
{
    /**
     * Formato em que o frontend envia os instantes, já convertidos para o fuso
     * da aplicação. É o mesmo do `WorkOrderRequest`.
     */
    public const FORMATO_DO_INSTANTE = 'Y-m-d\TH:i';

    public function rules(): array
    {
        return [
            // `date_format` em vez de `date` de propósito: a agenda fala
            // sempre `Y-m-d`, e `date` sozinho aceitaria "08/01/2026", que o
            // Carbon lê no formato americano e agendaria o dia errado sem
            // reclamar de nada.
            'scheduled_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['present', 'nullable', 'date_format:'.self::FORMATO_DO_INSTANTE],
            'end_time' => ['present', 'nullable', 'date_format:'.self::FORMATO_DO_INSTANTE, 'after:start_time'],
        ];
    }

    public function messages(): array
    {
        return [
            'scheduled_date.required' => 'A data agendada é obrigatória.',
            'scheduled_date.date_format' => 'A data agendada deve estar no formato AAAA-MM-DD.',
            'start_time.present' => 'Envie start_time junto com scheduled_date, mesmo que nulo: o reagendamento define o horário inteiro da visita.',
            'start_time.date_format' => 'O horário de início deve estar no formato AAAA-MM-DDTHH:MM, já convertido para UTC.',
            'end_time.present' => 'Envie end_time junto com scheduled_date, mesmo que nulo: o reagendamento define o horário inteiro da visita.',
            'end_time.date_format' => 'O horário de término deve estar no formato AAAA-MM-DDTHH:MM, já convertido para UTC.',
            'end_time.after' => 'O horário de término deve ser posterior ao de início.',
        ];
    }

    /**
     * @param  \Illuminate\Validation\Validator  $validator
     */
    protected function regrasAdicionais($validator): void
    {
        $dia = $this->input('scheduled_date');

        if (! is_string($dia) || $dia === '') {
            return;
        }

        $this->horarioNoDiaAgendado($validator, 'start_time', $dia);
        $this->horarioNoDiaAgendado($validator, 'end_time', $dia);
    }

    /**
     * O instante informado, lido no fuso do negócio, precisa cair no dia
     * agendado.
     *
     * Sem isto, `scheduled_date` e `start_time` podem sair da requisição
     * apontando para dias diferentes, e a ordem de serviço passa a existir em
     * dois dias ao mesmo tempo: um no calendário, outro no relógio.
     *
     * A conversão aqui é só de leitura, para comparar; nada do que é gravado
     * passa por ela. Ela repete o que o `DisponibilidadeService` já faz: lê o
     * instante no fuso da aplicação (sem deslocamento na string) e o traz para
     * o fuso do negócio.
     *
     * @param  \Illuminate\Validation\Validator  $validator
     */
    private function horarioNoDiaAgendado($validator, string $campo, string $diaAgendado): void
    {
        $valor = $this->input($campo);

        if (! is_string($valor) || $valor === '') {
            return;
        }

        $instante = $this->noFusoDoNegocio($valor);

        if ($instante === null || $instante->toDateString() === $diaAgendado) {
            return;
        }

        $validator->errors()->add($campo, sprintf(
            'O horário informado cai em %s no fuso de Brasília, e não no dia agendado (%s). '
            .'Converta o horário para UTC a partir do dia agendado antes de enviar.',
            $instante->format('d/m/Y H:i'),
            $diaAgendado
        ));
    }

    private function noFusoDoNegocio(string $valor): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::parse($valor, $this->fusoDaAplicacao())
                ->setTimezone(BusinessDate::fuso());
        } catch (Throwable) {
            // Formato inválido já é recusado por `date_format`; aqui só não há
            // o que comparar.
            return null;
        }
    }

    /**
     * Fuso em que a aplicação grava os campos `datetime`. Continua UTC neste
     * projeto, e o valor vem da configuração para não ficar cravado aqui.
     */
    private function fusoDaAplicacao(): string
    {
        $fuso = config('app.timezone');

        return is_string($fuso) && $fuso !== '' ? $fuso : 'UTC';
    }
}
