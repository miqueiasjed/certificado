<?php

namespace App\Services;

use App\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\URL;
use RuntimeException;

class WorkOrderService
{
    /**
     * Validade do link assinado do recibo, em dias.
     *
     * Sete dias é o prazo escolhido porque o recibo é mandado ao cliente por
     * WhatsApp ou e-mail logo depois da baixa do pagamento, e ele costuma abrir
     * o link em outro momento: uma semana cobre o pagamento na sexta aberto na
     * segunda seguinte, e ainda cobre quem repassa o link ao contador. Acima
     * disso o documento, que traz nome, endereço e valores pagos do cliente,
     * ficaria vivo por tempo demais em uma conversa de aplicativo. Abaixo
     * disso, um link de 24 horas quebraria o caso comum sem ganho real de
     * segurança. Quando expira não há perda: qualquer usuário com
     * `ordem-servico-ver` reabre a OS e o botão emite um link novo.
     */
    public const DIAS_DE_VALIDADE_DO_LINK_DO_RECIBO = 7;
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

        $workOrder = $this->criarComNumeroDeOrdemUnico($data);

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
     * Cria a ordem de serviço tratando a corrida entre duas requisições que
     * calcularam o mesmo `order_number` ao mesmo tempo.
     *
     * `generateOrderNumber()` só lê o maior número existente; não reserva
     * nada. Então duas requisições concorrentes podem gerar o mesmo
     * candidato antes de qualquer uma delas gravar. Quem grava por último
     * esbarra na unique de `order_number` e cai aqui: gera um número novo (já
     * enxergando a linha que a outra requisição acabou de inserir) e tenta de
     * novo, até o limite de tentativas.
     *
     * Isso NÃO elimina a corrida, só o efeito dela: em vez de duas OS com o
     * mesmo número (o que a unique do banco já impediria) ou um erro cru pro
     * usuário, a segunda requisição recalcula e segue. Se o `order_number` já
     * vier em `$data` (fluxo normal, gerado em `WorkOrderRequest` ou em
     * `GeracaoDeVisitasService`), ele só é descartado se colidir de fato.
     */
    private function criarComNumeroDeOrdemUnico(array $data, int $maxTentativas = 5): WorkOrder
    {
        for ($tentativa = 1; $tentativa <= $maxTentativas; $tentativa++) {
            try {
                return WorkOrder::create($data);
            } catch (QueryException $e) {
                if (! $this->violacaoDeNumeroDeOrdemDuplicado($e) || $tentativa === $maxTentativas) {
                    throw $e;
                }

                $data['order_number'] = $this->generateOrderNumber();
            }
        }

        // Inatingível: o laço acima sempre retorna ou lança na última tentativa.
        throw new \RuntimeException('Não foi possível criar a ordem de serviço: número de ordem em conflito.');
    }

