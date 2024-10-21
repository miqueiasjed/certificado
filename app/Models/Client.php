<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Client extends Model
{
    protected $fillable = [
        'email',
        'name',
        'phone',
        'cnpj',
        'date_of_birth',
        'address',
        'neighborhood',
        'city',
        'number',
    ];
    public function certificates(): HasMany
    {
        return $this->hasMany(Certificate::class);
    }
}
