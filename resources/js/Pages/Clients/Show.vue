<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Detalhes do Cliente"
        :description="client.name">
        <template #actions>
          <Link :href="`/clients/${client.id}/edit`" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
            </svg>
            Editar Cliente
          </Link>
          <Link href="/clients" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-4xl mx-auto space-y-6">
      <!-- Informações Principais -->
      <Card>
        <div class="p-6">
          <div class="flex items-center space-x-4">
            <div class="flex-shrink-0">
              <div class="h-16 w-16 rounded-full bg-blue-100 flex items-center justify-center">
                <svg class="h-8 w-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
              </div>
            </div>
            <div class="flex-1">
              <h1 class="text-2xl font-bold text-gray-900">{{ client.name }}</h1>
              <p class="text-sm text-gray-500">ID: {{ client.id }}</p>
              <p class="text-sm text-gray-500">Criado em: {{ formatDate(client.created_at) }}</p>
            </div>
          </div>
        </div>
      </Card>

      <!-- Informações de Contato -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Email -->
        <Card>
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-medium text-gray-900">Email</h3>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                Obrigatório
              </span>
            </div>
            <div class="space-y-2">
              <p class="text-sm text-gray-900 font-medium">{{ client.email }}</p>
              <p class="text-xs text-gray-500">Endereço de email principal</p>
            </div>
          </div>
        </Card>

        <!-- Telefone -->
        <Card>
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-medium text-gray-900">Telefone</h3>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                Obrigatório
              </span>
            </div>
            <div class="space-y-2">
              <p class="text-sm text-gray-900 font-medium">{{ client.phone }}</p>
              <p class="text-xs text-gray-500">Telefone para contato</p>
            </div>
          </div>
        </Card>

        <!-- CPF/CNPJ -->
        <Card>
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-medium text-gray-900">CPF/CNPJ</h3>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                Obrigatório
              </span>
            </div>
            <div class="space-y-2">
              <p class="text-sm text-gray-900 font-medium">{{ client.cnpj }}</p>
              <p class="text-xs text-gray-500">Documento de identificação</p>
            </div>
          </div>
        </Card>

      </div>

      <!-- Endereços do Cliente -->
      <Card>
        <div class="p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900">Endereços</h3>
            <button @click="showAddressModal = true" class="btn-primary">
              <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
              </svg>
              Novo Endereço
            </button>
          </div>

          <div v-if="client.addresses && client.addresses.length > 0" class="space-y-4">
            <div v-for="address in client.addresses" :key="address.id" class="border border-gray-200 rounded-lg p-4">
              <div class="flex items-start justify-between">
                <div class="flex-1">
                  <div class="flex items-center space-x-2 mb-2">
                    <h4 class="text-sm font-medium text-gray-900">{{ address.nickname }}</h4>
                    <span v-if="address.active" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-green-100 text-green-800">
                      Ativo
                    </span>
                    <span v-else class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                      Inativo
                    </span>
                  </div>
                  <p class="text-sm text-gray-600">
                    {{ address.street }}, {{ address.number }} - {{ address.district }}
                  </p>
                  <p class="text-sm text-gray-600">
                    {{ address.city }} - {{ address.state }} | CEP: {{ address.zip }}
                  </p>
                  <p v-if="address.reference" class="text-sm text-gray-500 mt-1">
                    Ref: {{ address.reference }}
                  </p>
                </div>
                <div class="flex space-x-2">
                  <Link :href="`/addresses/${address.id}`" class="btn-secondary btn-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                  </Link>
                  <Link :href="`/addresses/${address.id}/edit`" class="btn-primary btn-sm">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                    </svg>
                  </Link>
                </div>
              </div>
            </div>
          </div>

          <div v-else class="text-center py-8">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"></path>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum endereço cadastrado</h3>
            <p class="mt-1 text-sm text-gray-500">Comece adicionando o primeiro endereço para este cliente.</p>
            <div class="mt-6">
              <button @click="showAddressModal = true" class="btn-primary">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Adicionar Endereço
              </button>
            </div>
          </div>
        </div>
      </Card>

      <!-- Modal para criar Endereço -->
      <div v-if="showAddressModal" class="fixed inset-0 bg-black bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-xl shadow-xl max-w-4xl w-full mx-4 transform transition-all">
          <div class="p-6">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-xl font-semibold text-gray-900">Novo Endereço</h3>
              <button @click="showAddressModal = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
              </button>
            </div>

            <form @submit.prevent="submitAddress" class="space-y-4">
              <!-- Apelido do Endereço -->
              <div>
                <label for="nickname" class="block text-sm font-medium text-gray-700 mb-2">
                  Apelido <span class="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  id="nickname"
                  v-model="addressForm.nickname"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  placeholder="Ex: Matriz, Casa, Filial"
                  required
                />
              </div>

              <!-- CEP e Busca Automática -->
              <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div class="md:col-span-1">
                  <label for="zip" class="block text-sm font-medium text-gray-700 mb-2">
                    CEP <span class="text-red-500">*</span>
                  </label>
                  <input
                    type="text"
                    id="zip"
                    v-model="addressForm.zip"
                    @blur="searchCep"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                    placeholder="00000-000"
                    maxlength="9"
                    required
                  />
                </div>
                <div class="md:col-span-2 flex items-end">
                  <button
                    type="button"
                    @click="searchCep"
                    class="px-4 py-2 bg-blue-600 text-white rounded-lg hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors"
                  >
                    <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                    </svg>
                    Buscar CEP
                  </button>
                </div>
              </div>

              <!-- Endereço -->
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label for="street" class="block text-sm font-medium text-gray-700 mb-2">
                    Rua <span class="text-red-500">*</span>
                  </label>
                  <input
                    type="text"
                    id="street"
                    v-model="addressForm.street"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                    placeholder="Nome da rua"
                    required
                  />
                </div>
                <div>
                  <label for="number" class="block text-sm font-medium text-gray-700 mb-2">
                    Número <span class="text-red-500">*</span>
                  </label>
                  <input
                    type="text"
                    id="number"
                    v-model="addressForm.number"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                    placeholder="Número ou S/N"
                    required
                  />
                </div>
              </div>

              <!-- Bairro e Cidade -->
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label for="district" class="block text-sm font-medium text-gray-700 mb-2">
                    Bairro <span class="text-red-500">*</span>
                  </label>
                  <input
                    type="text"
                    id="district"
                    v-model="addressForm.district"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                    placeholder="Nome do bairro"
                    required
                  />
                </div>
                <div>
                  <label for="city" class="block text-sm font-medium text-gray-700 mb-2">
                    Cidade <span class="text-red-500">*</span>
                  </label>
                  <input
                    type="text"
                    id="city"
                    v-model="addressForm.city"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                    placeholder="Nome da cidade"
                    required
                  />
                </div>
              </div>

              <!-- Estado -->
              <div>
                <label for="state" class="block text-sm font-medium text-gray-700 mb-2">
                  Estado <span class="text-red-500">*</span>
                </label>
                <select
                  id="state"
                  v-model="addressForm.state"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  required
                >
                  <option value="">Selecione o estado</option>
                  <option value="AC">Acre</option>
                  <option value="AL">Alagoas</option>
                  <option value="AP">Amapá</option>
                  <option value="AM">Amazonas</option>
                  <option value="BA">Bahia</option>
                  <option value="CE">Ceará</option>
                  <option value="DF">Distrito Federal</option>
                  <option value="ES">Espírito Santo</option>
                  <option value="GO">Goiás</option>
                  <option value="MA">Maranhão</option>
                  <option value="MT">Mato Grosso</option>
                  <option value="MS">Mato Grosso do Sul</option>
                  <option value="MG">Minas Gerais</option>
                  <option value="PA">Pará</option>
                  <option value="PB">Paraíba</option>
                  <option value="PR">Paraná</option>
                  <option value="PE">Pernambuco</option>
                  <option value="PI">Piauí</option>
                  <option value="RJ">Rio de Janeiro</option>
                  <option value="RN">Rio Grande do Norte</option>
                  <option value="RS">Rio Grande do Sul</option>
                  <option value="RO">Rondônia</option>
                  <option value="RR">Roraima</option>
                  <option value="SC">Santa Catarina</option>
                  <option value="SP">São Paulo</option>
                  <option value="SE">Sergipe</option>
                  <option value="TO">Tocantins</option>
                </select>
              </div>

              <!-- Referência -->
              <div>
                <label for="reference" class="block text-sm font-medium text-gray-700 mb-2">
                  Referência
                </label>
                <textarea
                  id="reference"
                  v-model="addressForm.reference"
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  rows="2"
                  placeholder="Ponto de referência, próximo a..."
                ></textarea>
              </div>

              <!-- Status -->
              <div>
                <label class="flex items-center">
                  <input
                    type="checkbox"
                    v-model="addressForm.active"
                    class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded"
                  />
                  <span class="ml-2 text-sm text-gray-700">Endereço ativo</span>
                </label>
              </div>

              <!-- Botões de Ação -->
              <div class="flex justify-end space-x-4 pt-4">
                <button type="button" @click="showAddressModal = false" class="px-6 py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 font-medium transition-colors">
                  Cancelar
                </button>
                <button type="submit" :disabled="isSubmitting" class="px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed">
                  <span v-if="isSubmitting">Salvando...</span>
                  <span v-else>Salvar Endereço</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Observações -->
      <Card v-if="client.notes">
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Observações</h3>
          <div class="space-y-2">
            <p class="text-sm text-gray-900">{{ client.notes }}</p>
            <p class="text-xs text-gray-500">Informações adicionais</p>
          </div>
        </div>
      </Card>

      <!-- Informações do Sistema -->
      <Card>
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Informações do Sistema</h3>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
              <dt class="text-sm font-medium text-gray-500">Data de Criação</dt>
              <dd class="mt-1 text-sm text-gray-900">{{ formatDate(client.created_at) }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Última Atualização</dt>
              <dd class="mt-1 text-sm text-gray-900">{{ formatDate(client.updated_at) }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Status</dt>
              <dd class="mt-1">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                  Ativo
                </span>
              </dd>
            </div>
          </div>
        </div>
      </Card>

      <!-- Ações -->
      <div class="flex justify-center space-x-4">
        <Link :href="`/clients/${client.id}/edit`" class="btn-primary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
          </svg>
          Editar Cliente
        </Link>
        <Link href="/clients" class="btn-secondary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
          </svg>
          Voltar à Lista
        </Link>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { useForm, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  client: Object,
});

// Estado do modal
const showAddressModal = ref(false);
const isSubmitting = ref(false);

// Formulário de endereço
const addressForm = useForm({
  client_id: props.client.id, // Preenchido automaticamente
  nickname: '',
  street: '',
  number: '',
  district: '',
  city: '',
  state: '',
  zip: '',
  reference: '',
  active: true,
});

// Buscar CEP
const searchCep = async () => {
  if (!addressForm.zip || addressForm.zip.length < 8) return;

  try {
    const cleanCep = addressForm.zip.replace(/\D/g, '');
    const response = await fetch(`https://viacep.com.br/ws/${cleanCep}/json/`);
    const data = await response.json();

    if (!data.erro) {
      addressForm.street = data.logradouro || '';
      addressForm.district = data.bairro || '';
      addressForm.city = data.localidade || '';
      addressForm.state = data.uf || '';
    }
  } catch (error) {
    console.error('Erro ao buscar CEP:', error);
  }
};

// Submeter formulário
const submitAddress = async () => {
  isSubmitting.value = true;

  try {
    await addressForm.post('/addresses', {
      onSuccess: () => {
        showAddressModal.value = false;
        addressForm.reset();
        // Recarregar a página para mostrar o novo endereço usando Inertia
        router.reload({ only: ['client'] });
      },
      onError: () => {
        // Erro será tratado pelo sistema de alertas do layout
      }
    });
  } finally {
    isSubmitting.value = false;
  }
};

const formatDate = (dateString) => {
  if (!dateString) return '-';
  return new Date(dateString).toLocaleDateString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
};
</script>
