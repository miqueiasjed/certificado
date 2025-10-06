<template>
  <AuthenticatedLayout>
    <PageHeader
      title="Nova Ordem de Serviço"
      subtitle="Crie uma nova ordem de serviço"
    />

    <div class="max-w-6xl mx-auto">
      <Card>
        <form @submit.prevent="submit" class="p-6 space-y-6">
          <!-- Primeira linha: Cliente, Endereço, Técnico -->
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- Cliente -->
            <div>
              <label for="client_id" class="block text-sm font-medium text-gray-700 mb-2">
                Cliente *
              </label>
              <select
                id="client_id"
                v-model="form.client_id"
                required
                @change="onClientChange"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.client_id }"
              >
                <option value="">Selecione um cliente</option>
                <option v-for="client in clients" :key="client.id" :value="client.id">
                  {{ client.name }}
                </option>
              </select>
              <p v-if="form.errors.client_id" class="mt-1 text-sm text-red-600">
                {{ form.errors.client_id }}
              </p>
            </div>

            <!-- Endereço -->
            <div>
              <label for="address_id" class="block text-sm font-medium text-gray-700 mb-2">
                Endereço *
              </label>
              <select
                id="address_id"
                v-model="form.address_id"
                required
                :disabled="!form.client_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
                :class="{ 'border-red-500': form.errors.address_id }"
              >
                <option value="">Selecione um endereço</option>
                <option v-for="address in filteredAddresses" :key="address.id" :value="address.id">
                  {{ address.nickname }} - {{ address.street }}, {{ address.number }} - {{ address.city }}/{{ address.state }}
                </option>
              </select>
              <p v-if="form.errors.address_id" class="mt-1 text-sm text-red-600">
                {{ form.errors.address_id }}
              </p>
            </div>

            <!-- Técnico -->
            <div>
              <label for="technician_id" class="block text-sm font-medium text-gray-700 mb-2">
                Técnico *
              </label>
              <div class="flex gap-2">
                <select
                  id="technician_id"
                  v-model="form.technician_id"
                  required
                  class="flex-1 h-10 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.technician_id }"
                >
                  <option value="">Selecione um técnico</option>
                  <option v-for="technician in technicians" :key="technician.id" :value="technician.id">
                    {{ technician.name }} - {{ technician.specialty }}
                  </option>
                </select>
                <button
                  type="button"
                  @click="showTechnicianModal = true"
                  class="h-10 w-10 text-green-600 border border-green-300 rounded-md hover:bg-green-50 focus:outline-none focus:ring-2 focus:ring-green-500 transition-colors flex items-center justify-center"
                  title="Adicionar novo técnico"
                >
                  <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                  </svg>
                </button>
              </div>
              <p v-if="form.errors.technician_id" class="mt-1 text-sm text-red-600">
                {{ form.errors.technician_id }}
              </p>
            </div>
          </div>

          <!-- Segunda linha: Tipo de Ordem, Nível de Prioridade, Data Agendada -->
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- Tipo de Ordem -->
            <div>
              <label for="order_type" class="block text-sm font-medium text-gray-700 mb-2">
                Tipo de Ordem *
              </label>
              <select
                id="order_type"
                v-model="form.order_type"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.order_type }"
              >
                <option value="">Selecione o tipo de ordem</option>
                <option value="preventive">Preventiva</option>
                <option value="corrective">Corretiva</option>
                <option value="emergency">Emergência</option>
                <option value="inspection">Inspeção</option>
                <option value="maintenance">Manutenção</option>
                <option value="other">Outros</option>
              </select>
              <p v-if="form.errors.order_type" class="mt-1 text-sm text-red-600">
                {{ form.errors.order_type }}
              </p>
            </div>

            <!-- Nível de Prioridade -->
            <div>
              <label for="priority_level" class="block text-sm font-medium text-gray-700 mb-2">
                Nível de Prioridade *
              </label>
              <select
                id="priority_level"
                v-model="form.priority_level"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.priority_level }"
              >
                <option value="">Selecione o nível de prioridade</option>
                <option value="low">Baixa</option>
                <option value="medium">Média</option>
                <option value="high">Alta</option>
                <option value="urgent">Urgente</option>
                <option value="emergency">Emergência</option>
              </select>
              <p v-if="form.errors.priority_level" class="mt-1 text-sm text-red-600">
                {{ form.errors.priority_level }}
              </p>
            </div>

            <!-- Data Agendada -->
            <div>
              <label for="scheduled_date" class="block text-sm font-medium text-gray-700 mb-2">
                Data Agendada *
              </label>
              <input
                id="scheduled_date"
                v-model="form.scheduled_date"
                type="date"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.scheduled_date }"
              />
              <p v-if="form.errors.scheduled_date" class="mt-1 text-sm text-red-600">
                {{ form.errors.scheduled_date }}
              </p>
            </div>
          </div>

          <!-- Terceira linha: Horário de Início, Status, Status de Pagamento -->
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- Horário de Início -->
            <div>
              <label for="start_time" class="block text-sm font-medium text-gray-700 mb-2">
                Horário de Início
              </label>
              <input
                id="start_time"
                v-model="form.start_time"
                type="datetime-local"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.start_time }"
              />
              <p v-if="form.errors.start_time" class="mt-1 text-sm text-red-600">
                {{ form.errors.start_time }}
              </p>
            </div>

            <!-- Status -->
            <div>
              <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                Status *
              </label>
              <select
                id="status"
                v-model="form.status"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.status }"
              >
                <option value="">Selecione o status</option>
                <option value="pending">Pendente</option>
                <option value="scheduled">Agendada</option>
                <option value="in_progress">Em Andamento</option>
                <option value="completed">Concluída</option>
                <option value="cancelled">Cancelada</option>
                <option value="on_hold">Em Espera</option>
              </select>
              <p v-if="form.errors.status" class="mt-1 text-sm text-red-600">
                {{ form.errors.status }}
              </p>
            </div>

          </div>

          <!-- Campos de texto em largura total -->
          <!-- Descrição -->
          <div>
            <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
              Descrição *
            </label>
            <textarea
              id="description"
              v-model="form.description"
              rows="4"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.description }"
              placeholder="Descreva detalhadamente o serviço a ser realizado..."
            ></textarea>
            <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">
              {{ form.errors.description }}
            </p>
          </div>

          <!-- Observações -->
          <div>
            <label for="observations" class="block text-sm font-medium text-gray-700 mb-2">
              Observações
            </label>
            <textarea
              id="observations"
              v-model="form.observations"
              rows="3"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.observations }"
              placeholder="Adicione observações adicionais..."
            ></textarea>
            <p v-if="form.errors.observations" class="mt-1 text-sm text-red-600">
              {{ form.errors.observations }}
            </p>
          </div>

          <!-- Status Ativo -->
          <div class="flex items-center">
            <input
              id="active"
              v-model="form.active"
              type="checkbox"
              class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded"
            />
            <label for="active" class="ml-2 block text-sm text-gray-900">
              Ordem de serviço ativa
            </label>
          </div>

          <!-- Botões -->
          <div class="flex justify-end space-x-3">
            <Link :href="route('work-orders.index')" class="btn-secondary">
              Cancelar
            </Link>
            <button
              type="submit"
              :disabled="form.processing"
              class="btn-primary"
            >
              <svg v-if="form.processing" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ form.processing ? 'Salvando...' : 'Salvar Ordem de Serviço' }}
            </button>
          </div>
        </form>
      </Card>
    </div>

    <!-- Modal de Criação Rápida de Técnico -->
    <QuickTechnicianModal
      :show="showTechnicianModal"
      @close="showTechnicianModal = false"
      @technician-created="onTechnicianCreated"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, watch, getCurrentInstance } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import QuickTechnicianModal from '@/Components/QuickTechnicianModal.vue';

