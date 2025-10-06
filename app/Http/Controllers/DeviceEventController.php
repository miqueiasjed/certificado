<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeviceEventRequest;
use App\Models\DeviceEvent;
use App\Models\Device;
use App\Models\WorkOrder;
use App\Services\DeviceEventService;
use Illuminate\Http\Request;

use Inertia\Inertia;

class DeviceEventController extends Controller
{
    protected $deviceEventService;

    public function __construct(DeviceEventService $deviceEventService)
    {
        $this->deviceEventService = $deviceEventService;
    }

    public function index(Request $request)
    {
        $query = DeviceEvent::with(['device.room.address.client', 'workOrder.technician']);

        // Filtros
        if ($request->filled('device_id')) {
            $query->where('device_id', $request->device_id);
        }

        if ($request->filled('work_order_id')) {
            $query->where('work_order_id', $request->work_order_id);
        }

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->event_type);
        }

        if ($request->filled('date_from')) {
            $query->whereDate('event_date', '>=', $request->date_from);
        }

        if ($request->filled('date_to')) {
            $query->whereDate('event_date', '<=', $request->date_to);
        }

        $deviceEvents = $query->orderBy('event_date', 'desc')->paginate(15);

        return Inertia::render('DeviceEvents/Index', [
            'deviceEvents' => $deviceEvents,
            'filters' => $request->only(['device_id', 'work_order_id', 'event_type', 'date_from', 'date_to']),
        ]);
    }

    public function create(Request $request)
    {
        $devices = Device::with('room.address.client')->orderBy('label')->get();
        $workOrders = WorkOrder::with('technician')->orderBy('order_number')->get();

        return Inertia::render('DeviceEvents/Create', [
            'devices' => $devices,
            'workOrders' => $workOrders,
            'preselectedDevice' => $request->device_id,
            'preselectedWorkOrder' => $request->work_order_id,
        ]);
    }

    public function store(DeviceEventRequest $request)
    {
        try {
            $deviceEvent = $this->deviceEventService->createDeviceEvent($request->validated());

            // Retornar resposta JSON de sucesso em vez de redirecionar
            return response()->json([
                'success' => true,
                'message' => 'Evento do dispositivo criado com sucesso!',
                'deviceEvent' => $deviceEvent
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao criar evento: ' . $e->getMessage()
            ], 500);
        }
    }

    public function show(DeviceEvent $deviceEvent)
    {
        $deviceEvent->load(['device.room.address.client', 'workOrder.technician']);

        return Inertia::render('DeviceEvents/Show', [
            'deviceEvent' => $deviceEvent,
        ]);
    }

    public function edit(DeviceEvent $deviceEvent)
    {
        $deviceEvent->load(['device.room.address.client', 'workOrder.technician']);
        $devices = Device::with('room.address.client')->orderBy('label')->get();
        $workOrders = WorkOrder::with('technician')->orderBy('order_number')->get();

        return Inertia::render('DeviceEvents/Edit', [
            'deviceEvent' => $deviceEvent,
            'devices' => $devices,
            'workOrders' => $workOrders,
        ]);
    }

        public function update(DeviceEventRequest $request, DeviceEvent $deviceEvent)
    {
        try {
            $updated = $this->deviceEventService->updateDeviceEvent($deviceEvent, $request->validated());

            if ($updated) {
                // Recarregar o modelo com relacionamentos
                $deviceEvent->refresh();
                $deviceEvent->load(['device.room.address.client', 'workOrder.technician']);

                return response()->json([
                    'success' => true,
                    'message' => 'Evento do dispositivo atualizado com sucesso!',
                    'deviceEvent' => $deviceEvent
                ]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'Erro ao atualizar evento do dispositivo'
                ], 500);
            }
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Erro ao atualizar evento: ' . $e->getMessage()
            ], 500);
        }
    }

    public function destroy(DeviceEvent $deviceEvent)
    {
        $this->deviceEventService->deleteDeviceEvent($deviceEvent);

        return redirect()->route('device-events.index')
            ->with('success', 'Evento do dispositivo excluído com sucesso!');
    }

    public function getByDevice($deviceId)
    {
        $deviceEvents = DeviceEvent::where('device_id', $deviceId)
            ->with(['workOrder.technician'])
            ->orderBy('event_date', 'desc')
            ->get();

        return response()->json($deviceEvents);
    }

    public function getByWorkOrder($workOrderId)
    {
        $deviceEvents = DeviceEvent::where('work_order_id', $workOrderId)
            ->with(['device.room.address.client'])
            ->orderBy('event_date', 'desc')
            ->get();

        return response()->json($deviceEvents);
    }
}

