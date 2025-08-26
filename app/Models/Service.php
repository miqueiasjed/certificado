<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'category',
        'price',
        'is_active',
        'notes',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class);
    }

    public function certificates(): BelongsToMany
    {
        return $this->belongsToMany(Certificate::class, 'certificate_service');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeByPriceRange($query, $minPrice, $maxPrice)
    {
        return $query->whereBetween('price', [$minPrice, $maxPrice]);
    }

    public function getFormattedPriceAttribute(): string
    {
        if (!$this->price) {
            return 'Não informado';
        }

        return 'R$ ' . number_format($this->price, 2, ',', '.');
    }

    public function getStatusBadgeAttribute(): string
    {
        return $this->is_active ? 'Ativo' : 'Inativo';
    }

    public function getStatusColorAttribute(): string
    {
        return $this->is_active ? 'green' : 'red';
    }

    public function getCategoryBadgeAttribute(): string
    {
        return $this->category ?: 'Não informado';
    }
}
