<template>
  <div class="flex h-full flex-col">
    <!-- Logo -->
    <div class="flex h-16 shrink-0 items-center border-b border-green-700 px-4">
      <Link href="/" class="flex items-center">
        <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-green-500">
          <svg class="h-5 w-5 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
        </div>
        <span v-if="!collapsed" class="ml-3 text-lg font-semibold text-white">
          Sistema
        </span>
      </Link>
    </div>

    <!-- Navigation -->
    <nav ref="navegacao" class="flex flex-1 flex-col overflow-y-auto min-h-0 px-3 py-4">
      <ul role="list" class="flex flex-col gap-y-1">
        <li>
          <Link
            href="/dashboard"
            :title="collapsed ? 'Dashboard' : null"
            :class="[
              isCurrentRoute('/dashboard')
                ? 'bg-green-700 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              collapsed ? 'justify-center' : '',
              'group flex items-center gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 transition-colors'
            ]">
            <svg :class="[
              isCurrentRoute('/dashboard') ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path v-for="d in icones.dashboard" :key="d" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="d"></path>
            </svg>
            <span v-if="!collapsed">Dashboard</span>
          </Link>
        </li>
      </ul>

      <!-- Barra recolhida: só os ícones, com um divisor entre os módulos -->
      <ul v-if="collapsed" role="list" class="flex flex-col gap-y-1">
        <template v-for="grupo in grupos" :key="grupo.chave">
          <li class="my-2 border-t border-green-500/60" aria-hidden="true"></li>
          <li v-for="item in grupo.itens" :key="item.href">
            <Link
              :href="item.href"
              :title="item.rotulo"
              :class="[
                itemAtivo(item)
                  ? 'bg-green-700 text-white'
                  : 'text-green-300 hover:bg-green-700 hover:text-white',
                'relative flex items-center justify-center rounded-md p-2 transition-colors'
              ]">
              <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path v-for="d in item.icone" :key="d" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="d"></path>
              </svg>
              <span
                v-if="item.contador > 0"
                class="absolute right-1 top-1 h-2 w-2 rounded-full bg-red-500 ring-2 ring-green-600"></span>
              <span class="sr-only">{{ item.rotulo }}</span>
            </Link>
          </li>
        </template>
      </ul>

      <!-- Barra aberta: módulos que abrem e fecham -->
      <ul v-else role="list" class="mt-3 flex flex-col gap-y-1">
        <li v-for="grupo in grupos" :key="grupo.chave">
          <button
            type="button"
            :aria-expanded="estaAberto(grupo)"
            :aria-controls="`grupo-${grupo.chave}`"
            :class="[
              grupoAtivo(grupo) && !estaAberto(grupo)
                ? 'bg-green-700/60 text-white'
                : 'text-green-100 hover:bg-green-700 hover:text-white',
              'group flex w-full items-center gap-x-3 rounded-md p-2 text-left text-sm font-semibold leading-6 transition-colors'
            ]"
            @click="alternarGrupo(grupo)">
            <svg :class="[
              grupoAtivo(grupo) ? 'text-white' : 'text-green-300 group-hover:text-white',
              'h-5 w-5 shrink-0 transition-colors'
            ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path v-for="d in grupo.icone" :key="d" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="d"></path>
            </svg>
            <span class="flex-1 truncate">{{ grupo.titulo }}</span>
            <span
              v-if="!estaAberto(grupo) && grupo.contador > 0"
              class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1 text-xs font-semibold text-white">
              {{ grupo.contador }}
            </span>
            <svg
              :class="[estaAberto(grupo) ? 'rotate-90' : '', 'h-4 w-4 shrink-0 text-green-300 transition-transform duration-200 group-hover:text-white']"
              fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
            </svg>
          </button>

          <div
            :id="`grupo-${grupo.chave}`"
            :class="[
              estaAberto(grupo) ? 'grid-rows-[1fr] opacity-100' : 'grid-rows-[0fr] opacity-0',
              'grid transition-[grid-template-rows,opacity] duration-200 ease-out'
            ]">
            <div class="overflow-hidden">
              <ul role="list" class="ml-4 mt-1 flex flex-col gap-y-0.5 border-l border-green-500/60 pl-2">
                <li v-for="item in grupo.itens" :key="item.href">
                  <Link
                    :href="item.href"
                    :tabindex="estaAberto(grupo) ? null : -1"
                    :class="[
                      itemAtivo(item)
                        ? 'bg-green-700 text-white'
                        : 'text-green-100 hover:bg-green-700 hover:text-white',
                      'group flex items-center gap-x-2.5 rounded-md px-2 py-1.5 text-sm font-medium leading-6 transition-colors'
                    ]">
                    <svg :class="[
                      itemAtivo(item) ? 'text-white' : 'text-green-300 group-hover:text-white',
                      'h-4 w-4 shrink-0 transition-colors'
                    ]" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path v-for="d in item.icone" :key="d" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="d"></path>
                    </svg>
                    <span class="flex-1 truncate">{{ item.rotulo }}</span>
                    <span
                      v-if="item.contador > 0"
                      class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-red-500 px-1 text-xs font-semibold text-white">
                      {{ item.contador }}
                    </span>
                  </Link>
                </li>
              </ul>
            </div>
          </div>
        </li>
      </ul>
    </nav>

    <!-- User Menu (fixo, fora da área rolável do menu) -->
    <div class="shrink-0 border-t border-green-700 p-4">
      <div class="relative">
        <button
          @click="toggleUserMenu"
          :class="[
            'group flex w-full items-center gap-x-3 rounded-md p-2 text-sm font-semibold leading-6 text-green-100 hover:bg-green-700 hover:text-white transition-colors'
          ]">
          <div class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-500">
            <svg class="h-4 w-4 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
            </svg>
          </div>
          <span v-if="!collapsed" class="truncate">{{ userName }}</span>
          <svg v-if="!collapsed" class="ml-auto h-4 w-4 shrink-0" :class="{ 'rotate-180': userMenuOpen }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
          </svg>
        </button>

        <!-- User Dropdown -->
        <transition
          enter-active-class="transition ease-out duration-100"
          enter-from-class="transform opacity-0 scale-95"
          enter-to-class="transform opacity-100 scale-100"
          leave-active-class="transition ease-in duration-75"
          leave-from-class="transform opacity-100 scale-100"
          leave-to-class="transform opacity-0 scale-95">
          <div v-if="userMenuOpen && !collapsed"
               class="absolute bottom-full left-0 right-0 mb-2 rounded-md bg-white shadow-lg ring-1 ring-black ring-opacity-5">
            <div class="px-4 py-3">
              <p class="text-sm font-medium text-gray-900 truncate">{{ userName }}</p>
              <p class="text-sm text-gray-500 truncate">{{ userEmail }}</p>
            </div>
            <div class="py-1">
              <Link
                v-if="podeConfigurarEmpresa"
                :href="route('settings.company.edit')"
                class="flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="mr-3 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"></path>
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                </svg>
                Configurações
              </Link>
              <Link
                v-if="podeConfigurarDisponibilidade"
                :href="route('settings.disponibilidade.edit')"
                class="flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="mr-3 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                </svg>
                Disponibilidade
              </Link>
              <Link
                v-if="podeVerUsuarios"
                :href="route('settings.users.index')"
                class="flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="mr-3 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"></path>
                </svg>
                Usuários
              </Link>
              <Link
                v-if="podeConvidarUsuarios"
                :href="route('settings.convites.index')"
                class="flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                <svg class="mr-3 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                   <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 8v6m3-3h-6"></path>
                </svg>
                Convites
              </Link>
              <button
                type="button"
                class="flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors"
                @click="abrirRelatoDeProblema">
                <svg class="mr-3 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                </svg>
                Relatar problema
              </button>
              <form @submit.prevent="logout">
                <button
                  type="submit"
                  class="flex w-full items-center px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 transition-colors">
                  <svg class="mr-3 h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 16l4-4m0 0l-4-4m4 4H7m6 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h4a3 3 0 013 3v1"></path>
                  </svg>
                  Sair
                </button>
              </form>
            </div>
          </div>
        </transition>
      </div>
    </div>

    <RelatarProblemaModal
      :show="relatoDeProblemaAberto"
      :rota="route('relatos.store')"
      @close="relatoDeProblemaAberto = false" />

    <!-- Collapse Toggle -->
    <div class="hidden border-t border-green-700 p-4 lg:block">
      <button
        @click="toggleCollapse"
        :title="collapsed ? 'Expandir menu' : 'Recolher menu'"
        class="flex w-full items-center justify-center rounded-md p-2 text-green-100 hover:bg-green-700 hover:text-white transition-colors">
        <svg class="h-4 w-4 transition-transform" :class="{ 'rotate-180': collapsed }" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 19l-7-7 7-7m8 14l-7-7 7-7"></path>
        </svg>
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted, onBeforeUnmount, nextTick } from 'vue';
import { Link, usePage, router } from '@inertiajs/vue3';
import { usePermissoes } from '@/Composables/usePermissoes';
import { useModulos } from '@/Composables/useModulos';
import RelatarProblemaModal from '@/Components/RelatarProblemaModal.vue';

