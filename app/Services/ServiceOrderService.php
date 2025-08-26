<?php

namespace App\Services;

use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ServiceOrderService
{
    public function getAllServiceOrders(): Collection
    {
        return ServiceOrder::with(['client', 'service'])->orderBy('created_at', 'desc')->get();
    }

    public function getServiceOrdersPaginated(int $perPage = 15): LengthAwarePaginator
    {
        return ServiceOrder::with(['client', 'service'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function findServiceOrder(int $id): ?ServiceOrder
    {
        return ServiceOrder::with(['client', 'service'])->find($id);
    }

    public function createServiceOrder(array $data): ServiceOrder
    {
        return ServiceOrder::create($data);
    }

    public function updateServiceOrder(ServiceOrder $serviceOrder, array $data): bool
    {
        return $serviceOrder->update($data);
    }

    public function deleteServiceOrder(ServiceOrder $serviceOrder): bool
    {
        return $serviceOrder->delete();
    }

    public function searchServiceOrders(string $search): LengthAwarePaginator
    {
        return ServiceOrder::with(['client', 'service'])
            ->where(function($query) use ($search) {
                $query->where('id', 'like', "%{$search}%")
                      ->orWhere('status', 'like', "%{$search}%")
                      ->orWhere('priority', 'like', "%{$search}%")
                      ->orWhere('notes', 'like', "%{$search}%")
                      ->orWhereHas('client', function($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%")
                            ->orWhere('cnpj', 'like', "%{$search}%");
                      })
                      ->orWhereHas('service', function($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%");
                      });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(15);
    }

    public function getServiceOrdersByStatus(string $status): Collection
    {
        return ServiceOrder::with(['client', 'service'])
            ->where('status', $status)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getServiceOrdersByPriority(string $priority): Collection
    {
        return ServiceOrder::with(['client', 'service'])
            ->where('priority', $priority)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getPendingServiceOrders(): Collection
    {
        return $this->getServiceOrdersByStatus('pending');
    }

    public function getInProgressServiceOrders(): Collection
    {
        return $this->getServiceOrdersByStatus('in_progress');
    }

    public function getCompletedServiceOrders(): Collection
    {
        return $this->getServiceOrdersByStatus('completed');
    }
}
