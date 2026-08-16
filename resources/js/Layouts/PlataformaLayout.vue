<template>
  <FaixaDeSuporte />

  <div
    class="flex h-screen bg-gray-100"
    :class="{ 'pt-11 sm:pt-12': suporteAtivo }">
    <!-- Navegação da plataforma -->
    <div class="plataforma-nav relative flex">
      <!-- Overlay para mobile -->
      <div
        v-if="navAberta"
        class="fixed inset-0 z-40 bg-slate-900/75 lg:hidden"
        @click="fecharNav">
      </div>

      <!-- Painel de navegação -->
      <div
        :class="[
          'fixed inset-y-0 left-0 z-50 flex w-64 transform flex-col bg-slate-900 transition-transform duration-300 ease-in-out lg:static lg:translate-x-0',
          navAberta ? 'translate-x-0' : '-translate-x-full',
        ]">
        <!-- Marca: identidade visual distinta da área das empresas -->
        <div class="flex h-16 shrink-0 items-center gap-3 border-b border-slate-700 px-4">
          <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-slate-700">
            <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path
                stroke-linecap="round"
                stroke-linejoin="round"
                stroke-width="2"
                d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
          </div>
          <div class="min-w-0">
            <span class="block truncate text-sm font-semibold text-white">Plataforma</span>
            <span class="block truncate text-xs text-slate-400">Administração</span>
          </div>
        </div>

        <!-- Navegação -->
        <nav class="flex flex-1 flex-col px-4 py-4">
          <ul role="list" class="flex flex-1 flex-col gap-y-1">
            <li>
              <Link :href="'/plataforma'" :class="linkClasses('/plataforma', true)">
                <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z" />
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z" />
                </svg>
                Visão geral
              </Link>
            </li>
            <li>
              <Link :href="'/plataforma/tenants'" :class="linkClasses('/plataforma/tenants')">
                <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2M19 21H5m0 0H3m8-16h.01M11 11h.01M11 15h.01M15 7h.01M15 11h.01M15 15h.01" />
                </svg>
                Tenants
              </Link>
            </li>
            <li>
              <Link :href="'/plataforma/planos'" :class="linkClasses('/plataforma/planos')">
                <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                </svg>
                Planos
              </Link>
            </li>

            <!-- Usuário e sair -->
            <li class="mt-auto border-t border-slate-700 pt-4">
              <div class="mb-2 truncate px-2 text-xs text-slate-400">{{ userName }}</div>
              <form @submit.prevent="sair">
                <button
                  type="submit"
                  class="group flex w-full items-center gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 text-slate-300 transition-colors hover:bg-slate-800 hover:text-white">
                  <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path
                      stroke-linecap="round"
                      stroke-linejoin="round"
                      stroke-width="2"
                      d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1" />
                  </svg>
                  Sair
                </button>
              </form>
            </li>
          </ul>
        </nav>
      </div>
    </div>

    <!-- Conteúdo -->
    <div class="flex flex-1 flex-col overflow-hidden">
      <!-- Topo mobile -->
      <div class="sticky top-0 z-10 flex h-16 shrink-0 items-center gap-x-4 border-b border-gray-200 bg-white px-4 shadow-sm sm:gap-x-6 sm:px-6 lg:hidden">
        <button type="button" class="-m-2.5 p-2.5 text-gray-700" @click="abrirNav">
          <span class="sr-only">Abrir navegação</span>
          <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" />
          </svg>
        </button>

        <div class="flex flex-1 items-center gap-x-4 self-stretch sm:gap-x-6">
          <h1 class="text-lg font-semibold text-gray-900">Plataforma</h1>
        </div>
      </div>

      <!-- Cabeçalho da página -->
      <div v-if="$slots.header" class="bg-white shadow-sm border-b border-gray-200">
        <div class="mx-auto max-w-7xl px-4 py-6 sm:px-6 lg:px-8">
          <slot name="header" />
        </div>
      </div>

      <!-- Área principal -->
      <main class="flex-1 overflow-y-auto">
        <div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
          <slot />
        </div>
      </main>
    </div>
  </div>

  <BotaoDeAjuda escopo="plataforma" />
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import FaixaDeSuporte from '@/Components/FaixaDeSuporte.vue';
import BotaoDeAjuda from '@/Components/BotaoDeAjuda.vue';

const $page = usePage();
const navAberta = ref(false);

const suporteAtivo = computed(() => $page.props.suporte?.ativo === true);
const userName = computed(() => $page.props.auth?.user?.name || 'Administrador');

const abrirNav = () => {
  navAberta.value = true;
};

const fecharNav = () => {
  navAberta.value = false;
};

// `exato` evita que "Visão geral" (/plataforma) fique marcada como ativa
// quando a rota atual é, na verdade, uma rota aninhada como /plataforma/tenants.
const linkAtivo = (href, exato = false) => {
  if (exato) {
    return $page.url === href;
  }

  return $page.url === href || $page.url.startsWith(href + '/');
};

const linkClasses = (href, exato = false) => [
  linkAtivo(href, exato) ? 'bg-slate-800 text-white' : 'text-slate-300 hover:bg-slate-800 hover:text-white',
  'group flex items-center gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors',
];

const sair = () => {
  router.post('/logout');
};

const handleResize = () => {
  if (window.innerWidth >= 1024) {
    navAberta.value = false;
  }
};

const handleKeydown = (event) => {
  if (event.key === 'Escape' && navAberta.value) {
    fecharNav();
  }
};

onMounted(() => {
  window.addEventListener('resize', handleResize);
  document.addEventListener('keydown', handleKeydown);
});

onUnmounted(() => {
  window.removeEventListener('resize', handleResize);
  document.removeEventListener('keydown', handleKeydown);
});
</script>
