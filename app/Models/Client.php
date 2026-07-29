<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    use Auditavel, BelongsToCompany;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'cnpj',
        'notes',
        // Preferência de contato da central de notificações (Plano 14).
        'aceita_email',
        'aceita_whatsapp',
        'canal_preferido',
        'email_notificacao',
    ];

    protected $casts = [
        // Nascem `true`: cliente sem preferência declarada recebe. A recusa é
        // registrada quando ele pedir.
        'aceita_email' => 'boolean',
        'aceita_whatsapp' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function workOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class);
    }

    /**
     * Títulos a receber deste cliente (Plano 18). O lado inverso de
     * `Receivable::client()`, usado pela tela de contas a receber para montar
     * o seletor só com quem tem cobrança.
     */
    public function receivables(): HasMany
    {
        return $this->hasMany(Receivable::class);
    }
}
