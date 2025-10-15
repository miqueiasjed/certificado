<template>
  <Modal :show="show" @close="$emit('close')">
    <template #icon>
      <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
      </svg>
    </template>

    <template #title>
      Novo Tipo de Isca
    </template>

    <template #content>
      <div class="space-y-6">
        <!-- Nome do Tipo de Isca -->
        <div>
          <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
            Nome do Tipo de Isca *
          </label>
          <input
            id="name"
            v-model="form.name"
            type="text"
            required
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            :class="{ 'border-red-500': form.errors.name }"
            placeholder="Ex: Isca para Ratos, Isca para Baratas"
          />
          <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
            {{ form.errors.name }}
          </p>
        </div>

        <!-- Marca -->
        <div>
          <label for="brand" class="block text-sm font-medium text-gray-700 mb-2">
            Marca
          </label>
          <input
            id="brand"
            v-model="form.brand"
            type="text"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            :class="{ 'border-red-500': form.errors.brand }"
            placeholder="Ex: Raticida, Baraticida"
          />
          <p v-if="form.errors.brand" class="mt-1 text-sm text-red-600">
            {{ form.errors.brand }}
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
            placeholder="Ex: Isca específica para controle de roedores"
          ></textarea>
          <p v-if="form.errors.notes" class="mt-1 text-sm text-red-600">
            {{ form.errors.notes }}
          </p>
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
        {{ form.processing ? 'Salvando...' : 'Salvar Tipo de Isca' }}
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
  errors: Object,
});

const emit = defineEmits(['close', 'bait-type-created']);

const form = useForm({
  name: '',
  brand: '',
  notes: '',
});

const submit = () => {
  form.post('/bait-types', {
    onSuccess: () => {
      // Emitir evento de sucesso para atualizar a lista de tipos de isca
      emit('bait-type-created');
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
  }
});
</script>

















