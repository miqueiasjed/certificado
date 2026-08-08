<?php

namespace App\Services\Fleet;

use App\Exceptions\QuilometragemRetroativaException;
use App\Models\Payable;
use App\Models\Supplier;
use App\Models\Vehicle;
use App\Models\VehicleMaintenance;
use App\Services\PayableService;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registro de manutenção preventiva e corretiva (Plano 27, Task 27.4).
 *
 * Vale aqui a mesma regra do abastecimento, pelo mesmo motivo: a quilometragem
 * informada na manutenção **também** move `vehicles.km_atual`, porque a oficina
 * anota o hodômetro na ordem de serviço dela, e essa costuma ser a leitura mais
 * recente que a empresa tem. E, também como lá, leitura retroativa é recusada
 * com a última registrada na mensagem.
 *
 * `proxima_em_data` e `proxima_em_km` continuam independentes: quem registra
 * pode preencher um, o outro ou os dois, e é a Task 27.3 que decide qual dos
 * dois dispara primeiro o alerta.
 *
 * Título a pagar é oferecido, não criado automaticamente, exatamente como no
 * abastecimento. Aqui o padrão de vencimento é diferente: serviço de oficina
 * costuma ser faturado, então o vencimento sugerido é o dia da manutenção, e
 * quem registra informa outro se houver prazo.
 */
class VehicleMaintenanceService
{
    public function __construct(
        private readonly PayableService $titulos,
    ) {}

    /**
     * Registra a manutenção e, se pedido, gera o título a pagar vinculado.
     *
     * @param  array<string, mixed>  $dados
     * @return array{manutencao: VehicleMaintenance, titulo: ?Payable, oferta_de_titulo: array<string, mixed>}
     */
    public function registrar(Vehicle $veiculo, array $dados): array
    {
        $km = isset($dados['km']) && $dados['km'] !== null ? (int) $dados['km'] : null;

        if ($km !== null && $km < (int) $veiculo->km_atual) {
            throw QuilometragemRetroativaException::para($veiculo, $km);
        }

        return DB::transaction(function () use ($veiculo, $dados, $km): array {
            $manutencao = VehicleMaintenance::create([
                'vehicle_id' => $veiculo->getKey(),
                'tipo' => $dados['tipo'],
                'descricao' => $dados['descricao'],
                'data' => BusinessDate::diaDe($dados['data'] ?? null),
                'km' => $km,
                'proxima_em_data' => BusinessDate::diaDe($dados['proxima_em_data'] ?? null),
                'proxima_em_km' => $dados['proxima_em_km'] ?? null,
                'valor' => $dados['valor'] ?? null,
                'oficina' => $dados['oficina'] ?? null,
                'situacao' => $dados['situacao'] ?? 'agendada',
            ]);

            if ($km !== null && $km > (int) $veiculo->km_atual) {
                $veiculo->forceFill(['km_atual' => $km])->save();
            }

            $titulo = $this->gerarTituloSePedido($veiculo, $manutencao, $dados);

            return [
                'manutencao' => $manutencao->fresh(['payable']),
                'titulo' => $titulo,
                'oferta_de_titulo' => $this->oferta($veiculo, $manutencao, $titulo),
            ];
        });
    }

    /**
     * Atualiza a manutenção. Mesma regra de quilometragem retroativa da
     * criação: a leitura informada aqui também move o hodômetro.
     *
     * @param  array<string, mixed>  $dados
     */
    public function atualizar(VehicleMaintenance $manutencao, array $dados): VehicleMaintenance
    {
        $veiculo = $manutencao->vehicle;
        $km = array_key_exists('km', $dados) && $dados['km'] !== null ? (int) $dados['km'] : null;

        if ($km !== null && $veiculo !== null && $km < (int) $veiculo->km_atual) {
            throw QuilometragemRetroativaException::para($veiculo, $km);
        }

        return DB::transaction(function () use ($manutencao, $veiculo, $dados, $km): VehicleMaintenance {
            $manutencao->update(array_intersect_key($dados, array_flip([
                'tipo',
                'descricao',
                'data',
                'km',
                'proxima_em_data',
                'proxima_em_km',
                'valor',
                'oficina',
                'situacao',
            ])));

            if ($km !== null && $veiculo !== null && $km > (int) $veiculo->km_atual) {
                $veiculo->forceFill(['km_atual' => $km])->save();
            }

            return $manutencao->fresh(['payable']);
        });
    }

