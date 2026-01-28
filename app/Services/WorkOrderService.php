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
        $rooms = $data['rooms'] ?? [];
        $devices = $data['devices'] ?? [];

        unset($data['technicians'], $data['products'], $data['services'], $data['rooms'], $data['devices']);

        $workOrder = WorkOrder::create($data);

        // Sincronizar técnicos
        if (!empty($technicians)) {
            $technicians = array_filter($technicians, function ($id) {
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
                        'quantity' => $product['quantity'] ?? null,
                        'unit' => $product['unit'] ?? null,
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

        // Sincronizar cômodos
        if (!empty($rooms)) {
            $syncData = [];
            foreach ($rooms as $room) {
                if (!empty($room['id'])) {
                    $syncData[$room['id']] = [
                        'observation' => $room['observation'] ?? null,
                        'event_type_id' => $room['event_type'] ?? null,
                        'event_date' => $room['event_date'] ?? null,
                        'event_description' => $room['event_description'] ?? null,
                        'event_observations' => $room['event_observations'] ?? null,
                        'pest_type' => $room['pest_type'] ?? null,
                        'pest_sighting_date' => $room['pest_sighting_date'] ?? null,
                        'pest_location' => $room['pest_location'] ?? null,
                        'pest_quantity' => $room['pest_quantity'] ?? null,
                        'pest_observation' => $room['pest_observation'] ?? null,
                    ];
                }
            }
            $workOrder->rooms()->sync($syncData);
        }

        // Processar dispositivos e seus eventos
        if (!empty($devices)) {
            foreach ($devices as $deviceData) {
                if (!empty($deviceData['id'])) {
                    // Salvar dispositivo na OS (mesmo sem eventos)
                    $workOrder->devices()->syncWithoutDetaching([
                        $deviceData['id'] => [
                            'observation' => $deviceData['observation'] ?? null
                        ]
                    ]);

                    // Salvar eventos do dispositivo (se houver)
                    if (!empty($deviceData['device_events']) && is_array($deviceData['device_events'])) {
                        foreach ($deviceData['device_events'] as $deviceEvent) {
                            if (!empty($deviceEvent['event_type']) && !empty($deviceEvent['event_date'])) {
                                \App\Models\WorkOrderDeviceEvent::create([
                                    'work_order_id' => $workOrder->id,
                                    'device_id' => $deviceData['id'],
                                    'event_type_id' => $deviceEvent['event_type'],
                                    'event_date' => $deviceEvent['event_date'],
                                    'event_description' => $deviceEvent['event_description'] ?? null,
                                    'event_observations' => $deviceEvent['event_observations'] ?? null,
                                ]);
                            }
                        }
                    }
                }
            }
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
        $rooms = $data['rooms'] ?? [];
        $devices = $data['devices'] ?? [];

        unset($data['technicians'], $data['products'], $data['services'], $data['rooms'], $data['devices']);

        $result = $workOrder->update($data);

        // Sincronizar técnicos
        if (!empty($technicians)) {
            $technicians = array_filter($technicians, function ($id) {
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
                        'quantity' => $product['quantity'] ?? null,
                        'unit' => $product['unit'] ?? null,
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

        // Sincronizar cômodos
        if (!empty($rooms)) {
            $syncData = [];
            foreach ($rooms as $room) {
                if (!empty($room['id'])) {
                    $syncData[$room['id']] = [
                        'observation' => $room['observation'] ?? null,
                        'event_type_id' => $room['event_type'] ?? null,
                        'event_date' => $room['event_date'] ?? null,
                        'event_description' => $room['event_description'] ?? null,
                        'event_observations' => $room['event_observations'] ?? null,
                        'pest_type' => $room['pest_type'] ?? null,
                        'pest_sighting_date' => $room['pest_sighting_date'] ?? null,
                        'pest_location' => $room['pest_location'] ?? null,
                        'pest_quantity' => $room['pest_quantity'] ?? null,
                        'pest_observation' => $room['pest_observation'] ?? null,
                    ];
                }
            }
            $workOrder->rooms()->sync($syncData);
        }

        // Processar dispositivos e seus eventos
        if (!empty($devices)) {
            $syncData = [];
            foreach ($devices as $deviceData) {
                if (!empty($deviceData['id'])) {
                    // Preparar dados para sincronização
                    $syncData[$deviceData['id']] = [
                        'observation' => $deviceData['observation'] ?? null
                    ];
                }
            }
            // Sincronizar dispositivos
            $workOrder->devices()->sync($syncData);

            // Remover eventos de dispositivo existentes e recriar
            $workOrder->workOrderDeviceEvents()->delete();

            // Processar eventos dos dispositivos
            foreach ($devices as $deviceData) {
                if (!empty($deviceData['id']) && !empty($deviceData['device_events']) && is_array($deviceData['device_events'])) {
                    foreach ($deviceData['device_events'] as $deviceEvent) {
                        if (!empty($deviceEvent['event_type']) && !empty($deviceEvent['event_date'])) {
                            \App\Models\WorkOrderDeviceEvent::create([
                                'work_order_id' => $workOrder->id,
                                'device_id' => $deviceData['id'],
                                'event_type_id' => $deviceEvent['event_type'],
                                'event_date' => $deviceEvent['event_date'],
                                'event_description' => $deviceEvent['event_description'] ?? null,
                                'event_observations' => $deviceEvent['event_observations'] ?? null,
                            ]);
                        }
                    }
                }
            }
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
    /**
     * Mark work order as completed.
     */
    public function markAsCompleted(WorkOrder $workOrder, array $data = []): bool
    {
        $data['status'] = 'completed';
        $data['end_time'] = now();

        return $workOrder->update($data);
    }

    /**
     * Prepare data for PDF generation, including Base64 images.
     */
    public function preparePdfData(WorkOrder $workOrder): array
    {
        // Load relationships
        $workOrder->load([
            'client',
            'address.client',
            'technician',
            'technicians',
            'products' => function ($query) {
                $query->withPivot(['quantity', 'unit', 'observations']);
            },
            'service',
            'rooms' => function ($query) {
                $query->withPivot([
                    'observation',
                    'event_type_id',
                    'event_date',
                    'event_description',
                    'event_observations',
                    'pest_type',
                    'pest_sighting_date',
                    'pest_location',
                    'pest_quantity',
                    'pest_observation'
                ]);
            },
            'devices' => function ($query) {
                $query->with(['baitType'])->orderBy('label');
            },
            'workOrderDeviceEvents' => function ($query) {
                $query->with([
                    'device.address.client',
                    'device.baitType',
                    'eventType'
                ])->orderBy('event_date', 'desc');
            },
            'pestSightings' => function ($query) {
                $query->with(['address.client'])->orderBy('sighting_date', 'desc');
            }
        ]);

        $company = \App\Models\Company::current();

        return [
            'workOrder' => $workOrder,
            'company' => $company,
            'currentDate' => now()->format('d/m/Y'),
            'currentTime' => now()->format('H:i'),
            'logoSrc' => $this->convertStorageFileToBase64($company->logo_path),
            'chemSrc' => $this->convertStorageFileToBase64($company->signature_chemical_path),
        ];
    }

    /**
     * Prepare receipt data for PDF generation.
     */
    public function prepareReceiptData(WorkOrder $workOrder): array
    {
        $workOrder->load([
            'client',
            'address',
            'paymentDetails' => function ($query) {
                $query->whereNotNull('payment_date')->orderBy('payment_date', 'desc');
            }
        ]);

        $totalPaid = $workOrder->paymentDetails->sum('amount_paid');
        $receiptNumber = 'REC-' . str_pad($workOrder->id, 6, '0', STR_PAD_LEFT) . '-' . date('Ymd');
        $company = \App\Models\Company::current();

        return [
            'workOrder' => $workOrder,
            'payments' => $workOrder->paymentDetails,
            'totalPaid' => $totalPaid,
            'receiptNumber' => $receiptNumber,
            'logoSrc' => $this->convertStorageFileToBase64($company->logo_path),
            'company' => $company,
        ];
    }

    /**
     * Convert a stored image file to a Base64 string.
     */
    private function convertStorageFileToBase64(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        $fullPath = storage_path('app/public/' . $path);

        if (!file_exists($fullPath)) {
            return null;
        }

        $extension = pathinfo($fullPath, PATHINFO_EXTENSION);
        $data = file_get_contents($fullPath);

        $mime = match (strtolower($extension)) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            default => 'application/octet-stream',
        };

        return 'data:' . $mime . ';base64,' . base64_encode($data);
    }
}
