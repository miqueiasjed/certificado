<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Editar Certificado" :description="`Editando: #${certificate.id}`">
        <template #actions>
          <Link :href="`/certificates/${certificate.id}`" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
            </svg>
            Ver Certificado
          </Link>
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
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
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
                >
                  <option value="">Selecione um cliente</option>
                  <option v-for="client in clients" :key="client.id" :value="client.id" :selected="client.id === form.client_id">
                    {{ client.name }} - {{ client.cnpj }}
                  </option>
                </select>
                <p v-if="errors.client_id" class="mt-1 text-sm text-red-600">{{ errors.client_id }}</p>
              </div>

              <!-- Campo de endereço - só aparece quando não há OS selecionada -->
              <div v-if="!form.work_order_id">
                <label for="address_id" class="block text-sm font-medium text-gray-700 mb-1">
                  Endereço *
                </label>
                <select
                  id="address_id"
                  v-model="form.address_id"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.address_id }"
                >
                  <option value="">Selecione um endereço</option>
                  <option v-for="address in clientAddresses" :key="address.id" :value="address.id">
                    {{ address.nickname }} - {{ address.street }}, {{ address.number }}, {{ address.district }}, {{ address.city }}/{{ address.state }}
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

              <div>
                <label for="work_order_id" class="block text-sm font-medium text-gray-700 mb-1">
                  Ordem de Serviço
                </label>
                <select
                  id="work_order_id"
                  v-model="form.work_order_id"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.work_order_id }"
                >
                  <option value="">Selecione uma OS (opcional)</option>
                  <option v-for="wo in workOrders" :key="wo.id" :value="wo.id">
                    {{ wo.order_number }} - {{ wo.client.name }} - {{ new Date(wo.scheduled_date).toLocaleDateString('pt-BR') }}
                  </option>
                </select>
                <p v-if="errors.work_order_id" class="mt-1 text-sm text-red-600">{{ errors.work_order_id }}</p>
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
            </div>

            <div v-else class="space-y-4">
              <div
                v-for="(product, index) in form.products"
                :key="index"
                class="grid grid-cols-1 gap-4 p-4 border border-gray-200 rounded-lg bg-gray-50"
              >
                <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                  <!-- Produto -->
                  <div class="md:col-span-5">
                    <label :for="`product_${index}`" class="block text-sm font-medium text-gray-700 mb-1">
                      Produto {{ index + 1 }} *
                    </label>
                    <select
                      :id="`product_${index}`"
                      v-model="product.product_id"
                      @change="checkDuplicateProducts(index)"
                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                      :class="{ 'border-red-500': errors[`products.${index}.product_id`] || product.isDuplicate }"
                      required
                    >
                      <option value="">Selecione um produto</option>
                      <option v-for="prod in products" :key="prod.id" :value="prod.id" :selected="prod.id === product.product_id">
                        {{ prod.name }}
                      </option>
                    </select>
                    <p v-if="product.isDuplicate" class="mt-1 text-sm text-red-600">
                      Este produto já foi adicionado
                    </p>
                    <p v-else-if="errors[`products.${index}.product_id`]" class="mt-1 text-sm text-red-600">
                      {{ errors[`products.${index}.product_id`] }}
                    </p>
                  </div>

                  <!-- Quantidade -->
                  <div class="md:col-span-3">
                    <label :for="`quantity_${index}`" class="block text-sm font-medium text-gray-700 mb-1">
                      Quantidade
                    </label>
                    <input
                      :id="`quantity_${index}`"
                      v-model="product.quantity"
                      type="number"
                      step="0.01"
                      min="0"
                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                      placeholder="0.00"
                    />
                  </div>

                  <!-- Unidade -->
                  <div class="md:col-span-3">
                    <label :for="`unit_${index}`" class="block text-sm font-medium text-gray-700 mb-1">
                      Unidade
                    </label>
                    <select
                      :id="`unit_${index}`"
                      v-model="product.unit"
                      class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                    >
                      <option value="">Selecione</option>
                      <option value="unidade">Unidade</option>
                      <option value="kg">Quilograma (kg)</option>
                      <option value="g">Grama (g)</option>
                      <option value="mg">Miligrama (mg)</option>
                      <option value="L">Litro (L)</option>
                      <option value="mL">Mililitro (mL)</option>
                      <option value="caixa">Caixa</option>
                      <option value="pacote">Pacote</option>
                    </select>
                  </div>

                  <!-- Botão Remover -->
                  <div class="md:col-span-1 flex items-end">
                    <button
                      type="button"
                      @click="removeProduct(index)"
                      class="w-full px-3 py-2 bg-red-500 text-white rounded-md hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-colors flex items-center justify-center"
                      title="Remover produto"
                    >
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                      </svg>
                    </button>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </Card>

        <!-- Serviço -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Serviço *</h3>
          </div>
          <div class="p-6">
            <div>
              <label for="service_id" class="block text-sm font-medium text-gray-700 mb-1">
                Selecione o Serviço *
              </label>
              <select
                id="service_id"
                v-model="form.service_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                :class="{ 'border-red-500': errors.service_id }"
                required
              >
                <option value="">Selecione um serviço</option>
                <option v-for="serv in services" :key="serv.id" :value="serv.id">
                  {{ serv.name }} - {{ serv.category }}
                </option>
              </select>
              <p v-if="errors.service_id" class="mt-1 text-sm text-red-600">
                {{ errors.service_id }}
              </p>
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
          <Link :href="`/certificates/${certificate.id}`" class="btn-secondary">
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
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  certificate: Object,
  clients: Array,
  addresses: Array,
  products: Array,
  services: Array,
  technicians: Array,
  workOrders: Array,
  errors: Object,
});


