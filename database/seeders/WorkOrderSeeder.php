<?php

namespace Database\Seeders;

use App\Models\WorkOrder;
use App\Models\Client;
use App\Models\Address;
use App\Models\Technician;
use App\Models\PaymentDetail;
use Illuminate\Database\Seeder;

class WorkOrderSeeder extends Seeder
{
    public function run(): void
    {
        // Buscar clientes, endereços e técnicos existentes
        $clients = Client::all();
        $addresses = Address::all();
        $technicians = Technician::all();

        if ($clients->isEmpty() || $addresses->isEmpty() || $technicians->isEmpty()) {
            $this->command->warn('Não é possível criar Work Orders: faltam clientes, endereços ou técnicos.');
            return;
        }

        // Criar algumas Work Orders de exemplo
        $workOrders = [
            [
                'order_number' => 'OS-2025-001',
                'client_id' => $clients->first()->id,
                'address_id' => $addresses->first()->id,
                'technician_id' => $technicians->first()->id,
                'order_type' => 'preventive',
                'priority_level' => 'medium',
                'scheduled_date' => now()->addDays(1),
                'start_time' => now()->addDays(1)->setTime(8, 0),
                'end_time' => now()->addDays(1)->setTime(12, 0),
                'status' => 'scheduled',
                'description' => 'Aplicação preventiva de inseticida em todos os ambientes',
                'observations' => 'Cliente solicitou aplicação em horário comercial',
                'materials_used' => json_encode(['Inseticida', 'Pulverizador', 'Equipamento de proteção']),
                'payment_status' => 'pending',
                'completion_notes' => null,
                'active' => true,
            ],
            [
                'order_number' => 'OS-2025-002',
                'client_id' => $clients->count() > 1 ? $clients->get(1)->id : $clients->first()->id,
                'address_id' => $addresses->count() > 1 ? $addresses->get(1)->id : $addresses->first()->id,
                'technician_id' => $technicians->count() > 1 ? $technicians->get(1)->id : $technicians->first()->id,
                'order_type' => 'corrective',
                'priority_level' => 'high',
                'scheduled_date' => now()->subDays(1),
                'start_time' => now()->subDays(1)->setTime(14, 0),
                'end_time' => now()->subDays(1)->setTime(18, 0),
                'status' => 'completed',
                'description' => 'Tratamento corretivo para infestação de baratas',
                'observations' => 'Infestação severa na cozinha e banheiro',
                'materials_used' => json_encode(['Inseticida forte', 'Gel', 'Armadilhas', 'Pulverizador']),
                'payment_status' => 'paid',
                'completion_notes' => 'Tratamento realizado com sucesso. Recomenda-se retorno em 30 dias.',
                'active' => true,
            ],
            [
                'order_number' => 'OS-2025-003',
                'client_id' => $clients->count() > 2 ? $clients->get(2)->id : $clients->first()->id,
                'address_id' => $addresses->count() > 2 ? $addresses->get(2)->id : $addresses->first()->id,
                'technician_id' => $technicians->first()->id,
                'order_type' => 'emergency',
                'priority_level' => 'urgent',
                'scheduled_date' => now(),
                'start_time' => now()->setTime(10, 0),
                'end_time' => now()->setTime(14, 0),
                'status' => 'in_progress',
                'description' => 'Atendimento emergencial para infestação de ratos',
                'observations' => 'Cliente relatou avistamento de ratos na área externa',
                'materials_used' => json_encode(['Raticida', 'Armadilhas', 'Iscas']),
                'payment_status' => 'partial',
                'completion_notes' => null,
                'active' => true,
            ]
        ];

        foreach ($workOrders as $workOrderData) {
            $workOrder = WorkOrder::create($workOrderData);

            // Criar detalhes de pagamento para algumas Work Orders
            if ($workOrder->payment_status === 'paid') {
                PaymentDetail::create([
                    'work_order_id' => $workOrder->id,
                    'total_cost' => 450.00,
                    'discount_amount' => null,
                    'final_amount' => 450.00,
                    'payment_due_date' => $workOrder->scheduled_date,
                    'payment_date' => $workOrder->scheduled_date,
                    'payment_method' => 'pix',
                    'amount_paid' => 450.00,
                    'payment_notes' => 'Pagamento realizado via PIX',
                    'is_partial_payment' => false,
                ]);
            } elseif ($workOrder->payment_status === 'partial') {
                // Primeiro pagamento parcial
                PaymentDetail::create([
                    'work_order_id' => $workOrder->id,
                    'total_cost' => 280.00,
                    'discount_amount' => 30.00,
                    'final_amount' => 250.00,
                    'payment_due_date' => $workOrder->scheduled_date,
                    'payment_date' => $workOrder->scheduled_date,
                    'payment_method' => 'pix',
                    'amount_paid' => 150.00,
                    'payment_notes' => 'Primeiro pagamento parcial',
                    'is_partial_payment' => true,
                ]);

                // Segundo pagamento pendente
                PaymentDetail::create([
                    'work_order_id' => $workOrder->id,
                    'total_cost' => 280.00,
                    'discount_amount' => 30.00,
                    'final_amount' => 250.00,
                    'payment_due_date' => $workOrder->scheduled_date->addDays(30),
                    'payment_date' => null,
                    'payment_method' => null,
                    'amount_paid' => 100.00,
                    'payment_notes' => 'Segundo pagamento pendente',
                    'is_partial_payment' => true,
                ]);
            } else {
                // Work Order pendente - criar apenas o registro financeiro
                PaymentDetail::create([
                    'work_order_id' => $workOrder->id,
                    'total_cost' => 350.00,
                    'discount_amount' => 50.00,
                    'final_amount' => 300.00,
                    'payment_due_date' => $workOrder->scheduled_date,
                    'payment_date' => null,
                    'payment_method' => null,
                    'amount_paid' => 0.00,
                    'payment_notes' => 'Pagamento pendente',
                    'is_partial_payment' => false,
                ]);
            }
        }

        $this->command->info('Work Orders de exemplo criadas com sucesso!');
    }
}
