<template>
  <Head title="Portal indisponível" />

  <div class="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-4 py-12" :style="variaveisDeCor">
    <div class="w-full max-w-md text-center">
      <div class="flex flex-col items-center">
        <img
          v-if="empresaAtual?.logo_url"
          :src="empresaAtual.logo_url"
          :alt="empresaAtual.nome"
          class="h-16 w-16 rounded-md bg-white object-contain p-1 shadow-sm">
        <div v-else class="flex h-16 w-16 items-center justify-center rounded-full" style="background-color: var(--portal-cor-primaria)">
          <svg class="h-8 w-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>

        <h1 class="mt-4 text-xl font-semibold text-gray-900">{{ nome }}</h1>
        <p class="mt-2 text-sm text-gray-600">{{ mensagem }}</p>
      </div>

      <button
        type="button"
        @click="sair"
        class="mt-6 text-sm font-medium"
        style="color: var(--portal-cor-primaria)">
        Sair
      </button>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { resolverCoresDoPortal } from '@/utils/corDoPortal';

// Destino de `EnsurePortalModuleIsActive` quando a empresa não tem o módulo
// `portal_cliente` ativo (`PortalController::moduloIndisponivel()`, Task 15.4). Sem
// `PortalLayout` de propósito (Task 15.8): o layout inteiro monta a navegação para
// visitas, documentos, pendências etc., e mostrar esse menu funcional numa tela que
// existe justamente porque nada disso está liberado passaria a impressão errada. A
// tela fica isolada, no mesmo espírito visual de `Portal/Auth/Login.vue`, sem nenhum
// tom de erro - o texto de `mensagem` já vem pronto do backend, calmo.
defineProps({
  nome: {
    type: String,
    required: true,
  },
  mensagem: {
    type: String,
    required: true,
  },
});

const $page = usePage();
const empresaAtual = computed(() => $page.props.empresa);

const variaveisDeCor = computed(() => {
  const { corPrimaria, corDestaque } = resolverCoresDoPortal(empresaAtual.value);

  return {
    '--portal-cor-primaria': corPrimaria,
    '--portal-cor-destaque': corDestaque,
  };
});

const sair = () => {
  router.post(route('portal.logout'));
};
</script>
