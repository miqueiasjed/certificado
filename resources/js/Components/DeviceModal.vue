<template>
  <Modal :show="show" @close="$emit('close')">
    <template #icon>
      <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
      </svg>
    </template>

    <template #title>
      Novo Dispositivo
    </template>

    <template #content>
      <div class="space-y-6">
        <!-- Cômodo (Readonly) -->
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            Cômodo
          </label>
          <div class="px-3 py-2 bg-gray-50 border border-gray-300 rounded-md text-sm text-gray-900">
            {{ room.name }}
          </div>
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
      </div>
    </template>

    <template #actions>
      <button
        type="button"
        @click="$emit('close')"
        class="btn-secondary"
      >
        Cancelar
      </button>
      <button
        type="submit"
        @click="submit"
        :disabled="form.processing"
        class="btn-primary"
      >
        <svg v-if="form.processing" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
          <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
          <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
        </svg>
        {{ form.processing ? 'Salvando...' : 'Salvar Dispositivo' }}
      </button>
    </template>
  </Modal>

  <!-- Modal para Criar Tipo de Isca -->
  <BaitTypeModal
    :show="showBaitTypeModal"
    :errors="errors"
    @close="showBaitTypeModal = false"
    @bait-type-created="refreshBaitTypes"
  />
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import { watch, ref } from 'vue';
import Modal from '@/Components/Modal.vue';
import BaitTypeModal from '@/Components/BaitTypeModal.vue';

const props = defineProps({
  show: Boolean,
  room: Object,
  errors: Object,
  baitTypes: {
    type: Array,
    default: () => []
  }
});

const emit = defineEmits(['close', 'device-created', 'bait-type-created']);

const showBaitTypeModal = ref(false);

const form = useForm({
  room_id: props.room?.id || '',
  label: '',
  number: '',
  bait_type_id: '',
  default_location_note: '',
  active: true,
});

const submit = () => {
  form.post('/devices', {
    onSuccess: () => {
      // Emitir evento de sucesso para atualizar a lista de dispositivos
      emit('device-created');
      emit('close');

      // Resetar formulário
      form.reset();

      // Mostrar mensagem de sucesso
      // O Inertia automaticamente exibe mensagens flash
    },
    onError: (errors) => {
      // Erros são tratados automaticamente pelo Inertia
      // O modal permanece aberto para correção
      console.log('Erros de validação:', errors);
    },
    // Evitar redirecionamento - permanecer na mesma página
    preserveScroll: true,
    preserveState: true,
  });
};

const refreshBaitTypes = () => {
  // Emitir evento para atualizar tipos de isca
  emit('bait-type-created');
  showBaitTypeModal.value = false;
};

// Resetar formulário quando modal abrir
watch(() => props.show, (newValue) => {
  if (newValue) {
    form.reset();
    form.room_id = props.room?.id || '';
  }
});
</script>
