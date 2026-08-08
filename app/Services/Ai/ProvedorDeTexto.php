<?php

namespace App\Services\Ai;

use App\Support\Ai\RespostaDeTexto;

/**
 * Contrato do provedor de geração de texto (Plano 25).
 *
 * A fronteira existe por dois motivos:
 *
 * 1. **Trocar de provedor** não pode tocar em nada acima desta interface.
 *    `RascunhoDeParecerService`, `SugestaoDePrecoService` e os controllers
 *    dependem só do contrato.
 * 2. **A suíte não chama a API real.** O teste liga uma implementação falsa no
 *    container e verifica o que foi enviado — inclusive que nenhum dado de
 *    outra empresa entrou no contexto.
 *
 * Nenhuma implementação guarda credencial de tenant: a conta é da plataforma,
 * a chave vem de `config('ai.anthropic.chave')`, e o custo é medido por tenant
 * em `ai_usages`.
 */
interface ProvedorDeTexto
{
    /**
     * Gera texto a partir de um prefixo de sistema estável e de uma entrada
     * variável.
     *
     * `$sistema` é o bloco de instruções que se repete em toda geração e é
     * cacheado pelo provedor. Precisa ser byte a byte idêntico entre chamadas:
     * interpolar data, nome de empresa ou qualquer identificador nele invalida
     * o cache e multiplica a conta.
     *
     * `$entrada` é o que varia — os dados da origem, montados por
     * `MontadorDeContexto`.
     *
     * @param  array<string, mixed>  $opcoes  Ajustes pontuais reconhecidos pela
     *                                        implementação (por exemplo
     *                                        `max_tokens`, `esforco`, `tipo`).
     *                                        Nunca aceita parâmetro de
     *                                        amostragem: o modelo em uso recusa
     *                                        `temperature`, `top_p` e `top_k`
     *                                        com erro 400.
     *
     * @throws \App\Exceptions\IaIndisponivelException Falha de rede ou 5xx: pode repetir.
     * @throws \App\Exceptions\IaLimiteDeTaxaException Limite de chamadas do provedor: repetir depois.
     * @throws \App\Exceptions\IaRecusouException O provedor disse não: não repetir a mesma entrada.
     */
    public function gerar(string $sistema, string $entrada, array $opcoes = []): RespostaDeTexto;

    /**
     * Identificador do modelo que esta instância usa por padrão.
     *
     * Gravado em cada rascunho e em cada uso: quando o modelo mudar, é preciso
     * saber o que foi gerado com qual versão.
     */
    public function modelo(): string;
}
