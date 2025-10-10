<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Novo Certificado" description="Crie um novo certificado para o sistema">
        <template #actions>
          <Link href="/certificates" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-6xl mx-auto">
      <form @submit.prevent="submitForm" class="space-y-6">
        <!-- Informações Principais -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Informações Principais</h3>
          </div>
          <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div>
                <label for="client_id" class="block text-sm font-medium text-gray-700 mb-1">
                  Cliente *
                </label>
                <select
                  id="client_id"
                  v-model="form.client_id"
                  @change="loadClientAddresses"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.client_id }"
                  required
                >
                  <option value="">Selecione um cliente</option>
                  <option v-for="client in clients" :key="client.id" :value="client.id">
                    {{ client.name }} - {{ client.cnpj }}
                  </option>
                </select>
                <p v-if="errors.client_id" class="mt-1 text-sm text-red-600">{{ errors.client_id }}</p>
              </div>

              <div>
                <label for="address_id" class="block text-sm font-medium text-gray-700 mb-1">
                  Endereço do Cliente *
                </label>
                <select
                  id="address_id"
                  v-model="form.address_id"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.address_id }"
                  :disabled="!form.client_id"
                  required
                >
                  <option value="">Selecione um endereço</option>
                  <option v-for="address in clientAddresses" :key="address.id" :value="address.id">
                    {{ address.nickname }} - {{ address.street }}, {{ address.number }}
                  </option>
                </select>
                <p v-if="errors.address_id" class="mt-1 text-sm text-red-600">{{ errors.address_id }}</p>
              </div>

              <div>
                <label for="execution_date" class="block text-sm font-medium text-gray-700 mb-1">
                  Data da Execução *
                </label>
                <input
                  type="date"
                  id="execution_date"
                  v-model="form.execution_date"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.execution_date }"
                  required
                />
                <p v-if="errors.execution_date" class="mt-1 text-sm text-red-600">{{ errors.execution_date }}</p>
              </div>

              <div>
                <label for="warranty" class="block text-sm font-medium text-gray-700 mb-1">
                  Garantia
                </label>
                <input
                  type="date"
                  id="warranty"
                  v-model="form.warranty"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.warranty }"
                />
                <p v-if="errors.warranty" class="mt-1 text-sm text-red-600">{{ errors.warranty }}</p>
              </div>
            </div>
          </div>
        </Card>

        <!-- Produtos -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex justify-between items-center">
              <h3 class="text-lg font-medium text-gray-900">Produtos</h3>
              <button
                type="button"
                @click="addProduct"
                class="px-3 py-1 text-sm bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-colors"
              >
                <svg class="w-4 h-4 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Adicionar Produto
              </button>
            </div>
          </div>
          <div class="p-6">
            <div v-if="form.products.length === 0" class="text-center py-8 text-gray-500">
              <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
              </svg>
              <p class="mt-2">Nenhum produto adicionado</p>
              <p class="text-sm">Clique em "Adicionar Produto" para começar</p>
              <div class="mt-4 flex justify-center">
                <button
                  type="button"
                  @click="addProduct"
                  class="px-3 py-1 text-sm bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-colors"
                >
                  Adicionar Produto
                </button>
              </div>
            </div>

            <div v-else class="space-y-4">
              <div
                v-for="(product, index) in form.products"
                :key="index"
                class="grid grid-cols-1 md:grid-cols-4 gap-4 p-4 border border-gray-200 rounded-lg bg-gray-50"
              >
                <div class="md:col-span-3">
                  <label :for="`product_${index}`" class="block text-sm font-medium text-gray-700 mb-1">
                    Produto {{ index + 1 }}
                  </label>
                  <select
                    :id="`product_${index}`"
                    v-model="product.product_id"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                    :class="{ 'border-red-500': errors[`products.${index}.product_id`] }"
                  >
                    <option value="">Selecione um produto</option>
                    <option v-for="prod in products" :key="prod.id" :value="prod.id">
                      {{ prod.name }}
                    </option>
                  </select>
                  <p v-if="errors[`products.${index}.product_id`]" class="mt-1 text-sm text-red-600">
                    {{ errors[`products.${index}.product_id`] }}
                  </p>
                </div>

                <div class="flex items-end">
                  <button
                    type="button"
                    @click="removeProduct(index)"
                    class="w-full px-3 py-2 bg-red-500 text-white rounded-md hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-colors flex items-center justify-center"
                    title="Remover produto"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                    <span class="ml-2 text-sm">Remover</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </Card>

        <!-- Serviços -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex justify-between items-center">
              <h3 class="text-lg font-medium text-gray-900">Serviços</h3>
              <button
                type="button"
                @click="addService"
                class="px-3 py-1 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors"
              >
                <svg class="w-4 h-4 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Adicionar Serviço
              </button>
            </div>
          </div>
          <div class="p-6">
            <div v-if="form.services.length === 0" class="text-center py-8 text-gray-500">
              <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <p class="mt-2">Nenhum serviço adicionado</p>
              <p class="text-sm">Clique em "Adicionar Serviço" para começar</p>
            </div>

            <div v-else class="space-y-4">
              <div
                v-for="(service, index) in form.services"
                :key="index"
                class="grid grid-cols-1 md:grid-cols-4 gap-4 p-4 border border-gray-200 rounded-lg bg-gray-50"
              >
                <div class="md:col-span-3">
                  <label :for="`service_${index}`" class="block text-sm font-medium text-gray-700 mb-1">
                    Serviço {{ index + 1 }}
                  </label>
                  <select
                    :id="`service_${index}`"
                    v-model="service.service_id"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                    :class="{ 'border-red-500': errors[`services.${index}.service_id`] }"
                  >
                    <option value="">Selecione um serviço</option>
                    <option v-for="serv in services" :key="serv.id" :value="serv.id">
                      {{ serv.name }} - {{ serv.category }}
                    </option>
                  </select>
                  <p v-if="errors[`services.${index}.service_id`]" class="mt-1 text-sm text-red-600">
                    {{ errors[`services.${index}.service_id`] }}
                  </p>
                </div>

                <div class="flex items-end">
                  <button
                    type="button"
                    @click="removeService(index)"
                    class="w-full px-3 py-2 bg-red-500 text-white rounded-md hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-colors flex items-center justify-center"
                    title="Remover serviço"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                    <span class="ml-2 text-sm">Remover</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </Card>

        <!-- Procedimento Utilizado -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Procedimento Utilizado</h3>
          </div>
          <div class="p-6">
            <div>
              <label for="procedure_used" class="block text-sm font-medium text-gray-700 mb-1">
                Procedimento Utilizado *
              </label>
              <textarea
                id="procedure_used"
                v-model="form.procedure_used"
                rows="4"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                :class="{ 'border-red-500': errors.procedure_used }"
                placeholder="Descreva detalhadamente o procedimento utilizado..."
                required
              ></textarea>
              <p v-if="errors.procedure_used" class="mt-1 text-sm text-red-600">{{ errors.procedure_used }}</p>
            </div>
          </div>
        </Card>

        <!-- Observações -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Observações</h3>
          </div>
          <div class="p-6">
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
                placeholder="Observações adicionais sobre o certificado..."
              ></textarea>
              <p v-if="errors.notes" class="mt-1 text-sm text-red-600">{{ errors.notes }}</p>
            </div>
          </div>
        </Card>



        <!-- Botões de Ação -->
        <div class="flex justify-end space-x-3">
          <Link href="/certificates" class="btn-secondary">
            Cancelar
          </Link>
          <button type="submit" class="btn-primary" :disabled="form.processing">
            <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ form.processing ? 'Criando...' : 'Criar Certificado' }}
          </button>
        </div>
      </form>
    </div>




  </AuthenticatedLayout>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  clients: Array,
  products: Array,
  services: Array,
  activeIngredients: Array,
  chemicalGroups: Array,
  antidotes: Array,
  organRegistrations: Array,
  errors: Object,
});

const clientAddresses = ref([]);

const form = useForm({
  client_id: '',
  address_id: '',
  products: [],
  services: [],
  execution_date: '',
  warranty: '',
  notes: '',
  procedure_used: '',
});






onMounted(() => {
  // Inicialização do componente
});

watch(() => form.client_id, (val) => {
  loadClientAddresses();
});

const addProduct = () => {
  form.products.push({
    product_id: '',
  });
};

const removeProduct = (index) => {
  form.products.splice(index, 1);
};

const addService = () => {
  form.services.push({
    service_id: '',
  });
};

const removeService = (index) => {
  form.services.splice(index, 1);
};



const loadClientAddresses = async () => {
  if (!form.client_id) {
    clientAddresses.value = [];
    form.address_id = '';
    return;
  }

  try {
    const response = await fetch(`/api/clients/${form.client_id}/addresses`);
    const data = await response.json();
    clientAddresses.value = data.addresses || [];
    form.address_id = '';
  } catch (error) {
    console.error('Erro ao carregar endereços:', error);
    clientAddresses.value = [];
  }
};

const submitForm = () => {
  form.post('/certificates', {
    onSuccess: () => {
      // Sucesso
    },
  });
};



</script>
