<?php

namespace App\Enums;

enum WorkOrderStatus: string
{
    case OPEN = 'OPEN';
    case IN_PROGRESS = 'IN_PROGRESS';
    case DONE = 'DONE';
    case CANCELED = 'CANCELED';

    public function label(): string
    {
        return match($this) {
            self::OPEN => 'Aberta',
            self::IN_PROGRESS => 'Em Andamento',
            self::DONE => 'Concluída',
            self::CANCELED => 'Cancelada',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::OPEN => 'blue',
            self::IN_PROGRESS => 'yellow',
            self::DONE => 'green',
            self::CANCELED => 'red',
        };
    }
}







