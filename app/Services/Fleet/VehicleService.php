<?php

namespace App\Services\Fleet;

use App\Models\StockBalance;
use App\Models\StockLocation;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Services\ModuleService;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * Cadastro do veículo e a ficha que a tela de frota mostra (Plano 27,
 * Task 27.4).
 *
 * O local de estoque nasce junto com o veículo
 * --------------------------------------------
 * O Plano 17 deixou `stock_locations.tipo = 'veiculo'` declarado e sem uso, à
 * espera deste plano. Criar o veículo e o local em dois cadastros separados, e
 * pedir que o usuário os vincule à mão, é exatamente como uma integração fica
 * sem uso: ninguém faz o segundo passo, e o estoque do veículo continua
 * inexistente. Por isso `criar()` cria os dois na mesma transação, e o vínculo
 * já sai gravado em `vehicles.stock_location_id`.
 *
 * A criação do local é condicionada ao módulo `estoque` estar ativo para o
 * tenant: criar local de estoque para quem não usa estoque só polui um cadastro
 * que a empresa nunca vai abrir. Veículo cadastrado antes de o módulo ser
 * ligado ganha o local na primeira atualização feita depois disso.
 *
 * Ficha do veículo
 * ----------------
 * `ficha()` junta o que a tela precisa em uma leitura só: consumo médio e custo
 * por quilômetro (`CustoPorKmService`, que já diz se o número é medido ou o
 * padrão do veículo), próximas manutenções, documentos com validade e o saldo
 * do estoque do veículo. Nenhuma regra é reimplementada aqui.
 */
class VehicleService
{
    /**
     * Janela de apuração do consumo mostrado na ficha, em meses.
     *
     * A mesma de `RateioDeDeslocamentoService::MESES_DE_APURACAO`, de
     * propósito: a ficha precisa mostrar o mesmo custo por quilômetro que entra
     * na margem da OS. Dois períodos diferentes fariam a tela e o financeiro
     * discordarem sobre o custo do mesmo veículo, e não haveria como dizer qual
     * dos dois está certo.
     */
    public const MESES_DE_APURACAO = RateioDeDeslocamentoService::MESES_DE_APURACAO;

    public function __construct(
        private readonly CustoPorKmService $custoPorKm,
        private readonly AlertaDeFrotaService $alertas,
        private readonly ModuleService $modulos,
    ) {}

    /**
     * Cria o veículo e, quando o módulo de estoque está ativo, o local de
     * estoque dele.
     *
     * @param  array<string, mixed>  $dados
     */
    public function criar(array $dados): Vehicle
    {
        return DB::transaction(function () use ($dados): Vehicle {
            $veiculo = Vehicle::create($this->camposDoCadastro($dados));

            $this->garantirLocalDeEstoque($veiculo);

            return $veiculo->fresh(['stockLocation', 'technician']);
        });
    }

    /**
     * Atualiza o cadastro e aproveita para criar o local de estoque de veículo
     * cadastrado antes de o módulo de estoque ser ligado.
     *
     * `km_atual` **não** é atualizado por aqui: quem move o hodômetro é o
     * registro de abastecimento e o de manutenção, que recusam leitura
     * retroativa. Permitir a edição livre no cadastro reabriria a porta que
     * `QuilometragemRetroativaException` existe para fechar.
     *
     * @param  array<string, mixed>  $dados
     */
    public function atualizar(Vehicle $veiculo, array $dados): Vehicle
    {
        return DB::transaction(function () use ($veiculo, $dados): Vehicle {
            $veiculo->update($this->camposDoCadastro($dados));

            $this->garantirLocalDeEstoque($veiculo);

            return $veiculo->fresh(['stockLocation', 'technician']);
        });
    }

