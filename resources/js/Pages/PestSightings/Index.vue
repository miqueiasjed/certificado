<template>
  <AuthenticatedLayout>
    <PageHeader
      title="Avistamentos de Pragas"
      subtitle="Gerencie todos os avistamentos de pragas registrados"
    >
      <template #actions>
        <Link :href="route('pest-sightings.create')" class="btn-primary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
          </svg>
          Novo Avistamento
        </Link>
      </template>
    </PageHeader>

    <!-- Filtros -->
    <Card class="mb-6">
      <div class="p-6">
        <form @submit.prevent="applyFilters" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <!-- Endereço -->
          <div>
            <label for="address_id" class="block text-sm font-medium text-gray-700 mb-2">
              Endereço
            </label>
            <select
              id="address_id"
              v-model="filters.address_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os endereços</option>
              <option v-for="address in addresses" :key="address.id" :value="address.id">
                {{ address.nickname }} - {{ address.street }}, {{ address.number }}
              </option>
            </select>
          </div>

          <!-- Ordem de Serviço -->
          <div>
            <label for="work_order_id" class="block text-sm font-medium text-gray-700 mb-2">
              Ordem de Serviço
            </label>
            <select
              id="work_order_id"
              v-model="filters.work_order_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todas as ordens</option>
              <option v-for="workOrder in workOrders" :key="workOrder.id" :value="workOrder.id">
                {{ workOrder.order_number }}
              </option>
            </select>
          </div>

          <!-- Tipo de Praga -->
          <div>
            <label for="pest_type" class="block text-sm font-medium text-gray-700 mb-2">
              Tipo de Praga
            </label>
            <select
              id="pest_type"
              v-model="filters.pest_type"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os tipos</option>
              <option value="rats">Ratos</option>
              <option value="mice">Camundongos</option>
              <option value="cockroaches">Baratas</option>
              <option value="ants">Formigas</option>
              <option value="termites">Cupins</option>
              <option value="flies">Moscas</option>
              <option value="fleas">Pulgas</option>
              <option value="ticks">Carrapatos</option>
              <option value="scorpions">Escorpiões</option>
              <option value="spiders">Aranhas</option>
              <option value="bees">Abelhas</option>
              <option value="wasps">Vespas</option>
              <option value="other">Outros</option>
            </select>
          </div>

          <!-- Nível de Severidade -->
          <div>
            <label for="severity_level" class="block text-sm font-medium text-gray-700 mb-2">
              Severidade
            </label>
            <select
              id="severity_level"
              v-model="filters.severity_level"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os níveis</option>
              <option value="low">Baixa</option>
              <option value="medium">Média</option>
              <option value="high">Alta</option>
              <option value="critical">Crítica</option>
            </select>
          </div>

          <!-- Data (De) -->
          <div>
            <label for="date_from" class="block text-sm font-medium text-gray-700 mb-2">
              Data (De)
            </label>
            <input
              id="date_from"
              v-model="filters.date_from"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>

          <!-- Data (Até) -->
          <div>
            <label for="date_to" class="block text-sm font-medium text-gray-700 mb-2">
              Data (Até)
            </label>
            <input
              id="date_to"
              v-model="filters.date_to"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>

          <!-- Botões de Filtro -->
          <div class="md:col-span-2 lg:col-span-4 flex justify-end space-x-3">
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

    <!-- Lista de Avistamentos -->
    <Card>
      <div class="p-6">
        <div v-if="pestSightings.data.length === 0" class="text-center py-8">
          <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
          </svg>
          <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum avistamento encontrado</h3>
          <p class="mt-1 text-sm text-gray-500">Comece registrando um novo avistamento de praga.</p>
        </div>

        <div v-else class="space-y-4">
          <div
            v-for="sighting in pestSightings.data"
            :key="sighting.id"
            class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors"
          >
            <div class="flex items-center justify-between">
              <div class="flex-1">
                <div class="flex items-center space-x-3">
                  <!-- Ícone da praga -->
                  <div class="flex-shrink-0">
                    <div class="h-8 w-8 rounded-full bg-red-100 flex items-center justify-center">
                      <svg class="h-4 w-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                      </svg>
                    </div>
                  </div>

                  <!-- Informações do avistamento -->
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center space-x-2">
                      <h3 class="text-sm font-medium text-gray-900">
                        {{ sighting.pest_type_text }}
                      </h3>
                      <span
                        :class="`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                          sighting.severity_level === 'critical' ? 'bg-red-100 text-red-800' :
                          sighting.severity_level === 'high' ? 'bg-orange-100 text-orange-800' :
                          sighting.severity_level === 'medium' ? 'bg-yellow-100 text-yellow-800' :
                          'bg-green-100 text-green-800'
                        }`"
                      >
                        {{ sighting.severity_level_text }}
                      </span>
                      <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                        {{ sighting.address?.nickname }}
                      </span>
                    </div>
                    <p class="text-sm text-gray-500">
                      {{ sighting.address?.client?.name }} - {{ sighting.address?.street }}, {{ sighting.address?.number }} - {{ sighting.address?.city }}/{{ sighting.address?.state }}
                    </p>
                    <p class="text-sm text-gray-500">
                      {{ formatDate(sighting.sighting_date) }} • {{ sighting.work_order?.order_number }}
                    </p>
                  </div>
                </div>

                <!-- Detalhes do avistamento -->
                <div class="mt-3 ml-11">
                  <div v-if="sighting.location_description" class="text-sm text-gray-600 mb-2">
                    <span class="font-medium">Localização:</span> {{ sighting.location_description }}
                  </div>
                  <div v-if="sighting.environmental_conditions" class="text-sm text-gray-600 mb-2">
                    <span class="font-medium">Condições Ambientais:</span> {{ sighting.environmental_conditions }}
                  </div>
                  <div v-if="sighting.control_measures_applied" class="text-sm text-gray-600 mb-2">
                    <span class="font-medium">Medidas Aplicadas:</span> {{ sighting.control_measures_applied }}
                  </div>
                  <div v-if="sighting.technician_notes" class="text-sm text-gray-600">
                    <span class="font-medium">Observações do Técnico:</span> {{ sighting.technician_notes }}
                  </div>
                </div>
              </div>

              <!-- Ações -->
              <div class="flex items-center space-x-2">
                <Link
                  :href="route('pest-sightings.show', sighting.id)"
                  class="text-green-600 hover:text-green-900 text-sm font-medium"
                >
                  Ver Detalhes
                </Link>
                <Link
                  :href="route('pest-sightings.edit', sighting.id)"
                  class="text-blue-600 hover:text-blue-900 text-sm font-medium"
                >
                  Editar
                </Link>
              </div>
            </div>
          </div>
        </div>

        <!-- Paginação -->
        <Pagination
          v-if="pestSightings.data.length > 0"
          :links="pestSightings.links"
          class="mt-6"
        />
      </div>
    </Card>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
  pestSightings: Object,
  filters: Object,
  addresses: Array,
  workOrders: Array,
});

const filters = ref({
  address_id: props.filters?.address_id || '',
  work_order_id: props.filters?.work_order_id || '',
  pest_type: props.filters?.pest_type || '',
  severity_level: props.filters?.severity_level || '',
  date_from: props.filters?.date_from || '',
  date_to: props.filters?.date_to || '',
});

const applyFilters = () => {
  router.get(route('pest-sightings.index'), filters.value, {
    preserveState: true,
    preserveScroll: true,
  });
};

const clearFilters = () => {
  filters.value = {
    address_id: '',
    work_order_id: '',
    pest_type: '',
    severity_level: '',
    date_from: '',
    date_to: '',
  };
  router.get(route('pest-sightings.index'));
};

const formatDate = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleDateString('pt-BR');
};
</script>
























