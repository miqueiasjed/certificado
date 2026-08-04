<?php

namespace App\Exceptions;

use App\Models\Company;
use RuntimeException;

/**
 * A empresa não tem gateway de cobrança configurado e ativo (Plano 19, Task
 * 19.2).
 *
 * Lançada por `App\Services\Payments\ResolvedorDeGateway::para()`. É o caso
 * de um tenant que ainda não cadastrou credencial nenhuma, ou cadastrou e
 * deixou `ativo = false`. Mensagem em português, sem nenhum dado sensível:
 * não há credencial nenhuma neste ponto, porque a busca não encontrou
 * configuração.
 */
class GatewayNaoConfiguradoException extends RuntimeException
{
    public static function paraEmpresa(Company $empresa): self
    {
        return new self(sprintf(
            'A empresa "%s" não tem nenhum gateway de cobrança configurado e ativo. '
            .'Configure e ative uma credencial na tela de integração antes de emitir cobrança.',
            $empresa->name ?? ('#'.$empresa->getKey())
        ));
    }
}
