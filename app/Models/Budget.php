<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    protected $guarded = [];

    protected $casts = [
        'target_pests' => 'array',
        'areas_to_treat' => 'array',
        'date' => 'date',
        'validity_date' => 'date',
        'discount' => 'decimal:2',
    ];

    public function client()
    {
        return $this->belongsTo(Client::class);
    }

    public function address()
    {
        return $this->belongsTo(Address::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function services()
    {
        return $this->belongsToMany(Service::class, 'budget_services')
            ->withPivot(['quantity', 'unit_price', 'subtotal'])
            ->withTimestamps();
    }

    public function products()
    {
        return $this->belongsToMany(Product::class, 'budget_products')
            ->withPivot(['quantity'])
            ->withTimestamps();
    }
}
