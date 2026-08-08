<?php

namespace App\Support\Signature;

use App\Exceptions\AssinaturaEletronicaSignatarioInvalidoException;
use InvalidArgumentException;

/**
 * Um signatário no momento de enviar o contrato para assinatura (Plano 26,
 * Task 26.2).
 *
 * Existe como objeto, e não como array solto, por um motivo concreto: é ele
 * que carrega o e-mail para onde o contrato vai. Array solto com chave errada
 * vira `null` silencioso e o contrato sai para o endereço errado — ou não sai.
 * Aqui a validação de formato acontece na construção, **antes** da ida à rede,
 * e vira `AssinaturaEletronicaSignatarioInvalidoException` com o nome de quem
 * está com o e-mail errado.
 *
 * `ordem` existe porque alguns contratos exigem que a contratada assine antes
 * de o documento chegar ao cliente. Signatários com a mesma ordem assinam em
 * paralelo.
 *
 * Imutável e sem dependência de framework.
 */
final class SignatarioParaEnvio
{
    /**
     * Mesma lista do enum de `signature_signers.papel`.
     *
     * @var array<int, string>
     */
    public const PAPEIS = ['contratante', 'contratada', 'testemunha'];

    /**
     * @param  string  $nome  Nome de quem assina, como aparece no documento.
     * @param  string  $email  Endereço para onde o convite de assinatura é enviado. Validado aqui, antes da rede.
     * @param  string  $papel  Um dos `self::PAPEIS`.
     * @param  int  $ordem  Ordem de assinatura, a partir de 1. Mesma ordem significa assinatura em paralelo.
     * @param  string|null  $documento  CPF ou CNPJ, quando o provedor for conferir a identidade.
     *
     * @throws AssinaturaEletronicaSignatarioInvalidoException E-mail que não é um endereço válido.
     */
    public function __construct(
        public readonly string $nome,
        public readonly string $email,
        public readonly string $papel,
        public readonly int $ordem = 1,
        public readonly ?string $documento = null,
    ) {
        if (! in_array($papel, self::PAPEIS, true)) {
            throw new InvalidArgumentException(
                "Papel de signatário desconhecido: \"{$papel}\". "
                .'Papéis válidos: '.implode(', ', self::PAPEIS).'.'
            );
        }

        if ($ordem < 1) {
            throw new InvalidArgumentException('A ordem de assinatura começa em 1.');
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw AssinaturaEletronicaSignatarioInvalidoException::emailInvalido($nome, $email);
        }
    }
}
