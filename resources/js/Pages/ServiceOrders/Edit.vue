<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Editar Ordem de Serviço" :description="`Editando: #${serviceOrder.id}`">
        <template #actions>
          <Link :href="`/service-orders/${serviceOrder.id}`" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
            </svg>
            Ver Ordem
          </Link>
          <Link href="/service-orders" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-4xl mx-auto">
      <form @submit.prevent="submitForm" class="space-y-6">
        <!-- Informações do Cliente -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Informações do Cliente</h3>
          </div>
          <div class="p-6 space-y-4">
            <div>
              <label for="client_id" class="block text-sm font-medium text-gray-700 mb-1">
                Cliente *
              </label>
              <select
                id="client_id"
                v-model="form.client_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                :class="{ 'border-red-500': errors.client_id }"
              >
                <option value="">Selecione um cliente</option>
                <option v-for="client in clients" :key="client.id" :value="client.id">
                  {{ client.name }} - {{ client.cnpj }}
                </option>
              </select>
              <p v-if="errors.client_id" class="mt-1 text-sm text-red-600">{{ errors.client_id }}</p>
            </div>
          </div>
        </Card>

        <!-- Informações do Serviço -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Informações do Serviço</h3>
          </div>
          <div class="p-6 space-y-4">
            <div>
              <label for="service_id" class="block text-sm font-medium text-gray-700 mb-1">
                Serviço *
              </label>
              <select
                id="service_id"
                v-model="form.service_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                :class="{ 'border-red-500': errors.service_id }"
              >
                <option value="">Selecione um serviço</option>
                <option v-for="service in services" :key="service.id" :value="service.id">
                  {{ service.name }} - {{ formatPrice(service.price) }}
                </option>
              </select>
              <p v-if="errors.service_id" class="mt-1 text-sm text-red-600">{{ errors.service_id }}</p>
            </div>
          </div>
        </Card>

        <!-- Informações da Ordem -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Informações da Ordem</h3>
          </div>
          <div class="p-6 space-y-4">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label for="status" class="block text-sm font-medium text-gray-700 mb-1">
                  Status *
                </label>
                <select
                  id="status"
                  v-model="form.status"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.status }"
                >
                  <option value="">Selecione o status</option>
                  <option value="pending">Pendente</option>
                  <option value="in_progress">Em Andamento</option>
                  <option value="completed">Concluída</option>
                  <option value="cancelled">Cancelada</option>
                </select>
                <p v-if="errors.status" class="mt-1 text-sm text-red-600">{{ errors.status }}</p>
              </div>

              <div>
                <label for="priority" class="block text-sm font-medium text-gray-700 mb-1">
                  Prioridade
                </label>
                <select
                  id="priority"
                  v-model="form.priority"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.priority }"
                >
                  <option value="">Selecione a prioridade</option>
                  <option value="low">Baixa</option>
                  <option value="medium">Média</option>
                  <option value="high">Alta</option>
                  <option value="urgent">Urgente</option>
                </select>
                <p v-if="errors.priority" class="mt-1 text-sm text-red-600">{{ errors.priority }}</p>
              </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label for="start_date" class="block text-sm font-medium text-gray-700 mb-1">
                  Data de Início
                </label>
                <input
                  type="date"
                  id="start_date"
                  v-model="form.start_date"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.start_date }"
                />
                <p v-if="errors.start_date" class="mt-1 text-sm text-red-600">{{ errors.start_date }}</p>
              </div>

              <div>
                <label for="due_date" class="block text-sm font-medium text-gray-700 mb-1">
                  Data de Conclusão Prevista
                </label>
                <input
                  type="date"
                  id="due_date"
                  v-model="form.due_date"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.due_date }"
                />
                <p v-if="errors.due_date" class="mt-1 text-sm text-red-600">{{ errors.due_date }}</p>
              </div>
            </div>

            <div>
              <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">
                Observações
              </label>
              <textarea
                id="notes"
                v-model="form.notes"
                rows="3"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                :class="{ 'border-red-500': errors.notes }"
                placeholder="Observações sobre a ordem de serviço..."
              ></textarea>
              <p v-if="errors.notes" class="mt-1 text-sm text-red-600">{{ errors.notes }}</p>
            </div>
          </div>
        </Card>

        <!-- Cômodos Atendidos -->
        <Card v-if="availableRooms.length > 0">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Cômodos Atendidos</h3>
            <p class="mt-1 text-sm text-gray-500">Selecione os cômodos que serão atendidos nesta OS</p>
          </div>
          <div class="p-6 space-y-4">
            <div v-for="room in availableRooms" :key="room.id" class="border border-gray-200 rounded-lg p-4">
              <div class="flex items-start">
                <input
                  :id="`room-${room.id}`"
                  type="checkbox"
                  :value="room.id"
                  v-model="selectedRoomIds"
                  class="mt-1 h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
                />
                <div class="ml-3 flex-1">
                  <label :for="`room-${room.id}`" class="block text-sm font-medium text-gray-900">
                    {{ room.full_name }}
                  </label>
                  <div v-if="isRoomSelected(room.id)" class="mt-2">
                    <label :for="`room-obs-${room.id}`" class="block text-sm text-gray-600 mb-1">
                      Observação do atendimento:
                    </label>
                    <textarea
                      :id="`room-obs-${room.id}`"
                      v-model="roomObservations[room.id]"
                      rows="2"
                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500 focus:border-transparent text-sm"
                      placeholder="Adicione observações sobre o atendimento neste cômodo..."
                    ></textarea>
                  </div>
                </div>
              </div>
            </div>
            <p v-if="availableRooms.length === 0 && form.client_id" class="text-sm text-gray-500 text-center py-4">
              Nenhum cômodo cadastrado para este cliente.
            </p>
          </div>
        </Card>

        <!-- Botões de Ação -->
        <div class="flex justify-end space-x-3">
          <Link :href="`/service-orders/${serviceOrder.id}`" class="btn-secondary">
            Cancelar
          </Link>
          <button type="submit" class="btn-primary" :disabled="form.processing">
            <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ form.processing ? 'Salvando...' : 'Salvar Alterações' }}
          </button>
        </div>
      </form>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import { ref, watch, onMounted } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  serviceOrder: Object,
  clients: Array,
  services: Array,
  errors: Object,
});

