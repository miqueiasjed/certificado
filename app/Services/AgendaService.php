<?php

namespace App\Services;

use App\Models\Compromisso;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consulta de ordens de serviço e compromissos avulsos para o calendário da
 * agenda (Plano 10 e Plano 30, Task 30.4).
 *
 * Não resolve usuário nem sessão: quem chama pode entregar um Builder já
 * escopado (`$consultaDeOs`/`$consultaDeCompromissos`), tipicamente o
 * resultado de `WorkOrderAccessService::aplicarEscopoDoUsuario()` (ou do
 * equivalente para `Compromisso`) aplicado pelo controller, do mesmo jeito
 * que o `WorkOrderController` já faz. O escopo por empresa é automático via
 * `BelongsToCompany` nos dois models e não precisa de nenhum código aqui.
 */
class AgendaService
{
    /**
     * Relações carregadas de uma vez, para uma consulta de agenda com
     * centenas de ordens de serviço no mês não virar uma consulta por linha.
     */
    private const RELACOES = [
        'client:id,name',
        'address:id,client_id,street,number,district,city,state',
        'technician:id,name',
        'service:id,name',
    ];

    /**
     * Mesma ideia de `RELACOES`, para `Compromisso`. Sem `service`: a tabela
     * `compromissos` não tem `service_id`.
     */
    private const RELACOES_DE_COMPROMISSO = [
        'client:id,name',
        'address:id,client_id,street,number,district,city,state',
        'technician:id,name',
    ];

    /**
     * Situação que fica de fora da agenda quando o filtro de situação não é
     * informado. OS cancelada não some de vez: entra assim que alguém filtra
     * por ela, porque esconder para sempre tira a visibilidade de quem
     * investiga o que aconteceu com uma visita.
     */
    private const SITUACAO_OCULTA_POR_PADRAO = 'cancelled';

    /**
     * Mesma ideia de `SITUACAO_OCULTA_POR_PADRAO`, para a coluna `situacao`
     * de `Compromisso`, que usa vocabulário próprio em português
     * (`Compromisso::SITUACOES`), diferente do `status` de `WorkOrder`.
     */
    private const SITUACAO_DE_COMPROMISSO_OCULTA_POR_PADRAO = 'cancelado';

    /**
     * Rótulo em português da `situacao` de `Compromisso`, equivalente ao que
     * `WorkOrder::status_text` já resolve para OS. Fica aqui, e não no model,
     * porque a Task 30.4 não altera `Compromisso` (Task 30.1 já fechada).
     *
     * @var array<string, string>
     */
    private const SITUACAO_DE_COMPROMISSO_TEXTO = [
        'agendado' => 'Agendado',
        'concluido' => 'Concluído',
        'cancelado' => 'Cancelado',
    ];

    /**
     * Ordens de serviço e compromissos avulsos do período informado,
     * mesclados numa lista única e formatados para o calendário: cada item
     * carrega `tipo_item` (`'os'` ou `'compromisso'`) para o frontend saber
     * o que está renderizando.
     *
     * Ordenado por `data` e depois por `hora_inicio`, com nulo por último
     * (mesmo critério do `orderBy('start_time')` que a consulta de OS já
     * fazia): quem ainda não tem horário definido aparece depois de quem já
     * tem, no mesmo dia. A ordenação acontece em PHP, e não em SQL, porque as
     * duas coleções vêm de tabelas diferentes e são mescladas depois de
     * formatadas.
     *
     * Compatibilidade: sem `$consultaDeCompromissos` informado e sem nenhum
     * compromisso cadastrado no período, a lista devolvida é idêntica à de
     * antes desta task (só as OS, cada uma com o `tipo_item` novo).
     *
     * @param  string  $inicio  Data no formato Y-m-d.
     * @param  string  $fim  Data no formato Y-m-d.
     * @param  array  $filtros  Chaves aceitas: `technician_id` (aceita o
     *                          valor literal "sem_tecnico", filtra OS e compromisso), `service_id`
     *                          (só filtra OS: compromisso não tem serviço), `status` (string ou
     *                          array de strings; interpretado contra o vocabulário de cada model,
     *                          `status` para OS e `situacao` para compromisso) e `cidade` (só
     *                          filtra pelo endereço cadastrado; compromisso sem `address_id` não
     *                          participa do filtro, mesmo critério de "sem dado, sem match").
     * @param  Builder|null  $consultaDeOs  Builder de partida para OS, já
     *                                      escopado por quem chama (ex.:
     *                                      `WorkOrderAccessService::aplicarEscopoDoUsuario()`). Quando nulo,
     *                                      parte de `WorkOrder::query()` sem escopo adicional.
     * @param  Builder|null  $consultaDeCompromissos  Builder de partida para
     *                                                compromissos, já escopado por quem chama. Quando nulo, parte de
     *                                                `Compromisso::query()` sem escopo adicional.
     */
    public function doPeriodo(
        string $inicio,
        string $fim,
        array $filtros = [],
        ?Builder $consultaDeOs = null,
        ?Builder $consultaDeCompromissos = null,
    ): array {
        $itens = array_merge(
            $this->ordensDoPeriodo($inicio, $fim, $filtros, $consultaDeOs),
            $this->compromissosDoPeriodo($inicio, $fim, $filtros, $consultaDeCompromissos)
        );

        usort($itens, fn (array $a, array $b) => $this->compararItensDaAgenda($a, $b));

        return $itens;
    }

