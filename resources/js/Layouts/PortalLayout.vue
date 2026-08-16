<template>
  <div class="flex min-h-screen flex-col bg-gray-50 lg:flex-row" :style="variaveisDeCor">
    <!-- Navegação lateral (computador) -->
    <aside
      class="hidden shrink-0 flex-col text-white lg:flex lg:w-64"
      style="background-color: var(--portal-cor-primaria)">
      <div class="flex h-16 items-center gap-3 border-b border-white/20 px-4">
        <img
          v-if="empresaAtual.logo_url"
          :src="empresaAtual.logo_url"
          :alt="nomeDaEmpresa"
          class="h-10 w-10 shrink-0 rounded-md bg-white object-contain p-1">
        <div v-else class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-white/15">
          <svg class="h-6 w-6 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"></path>
          </svg>
        </div>
        <div class="min-w-0">
          <p class="truncate text-sm font-semibold leading-tight">{{ nomeDaEmpresa }}</p>
          <p v-if="clienteAtual" class="truncate text-xs leading-tight text-white/80">{{ clienteAtual.nome }}</p>
        </div>
      </div>

      <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">
        <Link
          v-for="item in itensDeNavegacao"
          :key="item.rota"
          :href="caminhoDoItem(item)"
          :class="[
            'flex items-center gap-3 rounded-md px-3 py-2 text-sm font-medium transition-colors',
            estaAtivo(item) ? 'bg-white/15 text-white' : 'text-white/80 hover:bg-white/10 hover:text-white'
          ]">
          <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="item.icone"></path>
          </svg>
          <span>{{ item.rotulo }}</span>
        </Link>
      </nav>

      <div class="border-t border-white/20 p-3">
        <button
          type="button"
          @click="sair"
          class="flex w-full items-center gap-3 rounded-md px-3 py-2 text-sm font-medium text-white/80 transition-colors hover:bg-white/10 hover:text-white">
          <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
          </svg>
          <span>Sair</span>
        </button>
      </div>
    </aside>

    <!-- Barra superior (celular) -->
    <header
      class="sticky top-0 z-30 flex h-14 shrink-0 items-center gap-3 px-4 text-white lg:hidden"
      style="background-color: var(--portal-cor-primaria)">
      <img
        v-if="empresaAtual.logo_url"
        :src="empresaAtual.logo_url"
        :alt="nomeDaEmpresa"
        class="h-9 w-9 shrink-0 rounded-md bg-white object-contain p-1">
      <div v-else class="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-white/15">
        <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"></path>
        </svg>
      </div>
      <p class="min-w-0 flex-1 truncate text-sm font-semibold">{{ nomeDaEmpresa }}</p>
      <button type="button" @click="sair" class="shrink-0 text-xs font-medium text-white/80 hover:text-white">
        Sair
      </button>
    </header>

    <!-- Conteúdo -->
    <div class="flex min-w-0 flex-1 flex-col">
      <div v-if="$slots.header" class="border-b border-gray-200 bg-white">
        <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6">
          <slot name="header" />
        </div>
      </div>

      <main class="flex-1">
        <div class="mx-auto max-w-5xl px-4 py-6 sm:px-6">
          <div v-if="$page.props.flash?.success" class="mb-6 rounded-md border border-green-200 bg-green-50 p-4">
            <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
          </div>
          <div v-if="$page.props.flash?.error" class="mb-6 rounded-md border border-red-200 bg-red-50 p-4">
            <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
          </div>

          <slot />
        </div>
      </main>

      <footer class="border-t border-gray-200 bg-white pb-24 lg:pb-0">
        <div class="mx-auto max-w-5xl px-4 py-4 sm:px-6">
          <p class="text-sm font-medium text-gray-700">{{ nomeDaEmpresa }}</p>
          <p v-if="empresaAtual.telefone || empresaAtual.email" class="mt-1 text-sm text-gray-500">
            <span v-if="empresaAtual.telefone">{{ empresaAtual.telefone }}</span>
            <span v-if="empresaAtual.telefone && empresaAtual.email"> &middot; </span>
            <span v-if="empresaAtual.email">{{ empresaAtual.email }}</span>
          </p>
        </div>
      </footer>
    </div>

    <!-- Navegação inferior (celular) -->
    <nav class="fixed inset-x-0 bottom-0 z-30 flex overflow-x-auto border-t border-gray-200 bg-white lg:hidden">
      <Link
        v-for="item in itensDeNavegacao"
        :key="item.rota"
        :href="caminhoDoItem(item)"
        class="flex flex-1 flex-col items-center gap-0.5 px-2 py-2 text-gray-500"
        :style="estaAtivo(item) ? { color: 'var(--portal-cor-primaria)' } : undefined">
        <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="item.icone"></path>
        </svg>
        <span class="whitespace-nowrap text-[10px] font-medium leading-tight">{{ item.rotulo }}</span>
      </Link>
    </nav>
  </div>

  <BotaoDeAjuda escopo="portal" />
