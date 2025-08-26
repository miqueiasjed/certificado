<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Service;

class ServiceSeeder extends Seeder
{
    public function run(): void
    {
        $services = [
            [
                'name' => 'Análise Química de Produtos',
                'description' => 'Análise completa de composição química de produtos diversos.',
                'category' => 'Análise',
                'price' => 150.00,
                'is_active' => true,
                'notes' => 'Serviço padrão para certificação de produtos químicos.',
            ],
            [
                'name' => 'Certificação de Qualidade',
                'description' => 'Processo completo de certificação de qualidade e segurança.',
                'category' => 'Certificação',
                'price' => 300.00,
                'is_active' => true,
                'notes' => 'Inclui análise, documentação e emissão de certificado.',
            ],
            [
                'name' => 'Consultoria Técnica',
                'description' => 'Consultoria especializada em processos industriais e segurança.',
                'category' => 'Consultoria',
                'price' => 200.00,
                'is_active' => true,
                'notes' => 'Consultoria por hora técnica especializada.',
            ],
            [
                'name' => 'Teste de Eficácia',
                'description' => 'Testes para comprovar eficácia de produtos e processos.',
                'category' => 'Testes',
                'price' => 250.00,
                'is_active' => true,
                'notes' => 'Testes laboratoriais com relatório detalhado.',
            ],
            [
                'name' => 'Auditoria de Segurança',
                'description' => 'Auditoria completa de segurança em instalações industriais.',
                'category' => 'Auditoria',
                'price' => 500.00,
                'is_active' => false,
                'notes' => 'Serviço temporariamente suspenso para revisão.',
            ],
        ];

        foreach ($services as $service) {
            Service::create($service);
        }
    }
}
