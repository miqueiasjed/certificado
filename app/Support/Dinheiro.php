<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Aritmética monetária em centavos (inteiro).
 *
 * Todo dinheiro deste sistema é `decimal(10,2)` no banco e chega ao PHP como
 * texto ("333.33"). Somar, subtrair e comparar esses valores como float é o
 * defeito clássico que só aparece na conciliação meses depois: `0.1 + 0.2`
 * não é `0.3`, e `round()` aplicado parcela a parcela fecha a conta um
 * centavo abaixo ou acima do total.
 *
 * A regra do projeto é converter para centavos como número inteiro, fazer a
 * conta em inteiro, e só voltar para decimal na hora de gravar ou de exibir.
 * Esta classe é o ponto único dessa conversão: `ReceivableService` (divisão
 * do título em parcelas), `SettlementService` (soma das baixas e comparação
 * com o saldo devedor) e o lado de contas a pagar usam as mesmas três
 * funções, para não existirem duas implementações que divirjam em um centavo.
 *
 * Utilitário de infraestrutura: sem regra de domínio e sem acesso a banco.
 */
final class Dinheiro
{
    /**
     * Converte um valor monetário (decimal com até duas casas) para centavos,
     * sem passar por multiplicação em ponto flutuante: a parte inteira e a
     * parte decimal do texto são lidas separadamente.
     *
     * `null` e string vazia valem zero, porque é assim que chega uma coluna
     * de valor ainda não preenchida.
     */
    public static function centavos(mixed $valor): int
    {
        if ($valor === null || $valor === '') {
            return 0;
        }

        if (! is_numeric($valor)) {
            throw new InvalidArgumentException(
                'Valor monetário inválido: '.get_debug_type($valor).' ('.var_export($valor, true).').'
            );
        }

        $normalizado = number_format((float) $valor, 2, '.', '');
        $negativo = str_starts_with($normalizado, '-');
        $normalizado = ltrim($normalizado, '-');

        [$reais, $centavos] = explode('.', $normalizado);

        $total = ((int) $reais * 100) + (int) $centavos;

        return $negativo ? -$total : $total;
    }

    /**
     * Converte centavos (inteiro) para o texto decimal gravado nas colunas de
     * valor ("333.34"), sem nenhuma divisão em ponto flutuante.
     */
    public static function paraDecimal(int $centavos): string
    {
        $sinal = $centavos < 0 ? '-' : '';
        $centavos = abs($centavos);

        return sprintf('%s%d.%02d', $sinal, intdiv($centavos, 100), $centavos % 100);
    }

    /**
     * Formata centavos para exibição em português ("R$ 1.234,56").
     *
     * Usado em mensagem de erro que precisa mostrar o número ao usuário, como
     * a recusa de baixa acima do saldo devedor: dizer o valor exato evita a
     * segunda tentativa no escuro.
     */
    public static function formatar(int $centavos): string
    {
        $sinal = $centavos < 0 ? '-' : '';
        $centavos = abs($centavos);

        $reais = number_format(intdiv($centavos, 100), 0, ',', '.');

        return sprintf('%sR$ %s,%02d', $sinal, $reais, $centavos % 100);
    }
}
