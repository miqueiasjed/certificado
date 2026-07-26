<?php

namespace App\Services;

use App\Models\PaymentDetail;
use Illuminate\Database\Eloquent\Builder;

/**
 * Regras de valor das parcelas de uma ordem de serviço.
 *
 * `amount_paid` significa valor PAGO em todo o sistema: a soma de recebimentos
 * de uma ordem filtra por `payment_date` e soma essa coluna, e os scopes
 * `scopePaid()` e `scopePending()` do model decidem pela mesma data. Parcela em
 * aberto não teve pagamento nenhum, então guarda quanto vale em `final_amount`
 * e mantém `amount_paid` zerado.
 *
 * Gravar o valor a receber em `amount_paid` é o que fazia a rotina agendada
 * classificar parcela em aberto como paga.
 */
class PaymentDetailService
{
    /**
     * Status que só existem quando houve recebimento.
     *
     * @var list<string>
     */
    private const STATUS_COM_RECEBIMENTO = ['paid', 'partial'];

    /**
     * Move o valor informado em `amount_paid` para `final_amount` quando os
     * dados descrevem uma parcela sem pagamento nenhum.
     *
     * A tela de pagamento manda o valor da parcela no campo `amount_paid`
     * (rótulo "Valor Devido" na criação). Normalizar aqui, e não na tela, faz a
     * regra valer para qualquer cliente que chame o endpoint.
     *
     * @param  array<string, mixed>  $dados
     * @param  PaymentDetail|null  $parcela  registro já gravado, no caso de edição
     * @return array<string, mixed>
     */
    public function normalizarValores(array $dados, ?PaymentDetail $parcela = null): array
    {
        if (! $this->descreveParcelaSemPagamento($dados, $parcela)) {
            return $dados;
        }

        $valorInformado = (float) ($dados['amount_paid'] ?? 0);

        if ($valorInformado <= 0) {
            return $dados;
        }

        // Sem `final_amount` explícito, o valor digitado é o valor da parcela.
        // Com `final_amount` explícito, quem manda é ele e `amount_paid` só zera.
        if (! $this->veioPreenchido($dados, 'final_amount')) {
            $dados['final_amount'] = $valorInformado;
        }

        $dados['amount_paid'] = 0;

        return $dados;
    }

    /**
     * Quanto a parcela vale, a partir dos dados de entrada já normalizados.
     *
     * @param  array<string, mixed>  $dados
     */
    public function valorDaParcela(array $dados): float
    {
        if ($this->veioPreenchido($dados, 'final_amount')) {
            return (float) $dados['final_amount'];
        }

        return (float) ($dados['amount_paid'] ?? 0);
    }

    /**
     * Procura parcela pendente da mesma ordem com o valor informado, para não
     * duplicar cobrança.
     *
     * O valor da parcela vive em `final_amount`. Registros gravados antes desta
     * correção têm `final_amount` nulo e o valor em `amount_paid`, e a busca
     * cobre os dois formatos enquanto o backfill
     * (`payments:corrigir-parcelas-pendentes`) não roda em produção.
     */
    public function parcelaEmAbertoComValor(int $ordemId, float $valor, ?string $trechoObservacoes = null): ?PaymentDetail
    {
        $consulta = PaymentDetail::query()
            ->where('work_order_id', $ordemId)
            ->where('payment_status', 'pending')
            ->where(function (Builder $filtro) use ($valor) {
                $filtro->where('final_amount', $valor)
                    ->orWhere(function (Builder $formatoAntigo) use ($valor) {
                        $formatoAntigo->whereNull('final_amount')
                            ->where('amount_paid', $valor);
                    });
            });

        if ($trechoObservacoes !== null) {
            $consulta->where('payment_notes', 'like', '%' . $trechoObservacoes . '%');
        }

        return $consulta->first();
    }

