<template>
  <div
    v-if="suporteAtivo"
    class="fixed inset-x-0 top-0 z-[100] flex h-11 items-center justify-between gap-3 bg-amber-500 px-3 text-gray-900 shadow-md sm:h-12 sm:px-6">
    <div class="flex min-w-0 items-center gap-2">
      <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
      </svg>
      <p class="truncate text-xs font-medium sm:text-sm">
        Você está operando como <strong class="font-bold">{{ empresa }}</strong>
      </p>
    </div>

    <button
      type="button"
      :disabled="saindo"
      class="inline-flex shrink-0 items-center gap-2 rounded-md bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white transition-colors hover:bg-gray-800 disabled:cursor-not-allowed disabled:opacity-60 sm:text-sm"
      @click="sairDoModoSuporte">
      <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
      </svg>
      <span class="hidden sm:inline">{{ saindo ? 'Saindo...' : 'Sair do modo suporte' }}</span>
      <span class="sm:hidden">Sair</span>
    </button>
  </div>
</template>

<script setup>
import { computed, ref } from 'vue';
import { router, usePage } from '@inertiajs/vue3';

const $page = usePage();
const saindo = ref(false);

const suporteAtivo = computed(() => $page.props.suporte?.ativo === true);
const empresa = computed(() => $page.props.suporte?.empresa || '');

const sairDoModoSuporte = () => {
  if (saindo.value) {
    return;
  }

  saindo.value = true;

  router.post(
    '/plataforma/liberar',
    {},
    {
      onFinish: () => {
        saindo.value = false;
      },
    }
  );
};
</script>
