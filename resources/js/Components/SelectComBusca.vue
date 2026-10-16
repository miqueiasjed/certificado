<template>
  <div ref="raiz" class="relative" :class="$attrs.class">
    <input
      :id="id"
      ref="campo"
      type="text"
      role="combobox"
      autocomplete="off"
      :aria-expanded="aberto"
      :disabled="disabled"
      :value="aberto ? busca : rotuloSelecionado"
      :placeholder="aberto ? (rotuloSelecionado || placeholder) : placeholder"
      :class="[
        classeCampo || 'w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500',
        'pr-14 truncate',
        { 'border-red-500': erro, 'bg-gray-100 cursor-not-allowed': disabled },
      ]"
      @focus="abrir"
      @click="abrir"
      @input="aoDigitar"
      @keydown="tratarTecla"
    />

    <!--
      O campo visível mostra o rótulo, não o valor, então a validação nativa
      de obrigatório fica neste input invisível, que o navegador ainda
      consegue focar e apontar.
    -->
    <input
      v-if="required"
      tabindex="-1"
      aria-hidden="true"
      required
      :value="selecionado ? String(modelValue) : ''"
      class="absolute bottom-0 left-1/2 w-px h-px opacity-0 pointer-events-none"
    />

    <div class="absolute inset-y-0 right-0 flex items-center pr-2 gap-1">
      <button
        v-if="podeLimpar"
        type="button"
        tabindex="-1"
        class="p-0.5 text-gray-400 hover:text-gray-600"
        title="Limpar"
        @mousedown.prevent
        @click="selecionar(null)"
      >
        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
      <svg class="w-4 h-4 text-gray-400 pointer-events-none" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
      </svg>
    </div>

    <!--
      A lista vai para o body porque o Modal usa overflow-hidden e cortaria um
      dropdown posicionado dentro dele.
    -->
    <Teleport to="body">
      <ul
        v-if="aberto"
        ref="lista"
        role="listbox"
        class="fixed z-[60] bg-white shadow-lg rounded-md py-1 text-sm ring-1 ring-black ring-opacity-5 overflow-auto"
        :style="estiloLista"
      >
        <li v-if="filtradas.length === 0" class="px-3 py-2 text-gray-500">{{ textoVazio }}</li>
        <li
          v-for="(item, indice) in filtradas"
          :key="item.chave"
          role="option"
          :aria-selected="item.selecionada"
          class="cursor-pointer select-none px-3 py-2"
          :class="[
            indice === destaque ? 'bg-green-50 text-green-900' : 'text-gray-900',
            item.selecionada ? 'font-medium' : '',
            item.vazia ? 'text-gray-500 italic' : '',
          ]"
          @mousedown.prevent
          @mouseenter="destaque = indice"
          @click="selecionar(item)"
        >
          <span class="block truncate">{{ item.rotulo }}</span>
          <span v-if="item.detalhe" class="block truncate text-xs text-gray-500">{{ item.detalhe }}</span>
        </li>
        <li v-if="excedente > 0" class="px-3 py-2 text-xs text-gray-500">
          Mais {{ excedente }} resultado(s). Digite para refinar a busca.
        </li>
      </ul>
    </Teleport>
  </div>
</template>

<script setup>
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';

defineOptions({ inheritAttrs: false });

const props = defineProps({
  modelValue: { type: [String, Number, Boolean, null], default: null },
  opcoes: { type: Array, default: () => [] },
  // Campo (ou função) que dá o valor gravado no v-model.
  chaveValor: { type: [String, Function], default: 'id' },
  // Campo (ou função) que dá o texto exibido e buscado.
  chaveRotulo: { type: [String, Function], default: 'name' },
  // Linha secundária opcional, também entra na busca.
  chaveDetalhe: { type: [String, Function], default: null },
  placeholder: { type: String, default: 'Selecione...' },
  permitirVazio: { type: Boolean, default: true },
  valorVazio: { type: [String, Number, null], default: '' },
  disabled: { type: Boolean, default: false },
  required: { type: Boolean, default: false },
  erro: { type: [Boolean, String], default: false },
  id: { type: String, default: null },
  classeCampo: { type: String, default: null },
  textoVazio: { type: String, default: 'Nenhum resultado encontrado.' },
});

const emit = defineEmits(['update:modelValue', 'change']);

const LIMITE = 200;

const raiz = ref(null);
const campo = ref(null);
const lista = ref(null);
const aberto = ref(false);
const busca = ref('');
const destaque = ref(0);
const estiloLista = ref({});

const ler = (opcao, chave) => {
  if (chave === null) return null;
  if (opcao === null || typeof opcao !== 'object') return opcao;
  return typeof chave === 'function' ? chave(opcao) : opcao[chave];
};

const normalizar = (texto) =>
  String(texto ?? '')
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase();

const estaVazio = (valor) => valor === null || valor === undefined || valor === '';

