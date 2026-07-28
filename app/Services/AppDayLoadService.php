<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\BaitType;
use App\Models\EventType;
use App\Models\Product;
use App\Models\Technician;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Throwable;

/**
 * Carga do dia do aplicativo do técnico (Plano 12, Task 12.3).
 *
 * Entrega em uma chamada só tudo que o técnico precisa para trabalhar sem
 * rede: as ordens de serviço do período pedido (por padrão, de hoje até 3 dias
 * à frente, no fuso do negócio) e os catálogos de apoio (tipos de evento,
 * tipos de isca, pragas e produtos), mais o cabeçalho da empresa.
 *
 * A carga é somente leitura: nenhum método aqui grava nada.
 *
 * Nenhum dado financeiro entra na carga. O `WorkOrder` tem `$appends` que
 * calculam `total_paid`, `remaining_amount`, `effective_amount`,
 * `payment_status_text` e `payment_status_color` automaticamente em qualquer
 * serialização direta do model, e por isso este Service nunca serializa a
 * ordem de serviço com `toArray()`/`toJson()`: cada campo do retorno é listado
 * a mão em `mapearOrdem()`, e nenhum deles é o de custo, valor ou margem.
 */
class AppDayLoadService
{
    /**
     * Limite de ordens de serviço por chamada. Acima disso, o aplicativo
     * recebe o indicador `ordens_tem_mais` e pede o restante em uma chamada
     * seguinte (com `desde` da carga anterior).
     */
    private const LIMITE_ORDENS = 200;

    /**
     * Sem `de`/`ate` explícitos, o período padrão cobre hoje e os três dias
     * seguintes: cobre o fim de semana para o técnico que sai de manhã e passa
     * o dia em zona sem sinal, sem inflar demais o pacote.
     */
    private const DIAS_PADRAO_A_FRENTE = 3;

    /**
     * Catálogo fechado de pragas usado em `PestSighting`/`PestSightingItem` e
     * no pivot `work_order_room.pest_type` (mesmos rótulos de
     * `PestSighting::getPestTypeTextAttribute()`).
     *
     * Não existe tabela própria de praga neste sistema: é um enum de código,
     * igual para toda empresa. Por isso este catálogo nunca varia por empresa
     * nem por `desde`, e nunca aparece em `removidos`.
     *
     * @var array<string, string>
     */
    private const PRAGAS = [
        'rats' => 'Ratos',
        'mice' => 'Camundongos',
        'cockroaches' => 'Baratas',
        'ants' => 'Formigas',
        'termites' => 'Cupins',
        'flies' => 'Moscas',
        'fleas' => 'Pulgas',
        'ticks' => 'Carrapatos',
        'scorpions' => 'Escorpiões',
        'spiders' => 'Aranhas',
        'bees' => 'Abelhas',
        'wasps' => 'Vespas',
        'other' => 'Outros',
    ];

    public function __construct(private readonly WorkOrderAccessService $workOrderAccessService) {}

    /**
     * Monta a carga do dia para o usuário autenticado.
     *
     * @param  array{de?: mixed, ate?: mixed, desde?: mixed, tecnico_id?: mixed}  $parametros
     * @return array{sucesso: bool, status: int, mensagem?: string, dados?: array}
     */
    public function carregar(User $usuario, array $parametros): array
    {
        try {
            $periodo = $this->resolverPeriodo($parametros['de'] ?? null, $parametros['ate'] ?? null);
            $desde = $this->resolverDesde($parametros['desde'] ?? null);
            $tecnicoAlvo = $this->resolverTecnicoAlvo($usuario, $parametros['tecnico_id'] ?? null);
        } catch (InvalidArgumentException $erro) {
            return $this->falha(422, $erro->getMessage());
        }

        $geradoEm = Carbon::now();

        [$ordens, $temMais] = $this->carregarOrdens($usuario, $tecnicoAlvo, $periodo, $desde);

        $dados = [
            'gerado_em' => $geradoEm->toIso8601String(),
            'periodo' => [
                'de' => $periodo['de']->toDateString(),
                'ate' => $periodo['ate']->toDateString(),
            ],
            'empresa' => $this->carregarEmpresa($usuario),
            'ordens' => $ordens,
            'ordens_tem_mais' => $temMais,
            'tipos_de_evento' => $this->carregarTiposDeEvento($desde),
            'tipos_de_isca' => $this->carregarTiposDeIsca($desde),
            'pragas' => $this->catalogoDePragas(),
            'produtos' => $this->carregarProdutos($desde),
            'removidos' => [
                'ordens' => $this->ordensRemovidasDesde($usuario, $tecnicoAlvo, $periodo, $desde),
                'produtos' => $this->produtosRemovidosDesde($desde),
            ],
        ];

        return ['sucesso' => true, 'status' => 200, 'dados' => $dados];
    }

