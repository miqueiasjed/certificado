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

              <!-- Segunda linha: Endereço e Técnicos -->
              <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
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

                <!-- Técnicos -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">
                    Técnicos *
                  </label>
                  <div class="space-y-2">
                    <div v-for="(technicianId, index) in form.technicians" :key="index" class="flex gap-2 items-center">
                      <select
                        v-model="form.technicians[index]"
                        class="flex-1 h-10 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                        :class="{ 'border-red-500': form.errors.technicians }"
                        required
                      >
                        <option value="">Selecione um técnico</option>
                        <option v-for="technician in getAvailableTechnicians(index)" :key="technician.id" :value="technician.id">
                          {{ technician.name }} - {{ technician.specialty }}
                        </option>
                      </select>
                      <button
                        type="button"
                        @click="removeTechnician(index)"
                        class="h-10 w-10 text-red-600 border border-red-300 rounded-md hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 transition-colors flex items-center justify-center"
                        title="Remover técnico"
                      >
                        <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                      </button>
                    </div>
                    <div class="flex gap-2">
                      <button
                        type="button"
                        @click="addTechnician"
                        class="px-3 py-2 text-green-600 border border-green-300 rounded-md hover:bg-green-50 focus:outline-none focus:ring-2 focus:ring-green-500 transition-colors text-sm"
                      >
                        + Adicionar Técnico
                      </button>
                    </div>
                  </div>
                  <p v-if="form.errors.technicians" class="mt-1 text-sm text-red-600">
                    {{ form.errors.technicians }}
                  </p>
                </div>
              </div>

              <!-- Tipo de Serviço -->
              <div>
                <label for="service_type_id" class="block text-sm font-medium text-gray-700 mb-2">
                  Tipo de Serviço *
                </label>
                <div class="flex gap-2">
                  <select
                    id="service_type_id"
                    v-model="form.service_type_id"
                    required
                    class="flex-1 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 h-10"
                    :class="{ 'border-red-500': form.errors.service_type_id }"
                  >
                    <option value="">Selecione o tipo de serviço</option>
                    <option v-for="serviceType in serviceTypes" :key="serviceType.id" :value="serviceType.id">
                      {{ serviceType.name }}
                    </option>
                  </select>
                  <button
                    type="button"
                    @click="showServiceTypeModal = true"
                    class="h-10 w-10 text-green-600 border border-green-300 rounded-md hover:bg-green-50 focus:outline-none focus:ring-2 focus:ring-green-500 transition-colors flex items-center justify-center"
                    title="Adicionar novo tipo de serviço"
                  >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                    </svg>
                  </button>
                </div>
                <p v-if="form.errors.service_type_id" class="mt-1 text-sm text-red-600">
                  {{ form.errors.service_type_id }}
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


              <!-- Produtos -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                  Produtos Utilizados
                </label>
                <div class="space-y-4">
                  <div v-for="(product, index) in form.products" :key="index" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                    <div class="md:col-span-4">
                      <select
                        v-model="product.id"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                      >
                        <option value="">Selecione um produto</option>
                        <option v-for="prod in availableProducts(index)" :key="prod.id" :value="prod.id">
                          {{ prod.name }}
                        </option>
                      </select>
                    </div>
                    <div class="md:col-span-2">
                      <input
                        v-model.number="product.quantity"
                        type="number"
                        min="1"
                        placeholder="Qtd"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                      />
                    </div>
                    <div class="md:col-span-5">
                      <input
                        v-model="product.observations"
                        type="text"
                        placeholder="Observações (opcional)"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                      />
                    </div>
                    <div class="md:col-span-1">
                      <button
                        type="button"
                        @click="removeProduct(index)"
                        class="w-full px-3 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500"
                      >
                        ×
                      </button>
                    </div>
                  </div>
                  <button
                    type="button"
                    @click="addProduct"
                    class="w-full px-4 py-2 border-2 border-dashed border-gray-300 rounded-md text-gray-600 hover:border-green-500 hover:text-green-600 focus:outline-none focus:ring-2 focus:ring-green-500"
                  >
                    + Adicionar Produto
                  </button>
                </div>
              </div>

              <!-- Serviços -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">
                  Serviços Realizados
                </label>
                <div class="space-y-4">
                  <div v-for="(service, index) in form.services" :key="index" class="grid grid-cols-1 md:grid-cols-11 gap-4 items-end">
                    <div class="md:col-span-5">
                      <select
                        v-model="service.id"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                      >
                        <option value="">Selecione um serviço</option>
                        <option v-for="serv in availableServices(index)" :key="serv.id" :value="serv.id">
                          {{ serv.name }}
                        </option>
                      </select>
                    </div>
                    <div class="md:col-span-5">
                      <input
                        v-model="service.observations"
                        type="text"
                        placeholder="Observações (opcional)"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                      />
                    </div>
                    <div class="md:col-span-1">
                      <button
                        type="button"
                        @click="removeService(index)"
                        class="w-full px-3 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500"
                      >
                        ×
                      </button>
                    </div>
                  </div>
                  <button
                    type="button"
                    @click="addService"
                    class="w-full px-4 py-2 border-2 border-dashed border-gray-300 rounded-md text-gray-600 hover:border-green-500 hover:text-green-600 focus:outline-none focus:ring-2 focus:ring-green-500"
                  >
                    + Adicionar Serviço
                  </button>
                </div>
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
    <QuickServiceTypeModal
      :show="showServiceTypeModal"
      @close="showServiceTypeModal = false"
      @service-type-created="onServiceTypeCreated"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import QuickServiceTypeModal from '@/Components/QuickServiceTypeModal.vue';

