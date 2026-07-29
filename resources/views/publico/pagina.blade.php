{{--
    Casca das páginas públicas, sem autenticação (Plano 16, Task 16.3).

    É a mesma casca de `resources/views/app.blade.php`, com uma diferença
    deliberada: `@routes('publico')` no lugar de `@routes`. O segundo imprime a
    lista inteira de rotas nomeadas do sistema no HTML, e esta página é aberta
    por qualquer pessoa da internet. Ver o motivo em `config/ziggy.php`.

    O título fica genérico de propósito: quem define o texto da aba é a própria
    página Inertia, com `<Head>` (Task 16.6), e ali dá para usar o nome do
    tenant.

    `noindex` é o padrão seguro desta casca porque ela também serve a página de
    resposta da pesquisa de satisfação (Task 16.5), cuja URL carrega um token de
    uso único: link desse tipo em resultado de busca é vazamento. Se algum dia a
    empresa quiser a página de agendamento indexada, o certo é liberar por página
    (`<Head>`), nunca tirar daqui.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex">

    <title>{{ config('app.name', 'Sistema de Certificados') }}</title>

    <link rel="icon" type="image/png" href="{{ asset('images/logo.png') }}">
    <link rel="shortcut icon" type="image/png" href="{{ asset('images/logo.png') }}">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @routes('publico')
</head>

<body class="font-sans antialiased bg-gray-50">
    @inertia
</body>

</html>