    // -----------------------------------------------------------------
    // Resolução dos parâmetros de entrada
    // -----------------------------------------------------------------

    /**
     * @return array{de: CarbonImmutable, ate: CarbonImmutable}
     */
    private function resolverPeriodo(mixed $de, mixed $ate): array
    {
        $hoje = BusinessDate::hoje();

        $inicio = $this->vazio($de) ? $hoje : $this->interpretarDia($de, 'de');
        $fim = $this->vazio($ate) ? $hoje->addDays(self::DIAS_PADRAO_A_FRENTE) : $this->interpretarDia($ate, 'ate');

        if ($fim->lessThan($inicio)) {
            throw new InvalidArgumentException('O parâmetro "ate" não pode ser anterior ao parâmetro "de".');
        }

        return ['de' => $inicio, 'ate' => $fim];
    }

    /**
     * Dia puro (sem hora) no fuso do negócio, a partir de uma string
     * `YYYY-MM-DD`. Nunca instancia `Date`/`Carbon` a partir da string sem
     * passar pelo fuso do negócio: é exatamente isso que produz o erro de um
     * dia de diferença.
     */
    private function interpretarDia(mixed $valor, string $campo): CarbonImmutable
    {
        if (! is_string($valor) || preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($valor)) !== 1) {
            throw new InvalidArgumentException("O parâmetro \"{$campo}\" precisa estar no formato AAAA-MM-DD.");
        }

