<?php

namespace App\Services;

use App\Models\DeviceEvent;


class DeviceEventService
{
    /**
     * Create a new device event.
     */
    public function createDeviceEvent(array $data): DeviceEvent
    {
        return DeviceEvent::create($data);
    }

    /**
     * Update an existing device event.
     */
    public function updateDeviceEvent(DeviceEvent $deviceEvent, array $data): bool
    {
        return $deviceEvent->update($data);
    }

    /**
     * Delete a device event.
     */
    public function deleteDeviceEvent(DeviceEvent $deviceEvent): bool
    {
        return $deviceEvent->delete();
    }

    /**
     * Get device events by device ID.
     */
    public function getEventsByDevice(int $deviceId): \Illuminate\Database\Eloquent\Collection
    {
        return DeviceEvent::where('device_id', $deviceId)
            ->with(['workOrder.technician'])
            ->orderBy('event_date', 'desc')
            ->get();
    }

    /**
     * Get device events by work order ID.
     */
    public function getEventsByWorkOrder(int $workOrderId): \Illuminate\Database\Eloquent\Collection
    {
        return DeviceEvent::where('work_order_id', $workOrderId)
            ->with(['device.room.address.client'])
            ->orderBy('event_date', 'desc')
            ->get();
    }

    /**
     * Get recent device events.
     */
    public function getRecentEvents(int $days = 30): \Illuminate\Database\Eloquent\Collection
    {
        return DeviceEvent::where('event_date', '>=', now()->subDays($days))
            ->with(['device.room.address.client', 'workOrder.technician'])
            ->orderBy('event_date', 'desc')
            ->get();
    }

    /**
     * Get device events by type.
     */
    public function getEventsByType(string $type): \Illuminate\Database\Eloquent\Collection
    {
        return DeviceEvent::where('event_type', $type)
            ->with(['device.room.address.client', 'workOrder.technician'])
            ->orderBy('event_date', 'desc')
            ->get();
    }
}

