<?php

namespace App\Services;

use App\Models\User;
use App\Models\WorkOrder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;

/**
 * Regras de acesso do usuário às ordens de serviço.
 *
 * Papel `administrador` e qualquer usuário sem o papel `tecnico` passam sem
 * filtro. Usuário com papel `tecnico` só alcança as ordens em que está
 * atribuído, seja pela coluna `work_orders.technician_id`, seja pela pivot
 * `work_order_technicians`.
 *
 * O escopo por empresa não separa um técnico do outro, então esta verificação
 * é obrigatória e continua valendo depois do multiempresa.
 */
class WorkOrderAccessService
{
    /**
     * Restringe a consulta às ordens de serviço que o usuário pode enxergar.
     */
    public function aplicarEscopoDoUsuario(Builder $query, User $usuario): Builder
    {
        if (! $this->precisaDeFiltro($usuario)) {
            return $query;
        }

        $tecnicoId = $this->tecnicoIdDoUsuario($usuario);

        // Usuário com papel `tecnico` e sem cadastro de técnico vinculado
        // (technicians.user_id) enxerga zero ordens de serviço. Nunca todas.
        if ($tecnicoId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $consulta) use ($tecnicoId) {
            $consulta
                ->where('work_orders.technician_id', $tecnicoId)
                ->orWhereHas('technicians', function (Builder $pivot) use ($tecnicoId) {
                    $pivot->where('technicians.id', $tecnicoId);
                });
        });
    }

    /**
     * Bloqueia o acesso do técnico a ordem de serviço em que ele não está atribuído.
     *
     * Responde 403, e não 404: dentro da mesma empresa a existência da ordem
     * não é segredo.
     *
     * @throws AuthorizationException
     */
    public function garantirAcesso(WorkOrder $ordem, User $usuario): void
    {
        if (! $this->precisaDeFiltro($usuario)) {
            return;
        }

        $tecnicoId = $this->tecnicoIdDoUsuario($usuario);

        if ($tecnicoId === null || ! $this->estaAtribuido($ordem, $tecnicoId)) {
            throw new AuthorizationException(
                'Você não tem permissão para acessar esta ordem de serviço.'
            );
        }
    }

    /**
     * Só filtra quem tem o papel `tecnico` e não tem o papel `administrador`.
     */
    private function precisaDeFiltro(User $usuario): bool
    {
        if ($usuario->hasRole('administrador')) {
            return false;
        }

        return $usuario->hasRole('tecnico');
    }

    /**
     * Id do técnico vinculado ao usuário, ou null quando não existe vínculo.
     */
    private function tecnicoIdDoUsuario(User $usuario): ?int
    {
        $tecnico = $usuario->technician;

        return $tecnico ? (int) $tecnico->id : null;
    }

    /**
     * O técnico está atribuído à ordem pela coluna direta ou pela pivot.
     */
    private function estaAtribuido(WorkOrder $ordem, int $tecnicoId): bool
    {
        if ($ordem->technician_id !== null && (int) $ordem->technician_id === $tecnicoId) {
            return true;
        }

        if ($ordem->relationLoaded('technicians')) {
            return $ordem->technicians->contains('id', $tecnicoId);
        }

        return $ordem->technicians()->where('technicians.id', $tecnicoId)->exists();
    }
}