const { proxy } = getCurrentInstance();

const props = defineProps({
  clients: Array,
  addresses: Array,
  technicians: Array,
  preselectedClient: Number,
  preselectedAddress: Number,
  preselectedTechnician: Number,
  errors: Object,
});

// Estado do modal
const showTechnicianModal = ref(false);

const form = useForm({
  client_id: props.preselectedClient || '',
  address_id: props.preselectedAddress || '',
  technician_id: props.preselectedTechnician || '',
  order_type: '',
  priority_level: '',
  scheduled_date: new Date().toISOString().slice(0, 10),
  start_time: '',
  status: 'pending',
  description: '',
  observations: '',
  active: true,
});

// Filtrar endereços baseado no cliente selecionado
const filteredAddresses = computed(() => {
  if (!form.client_id) return [];
  return props.addresses.filter(address => address.client_id == form.client_id);
});

// Limpar endereço quando cliente muda
const onClientChange = () => {
  form.address_id = '';
};

// Limpar endereço quando cliente é limpo
watch(() => form.client_id, (newValue) => {
  if (!newValue) {
    form.address_id = '';
  }
});

const submit = () => {
  form.post('/work-orders');
};

// Função para quando um técnico é criado
const onTechnicianCreated = (technician) => {
  // Selecionar automaticamente o técnico recém-criado
  form.technician_id = technician.id;

  // Recarregar a página para atualizar a lista de técnicos
  router.reload({ only: ['technicians'] });
};
</script>
