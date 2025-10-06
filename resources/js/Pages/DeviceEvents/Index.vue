<template>
  <AuthenticatedLayout>
    <PageHeader
      title="Eventos de Dispositivos"
      subtitle="Gerencie todos os eventos dos dispositivos"
    >
      <template #actions>
        <Link :href="route('device-events.create')" class="btn-primary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
          </svg>
          Novo Evento
        </Link>
      </template>
    </PageHeader>

    <!-- Filtros -->
    <Card class="mb-6">
      <div class="p-6">
        <form @submit.prevent="applyFilters" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <!-- Dispositivo -->
          <div>
            <label for="device_id" class="block text-sm font-medium text-gray-700 mb-2">
              Dispositivo
            </label>
            <select
              id="device_id"
              v-model="filters.device_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os dispositivos</option>
              <option v-for="device in devices" :key="device.id" :value="device.id">
                {{ device.label }} ({{ device.number }})
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

          <!-- Tipo de Evento -->
          <div>
            <label for="event_type" class="block text-sm font-medium text-gray-700 mb-2">
              Tipo de Evento
            </label>
            <select
              id="event_type"
              v-model="filters.event_type"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os tipos</option>
              <option value="bait_consumption">Consumo de Isca</option>
              <option value="cleaning">Limpeza</option>
              <option value="bait_change">Troca de Isca</option>
              <option value="technician_notes">Observações do Técnico</option>
            </select>
          </div>

          <!-- Data -->
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

    <!-- Lista de Eventos -->
    <Card>
      <div class="p-6">
        <div v-if="deviceEvents.data.length === 0" class="text-center py-8">
          <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
          </svg>
          <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum evento encontrado</h3>
          <p class="mt-1 text-sm text-gray-500">Comece criando um novo evento de dispositivo.</p>
        </div>

        <div v-else class="space-y-4">
          <div
            v-for="event in deviceEvents.data"
            :key="event.id"
            class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors"
          >
            <div class="flex items-center justify-between">
              <div class="flex-1">
                <div class="flex items-center space-x-3">
                  <!-- Ícone do tipo de evento -->
                  <div class="flex-shrink-0">
                    <div class="h-8 w-8 rounded-full bg-green-100 flex items-center justify-center">
                      <svg class="h-4 w-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path v-if="event.event_type === 'bait_consumption'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                        <path v-else-if="event.event_type === 'cleaning'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.207A1 1 0 013 6.5V4z"></path>
                        <path v-else-if="event.event_type === 'bait_change'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                        <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                      </svg>
                    </div>
                  </div>

                  <!-- Informações do evento -->
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center space-x-2">
                      <h3 class="text-sm font-medium text-gray-900">
                        {{ event.event_type_text }}
                      </h3>
                      <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                        {{ event.device?.label }} ({{ event.device?.number }})
                      </span>
                    </div>
                    <p class="text-sm text-gray-500">
                      {{ event.device?.room?.address?.client?.name }} - {{ event.device?.room?.address?.street }}, {{ event.device?.room?.address?.number }}
                    </p>
                    <p class="text-sm text-gray-500">
                      {{ formatDate(event.event_date) }} • {{ event.work_order?.order_number }}
                    </p>
                  </div>
                </div>

                <!-- Detalhes específicos do evento -->
                <div class="mt-3 ml-11">
                  <div v-if="event.event_type === 'bait_consumption'" class="text-sm text-gray-600">
                    <span class="font-medium">Status:</span> {{ event.bait_consumption_status_text }}
                    <span v-if="event.bait_consumption_quantity" class="ml-3">
                      <span class="font-medium">Quantidade:</span> {{ event.bait_consumption_quantity }}
                    </span>
                  </div>
                  <div v-else-if="event.event_type === 'cleaning'" class="text-sm text-gray-600">
                    <span class="font-medium">Limpeza:</span> {{ event.cleaning_status_text }}
                    <span v-if="event.cleaning_notes" class="ml-3">
                      <span class="font-medium">Observações:</span> {{ event.cleaning_notes }}
                    </span>
                  </div>
                  <div v-else-if="event.event_type === 'bait_change'" class="text-sm text-gray-600">
                    <span v-if="event.bait_change_type" class="font-medium">Tipo:</span> {{ event.bait_change_type }}
                    <span v-if="event.bait_change_lot" class="ml-3">
                      <span class="font-medium">Lote:</span> {{ event.bait_change_lot }}
                    </span>
                    <span v-if="event.bait_change_quantity" class="ml-3">
                      <span class="font-medium">Quantidade:</span> {{ event.bait_change_quantity }}
                    </span>
                  </div>
                  <div v-else-if="event.event_type === 'technician_notes'" class="text-sm text-gray-600">
                    <span class="font-medium">Observações:</span> {{ event.technician_notes }}
                  </div>
                </div>
              </div>

              <!-- Ações -->
              <div class="flex items-center space-x-2">
                <Link
                  :href="route('device-events.show', event.id)"
                  class="text-green-600 hover:text-green-900 text-sm font-medium"
                >
                  Ver Detalhes
                </Link>
                <Link
                  :href="route('device-events.edit', event.id)"
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
          v-if="deviceEvents.data.length > 0"
          :links="deviceEvents.links"
          class="mt-6"
        />
      </div>
    </Card>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
  deviceEvents: Object,
  filters: Object,
  devices: Array,
  workOrders: Array,
});

const filters = ref({
  device_id: props.filters?.device_id || '',
  work_order_id: props.filters?.work_order_id || '',
  event_type: props.filters?.event_type || '',
  date_from: props.filters?.date_from || '',
});

const applyFilters = () => {
  router.get(route('device-events.index'), filters.value, {
    preserveState: true,
    preserveScroll: true,
  });
};

const clearFilters = () => {
  filters.value = {
    device_id: '',
    work_order_id: '',
    event_type: '',
    date_from: '',
  };
  router.get(route('device-events.index'));
};

const formatDate = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleDateString('pt-BR');
};
</script>











