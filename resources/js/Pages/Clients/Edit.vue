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

          <!-- Nota: Endereços agora são cadastrados separadamente no módulo de Endereços -->
          <div class="bg-blue-50 border border-blue-200 rounded-md p-4">
            <div class="flex">
              <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
              </div>
              <div class="ml-3">
                <h3 class="text-sm font-medium text-blue-800">
                  Endereços
                </h3>
                <div class="mt-2 text-sm text-blue-700">
                  <p>Os endereços deste cliente podem ser gerenciados no módulo de Endereços.</p>
                </div>
              </div>
            </div>
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
  notes: props.client.notes || '',
});

const alert = ref({
  show: false,
  type: 'info',
  title: '',
  message: ''
});

// Usar o composable de máscaras
const { phoneMask, cpfCnpjMask } = useMasks();

// Funções de formatação
const formatPhone = (event) => {
  form.phone = phoneMask(event.target.value);
};

const formatCnpj = (event) => {
  form.cnpj = cpfCnpjMask(event.target.value);
};

// Nota: Endereços agora são cadastrados separadamente no módulo de Endereços

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
