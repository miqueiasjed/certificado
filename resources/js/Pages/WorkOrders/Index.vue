<template>
  <AuthenticatedLayout>
    <PageHeader
      title="Ordens de Serviço"
      subtitle="Gerencie todas as ordens de serviço do sistema"
    >
      <template #actions>
        <Link :href="route('work-orders.create')" class="btn-primary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
          </svg>
          Nova Ordem
        </Link>
      </template>
    </PageHeader>

    <!-- Filtros -->
    <Card class="mb-6 mt-8">
      <div class="p-6">
        <form @submit.prevent="applyFilters" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <!-- Cliente -->
          <div>
            <label for="client_id" class="block text-sm font-medium text-gray-700 mb-2">
              Cliente
            </label>
            <select
              id="client_id"
              v-model="filters.client_id"
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
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os endereços</option>
              <option v-for="address in addresses" :key="address.id" :value="address.id">
                {{ address.nickname }} - {{ address.street }}, {{ address.number }}
              </option>
            </select>
          </div>

          <!-- Técnico -->
          <div>
            <label for="technician_id" class="block text-sm font-medium text-gray-700 mb-2">
              Técnico
            </label>
            <select
              id="technician_id"
              v-model="filters.technician_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os técnicos</option>
              <option v-for="technician in technicians" :key="technician.id" :value="technician.id">
                {{ technician.name }}
              </option>
            </select>
          </div>

          <!-- Status -->
          <div>
            <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
              Status
            </label>
            <select
              id="status"
              v-model="filters.status"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os status</option>
              <option value="pending">Pendente</option>
              <option value="scheduled">Agendada</option>
              <option value="in_progress">Em Andamento</option>
              <option value="completed">Concluída</option>
              <option value="cancelled">Cancelada</option>
              <option value="on_hold">Em Espera</option>
            </select>
          </div>

          <!-- Prioridade -->
          <div>
            <label for="priority_level" class="block text-sm font-medium text-gray-700 mb-2">
              Prioridade
            </label>
            <select
              id="priority_level"
              v-model="filters.priority_level"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todas as prioridades</option>
              <option value="low">Baixa</option>
              <option value="medium">Média</option>
              <option value="high">Alta</option>
              <option value="urgent">Urgente</option>
              <option value="emergency">Emergência</option>
            </select>
          </div>

          <!-- Serviço -->
          <div>
            <label for="service_id" class="block text-sm font-medium text-gray-700 mb-2">
              Serviço
            </label>
            <select
              id="service_id"
              v-model="filters.service_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os serviços</option>
              <option v-for="service in services" :key="service.id" :value="service.id">
                {{ service.name }}
              </option>
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

    <!-- Lista de Ordens de Serviço -->
    <Card>
      <div class="p-6">
        <div v-if="workOrders.data.length === 0" class="text-center py-8">
          <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
          </svg>
          <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhuma ordem de serviço encontrada</h3>
          <p class="mt-1 text-sm text-gray-500">Comece criando uma nova ordem de serviço.</p>
        </div>

        <div v-else class="space-y-4">
          <div
            v-for="workOrder in workOrders.data"
            :key="workOrder.id"
            class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors"
          >
            <div class="flex items-center justify-between">
              <div class="flex-1">
                <div class="flex items-center space-x-3">
                  <!-- Ícone da ordem -->
                  <div class="flex-shrink-0">
                    <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center">
                      <svg class="h-4 w-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                      </svg>
                    </div>
                  </div>

                  <!-- Informações da ordem -->
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center space-x-2">
                      <h3 class="text-sm font-medium text-gray-900">
                        {{ workOrder.order_number }}
                      </h3>
                      <span
                        :class="`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                          workOrder.status === 'completed' ? 'bg-green-100 text-green-800' :
                          workOrder.status === 'in_progress' ? 'bg-yellow-100 text-yellow-800' :
                          workOrder.status === 'cancelled' ? 'bg-red-100 text-red-800' :
                          workOrder.status === 'on_hold' ? 'bg-orange-100 text-orange-800' :
                          'bg-gray-100 text-gray-800'
                        }`"
                      >
                        {{ workOrder.status_text }}
                      </span>
                      <span
                        :class="`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                          workOrder.priority_level === 'emergency' ? 'bg-purple-100 text-purple-800' :
                          workOrder.priority_level === 'urgent' ? 'bg-red-100 text-red-800' :
                          workOrder.priority_level === 'high' ? 'bg-orange-100 text-orange-800' :
                          workOrder.priority_level === 'medium' ? 'bg-yellow-100 text-yellow-800' :
                          'bg-green-100 text-green-800'
                        }`"
                      >
                        {{ workOrder.priority_level_text }}
                      </span>
                    </div>
                    <p class="text-sm text-gray-500">
                      {{ workOrder.client?.name }} - {{ workOrder.address?.street }}, {{ workOrder.address?.number }} - {{ workOrder.address?.city }}/{{ workOrder.address?.state }}
                    </p>
                    <p class="text-sm text-gray-500">
                      {{ formatDate(workOrder.scheduled_date) }} • {{ workOrder.technician?.name }}
                    </p>
                  </div>
                </div>

                <!-- Descrição -->
                <div class="mt-3 ml-11">
                  <div v-if="workOrder.description" class="text-sm text-gray-600 mb-2">
                    <span class="font-medium">Descrição:</span> {{ workOrder.description }}
                  </div>
                  <div v-if="workOrder.observations" class="text-sm text-gray-600">
                    <span class="font-medium">Observações:</span> {{ workOrder.observations }}
                  </div>
                </div>
              </div>

              <!-- Ações -->
              <div class="flex items-center space-x-2">
                <Link
                  :href="route('work-orders.show', workOrder.id)"
                  class="text-green-600 hover:text-green-900 text-sm font-medium"
                >
                  Ver Detalhes
                </Link>
                <Link
                  :href="route('work-orders.edit', workOrder.id)"
                  class="text-blue-600 hover:text-blue-900 text-sm font-medium"
                >
                  Editar
                </Link>
                <button
                  @click="deleteWorkOrder(workOrder)"
                  class="inline-flex items-center text-red-600 hover:text-red-900 text-sm font-medium"
                  title="Excluir ordem de serviço"
                >
                  <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                  </svg>
                  Excluir
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Paginação -->
        <Pagination
          v-if="workOrders.data.length > 0"
          :links="workOrders.links"
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
  workOrders: Object,
  filters: Object,
  clients: Array,
  addresses: Array,
  technicians: Array,
  services: Array,
});

const filters = ref({
  client_id: props.filters?.client_id || '',
  address_id: props.filters?.address_id || '',
  technician_id: props.filters?.technician_id || '',
  status: props.filters?.status || '',
  priority_level: props.filters?.priority_level || '',
  service_id: props.filters?.service_id || '',
  date_from: props.filters?.date_from || '',
  date_to: props.filters?.date_to || '',
});

const applyFilters = () => {
  router.get(route('work-orders.index'), filters.value, {
    preserveState: true,
    preserveScroll: true,
  });
};

const clearFilters = () => {
  filters.value = {
    client_id: '',
    address_id: '',
    technician_id: '',
    status: '',
    priority_level: '',
    service_id: '',
    date_from: '',
    date_to: '',
  };
  router.get(route('work-orders.index'));
};

const formatDate = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleDateString('pt-BR');
};

const deleteWorkOrder = (workOrder) => {
  if (confirm(`Tem certeza que deseja excluir a ordem de serviço ${workOrder.order_number}?\n\nCliente: ${workOrder.client?.name}\nData: ${formatDate(workOrder.scheduled_date)}\n\nEsta ação não pode ser desfeita.`)) {
    router.delete(route('work-orders.destroy', workOrder.id), {
      preserveScroll: true,
      onSuccess: () => {
        // Sucesso - a página será recarregada automaticamente
      },
      onError: (errors) => {
        alert('Erro ao excluir ordem de serviço: ' + (errors.message || 'Erro desconhecido'));
      }
    });
  }
};
</script>
