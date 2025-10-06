<?php

namespace App\Services;

use App\Models\PestSighting;

class PestSightingService
{
    /**
     * Create a new pest sighting.
     */
    public function createPestSighting(array $data): PestSighting
    {
        return PestSighting::create($data);
    }

    /**
     * Update an existing pest sighting.
     */
    public function updatePestSighting(PestSighting $pestSighting, array $data): bool
    {
        return $pestSighting->update($data);
    }

    /**
     * Delete a pest sighting.
     */
    public function deletePestSighting(PestSighting $pestSighting): bool
    {
        return $pestSighting->delete();
    }

    /**
     * Get pest sightings by address ID.
     */
    public function getSightingsByAddress(int $addressId): \Illuminate\Database\Eloquent\Collection
    {
        return PestSighting::where('address_id', $addressId)
            ->with(['workOrder.technician', 'items'])
            ->orderBy('sighting_date', 'desc')
            ->get();
    }

    /**
     * Get pest sightings by work order ID.
     */
    public function getSightingsByWorkOrder(int $workOrderId): \Illuminate\Database\Eloquent\Collection
    {
        return PestSighting::where('work_order_id', $workOrderId)
            ->with(['address.client', 'items'])
            ->orderBy('sighting_date', 'desc')
            ->get();
    }

    /**
     * Get pest sightings by pest type.
     */
    public function getSightingsByPestType(string $pestType): \Illuminate\Database\Eloquent\Collection
    {
        return PestSighting::where('pest_type', $pestType)
            ->with(['address.client', 'workOrder.technician'])
            ->orderBy('sighting_date', 'desc')
            ->get();
    }

    /**
     * Get pest sightings by severity level.
     */
    public function getSightingsBySeverityLevel(string $severityLevel): \Illuminate\Database\Eloquent\Collection
    {
        return PestSighting::where('severity_level', $severityLevel)
            ->with(['address.client', 'workOrder.technician'])
            ->orderBy('sighting_date', 'desc')
            ->get();
    }

    /**
     * Get recent pest sightings.
     */
    public function getRecentSightings(int $days = 30): \Illuminate\Database\Eloquent\Collection
    {
        return PestSighting::where('sighting_date', '>=', now()->subDays($days))
            ->with(['address.client', 'workOrder.technician'])
            ->orderBy('sighting_date', 'desc')
            ->get();
    }

    /**
     * Get pest sightings statistics.
     */
    public function getSightingsStatistics(): array
    {
        $totalSightings = PestSighting::count();
        $activeSightings = PestSighting::where('active', true)->count();
        $criticalSightings = PestSighting::where('severity_level', 'critical')->count();
        $recentSightings = PestSighting::where('sighting_date', '>=', now()->subDays(7))->count();

        return [
            'total' => $totalSightings,
            'active' => $activeSightings,
            'critical' => $criticalSightings,
            'recent' => $recentSightings,
        ];
    }
}











