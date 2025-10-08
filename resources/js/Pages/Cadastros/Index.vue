<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Cadastros" description="Gerencie todos os cadastros do sistema">
        <template #actions>
          <div class="text-sm text-gray-500">
            {{ cadastros.length }} tipos de cadastro disponíveis
          </div>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-7xl mx-auto">
      <!-- Grid de Cadastros -->
      <Card>
        <div class="p-6">
          <h3 class="text-lg font-semibold text-gray-900 mb-6">
            Acesse os Cadastros
          </h3>

          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
            <div
              v-for="cadastro in cadastros"
              :key="cadastro.id"
              @click="navigateToCadastro(cadastro.route)"
              class="group cursor-pointer bg-white border border-gray-200 rounded-lg p-6 hover:shadow-lg hover:border-gray-300 transition-all duration-200"
            >
              <!-- Ícone -->
              <div class="flex items-center justify-center w-16 h-16 mx-auto mb-4 rounded-full"
                   :class="getIconBgClass(cadastro.color)">
                <svg class="w-8 h-8" :class="getIconColorClass(cadastro.color)" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <!-- Ícone de Pacote -->
                  <path v-if="cadastro.icon === 'package'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>

                  <!-- Ícone de Usuários -->
                  <path v-else-if="cadastro.icon === 'user-group'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"></path>

                  <!-- Ícone de Engrenagem -->
                  <path v-else-if="cadastro.icon === 'cog'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>

                  <!-- Ícone de Béquer -->
                  <path v-else-if="cadastro.icon === 'beaker'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>

                  <!-- Ícone de Frasco -->
                  <path v-else-if="cadastro.icon === 'flask'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>

                  <!-- Ícone de Escudo -->
                  <path v-else-if="cadastro.icon === 'shield-check'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"></path>

                  <!-- Ícone de Documento -->
                  <path v-else-if="cadastro.icon === 'document-text'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>

                  <!-- Ícone de Dispositivo Móvel -->
                  <path v-else-if="cadastro.icon === 'device-mobile'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 18h.01M8 21h8a2 2 0 002-2V5a2 2 0 00-2-2H8a2 2 0 00-2 2v14a2 2 0 002 2z"></path>

                  <!-- Ícone padrão -->
                  <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                </svg>
              </div>

              <!-- Conteúdo -->
              <div class="text-center">
                <h4 class="text-lg font-semibold text-gray-900 mb-2 group-hover:text-gray-700">
                  {{ cadastro.name }}
                </h4>
                <p class="text-sm text-gray-600 mb-3">
                  {{ cadastro.description }}
                </p>
                <div class="flex items-center justify-center">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                        :class="getBadgeClass(cadastro.color)">
                    {{ cadastro.count }} {{ cadastro.count === 1 ? 'item' : 'itens' }}
                  </span>
                </div>
              </div>

              <!-- Indicador de hover -->
              <div class="mt-4 flex items-center justify-center">
                <svg class="w-5 h-5 text-gray-400 group-hover:text-gray-600 transition-colors" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
              </div>
            </div>
          </div>
        </div>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  cadastros: Array,
});

// Métodos para estilização
const getIconBgClass = (color) => {
  const colors = {
    blue: 'bg-blue-100',
    green: 'bg-green-100',
    purple: 'bg-purple-100',
    orange: 'bg-orange-100',
    red: 'bg-red-100',
    yellow: 'bg-yellow-100',
    indigo: 'bg-indigo-100',
    cyan: 'bg-cyan-100',
  };
  return colors[color] || 'bg-gray-100';
};

const getIconColorClass = (color) => {
  const colors = {
    blue: 'text-blue-600',
    green: 'text-green-600',
    purple: 'text-purple-600',
    orange: 'text-orange-600',
    red: 'text-red-600',
    yellow: 'text-yellow-600',
    indigo: 'text-indigo-600',
    cyan: 'text-cyan-600',
  };
  return colors[color] || 'text-gray-600';
};

const getBadgeClass = (color) => {
  const colors = {
    blue: 'bg-blue-100 text-blue-800',
    green: 'bg-green-100 text-green-800',
    purple: 'bg-purple-100 text-purple-800',
    orange: 'bg-orange-100 text-orange-800',
    red: 'bg-red-100 text-red-800',
    yellow: 'bg-yellow-100 text-yellow-800',
    indigo: 'bg-indigo-100 text-indigo-800',
    cyan: 'bg-cyan-100 text-cyan-800',
  };
  return colors[color] || 'bg-gray-100 text-gray-800';
};

// Navegação
const navigateToCadastro = (route) => {
  router.visit(route);
};
</script>
