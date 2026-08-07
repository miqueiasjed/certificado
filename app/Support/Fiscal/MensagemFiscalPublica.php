<?php

namespace App\Support\Fiscal;

use App\Exceptions\FalhaFiscalException;
use Illuminate\Support\Facades\Log;
use Throwable;

final class MensagemFiscalPublica
{
    public const FALHA_INTERNA = 'Não foi possível processar a operação fiscal. Tente novamente em alguns minutos.';

    public static function deFalha(Throwable $falha, array $contexto = []): string
    {
        if ($falha instanceof FalhaFiscalException) {
            return $falha->getMessage();
        }

        Log::error('[fiscal] Falha interna ocultada da resposta pública.', [
            ...$contexto,
            'exception' => $falha,
        ]);

        return self::FALHA_INTERNA;
    }

    public static function deTextoPersistido(?string $mensagem): ?string
    {
        $mensagem = trim((string) $mensagem);

        if ($mensagem === '') {
            return null;
        }

        if (self::contemDetalheInterno($mensagem)) {
            Log::warning('[fiscal] Mensagem interna persistida foi ocultada da resposta pública.');

            return self::FALHA_INTERNA;
        }

        return $mensagem;
    }

    private static function contemDetalheInterno(string $mensagem): bool
    {
        // Registros antigos não guardam o tipo da exceção. Esta lista reduz a
        // exposição conhecida, sem tentar classificar todo texto histórico.
        return preg_match(
            '~(?:SQLSTATE|PDOException|QueryException|Stack trace|Undefined array key|Call to undefined method|Connection refused|tcp://|vendor/|app/|storage/|\.php:\d+|/(?:var|tmp|home|Users)/|(?:select|insert|update|delete)\s+.+\s+from\s+)~i',
            $mensagem,
        ) === 1;
    }
}
