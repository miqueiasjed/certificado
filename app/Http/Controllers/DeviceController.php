<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeviceRequest;
use App\Http\Requests\StoreDeviceBatchRequest;
use App\Models\Address;
use App\Models\Device;
use App\Services\DeviceBatchService;
use App\Services\DeviceReplacementService;
use App\Services\DeviceService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class DeviceController extends Controller
{
    protected $deviceService;

    protected DeviceBatchService $deviceBatchService;

    protected DeviceReplacementService $deviceReplacementService;

    public function __construct(
        DeviceService $deviceService,
        DeviceBatchService $deviceBatchService,
        DeviceReplacementService $deviceReplacementService
    ) {
        $this->deviceService = $deviceService;
        $this->deviceBatchService = $deviceBatchService;
        $this->deviceReplacementService = $deviceReplacementService;
    }

    public function index(Request $request)
    {
        $query = Device::with(['address.client', 'baitType']);

        // Aplicar filtros
        if ($request->filled('client_id')) {
            $query->whereHas('address', function ($q) use ($request) {
                $q->where('client_id', $request->client_id);
            });
        }

        if ($request->filled('address_id')) {
            $query->where('address_id', $request->address_id);
        }

        if ($request->filled('bait_type_id')) {
            $query->where('bait_type_id', $request->bait_type_id);
        }

        $devices = $query->paginate(15);

        // Carregar dados para filtros
        $clients = \App\Models\Client::orderBy('name')->limit(500)->get();
        $addresses = \App\Models\Address::with('client')->orderBy('nickname')->limit(500)->get();
        $baitTypes = \App\Models\BaitType::orderBy('name')->limit(200)->get();

        return Inertia::render('Devices/Index', [
            'devices' => $devices,
            'filters' => $request->only(['client_id', 'address_id', 'bait_type_id']),
            'clients' => $clients,
            'addresses' => $addresses,
            'baitTypes' => $baitTypes,
        ]);
    }

    public function create()
    {
        $addresses = \App\Models\Address::with('client')->orderBy('nickname')->limit(500)->get();
        $baitTypes = \App\Models\BaitType::orderBy('name')->limit(200)->get();

        return Inertia::render('Devices/Create', [
            'addresses' => $addresses,
            'baitTypes' => $baitTypes,
        ]);
    }

    public function store(DeviceRequest $request)
    {
        $device = $this->deviceService->createDevice($request->validated());

        // Retornar mensagem de sucesso sem redirecionar
        return back()->with('success', 'Dispositivo criado com sucesso!');
    }

    public function show(Device $device)
    {
        $device->load(['address.client', 'baitType']);

        // Histórico do ponto de monitoramento: os dispositivos que já
        // ocuparam este lugar, do mais antigo ao atual, a partir de qualquer
        // elo da cadeia. Dispositivo nunca substituído devolve uma linha do
        // tempo com ele mesmo como único item, e a tela trata isso como
        // "nenhuma substituição registrada".
        $historico = $this->deviceReplacementService->historicoParaTela(
            $this->deviceReplacementService->historicoDoPonto($device)
        );

        return Inertia::render('Devices/Show', [
            'device' => array_merge($device->toArray(), ['historico' => $historico]),
        ]);
    }

    public function edit(Device $device, Request $request)
    {
        // Carregar o dispositivo com todos os relacionamentos necessários
        $device->load(['address.client', 'baitType']);

        // Carregar endereços para o formulário de edição
        $addresses = \App\Models\Address::with('client')->orderBy('nickname')->limit(500)->get();

        // Carregar tipos de isca para o formulário de edição
        $baitTypes = \App\Models\BaitType::orderBy('name')->limit(200)->get();

        return Inertia::render('Devices/Edit', [
            'device' => $device,
            'addresses' => $addresses,
            'baitTypes' => $baitTypes,
            'returnUrl' => $request->get('return_url'), // URL de retorno
        ]);
    }

    public function update(DeviceRequest $request, Device $device)
    {
        $this->deviceService->updateDevice($device, $request->validated());

        // Verificar se há uma URL de retorno específica
        $returnUrl = $request->get('return_url');
        if ($returnUrl) {
            return redirect($returnUrl)->with('success', 'Dispositivo atualizado com sucesso!');
        }

        return back()->with('success', 'Dispositivo atualizado com sucesso!');
    }

    public function destroy(Device $device)
    {
        try {
            $this->deviceService->deleteDevice($device);

            return back()->with('success', 'Dispositivo excluído com sucesso!');
        } catch (\Exception $e) {
            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Check if device can be deleted.
     */
    public function canDelete(Device $device)
    {
        $canDelete = $this->deviceService->canDeleteDevice($device);

        return response()->json([
            'can_delete' => $canDelete,
            'message' => $canDelete
                ? 'Dispositivo pode ser excluído'
                : 'Dispositivo não pode ser excluído pois está vinculado a ordens de serviço ou eventos',
        ]);
    }

    /**
     * Cria de uma vez a faixa numerada de dispositivos de um endereço.
     *
     * Toda a regra vive em `DeviceBatchService`: aqui só orquestra e devolve a
     * resposta. O `Address` chega por Model Binding, e o escopo global por
     * empresa já responde 404 para um endereço de outro tenant antes de qualquer
     * linha daqui rodar.
     *
     * A resposta é JSON, e não redirect Inertia, por dois motivos: a recusa por
     * número repetido precisa chegar à tela com 422 e a lista dos números em
     * conflito, e o sucesso devolve os dispositivos criados para a tela oferecer
     * a impressão da folha de etiquetas em seguida.
     */
    public function criarLote(StoreDeviceBatchRequest $request, Address $address)
    {
        $resultado = $this->deviceBatchService->criarLote($address, $request->validated());

        if (! $resultado['success']) {
            return response()->json([
                'success' => false,
                'message' => $resultado['message'],
                'numeros_em_conflito' => $resultado['data']['numeros_em_conflito'],
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $resultado['message'],
            // `load` na coleção, e não em cada dispositivo: o tipo de isca é o
            // mesmo para o lote inteiro, e carregar um a um seriam 200 consultas
            // para trazer sempre a mesma linha.
            'dispositivos' => $resultado['data']['dispositivos']->load('baitType')->values(),
        ]);
    }

    public function getByAddress($addressId)
    {
        $devices = Device::where('address_id', $addressId)
            ->with(['address.client', 'baitType'])
            ->get();

        return response()->json($devices);
    }
}
