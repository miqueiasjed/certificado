<?php

namespace App\Services;

use App\Models\Technician;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class TechnicianService
{
    public function getAllTechnicians(): Collection
    {
        return Technician::orderBy('name')->get();
    }

    public function getTechniciansPaginated(int $perPage = 15): LengthAwarePaginator
    {
        return Technician::orderBy('name')->paginate($perPage);
    }

    public function findTechnician(int $id): ?Technician
    {
        return Technician::find($id);
    }

    public function createTechnician(array $data): Technician
    {
        return Technician::create($data);
    }

    public function updateTechnician(Technician $technician, array $data): bool
    {
        return $technician->update($data);
    }

    public function deleteTechnician(Technician $technician): bool
    {
        return $technician->delete();
    }

    public function searchTechnicians(string $search): LengthAwarePaginator
    {
        return Technician::where(function($query) use ($search) {
            $query->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('specialty', 'like', "%{$search}%")
                  ->orWhere('registration_number', 'like', "%{$search}%");
        })
        ->orderBy('name')
        ->paginate(15);
    }

    public function getActiveTechnicians(): Collection
    {
        return Technician::where('is_active', true)
            ->orderBy('name')
            ->get();
    }
}
