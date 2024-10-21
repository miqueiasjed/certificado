<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganRegistration extends Model
{
    protected $fillable = [
        'record',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