    private function ordensDoPeriodo(string $inicio, string $fim, array $filtros, ?Builder $consultaDeOs): array
    {
        $consulta = ($consultaDeOs ?? WorkOrder::query())
            ->whereNotNull('scheduled_date')
            ->whereBetween('scheduled_date', [$inicio, $fim])
            ->with(self::RELACOES);

        $this->aplicarFiltroDeSituacao($consulta, $filtros);
        $this->aplicarFiltroDeTecnico($consulta, $filtros);
        $this->aplicarFiltroDeServico($consulta, $filtros);
        $this->aplicarFiltroDeCidade($consulta, $filtros);

        return $consulta
            ->orderBy('scheduled_date')
            ->orderBy('start_time')
            ->get()
            ->map(fn (WorkOrder $ordem) => $this->formatar($ordem))
            ->all();
    }

    /**
     * Mesma ideia de `ordensDoPeriodo()`, para `Compromisso`. `data` é
     * coluna `NOT NULL` na tabela `compromissos`, então não existe aqui o
     * equivalente ao `whereNotNull('scheduled_date')` de OS.
     */
    private function compromissosDoPeriodo(string $inicio, string $fim, array $filtros, ?Builder $consultaDeCompromissos): array
    {
        $consulta = ($consultaDeCompromissos ?? Compromisso::query())
            ->whereBetween('data', [$inicio, $fim])
            ->with(self::RELACOES_DE_COMPROMISSO);

        $this->aplicarFiltroDeSituacaoDoCompromisso($consulta, $filtros);
        $this->aplicarFiltroDeTecnico($consulta, $filtros);
        $this->aplicarFiltroDeCidade($consulta, $filtros);

        return $consulta
            ->get()
            ->map(fn (Compromisso $compromisso) => $this->formatarCompromisso($compromisso))
            ->all();
    }

    /**
     * Compara dois itens já formatados (OS ou compromisso) por `data` e,
     * empatado, por `hora_inicio` com nulo por último. `usort()` é estável a
     * partir do PHP 8.0, então itens empatados nos dois critérios mantêm a
     * ordem em que `array_merge()` os colocou.
     */
    private function compararItensDaAgenda(array $a, array $b): int
    {
        $comparacaoPorData = ($a['data'] ?? '') <=> ($b['data'] ?? '');

        if ($comparacaoPorData !== 0) {
            return $comparacaoPorData;
        }

        return $this->compararHoraComNuloPorUltimo($a['hora_inicio'] ?? null, $b['hora_inicio'] ?? null);
    }

    private function compararHoraComNuloPorUltimo(?string $a, ?string $b): int
    {
        if ($a === $b) {
            return 0;
        }

        if ($a === null) {
            return 1;
        }

        if ($b === null) {
            return -1;
        }

        return $a <=> $b;
    }

    /**
     * Sem filtro de situação informado, cancelada some da agenda. Com
     * filtro informado (mesmo que inclua "cancelled"), o filtro manda.
     */
    private function aplicarFiltroDeSituacao(Builder $consulta, array $filtros): void
    {
        $situacoes = $filtros['status'] ?? null;

        if (blank($situacoes)) {
            $consulta->where('status', '!=', self::SITUACAO_OCULTA_POR_PADRAO);

            return;
        }

        $situacoes = is_array($situacoes) ? $situacoes : [$situacoes];

        $consulta->whereIn('status', $situacoes);
    }

