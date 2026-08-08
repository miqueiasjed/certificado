<?php

namespace App\Http\Controllers;

use App\Http\Requests\VehicleRequest;
use App\Models\Vehicle;
use App\Services\Fleet\VehicleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cadastro de veículo e ficha da frota (Plano 27, Task 27.4).
 *
 * Controller fino: nenhuma regra de frota mora aqui. Criar o veículo (e, junto
 * com ele, o local de estoque do Plano 17), montar a ficha com consumo e custo
 * por quilômetro, e decidir o que é quilometragem válida são todos assunto do
 * `VehicleService` e do `CustoPorKmService`.
 *
 * Isolamento entre empresas: `{vehicle}` chega por route-model binding, e o
 * escopo global de `Vehicle` faz o binding não encontrar veículo de outra
 * empresa — 404, que é a resposta certa quando revelar a existência do registro
 * já vaza informação. `technician_id` vem do corpo da requisição, e ali o
 * escopo global não ajuda: a regra `exists` do Laravel roda direto no query
 * builder. Quem separa as empresas nesse caso é o `VehicleRequest`, que compõe
 * `Rule::exists('technicians', 'id')` com `company_id = TenantAtual::exigirId()`
 * — técnico de outra empresa para na validação, com mensagem. O módulo e a
 * permissão da rota dizem o que este usuário pode fazer, nunca a qual empresa o
 * id enviado pertence.
 */
class VehicleController extends Controller
{
    public function __construct(
        private readonly VehicleService $veiculos,
    ) {}

    /**
     * Lista os veículos da empresa, com o técnico responsável, o consumo médio
     * e a situação dos alertas.
     *
     * Filtros aceitos: `situacao` e `technician_id`.
     *
     * O consumo é calculado veículo a veículo, e não em uma consulta só: a
     * apuração depende de percorrer a série de abastecimentos em ordem de
     * hodômetro (`CustoPorKmService`), o que não cabe em agregação de SQL. É
     * aceitável aqui porque frota é da ordem de dezenas de veículos, nunca de
     * milhares — se um dia passar disso, o caminho é materializar o consumo em
     * coluna, não reimplementar o cálculo em SQL e ter duas versões da regra.
     */
    public function index(Request $request): Response|JsonResponse
    {
        $consulta = Vehicle::query()->with(['technician', 'stockLocation'])->orderBy('placa');

        if ($request->filled('situacao')) {
            $consulta->where('situacao', $request->string('situacao')->toString());
        }

        if ($request->filled('technician_id')) {
            $consulta->where('technician_id', $request->integer('technician_id'));
        }

        $resumo = $this->veiculos->resumoDaLista($consulta->get());

        $dados = [
            'veiculos' => $resumo['veiculos'],
            'indicadores' => $resumo['indicadores'],
            'filtros' => [
                'situacao' => $request->string('situacao')->toString() ?: null,
                'technician_id' => $request->filled('technician_id') ? $request->integer('technician_id') : null,
            ],
            'tipos' => Vehicle::TIPOS,
            'situacoes' => Vehicle::SITUACOES,
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Frota/Index', $dados);
    }

    /**
     * Ficha do veículo: consumo médio, custo por quilômetro, próximas
     * manutenções, documentos com validade e saldo do estoque do veículo.
     */
    public function show(Request $request, Vehicle $vehicle): Response|JsonResponse
    {
        $dados = $this->veiculos->ficha($vehicle);

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Frota/Show', $dados);
    }

    public function store(VehicleRequest $request): RedirectResponse|JsonResponse
    {
        $veiculo = $this->veiculos->criar($request->validated());

        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => 'Veículo cadastrado.',
                'veiculo' => $veiculo,
            ], 201);
        }

        return redirect()
            ->route('frota.show', $veiculo)
            ->with('success', 'Veículo cadastrado.');
    }

    public function update(VehicleRequest $request, Vehicle $vehicle): RedirectResponse|JsonResponse
    {
        $veiculo = $this->veiculos->atualizar($vehicle, $request->validated());

        if ($request->expectsJson()) {
            return response()->json([
                'mensagem' => 'Veículo atualizado.',
                'veiculo' => $veiculo,
            ]);
        }

        return redirect()
            ->route('frota.show', $veiculo)
            ->with('success', 'Veículo atualizado.');
    }

    /**
     * Exclusão do cadastro.
     *
     * Recusa o veículo que já tem histórico: abastecimento e manutenção são o
     * que sustenta o custo por quilômetro que entrou na margem de OS já
     * fechadas, e apagar isso reescreveria financeiro passado sem deixar
     * rastro. Veículo que saiu de operação vira `situacao = inativo`, que é
     * para isso que a coluna existe.
     */
    public function destroy(Request $request, Vehicle $vehicle): RedirectResponse|JsonResponse
    {
        $temHistorico = $vehicle->refuelings()->exists() || $vehicle->maintenances()->exists();

        if ($temHistorico) {
            $mensagem = 'Este veículo tem abastecimento ou manutenção registrados e não pode ser excluído. '
                .'Marque a situação como inativo: o histórico sustenta o custo por quilômetro de ordens de '
                .'serviço já fechadas.';

            if ($request->expectsJson()) {
                return response()->json(['mensagem' => $mensagem], 422);
            }

            return redirect()->route('frota.show', $vehicle)->with('error', $mensagem);
        }

        $vehicle->delete();

        if ($request->expectsJson()) {
            return response()->json(['mensagem' => 'Veículo excluído.']);
        }

        return redirect()->route('frota.index')->with('success', 'Veículo excluído.');
    }
}
