<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação da coleta de assinatura do cliente feita no computador do
 * escritório (Plano 13, Task 13.4).
 *
 * `imagem` é o PNG do canvas em base64: o limite de 300 KB validado aqui é o
 * tamanho da própria string base64 recebida, não o do binário decodificado
 * (assinatura de canvas em traço monocromático não passa disso, e um valor
 * maior indica que alguém está enviando outra coisa).
 */
class StoreWorkOrderSignatureRequest extends FormRequest
{
    /**
     * Tamanho máximo, em bytes, da string base64 da imagem da assinatura.
     */
    private const TAMANHO_MAXIMO_IMAGEM = 300 * 1024;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assinante_nome' => 'required|string|max:255',
            // Opcional: nem todo cliente informa documento, e recusar a
            // assinatura por falta de CPF deixaria a OS sem comprovação
            // nenhuma. Quando vier preenchido, precisa ser um CPF ou CNPJ
            // válido pelo dígito verificador.
            'assinante_documento' => [
                'nullable',
                'string',
                'max:20',
                function ($attribute, $value, $fail) {
                    if ($value === null || trim((string) $value) === '') {
                        return;
                    }

                    if (! $this->documentoValido((string) $value)) {
                        $fail('O CPF ou CNPJ informado não é válido.');
                    }
                },
            ],
            'assinante_vinculo' => 'nullable|string|max:255',
            'imagem' => [
                'required',
                'string',
                function ($attribute, $value, $fail) {
                    if (strlen((string) $value) > self::TAMANHO_MAXIMO_IMAGEM) {
                        $fail('A imagem da assinatura não pode passar de 300 KB.');
                    }
                },
            ],
            'coletada_em' => 'required|date|before_or_equal:now',
            'latitude' => 'nullable|numeric|between:-90,90',
            'longitude' => 'nullable|numeric|between:-180,180',
            'precisao_metros' => 'nullable|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'assinante_nome.required' => 'Informe o nome de quem está assinando.',
            'assinante_nome.max' => 'O nome não pode ter mais de 255 caracteres.',
            'assinante_documento.max' => 'O documento informado é inválido.',
            'imagem.required' => 'A imagem da assinatura é obrigatória.',
            'coletada_em.required' => 'Informe o instante da coleta da assinatura.',
            'coletada_em.date' => 'O instante da coleta é inválido.',
            'coletada_em.before_or_equal' => 'O instante da coleta não pode estar no futuro.',
            'latitude.between' => 'A latitude informada é inválida.',
            'longitude.between' => 'A longitude informada é inválida.',
        ];
    }

    /**
     * CPF (11 dígitos) ou CNPJ (14 dígitos) válido pelo dígito verificador.
     *
     * Não há regra reutilizável no projeto até esta task: os lugares que hoje
     * mexem com CPF/CNPJ (`ClientRequest`, `App\Models\User`) validam só
     * formato e unicidade, nunca o dígito verificador.
     */
    private function documentoValido(string $documento): bool
    {
        $numeros = preg_replace('/\D/', '', $documento) ?? '';

        if (strlen($numeros) === 11) {
            return $this->cpfValido($numeros);
        }

        if (strlen($numeros) === 14) {
            return $this->cnpjValido($numeros);
        }

        return false;
    }

    private function cpfValido(string $cpf): bool
    {
        if (preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($posicao = 9; $posicao < 11; $posicao++) {
            $soma = 0;

            for ($indice = 0; $indice < $posicao; $indice++) {
                $soma += (int) $cpf[$indice] * (($posicao + 1) - $indice);
            }

            $digitoEsperado = ((10 * $soma) % 11) % 10;

            if ((int) $cpf[$posicao] !== $digitoEsperado) {
                return false;
            }
        }

        return true;
    }

    private function cnpjValido(string $cnpj): bool
    {
        if (preg_match('/^(\d)\1{13}$/', $cnpj)) {
            return false;
        }

        $pesosPrimeiroDigito = [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
        $pesosSegundoDigito = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

        if ((int) $cnpj[12] !== $this->digitoVerificadorCnpj($cnpj, $pesosPrimeiroDigito, 12)) {
            return false;
        }

        if ((int) $cnpj[13] !== $this->digitoVerificadorCnpj($cnpj, $pesosSegundoDigito, 13)) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<int, int>  $pesos
     */
    private function digitoVerificadorCnpj(string $cnpj, array $pesos, int $quantidadeDigitos): int
    {
        $soma = 0;

        for ($indice = 0; $indice < $quantidadeDigitos; $indice++) {
            $soma += (int) $cnpj[$indice] * $pesos[$indice];
        }

        $resto = $soma % 11;

        return $resto < 2 ? 0 : 11 - $resto;
    }
}
