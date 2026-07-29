<template>
  <div class="flex min-h-screen flex-col bg-gray-50" :style="variaveisDeCor">
    <header class="border-b border-gray-200 bg-white">
      <div class="mx-auto flex max-w-xl items-center gap-3 px-4 py-4 sm:px-6">
        <img
          v-if="empresaAtual.logo_url"
          :src="empresaAtual.logo_url"
          :alt="nomeDaEmpresa"
          class="h-12 w-12 shrink-0 rounded-md border border-gray-200 bg-white object-contain p-1">
        <div
          v-else
          class="flex h-12 w-12 shrink-0 items-center justify-center rounded-md"
          style="background-color: var(--publico-cor-primaria)">
          <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"></path>
          </svg>
        </div>
        <p class="min-w-0 truncate text-lg font-semibold text-gray-900">{{ nomeDaEmpresa }}</p>
      </div>
    </header>

    <main class="flex flex-1 flex-col">
      <div class="mx-auto w-full max-w-xl flex-1 px-4 py-6 sm:px-6">
        <slot />
      </div>
    </main>

    <footer class="border-t border-gray-200 bg-white">
      <div class="mx-auto max-w-xl px-4 py-4 text-center sm:px-6">
        <p v-if="empresaAtual.telefone || empresaAtual.email" class="text-xs text-gray-500">
          <span v-if="empresaAtual.telefone">{{ empresaAtual.telefone }}</span>
          <span v-if="empresaAtual.telefone && empresaAtual.email"> &middot; </span>
          <span v-if="empresaAtual.email">{{ empresaAtual.email }}</span>
        </p>
      </div>
    </footer>
  </div>
</template>

<script setup>
// Layout das páginas públicas sem autenticação nenhuma (Plano 16, Task 16.6):
// pedido de horário e resposta de pesquisa de satisfação. Mesma abordagem de
// variáveis CSS de cor validadas do Plano 15 (`PortalLayout.vue`), mas sem
// menu, sem navegação e sem qualquer link para a área autenticada - quem abre
// estas páginas não tem conta no sistema, e às vezes nem sabe que uma existe.
//
// `empresa` chega como prop da própria página Inertia (`brandingDoPortal()`
// no backend), nunca de `$page.props`: as rotas públicas usam
// `HandleInertiaPublicoRequests`, que não compartilha dado de tenant nenhum
// global. Pode ser `null` (estado "invalida" da pesquisa, token que não
// resolveu empresa nenhuma), e a interface cai no nome genérico sem quebrar.
import { computed } from 'vue';
import { resolverCoresDoPortal } from '@/utils/corDoPortal';

const props = defineProps({
  empresa: {
    type: Object,
    default: null,
  },
});

const empresaAtual = computed(() => props.empresa ?? {});
const nomeDaEmpresa = computed(() => empresaAtual.value.nome || 'Controle de pragas');

const variaveisDeCor = computed(() => {
  const { corPrimaria, corDestaque } = resolverCoresDoPortal(empresaAtual.value);

  return {
    '--publico-cor-primaria': corPrimaria,
    '--publico-cor-destaque': corDestaque,
  };
});
</script>
