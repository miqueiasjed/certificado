<template>
  <Modal :show="show" @close="fechar">
    <template #icon>
      <svg v-if="tipo === 'entrada'" class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m0 0l-6-6m6 6l6-6" />
      </svg>
      <svg v-else-if="tipo === 'transferencia'" class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h12m0 0l-4-4m4 4l-4 4M16 17H4m0 0l4 4m-4-4l4-4" />
      </svg>
      <svg v-else class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
      </svg>
    </template>

    <template #title>{{ tituloTipo }}</template>

    <template #content>
      <div class="space-y-4 text-left">
        <div v-if="erro" class="bg-red-50 border border-red-200 rounded-md p-3">
          <p class="text-sm text-red-800">{{ erro }}</p>
        </div>

        <!-- Tipo de movimentação -->
        <div class="flex gap-2" role="tablist">
          <button
            v-for="opcao in TIPOS"
            :key="opcao.valor"
            type="button"
            role="tab"
            :aria-selected="tipo === opcao.valor"
            @click="selecionarTipo(opcao.valor)"
            class="flex-1 px-2 py-2 text-sm font-medium rounded-md border transition-colors"
            :class="tipo === opcao.valor
              ? 'bg-green-600 text-white border-green-600'
              : 'bg-white text-gray-700 border-gray-300 hover:bg-gray-50'"
          >
            {{ opcao.rotulo }}
          </button>
        </div>

        <!-- Produto -->
        <div>
          <label for="mov_produto" class="block text-sm font-medium text-gray-700 mb-1">Produto *</label>
          <select
            id="mov_produto"
            v-model="form.product_id"
            :disabled="!!produtoFixo"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 disabled:bg-gray-100 disabled:text-gray-500"
          >
            <option value="">Selecione um produto</option>
            <option v-for="produto in produtos" :key="produto.id" :value="produto.id">{{ produto.nome }}</option>
          </select>
        </div>

        <!-- Campos por tipo -->
        <div v-if="tipo === 'entrada'">
          <label for="mov_local_entrada" class="block text-sm font-medium text-gray-700 mb-1">Local que recebe *</label>
          <select
            id="mov_local_entrada"
            v-model="form.stock_location_id"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          >
            <option value="">Selecione um local</option>
            <option v-for="local in locais" :key="local.id" :value="local.id">{{ local.nome }}</option>
          </select>
        </div>

        <div v-else-if="tipo === 'transferencia'" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label for="mov_origem" class="block text-sm font-medium text-gray-700 mb-1">Origem *</label>
            <select
              id="mov_origem"
              v-model="form.origem_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Selecione a origem</option>
              <option v-for="local in locais" :key="local.id" :value="local.id">{{ local.nome }}</option>
            </select>
          </div>
          <div>
            <label for="mov_destino" class="block text-sm font-medium text-gray-700 mb-1">Destino *</label>
            <select
              id="mov_destino"
              v-model="form.destino_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Selecione o destino</option>
              <option v-for="local in locais" :key="local.id" :value="local.id">{{ local.nome }}</option>
            </select>
          </div>
          <p v-if="form.origem_id && form.destino_id && String(form.origem_id) === String(form.destino_id)" class="sm:col-span-2 text-sm text-red-600">
            Origem e destino precisam ser locais diferentes.
          </p>
        </div>

        <div v-else>
          <label for="mov_local_descarte" class="block text-sm font-medium text-gray-700 mb-1">Local de onde sai *</label>
          <select
            id="mov_local_descarte"
            v-model="form.stock_location_id"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          >
            <option value="">Selecione um local</option>
            <option v-for="local in locais" :key="local.id" :value="local.id">{{ local.nome }}</option>
          </select>
        </div>

        <!-- Saldo do local relevante, sempre o valor vindo do servidor -->
        <p v-if="produtoSelecionado && localRelevante && saldoNoLocal !== null" class="text-sm text-gray-600">
          Saldo atual de <strong>{{ produtoSelecionado.nome }}</strong> neste local:
          <strong :class="quantidadeExcedeSaldo ? 'text-red-600' : 'text-gray-900'">
            {{ formatarQuantidade(saldoNoLocal) }} {{ produtoSelecionado.unidade }}
          </strong>
        </p>
        <p v-else-if="produtoSelecionado && localRelevante" class="text-xs text-gray-400">
          Saldo por local não carregado nesta tela para este produto (filtro aplicado). Confira antes de enviar.
        </p>

        <!-- Quantidade -->
        <div>
          <label for="mov_quantidade" class="block text-sm font-medium text-gray-700 mb-1">
            Quantidade<template v-if="produtoSelecionado"> ({{ produtoSelecionado.unidade }})</template> *
          </label>
          <input
            id="mov_quantidade"
            v-model.number="form.quantidade"
            type="number"
            step="0.0001"
            min="0.0001"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          />
        </div>

        <!-- Lote -->
        <div>
          <label for="mov_lote" class="block text-sm font-medium text-gray-700 mb-1">Lote</label>
          <select
            id="mov_lote"
            v-model="form.product_batch_id"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          >
            <option value="">{{ tipo === 'entrada' ? 'Sem lote específico' : 'Automático (por validade)' }}</option>
            <option v-for="lote in lotesDoProduto" :key="lote.product_batch_id" :value="lote.product_batch_id">
              {{ lote.lote }} - vence {{ formatarData(lote.validade) }} ({{ ROTULO_SITUACAO[lote.situacao] || lote.situacao }})
            </option>
          </select>
        </div>

        <!-- Motivo -->
        <div>
          <label for="mov_motivo" class="block text-sm font-medium text-gray-700 mb-1">
            Motivo{{ tipo === 'descarte' ? ' *' : ' (opcional)' }}
          </label>
          <textarea
            id="mov_motivo"
            v-model="form.motivo"
            rows="2"
            maxlength="1000"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          ></textarea>
          <p v-if="tipo === 'descarte'" class="mt-1 text-xs text-gray-500">O descarte exige motivo registrado.</p>
        </div>

        <!-- Data/hora da movimentação -->
        <div>
          <label for="mov_ocorrido_em" class="block text-sm font-medium text-gray-700 mb-1">Data e hora da movimentação</label>
          <input
            id="mov_ocorrido_em"
            v-model="form.ocorrido_em"
            type="datetime-local"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          />
          <p class="mt-1 text-xs text-gray-500">Deixe como está para registrar agora.</p>
        </div>
      </div>
    </template>

    <template #actions>
      <button type="button" class="btn-secondary" :disabled="enviando" @click="fechar">Cancelar</button>
      <button type="button" class="btn-primary" :disabled="enviando || !podeEnviar" @click="enviar">
        {{ enviando ? 'Enviando...' : 'Confirmar' }}
      </button>
    </template>
  </Modal>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import { formatarData, agoraInputDateTime, inputDateTimeParaUtc } from '@/utils/formatDate';

