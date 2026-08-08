<?php

namespace App\Support\Ai;

/**
 * Resultado de uma geração de texto, no vocabulário do domínio (Plano 25).
 *
 * Existe para que nada acima de `App\Services\Ai\ProvedorDeTexto` conheça o
 * formato de resposta de um provedor específico. Trocar de provedor (ou de
 * versão de API) deve mudar só a implementação da interface, nunca quem a
 * consome.
 *
 * Os quatro contadores de token são separados de propósito, porque têm preços
 * diferentes: token de entrada comum, token de saída, token lido do cache de
 * prefixo (fração do preço da entrada) e token gravado no cache (um pouco mais
 * caro que a entrada). Somar tudo em um número só esconderia justamente o
 * efeito do prefixo cacheado, que é o que torna o custo do recurso viável.
 */
final readonly class RespostaDeTexto
{
    public function __construct(
        public string $texto,
        public string $modelo,
        public int $tokensEntrada = 0,
        public int $tokensSaida = 0,
        public int $tokensCacheLeitura = 0,
        public int $tokensCacheEscrita = 0,
        public int $duracaoMs = 0,
    ) {}

    /**
     * O prefixo de sistema foi servido do cache nesta chamada?
     *
     * Usado na conferência manual descrita na Task 25.2: duas gerações
     * seguidas com o mesmo prefixo, e a segunda precisa ler do cache.
     */
    public function leuDoCache(): bool
    {
        return $this->tokensCacheLeitura > 0;
    }
}
