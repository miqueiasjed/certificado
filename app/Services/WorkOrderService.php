<?php

namespace App\Services;

use App\Models\WorkOrder;

class WorkOrderService
{
    /**
     * Create a new work order.
     */
    public function createWorkOrder(array $data): WorkOrder
    {
        $technicians = $data['technicians'] ?? [];
        $products = $data['products'] ?? [];
        $services = $data['services'] ?? [];

        unset($data['technicians'], $data['products'], $data['services']);

        $workOrder = WorkOrder::create($data);

        // Sincronizar técnicos
        if (!empty($technicians)) {
            $technicians = array_filter($technicians, function($id) {
                return !empty($id);
            });

            if (!empty($technicians)) {
                $syncData = [];
                foreach ($technicians as $index => $technicianId) {
                    $syncData[$technicianId] = ['is_primary' => $index === 0];
                }
                $workOrder->technicians()->sync($syncData);
            }
        }

        // Sincronizar produtos
        if (!empty($products)) {
            $syncData = [];
            foreach ($products as $product) {
                if (!empty($product['id'])) {
                    $syncData[$product['id']] = [
                        'quantity' => $product['quantity'] ?? 1,
                        'observations' => $product['observations'] ?? null
                    ];
                }
            }
            $workOrder->products()->sync($syncData);
        }

        // Sincronizar serviços
        if (!empty($services)) {
            $syncData = [];
            foreach ($services as $service) {
                if (!empty($service['id'])) {
                    $syncData[$service['id']] = [
                        'observations' => $service['observations'] ?? null
                    ];
                }
            }
            $workOrder->services()->sync($syncData);
        }

        return $workOrder;
    }

    /**
     * Update an existing work order.
     */
    public function updateWorkOrder(WorkOrder $workOrder, array $data): bool
    {
        $technicians = $data['technicians'] ?? [];
        $products = $data['products'] ?? [];
        $services = $data['services'] ?? [];

        unset($data['technicians'], $data['products'], $data['services']);

        $result = $workOrder->update($data);

        // Sincronizar técnicos
        if (!empty($technicians)) {
            $technicians = array_filter($technicians, function($id) {
                return !empty($id);
            });

            if (!empty($technicians)) {
                $syncData = [];
                foreach ($technicians as $index => $technicianId) {
                    $syncData[$technicianId] = ['is_primary' => $index === 0];
                }
                $workOrder->technicians()->sync($syncData);
            }
        }

        // Sincronizar produtos
        if (!empty($products)) {
            $syncData = [];
            foreach ($products as $product) {
                if (!empty($product['id'])) {
                    $syncData[$product['id']] = [
                        'quantity' => $product['quantity'] ?? 1,
                        'observations' => $product['observations'] ?? null
                    ];
                }
            }
            $workOrder->products()->sync($syncData);
        }

        // Sincronizar serviços
        if (!empty($services)) {
            $syncData = [];
            foreach ($services as $service) {
                if (!empty($service['id'])) {
                    $syncData[$service['id']] = [
                        'observations' => $service['observations'] ?? null
                    ];
                }
            }
            $workOrder->services()->sync($syncData);
        }

        return $result;
    }

    /**
     * Delete a work order.
     */
    public function deleteWorkOrder(WorkOrder $workOrder): bool
    {
        return $workOrder->delete();
    }

    /**
     * Get work orders by client ID.
     */
    public function getWorkOrdersByClient(int $clientId): \Illuminate\Database\Eloquent\Collection
    {
        return WorkOrder::where('client_id', $clientId)
            ->with(['address', 'technician'])
            ->orderBy('scheduled_date', 'desc')
            ->get();
    }

    /**
     * Get work orders by address ID.
     */
    public function getWorkOrdersByAddress(int $addressId): \Illuminate\Database\Eloquent\Collection
    {
        return WorkOrder::where('address_id', $addressId)
            ->with(['client', 'technician'])
            ->orderBy('scheduled_date', 'desc')
            ->get();
    }

    /**
     * Get work orders by technician ID.
     */
    public function getWorkOrdersByTechnician(int $technicianId): \Illuminate\Database\Eloquent\Collection
    {
        return WorkOrder::where('technician_id', $technicianId)
            ->with(['client', 'address'])
            ->orderBy('scheduled_date', 'desc')
            ->get();
    }