const emit = defineEmits(['toggle-collapse']);

const props = defineProps({
  collapsed: {
    type: Boolean,
    default: false
  }
});

const $page = usePage();
const userMenuOpen = ref(false);

const { pode } = usePermissoes();
const { temModulo } = useModulos();

// Gestão de Clientes
const podeVerClientes = computed(() => pode('cliente-ver'));
const podeVerEnderecos = computed(() => pode('endereco-ver'));
const podeVerOrcamentos = computed(() => pode('orcamento-ver'));

// Atendimento e Agendamento Online
const podeVerSolicitacoes = computed(() => pode('solicitacao-ver'));
const podeVerAgendamentos = computed(() => pode('agendamento-ver'));
const solicitacoesAbertas = computed(() => $page.props.solicitacoesAbertas || 0);
const pedidosDeHorarioPendentes = computed(() => $page.props.pedidosDeHorarioPendentes || 0);

// Gestão Operacional
const podeVerCadastros = computed(() => pode('cadastro-ver'));
const podeVerOrdensServico = computed(() => pode('ordem-servico-ver'));
const podeVerCertificados = computed(() => pode('certificado-ver'));
const podeVerContratos = computed(() => pode('contrato-ver') && temModulo('contratos'));
const podeVerRoteiro = computed(() => pode('roteiro-ver') && temModulo('roteirizacao'));
// Frota (Plano 27): o item só aparece com a permissão E com o módulo ligado.
// O módulo nasce desligado para todos os tenants, então a barra lateral
// continua exatamente como está hoje até alguém pedir a frota.
const podeVerFrota = computed(() => pode('frota-ver') && temModulo('frota'));
// Controle de EPI (Plano 28): mesma regra da frota, permissão E módulo ligado.
const podeVerEpi = computed(() => pode('epi-ver') && temModulo('epi'));

