<?php

namespace App\Services\Fiscal;

use App\Models\Address;
use App\Models\Client;
use App\Models\FiscalConfig;

class ValidadorFiscal
{
    /**
     * @return array<int, string>
     */
    public function validar(Client $cliente, FiscalConfig $configuracao, ?Address $endereco): array
    {
        $faltando = [];

        if (blank(trim((string) $cliente->name))) {
            $faltando[] = 'Nome do cliente';
        }

        if (! $this->documentoValido((string) $cliente->cnpj)) {
            $faltando[] = 'CPF ou CNPJ do cliente';
        }

        $email = trim((string) ($cliente->email_nfe ?: $cliente->email));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $faltando[] = 'E-mail para nota fiscal';
        }

        if (blank(preg_replace('/\D/', '', (string) $cliente->phone))) {
            $faltando[] = 'Telefone do cliente';
        }

        if (! $endereco instanceof Address) {
            $faltando[] = 'Endereço do cliente';
        } else {
            $campos = [
                'street' => 'Logradouro do endereço do cliente',
                'number' => 'Número do endereço do cliente',
                'district' => 'Bairro do endereço do cliente',
                'city' => 'Cidade do endereço do cliente',
                'state' => 'Estado do endereço do cliente',
                'zip' => 'CEP do endereço do cliente',
            ];

            foreach ($campos as $atributo => $rotulo) {
                if (blank(trim((string) $endereco->{$atributo}))) {
                    $faltando[] = $rotulo;
                }
            }

            $codigoIbge = preg_replace('/\D/', '', (string) $endereco->codigo_municipio_ibge) ?? '';

            if (preg_match('/^\d{7}$/', $codigoIbge) !== 1) {
                $faltando[] = 'Código do município (IBGE) do endereço do cliente';
            }
        }

        $documento = preg_replace('/\D/', '', (string) $cliente->cnpj) ?? '';

        if (strlen($documento) === 14
            && $configuracao->exige_inscricao_municipal_tomador
            && blank(trim((string) $cliente->inscricao_municipal))) {
            $faltando[] = 'Inscrição municipal';
        }

        return array_values(array_unique($faltando));
    }

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

        return $cpf[9] === (string) $this->digitoCpf($cpf, 9, 10)
            && $cpf[10] === (string) $this->digitoCpf($cpf, 10, 11);
    }

    private function cnpjValido(string $cnpj): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $cnpj) === 1) {
            return false;
        }

        $primeiro = $this->digitoCnpj($cnpj, [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);
        $segundo = $this->digitoCnpj($cnpj, [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2]);

        return $cnpj[12] === (string) $primeiro && $cnpj[13] === (string) $segundo;
    }

    private function digitoCpf(string $cpf, int $tamanho, int $peso): int
    {
        $soma = 0;

        for ($indice = 0; $indice < $tamanho; $indice++) {
            $soma += (int) $cpf[$indice] * ($peso - $indice);
        }

        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }

    /** @param array<int, int> $pesos */
    private function digitoCnpj(string $cnpj, array $pesos): int
    {
        $soma = 0;

        foreach ($pesos as $indice => $peso) {
            $soma += (int) $cnpj[$indice] * $peso;
        }

        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }
}
