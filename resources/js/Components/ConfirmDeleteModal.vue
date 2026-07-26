<template>
  <div
    v-if="show"
    class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4"
    @click.self="handleCancel"
  >
    <div
      class="relative bg-white rounded-xl shadow-xl max-w-md w-full mx-4 transform transition-all"
      role="dialog"
      aria-modal="true"
      :aria-label="title"
    >
      <div class="p-6">
        <div class="flex items-center gap-4 mb-4">
          <div class="flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center" :class="tone.circulo">
            <svg class="w-6 h-6" :class="tone.icone" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="tone.caminho"></path>
            </svg>
          </div>
          <div>
            <h3 class="text-lg font-semibold text-gray-900">{{ title }}</h3>
            <p v-if="subtitle" class="text-sm text-gray-500">{{ subtitle }}</p>
          </div>
        </div>

        <p class="text-sm text-gray-700 mb-6 whitespace-pre-line">
          {{ message }}<template v-if="itemName"> <strong class="text-gray-900">"{{ itemName }}"</strong>?</template>
        </p>

        <div class="flex justify-end gap-3">
          <button
            type="button"
            @click="handleCancel"
            :disabled="processing"
            class="btn-secondary disabled:opacity-50 disabled:cursor-not-allowed"
          >
            {{ cancelText }}
          </button>
          <button
            type="button"
            @click="handleConfirm"
            :disabled="processing"
            class="disabled:opacity-50 disabled:cursor-not-allowed"
            :class="tone.botao"
          >
            <svg v-if="processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ processing ? processingText : confirmText }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted } from 'vue';

// Aparência por tipo de ação. "danger" é o padrão de exclusão; "warning" serve
// para ação que pede confirmação sem destruir nada, como converter um orçamento
// em ordem de serviço, onde o alerta vermelho passa a impressão errada.
const TONS = {
  danger: {
    circulo: 'bg-red-100',
    icone: 'text-red-600',
    botao: 'btn-danger',
    caminho: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z',
  },
  warning: {
    circulo: 'bg-amber-100',
    icone: 'text-amber-600',
    botao: 'btn-primary',
    caminho: 'M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
  },
};

const props = defineProps({
  show: {
    type: Boolean,
    required: true,
  },
  title: {
    type: String,
    default: 'Confirmar exclusão',
  },
  message: {
    type: String,
    required: true,
  },
  itemName: {
    type: String,
    default: '',
  },
  confirmText: {
    type: String,
    default: 'Excluir',
  },
  cancelText: {
    type: String,
    default: 'Cancelar',
  },
  processing: {
    type: Boolean,
    default: false,
  },
  // Texto de apoio abaixo do título. String vazia esconde a linha, para ação
  // que não é irreversível.
  subtitle: {
    type: String,
    default: 'Esta ação não pode ser desfeita.',
  },
  // Rótulo do botão durante a requisição. Permite manter o verbo da ação
  // ("Excluindo...", "Convertendo...") em vez de um genérico.
  processingText: {
    type: String,
    default: 'Processando...',
  },
  variant: {
    type: String,
    default: 'danger',
    // Literal em vez de Object.keys(TONS): defineProps é avaliado em tempo de
    // compilação e não enxerga variáveis do escopo do setup.
    validator: (valor) => ['danger', 'warning'].includes(valor),
  },
});

const tone = computed(() => TONS[props.variant] ?? TONS.danger);

const emit = defineEmits(['confirm', 'cancel']);

const handleCancel = () => {
  if (props.processing) return;
  emit('cancel');
};

const handleConfirm = () => {
  if (props.processing) return;
  emit('confirm');
};

const handleKeydown = (event) => {
  if (event.key === 'Escape' && props.show) {
    handleCancel();
  }
};

onMounted(() => {
  document.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
  document.removeEventListener('keydown', handleKeydown);
});
</script>
