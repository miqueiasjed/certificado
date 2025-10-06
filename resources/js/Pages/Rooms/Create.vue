<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Novo Cômodo"
        description="Cadastre um novo cômodo no sistema">
        <template #actions>
          <button @click="goBack" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </button>
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
          <!-- Seleção de Endereço -->
          <div>
            <label for="address_id" class="block text-sm font-medium text-gray-700 mb-2">
              Endereço *
            </label>
            <select
              id="address_id"
              v-model="form.address_id"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.address_id }"
            >
              <option value="">Selecione um endereço</option>
              <option v-for="address in addresses" :key="address.id" :value="address.id">
                {{ address.nickname }} - {{ address.street }}, {{ address.number }} - {{ address.city }}/{{ address.state }}
              </option>
            </select>
            <p v-if="form.errors.address_id" class="mt-1 text-sm text-red-600">
              {{ form.errors.address_id }}
            </p>
          </div>

          <!-- Nome do Cômodo -->
          <div>
            <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
              Nome do Cômodo *
            </label>
            <input
              id="name"
              v-model="form.name"
              type="text"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.name }"
              placeholder="Ex: Sala, Quarto, Cozinha, Banheiro"
            />
            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
              {{ form.errors.name }}
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
              placeholder="Informações adicionais sobre o cômodo"
            ></textarea>
            <p v-if="form.errors.notes" class="mt-1 text-sm text-red-600">
              {{ form.errors.notes }}
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
              Cômodo ativo
            </label>
          </div>

          <!-- Botões -->
          <div class="flex justify-end space-x-3">
            <Link
              :href="selectedAddressId ? `/addresses/${selectedAddressId}` : '/addresses'"
              class="btn-secondary"
            >
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
              {{ form.processing ? 'Salvando...' : 'Salvar Cômodo' }}
            </button>
          </div>
        </form>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Alert from '@/Components/Alert.vue';

const props = defineProps({
  addresses: Array,
  selectedAddressId: String,
  errors: Object,
});

const form = useForm({
  address_id: props.selectedAddressId || '',
  name: '',
  notes: '',
  active: true,
});

const alert = ref({
  show: false,
  type: 'info',
  title: '',
  message: ''
});

// O endereço já é pré-selecionado via props.selectedAddressId

const submit = () => {
  form.post('/rooms', {
    onSuccess: () => {
      alert.value = {
        show: true,
        type: 'success',
        title: 'Cômodo criado!',
        message: 'Cômodo cadastrado com sucesso no sistema.'
      };
    },
    onError: (errors) => {
      alert.value = {
        show: true,
        type: 'error',
        title: 'Erro ao salvar',
        message: 'Verifique os campos destacados e tente novamente.'
      };
    },
  });
};

const goBack = () => {
  if (window.history.length > 1) {
    window.history.back();
  } else {
    router.visit('/rooms');
  }
};
</script>
