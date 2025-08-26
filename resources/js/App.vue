<template>
  <div id="app" class="min-h-screen bg-gray-50">
    <!-- Header -->
    <header class="bg-green-600 text-white shadow-lg">
      <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
        <div class="flex justify-between items-center py-4">
          <div class="flex items-center">
            <h1 class="text-2xl font-bold">Sistema de Certificados</h1>
          </div>
          <nav class="flex space-x-4">
            <router-link to="/" class="hover:text-green-200 transition-colors">Dashboard</router-link>
            <router-link to="/clients" class="hover:text-green-200 transition-colors">Clientes</router-link>
            <router-link to="/products" class="hover:text-green-200 transition-colors">Produtos</router-link>
            <router-link to="/service-orders" class="hover:text-green-200 transition-colors">Ordens de Serviço</router-link>
            <router-link to="/certificates" class="hover:text-green-200 transition-colors">Certificados</router-link>
          </nav>
        </div>
      </div>
    </header>

    <!-- Main Content -->
    <main class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
      <router-view></router-view>
    </main>
  </div>
</template>

<script>
export default {
  name: 'App'
}
</script>

<style>
/* Estilos globais */
body {
  font-family: 'Inter', sans-serif;
}

/* Cores do tema */
:root {
  --primary-green: #059669;
  --light-green: #10b981;
  --dark-green: #047857;
}
</style>
