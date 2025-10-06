<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\BaitType;

class BaitTypeSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $baitTypes = [
            [
                'name' => 'Isca para Ratos',
                'brand' => 'Raticida',
                'notes' => 'Isca específica para controle de roedores'
            ],
            [
                'name' => 'Isca para Baratas',
                'brand' => 'Baraticida',
                'notes' => 'Isca para controle de baratas e insetos rastejantes'
            ],
            [
                'name' => 'Isca para Formigas',
                'brand' => 'Formicida',
                'notes' => 'Isca para controle de formigas'
            ],
            [
                'name' => 'Isca para Cupins',
                'brand' => 'Cupinicida',
                'notes' => 'Isca para controle de cupins'
            ],
            [
                'name' => 'Isca para Moscas',
                'brand' => 'Muscacida',
                'notes' => 'Isca para controle de moscas'
            ],
            [
                'name' => 'Isca para Pulgas',
                'brand' => 'Pulicida',
                'notes' => 'Isca para controle de pulgas'
            ],
            [
                'name' => 'Isca para Carrapatos',
                'brand' => 'Carrapaticida',
                'notes' => 'Isca para controle de carrapatos'
            ],
            [
                'name' => 'Isca para Escorpiões',
                'brand' => 'Escorpionicida',
                'notes' => 'Isca para controle de escorpiões'
            ],
            [
                'name' => 'Isca para Aranhas',
                'brand' => 'Aranhicida',
                'notes' => 'Isca para controle de aranhas'
            ],
            [
                'name' => 'Isca para Abelhas',
                'brand' => 'Apicida',
                'notes' => 'Isca para controle de abelhas e vespas'
            ]
        ];

        foreach ($baitTypes as $baitType) {
            BaitType::create($baitType);
        }
    }
}