    /**
     * Mesmo critério de `aplicarFiltroDeSituacao()`, mas para a coluna
     * `situacao` de `Compromisso`. O filtro de `status` recebido chega no
     * vocabulário de OS (`scheduled`, `cancelled`, ...); quando informado e
     * não contém nenhum valor do vocabulário de compromisso
     * (`Compromisso::SITUACOES`), a lista de compromissos fica vazia, mesmo
     * critério de "sem dado, sem match" já adotado para o filtro de cidade.
     * Continua sendo o comportamento certo para o caso que a Task 30.4
     * precisa cobrir: filtrar explicitamente por `['cancelado']` volta a
     * mostrar compromisso cancelado, do mesmo jeito que `['cancelled']` faz
     * para OS.
     */
    private function aplicarFiltroDeSituacaoDoCompromisso(Builder $consulta, array $filtros): void
    {
        $situacoes = $filtros['status'] ?? null;

        if (blank($situacoes)) {
            $consulta->where('situacao', '!=', self::SITUACAO_DE_COMPROMISSO_OCULTA_POR_PADRAO);

            return;
        }

        $situacoes = is_array($situacoes) ? $situacoes : [$situacoes];

        $consulta->whereIn('situacao', $situacoes);
    }

    /**
     * `technician_id` aceita o id do técnico ou o valor literal
     * "sem_tecnico", para achar as ordens que ainda não têm técnico
     * atribuído. Reaproveitado também para `Compromisso`: a coluna
     * `technician_id` existe lá com o mesmo significado, sem pivot.
     */
    private function aplicarFiltroDeTecnico(Builder $consulta, array $filtros): void
    {
        $tecnico = $filtros['technician_id'] ?? null;

        if (blank($tecnico)) {
            return;
        }

        if ($tecnico === 'sem_tecnico') {
            $consulta->whereNull('technician_id');

            return;
        }

        $consulta->where('technician_id', $tecnico);
    }

    private function aplicarFiltroDeServico(Builder $consulta, array $filtros): void
    {
        if (blank($filtros['service_id'] ?? null)) {
            return;
        }

        $consulta->where('service_id', $filtros['service_id']);
    }

    /**
     * Cidade vive no endereço, não na ordem de serviço nem no compromisso,
     * então o filtro passa pela relação `address` e reaproveita
     * `Address::scopeByCity()`. Reaproveitado também para `Compromisso`, que
     * tem a mesma relação `address()`: compromisso sem `address_id`
     * (endereço em texto livre) não casa com o `whereHas` e simplesmente não
     * aparece quando este filtro está ativo, mesmo critério de "sem dado,
     * sem match" usado no restante da agenda.
     */
    private function aplicarFiltroDeCidade(Builder $consulta, array $filtros): void
    {
        if (blank($filtros['cidade'] ?? null)) {
            return;
        }

        $cidade = $filtros['cidade'];

        $consulta->whereHas('address', function (Builder $consultaDeEndereco) use ($cidade) {
            $consultaDeEndereco->byCity($cidade);
        });
    }

    /**
     * Uma ordem de serviço isolada, no mesmo formato que `doPeriodo()` devolve.
     *
     * Serve a quem acabou de alterá-la (reagendamento e atribuição de técnico,
     * na Task 10.3): o calendário recebe a visita pronta e atualiza o cartão
     * sem uma segunda ida ao servidor.
     *
     * As relações são recarregadas de propósito, com `load()` e não
     * `loadMissing()`: depois de trocar o técnico, a relação carregada no model
     * ainda aponta para o técnico antigo. É também por isso que este método
     * fica fora do laço de `doPeriodo()`, onde uma recarga por linha desfaria o
     * `with()`.
     */
    public function formatarOrdem(WorkOrder $ordem): array
    {
        return $this->formatar($ordem->load(self::RELACOES));
    }

