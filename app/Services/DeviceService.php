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
     * Delete a device.
     */
    public function deleteDevice(Device $device): bool
    {
        return $device->delete();
    }
}