</template>

<script setup>
import { computed } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { resolverCoresDoPortal } from '@/utils/corDoPortal';
import BotaoDeAjuda from '@/Components/BotaoDeAjuda.vue';

const $page = usePage();

/**
 * Identidade e contato do tenant (`Company::brandingDoPortal()`, compartilhado
 * por `HandleInertiaRequests`). `null` no caso raro de o tenant não resolver
 * (ver o docblock de `brandingDoPortalCliente()` no backend): a interface cai
 * no objeto vazio, sem nome de empresa e sem logo, mas nunca quebra a página.
 */
const empresaAtual = computed(() => $page.props.empresa ?? {});
const clienteAtual = computed(() => $page.props.clienteLogado ?? null);
const nomeDaEmpresa = computed(() => empresaAtual.value.nome || 'Portal do Cliente');

/**
 * Cor sempre validada como hexadecimal antes de virar variável CSS (Task
 * 15.6): `resolverCoresDoPortal()` cai no verde padrão do sistema para
 * qualquer valor que não seja exatamente `#RRGGBB`, incluindo ausente. As
 * variáveis só existem aqui, na raiz do layout, e o resto do template só as
 * referencia por `var(--portal-cor-primaria)`/`var(--portal-cor-destaque)` -
 * nunca há interpolação direta do valor do banco em texto de CSS.
 */
const variaveisDeCor = computed(() => {
  const { corPrimaria, corDestaque } = resolverCoresDoPortal(empresaAtual.value);

  return {
    '--portal-cor-primaria': corPrimaria,
    '--portal-cor-destaque': corDestaque,
  };
});

/**
 * Sem link nenhum para o sistema interno, de propósito (Task 15.6): todo
 * `rota` aqui é do arquivo `routes/portal.php`, prefixo `portal.`.
 */
const itensDeNavegacao = [
  {
    rotulo: 'Início',
    rota: 'portal.painel',
    icone: 'M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6',
  },
  {
    rotulo: 'Visitas',
    rota: 'portal.visitas',
    icone: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
  },
  {
    rotulo: 'Relatórios',
    rota: 'portal.relatorios.index',
    icone: 'M13 7h8m0 0v8m0-8l-8 8-4-4-6 6',
  },
  {
    rotulo: 'Documentos',
    rota: 'portal.certificados',
    icone: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
  },
  {
    rotulo: 'Contratos',
    rota: 'portal.contratos',
    icone: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
  },
  {
    rotulo: 'Pendências',
    rota: 'portal.adequacoes',
    icone: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z',
  },
  {
    rotulo: 'Faturas',
    rota: 'portal.faturas',
    icone: 'M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z',
  },
  {
    rotulo: 'Solicitações',
    rota: 'portal.solicitacoes.index',
    icone: 'M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z',
  },
];

/**
 * Caminho relativo da rota nomeada (`false` no terceiro argumento do Ziggy),
 * para comparar direto com `$page.url` - a mesma forma relativa que o
 * Inertia usa nessa prop. Mesmo critério de `isCurrentRoute()` em
 * `Components/Sidebar.vue`.
 */
const caminhoDoItem = (item) => route(item.rota, undefined, false);

const estaAtivo = (item) => {
  const caminho = caminhoDoItem(item);

  return $page.url === caminho || $page.url.startsWith(caminho + '/');
};

const sair = () => {
  router.post(route('portal.logout'));
};
</script>
