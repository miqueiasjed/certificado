<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\Client;
use App\Models\Address;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BudgetService
{
    /**
     * Create a new budget.
     */
    public function createBudget(array $data): Budget
    {
        return DB::transaction(function () use ($data) {
            $budget = Budget::create([
                'client_id' => $data['client_id'] ?? null,
                'prospect_name' => $data['prospect_name'] ?? null,
                'prospect_phone' => $data['prospect_phone'] ?? null,
                'prospect_address' => $data['prospect_address'] ?? null,
                'user_id' => auth()->id(),
                'date' => $data['date'],
                'priority' => $data['priority'],
                'channel' => $data['channel'] ?? null,
                'target_pests' => $data['target_pests'] ?? null,
                'environment_type' => $data['environment_type'] ?? null,
                'areas_to_treat' => $data['areas_to_treat'] ?? null,
                'labor_technicians' => $data['labor_technicians'] ?? 1,
                'labor_hours' => $data['labor_hours'] ?? null,
                'discount' => $data['discount'] ?? 0,
                'payment_method' => $data['payment_method'] ?? null,
                'payment_conditions' => $data['payment_conditions'] ?? null,
                'execution_deadline' => $data['execution_deadline'] ?? null,
                'validity_date' => $data['validity_date'] ?? null,
                'status' => 'draft',
            ]);

            // Sync Services
            if (!empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    $budget->services()->attach($item['service_id'], [
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $item['subtotal'],
                    ]);
                }
            }

            // Sync Products (optional)
            if (!empty($data['products'])) {
                foreach ($data['products'] as $prod) {
                    $budget->products()->attach($prod['product_id'], [
                        'quantity' => $prod['quantity'],
                    ]);
                }
            }

            return $budget;
        });
    }

    /**
     * Update an existing budget.
     */
    public function updateBudget(Budget $budget, array $data): Budget
    {
        return DB::transaction(function () use ($budget, $data) {
            $budget->update([
                'client_id' => $data['client_id'] ?? null,
                'prospect_name' => $data['prospect_name'] ?? null,
                'prospect_phone' => $data['prospect_phone'] ?? null,
                'prospect_address' => $data['prospect_address'] ?? null,
                'date' => $data['date'],
                'priority' => $data['priority'],
                'channel' => $data['channel'] ?? null,
                'target_pests' => $data['target_pests'] ?? null,
                'environment_type' => $data['environment_type'] ?? null,
                'areas_to_treat' => $data['areas_to_treat'] ?? null,
                'labor_technicians' => $data['labor_technicians'] ?? 1,
                'labor_hours' => $data['labor_hours'] ?? null,
                'discount' => $data['discount'] ?? 0,
                'payment_method' => $data['payment_method'] ?? null,
                'payment_conditions' => $data['payment_conditions'] ?? null,
                'execution_deadline' => $data['execution_deadline'] ?? null,
                'validity_date' => $data['validity_date'] ?? null,
                'status' => $data['status'],
                'loss_reason' => $data['loss_reason'] ?? null,
            ]);

            // Sync Services
            $budget->services()->detach();
            if (!empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    $budget->services()->attach($item['service_id'], [
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'subtotal' => $item['subtotal'],
                    ]);
                }
            }

            // Sync Products
            $budget->products()->detach();
            if (!empty($data['products'])) {
                foreach ($data['products'] as $prod) {
                    $budget->products()->attach($prod['product_id'], [
                        'quantity' => $prod['quantity'],
                    ]);
                }
            }

            return $budget;
        });
    }

    /**
     * Delete a budget.
     */
    public function deleteBudget(Budget $budget): bool
    {
        return $budget->delete();
    }

    /**
     * Convert a budget to a work order (Client setup).
     * Returns the client ID to be used for redirection.
     */
    public function prepareForConversion(Budget $budget): array
    {
        if ($budget->status === 'converted') {
            throw new \Exception('Este orçamento já foi convertido.');
        }

        $clientId = $budget->client_id;

        DB::transaction(function () use ($budget, &$clientId) {
            if (!$clientId) {
                // Create Client from Prospect
                $email = Str::slug($budget->prospect_name) . '-' . time() . '@tempprospect.com';

                $client = Client::create([
                    'name' => $budget->prospect_name,
                    'phone' => $budget->prospect_phone ?? 'Não informado',
                    'email' => $email,
                    'cnpj' => '000.000.000-00', // Placeholder
                    'date_of_birth' => now(), // Placeholder
                ]);

                // Create Address
                Address::create([
                    'client_id' => $client->id,
                    'nickname' => 'Endereço Orçamento',
                    'street' => $budget->prospect_address ?? 'Endereço não informado',
                    'number' => 'S/N',
                    'district' => '-',
                    'city' => '-',
                    'state' => 'XX',
                    'zip' => '00000-000',
                    'active' => true
                ]);

                $budget->update(['client_id' => $client->id]);
                $clientId = $client->id;
            }

            $budget->update(['status' => 'converted']);
        });

        return ['client_id' => $clientId];
    }
}
