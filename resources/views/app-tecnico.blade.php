<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">

    <title>Aplicativo do Técnico - Sistema de Certificados</title>

    {{--
        Casca do aplicativo do técnico (Plano 12, Task 12.6).

        Esta view fica fora do layout Inertia do painel web (resources/views/app.blade.php):
        não tem `@inertia`, não carrega `resources/js/app.js` e não depende de sessão web.
        O aplicativo abre com este HTML/CSS/JS em precache pelo service worker
        (vite.config.js), o que é o que permite abrir sem rede.

        HTTPS é obrigatório em produção para o service worker e para a câmera
        (Task 12.11) funcionarem. Sem HTTPS, o navegador não instala nem registra
        nada disto - ver checagem no roteiro de deploy do Plano 12 (.claude/tasks/12/INDEX.md).
    --}}

    <link rel="manifest" href="{{ asset('manifest.webmanifest') }}">
    <meta name="theme-color" content="#059669">

    {{-- Favicon: reaproveita o mesmo do painel web. --}}
    <link rel="icon" type="image/png" href="{{ asset('images/logo.png') }}">

    {{--
        Ícones do PWA e suporte a iOS.

        PLACEHOLDER: `icon-192.png`, `icon-512.png` e `icon-512-maskable.png`
        (public/images/pwa/) foram gerados por script para esta task, não são a
        arte final. Substituir pelos ícones definitivos do aplicativo assim que
        a identidade visual for fechada; o manifesto e as tags abaixo continuam
        válidos, só trocando os arquivos de imagem.
    --}}
    <link rel="apple-touch-icon" href="{{ asset('images/pwa/icon-192.png') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="App Técnico">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

    <!-- Scripts -->
    {{--
        Ponto de entrada próprio (resources/js/app-tecnico.js), e não
        resources/js/app.js: o aplicativo do técnico não carrega o bundle do
        painel Inertia, só o que precisa para funcionar offline.
    --}}
    @vite(['resources/css/app.css', 'resources/js/app-tecnico.js'])
</head>

<body class="font-sans antialiased bg-gray-50">
    <div id="app-tecnico"></div>
</body>

</html>