    /**
     * Get work orders by status.
     */
    public function getWorkOrdersByStatus(string $status): \Illuminate\Database\Eloquent\Collection
    {
        return WorkOrder::where('status', $status)
            ->with(['client', 'address', 'technician'])
            ->orderBy('scheduled_date', 'desc')
            ->get();
    }

    /**
     * Get pending work orders.
     */
    public function getPendingWorkOrders(): \Illuminate\Database\Eloquent\Collection
    {
        return WorkOrder::pending()
            ->with(['client', 'address', 'technician'])
            ->orderBy('scheduled_date', 'asc')
            ->get();
    }

    /**
     * Get overdue work orders.
     */
    public function getOverdueWorkOrders(): \Illuminate\Database\Eloquent\Collection
    {
        return WorkOrder::overdue()
            ->with(['client', 'address', 'technician'])
            ->orderBy('scheduled_date', 'asc')
            ->get();
    }

    /**
     * Get today's work orders.
     */
    public function getTodayWorkOrders(): \Illuminate\Database\Eloquent\Collection
    {
        return WorkOrder::today()
            ->with(['client', 'address', 'technician'])
            ->orderBy('scheduled_date', 'asc')
            ->get();
    }

    /**
     * Get this week's work orders.
     */
    public function getThisWeekWorkOrders(): \Illuminate\Database\Eloquent\Collection
    {
        return WorkOrder::thisWeek()
            ->with(['client', 'address', 'technician'])
            ->orderBy('scheduled_date', 'asc')
            ->get();
    }

    /**
     * Get this month's work orders.
     */
    public function getThisMonthWorkOrders(): \Illuminate\Database\Eloquent\Collection
    {
        return WorkOrder::thisMonth()
            ->with(['client', 'address', 'technician'])
            ->orderBy('scheduled_date', 'asc')
            ->get();
    }

    /**
     * Get work order statistics.
     */
    public function getWorkOrderStatistics(): array
    {
        $totalWorkOrders = WorkOrder::count();
        $pendingWorkOrders = WorkOrder::pending()->count();
        $inProgressWorkOrders = WorkOrder::where('status', 'in_progress')->count();
        $completedWorkOrders = WorkOrder::completed()->count();
        $overdueWorkOrders = WorkOrder::overdue()->count();
        $todayWorkOrders = WorkOrder::today()->count();

        return [
            'total' => $totalWorkOrders,
            'pending' => $pendingWorkOrders,
            'in_progress' => $inProgressWorkOrders,
            'completed' => $completedWorkOrders,
            'overdue' => $overdueWorkOrders,
            'today' => $todayWorkOrders,
        ];
    }

    /**
     * Generate order number.
     */
    public function generateOrderNumber(): string
    {
        // Otimização: usar max('id') ao invés de orderBy().first()
        $maxId = WorkOrder::max('id') ?? 0;
        $attempts = 0;
        $maxAttempts = 10; // Limitar tentativas para evitar loop infinito

        do {
            $nextId = $maxId + 1 + $attempts;
            $orderNumber = 'OS' . str_pad($nextId, 6, '0', STR_PAD_LEFT);

            // Check if this order number already exists
            $exists = WorkOrder::where('order_number', $orderNumber)->exists();

            if (!$exists) {
                return $orderNumber;
            }

            $attempts++;

            // Se atingir o limite de tentativas, usar timestamp para garantir unicidade
            if ($attempts >= $maxAttempts) {
                return 'OS' . str_pad($nextId, 6, '0', STR_PAD_LEFT) . '-' . time();
            }
        } while ($attempts < $maxAttempts);

        // Fallback: usar timestamp
        return 'OS' . date('YmdHis');
    }

    /**
     * Update work order status.
     */
    public function updateStatus(WorkOrder $workOrder, string $status): bool
    {
        return $workOrder->update(['status' => $status]);
    }

    /**
     * Mark work order as completed.
     */
    public function markAsCompleted(WorkOrder $workOrder, array $data = []): bool
    {
        $data['status'] = 'completed';
        $data['end_time'] = now();

        return $workOrder->update($data);
    }
}
