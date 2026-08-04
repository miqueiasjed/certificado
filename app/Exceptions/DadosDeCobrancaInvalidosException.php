<?php

namespace App\Exceptions;

use App\Models\Client;
use RuntimeException;

/**
 * Recusa de emissão de cobrança por dado do cliente final incompleto ou
 * inválido, apurada **antes** de qualquer chamada ao gateway (Plano 19,
 * Task 19.3).
 *
 * Lançada por `App\Services\ChargeService::emitir()` quando
 * `App\Services\Payments\ValidadorDeDadosDeCobranca::validar()` devolve
 * pendência. É a barreira que evita o erro cru do provedor
 * (`GatewayDadosClienteInvalidosException`, Task 19.2) para o caso mais
 * comum: cliente cadastrado sem CPF/CNPJ, sem e-mail ou sem CEP no endereço.
 * A lista do que falta fica em `$faltando`, em português, pronta para a tela
 * mostrar sem precisar reprocessar a mensagem.
 */
class DadosDeCobrancaInvalidosException extends RuntimeException
{
    /**
     * @param  array<int, string>  $faltando
     */
    public function __construct(
        string $message,
        public readonly array $faltando,
        public readonly ?int $clientId = null,
    ) {
        parent::__construct($message);
    }

    /**
     * @param  array<int, string>  $faltando  Ver `ValidadorDeDadosDeCobranca::validar()`.
     */
    public static function paraCliente(Client $cliente, array $faltando): self
    {
        $mensagem = sprintf(
            'Não é possível emitir cobrança para "%s": faltam os seguintes dados do cadastro: %s.',
            $cliente->name,
            implode(', ', $faltando)
        );

        return new self($mensagem, $faltando, $cliente->getKey());
    }
}
