<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Gateway de assinatura (Plano 7)
    |--------------------------------------------------------------------------
    |
    | Classe que implementa App\Contracts\GatewayAssinatura, ligada pelo
    | AppServiceProvider. PagBank (App\Services\Gateway\PagBankGateway) é o
    | provedor de hoje, implementado na Task 7.3; a variável de ambiente
    | existe para permitir trocar de provedor, ou usar um dublê em ambiente
    | sem credencial, sem mudar código.
    |
    */

    'gateway_assinatura' => env('GATEWAY_ASSINATURA_DRIVER', \App\Services\Gateway\PagBankGateway::class),

    /*
    |--------------------------------------------------------------------------
    | PagBank (Plano 7, Task 7.3)
    |--------------------------------------------------------------------------
    |
    | Credencial e endereços do provedor de hoje. Nada aqui vai para log, para
    | mensagem de erro ou para resposta de API: `PagBankGateway` registra só o
    | caminho do endpoint, o status e o tempo da chamada.
    |
    | São DUAS famílias de API, com hosts diferentes, e não é escolha do
    | projeto: assinatura recorrente vive em `api.assinaturas.pagseguro.com`,
    | e a cobrança avulsa de uma fatura (Pix e boleto) vive em
    | `api.pagseguro.com`, no recurso `/orders`. A API de assinaturas não
    | oferece Pix nem emite cobrança sob demanda, então as duas precisam
    | coexistir.
    |
    | `sandbox` é `true` por padrão de propósito: ambiente sem a variável
    | definida é ambiente de desenvolvimento, e o erro de esquecer a chave
    | precisa cair no lado que não cobra ninguém de verdade. Subir produção
    | com sandbox ligado é erro de configuração, e o comando de diagnóstico do
    | Plano 7 mostra o valor efetivo.
    |
    */

    'pagbank' => [
        // Assinatura recorrente: /plans, /customers, /subscriptions.
        'base_url_sandbox' => env('PAGBANK_BASE_URL_SANDBOX', 'https://sandbox.api.assinaturas.pagseguro.com'),
        'base_url_producao' => env('PAGBANK_BASE_URL_PRODUCAO', 'https://api.assinaturas.pagseguro.com'),

        // Cobrança avulsa de fatura (Pix e boleto): /orders.
        'base_url_pedidos_sandbox' => env('PAGBANK_BASE_URL_PEDIDOS_SANDBOX', 'https://sandbox.api.pagseguro.com'),
        'base_url_pedidos_producao' => env('PAGBANK_BASE_URL_PEDIDOS_PRODUCAO', 'https://api.pagseguro.com'),

        'token' => env('PAGBANK_TOKEN'),

        // Token de autenticidade do webhook. O PagBank manda no cabeçalho
        // `x-authenticity-token` o SHA-256 de `token + "-" + corpo cru`; sem
        // este valor configurado, `validarWebhook()` recusa tudo, que é o
        // comportamento desejado: sem validação, qualquer um confirma
        // pagamento por POST.
        'webhook_token' => env('PAGBANK_WEBHOOK_TOKEN'),

        // Para onde o PagBank avisa a mudança de estado de uma cobrança
        // avulsa. Vai no corpo do pedido (`notification_urls`), diferente do
        // webhook de assinatura, que é cadastrado no painel do provedor.
        // Nulo em desenvolvimento: sem URL pública não há o que notificar.
        'webhook_url' => env('PAGBANK_WEBHOOK_URL'),

        'sandbox' => (bool) env('PAGBANK_SANDBOX', true),

        // Segundos. Explícito porque o padrão do cliente HTTP é esperar
        // indefinidamente, e requisição de checkout presa é pior que
        // requisição que falha rápido e é repetida.
        'timeout' => (int) env('PAGBANK_TIMEOUT', 20),
    ],

    /*
    |--------------------------------------------------------------------------
    | Geocodificação de endereços (Plano 22, Task 22.2)
    |--------------------------------------------------------------------------
    |
    | Classe que implementa App\Services\Geo\ProvedorDeGeocodificacao, ligada
    | pelo AppServiceProvider. Nominatim (OpenStreetMap) é o provedor de hoje
    | (App\Services\Geo\ProvedorNominatim): gratuito, sem chave de API - ver o
    | cabeçalho daquela classe para o porquê. Trocar para um provedor pago
    | (Google Maps, Mapbox etc.) é criar outra classe atrás da mesma
    | interface e mudar GEOCODIFICACAO_PROVEDOR_DRIVER, sem tocar em
    | GeocodificacaoService nem no comando enderecos:geocodificar.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Assinatura eletrônica de contratos (Plano 26, Task 26.2)
    |--------------------------------------------------------------------------
    |
    | Só os ENDEREÇOS do provedor moram aqui. A credencial NÃO: ela é por
    | tenant, cifrada em `signature_provider_configs.credenciais`
    | (App\Models\SignatureProviderConfig), porque a conta com o provedor é da
    | empresa — a plataforma não assina contrato de ninguém. Mesmo desenho da
    | cobrança do tenant ao cliente final (Plano 19), e diferente da assinatura
    | que o tenant paga à plataforma (Plano 7), que usa `pagbank.token`.
    |
    | Qual ambiente vale (sandbox ou produção) também não é decisão da
    | plataforma: vem de `signature_provider_configs.ambiente`, tenant a
    | tenant, para que uma empresa possa testar o ciclo inteiro enquanto outra
    | já assina de verdade. Documento assinado em sandbox NÃO tem validade
    | jurídica, e a tela mostra o aviso enquanto o ambiente for esse.
    |
    */

    'zapsign' => [
        'base_url_sandbox' => env('ZAPSIGN_BASE_URL_SANDBOX', 'https://sandbox.api.zapsign.com.br'),
        'base_url_producao' => env('ZAPSIGN_BASE_URL_PRODUCAO', 'https://api.zapsign.com.br'),
    ],

    'geocodificacao' => [
        'provedor' => env('GEOCODIFICACAO_PROVEDOR_DRIVER', \App\Services\Geo\ProvedorNominatim::class),

        'nominatim' => [
            'url' => env('GEOCODIFICACAO_NOMINATIM_URL', 'https://nominatim.openstreetmap.org/search'),

            // Nominatim exige um User-Agent identificando a aplicação
            // (política de uso deles); sem valor configurado, cai para o
            // nome e a URL da aplicação (config('app.name') / config('app.url')).
            'user_agent' => env('GEOCODIFICACAO_USER_AGENT'),

            'timeout' => (int) env('GEOCODIFICACAO_TIMEOUT', 10),
        ],
    ],

];