// Gestão Financeira
const podeVerFinanceiro = computed(() => pode('financeiro-ver') && temModulo('financeiro'));
const podeVerFiscal = computed(() => pode('fiscal-ver') && temModulo('nfse'));
const podeConfigurarFiscal = computed(() => pode('fiscal-configurar') && temModulo('nfse'));

// Configurações da empresa
const podeConfigurarEmpresa = computed(() => pode('empresa-configurar'));
const podeConfigurarDisponibilidade = computed(() => pode('empresa-configurar'));
const podeVerUsuarios = computed(() => pode('usuario-ver'));
const podeConvidarUsuarios = computed(() => pode('usuario-criar'));

// Traços dos ícones (heroicons outline), um array por ícone porque alguns têm mais de um path.
const icones = {
  dashboard: [
    'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2H5a2 2 0 00-2-2z',
    'M8 5a2 2 0 012-2h4a2 2 0 012 2v2H8V5z',
  ],
  clientes: ['M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0zM7 10a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z'],
  enderecos: ['M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6'],
  orcamentos: ['M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z'],
  atendimento: ['M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z'],
  calendario: ['M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z'],
  satisfacao: ['M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3zM7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3'],
  operacional: ['M21 13.255A23.931 23.931 0 0112 15c-3.183 0-6.22-.62-9-1.745M16 6V4a2 2 0 00-2-2h-4a2 2 0 00-2 2v2m4 6h.01M5 20h14a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z'],
  cadastros: ['M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10'],
  documento: ['M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z'],
  roteiro: ['M9 20l-5.447-2.724A1 1 0 013 16.382V5.618a1 1 0 011.447-.894L9 7m0 13l6-3m-6 3V7m6 10l4.553 2.276A1 1 0 0021 18.382V7.618a1 1 0 00-.553-.894L15 4m0 13V4m0 0L9 7'],
  frota: ['M8 17a2 2 0 100-4 2 2 0 000 4zm8 0a2 2 0 100-4 2 2 0 000 4zM3 13h18l-1.5-5H4.5L3 13z'],
  epi: ['M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z'],
  certificados: ['M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z'],
  grafico: ['M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z'],
  financeiro: ['M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z'],
  entradas: ['M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1'],
  saidas: ['M20 12H4m16 0l-4-4m4 4l-4 4M4 12l4-4m-4 4l4 4'],
  notas: ['M9 12h6m-6 4h6M9 8h6m2 13H7a2 2 0 01-2-2V5a2 2 0 012-2h7l5 5v11a2 2 0 01-2 2z'],
  ajustes: ['M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4v2m0-6V4m12 14a2 2 0 100-4m0 4v2m0-6V4'],
};

