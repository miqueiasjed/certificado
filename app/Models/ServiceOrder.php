<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;


class ServiceOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_number',
        'order_date',
        'start_time',
        'end_time',
        'service_type',
        'client_id',
        'technician_id',
        'description',
        'observations',
        'special_instructions',
        'status',
    ];

    protected $casts = [
        'order_date' => 'date',
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];



    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(Room::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    public function technician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'technician_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(ServiceOrderProduct::class);
    }

    public function getStatusLabelAttribute(): string
    {
        return match($this->status) {
            'pending' => 'Pendente',
            'in_progress' => 'Em Andamento',
            'completed' => 'Concluída',
            'cancelled' => 'Cancelada',
            default => 'Desconhecido',
        };
    }

    public function getFormattedOrderDateAttribute(): string
    {
        return $this->order_date->format('d/m/Y');
    }

    public function getFormattedStartTimeAttribute(): string
    {
        return $this->start_time->format('H:i');
    }

    public function getFormattedEndTimeAttribute(): string
    {
        return $this->end_time->format('H:i');
    }

    public function getServiceTypeLabelAttribute(): string
    {
        return match($this->service_type) {
            'dedetizacao' => 'Dedetização',
            'desinsetizacao' => 'Desinsetização',
            'descupinizacao' => 'Descupinização',
            'desratizacao' => 'Desratização',
            'sanitizacao' => 'Sanitização',
            default => 'Desconhecido',
        };
    }

    public static function generateOrderNumber(): string
    {
        $lastOrder = self::orderBy('id', 'desc')->first();
        $nextNumber = $lastOrder ? (int)$lastOrder->order_number + 1 : 1;
        return str_pad($nextNumber, 6, '0', STR_PAD_LEFT);
    }
}