const form = useForm({
  client_id: props.serviceOrder.client_id || '',
  service_id: props.serviceOrder.service_id || '',
  status: props.serviceOrder.status || '',
  priority: props.serviceOrder.priority || '',
  start_date: props.serviceOrder.start_date || '',
  due_date: props.serviceOrder.due_date || '',
  notes: props.serviceOrder.notes || '',
  rooms: [],
});

const availableRooms = ref([]);
const selectedRoomIds = ref([]);
const roomObservations = ref({});

// Carregar rooms existentes da OS
onMounted(async () => {
  if (props.serviceOrder.rooms && props.serviceOrder.rooms.length > 0) {
    selectedRoomIds.value = props.serviceOrder.rooms.map(r => r.id);

    props.serviceOrder.rooms.forEach(room => {
      roomObservations.value[room.id] = room.pivot?.observation || null;
    });
  }

  // Buscar rooms disponíveis do cliente
  if (form.client_id) {
    await loadRooms(form.client_id);
  }
});

// Função para carregar rooms
const loadRooms = async (clientId) => {
  if (!clientId) {
    availableRooms.value = [];
    return;
  }

  try {
    const response = await fetch(`/service-orders/rooms/by-client?client_id=${clientId}`);
    const data = await response.json();
    availableRooms.value = data.rooms || [];
  } catch (error) {
    console.error('Erro ao buscar cômodos:', error);
  }
};

// Buscar rooms quando cliente é alterado
watch(() => form.client_id, async (newClientId, oldClientId) => {
  if (newClientId !== oldClientId) {
    selectedRoomIds.value = [];
    roomObservations.value = {};
    await loadRooms(newClientId);
  }
});

// Atualizar form.rooms quando rooms selecionados mudarem
watch([selectedRoomIds, roomObservations], () => {
  form.rooms = selectedRoomIds.value.map(roomId => ({
    id: roomId,
    observation: roomObservations.value[roomId] || null
  }));
}, { deep: true });

const isRoomSelected = (roomId) => {
  return selectedRoomIds.value.includes(roomId);
};

const formatPrice = (price) => {
  if (!price) return 'Preço não informado';
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(price);
};

const submitForm = () => {
  form.put(`/service-orders/${props.serviceOrder.id}`, {
    onSuccess: () => {
      // Sucesso
    },
  });
};
</script>