const props = defineProps({
  workOrder: Object,
  clients: Array,
  addresses: Array,
  technicians: Array,
  serviceTypes: Array,
  products: Array,
  services: Array,
});


// Estado do modal
const showServiceTypeModal = ref(false);


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
  client_id: String(props.workOrder.client_id || ''),
  technician_id: String(props.workOrder.technician_id || ''),
  technicians: props.workOrder.technicians && props.workOrder.technicians.length > 0
    ? props.workOrder.technicians.map(t => t.id)
    : (props.workOrder.technician_id ? [props.workOrder.technician_id] : ['']),
  service_type_id: String(props.workOrder.service_type_id || ''),
  products: props.workOrder.products && props.workOrder.products.length > 0
    ? props.workOrder.products.map(p => ({
        id: p.id,
        quantity: p.pivot.quantity || 1,
        observations: p.pivot.observations || ''
      }))
    : [{ id: '', quantity: 1, observations: '' }],
  services: props.workOrder.services && props.workOrder.services.length > 0
    ? props.workOrder.services.map(s => ({
        id: s.id,
        observations: s.pivot.observations || ''
      }))
    : [{ id: '', observations: '' }],
  priority_level: props.workOrder.priority_level || '',
  scheduled_date: formatDateForDateInput(props.workOrder.scheduled_date),
  start_time: formatDateForInput(props.workOrder.start_time),
  end_time: formatDateForInput(props.workOrder.end_time),
  status: props.workOrder.status || '',
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
  // Filtrar técnicos vazios ANTES de criar os dados do form
  const cleanedTechnicians = form.technicians.filter(id => id !== '' && id !== null && id !== undefined);

  // Filtrar produtos vazios ANTES de criar os dados do form
  const cleanedProducts = form.products.filter(product => product.id && product.id !== '');

  // Filtrar serviços vazios ANTES de criar os dados do form
  const cleanedServices = form.services.filter(service => service.id && service.id !== '');

  // Atualizar o form com dados limpos
  form.technicians = cleanedTechnicians;
  form.products = cleanedProducts;
  form.services = cleanedServices;


  form.put(`/work-orders/${props.workOrder.id}`, {
    onSuccess: () => {
      // Ordem de serviço atualizada com sucesso
    },
    onError: (errors) => {
      console.error('Error details:', JSON.stringify(errors, null, 2));
    }
  });
};

// Filtrar técnicos disponíveis para cada select (evitar duplicatas)
const getAvailableTechnicians = (currentIndex) => {
  const selectedIds = form.technicians.filter((id, index) => index !== currentIndex && id !== '');
  return props.technicians.filter(technician => !selectedIds.includes(technician.id));
};

// Funções para gerenciar técnicos
const addTechnician = () => {
  form.technicians.push('');
};

const removeTechnician = (index) => {
  form.technicians.splice(index, 1);
};

// Funções para gerenciar produtos
const addProduct = () => {
  form.products.push({ id: '', quantity: 1, observations: '' });
};

const removeProduct = (index) => {
  form.products.splice(index, 1);
};

// Funções para gerenciar serviços
const addService = () => {
  form.services.push({ id: '', observations: '' });
};

const removeService = (index) => {
  form.services.splice(index, 1);
};

// Computed para produtos disponíveis (filtrar já selecionados, exceto o atual)
const availableProducts = computed(() => {
  return (currentProductIndex) => {
    const selectedIds = form.products
      .map((p, index) => index !== currentProductIndex ? p.id : null)
      .filter(id => id);
    return props.products.filter(prod => !selectedIds.includes(prod.id));
  };
});

// Computed para serviços disponíveis (filtrar já selecionados, exceto o atual)
const availableServices = computed(() => {
  return (currentServiceIndex) => {
    const selectedIds = form.services
      .map((s, index) => index !== currentServiceIndex ? s.id : null)
      .filter(id => id);
    return props.services.filter(serv => !selectedIds.includes(serv.id));
  };
});

// Função para lidar com criação de novo tipo de serviço
const onServiceTypeCreated = (serviceType) => {
  // Selecionar o tipo de serviço recém-criado
  form.service_type_id = serviceType.id;

  // Recarregar a lista de tipos de serviço
  router.reload({ only: ['serviceTypes'] });
};

</script>
