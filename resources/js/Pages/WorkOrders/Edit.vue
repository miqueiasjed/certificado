<template>
  <AuthenticatedLayout>
    <PageHeader
      title="Editar Ordem de Serviço"
      subtitle="Modifique as informações da ordem de serviço"
    />

    <div class="max-w-6xl mx-auto space-y-6">
      <!-- Formulário de Edição -->
      <Card>
        <div class="p-6">
            <form @submit.prevent="submit" class="space-y-6">
              <!-- Cliente (Readonly) -->
              <div>
                <label for="client_id" class="block text-sm font-medium text-gray-700 mb-2">
                  Cliente
                </label>
                <input
                  id="client_id"
                  type="text"
                  readonly
                  :value="props.workOrder.client?.name || 'Cliente não encontrado'"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm bg-gray-50 text-gray-600 cursor-not-allowed"
                />
                <p class="mt-1 text-xs text-gray-500">
                  O cliente não pode ser alterado após a criação da ordem de serviço
                </p>
              </div>

              <!-- Endereço (Readonly) -->
              <div>
                <label for="address_id" class="block text-sm font-medium text-gray-700 mb-2">
                  Endereço
                </label>
                <input
                  id="address_id"
                  type="text"
                  readonly
                  :value="getAddressDisplayText()"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm bg-gray-50 text-gray-600 cursor-not-allowed"
                />
                <p class="mt-1 text-xs text-gray-500">
                  O endereço não pode ser alterado após a criação da ordem de serviço
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
                  type="date"
                  id="scheduled_date"
                  v-model="form.scheduled_date"
                  required
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.scheduled_date }"
                />
                <p v-if="form.errors.scheduled_date" class="mt-1 text-sm text-red-600">
                  {{ form.errors.scheduled_date }}
                </p>
              </div>

              <!-- Horário de Início -->
              <div>
                <label for="start_time" class="block text-sm font-medium text-gray-700 mb-2">
                  Horário de Início
                </label>
                <input
                  type="datetime-local"
                  id="start_time"
                  v-model="form.start_time"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.start_time }"
                />
                <p v-if="form.errors.start_time" class="mt-1 text-sm text-red-600">
                  {{ form.errors.start_time }}
                </p>
              </div>

              <!-- Serviço -->
              <div>
                <label for="service_id" class="block text-sm font-medium text-gray-700 mb-2">
                  Serviço *
                </label>
                <select
                  id="service_id"
                  v-model="form.service_id"
                  required
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.service_id }"
                >
                  <option value="">Selecione um serviço</option>
                  <option v-for="service in services" :key="service.id" :value="service.id">
                    {{ service.name }}
                  </option>
                </select>
                <p v-if="form.errors.service_id" class="mt-1 text-sm text-red-600">
                  {{ form.errors.service_id }}
                </p>
              </div>

              <!-- Horário de Término -->
              <div>
                <label for="end_time" class="block text-sm font-medium text-gray-700 mb-2">
                  Horário de Término
                </label>
                <input
                  type="datetime-local"
                  id="end_time"
                  v-model="form.end_time"
                  @input="onEndTimeChange"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.end_time }"
                />
                <p v-if="form.errors.end_time" class="mt-1 text-sm text-red-600">
                  {{ form.errors.end_time }}
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


              <!-- Descrição -->
              <div>
                <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                  Descrição
                </label>
                <textarea
                  id="description"
                  v-model="form.description"
                  rows="3"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.description }"
                  placeholder="Descreva o serviço a ser realizado..."
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
                  placeholder="Observações adicionais..."
                ></textarea>
                <p v-if="form.errors.observations" class="mt-1 text-sm text-red-600">
                  {{ form.errors.observations }}
                </p>
              </div>
              <!-- Status Ativo -->
              <div class="flex items-center">
                <input
                  id="active"
                  type="checkbox"
                  v-model="form.active"
                  class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded"
                />
                <label for="active" class="ml-2 block text-sm text-gray-900">
                  Ordem de serviço ativa
                </label>
              </div>

              <!-- Botões -->
              <div class="flex justify-end space-x-3">
                <Link :href="`/work-orders/${workOrder.id}`" class="btn-secondary">
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
                  {{ form.processing ? 'Salvando...' : 'Salvar Alterações' }}
                </button>
              </div>
            </form>
        </div>
      </Card>
    </div>


    <!-- Modal para criação rápida de tipo de serviço -->
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref, onMounted } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  workOrder: Object,
  clients: Array,
  addresses: Array,
  technicians: Array,
  products: Array,
  services: Array,
  rooms: Array,
});




// Função para formatar data para datetime-local (sem conversão de fuso horário)
const formatDateForInput = (dateString) => {
  if (!dateString) return '';
  try {
    // Usar regex para extrair data e hora sem conversão de fuso
    const match = dateString.match(/^(\d{4}-\d{2}-\d{2})\s+(\d{2}:\d{2}):\d{2}/);
    if (match) {
      return `${match[1]}T${match[2]}`;
    }

    // Fallback para formato ISO
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return '';

    // Usar getFullYear, getMonth, etc. para evitar conversão de fuso
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    const hours = String(date.getHours()).padStart(2, '0');
    const minutes = String(date.getMinutes()).padStart(2, '0');

    return `${year}-${month}-${day}T${hours}:${minutes}`;
  } catch (error) {
    return '';
  }
};

// Função para formatar data para date input (sem conversão de fuso horário)
const formatDateForDateInput = (dateString) => {
  if (!dateString) return '';
  try {
    // Usar regex para extrair apenas a data
    const match = dateString.match(/^(\d{4}-\d{2}-\d{2})/);
    if (match) {
      return match[1];
    }

    // Fallback
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return '';

    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');

    return `${year}-${month}-${day}`;
  } catch (error) {
    return '';
  }
};



const form = useForm({
  priority_level: props.workOrder.priority_level || '',
  scheduled_date: formatDateForDateInput(props.workOrder.scheduled_date),
  start_time: formatDateForInput(props.workOrder.start_time),
  end_time: formatDateForInput(props.workOrder.end_time),
  status: props.workOrder.status || '',
  service_id: props.workOrder.service_id || '',
  description: props.workOrder.description || '',
  observations: props.workOrder.observations || '',
  active: props.workOrder.active !== undefined ? props.workOrder.active : true,
});




// Função para atualizar status automaticamente quando end_time for preenchido
const onEndTimeChange = () => {
  if (form.end_time) {
    // Se preencheu end_time, status vira "Concluída"
    if (form.status !== 'completed') {
      form.status = 'completed';
    }
  } else {
    // Se limpou end_time, status volta para "Em Andamento" ou "Agendada"
    if (form.status === 'completed') {
      // Se já tinha start_time, volta para "Em Andamento", senão "Agendada"
      form.status = form.start_time ? 'in_progress' : 'scheduled';
    }
  }
};

// Função para obter o texto de exibição do endereço
const getAddressDisplayText = () => {
  if (!props.workOrder.address) return 'Endereço não encontrado';

  const address = props.workOrder.address;
  return `${address.nickname} - ${address.street}, ${address.number} - ${address.city}/${address.state}`;
};

const submit = () => {
  form.put(`/work-orders/${props.workOrder.id}`, {
    onSuccess: () => {
      // Ordem de serviço atualizada com sucesso
    },
    onError: (errors) => {
      console.error('Error details:', JSON.stringify(errors, null, 2));
    }
  });
};


</script>
