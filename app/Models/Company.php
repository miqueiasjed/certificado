<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Company extends Model
{
    protected $fillable = [
        'name',
        'cnpj',
        'email',
        'phone',
        'street',
        'number',
        'complement',
        'district',
        'city',
        'state',
        'zip',
        'crq',
        'license_environmental',
        'license_sanitary',
        'license_business',
        'register_visa',
        'register_crea',
        'ceatox_info',
        'operational_manager_name',
        'technical_responsible_name',
        'logo_path',
        'signature_operational_path',
        'signature_chemical_path',
        'signature_responsible_path',
    ];

    /**
     * Get the current company settings (singleton pattern equivalent)
     */
    public static function current()
    {
        return self::firstOrCreate(['id' => 1]);
    }

    /**
     * Get full formatted address
     */
    public function getFullAddressAttribute()
    {
        $parts = [];
        if ($this->street)
            $parts[] = $this->street;
        if ($this->number)
            $parts[] = $this->number;
        if ($this->district)
            $parts[] = $this->district;
        if ($this->city)
            $parts[] = "{$this->city}/{$this->state}";
        if ($this->zip)
            $parts[] = "CEP: {$this->zip}";

        return implode(', ', $parts);
    }
}