    private function formatar(WorkOrder $ordem): array
    {
        return [
            'id' => $ordem->id,
            // Discriminador novo (Task 30.4): distingue OS de compromisso
            // avulso na lista mesclada que `doPeriodo()` devolve. Campo
            // aditivo, o resto do shape de OS não muda.
            'tipo_item' => 'os',
            'numero' => $ordem->order_number,
            // Campo de dia puro: nunca converte fuso, só formata (skill datas-timezone).
            'data' => optional($ordem->scheduled_date)->format('Y-m-d'),
            'hora_inicio' => $this->formatarHora($ordem->start_time),
            'hora_fim' => $this->formatarHora($ordem->end_time),
            'cliente' => $ordem->client ? [
                'id' => $ordem->client->id,
                'nome' => $ordem->client->name,
            ] : null,
            'endereco' => $ordem->address?->short_address,
            'cidade' => $ordem->address?->city,
            'servico' => $ordem->service ? [
                'id' => $ordem->service->id,
                'nome' => $ordem->service->name,
            ] : null,
            'status' => $ordem->status,
            'status_texto' => $ordem->status_text,
            'tecnico' => $ordem->technician ? [
                'id' => $ordem->technician->id,
                'nome' => $ordem->technician->name,
            ] : null,
            'sem_tecnico' => $ordem->technician_id === null,
            'origem' => $ordem->origem,
        ];
    }

    /**
     * Instante (start_time/end_time) convertido para o fuso do negócio e
     * formatado em HH:MM, para o frontend nunca precisar converter fuso.
     */
    private function formatarHora(mixed $valor): ?string
    {
        return BusinessDate::paraFusoNegocio($valor)?->format('H:i');
    }

    /**
     * Compromisso avulso, no mesmo formato que `doPeriodo()` devolve para OS,
     * reaproveitando os nomes de campo do item de OS sempre que o conceito é
     * o mesmo (`cliente`, `endereco`, `cidade`, `tecnico`, `sem_tecnico`,
     * `data`, `hora_inicio`, `hora_fim`). O equivalente a `status`/
     * `status_texto` de OS é `situacao`/`situacao_texto`, com o vocabulário
     * próprio de `Compromisso::SITUACOES`.
     *
     * Sem `numero`, `servico` e `origem`: não existe conceito equivalente em
     * compromisso avulso (nunca tem número de OS nem serviço vinculado).
     *
     * `work_order_id` viaja aqui porque a Task 30.5 (painel lateral da
     * Agenda) precisa saber se o compromisso já foi promovido para
     * desabilitar a ação "Promover para OS" sem uma segunda requisição.
     */
    private function formatarCompromisso(Compromisso $compromisso): array
    {
        return [
            'id' => $compromisso->id,
            'tipo_item' => 'compromisso',
            'tipo' => $compromisso->tipo,
            'titulo' => $compromisso->titulo,
            'observacoes' => $compromisso->observacoes,
            // Campo de dia puro: nunca converte fuso, só formata (skill datas-timezone).
            'data' => optional($compromisso->data)->format('Y-m-d'),
            'hora_inicio' => $this->formatarHoraDeCompromisso($compromisso->hora_inicio),
            'hora_fim' => $this->formatarHoraDeCompromisso($compromisso->hora_fim),
            'cliente' => $compromisso->client ? [
                'id' => $compromisso->client->id,
                'nome' => $compromisso->client->name,
            ] : null,
            // Endereço cadastrado tem prioridade; sem `address_id`, cai para
            // o texto livre lançado junto do compromisso.
            'endereco' => $compromisso->address?->short_address ?? $compromisso->endereco_texto,
            'cidade' => $compromisso->address?->city,
            'situacao' => $compromisso->situacao,
            'situacao_texto' => self::SITUACAO_DE_COMPROMISSO_TEXTO[$compromisso->situacao] ?? $compromisso->situacao,
            'tecnico' => $compromisso->technician ? [
                'id' => $compromisso->technician->id,
                'nome' => $compromisso->technician->name,
            ] : null,
            'sem_tecnico' => $compromisso->technician_id === null,
            'work_order_id' => $compromisso->work_order_id,
        ];
    }

    /**
     * `hora_inicio`/`hora_fim` de `Compromisso` são coluna `TIME` pura (hora
     * do dia, sem instante e sem fuso, mesmo critério documentado no model).
     * Só corta os segundos do valor que o driver devolve ("10:00:00" ->
     * "10:00"), sem nenhuma conversão de fuso, ao contrário de
     * `formatarHora()`, que trata um instante de verdade.
     */
    private function formatarHoraDeCompromisso(?string $valor): ?string
    {
        if (blank($valor)) {
            return null;
        }

        return substr($valor, 0, 5);
    }
}
