<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Detalhes do Cômodo"
        :description="room.name">
        <template #actions>
          <Link :href="`/rooms/${room.id}/edit`" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
            </svg>
            Editar Cômodo
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
                <Link :href="`/clients/${room.address.client.id}`" class="inline-flex items-center text-sm font-medium text-gray-700 hover:text-green-600">
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                  </svg>
                  {{ room.address.client.name }}
                </Link>
              </li>
              <li>
                <div class="flex items-center">
                  <svg class="w-6 h-6 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                  </svg>
                  <Link :href="`/addresses/${room.address.id}`" class="ml-1 text-sm font-medium text-gray-700 hover:text-green-600 md:ml-2">
                    {{ room.address.nickname }}
                  </Link>
                </div>
              </li>
              <li aria-current="page">
                <div class="flex items-center">
                  <svg class="w-6 h-6 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                  </svg>
                  <span class="ml-1 text-sm font-medium text-gray-500 md:ml-2">{{ room.name }}</span>
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
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                </svg>
              </div>
            </div>
            <div class="flex-1">
              <h1 class="text-2xl font-bold text-gray-900">{{ room.name }}</h1>
              <p class="text-sm text-gray-500">ID: {{ room.id }}</p>
              <p class="text-sm text-gray-500">Criado em: {{ formatDate(room.created_at) }}</p>
            </div>
            <div class="flex-shrink-0">
              <span v-if="room.active" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                Ativo
              </span>
              <span v-else class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                Inativo
              </span>
            </div>
          </div>
        </div>
      </Card>

      <!-- Observações -->
      <Card v-if="room.notes">
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Observações</h3>
          <div class="space-y-2">
            <p class="text-sm text-gray-900">{{ room.notes }}</p>
            <p class="text-xs text-gray-500">Informações adicionais sobre o cômodo</p>
          </div>
        </div>
      </Card>

      <!-- Dispositivos -->
      <Card>
        <div class="p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900">Dispositivos</h3>
            <button
              @click="showDeviceModal = true"
              class="btn-primary"
            >
              <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
              </svg>
              Novo Dispositivo
            </button>
          </div>

          <div v-if="room.devices && room.devices.length > 0" class="space-y-4">
            <div v-for="device in room.devices" :key="device.id" class="border border-gray-200 rounded-lg p-4">
              <div class="flex items-start justify-between">
                <div class="flex-1">
                  <div class="flex items-center space-x-2 mb-2">
                    <h4 class="text-sm font-medium text-gray-900">{{ device.label }}</h4>
                    <span v-if="device.active" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                      Ativo
                    </span>
                    <span v-else class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                      Inativo
                    </span>
                  </div>
                  <p class="text-sm text-gray-600">Código: {{ device.number }}</p>
                  <p v-if="device.default_location_note" class="text-sm text-gray-500 mt-1">
                    Localização: {{ device.default_location_note }}
                  </p>
                </div>
                <div class="flex space-x-2">
                  <Link :href="`/devices/${device.id}`" class="btn-secondary btn-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                  </Link>
                  <Link
                    :href="`/devices/${device.id}/edit?return_url=${encodeURIComponent($page.url)}`"
                    class="btn-primary btn-sm"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                  </Link>
                  <button
                    @click="checkAndDeleteDevice(device)"
                    class="btn-danger btn-sm"
                    :disabled="isCheckingDelete"
                    :title="isCheckingDelete ? 'Verificando...' : 'Excluir dispositivo'"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                  </button>
                </div>
              </div>
            </div>
          </div>

          <div v-else class="text-center py-8">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum dispositivo cadastrado</h3>
            <p class="mt-1 text-sm text-gray-500">Use o botão "Novo Dispositivo" acima para adicionar o primeiro dispositivo.</p>
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
              <dd class="mt-1 text-sm text-gray-900">{{ formatDate(room.created_at) }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Última Atualização</dt>
              <dd class="mt-1 text-sm text-gray-900">{{ formatDate(room.updated_at) }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Status</dt>
              <dd class="mt-1">
                <span v-if="room.active" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
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

      <!-- Ações -->
      <div class="flex justify-center space-x-4">
        <Link :href="`/rooms/${room.id}/edit`" class="btn-primary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
          </svg>
          Editar Cômodo
        </Link>
        <button @click="goBack" class="btn-secondary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
          </svg>
          Voltar
        </button>
      </div>
    </div>

    <!-- Modal para Criar Dispositivo -->
    <DeviceModal
      :show="showDeviceModal"
      :room="room"
      :errors="errors"
      :bait-types="baitTypes"
      @close="showDeviceModal = false"
      @device-created="refreshDevices"
      @bait-type-created="refreshBaitTypes"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import DeviceModal from '@/Components/DeviceModal.vue';

const props = defineProps({
  room: Object,
  baitTypes: {
    type: Array,
    default: () => []
  }
});

const showDeviceModal = ref(false);
const isCheckingDelete = ref(false);

const refreshDevices = () => {
  // Recarregar a página para atualizar a lista de dispositivos
  router.reload();
};

// Função para verificar e excluir dispositivo
const checkAndDeleteDevice = async (device) => {
  if (!confirm(`Tem certeza que deseja excluir o dispositivo "${device.label} (${device.number})"?`)) {
    return;
  }

  isCheckingDelete.value = true;

  try {
    // Verificar se pode excluir
    const response = await fetch(route('devices.can-delete', device.id), {
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      }
    });

    const result = await response.json();

    if (!result.can_delete) {
      alert(result.message);
      return;
    }

    // Se pode excluir, prosseguir com a exclusão
    router.delete(route('devices.destroy', device.id), {
      preserveScroll: true,
      onSuccess: () => {
        // A página será automaticamente atualizada pelo Inertia após o redirect
      },
      onError: (errors) => {
        alert('Erro ao excluir dispositivo: ' + (errors.message || 'Erro desconhecido'));
      }
    });

  } catch (error) {
    console.error('Erro ao verificar exclusão:', error);
    alert('Erro ao verificar se o dispositivo pode ser excluído');
  } finally {
    isCheckingDelete.value = false;
  }
};

const refreshBaitTypes = () => {
  // Recarregar a página para atualizar a lista de tipos de isca
  router.reload();
};

const getDeviceTypeLabel = (type) => {
  const types = {
    'trap': 'Armadilha',
    'sensor': 'Sensor',
    'monitor': 'Monitor',
    'other': 'Outro'
  };
  return types[type] || type;
};

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
    router.visit('/rooms');
  }
};
</script>
