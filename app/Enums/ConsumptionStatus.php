<?php

namespace App\Enums;

enum ConsumptionStatus: string
{
    case PARTIAL = 'PARTIAL';
    case TOTAL = 'TOTAL';
    case NONE = 'NONE';
    case SPOILED = 'SPOILED';
    case REPLACED = 'REPLACED';

    public function label(): string
    {
        return match($this) {
            self::PARTIAL => 'Parcial',
            self::TOTAL => 'Total',
            self::NONE => 'Nenhum',
            self::SPOILED => 'Estragado',
            self::REPLACED => 'Substituído',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::PARTIAL => 'yellow',
            self::TOTAL => 'red',
            self::NONE => 'gray',
            self::SPOILED => 'orange',
            self::REPLACED => 'green',
        };
    }
}







