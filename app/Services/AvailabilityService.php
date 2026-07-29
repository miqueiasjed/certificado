<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyAvailabilitySetting;
use App\Models\ServiceType;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Grade de horários da página pública de agendamento (Plano 16), calculada a
 * partir da carga real dos técnicos, e nunca de uma agenda separada.
 *
 * O que este Service protege
 * ---------------------------
 * A grade devolve só `disponivel` (bool) por dia e por período (`manha` e
 * `tarde`). Nunca quantidade de vagas restantes, nunca qual técnico está
 * livre: é informação interna da operação da empresa, e vazar isso numa
 * página pública revela o tamanho da equipe para qualquer visitante,
 * inclusive concorrente. `podeAceitar()` também devolve só um booleano, pelo
 * mesmo motivo.
 *
 * Disponibilidade aqui é indicativa. O pedido aberto a partir da grade
 * continua sendo solicitação a confirmar (Task 16.4): a decisão final, e a
 * escolha do técnico, é sempre da empresa.
 *
 * Este Service recebe `Company $empresa` explicitamente porque a página
 * pública de agendamento (Task 16.3) não passa por login: não há tenant
 * resolvido pelo usuário autenticado, então toda consulta aqui roda dentro de
 * `TenantAtual::comTenant()`, o mesmo padrão de `TenantUsageService`. Nenhum
 * método deste Service pode presumir que o tenant corrente já é a empresa
 * informada.
 *
 * Fuso horário
 * ------------
 * Todo cálculo de dia usa `BusinessDate`, no fuso do negócio
 * (`America/Sao_Paulo`), nunca UTC: "hoje", a antecedência mínima e a janela
 * máxima são contadas no dia de Brasília. `scheduled_date` é `date` (dia sem
 * hora) e passa por `BusinessDate` sem conversão; `start_time` é `datetime`
 * (instante gravado em UTC) e é convertido com `setTimezone`, nunca com
 * `BusinessDate::paraFusoNegocio()`, pelo mesmo motivo já documentado em
 * `DisponibilidadeService`: aquele método trataria meia-noite exata como dia
 * puro e deslocaria em três horas uma OS que começa 00:00 UTC.
 *
 * Período de uma OS existente
 * ----------------------------
 * A tabela `work_orders` não tem coluna de período: só `appointment_requests`
 * (Task 16.1) guarda `manha`/`tarde`, porque é o cliente quem escolhe o
 * período ao pedir. Para saber se uma OS já agendada ocupa a manhã ou a
 * tarde de um técnico, este Service classifica pelo `start_time`, mesmo corte
 * (`hora < 12` no fuso do negócio) já usado na lista do dia do app do técnico
 * (`resources/js/app-tecnico/Pages/Dia.vue`).
 *
 * OS sem `start_time` (a maioria das OS do sistema hoje, como documentado em
 * `DisponibilidadeService`) não entra na contagem de nenhum dos dois
 * períodos: não há como afirmar em qual período ela cai, e presumir errado
 * ora esconderia carga real (se sempre caísse fora), ora bloquearia a grade à
 * toa (se sempre caísse dentro). É o mesmo critério já adotado em
 * `DisponibilidadeService::conflitos()`, que trata OS sem horário como aviso,
 * nunca como fato bloqueante.
 */
class AvailabilityService
{
    public const PERIODO_MANHA = 'manha';

    public const PERIODO_TARDE = 'tarde';

    /**
     * @var array<int, string>
     */
    public const PERIODOS = [self::PERIODO_MANHA, self::PERIODO_TARDE];