// Compara como texto porque a mesma tela às vezes guarda o id como número e
// a opção como string (ou o contrário).
const mesmoValor = (a, b) => !estaVazio(a) && !estaVazio(b) && String(a) === String(b);

const itens = computed(() =>
  props.opcoes.map((opcao, indice) => {
    const valor = ler(opcao, props.chaveValor);
    const rotulo = String(ler(opcao, props.chaveRotulo) ?? '');
    const detalhe = props.chaveDetalhe ? ler(opcao, props.chaveDetalhe) : null;

    return {
      chave: `${indice}-${valor}`,
      valor,
      rotulo,
      detalhe,
      busca: normalizar(`${rotulo} ${detalhe ?? ''}`),
      selecionada: mesmoValor(valor, props.modelValue),
    };
  })
);

const selecionado = computed(() => itens.value.find((item) => item.selecionada) || null);
const rotuloSelecionado = computed(() => selecionado.value?.rotulo ?? '');
const podeLimpar = computed(() => props.permitirVazio && !props.disabled && selecionado.value !== null);

const encontradas = computed(() => {
  const termos = normalizar(busca.value).split(/\s+/).filter(Boolean);
  if (termos.length === 0) return itens.value;
  return itens.value.filter((item) => termos.every((termo) => item.busca.includes(termo)));
});

const excedente = computed(() => Math.max(0, encontradas.value.length - LIMITE));

const filtradas = computed(() => {
  const resultado = encontradas.value.slice(0, LIMITE);

  if (props.permitirVazio && busca.value === '') {
    resultado.unshift({ chave: '__vazio', valor: props.valorVazio, rotulo: props.placeholder, vazia: true });
  }

  return resultado;
});

const posicionar = () => {
  if (!campo.value) return;

  const caixa = campo.value.getBoundingClientRect();
  const alturaMaxima = 240;
  const espacoAbaixo = window.innerHeight - caixa.bottom;
  const abrirParaCima = espacoAbaixo < alturaMaxima && caixa.top > espacoAbaixo;

  estiloLista.value = {
    left: `${caixa.left}px`,
    width: `${caixa.width}px`,
    maxHeight: `${Math.min(alturaMaxima, (abrirParaCima ? caixa.top : espacoAbaixo) - 8)}px`,
    ...(abrirParaCima
      ? { bottom: `${window.innerHeight - caixa.top + 4}px` }
      : { top: `${caixa.bottom + 4}px` }),
  };
};

const rolarAteDestaque = () => {
  nextTick(() => {
    lista.value?.children[destaque.value]?.scrollIntoView({ block: 'nearest' });
  });
};

const aoClicarFora = (evento) => {
  if (raiz.value?.contains(evento.target) || lista.value?.contains(evento.target)) return;
  fechar();
};

const abrir = () => {
  if (props.disabled || aberto.value) return;

  aberto.value = true;
  busca.value = '';
  const indice = filtradas.value.findIndex((item) => item.selecionada);
  destaque.value = indice >= 0 ? indice : 0;

  posicionar();
  rolarAteDestaque();
  window.addEventListener('scroll', posicionar, true);
  window.addEventListener('resize', posicionar);
  document.addEventListener('mousedown', aoClicarFora);
};

const fechar = () => {
  if (!aberto.value) return;

  aberto.value = false;
  busca.value = '';
  window.removeEventListener('scroll', posicionar, true);
  window.removeEventListener('resize', posicionar);
  document.removeEventListener('mousedown', aoClicarFora);
};

const selecionar = (item) => {
  const valor = item ? item.valor : props.valorVazio;

  if (String(valor ?? '') !== String(props.modelValue ?? '')) {
    emit('update:modelValue', valor);
    emit('change', valor);
  }

  fechar();
};

const aoDigitar = (evento) => {
  if (!aberto.value) abrir();
  busca.value = evento.target.value;
  destaque.value = 0;
  posicionar();
};

const tratarTecla = (evento) => {
  if (evento.key === 'Escape') {
    if (aberto.value) {
      // Sem isto o Esc também fecharia o Modal em volta.
      evento.preventDefault();
      evento.stopPropagation();
      fechar();
    }
    return;
  }

  if (evento.key === 'Tab') {
    fechar();
    return;
  }

  if (evento.key === 'ArrowDown' || evento.key === 'ArrowUp') {
    evento.preventDefault();
    if (!aberto.value) {
      abrir();
      return;
    }
    const total = filtradas.value.length;
    if (total === 0) return;
    destaque.value = (destaque.value + (evento.key === 'ArrowDown' ? 1 : -1) + total) % total;
    rolarAteDestaque();
    return;
  }

  if (evento.key === 'Enter') {
    if (!aberto.value) return;
    // Enter escolhe a opção em vez de enviar o formulário.
    evento.preventDefault();
    const item = filtradas.value[destaque.value];
    if (item) selecionar(item.vazia ? null : item);
  }
};

onBeforeUnmount(fechar);

defineExpose({ focus: () => campo.value?.focus() });
</script>