// Cada módulo da barra. `ativoEm` é o prefixo de URL que acende o item quando
// difere do href (rotas nomeadas cujo caminho não começa igual).
const grupos = computed(() => [
  {
    chave: 'clientes',
    titulo: 'Gestão de Clientes',
    icone: icones.clientes,
    itens: [
      { rotulo: 'Clientes', href: '/clients', icone: icones.clientes, visivel: podeVerClientes.value },
      { rotulo: 'Endereços', href: '/addresses', icone: icones.enderecos, visivel: podeVerEnderecos.value },
      { rotulo: 'Orçamentos', href: '/budgets', icone: icones.orcamentos, visivel: podeVerOrcamentos.value },
    ],
  },
  {
    chave: 'atendimento',
    titulo: 'Atendimento',
    icone: icones.atendimento,
    itens: [
      { rotulo: 'Solicitações', href: '/solicitacoes', icone: icones.atendimento, visivel: podeVerSolicitacoes.value, contador: solicitacoesAbertas.value },
      { rotulo: 'Agendamentos', href: '/solicitacoes-de-horario', icone: icones.calendario, visivel: podeVerAgendamentos.value, contador: pedidosDeHorarioPendentes.value },
      { rotulo: 'Satisfação', href: '/satisfacao', icone: icones.satisfacao, visivel: podeVerAgendamentos.value },
    ],
  },
  {
    chave: 'operacional',
    titulo: 'Gestão Operacional',
    icone: icones.operacional,
    itens: [
      { rotulo: 'Cadastros', href: '/cadastros', icone: icones.cadastros, visivel: podeVerCadastros.value },
      { rotulo: 'Ordens de Serviço', href: '/work-orders', icone: icones.documento, visivel: podeVerOrdensServico.value },
      { rotulo: 'Agenda', href: '/agenda', icone: icones.calendario, visivel: podeVerOrdensServico.value },
      { rotulo: 'Roteiro do dia', href: route('roteiros.painel'), ativoEm: '/roteiros', icone: icones.roteiro, visivel: podeVerRoteiro.value },
      { rotulo: 'Frota', href: route('frota.index'), ativoEm: '/veiculos', icone: icones.frota, visivel: podeVerFrota.value },
      { rotulo: 'Controle de EPI', href: route('epi.index'), ativoEm: '/epis', icone: icones.epi, visivel: podeVerEpi.value },
      { rotulo: 'Certificados', href: '/certificates', icone: icones.certificados, visivel: podeVerCertificados.value },
      { rotulo: 'Contratos', href: '/contracts', icone: icones.documento, visivel: podeVerContratos.value },
    ],
  },
  {
    chave: 'financeiro',
    titulo: 'Financeiro e Fiscal',
    icone: icones.financeiro,
    itens: [
      { rotulo: 'Dashboard Financeiro', href: '/financial-dashboard', icone: icones.grafico, visivel: podeVerFinanceiro.value },
      { rotulo: 'Entradas Financeiras', href: '/financial-entries', icone: icones.entradas, visivel: podeVerFinanceiro.value },
      { rotulo: 'Saídas Financeiras', href: '/financial-withdrawals', icone: icones.saidas, visivel: podeVerFinanceiro.value },
      { rotulo: 'Fluxo de Caixa', href: '/cash-flow', icone: icones.grafico, visivel: podeVerFinanceiro.value },
      { rotulo: 'Notas fiscais', href: '/notas', icone: icones.notas, visivel: podeVerFiscal.value },
      { rotulo: 'Configuração fiscal', href: '/fiscal/configuracao', icone: icones.ajustes, visivel: podeConfigurarFiscal.value },
    ],
  },
]
  .map((grupo) => {
    const itens = grupo.itens.filter((item) => item.visivel);
    const contador = itens.reduce((total, item) => total + (item.contador || 0), 0);
    return { ...grupo, itens, contador };
  })
  .filter((grupo) => grupo.itens.length > 0));