const props = defineProps({
  show: {
    type: Boolean,
    required: true,
  },
  tipoInicial: {
    type: String,
    default: 'entrada',
    validator: (valor) => ['entrada', 'transferencia', 'descarte'].includes(valor),
  },
  // [{ id, nome, tipo }]
  locais: {
    type: Array,
    default: () => [],
  },
  // [{ id, nome, unidade }] - todos os produtos que controlam estoque
  produtos: {
    type: Array,
    default: () => [],
  },
  // { [produtoId]: { locais: [{ stock_location_id, nome, quantidade }], lotes: [{ product_batch_id, lote, validade, situacao, quantidade }] } }
  // Vem pronto da tela de Estoque, sem nova chamada ao servidor.
  saldos: {
    type: Object,
    default: () => ({}),
  },
  // { id, nome, unidade } - quando a movimentação é aberta a partir de uma linha de produto
  produtoFixo: {
    type: Object,
    default: null,
  },
});

const emit = defineEmits(['close', 'sucesso']);

const TIPOS = [
  { valor: 'entrada', rotulo: 'Entrada' },
  { valor: 'transferencia', rotulo: 'Transferência' },
  { valor: 'descarte', rotulo: 'Descarte' },
];

const ROTULO_SITUACAO = {
  normal: 'validade normal',
  vencendo: 'vencendo',
  vencido: 'vencido',
};

const TITULOS = {
  entrada: 'Entrada de estoque',
  transferencia: 'Transferência entre locais',
  descarte: 'Descarte de estoque',
};

const tipo = ref(props.tipoInicial);

const formVazio = () => ({
  product_id: props.produtoFixo?.id ?? '',
  stock_location_id: '',
  origem_id: '',
  destino_id: '',
  product_batch_id: '',
  quantidade: '',
  motivo: '',
  ocorrido_em: agoraInputDateTime(),
});

const form = ref(formVazio());
const enviando = ref(false);
const erro = ref('');

watch(
  () => props.show,
  (aberto) => {
    if (aberto) {
      tipo.value = props.tipoInicial;
      form.value = formVazio();
      erro.value = '';
    }
  }
);

const tituloTipo = computed(() => TITULOS[tipo.value] || 'Movimentação de estoque');

