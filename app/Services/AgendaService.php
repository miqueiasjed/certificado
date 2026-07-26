<?php

namespace App\Services;

use App\Models\WorkOrder;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Builder;

/**
 * Consulta de ordens de serviço para o calendário da agenda.
 *
 * Não resolve usuário nem sessão: quem chama pode entregar um Builder já
 * escopado (`$consulta`), tipicamente o resultado de
 * `WorkOrderAccessService::aplicarEscopoDoUsuario()` aplicado pelo
 * controller, do mesmo jeito que o `WorkOrderController` já faz. O escopo por
 * empresa é automático via `BelongsToCompany` no model `WorkOrder` e não
 * precisa de nenhum código aqui.
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
     * Situação que fica de fora da agenda quando o filtro de situação não é
     * informado. OS cancelada não some de vez: entra assim que alguém filtra
     * por ela, porque esconder para sempre tira a visibilidade de quem
     * investiga o que aconteceu com uma visita.
     */
    private const SITUACAO_OCULTA_POR_PADRAO = 'cancelled';

    /**
     * Ordens de serviço com `scheduled_date` no intervalo informado, já
     * formatadas para o calendário: id, número, data, horários, cliente,
     * endereço resumido, cidade, serviço, situação, técnico e origem.
     *
     * @param string $inicio Data no formato Y-m-d.
     * @param string $fim Data no formato Y-m-d.
     * @param array $filtros Chaves aceitas: `technician_id` (aceita o
     *   valor literal "sem_tecnico"), `service_id`, `status` (string ou
     *   array de strings) e `cidade`.
     * @param Builder|null $consulta Builder de partida, já escopado por quem
     *   chama (ex.: `WorkOrderAccessService::aplicarEscopoDoUsuario()`).
     *   Quando nulo, parte de `WorkOrder::query()` sem escopo adicional.
     */
    public function doPeriodo(string $inicio, string $fim, array $filtros = [], ?Builder $consulta = null): array
    {
        $consulta = ($consulta ?? WorkOrder::query())
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
     * `technician_id` aceita o id do técnico ou o valor literal
     * "sem_tecnico", para achar as ordens que ainda não têm técnico
     * atribuído.
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
     * Cidade vive no endereço, não na ordem de serviço, então o filtro passa
     * pela relação `address` e reaproveita `Address::scopeByCity()`.
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
}
