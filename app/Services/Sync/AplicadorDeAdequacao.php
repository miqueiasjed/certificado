<?php

namespace App\Services\Sync;

use App\Models\User;
use App\Services\Sync\Concerns\OperacaoDeCampo;
use App\Services\WorkOrderAdequationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Aplica uma operação de sincronização do tipo `adequacao` (Plano 13, Task
 * 13.2): a adequação (não conformidade) que o técnico registra em campo, com
 * descrição, prazo sugerido e prioridade.
 *
 * A gravação é delegada a `WorkOrderAdequationService::create()` (regra atual
 * de adequação, inclusive a recusa em OS cancelada, que continua valendo sem
 * repetição aqui). A foto da adequação chega em operação própria do tipo
 * `foto` (Plano 12) e é associada depois pelo `uuid` desta adequação (a
 * adequação ainda não tem `id` no servidor quando o técnico tira a foto,
 * offline), resolvido para o `id` real por `AplicadorDeFoto`; este aplicador
 * só grava a adequação em si, sem tocar em foto nenhuma.
 *
 * `status` nasce sempre `pendente`: uma adequação recém-registrada em campo
 * pelo técnico ainda não foi resolvida, e o fechamento dela é ação própria do
 * escritório, feita depois, pelo painel.
 */
class AplicadorDeAdequacao implements AplicadorDeOperacao
{
    use OperacaoDeCampo;

    private const STATUS_INICIAL = 'pendente';

    public function __construct(
        private readonly WorkOrderAdequationService $workOrderAdequationService,
    ) {}

    public function tipo(): string
    {
        return 'adequacao';
    }

    public function aplicar(array $payload, User $usuario): Model
    {
        $workOrder = $this->workOrderDestravada($payload);

        $tipo = trim((string) ($payload['type'] ?? ''));
        $descricao = trim((string) ($payload['description'] ?? ''));
        $prioridade = trim((string) ($payload['priority'] ?? ''));

        if ($tipo === '' || $descricao === '' || $prioridade === '') {
            throw ValidationException::withMessages([
                'adequation' => 'Tipo, descrição e prioridade são obrigatórios para registrar a adequação.',
            ]);
        }

        $instante = $this->instanteDoCelular($payload);

        $dados = [
            'type' => $tipo,
            'description' => $descricao,
            'priority' => $prioridade,
            'status' => self::STATUS_INICIAL,
            'deadline' => $payload['deadline'] ?? null,
            'registrada_em' => $instante,
            // Uuid gerado no aparelho: a foto desta adequação (operação
            // própria do tipo `foto`) chega referenciando este mesmo valor em
            // `entity_id`, porque no instante da captura, offline, a adequação
            // ainda não tem id no servidor. Ver AplicadorDeFoto.
            'uuid' => $payload['uuid'] ?? null,
        ];

        return $this->workOrderAdequationService->create($workOrder, $dados);
    }
}
