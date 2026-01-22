<?php

namespace App\Services;

use App\Models\Device;

class DeviceService
{
    /**
     * Create a new device.
     */
    public function createDevice(array $data): Device
    {
        return Device::create($data);
    }

    /**
     * Update an existing device.
     */
    public function updateDevice(Device $device, array $data): bool
    {
        return $device->update($data);
    }

    /**
     * Check if device can be deleted (not linked to any work orders).
     */
    public function canDeleteDevice(Device $device): bool
    {
        // Verificar se o dispositivo tem eventos vinculados
        $hasEvents = $device->deviceEvents()->exists();

        // Verificar se o dispositivo está sendo usado em ordens de serviço ou eventos de OS
        $hasWorkOrders = $device->workOrders()->exists();
        $hasWorkOrderEvents = $device->workOrderEvents()->exists();

        return !$hasEvents && !$hasWorkOrders && !$hasWorkOrderEvents;
    }

    /**
     * Delete a device.
     */
    public function deleteDevice(Device $device): bool
    {
        if (!$this->canDeleteDevice($device)) {
            throw new \Exception('Não é possível excluir o dispositivo pois ele está vinculado a ordens de serviço ou eventos.');
        }

        return $device->delete();
    }
}
