    /**
     * Grade de disponibilidade dos próximos `$dias` dias corridos a partir de
     * hoje, no fuso do negócio.
     *
     * `$tipo` está na assinatura para o chamador informar o tipo de serviço
     * pretendido (a página pública oferece essa escolha), mas hoje nenhum
     * técnico tem vínculo de especialização por tipo de serviço no modelo de
     * dados (`Technician::specialty` é texto livre, sem relação com
     * `ServiceType`), então o parâmetro ainda não filtra técnico nenhum. Fica
     * aceito e documentado aqui para não quebrar a assinatura no dia em que
     * essa vinculação existir.
     *
     * Cada dia da janela passa por três filtros, nesta ordem, e o dia que cai
     * fora de qualquer um deles simplesmente não aparece no retorno (não
     * aparece com `disponivel: false`, ele nem existe na lista):
     *
     * 1. Dia da semana fora de `dias_da_semana` da configuração.
     * 2. Dia anterior a hoje + `antecedencia_minima_dias`.
     * 3. Dia posterior a hoje + `janela_maxima_dias`.
     *
     * Para cada dia que sobra, `manha` e `tarde` são avaliados
     * independentemente: um período está `disponivel` quando existe ao menos
     * um técnico ativo da empresa com menos OS naquele período do que
     * `visitas_por_periodo`. Sem nenhum técnico ativo, os dois períodos de
     * todo dia vêm `false`.
     *
     * @return array<int, array{data: string, manha: array{disponivel: bool}, tarde: array{disponivel: bool}}>
     */
    public function gradeDisponivel(Company $empresa, ?ServiceType $tipo, int $dias): array
    {
        if ($dias < 1) {
            throw new InvalidArgumentException('O número de dias da grade precisa ser maior que zero.');
        }

        return TenantAtual::comTenant((int) $empresa->id, function () use ($dias): array {
            $configuracao = $this->configuracaoOuPadrao();
            $hoje = BusinessDate::hoje();
            $diaMinimo = $hoje->addDays($configuracao['antecedencia_minima_dias']);
            $diaMaximo = $hoje->addDays($configuracao['janela_maxima_dias']);

            $diasDaJanela = $this->diasDaJanela($hoje, $dias, $diaMinimo, $diaMaximo, $configuracao['dias_da_semana']);

            if ($diasDaJanela === []) {
                return [];
            }

            $tecnicosAtivos = $this->tecnicosAtivosDaEmpresa();
            $carga = $this->cargaPorDiaPeriodoETecnico(
                $diasDaJanela[0]->toDateString(),
                end($diasDaJanela)->toDateString()
            );

            $grade = [];

            foreach ($diasDaJanela as $dia) {
                $data = $dia->toDateString();

                $grade[] = [
                    'data' => $data,
                    'manha' => ['disponivel' => $this->periodoDisponivel($data, self::PERIODO_MANHA, $tecnicosAtivos, $configuracao['visitas_por_periodo'], $carga)],
                    'tarde' => ['disponivel' => $this->periodoDisponivel($data, self::PERIODO_TARDE, $tecnicosAtivos, $configuracao['visitas_por_periodo'], $carga)],
                ];
            }

            return $grade;
        });
    }

    /**
     * A empresa ainda pode aceitar um pedido para esta data e período agora?
     *
     * Chamado de novo no momento da confirmação (Task 16.4), porque entre o
     * pedido e a confirmação a agenda muda: outro pedido pode ter sido
     * confirmado, ou uma OS pode ter sido agendada por fora do fluxo de
     * pedidos. Por isso este método não reaproveita o resultado de
     * `gradeDisponivel()`: ele conta a carga de novo, na hora.
     *
     * Verifica só capacidade (existe técnico ativo com vaga no período), sem
     * repetir os filtros de dia de atendimento, antecedência ou janela: quem
     * está confirmando é a própria empresa, então o que precisa ser
     * recusado aqui é especificamente o período que lotou, não a política de
     * agenda.
     */
    public function podeAceitar(Company $empresa, string $data, string $periodo): bool
    {
        if (! in_array($periodo, self::PERIODOS, true)) {
            throw new InvalidArgumentException(
                "Período inválido: \"{$periodo}\". Use \"".self::PERIODO_MANHA.'" ou "'.self::PERIODO_TARDE.'".'
            );
        }

        $dia = BusinessDate::paraFusoNegocio(trim($data));

        if ($dia === null) {
            throw new InvalidArgumentException('Data não informada.');
        }

        return TenantAtual::comTenant((int) $empresa->id, function () use ($dia, $periodo): bool {
            $configuracao = $this->configuracaoOuPadrao();
            $tecnicosAtivos = $this->tecnicosAtivosDaEmpresa();

            $dataAlvo = $dia->toDateString();
            $carga = $this->cargaPorDiaPeriodoETecnico($dataAlvo, $dataAlvo);

            return $this->periodoDisponivel($dataAlvo, $periodo, $tecnicosAtivos, $configuracao['visitas_por_periodo'], $carga);
        });
    }

    // -----------------------------------------------------------------
    // Tela de configuração (Task 16.7)
    // -----------------------------------------------------------------

