<?php

namespace App\Services\Payments;

use App\Models\Address;
use App\Models\Client;

/**
 * Confere se o cadastro do cliente tem o mínimo exigido para receber um
 * boleto ou um Pix, antes de qualquer chamada ao gateway (Plano 19,
 * Task 19.3).
 *
 * Validar localmente reduz a falha mais comum de emissão (cliente sem
 * CPF/CNPJ, sem e-mail ou sem CEP no endereço) para uma mensagem em
 * português dizendo exatamente o que falta, em vez do erro cru do provedor
 * (`GatewayDadosClienteInvalidosException`, Task 19.2) — que continua
 * existindo como segunda barreira para o que esta validação não pega (CPF
 * que passa no formato mas o provedor recusa por outro motivo).
 *
 * Serviço sem estado e sem acesso a gateway: só lê o `Client` recebido e o
 * endereço dele.
 */
class ValidadorDeDadosDeCobranca
{
    /**
     * O que falta no cadastro do cliente para ele poder receber cobrança,
     * em português, pronto para exibição. Lista vazia significa cadastro
     * completo.
     *
     * Confere, nesta ordem: nome, documento (CPF ou CNPJ, com dígito
     * verificador válido), e-mail e endereço com CEP.
     *
     * @return array<int, string>
     */
    public function validar(Client $cliente): array
    {
        $faltando = [];

        if (blank(trim((string) $cliente->name))) {
            $faltando[] = 'nome do cliente';
        }

        $faltando = array_merge($faltando, $this->conferirDocumento($cliente));
        $faltando = array_merge($faltando, $this->conferirEmail($cliente));
        $faltando = array_merge($faltando, $this->conferirEndereco($cliente));

        return $faltando;
    }

    /**
     * Atalho para quem só precisa saber se passa ou não, sem a lista.
     */
    public function valido(Client $cliente): bool
    {
        return $this->validar($cliente) === [];
    }

    /**
     * Endereço usado para montar a cobrança: o ativo mais antigo, e na
     * ausência de um ativo, o mais antigo entre todos. `Client` não tem
     * conceito de "endereço principal" no cadastro atual, e este é o
     * critério mais estável disponível (o endereço mais antigo tende a ser
     * o de cadastro original do cliente).
     */
    public function enderecoPrincipal(Client $cliente): ?Address
    {
        return $cliente->addresses()->active()->orderBy('id')->first()
            ?? $cliente->addresses()->orderBy('id')->first();
    }

    /**
     * @return array<int, string>
     */
    private function conferirDocumento(Client $cliente): array
    {
        $documento = trim((string) $cliente->cnpj);

        if ($documento === '') {
            return ['CPF ou CNPJ do cliente'];
        }

        if (! $this->documentoValido($documento)) {
            return ['CPF ou CNPJ do cliente (documento inválido)'];
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function conferirEmail(Client $cliente): array
    {
        // Mesmo critério de `NotificationService::resolverDestino()`: o
        // e-mail de notificação, quando informado, é quem de fato recebe o
        // aviso (o cadastral costuma ser o da nota fiscal, em pessoa
        // jurídica).
        $email = trim((string) ($cliente->email_notificacao ?: $cliente->email));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['e-mail do cliente'];
        }

        return [];
    }

    /**
     * @return array<int, string>
     */
    private function conferirEndereco(Client $cliente): array
    {
        $endereco = $this->enderecoPrincipal($cliente);

        if ($endereco === null) {
            return ['endereço do cliente'];
        }

        if (blank(trim((string) $endereco->zip))) {
            return ['CEP do endereço do cliente'];
        }

        return [];
    }

    /**
     * CPF (11 dígitos) ou CNPJ (14 dígitos) com dígito verificador correto,
     * pelo algoritmo módulo 11 padrão da Receita Federal. Sequência de
     * dígitos repetidos ("111.111.111-11") nunca é válida, mesmo quando o
     * cálculo do dígito bateria: é o caso clássico de cadastro preenchido
     * com valor de teste.
     */
    private function documentoValido(string $documento): bool
    {
        $digitos = preg_replace('/\D/', '', $documento) ?? '';

        return match (strlen($digitos)) {
            11 => $this->cpfValido($digitos),
            14 => $this->cnpjValido($digitos),
            default => false,
        };
    }

    private function cpfValido(string $cpf): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $cpf) === 1) {
            return false;
        }

        $digito1 = $this->digitoVerificador($cpf, 9, 10);
        $digito2 = $this->digitoVerificador($cpf, 10, 11);

        return $cpf[9] === (string) $digito1 && $cpf[10] === (string) $digito2;
    }

    private function cnpjValido(string $cnpj): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        $pesos1 = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $pesos2 = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        $digito1 = $this->digitoVerificadorPonderado($cnpj, $pesos1);
        $digito2 = $this->digitoVerificadorPonderado($cnpj, $pesos2);

        return $cnpj[12] === (string) $digito1 && $cnpj[13] === (string) $digito2;
    }

    /**
     * Dígito verificador de CPF: soma dos `$tamanho` primeiros dígitos, cada
     * um multiplicado por um peso que decresce a partir de `$pesoInicial`.
     */
    private function digitoVerificador(string $numero, int $tamanho, int $pesoInicial): int
    {
        $soma = 0;
        $peso = $pesoInicial;

        for ($i = 0; $i < $tamanho; $i++) {
            $soma += (int) $numero[$i] * $peso;
            $peso--;
        }

        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }

    /**
     * Dígito verificador de CNPJ: mesma ideia acima, com pesos fixos que não
     * decrescem em sequência simples (por isso a lista explícita).
     *
     * @param  array<int, int>  $pesos
     */
    private function digitoVerificadorPonderado(string $numero, array $pesos): int
    {
        $soma = 0;

        foreach ($pesos as $i => $peso) {
            $soma += (int) $numero[$i] * $peso;
        }

        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }
}