const selecionarTipo = (novoTipo) => {
  if (tipo.value === novoTipo) return;

  tipo.value = novoTipo;
  erro.value = '';
  form.value.stock_location_id = '';
  form.value.origem_id = '';
  form.value.destino_id = '';
  form.value.product_batch_id = '';
  form.value.motivo = '';
};

const produtoSelecionado = computed(() =>
  props.produtos.find((produto) => String(produto.id) === String(form.value.product_id)) || null
);

const saldoDoProduto = computed(() => {
  if (!form.value.product_id) return null;

  return props.saldos[form.value.product_id] || null;
});

const lotesDoProduto = computed(() => saldoDoProduto.value?.lotes ?? []);

const localRelevante = computed(() =>
  tipo.value === 'transferencia' ? form.value.origem_id : form.value.stock_location_id
);

const saldoNoLocal = computed(() => {
  if (!saldoDoProduto.value || !localRelevante.value) return null;

  const linha = saldoDoProduto.value.locais.find(
    (local) => String(local.stock_location_id) === String(localRelevante.value)
  );

  return linha ? linha.quantidade : 0;
});

const quantidadeExcedeSaldo = computed(() => {
  if (saldoNoLocal.value === null || tipo.value === 'entrada') return false;

  const quantidade = Number(form.value.quantidade);

  return Number.isFinite(quantidade) && quantidade > saldoNoLocal.value;
});

const podeEnviar = computed(() => {
  const quantidadeValida = Number(form.value.quantidade) > 0;
  const produtoValido = !!form.value.product_id;

  if (!produtoValido || !quantidadeValida) return false;

  if (tipo.value === 'entrada') {
    return !!form.value.stock_location_id;
  }

  if (tipo.value === 'transferencia') {
    return (
      !!form.value.origem_id
      && !!form.value.destino_id
      && String(form.value.origem_id) !== String(form.value.destino_id)
    );
  }

  // descarte
  return !!form.value.stock_location_id && form.value.motivo.trim() !== '';
});

const formatarQuantidade = (valor) => {
  if (valor === null || valor === undefined) return '-';

  return Number(valor).toLocaleString('pt-BR', { maximumFractionDigits: 4 });
};

const cabecalhosJson = () => ({
  'Content-Type': 'application/json',
  Accept: 'application/json',
  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
});

const mensagemDoErro = async (resposta) => {
  try {
    const dados = await resposta.json();

    if (dados?.errors) {
      return Object.values(dados.errors).flat().join(' ');
    }

    if (dados?.message) {
      return dados.message;
    }
  } catch (e) {
    // resposta sem corpo JSON: cai no texto padrão abaixo.
  }

  return 'Não foi possível concluir a movimentação. Tente novamente.';
};

const montarPayload = () => {
  const payload = {
    product_id: Number(form.value.product_id),
    quantidade: Number(form.value.quantidade),
  };

  if (form.value.product_batch_id) {
    payload.product_batch_id = Number(form.value.product_batch_id);
  }

  if (form.value.motivo.trim() !== '') {
    payload.motivo = form.value.motivo.trim();
  }

  if (form.value.ocorrido_em) {
    const convertido = inputDateTimeParaUtc(form.value.ocorrido_em);
    if (convertido) {
      payload.ocorrido_em = convertido;
    }
  }

  if (tipo.value === 'entrada') {
    payload.stock_location_id = Number(form.value.stock_location_id);
  } else if (tipo.value === 'transferencia') {
    payload.origem_id = Number(form.value.origem_id);
    payload.destino_id = Number(form.value.destino_id);
  } else {
    payload.stock_location_id = Number(form.value.stock_location_id);
    payload.motivo = form.value.motivo.trim();
  }

  return payload;
};

const ROTAS = {
  entrada: 'estoque.entrada',
  transferencia: 'estoque.transferencia',
  descarte: 'estoque.descarte',
};

const enviar = async () => {
  if (!podeEnviar.value || enviando.value) return;

  erro.value = '';
  enviando.value = true;

  try {
    const resposta = await fetch(route(ROTAS[tipo.value]), {
      method: 'POST',
      headers: cabecalhosJson(),
      body: JSON.stringify(montarPayload()),
    });

    if (!resposta.ok) {
      erro.value = await mensagemDoErro(resposta);
      return;
    }

    const dados = await resposta.json();
    emit('sucesso', dados?.message || 'Movimentação registrada.');
  } catch (e) {
    erro.value = 'Não foi possível conectar ao servidor. Tente novamente.';
  } finally {
    enviando.value = false;
  }
};

const fechar = () => {
  if (enviando.value) return;
  emit('close');
};
</script>
