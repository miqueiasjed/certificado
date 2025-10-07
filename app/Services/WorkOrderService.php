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
        return WorkOrder::create($data);
    }

    /**
     * Update an existing work order.
     */
    public function updateWorkOrder(WorkOrder $workOrder, array $data): bool
    {
        return $workOrder->update($data);
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
        do {
            $lastOrder = WorkOrder::orderBy('id', 'desc')->first();
            $nextId = $lastOrder ? $lastOrder->id + 1 : 1;
            $orderNumber = 'OS' . str_pad($nextId, 6, '0', STR_PAD_LEFT);

            // Check if this order number already exists
            $exists = WorkOrder::where('order_number', $orderNumber)->exists();

            if (!$exists) {
                return $orderNumber;
            }

            // If exists, increment and try again
            $nextId++;
        } while (true);
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
