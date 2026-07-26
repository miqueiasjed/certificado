<?php

namespace App\Services;

use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use RuntimeException;

class ServiceOrderService
{
    public function getAllServiceOrders(): Collection
    {
        return ServiceOrder::with(['client', 'technician'])->orderBy('created_at', 'desc')->get();
    }

    public function getServiceOrdersPaginated(int $perPage = 15): LengthAwarePaginator
    {
        return ServiceOrder::with(['client', 'technician'])
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);
    }

    public function findServiceOrder(int $id): ?ServiceOrder
    {
        return ServiceOrder::with(['client', 'technician'])->find($id);
    }

    public function createServiceOrder(array $data): ServiceOrder
    {
        $rooms = $data['rooms'] ?? [];
        unset($data['rooms']);

        if (empty($data['order_number'])) {
            $data['order_number'] = $this->gerarNumeroDaOrdem();
        }

        $serviceOrder = $this->criarComNumeroDaOrdemUnico($data);

        if (!empty($rooms)) {
            $this->syncRooms($serviceOrder, $rooms);
        }

        return $serviceOrder;
    }

    /**
     * Gera o próximo número de ordem de serviço, no formato "SO" seguido de
     * 6 dígitos (ex.: SO000001) - o mesmo formato usado até hoje.
     *
     * A cada tentativa o candidato avança em relação à anterior
     * ($proximoId + $tentativa), diferente da versão anterior deste método,
     * que recalculava `$proximoId` do zero a cada volta do laço e por isso
     * nunca avançava de fato: se o número gerado já existisse, o laço girava
     * para sempre e a requisição travava até o timeout do PHP.
     *
     * Depois de `$maxTentativas` tentativas sem achar um número livre, lança
     * exceção em vez de continuar girando.
     *
     * @throws RuntimeException quando esgota as tentativas
     */
    public function gerarNumeroDaOrdem(int $maxTentativas = 20): string
    {
        $proximoId = $this->maiorNumeroDaOrdemExistente() + 1;

        for ($tentativa = 0; $tentativa < $maxTentativas; $tentativa++) {
            $numeroCandidato = $proximoId + $tentativa;
            $numeroDaOrdem = 'SO' . str_pad($numeroCandidato, 6, '0', STR_PAD_LEFT);

            if (! ServiceOrder::where('order_number', $numeroDaOrdem)->exists()) {
                return $numeroDaOrdem;
            }
        }

        throw new RuntimeException(
            "Não foi possível gerar um número de ordem de serviço único após {$maxTentativas} tentativas "
            . 'a partir de SO' . str_pad($proximoId, 6, '0', STR_PAD_LEFT) . '.'
        );
    }

    /**
     * Maior número sequencial já usado em `order_number` no formato "SO" +
     * dígitos, que é o formato que este método gera.
     *
     * Deriva do próprio `order_number`, nunca do `id` da tabela: `id` é
     * auto-increment global (o contador do InnoDB nem volta com ROLLBACK,
     * então ele corre à frente do número real de linhas), e
     * `service_orders.order_number` já é unique composta com `company_id`
     * (Plano 4). Se a base para "próximo número" continuasse sendo o `id`,
     * a numeração da segunda empresa saltaria conforme o volume da
     * primeira, porque as duas dividem o mesmo contador de `id` da tabela.
     *
     * Ignora valores que não sejam exatamente "SO" seguido só de dígitos,
     * para não deixar um número fora do padrão inflar a sequência.
     */
    private function maiorNumeroDaOrdemExistente(): int
    {
        $maior = ServiceOrder::query()
            ->where('order_number', 'regexp', '^SO[0-9]+$')
            ->selectRaw('MAX(CAST(SUBSTRING(order_number, 3) AS UNSIGNED)) as maior')
            ->value('maior');

        return (int) ($maior ?? 0);
    }

    /**
     * Cria a ordem de serviço tratando a corrida entre duas requisições que
     * calcularam o mesmo `order_number` ao mesmo tempo.
     *
     * `gerarNumeroDaOrdem()` só lê o maior número existente; não reserva
     * nada. Então duas requisições concorrentes podem gerar o mesmo
     * candidato antes de qualquer uma delas gravar. Quem grava por último
     * esbarra na unique de `order_number` e cai aqui: gera um número novo (já
     * enxergando a linha que a outra requisição acabou de inserir) e tenta de
     * novo, até o limite de tentativas.
     *
     * Isso NÃO elimina a corrida, só o efeito dela: em vez de duas OS com o
     * mesmo número (o que a unique do banco já impediria) ou um erro cru pro
     * usuário, a segunda requisição recalcula e segue.
     */
    private function criarComNumeroDaOrdemUnico(array $data, int $maxTentativas = 5): ServiceOrder
    {
        for ($tentativa = 1; $tentativa <= $maxTentativas; $tentativa++) {
            try {
                return ServiceOrder::create($data);
            } catch (QueryException $e) {
                if (! $this->violacaoDeNumeroDaOrdemDuplicado($e) || $tentativa === $maxTentativas) {
                    throw $e;
                }

                $data['order_number'] = $this->gerarNumeroDaOrdem();
            }
        }

        // Inatingível: o laço acima sempre retorna ou lança na última tentativa.
        throw new RuntimeException('Não foi possível criar a ordem de serviço: número de ordem em conflito.');
    }

    /**
     * A exceção é uma violação da unique de `order_number` (SQLSTATE 23000,
     * "Duplicate entry"), e não outro erro de integridade (ex.: FK inválida)?
     */
    private function violacaoDeNumeroDaOrdemDuplicado(QueryException $e): bool
    {
        return $e->getCode() === '23000' && str_contains($e->getMessage(), 'order_number');
    }

    public function updateServiceOrder(ServiceOrder $serviceOrder, array $data): bool
    {
        $rooms = $data['rooms'] ?? [];
        unset($data['rooms']);

        $updated = $serviceOrder->update($data);

        if (isset($rooms)) {
            $this->syncRooms($serviceOrder, $rooms);
        }

        return $updated;
    }

    /**
     * Sincroniza os cômodos atendidos na ordem de serviço
     *
     * @param ServiceOrder $serviceOrder
     * @param array $rooms Array de cômodos com formato: [['id' => 1, 'observation' => 'obs'], ...]
     * @return void
     */
    protected function syncRooms(ServiceOrder $serviceOrder, array $rooms): void
    {
        $syncData = [];

        foreach ($rooms as $room) {
            if (isset($room['id'])) {
                $syncData[$room['id']] = [
                    'observation' => $room['observation'] ?? null
                ];
            }
        }

        $serviceOrder->rooms()->sync($syncData);
    }

    public function deleteServiceOrder(ServiceOrder $serviceOrder): bool
    {
        return $serviceOrder->delete();
    }

    public function searchServiceOrders(string $search): LengthAwarePaginator
    {
        return ServiceOrder::with(['client', 'technician'])
            ->where(function($query) use ($search) {
                $query->where('id', 'like', "%{$search}%")
                      ->orWhere('order_number', 'like', "%{$search}%")
                      ->orWhere('status', 'like', "%{$search}%")
                      ->orWhere('service_type', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%")
                      ->orWhere('observations', 'like', "%{$search}%")
                      ->orWhereHas('client', function($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%")
                            ->orWhere('cnpj', 'like', "%{$search}%");
                      })
                      ->orWhereHas('technician', function($q) use ($search) {
                          $q->where('name', 'like', "%{$search}%");
                      });
            })
            ->orderBy('created_at', 'desc')
            ->paginate(15);
    }

    public function getServiceOrdersByStatus(string $status): Collection
    {
        return ServiceOrder::with(['client', 'technician'])
            ->where('status', $status)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getServiceOrdersByServiceType(string $serviceType): Collection
    {
        return ServiceOrder::with(['client', 'technician'])
            ->where('service_type', $serviceType)
            ->orderBy('created_at', 'desc')
            ->get();
    }

    public function getPendingServiceOrders(): Collection
    {
        return $this->getServiceOrdersByStatus('pending');
    }

    public function getInProgressServiceOrders(): Collection
    {
        return $this->getServiceOrdersByStatus('in_progress');
    }

    public function getCompletedServiceOrders(): Collection
    {
        return $this->getServiceOrdersByStatus('completed');
    }
}
