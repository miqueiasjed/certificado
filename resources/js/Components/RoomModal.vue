<template>
  <Modal :show="show" @close="$emit('close')">
    <template #icon>
      <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
      </svg>
    </template>

    <template #title>
      Novo Cômodo
    </template>

    <template #content>
      <div class="space-y-6">
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
        {{ form.processing ? 'Salvando...' : 'Salvar Cômodo' }}
      </button>
    </template>
  </Modal>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
  show: Boolean,
  address: Object,
  errors: Object,
});

const emit = defineEmits(['close', 'room-created']);

const form = useForm({
  address_id: props.address?.id || '',
  name: '',
  notes: '',
  active: true,
});

const submit = () => {
  form.post('/rooms', {
    onSuccess: () => {
      // Emitir evento de sucesso para atualizar a lista de cômodos
      emit('room-created');
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

// Resetar formulário quando modal abrir
watch(() => props.show, (newValue) => {
  if (newValue) {
    form.reset();
    form.address_id = props.address?.id || '';
  }
});
</script>