// Função para verificar se a rota atual corresponde ao link
const isCurrentRoute = (href) => {
  return $page.url === href || $page.url.startsWith(href + '/') || $page.url.startsWith(href + '?');
};

const itemAtivo = (item) => isCurrentRoute(item.ativoEm || new URL(item.href, window.location.origin).pathname);
const grupoAtivo = (grupo) => grupo.itens.some(itemAtivo);

// Módulos abertos ficam no localStorage para sobreviver à troca de página (o
// layout é remontado a cada visita do Inertia). Sem preferência salva, só o
// módulo da página atual começa aberto.
const CHAVE_GRUPOS = 'sidebar:grupos-abertos';
const CHAVE_ROLAGEM = 'sidebar:rolagem';

const lerGruposAbertos = () => {
  try {
    return JSON.parse(localStorage.getItem(CHAVE_GRUPOS)) || {};
  } catch {
    return {};
  }
};

const gruposAbertos = ref(lerGruposAbertos());

const salvarGruposAbertos = () => {
  try {
    localStorage.setItem(CHAVE_GRUPOS, JSON.stringify(gruposAbertos.value));
  } catch {
    // localStorage indisponível (modo privado): a barra só não lembra o estado.
  }
};

const estaAberto = (grupo) => gruposAbertos.value[grupo.chave] ?? grupoAtivo(grupo);

const alternarGrupo = (grupo) => {
  gruposAbertos.value = { ...gruposAbertos.value, [grupo.chave]: !estaAberto(grupo) };
  salvarGruposAbertos();
};

// Ao entrar numa página, o módulo dela abre mesmo que tenha sido fechado antes.
const abrirGrupoDaPaginaAtual = () => {
  const ativo = grupos.value.find(grupoAtivo);
  if (ativo && gruposAbertos.value[ativo.chave] === false) {
    gruposAbertos.value = { ...gruposAbertos.value, [ativo.chave]: true };
    salvarGruposAbertos();
  }
};

// Mantém a rolagem da barra entre páginas, para a lista não voltar ao topo a cada clique.
const navegacao = ref(null);

const restaurarRolagem = async () => {
  await nextTick();
  if (!navegacao.value) return;
  try {
    navegacao.value.scrollTop = Number(sessionStorage.getItem(CHAVE_ROLAGEM)) || 0;
  } catch {
    // sessionStorage indisponível: a barra abre no topo.
  }
  navegacao.value.querySelector('a.bg-green-700')?.scrollIntoView({ block: 'nearest' });
};

const salvarRolagem = () => {
  if (!navegacao.value) return;
  try {
    sessionStorage.setItem(CHAVE_ROLAGEM, String(navegacao.value.scrollTop));
  } catch {
    // sessionStorage indisponível: nada a guardar.
  }
};

const userName = computed(() => $page.props.auth?.user?.name || 'Usuário');
const userEmail = computed(() => $page.props.auth?.user?.email || 'usuario@exemplo.com');

const toggleCollapse = () => {
  emit('toggle-collapse');
};

const toggleUserMenu = () => {
  if (!props.collapsed) {
    userMenuOpen.value = !userMenuOpen.value;
  }
};

// Relatar problema: disponível para todo usuário logado, sem permissão
// própria, igual a sair do sistema.
const relatoDeProblemaAberto = ref(false);

const abrirRelatoDeProblema = () => {
  userMenuOpen.value = false;
  relatoDeProblemaAberto.value = true;
};

const logout = () => {
  router.post('/logout');
};

// Close user menu when clicking outside
const handleClickOutside = (event) => {
  const sidebar = event.target.closest('.sidebar');
  if (!sidebar) {
    userMenuOpen.value = false;
  }
};

onMounted(() => {
  document.addEventListener('click', handleClickOutside);
  abrirGrupoDaPaginaAtual();
  restaurarRolagem();
});

onBeforeUnmount(salvarRolagem);

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
});
</script>