        try {
            return BusinessDate::paraFusoNegocio($valor);
        } catch (Throwable) {
            throw new InvalidArgumentException("O parâmetro \"{$campo}\" não é uma data válida.");
        }
    }

    /**
     * `desde` é o instante (não o dia) da última carga bem-sucedida, aceito
     * tanto como timestamp Unix quanto como string ISO 8601. `null` quando o
     * parâmetro não veio: a carga é completa, não incremental.
     */
    private function resolverDesde(mixed $valor): ?Carbon
    {
        if ($this->vazio($valor)) {
            return null;
        }

        if (is_numeric($valor)) {
            return Carbon::createFromTimestamp((int) $valor);
        }

        if (! is_string($valor)) {
            throw new InvalidArgumentException('O parâmetro "desde" precisa ser um timestamp ou uma data/hora válida.');
        }

        try {
            return Carbon::parse($valor);
        } catch (Throwable) {
            throw new InvalidArgumentException('O parâmetro "desde" precisa ser um timestamp ou uma data/hora válida.');
        }
    }

    /**
     * Técnico a que a carga se refere, ou `null` quando a chamada não é
     * restrita a um técnico (o que hoje não acontece: todo usuário do
     * aplicativo tem um técnico vinculado, exigido no login da Task 12.2).
     *
     * - Usuário com papel `tecnico` (e sem o papel `administrador`) é sempre
     *   restrito ao próprio técnico vinculado por `WorkOrderAccessService`.
     *   Um `tecnico_id` enviado por ele é ignorado: nunca escolhe ver a
     *   agenda de outro técnico.
     * - Coordenador e administrador podem pedir a carga de um técnico
     *   específico via `tecnico_id`, sempre um só por chamada. Sem o
     *   parâmetro, a carga é a do próprio técnico vinculado ao usuário.
     */
    private function resolverTecnicoAlvo(User $usuario, mixed $tecnicoIdParam): ?int
    {
        if ($this->precisaRestricaoPorTecnico($usuario)) {
            return null;
        }

        if (! $this->vazio($tecnicoIdParam)) {
            if (! is_numeric($tecnicoIdParam)) {
                throw new InvalidArgumentException('O parâmetro "tecnico_id" precisa ser numérico.');
            }

            $tecnicoId = (int) $tecnicoIdParam;

            // Escopado à empresa corrente pelo escopo global de
            // `BelongsToCompany`: técnico de outra empresa nunca é encontrado
            // aqui, e por isso cai no mesmo "não encontrado" de um id
            // inventado.
            if (! Technician::whereKey($tecnicoId)->exists()) {
                throw new InvalidArgumentException('Técnico informado não foi encontrado nesta empresa.');
            }

            return $tecnicoId;
        }

        return $usuario->technician?->id;
    }

    /**
     * Mesma regra de `WorkOrderAccessService::precisaDeFiltro()`, duplicada
     * de propósito: aquele método é privado, e esta task (12.3) não altera
     * `WorkOrderAccessService`. Papel `administrador` nunca é restrito, e só
     * quem tem o papel `tecnico` (e não é administrador) é preso ao próprio
     * técnico vinculado.
     */
    private function precisaRestricaoPorTecnico(User $usuario): bool
    {
        if ($usuario->hasRole('administrador')) {
            return false;
        }

        return $usuario->hasRole('tecnico');
    }

    private function vazio(mixed $valor): bool
    {
        return $valor === null || $valor === '';
    }

    // -----------------------------------------------------------------
    // Ordens de serviço
    // -----------------------------------------------------------------

    /**
     * Consulta base das ordens de serviço: escopo por empresa (automático via
     * `BelongsToCompany`), escopo de técnico de `WorkOrderAccessService` e,
     * quando aplicável, o filtro adicional de um único técnico pedido por
     * quem não é restrito.
     */
    private function escopoBase(User $usuario, ?int $tecnicoAlvo): Builder
    {
        $consulta = $this->workOrderAccessService->aplicarEscopoDoUsuario(WorkOrder::query(), $usuario);

        if (! $this->precisaRestricaoPorTecnico($usuario) && $tecnicoAlvo !== null) {
            $consulta->where(function (Builder $filtro) use ($tecnicoAlvo) {
                $filtro->where('work_orders.technician_id', $tecnicoAlvo)
                    ->orWhereHas('technicians', fn (Builder $pivot) => $pivot->where('technicians.id', $tecnicoAlvo));
            });
        }

        return $consulta;
    }

    /**
     * @param  array{de: CarbonImmutable, ate: CarbonImmutable}  $periodo
     * @return array{0: array<int, array>, 1: bool}
     */
    private function carregarOrdens(User $usuario, ?int $tecnicoAlvo, array $periodo, ?Carbon $desde): array
    {
        $consulta = $this->escopoBase($usuario, $tecnicoAlvo)
            ->select([
                'work_orders.id',
                'work_orders.client_id',
                'work_orders.address_id',
                'work_orders.technician_id',
                'work_orders.order_number',
                'work_orders.origem',
                'work_orders.visita_numero',
                'work_orders.priority_level',
                'work_orders.scheduled_date',
                'work_orders.start_time',
                'work_orders.end_time',
                'work_orders.status',
                'work_orders.description',
                'work_orders.observations',
                'work_orders.completion_notes',
                'work_orders.active',
                'work_orders.updated_at',
            ])
            ->whereBetween('scheduled_date', [$periodo['de']->toDateString(), $periodo['ate']->toDateString()])
            ->when($desde !== null, fn (Builder $q) => $q->where('work_orders.updated_at', '>', $desde))
            ->with([
                'client:id,name,phone,email',
                'address:id,client_id,nickname,street,number,district,city,state,zip,reference',
                'rooms:id,address_id,name,notes,active',
                'devices' => function (BelongsToMany $q): void {
                    $q->select([
                        'devices.id',
                        'devices.address_id',
                        'devices.label',
                        'devices.number',
                        'devices.codigo_publico',
                        'devices.bait_type_id',
                        'devices.active',
                        'devices.situacao',
                    ])
                        // Dispositivo removido do ponto físico não interessa mais
                        // ao técnico em campo.
                        ->where('devices.situacao', '!=', 'removido');
                },
                'services:id,name,category',
                'products:id,name',
            ])
            ->orderBy('scheduled_date')
            ->orderBy('start_time')
            ->orderBy('work_orders.id')
            ->limit(self::LIMITE_ORDENS + 1)
            ->get();

        $temMais = $consulta->count() > self::LIMITE_ORDENS;

        $itens = $consulta->take(self::LIMITE_ORDENS)
            ->map(fn (WorkOrder $ordem) => $this->mapearOrdem($ordem))
            ->values()
            ->all();

        return [$itens, $temMais];
    }

    /**
     * Campos seguros de uma ordem de serviço para o aplicativo do técnico.
     * Nenhum campo de valor, custo ou margem entra aqui.
     */
    private function mapearOrdem(WorkOrder $ordem): array
    {
        return [
            'id' => $ordem->id,
            'numero' => $ordem->order_number,
            'origem' => $ordem->origem,
            'visita_numero' => $ordem->visita_numero,
            'status' => $ordem->status,
            'prioridade' => $ordem->priority_level,
            'ativa' => (bool) $ordem->active,
            'data_agendada' => optional($ordem->scheduled_date)->toDateString(),
            'inicio' => optional($ordem->start_time)->toIso8601String(),
            'fim' => optional($ordem->end_time)->toIso8601String(),
            'descricao' => $ordem->description,
            'observacoes' => $ordem->observations,
            'notas_conclusao' => $ordem->completion_notes,
            'updated_at' => $ordem->updated_at->toIso8601String(),
            'cliente' => $ordem->client === null ? null : [
                'id' => $ordem->client->id,
                'nome' => $ordem->client->name,
                'telefone' => $ordem->client->phone,
                'email' => $ordem->client->email,
            ],
            'endereco' => $ordem->address === null ? null : [
                'id' => $ordem->address->id,
                'apelido' => $ordem->address->nickname,
                'logradouro' => $ordem->address->street,
                'numero' => $ordem->address->number,
                'bairro' => $ordem->address->district,
                'cidade' => $ordem->address->city,
                'estado' => $ordem->address->state,
                'cep' => $ordem->address->zip,
                'referencia' => $ordem->address->reference,
            ],
            'comodos' => $ordem->rooms->map(fn ($comodo) => [
                'id' => $comodo->id,
                'nome' => $comodo->name,
                'observacoes' => $comodo->notes,
                'observacao_visita' => $comodo->pivot->observation,
                'tipo_evento_id' => $comodo->pivot->event_type_id,
                'data_evento' => $comodo->pivot->event_date,
                'descricao_evento' => $comodo->pivot->event_description,
                'observacoes_evento' => $comodo->pivot->event_observations,
                'praga' => $comodo->pivot->pest_type,
                'data_avistamento_praga' => $comodo->pivot->pest_sighting_date,
                'local_praga' => $comodo->pivot->pest_location,
                'quantidade_praga' => $comodo->pivot->pest_quantity,
                'observacao_praga' => $comodo->pivot->pest_observation,
            ])->values()->all(),
            'dispositivos' => $ordem->devices->map(fn ($dispositivo) => [
                'id' => $dispositivo->id,
                'rotulo' => $dispositivo->label,
                'numero' => $dispositivo->number,
                'codigo_publico' => $dispositivo->codigo_publico,
                'tipo_isca_id' => $dispositivo->bait_type_id,
                'ativo' => (bool) $dispositivo->active,
                'situacao' => $dispositivo->situacao,
                'observacao_visita' => $dispositivo->pivot->observation,
            ])->values()->all(),
            'servicos' => $ordem->services->map(fn ($servico) => [
                'id' => $servico->id,
                'nome' => $servico->name,
                'categoria' => $servico->category,
                'observacoes_visita' => $servico->pivot->observations,
            ])->values()->all(),
            'produtos_previstos' => $ordem->products->map(fn ($produto) => [
                'id' => $produto->id,
                'nome' => $produto->name,
                'quantidade' => $produto->pivot->quantity,
                'unidade' => $produto->pivot->unit,
                'observacoes' => $produto->pivot->observations,
            ])->values()->all(),
        ];
    }

    /**
     * Ids de ordens de serviço que saíram do alcance do técnico desde a
     * última carga, para o aplicativo apagar do armazenamento local.
     *
     * Duas origens, somadas:
     *
     * 1. Excluídas de fato: `WorkOrder` usa a trait `Auditavel`, então toda
     *    exclusão grava um `AuditLog` com os atributos de antes. É a única
     *    forma de saber que a linha existiu, já que este model não usa soft
     *    delete.
     * 2. Ainda existem, mas saíram do período pedido, foram desativadas ou
     *    trocaram de técnico depois de `desde`: continuam na tabela, então
     *    entram por uma consulta comum, sem o filtro de período.
     *
     * @param  array{de: CarbonImmutable, ate: CarbonImmutable}  $periodo
     * @return array<int, int>
     */
    private function ordensRemovidasDesde(User $usuario, ?int $tecnicoAlvo, array $periodo, ?Carbon $desde): array
    {
        if ($desde === null) {
            return [];
        }

        $excluidas = AuditLog::query()
            ->where('auditable_type', WorkOrder::class)
            ->where('acao', 'excluido')
            ->where('created_at', '>', $desde)
            ->get(['auditable_id', 'valores_antes'])
            ->filter(function (AuditLog $log) use ($tecnicoAlvo) {
                if ($tecnicoAlvo === null) {
                    return true;
                }

                $tecnicoDaOrdem = $log->valores_antes['technician_id'] ?? null;

                return $tecnicoDaOrdem !== null && (int) $tecnicoDaOrdem === $tecnicoAlvo;
            })
            ->pluck('auditable_id')
            ->map(fn ($id) => (int) $id);

        $foraDoAlcance = $this->escopoBase($usuario, $tecnicoAlvo)
            ->where('work_orders.updated_at', '>', $desde)
            ->where(function (Builder $consulta) use ($periodo) {
                $consulta->where('scheduled_date', '<', $periodo['de']->toDateString())
                    ->orWhere('scheduled_date', '>', $periodo['ate']->toDateString())
                    ->orWhere('active', false);
            })
            ->pluck('work_orders.id');

        return $excluidas->merge($foraDoAlcance)->unique()->values()->all();
    }

    // -----------------------------------------------------------------
    // Empresa
    // -----------------------------------------------------------------

    private function carregarEmpresa(User $usuario): ?array
    {
        $empresa = $usuario->company;

        if ($empresa === null) {
            return null;
        }

        return [
            'id' => $empresa->id,
            'nome' => $empresa->name,
            'logo_url' => filled($empresa->logo_path) ? Storage::disk('public')->url($empresa->logo_path) : null,
            'responsavel_tecnico' => $empresa->technical_responsible_name,
            'responsavel_tecnico_titulo' => $empresa->technical_responsible_title,
            'updated_at' => optional($empresa->updated_at)->toIso8601String(),
        ];
    }

    // -----------------------------------------------------------------
    // Catálogos de apoio
    // -----------------------------------------------------------------

    /**
     * @return array<int, array>
     */
    private function carregarTiposDeEvento(?Carbon $desde): array
    {
        return EventType::query()
            ->where('is_active', true)
            ->when($desde !== null, fn (Builder $q) => $q->where('updated_at', '>', $desde))
            ->orderBy('name')
            ->get(['id', 'name', 'description', 'color', 'updated_at'])
            ->map(fn (EventType $tipo) => [
                'id' => $tipo->id,
                'nome' => $tipo->name,
                'descricao' => $tipo->description,
                'cor' => $tipo->color,
                'updated_at' => $tipo->updated_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array>
     */
    private function carregarTiposDeIsca(?Carbon $desde): array
    {
        return BaitType::query()
            ->when($desde !== null, fn (Builder $q) => $q->where('updated_at', '>', $desde))
            ->orderBy('name')
            ->get(['id', 'name', 'brand', 'notes', 'updated_at'])
            ->map(fn (BaitType $tipo) => [
                'id' => $tipo->id,
                'nome' => $tipo->name,
                'marca' => $tipo->brand,
                'observacoes' => $tipo->notes,
                'updated_at' => $tipo->updated_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{id: string, nome: string}>
     */
    private function catalogoDePragas(): array
    {
        $itens = [];

        foreach (self::PRAGAS as $id => $nome) {
            $itens[] = ['id' => $id, 'nome' => $nome];
        }

        return $itens;
    }

    /**
     * Ficha técnica resumida do produto: nome e os quatro cadastros ligados a
     * ele, sem preço nem qualquer dado de custo (produto não guarda preço).
     *
     * @return array<int, array>
     */
    private function carregarProdutos(?Carbon $desde): array
    {
        return Product::query()
            ->when($desde !== null, fn (Builder $q) => $q->where('updated_at', '>', $desde))
            ->with([
                'activeIngredient:id,name',
                'chemicalGroup:id,name',
                'antidote:id,name',
                'organRegistration:id,record',
            ])
            ->orderBy('name')
            ->get(['id', 'name', 'active_ingredient_id', 'chemical_group_id', 'antidote_id', 'organ_registration_id', 'updated_at'])
            ->map(fn (Product $produto) => [
                'id' => $produto->id,
                'nome' => $produto->name,
                'principio_ativo' => $produto->activeIngredient?->name,
                'grupo_quimico' => $produto->chemicalGroup?->name,
                'antidoto' => $produto->antidote?->name,
                'registro_orgao' => $produto->organRegistration?->record,
                'updated_at' => $produto->updated_at->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * Produtos excluídos desde a última carga. `Product` também usa a trait
     * `Auditavel`, então a exclusão fica em `AuditLog` do mesmo jeito que a
     * de `WorkOrder`.
     *
     * @return array<int, int>
     */
    private function produtosRemovidosDesde(?Carbon $desde): array
    {
        if ($desde === null) {
            return [];
        }

        return AuditLog::query()
            ->where('auditable_type', Product::class)
            ->where('acao', 'excluido')
            ->where('created_at', '>', $desde)
            ->pluck('auditable_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function falha(int $status, string $mensagem): array
    {
        return ['sucesso' => false, 'status' => $status, 'mensagem' => $mensagem];
    }
}