    /**
     * Gera o título a pagar de uma manutenção já registrada, para quem recusou
     * a oferta na hora e mudou de ideia depois.
     *
     * @param  array<string, mixed>  $dados
     */
    public function gerarTitulo(VehicleMaintenance $manutencao, array $dados): Payable
    {
        if ($manutencao->payable_id !== null) {
            throw new RuntimeException(
                'Esta manutenção já tem um título a pagar vinculado (#'.$manutencao->payable_id.').'
            );
        }

        if ($manutencao->valor === null) {
            throw new RuntimeException(
                'Informe o valor da manutenção antes de gerar o título a pagar: '
                .'título sem valor não tem o que ser conciliado depois.'
            );
        }

        $titulo = $this->criarTitulo(
            $manutencao->vehicle,
            $manutencao,
            (int) $dados['supplier_id'],
            $dados['vencimento'] ?? null,
            $dados['chart_of_account_id'] ?? null
        );

        $manutencao->forceFill(['payable_id' => $titulo->getKey()])->save();

        return $titulo;
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $dados
     */
    private function gerarTituloSePedido(Vehicle $veiculo, VehicleMaintenance $manutencao, array $dados): ?Payable
    {
        $pediu = (bool) ($dados['gerar_titulo'] ?? false);
        $fornecedor = $dados['supplier_id'] ?? null;

        // Sem valor não há título: um título a pagar de valor zero é uma linha
        // que ninguém consegue conciliar depois.
        if (! $pediu || $fornecedor === null || $manutencao->valor === null) {
            return null;
        }

        $titulo = $this->criarTitulo(
            $veiculo,
            $manutencao,
            (int) $fornecedor,
            $dados['vencimento'] ?? null,
            $dados['chart_of_account_id'] ?? null
        );

        $manutencao->forceFill(['payable_id' => $titulo->getKey()])->save();

        return $titulo;
    }

    private function criarTitulo(
        ?Vehicle $veiculo,
        VehicleMaintenance $manutencao,
        int $supplierId,
        ?string $vencimento,
        ?int $chartOfAccountId,
    ): Payable {
        // Fornecedor pelo model escopado, nunca pelo id cru: id de outra
        // empresa não pode virar título nesta.
        $fornecedor = Supplier::query()->findOrFail($supplierId);

        $dia = BusinessDate::diaDe($manutencao->data) ?? BusinessDate::hoje()->toDateString();

        return $this->titulos->criar([
            'supplier_id' => $fornecedor->getKey(),
            'descricao' => $this->descricaoDoTitulo($veiculo, $manutencao),
            'valor' => $manutencao->valor,
            'emitido_em' => $dia,
            'vencimento' => $vencimento ?? $dia,
            'chart_of_account_id' => $chartOfAccountId,
        ]);
    }

    private function descricaoDoTitulo(?Vehicle $veiculo, VehicleMaintenance $manutencao): string
    {
        return sprintf(
            'Manutenção %s do veículo %s: %s%s',
            $manutencao->tipo,
            $veiculo?->placa ?? 'sem placa',
            $manutencao->descricao,
            $manutencao->oficina !== null ? ' ('.$manutencao->oficina.')' : ''
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function oferta(Vehicle $veiculo, VehicleMaintenance $manutencao, ?Payable $titulo): array
    {
        $dia = BusinessDate::diaDe($manutencao->data) ?? BusinessDate::hoje()->toDateString();

        return [
            'disponivel' => $titulo === null && $manutencao->valor !== null,
            'payable_id' => $titulo?->getKey(),
            'descricao_sugerida' => $this->descricaoDoTitulo($veiculo, $manutencao),
            'valor' => $manutencao->valor,
            'vencimento_sugerido' => $dia,
        ];
    }
}
