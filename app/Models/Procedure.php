<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Procedure extends Model
{
    use BelongsToCompany;

    protected $fillable = [
        'name',
    ];

    public function certificates(): BelongsToMany
    {
        return $this->belongsToMany(Certificate::class, 'certificate_procedure');
    }
}
