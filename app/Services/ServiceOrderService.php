<?php

namespace App\Services;

use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class ServiceOrderService
{
    public function getAllServiceOrders(): Collection
    {
        return ServiceOrder::with(['client', 'technician'])->orderBy('created_at', 'desc')->get();
    }

    public function getServiceOrdersPaginated(int $perPage = 15): LengthAwarePaginator
    {
        return ServiceOrder::with(['client', 'technician'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function findServiceOrder(int $id): ?ServiceOrder
    {
        return ServiceOrder::with(['client', 'technician'])->find($id);
    }

    public function createServiceOrder(array $data): ServiceOrder
    {
        $rooms = $data['rooms'] ?? [];
        unset($data['rooms']);

        $serviceOrder = ServiceOrder::create($data);

        if (!empty($rooms)) {
            $this->syncRooms($serviceOrder, $rooms);
        }

        return $serviceOrder;
    }

    public function updateServiceOrder(ServiceOrder $serviceOrder, array $data): bool
    {
        $rooms = $data['rooms'] ?? [];
        unset($data['rooms']);

        $updated = $serviceOrder->update($data);

        if (isset($rooms)) {
            $this->syncRooms($serviceOrder, $rooms);
        }

        return $updated;
    }

    /**
     * Sincroniza os cômodos atendidos na ordem de serviço
     *
     * @param ServiceOrder $serviceOrder
     * @param array $rooms Array de cômodos com formato: [['id' => 1, 'observation' => 'obs'], ...]
     * @return void
     */
    protected function syncRooms(ServiceOrder $serviceOrder, array $rooms): void
    {
        $syncData = [];

        foreach ($rooms as $room) {
            if (isset($room['id'])) {
                $syncData[$room['id']] = [
                    'observation' => $room['observation'] ?? null
                ];
            }
        }

        $serviceOrder->rooms()->sync($syncData);
    }

    public function deleteServiceOrder(ServiceOrder $serviceOrder): bool
    {
        return $serviceOrder->delete();
    }

    public function searchServiceOrders(string $search): LengthAwarePaginator
    {
        return ServiceOrder::with(['client', 'technician'])
            ->where(function($query) use ($search) {
                $query->where('id', 'like', "%{$search}%")
                      ->orWhere('order_number', 'like', "%{$search}%")
                      ->orWhere('status', 'like', "%{$search}%")
                      ->orWhere('service_type', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('observations', 'like', "%{$search}%")
                      ->orWhereHas('client', function($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%")
                            ->orWhere('cnpj', 'like', "%{$search}%");
                      })
                      ->orWhereHas('technician', function($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%");
                      });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(15);
    }

    public function getServiceOrdersByStatus(string $status): Collection
    {
        return ServiceOrder::with(['client', 'technician'])
            ->where('status', $status)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getServiceOrdersByServiceType(string $serviceType): Collection
    {
        return ServiceOrder::with(['client', 'technician'])
            ->where('service_type', $serviceType)
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
