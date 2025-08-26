<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'Sistema de Certificados') }}</title>

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700" rel="stylesheet" />

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <!-- Vue.js CDN para desenvolvimento -->
    <script src="https://unpkg.com/vue@3/dist/vue.global.js"></script>
</head>
<body class="font-sans antialiased bg-gray-50">
    <div id="app">
        <!-- Navigation -->
        <nav class="bg-green-600 border-b border-green-700">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex justify-between h-16">
                    <div class="flex">
                        <!-- Logo -->
                        <div class="flex-shrink-0 flex items-center">
                            <a href="{{ route('home') }}" class="text-white text-xl font-bold">
                                Sistema de Certificados
                            </a>
                        </div>

                        <!-- Navigation Links -->
                        <div class="hidden sm:ml-6 sm:flex sm:space-x-8">
                            <a href="{{ route('home') }}"
                               class="text-white hover:text-green-200 px-3 py-2 rounded-md text-sm font-medium transition-colors">
                                Dashboard
                            </a>
                            <a href="{{ route('clients.index') }}"
                               class="text-white hover:text-green-200 px-3 py-2 rounded-md text-sm font-medium transition-colors">
                                Clientes
                            </a>
                            <a href="{{ route('products.index') }}"
                               class="text-white hover:text-green-200 px-3 py-2 rounded-md text-sm font-medium transition-colors">
                                Produtos
                            </a>
                            <a href="{{ route('service-orders.index') }}"
                               class="text-white hover:text-green-200 px-3 py-2 rounded-md text-sm font-medium transition-colors">
                                Ordens de Serviço
                            </a>
                            <a href="{{ route('certificates.index') }}"
                               class="text-white hover:text-green-200 px-3 py-2 rounded-md text-sm font-medium transition-colors">
                                Certificados
                            </a>
                        </div>
                    </div>

                    <!-- User Menu -->
                    <div class="flex items-center">
                        <div class="relative">
                            <button type="button"
                                    class="bg-green-700 hover:bg-green-800 text-white px-3 py-2 rounded-md text-sm font-medium transition-colors">
                                {{ Auth::user()->name ?? 'Usuário' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </nav>

        <!-- Page Content -->
        <main class="py-6">
            @yield('content')
        </main>

        <!-- Footer -->
        <footer class="bg-white border-t border-gray-200 mt-auto">
            <div class="max-w-7xl mx-auto py-4 px-4 sm:px-6 lg:px-8">
                <div class="text-center text-sm text-gray-500">
                    &copy; {{ date('Y') }} Sistema de Certificados. Todos os direitos reservados.
                </div>
            </div>
        </footer>
    </div>

    @stack('scripts')
</body>
</html>