    /**
     * Total efetivamente recebido em uma ordem.
     *
     * Só conta parcela com `payment_date`: valor sem data de pagamento é
     * dinheiro a receber, nunca dinheiro que entrou.
     */
    public function totalRecebidoDaOrdem(int $ordemId): float
    {
        return (float) PaymentDetail::query()
            ->where('work_order_id', $ordemId)
            ->whereNotNull('payment_date')
            ->sum('amount_paid');
    }

    /**
     * Consulta das parcelas com valor registrado como pago sem nenhum
     * pagamento: `payment_date` nula e `amount_paid` maior que zero.
     */
    public function consultaDeParcelasComValorSemPagamento(): Builder
    {
        return PaymentDetail::query()
            ->whereNull('payment_date')
            ->where('amount_paid', '>', 0);
    }

    /**
     * Decide o que fazer com uma parcela com valor sem pagamento.
     *
     * Quando `final_amount` já tem valor diferente, os dois campos discordam
     * sobre quanto a parcela vale e não há como escolher sem conferir: o
     * registro é apenas listado.
     *
     * @return array{acao: string, valor: float, valorFinalAtual: float|null, motivo: string}
     */
    public function avaliarParcelaComValorSemPagamento(PaymentDetail $parcela): array
    {
        $valor = (float) $parcela->amount_paid;
        $valorFinal = $parcela->final_amount === null ? null : (float) $parcela->final_amount;

        if ($valorFinal === null || $valorFinal <= 0.0) {
            return [
                'acao' => 'corrigir',
                'valor' => $valor,
                'valorFinalAtual' => $valorFinal,
                'motivo' => 'final_amount_vazio',
            ];
        }

        if ($this->mesmoValor($valorFinal, $valor)) {
            return [
                'acao' => 'corrigir',
                'valor' => $valor,
                'valorFinalAtual' => $valorFinal,
                'motivo' => 'final_amount_igual',
            ];
        }

        return [
            'acao' => 'conferir',
            'valor' => $valor,
            'valorFinalAtual' => $valorFinal,
            'motivo' => 'final_amount_divergente',
        ];
    }

    /**
     * Aplica a correção em uma única parcela. Devolve false quando o registro
     * precisa de conferência manual e nada foi gravado.
     */
    public function corrigirParcelaComValorSemPagamento(PaymentDetail $parcela): bool
    {
        $avaliacao = $this->avaliarParcelaComValorSemPagamento($parcela);

        if ($avaliacao['acao'] !== 'corrigir') {
            return false;
        }

        $alteracoes = ['amount_paid' => 0];

        if ($avaliacao['motivo'] === 'final_amount_vazio') {
            $alteracoes['final_amount'] = $avaliacao['valor'];
        }

        $parcela->update($alteracoes);

        return true;
    }

    /**
     * Parcela sem pagamento é a que não tem data de pagamento nem status de
     * recebimento. O status entra na conta porque a tela de edição manda a data
     * vazia junto com o status escolhido pelo usuário.
     *
     * @param  array<string, mixed>  $dados
     */
    private function descreveParcelaSemPagamento(array $dados, ?PaymentDetail $parcela): bool
    {
        $dataPagamento = array_key_exists('payment_date', $dados)
            ? $dados['payment_date']
            : $parcela?->payment_date;

        if (! empty($dataPagamento)) {
            return false;
        }

        $status = $this->veioPreenchido($dados, 'payment_status')
            ? $dados['payment_status']
            : $parcela?->getRawOriginal('payment_status');

        return ! in_array($status, self::STATUS_COM_RECEBIMENTO, true);
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function veioPreenchido(array $dados, string $campo): bool
    {
        return array_key_exists($campo, $dados)
            && $dados[$campo] !== null
            && $dados[$campo] !== '';
    }

    /**
     * Comparação de dinheiro em centavos, para não depender de igualdade de
     * float.
     */
    private function mesmoValor(float $primeiro, float $segundo): bool
    {
        return round($primeiro, 2) === round($segundo, 2);
    }
}
