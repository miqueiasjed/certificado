<?php

namespace App\Services\Fleet;

use App\Exceptions\QuilometragemRetroativaException;
use App\Models\Payable;
use App\Models\Refueling;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\PayableService;
use App\Support\BusinessDate;
use App\Support\Dinheiro;
use Illuminate\Support\Facades\DB;

/**
 * Registro de abastecimento (Plano 27, Task 27.4).
 *
 * Duas regras moram aqui, e as duas existem para proteger o cálculo de consumo,
 * que é a base de todo o módulo:
 *
 * 1. **Quilometragem retroativa é recusada**, com a última registrada na
 *    mensagem (`QuilometragemRetroativaException`). Ver o docblock daquela
 *    classe para o estrago que um dígito a menos causa.
 * 2. **`vehicles.km_atual` avança junto com o abastecimento.** É o único
 *    caminho pelo qual o hodômetro do sistema acompanha o do carro, e é por
 *    isso que o alerta de manutenção por quilometragem depende de haver
 *    abastecimento registrado — daí o aviso próprio de quilometragem
 *    desatualizada da Task 27.3.
 *
 * Título a pagar é oferecido, não criado automaticamente
 * -------------------------------------------------------
 * `registrar()` só gera o título quando `gerar_titulo` vem verdadeiro **e** um
 * fornecedor é informado. Sem isso, o retorno traz `oferta_de_titulo` com o
 * valor e a descrição já montados, para a tela perguntar. Lançar despesa que
 * ninguém pediu suja o financeiro de quem controla frota só operacionalmente, e
 * o estrago é pior que o trabalho de um clique: título indevido entra no fluxo
 * de caixa e no aging, e alguém vai conciliá-lo achando que é real.
 */
class RefuelingService
{
    /**
     * Dias somados à data do abastecimento para o vencimento do título, quando
     * quem registra não informa um.
     *
     * Zero: combustível é pago no ato, no posto. Não é uma compra a prazo com
     * boleto, e datar o vencimento para a frente criaria um título "em aberto"
     * que na verdade já foi pago — exatamente o tipo de linha que ninguém
     * consegue conciliar depois.
     */
    public const DIAS_ATE_O_VENCIMENTO = 0;

    public function __construct(
        private readonly PayableService $titulos,
    ) {}

    /**
     * Registra o abastecimento, avança o hodômetro do veículo e, se pedido,
     * gera o título a pagar vinculado.
     *
     * @param  array<string, mixed>  $dados
     * @return array{abastecimento: Refueling, titulo: ?Payable, oferta_de_titulo: array<string, mixed>}
     */
    public function registrar(Vehicle $veiculo, array $dados, User $usuario): array
    {
        $km = (int) $dados['km'];

        if ($km < (int) $veiculo->km_atual) {
            throw QuilometragemRetroativaException::para($veiculo, $km);
        }

        $litros = round((float) $dados['litros'], 3);
        $valorEmCentavos = Dinheiro::centavos($dados['valor_total']);
        $data = BusinessDate::diaDe($dados['data']) ?? BusinessDate::hoje()->toDateString();

        return DB::transaction(function () use ($veiculo, $dados, $usuario, $km, $litros, $valorEmCentavos, $data): array {
            $abastecimento = Refueling::create([
                'vehicle_id' => $veiculo->getKey(),
                'data' => $data,
                'km' => $km,
                'litros' => $litros,
                'valor_total' => Dinheiro::paraDecimal($valorEmCentavos),
                'valor_litro' => $this->valorPorLitro($dados, $valorEmCentavos, $litros),
                'tipo_combustivel' => $dados['tipo_combustivel'],
                'posto' => $dados['posto'] ?? null,
                'tanque_cheio' => (bool) ($dados['tanque_cheio'] ?? true),
                'user_id' => $usuario->getKey(),
            ]);

            // O hodômetro do sistema só anda aqui e no registro de manutenção.
            if ($km > (int) $veiculo->km_atual) {
                $veiculo->forceFill(['km_atual' => $km])->save();
            }

            $titulo = $this->gerarTituloSePedido($veiculo, $abastecimento, $dados, $data);

            return [
                'abastecimento' => $abastecimento->fresh(['payable']),
                'titulo' => $titulo,
                'oferta_de_titulo' => $this->oferta($veiculo, $abastecimento, $titulo, $data),
            ];
        });
    }

