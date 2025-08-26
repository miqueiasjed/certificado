<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Editar Cliente"
        :description="`Editando: ${client.name}`">
        <template #actions>
          <Link :href="`/clients/${client.id}`" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
            </svg>
            Ver Cliente
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

    <!-- Alertas -->
    <Alert
      v-if="alert.show"
      :type="alert.type"
      :title="alert.title"
      :message="alert.message"
      @close="alert.show = false"
    />

    <div class="max-w-4xl mx-auto">
      <Card>
        <form @submit.prevent="submit" class="space-y-6">
          <!-- Informações Básicas -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                Nome / Razão Social *
              </label>
              <input
                id="name"
                v-model="form.name"
                type="text"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.name }"
                placeholder="Nome completo ou razão social"
              />
              <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                {{ form.errors.name }}
              </p>
            </div>

            <div>
              <label for="email" class="block text-sm font-medium text-gray-700 mb-2">
                Email *
              </label>
              <input
                id="email"
                v-model="form.email"
                type="email"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.email }"
                placeholder="email@exemplo.com"
              />
              <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">
                {{ form.errors.email }}
              </p>
            </div>
          </div>

          <!-- Contato -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label for="phone" class="block text-sm font-medium text-gray-700 mb-2">
                Telefone *
              </label>
              <input
                id="phone"
                v-model="form.phone"
                type="tel"
                required
                @input="formatPhone"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.phone }"
                placeholder="(11) 99999-9999"
              />
              <p v-if="form.errors.phone" class="mt-1 text-sm text-red-600">
                {{ form.errors.phone }}
              </p>
            </div>

            <div>
              <label for="cnpj" class="block text-sm font-medium text-gray-700 mb-2">
                CPF/CNPJ *
              </label>
              <input
                id="cnpj"
                v-model="form.cnpj"
                type="text"
                required
                @input="formatCnpj"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.cnpj }"
                placeholder="000.000.000-00 ou 00.000.000/0000-00"
              />
              <p v-if="form.errors.cnpj" class="mt-1 text-sm text-red-600">
                {{ form.errors.cnpj }}
              </p>
            </div>
          </div>

          <!-- Endereço -->
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
              <label for="zip_code" class="block text-sm font-medium text-gray-700 mb-2">
                CEP
              </label>
              <div class="flex gap-2">
                <input
                  id="zip_code"
                  v-model="form.zip_code"
                  type="text"
                  @input="formatCep"
                  @blur="searchCep"
                  class="flex-1 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.zip_code }"
                  placeholder="00000-000"
                />
                <button
                  type="button"
                  @click="searchCep"
                  :disabled="searchingCep"
                  class="px-3 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 disabled:opacity-50 transition-colors">
                  <svg v-if="searchingCep" class="animate-spin h-5 w-5" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                  </svg>
                  <svg v-else class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                  </svg>
                </button>
              </div>
              <p v-if="form.errors.zip_code" class="mt-1 text-sm text-red-600">
                {{ form.errors.zip_code }}
              </p>
            </div>

            <div>
              <label for="city" class="block text-sm font-medium text-gray-700 mb-2">
                Cidade *
              </label>
              <input
                id="city"
                v-model="form.city"
                type="text"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.city }"
                placeholder="Nome da cidade"
              />
              <p v-if="form.errors.city" class="mt-1 text-sm text-red-600">
                {{ form.errors.city }}
              </p>
            </div>

            <div>
              <label for="state" class="block text-sm font-medium text-gray-700 mb-2">
                Estado
              </label>
              <input
                id="state"
                v-model="form.state"
                type="text"
                maxlength="2"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.state }"
                placeholder="UF"
              />
              <p v-if="form.errors.state" class="mt-1 text-sm text-red-600">
                {{ form.errors.state }}
              </p>
            </div>
          </div>

          <!-- Endereço completo -->
          <div>
            <label for="address" class="block text-sm font-medium text-gray-700 mb-2">
              Endereço Completo
            </label>
            <input
              id="address"
              v-model="form.address"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.address }"
              placeholder="Rua, número, bairro, complemento"
            />
            <p v-if="form.errors.address" class="mt-1 text-sm text-red-600">
              {{ form.errors.address }}
            </p>
          </div>

          <!-- Observações -->
          <div>
            <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">
              Observações
            </label>
            <textarea
              id="notes"
              v-model="form.notes"
              rows="3"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.notes }"
              placeholder="Observações adicionais sobre o cliente..."></textarea>
            <p v-if="form.errors.notes" class="mt-1 text-sm text-red-600">
              {{ form.errors.notes }}
            </p>
          </div>

          <!-- Botões de ação -->
          <div class="flex justify-end gap-4 pt-4">
            <Link :href="`/clients/${client.id}`" class="btn-secondary">
              Cancelar
            </Link>
            <button type="submit" class="btn-primary" :disabled="form.processing">
              <svg v-if="form.processing" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ form.processing ? 'Salvando...' : 'Atualizar Cliente' }}
            </button>
          </div>
        </form>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Alert from '@/Components/Alert.vue';
import { useMasks } from '@/Composables/useMasks';

const props = defineProps({
  client: Object,
  errors: Object,
});

const form = useForm({
  name: props.client.name,
  email: props.client.email,
  phone: props.client.phone,
  cnpj: props.client.cnpj,
  city: props.client.city,
  state: props.client.state || '',
  zip_code: props.client.zip_code || '',
  address: props.client.address || '',
  notes: props.client.notes || '',
});

const searchingCep = ref(false);
const alert = ref({
  show: false,
  type: 'info',
  title: '',
  message: ''
});

// Usar o composable de máscaras
const { phoneMask, cpfCnpjMask, cepMask } = useMasks();

// Funções de formatação
const formatPhone = (event) => {
  form.phone = phoneMask(event.target.value);
};

const formatCnpj = (event) => {
  form.cnpj = cpfCnpjMask(event.target.value);
};

const formatCep = (event) => {
  form.zip_code = cepMask(event.target.value);
};

// Buscar endereço pelo CEP
const searchCep = async () => {
  const cleanCep = form.zip_code.replace(/\D/g, '');

  if (cleanCep.length !== 8) {
    showAlert('warning', 'CEP Inválido', 'Digite um CEP com 8 dígitos');
    return;
  }

  searchingCep.value = true;

  try {
    const response = await fetch(`https://viacep.com.br/ws/${cleanCep}/json/`);
    const data = await response.json();

    if (data.erro) {
      showAlert('error', 'CEP não encontrado', 'Verifique o CEP informado');
      return;
    }

    if (data.cep) {
      form.city = data.localidade;
      form.state = data.uf;
      form.address = `${data.logradouro}, ${data.bairro}`;
      showAlert('success', 'Endereço encontrado', 'Endereço preenchido automaticamente');
    }
  } catch (error) {
    showAlert('error', 'Erro na busca', 'Não foi possível buscar o endereço. Tente novamente.');
  } finally {
    searchingCep.value = false;
  }
};

// Mostrar alerta
const showAlert = (type, title, message) => {
  alert.value = {
    show: true,
    type,
    title,
    message
  };
};

const submit = () => {
  form.put(`/clients/${props.client.id}`, {
    onSuccess: () => {
      showAlert('success', 'Cliente atualizado!', 'Cliente atualizado com sucesso no sistema.');
    },
    onError: (errors) => {
      showAlert('error', 'Erro ao salvar', 'Verifique os campos destacados e tente novamente.');
    },
  });
};
</script>
