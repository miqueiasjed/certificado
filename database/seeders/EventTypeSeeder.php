<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\EventType;

class EventTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $eventTypes = [
            [
                'name' => 'Inspeção',
                'description' => 'Inspeção de dispositivos e áreas',
                'color' => '#3b82f6',
                'is_active' => true,
            ],
            [
                'name' => 'Limpeza',
                'description' => 'Limpeza e higienização',
                'color' => '#10b981',
                'is_active' => true,
            ],
            [
                'name' => 'Manutenção',
                'description' => 'Manutenção preventiva ou corretiva',
                'color' => '#f59e0b',
                'is_active' => true,
            ],
            [
                'name' => 'Reparo',
                'description' => 'Reparo de equipamentos ou dispositivos',
                'color' => '#ef4444',
                'is_active' => true,
            ],
            [
                'name' => 'Instalação',
                'description' => 'Instalação de novos equipamentos',
                'color' => '#8b5cf6',
                'is_active' => true,
            ],
            [
                'name' => 'Substituição',
                'description' => 'Substituição de equipamentos ou componentes',
                'color' => '#ec4899',
                'is_active' => true,
            ],
        ];

        foreach ($eventTypes as $eventType) {
            EventType::create($eventType);
        }
    }
}
