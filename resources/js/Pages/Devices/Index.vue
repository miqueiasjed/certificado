<template>
  <AuthenticatedLayout>
    <PageHeader
      title="Dispositivos"
      subtitle="Gerencie todos os dispositivos do sistema"
    >
      <template #actions>
        <Link :href="route('devices.create')" class="btn-primary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
          </svg>
          Novo Dispositivo
        </Link>
      </template>
    </PageHeader>

    <!-- Filtros -->
    <Card class="mb-6">
      <div class="p-6">
        <form @submit.prevent="applyFilters" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <!-- Cliente -->
          <div>
            <label for="client_id" class="block text-sm font-medium text-gray-700 mb-2">
              Cliente
            </label>
            <select
              id="client_id"
              v-model="filters.client_id"
              @change="onClientChange"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os clientes</option>
              <option v-for="client in clients" :key="client.id" :value="client.id">
                {{ client.name }}
              </option>
            </select>
          </div>

          <!-- Endereço -->
          <div>
            <label for="address_id" class="block text-sm font-medium text-gray-700 mb-2">
              Endereço
            </label>
            <select
              id="address_id"
              v-model="filters.address_id"
              :disabled="!filters.client_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
            >
              <option value="">Todos os endereços</option>
              <option v-for="address in filteredAddresses" :key="address.id" :value="address.id">
                {{ address.nickname }} - {{ address.street }}, {{ address.number }}
              </option>
            </select>
          </div>

          <!-- Tipo de Isca -->
          <div>
            <label for="bait_type_id" class="block text-sm font-medium text-gray-700 mb-2">
              Tipo de Isca
            </label>
            <select
              id="bait_type_id"
              v-model="filters.bait_type_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os tipos</option>
              <option v-for="baitType in baitTypes" :key="baitType.id" :value="baitType.id">
                {{ baitType.name }} - {{ baitType.brand }}
              </option>
            </select>
          </div>

          <!-- Botões de Filtro -->
          <div class="md:col-span-2 lg:col-span-3 flex justify-end space-x-3">
            <button
              type="button"
              @click="clearFilters"
              class="btn-secondary"
            >
              Limpar Filtros
            </button>
            <button
              type="submit"
              class="btn-primary"
            >
              Aplicar Filtros
            </button>
          </div>
        </form>
      </div>
    </Card>

    <!-- Lista de Dispositivos -->
    <Card>
      <div class="p-6">
        <div v-if="devices.data.length === 0" class="text-center py-8">
          <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
          </svg>
          <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum dispositivo encontrado</h3>
          <p class="mt-1 text-sm text-gray-500">Comece criando um novo dispositivo.</p>
        </div>

        <div v-else class="space-y-4">
          <div
            v-for="device in devices.data"
            :key="device.id"
            class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors"
          >
            <div class="flex items-center justify-between">
              <div class="flex-1">
                <div class="flex items-center space-x-3">
                  <!-- Ícone do dispositivo -->
                  <div class="flex-shrink-0">
                    <div class="h-8 w-8 rounded-full bg-green-100 flex items-center justify-center">
                      <svg class="h-4 w-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                      </svg>
                    </div>
                  </div>

                  <!-- Informações do dispositivo -->
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center space-x-2">
                      <h3 class="text-sm font-medium text-gray-900">
                        {{ device.label }} ({{ device.number }})
                      </h3>
                      <span v-if="device.bait_type" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                        {{ device.bait_type.name }}
                      </span>
                    </div>
                    <p class="text-sm text-gray-500">
                      {{ device.address?.client?.name }} - {{ device.address?.street }}, {{ device.address?.number }}
                    </p>
                    <p class="text-sm text-gray-500">
                      {{ device.address?.city }}/{{ device.address?.state }}
                    </p>
                  </div>
                </div>
              </div>

              <!-- Ações -->
              <div class="flex items-center space-x-2">
                <Link
                  :href="route('devices.show', device.id)"
                  class="text-green-600 hover:text-green-900 text-sm font-medium"
                >
                  Ver Detalhes
                </Link>
                <Link
                  :href="`${route('devices.edit', device.id)}?return_url=${encodeURIComponent($page.url)}`"
                  class="text-blue-600 hover:text-blue-900 text-sm font-medium"
                >
                  Editar
                </Link>
                <button
                  @click="checkAndDeleteDevice(device)"
                  class="text-red-600 hover:text-red-900 text-sm font-medium disabled:text-gray-400 disabled:cursor-not-allowed"
                  :disabled="isCheckingDelete"
                >
                  {{ isCheckingDelete ? 'Verificando...' : 'Excluir' }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Paginação -->
        <Pagination
          v-if="devices.data.length > 0"
          :links="devices.links"
          class="mt-6"
        />
      </div>
    </Card>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
  devices: Object,
  filters: Object,
  clients: Array,
  addresses: Array,
  baitTypes: Array,
});

const filters = ref({
  client_id: props.filters?.client_id || '',
  address_id: props.filters?.address_id || '',
  bait_type_id: props.filters?.bait_type_id || '',
});

// Filtrar endereços baseado no cliente selecionado
const filteredAddresses = computed(() => {
  if (!filters.value.client_id) return props.addresses || [];
  return (props.addresses || []).filter(address => address.client_id == filters.value.client_id);
});

// Limpar endereço quando cliente muda
const onClientChange = () => {
  filters.value.address_id = '';
};

const applyFilters = () => {
  router.get(route('devices.index'), filters.value, {
    preserveState: true,
    preserveScroll: true,
  });
};

const clearFilters = () => {
  filters.value = {
    client_id: '',
    address_id: '',
    bait_type_id: '',
  };
  router.get(route('devices.index'));
};

// Estado para verificação de exclusão
const isCheckingDelete = ref(false);

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
</script>

