    /**
     * Gera o título a pagar de um abastecimento já registrado, para quem
     * recusou a oferta na hora e mudou de ideia depois.
     *
     * Recusa em silêncio o segundo pedido para o mesmo abastecimento: dois
     * títulos para a mesma nota de combustível é despesa dobrada no fluxo de
     * caixa.
     *
     * @param  array<string, mixed>  $dados
     */
    public function gerarTitulo(Refueling $abastecimento, array $dados): Payable
    {
        if ($abastecimento->payable_id !== null) {
            throw new \RuntimeException(
                'Este abastecimento já tem um título a pagar vinculado (#'.$abastecimento->payable_id.').'
            );
        }

        $veiculo = $abastecimento->vehicle;

        $titulo = $this->criarTitulo(
            $veiculo,
            $abastecimento,
            (int) $dados['supplier_id'],
            $dados['vencimento'] ?? null,
            $dados['chart_of_account_id'] ?? null,
            BusinessDate::diaDe($abastecimento->data)
        );

        $abastecimento->forceFill(['payable_id' => $titulo->getKey()])->save();

        return $titulo;
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Valor por litro informado, ou calculado a partir do total e dos litros.
     *
     * Calculado é o caso comum: quem está no posto digita o que está na nota
     * (total e litros), e o preço por litro é derivado. Quatro casas porque é
     * como o preço é publicado na bomba.
     *
     * @param  array<string, mixed>  $dados
     */
    private function valorPorLitro(array $dados, int $valorEmCentavos, float $litros): string
    {
        if (isset($dados['valor_litro']) && $dados['valor_litro'] !== null && $dados['valor_litro'] !== '') {
            return number_format((float) $dados['valor_litro'], 4, '.', '');
        }

        if ($litros <= 0.0) {
            return '0.0000';
        }

        return number_format($valorEmCentavos / $litros / 100, 4, '.', '');
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function gerarTituloSePedido(Vehicle $veiculo, Refueling $abastecimento, array $dados, string $data): ?Payable
    {
        $pediu = (bool) ($dados['gerar_titulo'] ?? false);
        $fornecedor = $dados['supplier_id'] ?? null;

        if (! $pediu || $fornecedor === null) {
            return null;
        }

        $titulo = $this->criarTitulo(
            $veiculo,
            $abastecimento,
            (int) $fornecedor,
            $dados['vencimento'] ?? null,
            $dados['chart_of_account_id'] ?? null,
            $data
        );

        $abastecimento->forceFill(['payable_id' => $titulo->getKey()])->save();

        return $titulo;
    }

    private function criarTitulo(
        Vehicle $veiculo,
        Refueling $abastecimento,
        int $supplierId,
        ?string $vencimento,
        ?int $chartOfAccountId,
        string $data,
    ): Payable {
        // O fornecedor vem pelo model escopado, e não pelo id cru: id de outra
        // empresa não pode virar título nesta. Mesmo critério do
        // `StockController` para todo id vindo do corpo da requisição.
        $fornecedor = Supplier::query()->findOrFail($supplierId);

        return $this->titulos->criar([
            'supplier_id' => $fornecedor->getKey(),
            'descricao' => $this->descricaoDoTitulo($veiculo, $abastecimento),
            'valor' => $abastecimento->valor_total,
            'emitido_em' => $data,
            'vencimento' => $vencimento ?? BusinessDate::paraFusoNegocio($data)
                ->addDays(self::DIAS_ATE_O_VENCIMENTO)
                ->toDateString(),
            'chart_of_account_id' => $chartOfAccountId,
        ]);
    }

    private function descricaoDoTitulo(Vehicle $veiculo, Refueling $abastecimento): string
    {
        return sprintf(
            'Abastecimento do veículo %s em %s%s',
            $veiculo->placa,
            BusinessDate::paraFusoNegocio($abastecimento->data)->format('d/m/Y'),
            $abastecimento->posto !== null ? ' ('.$abastecimento->posto.')' : ''
        );
    }

    /**
     * O que a tela precisa para oferecer o título, e o que já foi feito.
     *
     * Vem no retorno mesmo quando o título foi gerado, para a resposta ser
     * sempre a mesma forma: `disponivel` diz se ainda cabe oferecer.
     *
     * @return array<string, mixed>
     */
    private function oferta(Vehicle $veiculo, Refueling $abastecimento, ?Payable $titulo, string $data): array
    {
        return [
            'disponivel' => $titulo === null,
            'payable_id' => $titulo?->getKey(),
            'descricao_sugerida' => $this->descricaoDoTitulo($veiculo, $abastecimento),
            'valor' => $abastecimento->valor_total,
            'vencimento_sugerido' => BusinessDate::paraFusoNegocio($data)
                ->addDays(self::DIAS_ATE_O_VENCIMENTO)
                ->toDateString(),
        ];
    }
}
