<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Editar Dispositivo"
        :description="`Editando: ${device.label}`">
        <template #actions>
          <Link :href="`/devices/${device.id}`" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
            </svg>
            Ver Dispositivo
          </Link>
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
          <!-- Cômodo -->
          <div>
            <label for="room_id" class="block text-sm font-medium text-gray-700 mb-2">
              Cômodo *
            </label>
            <select
              id="room_id"
              v-model="form.room_id"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.room_id }"
            >
              <option value="">Selecione um cômodo</option>
              <option v-for="room in rooms" :key="room.id" :value="room.id">
                {{ room.name }} - {{ room.address?.street }}, {{ room.address?.number }}
              </option>
            </select>
            <p v-if="form.errors.room_id" class="mt-1 text-sm text-red-600">
              {{ form.errors.room_id }}
            </p>
            <p class="mt-1 text-xs text-gray-500">
              Endereço: {{ device.room?.address?.street }}, {{ device.room?.address?.number }} - {{ device.room?.address?.city }}/{{ device.room?.address?.state }}
            </p>
          </div>

          <!-- Nome do Dispositivo -->
          <div>
            <label for="label" class="block text-sm font-medium text-gray-700 mb-2">
              Nome do Dispositivo *
            </label>
            <input
              id="label"
              v-model="form.label"
              type="text"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.label }"
              placeholder="Ex: Ratoeira, Armadilha, Sensor"
            />
            <p v-if="form.errors.label" class="mt-1 text-sm text-red-600">
              {{ form.errors.label }}
            </p>
          </div>

          <!-- Número/Código do Dispositivo -->
          <div>
            <label for="number" class="block text-sm font-medium text-gray-700 mb-2">
              Número/Código *
            </label>
            <input
              id="number"
              v-model="form.number"
              type="text"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.number }"
              placeholder="Ex: DISP001, ARM001"
            />
            <p v-if="form.errors.number" class="mt-1 text-sm text-red-600">
              {{ form.errors.number }}
            </p>
          </div>

          <!-- Tipo de Isca -->
          <div>
            <div class="flex items-center justify-between mb-2">
              <label for="bait_type_id" class="block text-sm font-medium text-gray-700">
                Tipo de Isca
              </label>
              <button
                type="button"
                @click="showBaitTypeModal = true"
                class="text-sm text-green-600 hover:text-green-700 font-medium"
              >
                + Novo Tipo
              </button>
            </div>
            <select
              id="bait_type_id"
              v-model="form.bait_type_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.bait_type_id }"
            >
              <option value="">Selecione um tipo de isca (opcional)</option>
              <option v-for="baitType in baitTypes" :key="baitType.id" :value="baitType.id">
                {{ baitType.name }} {{ baitType.brand ? `- ${baitType.brand}` : '' }}
              </option>
            </select>
            <p v-if="form.errors.bait_type_id" class="mt-1 text-sm text-red-600">
              {{ form.errors.bait_type_id }}
            </p>
          </div>

          <!-- Observação de Localização -->
          <div>
            <label for="default_location_note" class="block text-sm font-medium text-gray-700 mb-2">
              Observação de Localização
            </label>
            <textarea
              id="default_location_note"
              v-model="form.default_location_note"
              rows="3"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.default_location_note }"
              placeholder="Ex: atrás da geladeira, embaixo da pia"
            ></textarea>
            <p v-if="form.errors.default_location_note" class="mt-1 text-sm text-red-600">
              {{ form.errors.default_location_note }}
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
            <Link :href="`/devices/${device.id}`" class="btn-secondary">
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
              {{ form.processing ? 'Salvando...' : 'Atualizar Dispositivo' }}
            </button>
          </div>
        </form>
      </Card>
    </div>

    <!-- Modal para Criar Tipo de Isca -->
    <BaitTypeModal
      :show="showBaitTypeModal"
      :errors="errors"
      @close="showBaitTypeModal = false"
      @bait-type-created="refreshBaitTypes"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Alert from '@/Components/Alert.vue';
import BaitTypeModal from '@/Components/BaitTypeModal.vue';

const props = defineProps({
  device: Object,
  errors: Object,
  rooms: {
    type: Array,
    default: () => []
  },
  baitTypes: {
    type: Array,
    default: () => []
  }
});

const form = useForm({
  room_id: props.device.room_id || '',
  label: props.device.label || '',
  number: props.device.number || '',
  bait_type_id: props.device.bait_type_id || null,
  default_location_note: props.device.default_location_note || '',
  active: props.device.active !== undefined ? props.device.active : true,
});

const alert = ref({
  show: false,
  type: 'info',
  title: '',
  message: ''
});

const showBaitTypeModal = ref(false);

const refreshBaitTypes = () => {
  // Recarregar a página para atualizar a lista de tipos de isca usando Inertia
  router.reload({ only: ['baitTypes'] });
};

const submit = () => {
  form.put(`/devices/${props.device.id}`, {
    onSuccess: () => {
      alert.value = {
        show: true,
        type: 'success',
        title: 'Dispositivo atualizado!',
        message: 'Dispositivo atualizado com sucesso no sistema.'
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
    router.visit('/devices');
  }
};
</script>
