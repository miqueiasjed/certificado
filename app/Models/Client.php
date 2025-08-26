<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'cnpj',
        'city',
        'state',
        'zip_code',
        'address',
        'notes',
    ];

    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }
}
