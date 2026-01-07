<?php

namespace App\Http\Controllers;

use App\Http\Requests\RoomRequest;
use App\Models\Room;
use App\Services\RoomService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class RoomController extends Controller
{
    protected $roomService;

    public function __construct(RoomService $roomService)
    {
        $this->roomService = $roomService;
    }

    public function index(Request $request)
    {
        $search = $request->get('search', '');

        if ($search) {
            $rooms = $this->roomService->searchRooms($search);
        } else {
            $rooms = $this->roomService->getRoomsPaginated();
        }

        return Inertia::render('Rooms/Index', [
            'rooms' => $rooms,
            'search' => $search,
        ]);
    }

    public function create(Request $request)
    {
        $addressId = $request->get('address_id');

        // Se não foi passado address_id, buscar todos os endereços
        if (!$addressId) {
            $addresses = \App\Models\Address::with('client')->orderBy('nickname')->get();
        } else {
            // Se foi passado address_id, buscar apenas endereços do mesmo cliente
            $address = \App\Models\Address::with('client')->find($addressId);
            if ($address) {
                $addresses = \App\Models\Address::with('client')
                    ->where('client_id', $address->client_id)
                    ->orderBy('nickname')
                    ->get();
            } else {
                $addresses = collect();
            }
        }

        return Inertia::render('Rooms/Create', [
            'addresses' => $addresses,
            'selectedAddressId' => $addressId,
        ]);
    }

    public function store(RoomRequest $request)
    {
        $room = $this->roomService->createRoom($request->validated());

        // Retornar mensagem de sucesso sem redirecionar
        return back()->with('success', 'Cômodo criado com sucesso!');
    }

    public function show(Room $room)
    {
        $room->load(['address.client']);

        return Inertia::render('Rooms/Show', [
            'room' => $room,
        ]);
    }

    public function edit(Room $room)
    {
        // Carregar o cômodo com o relacionamento address
        $room->load('address.client');

        $addresses = \App\Models\Address::with('client')->orderBy('nickname')->get();

        return Inertia::render('Rooms/Edit', [
            'room' => $room,
            'addresses' => $addresses,
        ]);
    }

    public function update(RoomRequest $request, Room $room)
    {
        $this->roomService->updateRoom($room, $request->validated());

        return redirect()->route('rooms.show', $room)
            ->with('success', 'Cômodo atualizado com sucesso!');
    }

    public function destroy(Room $room)
    {
        $this->roomService->deleteRoom($room);

        return redirect()->route('rooms.index')
            ->with('success', 'Cômodo excluído com sucesso!');
    }

    public function getByAddress(Request $request, $addressId)
    {
        $rooms = $this->roomService->getRoomsByAddress($addressId);

        return response()->json($rooms);
    }
}
