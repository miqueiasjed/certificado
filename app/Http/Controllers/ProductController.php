<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProductRequest;
use App\Services\ProductService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function __construct(
        private ProductService $productService
    ) {}

    public function index(Request $request): Response
    {
        $search = $request->get('search', '');

        if (!empty($search) && strlen($search) >= 2) {
            $products = $this->productService->searchProducts($search);
        } else {
            $products = $this->productService->getProductsPaginated();
        }

        return Inertia::render('Products/Index', [
            'products' => $products,
            'search' => $search,
        ]);
    }

    public function create(): Response
    {
        $data = [
            'activeIngredients' => $this->productService->getActiveIngredients(),
            'chemicalGroups' => $this->productService->getChemicalGroups(),
            'antidotes' => $this->productService->getAntidotes(),
            'organRegistrations' => $this->productService->getOrganRegistrations(),
        ];

        return Inertia::render('Products/Create', $data);
    }

    public function store(ProductRequest $request)
    {
        try {
            $product = $this->productService->createProduct($request->validated());

            return redirect()->route('products.index')
                ->with('success', 'Produto criado com sucesso!');
        } catch (\Exception $e) {
            return back()->withInput()
                ->with('error', 'Erro ao criar produto: ' . $e->getMessage());
        }
    }

    public function show(int $id): Response
    {
        $product = $this->productService->findProduct($id);

        if (!$product) {
            abort(404);
        }

        return Inertia::render('Products/Show', [
            'product' => $product,
        ]);
    }

    public function edit(int $id): Response
    {
        $product = $this->productService->findProduct($id);

        if (!$product) {
            abort(404);
        }

        $data = [
            'product' => $product,
            'activeIngredients' => $this->productService->getActiveIngredients(),
            'chemicalGroups' => $this->productService->getChemicalGroups(),
            'antidotes' => $this->productService->getAntidotes(),
            'organRegistrations' => $this->productService->getOrganRegistrations(),
        ];

        return Inertia::render('Products/Edit', $data);
    }

    public function update(ProductRequest $request, int $id)
    {
        try {
            $product = $this->productService->findProduct($id);

            if (!$product) {
                return back()->with('error', 'Produto não encontrado');
            }

            $this->productService->updateProduct($product, $request->validated());

            return redirect()->route('products.index')
                ->with('success', 'Produto atualizado com sucesso!');
        } catch (\Exception $e) {
            return back()->withInput()
                ->with('error', 'Erro ao atualizar produto: ' . $e->getMessage());
        }
    }

    public function destroy(int $id)
    {
        try {
            $product = $this->productService->findProduct($id);

            if (!$product) {
                return back()->with('error', 'Produto não encontrado');
            }

            $this->productService->deleteProduct($product);

            return redirect()->route('products.index')
                ->with('success', 'Produto excluído com sucesso!');
        } catch (\Exception $e) {
            return back()->with('error', 'Erro ao excluir produto: ' . $e->getMessage());
        }
    }
}