    /**
     * Configuração salva da empresa, para preencher o formulário de
     * `Settings/Disponibilidade.vue`, ou o padrão do sistema quando a
     * empresa nunca salvou nada - mesmo conteúdo que `gradeDisponivel()` e
     * `podeAceitar()` aplicam, só que exposto para a tela ler, e não só para
     * o cálculo interno.
     *
     * @return array{dias_da_semana: array<int, int>, visitas_por_periodo: int, antecedencia_minima_dias: int, janela_maxima_dias: int, aceita_agendamento_online: bool}
     */
    public function configuracaoDaEmpresa(Company $empresa): array
    {
        return TenantAtual::comTenant((int) $empresa->id, fn (): array => $this->configuracaoOuPadrao());
    }

    /**
     * Grava a configuração de agendamento online da empresa (Task 16.7):
     * `company_availability_settings` (uma linha por empresa, ver o
     * `updateOrCreate` sem condição extra, que se apoia no escopo global da
     * trait `BelongsToCompany` para achar - ou criar - a linha certa) e, se
     * informado, `companies.slug_publico`.
     *
     * O slug é responsabilidade de `Company`, não desta tabela, mas as duas
     * mudanças nascem do mesmo formulário (ligar o agendamento online só faz
     * sentido com um slug definido) e por isso são gravadas juntas, na mesma
     * transação: metade salva e metade não é o estado que deixaria a empresa
     * com a chave ligada e sem endereço público para divulgar.
     *
     * A validação de formato do slug (minúsculas, sem espaço, sem acento) e
     * de unicidade já aconteceu no FormRequest; aqui o valor chega pronto
     * para gravar.
     *
     * @param  array<string, mixed>  $dados
     * @return array{configuracao: CompanyAvailabilitySetting, empresa: Company}
     */
    public function salvarConfiguracao(Company $empresa, array $dados): array
    {
        return DB::transaction(function () use ($empresa, $dados): array {
            $configuracao = TenantAtual::comTenant(
                (int) $empresa->id,
                fn (): CompanyAvailabilitySetting => CompanyAvailabilitySetting::query()->updateOrCreate([], [
                    'dias_da_semana' => array_values(array_map('intval', $dados['dias_da_semana'])),
                    'visitas_por_periodo' => (int) $dados['visitas_por_periodo'],
                    'antecedencia_minima_dias' => (int) $dados['antecedencia_minima_dias'],
                    'janela_maxima_dias' => (int) $dados['janela_maxima_dias'],
                    'aceita_agendamento_online' => (bool) ($dados['aceita_agendamento_online'] ?? false),
                ])
            );

            if (array_key_exists('slug_publico', $dados)) {
                $empresa->update(['slug_publico' => filled($dados['slug_publico']) ? $dados['slug_publico'] : null]);
            }

            return ['configuracao' => $configuracao, 'empresa' => $empresa->fresh()];
        });
    }

    /**
     * Configuração salva da empresa (já dentro do tenant corrente), ou o
     * padrão do sistema quando a empresa nunca configurou nada.
     *
     * @return array{dias_da_semana: array<int, int>, visitas_por_periodo: int, antecedencia_minima_dias: int, janela_maxima_dias: int, aceita_agendamento_online: bool}
     */
    private function configuracaoOuPadrao(): array
    {
        $configuracao = CompanyAvailabilitySetting::query()->first();

        if ($configuracao === null) {
            return CompanyAvailabilitySetting::padrao();
        }

        return [
            'dias_da_semana' => array_map('intval', $configuracao->dias_da_semana ?? CompanyAvailabilitySetting::DIAS_PADRAO),
            'visitas_por_periodo' => (int) $configuracao->visitas_por_periodo,
            'antecedencia_minima_dias' => (int) $configuracao->antecedencia_minima_dias,
            'janela_maxima_dias' => (int) $configuracao->janela_maxima_dias,
            'aceita_agendamento_online' => (bool) $configuracao->aceita_agendamento_online,
        ];
    }

    /**
     * Dias corridos a partir de hoje, já filtrados pelos três critérios de
     * exclusão da grade.
     *
     * @param  array<int, int>  $diasDaSemana
     * @return array<int, CarbonImmutable>
     */
    private function diasDaJanela(
        CarbonImmutable $hoje,
        int $dias,
        CarbonImmutable $diaMinimo,
        CarbonImmutable $diaMaximo,
        array $diasDaSemana
    ): array {
        $incluidos = [];
        $cursor = $hoje;

        for ($i = 0; $i < $dias; $i++) {
            if (
                in_array($cursor->dayOfWeekIso, $diasDaSemana, true)
                && ! $cursor->lessThan($diaMinimo)
                && ! $cursor->greaterThan($diaMaximo)
            ) {
                $incluidos[] = $cursor;
            }

            $cursor = $cursor->addDay();
        }

        return $incluidos;
    }

