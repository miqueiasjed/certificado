<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ServiceType extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'active',
        'sort_order',
    ];

    protected $casts = [
        'active' => 'boolean',
        'sort_order' => 'integer',
    ];

    // Relacionamentos
    public function serviceOrders(): HasMany
    {
        return $this->hasMany(WorkOrder::class, 'service_type_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    // Accessors
    public function getDisplayNameAttribute(): string
    {
        return $this->name;
    }

    // Mutators
    public function setNameAttribute($value)
    {
        $this->attributes['name'] = $value;
        $this->attributes['slug'] = Str::slug($value);
    }

    // Métodos estáticos
    public static function getActiveTypes()
    {
        return static::active()->ordered()->get();
    }

    public static function findBySlug($slug)
    {
        return static::where('slug', $slug)->first();
    }
}
