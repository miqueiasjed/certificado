<?php

namespace App\Models;

use App\Models\Concerns\Auditavel;
use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

class Budget extends Model
{
    use Auditavel, BelongsToCompany;

    /**
     * Único campo bloqueado para atribuição em massa. O model é o único de
     * domínio sem `$fillable`, então antes disto `company_id` entrava por
     * qualquer `create($request->all())`. Quem preenche a coluna é a trait
     * `BelongsToCompany`, a partir do tenant corrente, nunca o formulário.
     *
     * @var array<int, string>
     */
    protected $guarded = ['company_id'];

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
