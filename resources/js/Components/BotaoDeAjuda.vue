<template>
  <!-- Botão flutuante -->
  <button
    type="button"
    class="fixed bottom-5 right-5 z-40 flex h-12 w-12 items-center justify-center rounded-full bg-green-600 text-white shadow-lg transition-colors hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 print:hidden"
    :title="`Ajuda desta tela (${TECLA_DE_ATALHO})`"
    aria-label="Abrir ajuda desta tela"
    @click="abrir">
    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
    </svg>
  </button>

  <!-- Painel lateral -->
  <div v-if="aberto" class="fixed inset-0 z-50 print:hidden" role="dialog" aria-modal="true" aria-label="Manual de uso">
    <div class="absolute inset-0 bg-gray-900/50" @click="fechar"></div>

    <div
      ref="painel"
      class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col bg-white shadow-xl"
      tabindex="-1"
      @keydown="tratarTecla">
      <!-- Cabeçalho -->
      <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-5 py-4">
        <div class="min-w-0">
          <p class="text-xs font-semibold uppercase tracking-wider text-green-600">Manual de uso</p>
          <h2 class="truncate text-lg font-semibold text-gray-900">
            {{ manualVisivel?.titulo ?? 'Ajuda' }}
          </h2>
        </div>
        <button
          type="button"
          class="shrink-0 rounded-md p-1 text-gray-400 transition-colors hover:bg-gray-100 hover:text-gray-600"
          aria-label="Fechar ajuda"
          @click="fechar">
          <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>

      <!-- Busca -->
      <div class="border-b border-gray-200 px-5 py-3">
        <div class="relative">
          <svg class="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"></path>
          </svg>
          <input
            v-model="termo"
            type="search"
            placeholder="Buscar em todo o manual..."
            class="w-full rounded-md border border-gray-300 py-2 pl-9 pr-3 text-sm shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500">
        </div>

        <div class="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1">
          <button
            v-if="manualSelecionado || termo || mostrandoIndice"
            type="button"
            class="inline-flex items-center gap-1 text-sm font-medium text-green-700 hover:text-green-800"
            @click="voltarParaTelaAtual">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Ajuda desta tela
          </button>
          <button
            type="button"
            class="text-sm font-medium text-gray-600 hover:text-gray-800"
            @click="abrirIndice">
            Todos os assuntos
          </button>
          <button
            v-if="visaoGeral"
            type="button"
            class="text-sm font-medium text-gray-600 hover:text-gray-800"
            @click="selecionar(visaoGeral)">
            Como o sistema funciona
          </button>
        </div>
      </div>

      <!-- Conteúdo -->
      <div class="flex-1 overflow-y-auto px-5 py-4">
        <!-- Carregando o manual -->
        <p v-if="!catalogo" class="rounded-lg bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">
          Carregando o manual...
        </p>

        <!-- Resultados da busca -->
        <div v-else-if="termo.trim().length >= 2">
          <p class="mb-3 text-sm text-gray-500">
            {{ resultados.length }} {{ resultados.length === 1 ? 'tela encontrada' : 'telas encontradas' }} para "{{ termo }}"
          </p>
          <ul v-if="resultados.length" class="space-y-2">
            <li v-for="resultado in resultados" :key="resultado.chave">
              <button
                type="button"
                class="w-full rounded-lg border border-gray-200 px-4 py-3 text-left transition-colors hover:border-green-300 hover:bg-green-50"
                @click="selecionar(resultado)">
                <p class="text-sm font-semibold text-gray-900">{{ resultado.titulo }}</p>
                <p class="mt-0.5 text-xs text-gray-500">{{ resultado.area }}</p>
                <p class="mt-1 text-sm text-gray-600">{{ resultado.paraQueServe }}</p>
              </button>
            </li>
          </ul>
          <p v-else class="rounded-lg bg-gray-50 px-4 py-6 text-center text-sm text-gray-500">
            Nada encontrado. Tente outra palavra, como "certificado", "contrato" ou "estoque".
          </p>
        </div>

        <!-- Manual da tela -->
        <div v-else-if="manualVisivel && !mostrandoIndice" class="space-y-6">
          <p class="text-sm leading-relaxed text-gray-700">{{ manualVisivel.paraQueServe }}</p>

          <section v-for="bloco in manualVisivel.comoUsar ?? []" :key="bloco.titulo">
            <h3 class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500">{{ bloco.titulo }}</h3>
            <ol class="space-y-2">
              <li v-for="(passo, indice) in bloco.passos" :key="indice" class="flex gap-3 text-sm text-gray-700">
                <span class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-green-100 text-xs font-semibold text-green-700">
                  {{ indice + 1 }}
                </span>
                <span class="leading-relaxed">{{ passo }}</span>
              </li>
            </ol>
          </section>

          <section v-if="manualVisivel.campos?.length">
            <h3 class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500">Campos da tela</h3>
            <dl class="divide-y divide-gray-100 rounded-lg border border-gray-200">
              <div v-for="campo in manualVisivel.campos" :key="campo.nome" class="px-4 py-3">
                <dt class="text-sm font-medium text-gray-900">{{ campo.nome }}</dt>
                <dd class="mt-0.5 text-sm leading-relaxed text-gray-600">{{ campo.descricao }}</dd>
              </div>
            </dl>
          </section>

          <section v-if="manualVisivel.dicas?.length" class="rounded-lg bg-green-50 p-4">
            <h3 class="mb-2 text-sm font-semibold text-green-800">Dicas</h3>
            <ul class="space-y-1.5">
              <li v-for="(dica, indice) in manualVisivel.dicas" :key="indice" class="flex gap-2 text-sm leading-relaxed text-green-900">
                <span aria-hidden="true">&bull;</span>
                <span>{{ dica }}</span>
              </li>
            </ul>
          </section>

          <section v-if="manualVisivel.atencao?.length" class="rounded-lg bg-yellow-50 p-4">
            <h3 class="mb-2 text-sm font-semibold text-yellow-800">Atenção</h3>
            <ul class="space-y-1.5">
              <li v-for="(aviso, indice) in manualVisivel.atencao" :key="indice" class="flex gap-2 text-sm leading-relaxed text-yellow-900">
                <span aria-hidden="true">&bull;</span>
                <span>{{ aviso }}</span>
              </li>
            </ul>
          </section>

          <section v-if="manualVisivel.relacionados?.length">
            <h3 class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500">Telas relacionadas</h3>
            <div class="flex flex-wrap gap-2">
              <Link
                v-for="relacionado in manualVisivel.relacionados"
                :key="relacionado.href"
                :href="relacionado.href"
                class="rounded-full border border-gray-300 px-3 py-1 text-sm text-gray-700 transition-colors hover:border-green-400 hover:bg-green-50 hover:text-green-800"
                @click="fechar">
                {{ relacionado.titulo }}
              </Link>
            </div>
          </section>
        </div>

        <!-- Tela sem manual próprio: mostra o índice geral -->
        <div v-else class="space-y-5">
          <p v-if="!mostrandoIndice" class="rounded-lg bg-gray-50 px-4 py-3 text-sm text-gray-600">
            Esta tela ainda não tem um manual próprio. Use a busca acima ou escolha um assunto abaixo.
          </p>
          <section v-for="grupo in indice" :key="grupo.area">
            <h3 class="mb-2 text-sm font-semibold uppercase tracking-wider text-gray-500">{{ grupo.area }}</h3>
            <ul class="space-y-1">
              <li v-for="tela in grupo.telas" :key="tela.chave">
                <button
                  type="button"
                  class="w-full rounded-md px-3 py-2 text-left text-sm text-gray-700 transition-colors hover:bg-green-50 hover:text-green-800"
                  @click="selecionar(tela)">
                  {{ tela.titulo }}
                </button>
              </li>
            </ul>
          </section>
        </div>
      </div>

      <!-- Rodapé -->
      <div class="border-t border-gray-200 px-5 py-3 text-xs text-gray-500">
        Abra a ajuda a qualquer momento com a tecla <kbd class="rounded border border-gray-300 bg-gray-50 px-1.5 py-0.5 font-sans">{{ TECLA_DE_ATALHO }}</kbd>.
      </div>
    </div>
  </div>