// Função para formatar data para input type="date"
const formatDateForInput = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toISOString().split('T')[0];
};

const form = useForm({
  client_id: props.certificate.client_id || '',
  address_id: props.certificate.address_id || '',
  work_order_id: props.certificate.work_order_id || '',
  products: props.certificate.products ? props.certificate.products.map(p => ({
    product_id: p.id,
    quantity: p.pivot?.quantity || null,
    unit: p.pivot?.unit || '',
    isDuplicate: false
  })) : [],
  service_id: props.certificate.service_id || '',
  execution_date: props.certificate.execution_date ? formatDateForInput(props.certificate.execution_date) : '',
  warranty: props.certificate.warranty ? formatDateForInput(props.certificate.warranty) : '',
  notes: props.certificate.notes || '',
  procedure_used: props.certificate.procedure_used || '',
});

// Variáveis reativas
const clientAddresses = ref(props.addresses || []);

// Função para carregar endereços do cliente
const loadClientAddresses = async () => {
  if (!form.client_id) {
    clientAddresses.value = [];
    return;
  }

  try {
    router.get(`/api/clients/${form.client_id}/addresses`, {}, {
      preserveState: true,
      preserveScroll: true,
      only: [], // Não atualizar props, só obter dados
      onSuccess: (page) => {
        // Extrair endereços da resposta
        if (page.props.addresses) {
          clientAddresses.value = page.props.addresses;
        }
      },
      onError: (error) => {
        console.error('Erro ao carregar endereços:', error);
        clientAddresses.value = [];
      }
    });
  } catch (error) {
    console.error('Erro ao carregar endereços:', error);
    clientAddresses.value = [];
  }
};


const addProduct = () => {
  form.products.push({
    product_id: '',
    quantity: null,
    unit: '',
    isDuplicate: false,
  });
};

const removeProduct = (index) => {
  form.products.splice(index, 1);
  // Revalidar duplicatas após remover
  form.products.forEach((_, idx) => checkDuplicateProducts(idx));
};

const checkDuplicateProducts = (currentIndex) => {
  const currentProduct = form.products[currentIndex];
  if (!currentProduct.product_id) {
    currentProduct.isDuplicate = false;
    return;
  }

  // Verificar se o produto já existe em outro índice
  const hasDuplicate = form.products.some((product, index) =>
    index !== currentIndex &&
    product.product_id &&
    product.product_id === currentProduct.product_id
  );

  currentProduct.isDuplicate = hasDuplicate;
};

const submitForm = () => {
  // Verificar se há produtos duplicados
  const hasDuplicates = form.products.some(product => product.isDuplicate);
  if (hasDuplicates) {
    alert('Por favor, remova os produtos duplicados antes de continuar.');
    return;
  }

  // Validação básica antes de enviar
  if (!form.execution_date) {
    alert('O campo Data da Execução é obrigatório.');
    return;
  }

  // Usar form.put() do Inertia com rota nomeada
  form.put(route('certificates.update', props.certificate.id), {
    preserveScroll: true,
    onSuccess: () => {
      // Sucesso - o Inertia já redireciona automaticamente
    },
    onError: (errors) => {
      console.error('Erro ao atualizar certificado:', errors);
    }
  });
};

</script>
