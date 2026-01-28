<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Budget;
use App\Models\Client;
use App\Models\Service;
use App\Models\Product;
use App\Http\Requests\StoreBudgetRequest;
use App\Http\Requests\UpdateBudgetRequest;
use App\Services\BudgetService;
use Inertia\Inertia;

class BudgetController extends Controller
{
    protected $budgetService;

    public function __construct(BudgetService $budgetService)
    {
        $this->budgetService = $budgetService;
    }

    public function index(Request $request)
    {
        $query = Budget::with('client')
            ->orderBy('created_at', 'desc');

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                // Search in prospect name
                $q->where('prospect_name', 'like', "%{$search}%")
                    // Or search in related client fields
                    ->orWhereHas('client', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%")
                            ->orWhere('cnpj', 'like', "%{$search}%");
                    });
            });
        }

        $budgets = $query->paginate(10)
            ->withQueryString();

        return Inertia::render('Budgets/Index', [
            'budgets' => $budgets,
            'filters' => $request->only(['search']),
        ]);
    }

    public function create()
    {
        return Inertia::render('Budgets/Create', [
            'clients' => Client::orderBy('name')->get(),
            'services' => Service::orderBy('name')->get(),
            'products' => Product::orderBy('name')->get(),
        ]);
    }

    public function store(StoreBudgetRequest $request)
    {
        $this->budgetService->createBudget($request->validated());

        return redirect()->route('budgets.index')->with('success', 'Orçamento criado com sucesso!');
    }

    public function edit(Budget $budget)
    {
        $budget->load(['services', 'products', 'client']);

        return Inertia::render('Budgets/Edit', [
            'budget' => $budget,
            'clients' => Client::orderBy('name')->get(),
            'services' => Service::orderBy('name')->get(),
            'products' => Product::orderBy('name')->get(),
        ]);
    }

    public function update(UpdateBudgetRequest $request, Budget $budget)
    {
        $this->budgetService->updateBudget($budget, $request->validated());

        return redirect()->route('budgets.index')->with('success', 'Orçamento atualizado com sucesso!');
    }

    public function destroy(Budget $budget)
    {
        $this->budgetService->deleteBudget($budget);
        return redirect()->route('budgets.index')->with('success', 'Orçamento excluído com sucesso!');
    }

    public function convert(Budget $budget)
    {
        try {
            $result = $this->budgetService->prepareForConversion($budget);

            return redirect()->route('work-orders.create', [
                'client_id' => $result['client_id'],
            ])->with('success', 'Orçamento convertido! Continue a criação da OS.');

        } catch (\Exception $e) {
            return redirect()->back()->with('error', $e->getMessage());
        }
    }

    public function pdf(Budget $budget)
    {
        $budget->load(['services', 'products', 'client', 'user']);
        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('budgets.pdf', ['budget' => $budget]);
        return $pdf->stream("orcamento-{$budget->id}.pdf");
    }
}
