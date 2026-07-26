<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ScheduledTaskRun extends Model
{
    protected $fillable = [
        'task',
        'started_at',
        'finished_at',
        'status',
        'duration_ms',
        'output',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Scope para filtrar pela assinatura da tarefa
     */
    public function scopeDaTarefa($query, string $task)
    {
        return $query->where('task', $task);
    }

    /**
     * Scope para as últimas execuções, mais recentes primeiro
     */
    public function scopeUltimas($query, int $limite = 10)
    {
        return $query->orderByDesc('started_at')->limit($limite);
    }
}
