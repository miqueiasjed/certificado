<?php

namespace App\Services\AvisosAtualizacao;

/**
 * Versão do frontend publicado, derivada do manifesto do Vite.
 *
 * O manifesto muda sempre que JS ou CSS mudam, porque os nomes dos arquivos levam o hash do
 * conteúdo. Build igual gera manifesto igual e não força recarga.
 */
final class VersaoDoBuild
{
    private string $manifesto;

    private string $arquivoHot;

    public function __construct(?string $manifesto = null, ?string $arquivoHot = null)
    {
        $this->manifesto = $manifesto ?? public_path('build/manifest.json');
        $this->arquivoHot = $arquivoHot ?? public_path('hot');
    }

    /**
     * Devolve 12 caracteres hexadecimais, ou null quando não há versão fixa: em desenvolvimento
     * (arquivo hot do `npm run dev`) ou sem build (testes, ambiente sem manifesto).
     */
    public function atual(): ?string
    {
        if (is_file($this->arquivoHot) || ! is_file($this->manifesto)) {
            return null;
        }

        $hash = @md5_file($this->manifesto);

        return $hash === false ? null : substr($hash, 0, 12);
    }
}
