<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Client;
use App\Models\ServiceInvoice;
use App\Models\WorkOrder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FiscalClientService
{
    /** @return array<string, mixed> */
    public function dados(Client $cliente): array
    {
        $cliente->loadMissing(['addresses' => fn ($consulta) => $consulta->orderByDesc('active')->orderBy('nickname')]);

        return [
            'id' => (int) $cliente->id,
            'name' => $cliente->name,
            'email' => $cliente->email,
            'email_nfe' => $cliente->email_nfe,
            'phone' => $cliente->phone,
            'cnpj' => $cliente->cnpj,
            'inscricao_municipal' => $cliente->inscricao_municipal,
            'inscricao_estadual' => $cliente->inscricao_estadual,
            'addresses' => $cliente->addresses->map(fn (Address $endereco): array => [
                'id' => (int) $endereco->id,
                'nickname' => $endereco->nickname,
                'street' => $endereco->street,
                'number' => $endereco->number,
                'district' => $endereco->district,
                'city' => $endereco->city,
                'state' => $endereco->state,
                'zip' => $endereco->zip,
                'codigo_municipio_ibge' => $endereco->codigo_municipio_ibge,
                'active' => (bool) $endereco->active,
            ])->values()->all(),
        ];
    }

    /** @param array<string, mixed> $dados */
    public function salvar(Client $cliente, array $dados): array
    {
        DB::transaction(function () use ($cliente, $dados): void {
            $cliente->update(Arr::only($dados, [
                'name',
                'email',
                'email_nfe',
                'phone',
                'cnpj',
                'inscricao_municipal',
                'inscricao_estadual',
            ]));

            $dadosEndereco = $dados['address'];
            $enderecoId = $dadosEndereco['id'] ?? null;

            if ($enderecoId !== null) {
                $endereco = Address::query()
                    ->where('client_id', $cliente->id)
                    ->find($enderecoId);

                if (! $endereco instanceof Address) {
                    throw ValidationException::withMessages([
                        'address.id' => 'O endereço selecionado não pertence ao cliente.',
                    ]);
                }

                $endereco->update(Arr::except($dadosEndereco, ['id']));
            } else {
                $endereco = $cliente->addresses()->create(Arr::except($dadosEndereco, ['id']) + [
                    'active' => true,
                ]);
            }

            $this->aplicarEnderecoNasPendencias($cliente, $endereco, $dados['nota_ids'] ?? []);
        });

        return $this->dados($cliente->fresh());
    }

    /** @param array<int, int|string> $notaIds */
    private function aplicarEnderecoNasPendencias(Client $cliente, Address $endereco, array $notaIds): void
    {
        $ids = array_values(array_unique(array_map('intval', $notaIds)));

        if ($ids === []) {
            return;
        }

        $notas = ServiceInvoice::query()
            ->whereKey($ids)
            ->where('client_id', $cliente->id)
            ->where('situacao', 'erro')
            ->whereNull('reprocessada_por_id')
            ->whereNull('metadados_substituicao')
            ->lockForUpdate()
            ->get();

        if ($notas->count() !== count($ids)) {
            throw ValidationException::withMessages([
                'nota_ids' => 'Uma das pendências selecionadas não pertence ao cliente ou já foi resolvida.',
            ]);
        }

        ServiceInvoice::query()
            ->whereKey($notas->pluck('id'))
            ->update(['address_id' => $endereco->id]);

        $ordens = $notas->pluck('work_order_id')->filter()->unique()->values();

        if ($ordens->isNotEmpty()) {
            $consultaOrdens = WorkOrder::query()
                ->whereKey($ordens)
                ->where('client_id', $cliente->id);

            if ($consultaOrdens->count() !== $ordens->count()) {
                throw ValidationException::withMessages([
                    'nota_ids' => 'Uma das ordens de serviço da pendência não pertence ao cliente.',
                ]);
            }

            $consultaOrdens->update(['address_id' => $endereco->id]);
        }
    }
}
