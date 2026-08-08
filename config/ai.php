<?php

use App\Services\Ai\ProvedorAnthropic;

return [

    /*
    |--------------------------------------------------------------------------
    | Provedor de geração de texto (Plano 25)
    |--------------------------------------------------------------------------
    |
    | Classe que implementa `App\Services\Ai\ProvedorDeTexto`. É resolvida em
    | `AppServiceProvider::registrarProvedorDeTexto()`, no mesmo critério já
    | usado para o gateway de assinatura e para a geocodificação: trocar de
    | provedor não pode tocar em nenhum Service acima da interface.
    |
    | A suíte de testes troca este valor por uma implementação falsa. Nenhum
    | teste chama a API real.
    |
    */

    'provedor' => env('AI_PROVEDOR', ProvedorAnthropic::class),

    /*
    |--------------------------------------------------------------------------
    | Modelo padrão
    |--------------------------------------------------------------------------
    |
    | Gravado em cada rascunho (`ai_drafts.modelo`) e em cada chamada
    | (`ai_usages.modelo`): quando o modelo mudar, é preciso saber o que foi
    | gerado com qual versão. Identificador exato, sem sufixo de data.
    |
    */

    'modelo' => env('AI_MODELO', 'claude-opus-5'),

    /*
    |--------------------------------------------------------------------------
    | Teto de saída por chamada
    |--------------------------------------------------------------------------
    |
    | 4096 é folgado para um parecer técnico ou um resumo de período. Acima de
    | 16000 a chamada precisaria ser feita em streaming para não esbarrar no
    | tempo limite de HTTP; nenhum uso deste plano chega perto disso.
    |
    | Vale para o total gerado (raciocínio do modelo mais texto da resposta).
    |
    */

    'max_tokens' => (int) env('AI_MAX_TOKENS', 4096),

    /*
    |--------------------------------------------------------------------------
    | Esforço
    |--------------------------------------------------------------------------
    |
    | Regula profundidade de raciocínio e gasto de token: low, medium, high,
    | xhigh, max. `medium` é o padrão daqui porque a tarefa é escrever um texto
    | curto a partir de dado que já chega estruturado, e não resolver um
    | problema aberto. Quem quiser mais rigor sobe para `high` sem tocar em
    | código.
    |
    */

    'esforco' => env('AI_ESFORCO', 'medium'),

    /*
    |--------------------------------------------------------------------------
    | Tempo limite da chamada, em segundos
    |--------------------------------------------------------------------------
    */

    'tempo_limite_segundos' => (int) env('AI_TEMPO_LIMITE', 120),

    /*
    |--------------------------------------------------------------------------
    | Anthropic
    |--------------------------------------------------------------------------
    |
    | A chave é da plataforma, nunca do tenant: uma conta só, e o custo é
    | apurado por empresa em `ai_usages` (Task 25.5). Ela vem exclusivamente da
    | variável de ambiente, nunca é gravada no banco, nunca aparece em log e
    | nunca volta por endpoint.
    |
    | `fallback` liga o desvio automático do provedor quando o modelo recusa a
    | geração por política: a chamada é refeita no servidor deles, em um modelo
    | equivalente, dentro da mesma requisição. Custa nada quando não acontece e
    | evita que um falso positivo de classificador derrube a geração.
    |
    */

    'anthropic' => [
        'chave' => env('ANTHROPIC_API_KEY'),
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com'),
        'versao' => env('ANTHROPIC_VERSAO', '2023-06-01'),
        'fallback' => (bool) env('ANTHROPIC_FALLBACK', true),
        'beta_fallback' => 'server-side-fallback-2026-07-01',
    ],

    /*
    |--------------------------------------------------------------------------
    | Tabela de preço por modelo (Plano 25, Task 25.5)
    |--------------------------------------------------------------------------
    |
    | Dólar por milhão de tokens, como o provedor publica. Fica em
    | configuração, e não em código, porque preço de modelo muda sem aviso e
    | sem relação nenhuma com o sistema: atualizar a tabela não pode exigir
    | deploy de lógica.
    |
    | As quatro tarifas são distintas de propósito:
    |
    | - `entrada`: token do prompt que o provedor teve de processar.
    | - `saida`: token gerado, o mais caro (raciocínio do modelo entra aqui).
    | - `cache_leitura`: token servido do prefixo cacheado, um décimo da
    |   entrada. É a tarifa que torna o recurso viável, já que o prefixo de
    |   sistema se repete em toda geração.
    | - `cache_escrita`: token gravado no cache, 1,25x a entrada na validade
    |   padrão de cinco minutos. Custa um pouco mais que a entrada comum, e
    |   se paga na segunda chamada com o mesmo prefixo.
    |
    | Somar leitura de cache à entrada esconderia justamente o efeito do
    | cache na conta, que é o número que o plano quer conhecer antes de o
    | recurso virar item comercial.
    |
    | `claude-opus-4-8` está aqui mesmo não sendo o modelo padrão: é para onde
    | o desvio automático (`anthropic.fallback`) manda a chamada quando o
    | modelo recusa por política, e uso servido por ele precisa ser apurado
    | pela tarifa dele.
    |
    | Preços conferidos em 24/06/2026. `claude-sonnet-5` tem preço
    | promocional de 2.00/10.00 até 31/08/2026; a tabela usa o preço cheio
    | de propósito, para a apuração não subestimar o custo quando a promoção
    | acabar.
    |
    */

    'moeda' => 'USD',

    'precos' => [
        'claude-opus-5' => [
            'entrada' => 5.00,
            'saida' => 25.00,
            'cache_leitura' => 0.50,
            'cache_escrita' => 6.25,
        ],
        'claude-opus-4-8' => [
            'entrada' => 5.00,
            'saida' => 25.00,
            'cache_leitura' => 0.50,
            'cache_escrita' => 6.25,
        ],
        'claude-sonnet-5' => [
            'entrada' => 3.00,
            'saida' => 15.00,
            'cache_leitura' => 0.30,
            'cache_escrita' => 3.75,
        ],
        'claude-haiku-4-5' => [
            'entrada' => 1.00,
            'saida' => 5.00,
            'cache_leitura' => 0.10,
            'cache_escrita' => 1.25,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Teto de gerações por tenant, por mês
    |--------------------------------------------------------------------------
    |
    | Chave é o slug do plano comercial; `padrao` vale para quem não estiver
    | listado, e `null` é sem teto.
    |
    | Fica em configuração, e não em coluna de `plans`, porque este teto ainda
    | não é item comercial: o plano manda medir antes de vender, e enquanto o
    | custo por tenant não for conhecido o número certo não existe. Quando
    | virar item de plano, vira coluna, junto com os outros limites da
    | Task 6.5.
    |
    | O teto recusa **apenas a geração**. Ordem de serviço, certificado e
    | financeiro nunca param por causa dele: limite de um recurso opcional que
    | derruba o sistema é pior que não ter limite.
    |
    */

    'teto_de_geracoes_por_mes' => [
        'padrao' => (int) env('AI_TETO_GERACOES_MES', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Aviso de custo ao super admin
    |--------------------------------------------------------------------------
    |
    | Custo mensal somado de todos os tenants, em dólar, a partir do qual a
    | área de plataforma passa a avisar. Zero desliga o aviso.
    |
    */

    'aviso_de_custo_mensal' => (float) env('AI_AVISO_CUSTO_MENSAL', 50.0),

];
