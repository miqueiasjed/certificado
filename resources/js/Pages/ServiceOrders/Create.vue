<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Nova Ordem de Serviço" description="Crie uma nova ordem de serviço no sistema">
        <template #actions>
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

        <!-- Botões de Ação -->
        <div class="flex justify-end space-x-3">
          <Link href="/service-orders" class="btn-secondary">
            Cancelar
          </Link>
          <button type="submit" class="btn-primary" :disabled="form.processing">
            <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ form.processing ? 'Criando...' : 'Criar Ordem de Serviço' }}
          </button>
        </div>
      </form>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  clients: Array,
  services: Array,
  errors: Object,
});

const form = useForm({
  client_id: '',
  service_id: '',
  status: '',
  priority: '',
  start_date: '',
  due_date: '',
  notes: '',
});

const formatPrice = (price) => {
  if (!price) return 'Preço não informado';
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(price);
};

const submitForm = () => {
  form.post('/service-orders', {
    onSuccess: () => {
      // Sucesso
    },
  });
};
</script>
