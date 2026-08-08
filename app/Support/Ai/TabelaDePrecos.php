<?php

namespace App\Support\Ai;

/**
 * Custo de uma chamada ao modelo, a partir da tabela de preço da configuração
 * (Plano 25).
 *
 * Fica em `App\Support` porque duas camadas precisam da mesma conta e não
 * podem depender uma da outra: `ProvedorAnthropic` calcula o custo no momento
 * de gravar `ai_usages`, e `MedicaoDeUsoService` recalcula ao apurar consumo
 * por competência. Duas implementações da mesma tarifa divergiriam no primeiro
 * reajuste de preço, e a divergência apareceria como custo apurado diferente
 * do custo gravado, sem nada apontando a causa.
 *
 * As quatro tarifas são separadas de propósito: token lido do cache custa uma
 * fração do token de entrada, e é justamente esse desconto que torna o recurso
 * viável. Somar tudo esconderia o efeito do cache na conta.
 *
 * Utilitário de infraestrutura: sem regra de domínio e sem escrita em banco.
 */
final class TabelaDePrecos
{
    /**
     * Custo em dólar de uma chamada, com seis casas decimais.
     *
     * Modelo fora da tabela devolve zero em vez de estimar por analogia com
     * outro: custo inventado é pior que custo ausente, porque parece apurado.
     * A contagem de token continua sendo gravada de qualquer forma, e é ela
     * que não dá para reconstruir depois.
     *
     * @param  array{entrada?: int, saida?: int, cache_leitura?: int, cache_escrita?: int}  $tokens
     */
    public static function custoDaChamada(string $modelo, array $tokens): float
    {
        $precos = config('ai.precos.'.$modelo);

        if (! is_array($precos)) {
            return 0.0;
        }

        $porMilhao = static fn (int $quantidade, float $preco): float => ($quantidade / 1_000_000) * $preco;

        return round(
            $porMilhao((int) ($tokens['entrada'] ?? 0), (float) ($precos['entrada'] ?? 0))
            + $porMilhao((int) ($tokens['saida'] ?? 0), (float) ($precos['saida'] ?? 0))
            + $porMilhao((int) ($tokens['cache_leitura'] ?? 0), (float) ($precos['cache_leitura'] ?? 0))
            + $porMilhao((int) ($tokens['cache_escrita'] ?? 0), (float) ($precos['cache_escrita'] ?? 0)),
            6
        );
    }
}
