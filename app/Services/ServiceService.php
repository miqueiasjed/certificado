<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ServiceService
{
    public function getAllServices(): Collection
    {
        return Service::orderBy('name')->get();
    }

    public function getServicesPaginated(int $perPage = 15): LengthAwarePaginator
    {
        return Service::orderBy('name')->paginate($perPage);
    }

    public function findService(int $id): ?Service
    {
        return Service::find($id);
    }

    public function createService(array $data): Service
    {
        return Service::create($data);
    }

    public function updateService(Service $service, array $data): bool
    {
        return $service->update($data);
    }

    public function deleteService(Service $service): bool
    {
        return $service->delete();
    }

    public function searchServices(string $search): LengthAwarePaginator
    {
        return Service::where(function($query) use ($search) {
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%")
                  ->orWhere('category', 'like', "%{$search}%");
        })
        ->orderBy('name')
        ->paginate(15);
    }

    public function getActiveServices(): Collection
    {
        return Service::where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    public function getServicesByCategory(string $category): Collection
    {
        return Service::where('category', $category)
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
