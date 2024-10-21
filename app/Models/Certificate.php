<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Certificate extends Model
{
    protected $casts = [
        'date' => 'date',
        'assurance' => 'date',
    ];

    protected $fillable = [
        'date',
        'assurance',
        'client_id',
        'service_id',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'certificate_product');
    }

    public function procedures(): BelongsToMany
    {
        return $this->BelongsToMany(Procedure::class, 'certificate_procedure');
    }
}