    /**
     * Técnicos ativos da empresa corrente (o escopo global de `Technician` já
     * garante que são só os desta empresa).
     *
     * @return EloquentCollection<int, Technician>
     */
    private function tecnicosAtivosDaEmpresa(): EloquentCollection
    {
        return Technician::query()->active()->get(['id']);
    }

    /**
     * Um período de um dia está disponível quando existe ao menos um técnico
     * ativo com menos OS nele do que o teto. Sem técnico ativo nenhum, não há
     * quem atenda, e o período nunca está disponível.
     *
     * @param  EloquentCollection<int, Technician>  $tecnicosAtivos
     * @param  array<string, array<string, array<int, int>>>  $carga
     */
    private function periodoDisponivel(
        string $data,
        string $periodo,
        EloquentCollection $tecnicosAtivos,
        int $teto,
        array $carga
    ): bool {
        if ($tecnicosAtivos->isEmpty()) {
            return false;
        }

        foreach ($tecnicosAtivos as $tecnico) {
            $ocupadas = $carga[$data][$periodo][$tecnico->id] ?? 0;

            if ($ocupadas < $teto) {
                return true;
            }
        }

        return false;
    }

    /**
     * Quantidade de OS por dia, por período e por técnico, no intervalo
     * informado (inclusive nas pontas).
     *
     * Uma consulta só para todo o intervalo, e não uma por dia: a grade pode
     * cobrir dezenas de dias, e uma consulta por dia multiplicaria o custo à
     * toa. OS cancelada nunca ocupa o técnico, mesmo critério de
     * `DisponibilidadeService`. A atribuição do técnico vale pela coluna
     * `work_orders.technician_id` e pela pivot `work_order_technicians`, e uma
     * OS com os dois (ou com mais de um técnico pela pivot) conta para cada
     * técnico envolvido, porque cada um deles tem a agenda ocupada.
     *
     * @return array<string, array<string, array<int, int>>>
     */
    private function cargaPorDiaPeriodoETecnico(string $de, string $ate): array
    {
        $ordens = WorkOrder::query()
            ->where('status', '!=', DisponibilidadeService::STATUS_CANCELADA)
            ->whereBetween('scheduled_date', [$de, $ate])
            ->with('technicians:id')
            ->get(['id', 'scheduled_date', 'start_time', 'technician_id']);

        $carga = [];

        foreach ($ordens as $ordem) {
            $periodo = $this->periodoDaOrdem($ordem);

            if ($periodo === null) {
                continue;
            }

            $data = BusinessDate::diaDe($ordem->scheduled_date);

            foreach ($this->tecnicosDaOrdem($ordem) as $tecnicoId) {
                $carga[$data][$periodo][$tecnicoId] = ($carga[$data][$periodo][$tecnicoId] ?? 0) + 1;
            }
        }

        return $carga;
    }

    /**
     * Período (`manha` ou `tarde`) que a OS ocupa, pelo `start_time` já
     * convertido ao fuso do negócio. `null` quando a OS não tem horário: ela
     * não entra na contagem de período nenhum (ver o cabeçalho da classe).
     *
     * `start_time` é instante (`datetime`), gravado em UTC: a conversão é
     * `setTimezone` puro, nunca `BusinessDate::paraFusoNegocio()`, que
     * deslocaria em três horas uma OS gravada em meia-noite exata.
     */
    private function periodoDaOrdem(WorkOrder $ordem): ?string
    {
        if ($ordem->start_time === null) {
            return null;
        }

        $hora = CarbonImmutable::instance($ordem->start_time)
            ->setTimezone(BusinessDate::fuso())
            ->hour;

        return $hora < 12 ? self::PERIODO_MANHA : self::PERIODO_TARDE;
    }

    /**
     * Ids dos técnicos atribuídos à OS, pela coluna direta e pela pivot, sem
     * repetição.
     *
     * @return array<int, int>
     */
    private function tecnicosDaOrdem(WorkOrder $ordem): array
    {
        $ids = [];

        if ($ordem->technician_id !== null) {
            $ids[] = (int) $ordem->technician_id;
        }

        foreach ($ordem->technicians as $tecnico) {
            $ids[] = (int) $tecnico->id;
        }

        return array_values(array_unique($ids));
    }
}
