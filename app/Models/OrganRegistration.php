<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class OrganRegistration extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'record',
    ];

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }
}
