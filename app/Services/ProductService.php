<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ProductService
{
    public function getAllProducts(): Collection
    {
        return Product::with(['activeIngredient', 'chemicalGroup', 'antidote', 'organRegistration'])->get();
    }

    public function getProductsPaginated(int $perPage = 15): LengthAwarePaginator
    {
        return Product::with(['activeIngredient', 'chemicalGroup', 'antidote', 'organRegistration'])
            ->orderBy('name')
            ->paginate($perPage);
    }

    public function findProduct(int $id): ?Product
    {
        return Product::with(['activeIngredient', 'chemicalGroup', 'antidote', 'organRegistration'])->find($id);
    }

    public function createProduct(array $data): Product
    {
        return Product::create($data);
    }

    public function updateProduct(Product $product, array $data): bool
    {
        return $product->update($data);
    }

    public function deleteProduct(Product $product): bool
    {
        return $product->delete();
    }

    public function searchProducts(string $search): LengthAwarePaginator
    {
        return Product::with(['activeIngredient', 'chemicalGroup', 'antidote', 'organRegistration'])
            ->where(function($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('manufacturer', 'like', "%{$search}%")
                      ->orWhereHas('activeIngredient', function($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%");
                      })
                      ->orWhereHas('chemicalGroup', function($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%");
                      })
                      ->orWhereHas('antidote', function($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%");
                      });
            })
            ->orderBy('name')
            ->paginate(15);
    }

    public function getActiveIngredients(): Collection
    {
        return \App\Models\ActiveIngredient::orderBy('name')->get();
    }

    public function getChemicalGroups(): Collection
    {
        return \App\Models\ChemicalGroup::orderBy('name')->get();
    }

    public function getOrganRegistrations(): Collection
    {
        return \App\Models\OrganRegistration::orderBy('record')->get();
    }

    public function getAntidotes(): Collection
    {
        return \App\Models\Antidote::orderBy('name')->get();
    }
}
