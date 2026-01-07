<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Detalhes do Dispositivo"
        :description="device.label">
        <template #actions>
          <Link :href="`/devices/${device.id}/edit`" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
            </svg>
            Editar Dispositivo
          </Link>
          <button @click="goBack" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </button>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-4xl mx-auto space-y-6">
      <!-- Breadcrumb de Navegação -->
      <Card>
        <div class="p-4">
          <nav class="flex" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-1 md:space-x-3">
              <li class="inline-flex items-center">
                <Link :href="`/clients/${device.address?.client?.id}`" class="inline-flex items-center text-sm font-medium text-gray-700 hover:text-green-600">
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                  </svg>
                  {{ device.address?.client?.name }}
                </Link>
              </li>
              <li>
                <div class="flex items-center">
                  <svg class="w-6 h-6 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                  </svg>
                  <Link :href="`/addresses/${device.address?.id}`" class="ml-1 text-sm font-medium text-gray-700 hover:text-green-600 md:ml-2">
                    {{ device.address?.nickname || 'Endereço' }}
                  </Link>
                </div>
              </li>
              <li aria-current="page">
                <div class="flex items-center">
                  <svg class="w-6 h-6 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                  </svg>
                  <span class="ml-1 text-sm font-medium text-gray-500 md:ml-2">{{ device.label }}</span>
                </div>
              </li>
            </ol>
          </nav>
        </div>
      </Card>

      <!-- Informações Principais -->
      <Card>
        <div class="p-6">
          <div class="flex items-center space-x-4">
            <div class="flex-shrink-0">
              <div class="h-16 w-16 rounded-full bg-green-100 flex items-center justify-center">
                <svg class="h-8 w-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
              </div>
            </div>
            <div class="flex-1">
              <h1 class="text-2xl font-bold text-gray-900">{{ device.label }}</h1>
              <p class="text-sm text-gray-500">Código: {{ device.number }}</p>
              <p class="text-sm text-gray-500">ID: {{ device.id }}</p>
              <p class="text-sm text-gray-500">Criado em: {{ formatDate(device.created_at) }}</p>
            </div>
            <div class="flex-shrink-0">
              <span v-if="device.active" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                Ativo
              </span>
              <span v-else class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                Inativo
              </span>
            </div>
          </div>
        </div>
      </Card>

      <!-- Tipo de Isca -->
      <Card v-if="device.bait_type">
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Tipo de Isca</h3>
          <div class="space-y-3">
            <div class="flex items-center justify-between">
              <span class="text-sm font-medium text-gray-500">Nome:</span>
              <span class="text-sm text-gray-900">{{ device.bait_type.name }}</span>
            </div>
            <div v-if="device.bait_type.brand" class="flex items-center justify-between">
              <span class="text-sm font-medium text-gray-500">Marca:</span>
              <span class="text-sm text-gray-900">{{ device.bait_type.brand }}</span>
            </div>
            <div v-if="device.bait_type.notes" class="flex items-center justify-between">
              <span class="text-sm font-medium text-gray-500">Descrição:</span>
              <span class="text-sm text-gray-900">{{ device.bait_type.notes }}</span>
            </div>
          </div>
        </div>
      </Card>

      <!-- Observações -->
      <Card v-if="device.default_location_note">
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Observação de Localização</h3>
          <div class="space-y-2">
            <p class="text-sm text-gray-900">{{ device.default_location_note }}</p>
            <p class="text-xs text-gray-500">Informações sobre onde o dispositivo está localizado</p>
          </div>
        </div>
      </Card>

      <!-- Informações do Sistema -->
      <Card>
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Informações do Sistema</h3>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
              <dt class="text-sm font-medium text-gray-500">Data de Criação</dt>
              <dd class="mt-1 text-sm text-gray-900">{{ formatDate(device.created_at) }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Última Atualização</dt>
              <dd class="mt-1 text-sm text-gray-900">{{ formatDate(device.updated_at) }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Status</dt>
              <dd class="mt-1">
                <span v-if="device.active" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                  Ativo
                </span>
                <span v-else class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                  Inativo
                </span>
              </dd>
            </div>
          </div>
        </div>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  device: Object,
});

const formatDate = (dateString) => {
  if (!dateString) return '-';
  return new Date(dateString).toLocaleDateString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
};

const goBack = () => {
  if (window.history.length > 1) {
    window.history.back();
  } else {
    router.visit('/devices');
  }
};
</script>
