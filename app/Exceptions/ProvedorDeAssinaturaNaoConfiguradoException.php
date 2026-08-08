<?php

namespace App\Exceptions;

use App\Models\Company;
use RuntimeException;

/**
 * A empresa não tem provedor de assinatura eletrônica configurado e ativo
 * (Plano 26, Task 26.2).
 *
 * Lançada por `App\Services\Signature\ResolvedorDeProvedor::para()`. É o caso
 * de um tenant que ainda não cadastrou credencial nenhuma, ou cadastrou e
 * deixou `ativo = false`. Mensagem em português, sem nenhum dado sensível: não
 * há credencial neste ponto, porque a busca não encontrou configuração.
 *
 * Mesmo desenho de `GatewayNaoConfiguradoException` (Plano 19).
 */
class ProvedorDeAssinaturaNaoConfiguradoException extends RuntimeException
{
    public static function paraEmpresa(Company $empresa): self
    {
        return new self(sprintf(
            'A empresa "%s" não tem nenhum provedor de assinatura eletrônica configurado e ativo. '
            .'Configure e ative uma credencial na tela de integração antes de enviar contrato para assinatura.',
            $empresa->name ?? ('#'.$empresa->getKey())
        ));
    }
}