    /**
     * A exceção é uma violação da unique de `order_number` (SQLSTATE 23000,
     * "Duplicate entry"), e não outro erro de integridade (ex.: FK inválida)?
     */
    private function violacaoDeNumeroDeOrdemDuplicado(QueryException $e): bool
    {
        return $e->getCode() === '23000' && str_contains($e->getMessage(), 'order_number');
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
        $maiorNumero = $this->maiorNumeroDeOrdemExistente();
        $attempts = 0;
        $maxAttempts = 10; // Limitar tentativas para evitar loop infinito

        do {
            $nextId = $maiorNumero + 1 + $attempts;
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
     * Maior número sequencial já usado em `order_number` no formato "OS" +
     * dígitos, que é o formato que este método gera.
     *
     * Deriva do próprio `order_number`, nunca do `id` da tabela: `id` é
     * auto-increment global da tabela inteira, então usá-lo como base deixa
     * a numeração cheia de buracos sempre que alguma linha some sem virar OS
     * (e o próximo passo do projeto, a numeração por empresa do Plano 4, só
     * funciona se a base para "próximo número" for o número já emitido, não
     * um id compartilhado entre empresas).
     *
     * Ignora valores que não sejam exatamente "OS" seguido só de dígitos, para
     * não deixar um número fora do padrão (ex.: o fallback de timestamp deste
     * mesmo método) inflar a sequência.
     */
    private function maiorNumeroDeOrdemExistente(): int
    {
        $maior = WorkOrder::query()
            ->where('order_number', 'regexp', '^OS[0-9]+$')
            ->selectRaw('MAX(CAST(SUBSTRING(order_number, 3) AS UNSIGNED)) as maior')
            ->value('maior');

        return (int) ($maior ?? 0);
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
                    'eventType',
                    'photos',
                ])->orderBy('event_date', 'desc');
            },
            'pestSightings' => function ($query) {
                $query->with(['address.client'])->orderBy('sighting_date', 'desc');
            },
            'adequations' => function ($query) {
                $query->with('photos')->orderByRaw("FIELD(priority, 'alta', 'media', 'baixa')")->orderBy('status');
            },
        ]);

        $company = \App\Models\Company::current();

        // Convert adequation photos to base64
        foreach ($workOrder->adequations as $adequation) {
            foreach ($adequation->photos as $photo) {
                $photo->base64 = $this->convertStorageFileToPdfPhotoBase64($photo->path);
            }
        }

        // Convert device event photos to base64
        foreach ($workOrder->workOrderDeviceEvents as $event) {
            foreach ($event->photos as $photo) {
                $photo->base64 = $this->convertStorageFileToPdfPhotoBase64($photo->path);
            }
        }

        // Load and convert room event photos to base64
        $roomEventPhotos = \App\Models\WorkOrderPhoto::where('work_order_id', $workOrder->id)
            ->where('entity_type', 'room_event')
            ->orderBy('sort_order')
            ->get();
        foreach ($roomEventPhotos as $photo) {
            $photo->base64 = $this->convertStorageFileToPdfPhotoBase64($photo->path);
        }
        $roomEventPhotosByRoomId = $roomEventPhotos->groupBy('room_id');

        return [
            'workOrder' => $workOrder,
            'roomEventPhotosByRoomId' => $roomEventPhotosByRoomId,
            'company' => $company,
            'currentDate' => now()->format('d/m/Y'),
            'currentTime' => now()->format('H:i'),
            'logoSrc' => $this->convertStorageFileToBase64($company->logo_path),
            'chemSrc' => $this->convertStorageFileToBase64($company->signature_chemical_path),
            'opSrc' => $this->convertStorageFileToBase64($company->signature_operational_path),
        ];
    }

    /**
     * Link assinado e temporário do recibo desta ordem de serviço.
     *
     * Este é o único jeito de chegar ao recibo: a rota exige o middleware
     * `signed`, e a assinatura só é produzida aqui, dentro do sistema, por quem
     * já passou pelo middleware de permissão da tela que oferece o botão.
     *
     * A URL sai absoluta de propósito. Ela é feita para ser copiada e enviada
     * ao cliente final, e a assinatura do Laravel cobre a URL inteira,
     * inclusive o domínio, então precisa bater com o host por onde a
     * requisição chega.
     */
    public function urlAssinadaDoRecibo(WorkOrder $workOrder): string
    {
        return URL::temporarySignedRoute(
            'service-orders.receipt',
            now()->addDays(self::DIAS_DE_VALIDADE_DO_LINK_DO_RECIBO),
            ['workOrder' => $workOrder->id]
        );
    }

    /**
     * Empresa emitente do recibo: sempre a dona da ordem de serviço.
     *
     * Quem abre o link assinado não está autenticado, então `TenantAtual` não
     * tem usuário de onde tirar o tenant e `Company::current()` estouraria. O
     * tenant sai do próprio recurso, que é o único dado confiável nesse
     * contexto, e é aplicado com `TenantAtual::comTenant()` na geração.
     *
     * Cair na empresa 1 quando `company_id` está vazio é proibido: com dois
     * tenants isso emitiria um documento com o cabeçalho e o CNPJ da empresa
     * errada, e recibo tem valor perante fiscalização. Falhar é o
     * comportamento desejado.
     *
     * @throws RuntimeException Quando a ordem de serviço está sem empresa.
     */
    public function empresaEmitenteDoRecibo(WorkOrder $workOrder): int
    {
        $empresa = $workOrder->company_id ?? null;

        if (! is_numeric($empresa) || (int) $empresa < 1) {
            throw new RuntimeException(
                "A ordem de serviço {$workOrder->id} está sem empresa (company_id) e por isso "
                .'não é possível saber quem emite este recibo. Corrija o vínculo da ordem antes '
                .'de emitir o documento.'
            );
        }

        return (int) $empresa;
    }

    /**
     * Prepare receipt data for PDF generation.
     *
     * Depende do tenant corrente (`Company::current()`), então quem chama é
     * responsável por envolver a chamada em `TenantAtual::comTenant()` com a
     * empresa devolvida por `empresaEmitenteDoRecibo()`.
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

    /**
     * Convert a stored image into a consistent portrait thumbnail for PDF galleries.
     */
    private function convertStorageFileToPdfPhotoBase64(?string $path): ?string
    {
        if (!$path || !extension_loaded('gd')) {
            return $this->convertStorageFileToBase64($path);
        }

        $fullPath = storage_path('app/public/' . $path);

        if (!file_exists($fullPath)) {
            return null;
        }

        $extension = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
        $source = match ($extension) {
            'jpg', 'jpeg' => @imagecreatefromjpeg($fullPath),
            'png' => @imagecreatefrompng($fullPath),
            'webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($fullPath) : false,
            default => false,
        };

        if (!$source) {
            return $this->convertStorageFileToBase64($path);
        }

        if (in_array($extension, ['jpg', 'jpeg'], true) && function_exists('exif_read_data')) {
            $source = $this->applyImageOrientation($source, $fullPath);
        }

        $targetWidth = 360;
        $targetHeight = 480;
        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $sourceRatio = $sourceWidth / $sourceHeight;
        $targetRatio = $targetWidth / $targetHeight;

        if ($sourceRatio > $targetRatio) {
            $cropHeight = $sourceHeight;
            $cropWidth = (int) round($sourceHeight * $targetRatio);
            $cropX = (int) round(($sourceWidth - $cropWidth) / 2);
            $cropY = 0;
        } else {
            $cropWidth = $sourceWidth;
            $cropHeight = (int) round($sourceWidth / $targetRatio);
            $cropX = 0;
            $cropY = (int) round(($sourceHeight - $cropHeight) / 2);
        }

        $thumb = imagecreatetruecolor($targetWidth, $targetHeight);
        $white = imagecolorallocate($thumb, 255, 255, 255);
        imagefill($thumb, 0, 0, $white);
        imagecopyresampled(
            $thumb,
            $source,
            0,
            0,
            $cropX,
            $cropY,
            $targetWidth,
            $targetHeight,
            $cropWidth,
            $cropHeight
        );

        ob_start();
        imagejpeg($thumb, null, 82);
        $data = ob_get_clean();

        imagedestroy($source);
        imagedestroy($thumb);

        return $data ? 'data:image/jpeg;base64,' . base64_encode($data) : null;
    }

    private function applyImageOrientation(\GdImage $image, string $path): \GdImage
    {
        $exif = @exif_read_data($path);
        $orientation = $exif['Orientation'] ?? null;

        $rotated = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => $image,
        };

        return $rotated ?: $image;
    }
}
