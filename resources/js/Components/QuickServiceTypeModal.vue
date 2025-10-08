<template>
  <div v-if="show" class="fixed inset-0 z-50 overflow-y-auto">
    <div class="flex min-h-screen items-center justify-center p-4">
      <!-- Backdrop -->
      <div class="fixed inset-0 bg-gray-500 bg-opacity-75 transition-opacity" @click="close"></div>

      <!-- Modal -->
      <div class="relative bg-white rounded-lg shadow-xl max-w-md w-full">
        <!-- Header -->
        <div class="flex items-center justify-between p-6 border-b border-gray-200">
          <h3 class="text-lg font-semibold text-gray-900">
            Novo Tipo de Serviço
          </h3>
          <button
            @click="close"
            class="text-gray-400 hover:text-gray-600 transition-colors"
          >
            <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
            </svg>
          </button>
        </div>

        <!-- Form -->
        <form @submit.prevent="submit" class="p-6 space-y-4">
          <!-- Nome -->
          <div>
            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">
              Nome *
            </label>
            <input
              id="name"
              v-model="form.name"
              type="text"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.name }"
              placeholder="Ex: Dedetização, Desinsetização..."
            />
            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
              {{ form.errors.name }}
            </p>
          </div>

          <!-- Descrição -->
          <div>
            <label for="description" class="block text-sm font-medium text-gray-700 mb-1">
              Descrição
            </label>
            <textarea
              id="description"
              v-model="form.description"
              rows="3"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.description }"
              placeholder="Descrição detalhada do tipo de serviço..."
            ></textarea>
            <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">
              {{ form.errors.description }}
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
              Tipo de serviço ativo
            </label>
          </div>

          <!-- Botões -->
          <div class="flex justify-end space-x-3 pt-4">
            <button
              type="button"
              @click="close"
              class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 border border-gray-300 rounded-md hover:bg-gray-200 focus:outline-none focus:ring-2 focus:ring-gray-500 transition-colors"
            >
              Cancelar
            </button>
            <button
              type="submit"
              :disabled="form.processing"
              class="px-4 py-2 text-sm font-medium text-white bg-green-600 border border-transparent rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 disabled:opacity-50 disabled:cursor-not-allowed transition-colors"
            >
              <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ form.processing ? 'Salvando...' : 'Salvar' }}
            </button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';

const props = defineProps({
  show: {
    type: Boolean,
    default: false
  }
});

const emit = defineEmits(['close', 'service-type-created']);

const form = useForm({
  name: '',
  description: '',
  active: true,
});

const close = () => {
  emit('close');
};

const submit = async () => {
  try {
    const response = await fetch('/service-types', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        'Accept': 'application/json',
      },
      body: JSON.stringify(form.data())
    });

    if (response.ok) {
      const result = await response.json();

      // Emitir evento com o tipo de serviço criado
      emit('service-type-created', result.serviceType);

      // Limpar formulário
      form.reset();

      // Fechar modal
      close();
    } else {
      const errors = await response.json();
      // Tratar erros de validação
      Object.keys(errors.errors || {}).forEach(key => {
        form.setError(key, errors.errors[key][0]);
      });
    }
  } catch (error) {
    console.error('Erro ao criar tipo de serviço:', error);
  }
};

// Limpar formulário quando modal fecha
watch(() => props.show, (newValue) => {
  if (!newValue) {
    form.reset();
    form.clearErrors();
  }
});
</script>
