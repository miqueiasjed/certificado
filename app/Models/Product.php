<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Product extends Model
{
    protected $fillable = [
        'name',
        'active_ingredient_id',
        'chemical_group_id',
        'antidote_id',
        'organ_registration_id'
    ];

    public function activeIngredient(): BelongsTo
    {
        return $this->BelongsTo(ActiveIngredient::class);
    }

    public function chemicalGroup(): BelongsTo
    {
        return $this->BelongsTo(ChemicalGroup::class);
    }

    public function antidote(): BelongsTo
    {
        return $this->BelongsTo(Antidote::class);
    }

    public function organRegistration(): BelongsTo
    {
        return $this->BelongsTo(OrganRegistration::class);
    }

    public function certificates(): BelongsToMany
    {
        return $this->belongsToMany(Certificate::class, 'certificate_product');
    }

}
