<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Technician;

class TechnicianSeeder extends Seeder
{
    public function run(): void
    {
        $technicians = [
            [
                'name' => 'Dr. João Silva',
                'email' => 'joao.silva@empresa.com',
                'phone' => '(11) 99999-1111',
                'specialty' => 'Química',
                'registration_number' => 'CRQ-12345',
                'is_active' => true,
                'notes' => 'Especialista em análise química e certificação de produtos.',
            ],
            [
                'name' => 'Dra. Maria Santos',
                'email' => 'maria.santos@empresa.com',
                'phone' => '(11) 99999-2222',
                'specialty' => 'Biologia',
                'registration_number' => 'CRBio-67890',
                'is_active' => true,
                'notes' => 'Especialista em microbiologia e controle de qualidade.',
            ],
            [
                'name' => 'Eng. Carlos Oliveira',
                'email' => 'carlos.oliveira@empresa.com',
                'phone' => '(11) 99999-3333',
                'specialty' => 'Engenharia Química',
                'registration_number' => 'CREA-11111',
                'is_active' => true,
                'notes' => 'Especialista em processos industriais e segurança.',
            ],
            [
                'name' => 'Dr. Ana Costa',
                'email' => 'ana.costa@empresa.com',
                'phone' => '(11) 99999-4444',
                'specialty' => 'Farmácia',
                'registration_number' => 'CRF-22222',
                'is_active' => false,
                'notes' => 'Técnico inativo - licença médica.',
            ],
        ];

        foreach ($technicians as $technician) {
            Technician::create($technician);
        }
    }
}