    /**
     * Ficha completa do veículo: consumo, custo por quilômetro, próximas
     * manutenções, documentos e saldo do estoque do veículo.
     *
     * @return array<string, mixed>
     */
    public function ficha(Vehicle $veiculo): array
    {
        $ate = BusinessDate::hoje();
        $de = $ate->subMonths(self::MESES_DE_APURACAO);

        $consumo = $this->custoPorKm->consumo($veiculo, $de->toDateString(), $ate->toDateString());
        $custo = $this->custoPorKm->custoTotalPorKm($veiculo, $de->toDateString(), $ate->toDateString());

        return [
            'veiculo' => $veiculo->load(['technician', 'stockLocation']),
            'periodo' => ['de' => $de->toDateString(), 'ate' => $ate->toDateString()],
            'consumo' => $consumo,
            'serie_de_consumo' => $this->custoPorKm->serieDeConsumo($veiculo, $de->toDateString(), $ate->toDateString()),
            'custo_por_km' => $custo,
            'proximas_manutencoes' => $veiculo->maintenances()
                ->where('situacao', 'agendada')
                ->orderByRaw('proxima_em_data is null, proxima_em_data')
                ->get()
                ->all(),
            'ultimos_abastecimentos' => $veiculo->refuelings()
                ->orderByDesc('data')
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->all(),
            'documentos' => $veiculo->documents()->orderBy('validade')->get()
                ->map(fn ($documento): array => [
                    'documento' => $documento,
                    'vencido' => BusinessDate::estaVencido($documento->validade),
                ])
                ->all(),
            'estoque' => $this->saldoDoVeiculo($veiculo),
            // Fornecedores ativos para a oferta de título a pagar do modal de
            // abastecimento. Só id e nome: a ficha não é tela de fornecedor, e
            // mandar o cadastro inteiro para o navegador expõe documento e
            // contato sem necessidade.
            'fornecedores' => Supplier::query()
                ->where('ativo', true)
                ->orderBy('nome')
                ->get(['id', 'nome'])
                ->all(),
            'alertas' => [
                'manutencoes' => $this->alertas->manutencoesAVencer()
                    ->filter(fn (array $alerta): bool => (int) $alerta['veiculo']->getKey() === (int) $veiculo->getKey())
                    ->values()
                    ->all(),
            ],
        ];
    }

    /**
     * Resumo da lista de veículos: consumo médio e alertas por veículo, mais
     * os indicadores do topo da tela (Task 27.5).
     *
     * Calculado veículo a veículo de propósito. Ver o docblock de
     * `VehicleController::index()` para o raciocínio.
     *
     * @param  \Illuminate\Support\Collection<int, Vehicle>  $veiculos
     * @return array{veiculos: array<int, array<string, mixed>>, indicadores: array<string, int>}
     */
    public function resumoDaLista($veiculos): array
    {
        $ate = BusinessDate::hoje();
        $de = $ate->subMonths(self::MESES_DE_APURACAO);

        $manutencoes = $this->alertas->manutencoesAVencer()->groupBy(
            fn (array $alerta): int => (int) $alerta['veiculo']->getKey()
        );

        $documentos = collect($this->alertas->documentosAVencer()['vencendo'])
            // A janela mais larga (60 dias) já contém as outras duas: contar as
            // três somaria o mesmo documento até três vezes no indicador.
            ->first()['itens'] ?? collect();

        $documentosPorVeiculo = $documentos->groupBy(
            fn (array $item): int => (int) $item['veiculo']->getKey()
        );

        $linhas = $veiculos->map(function (Vehicle $veiculo) use ($de, $ate, $manutencoes, $documentosPorVeiculo): array {
            $consumo = $this->custoPorKm->consumo($veiculo, $de->toDateString(), $ate->toDateString());
            $custo = $this->custoPorKm->custoTotalPorKm($veiculo, $de->toDateString(), $ate->toDateString());

            return [
                'veiculo' => $veiculo,
                'km_por_litro' => $consumo['km_por_litro'],
                'custo_por_km' => $custo['total_por_km'],
                'origem_custo_km' => $custo['origem'],
                'manutencoes_a_vencer' => $manutencoes->get((int) $veiculo->getKey(), collect())->count(),
                'documentos_a_vencer' => $documentosPorVeiculo->get((int) $veiculo->getKey(), collect())->count(),
            ];
        })->values();

        return [
            'veiculos' => $linhas->all(),
            'indicadores' => [
                'ativos' => $veiculos->where('situacao', 'ativo')->count(),
                // Manutenções distintas, não avisos: a mesma manutenção aparece
                // em duas janelas (30 e 7 dias), e contar avisos faria o
                // indicador dobrar sozinho quando o prazo apertasse.
                'manutencoes_a_vencer' => $manutencoes->flatten(1)
                    ->pluck('manutencao.id')
                    ->unique()
                    ->count(),
                'documentos_a_vencer' => $documentos->count(),
            ],
        ];
    }

