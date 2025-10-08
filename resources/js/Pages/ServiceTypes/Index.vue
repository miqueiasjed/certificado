<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Tipos de Serviço" description="Gerencie os tipos de ordem de serviço">
        <template #actions>
          <Link href="/service-types/create" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
            </svg>
            Novo Tipo
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-7xl mx-auto">
      <!-- Filtros -->
      <Card class="mb-6">
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Buscar</label>
              <input
                v-model="filters.search"
                type="text"
                placeholder="Nome ou descrição..."
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500"
                @input="debouncedSearch"
              />
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Status</label>
              <select
                v-model="filters.active"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500"
                @change="loadServiceTypes"
              >
                <option value="">Todos</option>
                <option value="1">Ativos</option>
                <option value="0">Inativos</option>
              </select>
            </div>
            <div class="flex items-end">
              <button
                @click="clearFilters"
                class="w-full px-4 py-2 bg-gray-500 text-white rounded-md hover:bg-gray-600 transition-colors"
              >
                Limpar Filtros
              </button>
            </div>
          </div>
        </div>
      </Card>

      <!-- Estatísticas -->
      <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
        <StatCard
          title="Total de Tipos"
          :value="stats.total"
          icon="cog"
          color="purple"
        />
        <StatCard
          title="Tipos Ativos"
          :value="stats.active"
          icon="check-circle"
          color="green"
        />
        <StatCard
          title="Tipos Inativos"
          :value="stats.inactive"
          icon="x-circle"
          color="red"
        />
      </div>

      <!-- Tabela -->
      <Card>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Nome
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Descrição
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Status
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Ordens Vinculadas
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Criado em
                </th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Ações
                </th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-if="loading || !serviceTypes" class="animate-pulse">
                <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                  Carregando...
                </td>
              </tr>
              <tr v-else-if="serviceTypes && serviceTypes.data && serviceTypes.data.length === 0">
                <td colspan="6" class="px-6 py-4 text-center text-gray-500">
                  Nenhum tipo de serviço encontrado
                </td>
              </tr>
              <tr v-else v-for="serviceType in (serviceTypes?.data || [])" :key="serviceType.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="text-sm font-medium text-gray-900">{{ serviceType.name }}</div>
                  <div class="text-sm text-gray-500">{{ serviceType.slug }}</div>
                </td>
                <td class="px-6 py-4">
                  <div class="text-sm text-gray-900 max-w-xs truncate">
                    {{ serviceType.description || 'Sem descrição' }}
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                    :class="serviceType.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                  >
                    {{ serviceType.active ? 'Ativo' : 'Inativo' }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                        :class="serviceType.service_orders_count > 0 ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'">
                    {{ serviceType.service_orders_count }} ordem(ns)
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                  {{ formatDate(serviceType.created_at) }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                  <div class="flex items-center justify-end space-x-2">
                    <Link
                      :href="`/service-types/${serviceType.id}/edit`"
                      class="text-yellow-600 hover:text-yellow-900"
                    >
                      Editar
                    </Link>
                    <button
                      @click="deleteServiceType(serviceType)"
                      :class="serviceType.service_orders_count > 0 ? 'text-gray-400 cursor-not-allowed' : 'text-red-600 hover:text-red-900'"
                      :disabled="serviceType.service_orders_count > 0"
                      :title="serviceType.service_orders_count > 0 ? `Não é possível excluir: ${serviceType.service_orders_count} ordem(ns) vinculada(s)` : 'Excluir tipo de serviço'"
                    >
                      Excluir
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Paginação -->
        <div v-if="serviceTypes && serviceTypes.last_page > 1" class="px-6 py-4 border-t border-gray-200">
          <Pagination :links="serviceTypes.links" />
        </div>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, reactive, onMounted, watch } from 'vue';
import { router, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
  serviceTypes: Object,
  stats: Object,
  filters: Object,
});

// Estado reativo
const loading = ref(false);
const filters = reactive({
  search: props.filters?.search || '',
  active: props.filters?.active || '',
});

// Debounce simples
let searchTimeout = null;
const debouncedSearch = () => {
  if (searchTimeout) {
    clearTimeout(searchTimeout);
  }
  searchTimeout = setTimeout(() => {
    loadServiceTypes();
  }, 500);
};

// Métodos
const loadServiceTypes = () => {
  loading.value = true;
  router.get('/service-types', {
    search: filters.search,
    active: filters.active,
  }, {
    preserveState: true,
    onFinish: () => {
      loading.value = false;
    }
  });
};

const clearFilters = () => {
  filters.search = '';
  filters.active = '';
  loadServiceTypes();
};

const deleteServiceType = (serviceType) => {
  if (serviceType.service_orders_count > 0) {
    alert(`Não é possível excluir o tipo "${serviceType.name}" pois existem ${serviceType.service_orders_count} ordem(ns) de serviço vinculada(s) a ele.`);
    return;
  }

  if (confirm(`Tem certeza que deseja excluir o tipo "${serviceType.name}"?`)) {
    router.delete(`/service-types/${serviceType.id}`, {
      onSuccess: () => {
        loadServiceTypes();
      }
    });
  }
};

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('pt-BR');
};

// Computed para estatísticas
const stats = ref({
  total: props.stats?.total || 0,
  active: props.stats?.active || 0,
  inactive: props.stats?.inactive || 0,
});

// Watchers
watch(() => props.stats, (newStats) => {
  if (newStats) {
    stats.value = newStats;
  }
}, { deep: true });
</script>