</template>

<script setup>
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';

const TECLA_DE_ATALHO = 'F1';

// Define de qual conjunto de telas a busca e o índice desta ajuda tratam:
// 'sistema' (empresa), 'portal' (cliente final) ou 'plataforma' (administração).
const props = defineProps({
  escopo: {
    type: String,
    default: 'sistema',
    validator: (valor) => ['sistema', 'portal', 'plataforma'].includes(valor),
  },
});

const pagina = usePage();
const aberto = ref(false);
const termo = ref('');
const manualSelecionado = ref(null);
const mostrandoIndice = ref(false);
// O catálogo do manual é grande e não é usado na maioria das visitas: só é
// baixado quando o usuário abre a ajuda pela primeira vez.
const catalogo = ref(null);
const carregando = ref(false);
const painel = ref(null);
let focoAnterior = null;

const manualDaPaginaAtual = computed(() => catalogo.value?.manualDaTela(pagina.component) ?? null);
const visaoGeral = computed(() => {
  const geral = catalogo.value?.manuais?._visaoGeral;

  if (!geral || props.escopo !== 'sistema') {
    return null;
  }

  return { chave: '_visaoGeral', ...geral };
});
const manualVisivel = computed(() => manualSelecionado.value ?? manualDaPaginaAtual.value);
const resultados = computed(() => catalogo.value?.buscarNosManuais(termo.value, props.escopo) ?? []);
const indice = computed(() => catalogo.value?.manuaisPorArea(props.escopo) ?? []);

const carregarCatalogo = async () => {
  if (catalogo.value || carregando.value) {
    return;
  }

  carregando.value = true;

  try {
    catalogo.value = await import('@/ajuda/index.js');
  } finally {
    carregando.value = false;
  }
};

const abrir = async () => {
  focoAnterior = document.activeElement;
  aberto.value = true;
  carregarCatalogo();
  await nextTick();
  painel.value?.focus();
};

const fechar = () => {
  aberto.value = false;
  termo.value = '';
  manualSelecionado.value = null;
  mostrandoIndice.value = false;
  focoAnterior?.focus?.();
  focoAnterior = null;
};

const selecionar = (manual) => {
  manualSelecionado.value = manual;
  mostrandoIndice.value = false;
  termo.value = '';
};

const abrirIndice = () => {
  mostrandoIndice.value = true;
  manualSelecionado.value = null;
  termo.value = '';
};

const voltarParaTelaAtual = () => {
  manualSelecionado.value = null;
  mostrandoIndice.value = false;
  termo.value = '';
};

const tratarTecla = (evento) => {
  if (evento.key === 'Escape') {
    evento.preventDefault();
    fechar();
  }
};

const atalhoGlobal = (evento) => {
  if (evento.key !== TECLA_DE_ATALHO) {
    return;
  }

  evento.preventDefault();

  if (aberto.value) {
    fechar();
    return;
  }

  abrir();
};

// Ao navegar para outra tela, a ajuda volta a apontar para a tela atual.
watch(() => pagina.component, () => {
  manualSelecionado.value = null;
  mostrandoIndice.value = false;
  termo.value = '';
});

onMounted(() => document.addEventListener('keydown', atalhoGlobal));
onUnmounted(() => document.removeEventListener('keydown', atalhoGlobal));
</script>
