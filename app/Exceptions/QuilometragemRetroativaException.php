<?php

namespace App\Exceptions;

use App\Models\Vehicle;
use RuntimeException;
use Throwable;

/**
 * Recusa de quilometragem menor que a última registrada no veículo (Plano 27,
 * Task 27.4).
 *
 * O hodômetro só anda para a frente. Uma leitura menor que a anterior é erro de
 * digitação — quase sempre um dígito a menos, "9.850" no lugar de "98.500" — e o
 * estrago dela não fica no próprio registro: a distância de um intervalo é a
 * diferença entre duas leituras, então um valor errado contamina o intervalo que
 * termina nele **e** o que começa nele, e o consumo médio do veículo inteiro sai
 * distorcido a partir dali. Aceitar e "corrigir depois" não existe: quando
 * alguém percebe, já não se sabe qual das leituras era a errada.
 *
 * Por que classe própria, e não um erro genérico: a recusa precisa chegar ao
 * usuário com a última quilometragem registrada no texto. Sem esse número, quem
 * está no posto com o celular na mão não tem como saber se digitou errado ou se
 * o sistema está desatualizado — e tentaria de novo às cegas, mesmo critério de
 * `EstoqueInsuficienteException`.
 */
class QuilometragemRetroativaException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $vehicleId = null,
        public readonly int $kmInformado = 0,
        public readonly int $kmRegistrado = 0,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Recusa citando a placa, a quilometragem informada e a última registrada.
     */
    public static function para(Vehicle $veiculo, int $kmInformado): self
    {
        $kmRegistrado = (int) $veiculo->km_atual;

        $mensagem = sprintf(
            'A quilometragem informada (%s km) é menor que a última registrada para o veículo %s (%s km). '
            .'Confira o hodômetro: o consumo é calculado pela diferença entre duas leituras, e um valor '
            .'para trás distorce todos os intervalos seguintes.',
            number_format($kmInformado, 0, ',', '.'),
            $veiculo->placa,
            number_format($kmRegistrado, 0, ',', '.')
        );

        return new self($mensagem, $veiculo->getKey(), $kmInformado, $kmRegistrado);
    }
}
