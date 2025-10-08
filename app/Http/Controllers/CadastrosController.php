<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;

class CadastrosController extends Controller
{
    public function index()
    {
        $cadastros = [
            [
                'id' => 'products',
                'name' => 'Produtos',
                'description' => 'Gerenciar produtos e materiais',
                'route' => '/products',
                'icon' => 'package',
                'color' => 'blue',
                'count' => \App\Models\Product::count(),
            ],
            [
                'id' => 'technicians',
                'name' => 'Técnicos',
                'description' => 'Gerenciar técnicos e especialistas',
                'route' => '/technicians',
                'icon' => 'user-group',
                'color' => 'green',
                'count' => \App\Models\User::where('is_technician', true)->count(),
            ],
            [
                'id' => 'services',
                'name' => 'Serviços',
                'description' => 'Gerenciar serviços disponíveis',
                'route' => '/services',
                'icon' => 'cog',
                'color' => 'purple',
                'count' => \App\Models\Service::count(),
            ],
            [
                'id' => 'service-types',
                'name' => 'Tipos de Serviço',
                'description' => 'Gerenciar tipos de ordem de serviço',
                'route' => '/service-types',
                'icon' => 'cog',
                'color' => 'purple',
                'count' => \App\Models\ServiceType::count(),
            ],
            [
                'id' => 'active-ingredients',
                'name' => 'Princípio Ativo',
                'description' => 'Gerenciar princípios ativos',
                'route' => '/active-ingredients',
                'icon' => 'beaker',
                'color' => 'orange',
                'count' => \App\Models\ActiveIngredient::count(),
            ],
            [
                'id' => 'chemical-groups',
                'name' => 'Grupo Químico',
                'description' => 'Gerenciar grupos químicos',
                'route' => '/chemical-groups',
                'icon' => 'flask',
                'color' => 'red',
                'count' => \App\Models\ChemicalGroup::count(),
            ],
            [
                'id' => 'antidotes',
                'name' => 'Antídoto',
                'description' => 'Gerenciar antídotos',
                'route' => '/antidotes',
                'icon' => 'shield-check',
                'color' => 'yellow',
                'count' => \App\Models\Antidote::count(),
            ],
            [
                'id' => 'devices',
                'name' => 'Dispositivos',
                'description' => 'Gerenciar dispositivos e equipamentos',
                'route' => '/devices',
                'icon' => 'device-mobile',
                'color' => 'cyan',
                'count' => \App\Models\Device::count(),
            ],
            [
                'id' => 'organ-registrations',
                'name' => 'Reg. Min da Saúde',
                'description' => 'Gerenciar registros do Ministério da Saúde',
                'route' => '/organ-registrations',
                'icon' => 'document-text',
                'color' => 'indigo',
                'count' => \App\Models\OrganRegistration::count(),
            ],
        ];

        return Inertia::render('Cadastros/Index', [
            'cadastros' => $cadastros,
        ]);
    }
}