    /**
     * Veículo padrão do técnico designado, para a OS já nascer com o vínculo
     * preenchido (Task 27.4, item 5).
     *
     * Devolve `null` quando o técnico não tem veículo ativo, ou tem mais de um:
     * escolher um entre dois seria adivinhar, e o campo continua editável na
     * tela justamente porque troca de veículo acontece.
     */
    public function veiculoPadraoDoTecnico(?int $technicianId): ?Vehicle
    {
        if ($technicianId === null) {
            return null;
        }

        $veiculos = Vehicle::query()
            ->ativos()
            ->where('technician_id', $technicianId)
            ->limit(2)
            ->get();

        return $veiculos->count() === 1 ? $veiculos->first() : null;
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Campos que o cadastro escreve. `km_atual` e `stock_location_id` ficam de
     * fora de propósito: o primeiro é movido pelos registros de campo, o
     * segundo é gerido por este Service.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function camposDoCadastro(array $dados): array
    {
        return array_intersect_key($dados, array_flip([
            'placa',
            'modelo',
            'marca',
            'ano',
            'tipo',
            'technician_id',
            'situacao',
            'custo_km_padrao',
        ]));
    }

    /**
     * Cria o local de estoque do veículo, se ainda não existe e se o módulo de
     * estoque está ativo para o tenant.
     *
     * `technician_id` fica nulo no local mesmo quando o veículo tem técnico
     * responsável: em `StockLocation` essa coluna significa "van do técnico"
     * (`tipo = tecnico`), e preenchê-la num local `tipo = veiculo` misturaria
     * dois conceitos que o Plano 17 separou de propósito.
     */
    private function garantirLocalDeEstoque(Vehicle $veiculo): void
    {
        if ($veiculo->stock_location_id !== null || ! $this->modulos->ativo('estoque')) {
            return;
        }

        $local = StockLocation::create([
            'nome' => 'Veículo '.$veiculo->placa,
            'tipo' => 'veiculo',
            'ativo' => true,
        ]);

        $veiculo->forceFill(['stock_location_id' => $local->getKey()])->save();
    }

    /**
     * Saldo do estoque que está dentro do veículo, produto a produto.
     *
     * Vazio quando o veículo não tem local de estoque (módulo desligado ou
     * veículo antigo): a ficha continua abrindo, só sem o bloco de estoque.
     *
     * @return array<int, array{product_id: int, produto: ?string, quantidade: float}>
     */
    private function saldoDoVeiculo(Vehicle $veiculo): array
    {
        if ($veiculo->stock_location_id === null) {
            return [];
        }

        return StockBalance::query()
            ->with('product')
            ->where('stock_location_id', $veiculo->stock_location_id)
            ->get()
            ->groupBy('product_id')
            ->map(fn ($linhas, $productId): array => [
                'product_id' => (int) $productId,
                'produto' => $linhas->first()->product?->name,
                'quantidade' => round((float) $linhas->sum('quantidade'), 4),
            ])
            ->values()
            ->all();
    }
}
