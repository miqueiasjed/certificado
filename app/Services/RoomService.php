<?php

namespace App\Services;

use App\Models\Room;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

class RoomService
{
    public function getRoomsPaginated(int $perPage = 15): LengthAwarePaginator
    {
        return Room::with(['address.client'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function getRoomsByAddress(int $addressId): Collection
    {
        return Room::where('address_id', $addressId)
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }

    public function searchRooms(string $search, int $perPage = 15): LengthAwarePaginator
    {
        return Room::with(['address.client'])
            ->where(function ($query) use ($search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('address', function ($q) use ($search) {
                        $q->where('nickname', 'like', "%{$search}%")
                            ->orWhere('street', 'like', "%{$search}%")
                            ->orWhere('city', 'like', "%{$search}%");
                    })
                    ->orWhereHas('address.client', function ($q) use ($search) {
                        $q->where('name', 'like', "%{$search}%");
                    });
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function createRoom(array $data): Room
    {
        return Room::create($data);
    }

    public function updateRoom(Room $room, array $data): Room
    {
        $room->update($data);
        return $room->fresh();
    }

    public function deleteRoom(Room $room): bool
    {
        return $room->delete();
    }

    public function getRoomById(int $id): ?Room
    {
        return Room::with(['address.client', 'devices'])->find($id);
    }

    public function getActiveRoomsByAddress(int $addressId): Collection
    {
        return Room::where('address_id', $addressId)
            ->where('active', true)
            ->with(['devices' => function ($query) {
                $query->where('active', true);
            }])
            ->orderBy('name')
            ->get();
    }

    public function getRoomsWithDeviceCount(int $addressId): Collection
    {
        return Room::where('address_id', $addressId)
            ->withCount(['devices' => function ($query) {
                $query->where('active', true);
            }])
            ->orderBy('name')
            ->get();
    }
}
