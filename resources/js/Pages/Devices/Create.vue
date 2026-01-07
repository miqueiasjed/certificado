<template>
  <AuthenticatedLayout>
    <PageHeader
      title="Novo Dispositivo"
      subtitle="Crie um novo dispositivo no sistema"
    />

    <div class="max-w-4xl mx-auto">
      <Card>
        <form @submit.prevent="submit" class="p-6 space-y-6">
          <!-- Endereço -->
          <div>
            <label for="address_id" class="block text-sm font-medium text-gray-700 mb-2">
              Endereço *
            </label>
            <select
              id="address_id"
              v-model="form.address_id"
              required
              @change="onAddressChange"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.address_id }"
            >
              <option value="">Selecione um endereço</option>
              <option v-for="address in addresses" :key="address.id" :value="address.id">
                {{ address.nickname }} - {{ address.client?.name }} - {{ address.street }}, {{ address.number }}
              </option>
            </select>
            <p v-if="form.errors.address_id" class="mt-1 text-sm text-red-600">
              {{ form.errors.address_id }}
            </p>
          </div>

          <!-- Rótulo -->
          <div>
            <label for="label" class="block text-sm font-medium text-gray-700 mb-2">
              Rótulo *
            </label>
            <input
              id="label"
              v-model="form.label"
              type="text"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.label }"
              placeholder="Ex: Armadilha para Ratos, Isca para Baratas"
            />
            <p v-if="form.errors.label" class="mt-1 text-sm text-red-600">
              {{ form.errors.label }}
            </p>
          </div>

          <!-- Número -->
          <div>
            <label for="number" class="block text-sm font-medium text-gray-700 mb-2">
              Número *
            </label>
            <input
              id="number"
              v-model="form.number"
              type="text"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.number }"
              placeholder="Ex: 001, A1, B2"
            />
            <p v-if="form.errors.number" class="mt-1 text-sm text-red-600">
              {{ form.errors.number }}
            </p>
          </div>

          <!-- Tipo de Isca -->
          <div>
            <label for="bait_type_id" class="block text-sm font-medium text-gray-700 mb-2">
              Tipo de Isca
            </label>

            <!-- Debug temporário -->
            <div class="mb-2 p-2 bg-gray-100 rounded text-xs">
              Debug: baitTypes recebidos: {{ baitTypes ? baitTypes.length : 'undefined' }}
              <div v-if="baitTypes">
                <div v-for="bt in baitTypes" :key="bt.id" class="text-xs">
                  ID: {{ bt.id }} | Name: {{ bt.name }} | Brand: {{ bt.brand }}
                </div>
              </div>
            </div>

            <div class="flex space-x-2">
              <select
                id="bait_type_id"
                v-model="form.bait_type_id"
                class="flex-1 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.bait_type_id }"
              >
                <option value="">Selecione um tipo de isca</option>
                <option v-for="baitType in baitTypes" :key="baitType.id" :value="baitType.id">
                  {{ baitType.name }} - {{ baitType.brand }}
                </option>
              </select>
              <button
                type="button"
                @click="showBaitTypeModal = true"
                class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2"
              >
                + Novo Tipo
              </button>
            </div>
            <p v-if="form.errors.bait_type_id" class="mt-1 text-sm text-red-600">
              {{ form.errors.bait_type_id }}
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
              placeholder="Descreva detalhes sobre o dispositivo..."
            ></textarea>
            <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">
              {{ form.errors.description }}
            </p>
          </div>

          <!-- Localização -->
          <div>
            <label for="location" class="block text-sm font-medium text-gray-700 mb-2">
              Localização no Endereço
            </label>
            <input
              id="location"
              v-model="form.location"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.location }"
              placeholder="Ex: Perto da porta, Embaixo da pia, Canto da sala"
            />
            <p v-if="form.errors.location" class="mt-1 text-sm text-red-600">
              {{ form.errors.location }}
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
              Dispositivo ativo
            </label>
          </div>

          <!-- Botões -->
          <div class="flex justify-end space-x-3">
            <Link :href="route('devices.index')" class="btn-secondary">
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
              {{ form.processing ? 'Salvando...' : 'Salvar Dispositivo' }}
            </button>
          </div>
        </form>
      </Card>
    </div>

    <!-- Modal para criar novo tipo de isca -->
    <BaitTypeModal
      v-if="showBaitTypeModal"
      @close="showBaitTypeModal = false"
      @created="onBaitTypeCreated"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import BaitTypeModal from '@/Components/BaitTypeModal.vue';

const props = defineProps({
  addresses: Array,
  baitTypes: Array,
  errors: Object,
});

const showBaitTypeModal = ref(false);

const form = useForm({
  address_id: '',
  label: '',
  number: '',
  bait_type_id: '',
  description: '',
  location: '',
  active: true,
});

const onAddressChange = () => {
  // Lógica adicional se necessário quando o endereço muda
};

const onBaitTypeCreated = (newBaitType) => {
  // Atualizar a lista de tipos de isca e selecionar o novo
  form.bait_type_id = newBaitType.id;
  showBaitTypeModal.value = false;

  // Recarregar a página para atualizar a lista usando Inertia
  router.reload({ only: ['baitTypes'] });
};

const submit = () => {
  form.post(route('devices.store'));
};
</script>
