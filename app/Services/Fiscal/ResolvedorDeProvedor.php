<?php

namespace App\Services\Fiscal;

use App\Models\FiscalConfig;
use App\Support\TenantAtual;
use RuntimeException;

class ResolvedorDeProvedor
{
    /** @var array<string, class-string<ProvedorDeNfse>> */
    private const IMPLEMENTACOES = [
        ProvedorPadrao::NOME => ProvedorPadrao::class,
    ];

    public function para(): ProvedorDeNfse
    {
        return $this->paraConfiguracao($this->configuracaoAtiva());
    }

    public function paraConfiguracao(FiscalConfig $configuracao): ProvedorDeNfse
    {
        $classe = self::IMPLEMENTACOES[$configuracao->provedor] ?? null;

        if ($classe === null) {
            throw new RuntimeException(sprintf(
                'O provedor fiscal "%s" não possui implementação registrada.',
                $configuracao->provedor,
            ));
        }

        return app($classe);
    }

    public function configuracaoAtiva(): FiscalConfig
    {
        $companyId = TenantAtual::exigirId();
        $configuracoes = FiscalConfig::query()
            ->where('company_id', $companyId)
            ->where('ativo', true)
            ->limit(2)
            ->get();

        if ($configuracoes->isEmpty()) {
            throw new RuntimeException(
                'A empresa atual não possui configuração fiscal ativa. '
                .'Configure e ative a integração de NFS-e antes de emitir uma nota.',
            );
        }

        if ($configuracoes->count() > 1) {
            throw new RuntimeException(
                'A empresa possui mais de uma configuração fiscal ativa. Mantenha somente uma configuração ativa antes de emitir notas.',
            );
        }

        return $configuracoes->first();
    }

    public function validar(FiscalConfig $configuracao): bool
    {
        return $this->paraConfiguracao($configuracao)->validarCredenciais($configuracao);
    }
}
