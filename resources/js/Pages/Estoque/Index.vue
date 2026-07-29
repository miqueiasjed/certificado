<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Estoque" description="Saldo por produto, com a quebra por local e por lote.">
        <template #actions>
          <Link :href="route('lotes.index')" class="btn-secondary">Lotes cadastrados</Link>
          <button type="button" class="btn-primary" @click="abrirModal('entrada')">Nova movimentação</button>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-6xl mx-auto space-y-6">
      <div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>
      <div
        v-if="avisoLocal.texto"
        class="rounded-md p-4 border"
        :class="avisoLocal.tipo === 'erro' ? 'bg-red-50 border-red-200' : 'bg-green-50 border-green-200'"
      >
        <p class="text-sm font-medium" :class="avisoLocal.tipo === 'erro' ? 'text-red-800' : 'text-green-800'">
          {{ avisoLocal.texto }}
        </p>
      </div>

      <!-- Indicadores clicáveis -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <button type="button" class="text-left" @click="alternarFiltro('abaixo_do_minimo')">
          <StatCard
            title="Abaixo do mínimo"
            :value="indicadores.abaixo_do_minimo"
            color="red"
            :subtitle="filtros.abaixo_do_minimo ? 'Filtro ativo - clique para limpar' : 'Produtos com saldo abaixo do mínimo'"
            :class="filtros.abaixo_do_minimo ? 'ring-2 ring-green-600' : ''"
          />
        </button>
        <button type="button" class="text-left" @click="alternarFiltro('vencendo')">
          <StatCard
            :title="`Lotes vencendo em ${indicadores.dias_de_alerta} dias`"
            :value="indicadores.lotes_vencendo"
            color="yellow"
            :subtitle="filtros.validade === 'vencendo' ? 'Filtro ativo - clique para limpar' : 'Clique para ver a lista'"
            :class="filtros.validade === 'vencendo' ? 'ring-2 ring-green-600' : ''"
          />
        </button>
        <button type="button" class="text-left" @click="alternarFiltro('vencido')">
          <StatCard
            title="Lotes vencidos com saldo"
            :value="indicadores.lotes_vencidos"
            color="red"
            :subtitle="filtros.validade === 'vencido' ? 'Filtro ativo - clique para limpar' : 'Clique para ver a lista'"
            :class="filtros.validade === 'vencido' ? 'ring-2 ring-green-600' : ''"
          />
        </button>
      </div>

      <!-- Filtros adicionais -->
      <Card padding="small">
        <div class="flex flex-col sm:flex-row sm:items-end gap-3">
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Produto</label>
            <select
              v-model="filtroProduto"
              @change="aplicarFiltros"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os produtos</option>
              <option v-for="produto in produtosDoFiltro" :key="produto.id" :value="produto.id">{{ produto.nome }}</option>
            </select>
          </div>
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Local</label>
            <select
              v-model="filtroLocal"
              @change="aplicarFiltros"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os locais</option>
              <option v-for="local in locais" :key="local.id" :value="local.id">{{ local.nome }}</option>
            </select>
          </div>
          <button v-if="temFiltroAtivo" type="button" class="btn-secondary-sm shrink-0" @click="limparFiltros">
            Limpar filtros
          </button>
        </div>
      </Card>

      <!-- Lista por produto -->
      <Card padding="none">
        <div v-if="produtos.length === 0" class="p-8 text-center text-sm text-gray-500">
          Nenhum produto encontrado com estes filtros.
        </div>

        <div v-else>
          <div v-for="produto in produtos" :key="produto.id" class="border-b border-gray-200 last:border-b-0">
            <button
              type="button"
              class="w-full flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2 px-4 py-4 hover:bg-gray-50 text-left"
              @click="alternarExpandido(produto.id)"
            >
              <div class="flex items-start gap-3 min-w-0">
                <svg
                  class="w-5 h-5 text-gray-400 shrink-0 mt-0.5 transition-transform"
                  :class="estaExpandido(produto.id) ? 'rotate-90' : ''"
                  fill="none" stroke="currentColor" viewBox="0 0 24 24"
                >
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
                <div class="min-w-0">
                  <p class="font-medium text-gray-900 truncate">{{ produto.nome }}</p>
                  <div class="flex flex-wrap gap-1 mt-1">
                    <span v-if="produto.abaixo_do_minimo" class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                      Abaixo do mínimo
                    </span>
                    <span v-if="produto.tem_lote_vencido" class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                      Tem lote vencido
                    </span>
                    <span v-if="produto.tem_lote_vencendo" class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                      Tem lote vencendo
                    </span>
                  </div>
                </div>
              </div>
              <div class="pl-8 sm:pl-0 text-right">
                <p class="text-sm font-semibold" :class="produto.abaixo_do_minimo ? 'text-red-600' : 'text-gray-900'">
                  {{ formatarQuantidade(produto.saldo) }} {{ produto.unidade }}
                </p>
                <p class="text-xs text-gray-500">
                  mínimo:
                  {{ produto.estoque_minimo !== null ? `${formatarQuantidade(produto.estoque_minimo)} ${produto.unidade}` : 'não definido' }}
                </p>
              </div>
            </button>

            <div v-if="estaExpandido(produto.id)" class="px-4 pb-4 bg-gray-50 space-y-4">
              <!-- Por local -->
              <div>
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2">Por local</h4>
                <div class="space-y-1">
                  <div v-for="local in produto.locais" :key="local.stock_location_id" class="flex items-center justify-between text-sm">
                    <span class="text-gray-700">
                      {{ local.nome }}
                      <span class="text-gray-400">({{ ROTULO_TIPO_LOCAL[local.tipo] || local.tipo }})</span>
                    </span>
                    <span class="font-medium text-gray-900">{{ formatarQuantidade(local.quantidade) }} {{ produto.unidade }}</span>
                  </div>
                  <div v-if="produto.sem_lote > 0" class="flex items-center justify-between text-sm">
                    <span class="text-gray-500 italic">Sem lote definido</span>
                    <span class="font-medium text-gray-700">{{ formatarQuantidade(produto.sem_lote) }} {{ produto.unidade }}</span>
                  </div>
                  <p v-if="produto.locais.length === 0 && produto.sem_lote <= 0" class="text-sm text-gray-400">
                    Sem saldo em nenhum local.
                  </p>
                </div>
              </div>

              <!-- Por lote -->
              <div v-if="produto.lotes.length > 0">
                <h4 class="text-xs font-semibold uppercase tracking-wider text-gray-500 mb-2">Por lote</h4>
                <div class="space-y-2">
                  <div
                    v-for="lote in produto.lotes"
                    :key="lote.product_batch_id"
                    class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 text-sm border border-gray-200 rounded-md px-3 py-2 bg-white"
                  >
                    <div class="flex flex-wrap items-center gap-2">
                      <span class="font-medium text-gray-900">{{ lote.lote }}</span>
                      <span class="text-gray-500">vence {{ formatarData(lote.validade) }}</span>
                      <span
                        class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium"
                        :class="CLASSE_SITUACAO[lote.situacao] || 'bg-gray-100 text-gray-800'"
                      >
                        {{ ROTULO_SITUACAO[lote.situacao] || lote.situacao }}
                      </span>
                    </div>
                    <div class="flex items-center gap-4 text-gray-700">
                      <span>{{ formatarQuantidade(lote.quantidade) }} {{ produto.unidade }}</span>
                      <span class="text-xs text-gray-500">custo: {{ formatarMoeda(lote.custo_unitario) }}/{{ produto.unidade }}</span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="flex flex-wrap gap-2 pt-2">
                <button type="button" class="btn-secondary-sm" @click.stop="abrirModal('entrada', produto)">Entrada</button>
                <button type="button" class="btn-secondary-sm" @click.stop="abrirModal('transferencia', produto)">Transferência</button>
                <button type="button" class="btn-secondary-sm" @click.stop="abrirModal('descarte', produto)">Descarte</button>
              </div>
            </div>
          </div>
        </div>
      </Card>
    </div>

    <MovimentacaoModal
      :show="modalAberto"
      :tipo-inicial="modalTipo"
      :locais="locais"
      :produtos="produtosDoFiltro"
      :saldos="mapaDeSaldos"
      :produto-fixo="modalProdutoFixo"
      @close="fecharModal"
      @sucesso="aoMovimentar"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, onUnmounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import MovimentacaoModal from '@/Components/MovimentacaoModal.vue';
import { formatarData } from '@/utils/formatDate';

const props = defineProps({
  filtros: Object,
  indicadores: Object,
  produtos: Array,
  locais: Array,
  produtosDoFiltro: Array,
});

const ROTULO_TIPO_LOCAL = {
  deposito: 'Depósito',
  tecnico: 'Técnico',
  veiculo: 'Veículo',
};

const ROTULO_SITUACAO = {
  normal: 'Normal',
  vencendo: 'Vencendo',
  vencido: 'Vencido',
};

const CLASSE_SITUACAO = {
  normal: 'bg-gray-100 text-gray-800',
  vencendo: 'bg-yellow-100 text-yellow-800',
  vencido: 'bg-red-100 text-red-800',
};

const formatarQuantidade = (valor) => {
  if (valor === null || valor === undefined) return '-';

  return Number(valor).toLocaleString('pt-BR', { maximumFractionDigits: 4 });
};

const formatarMoeda = (valor) => {
  if (valor === null || valor === undefined) return '-';

  return Number(valor).toLocaleString('pt-BR', {
    style: 'currency',
    currency: 'BRL',
    minimumFractionDigits: 2,
    maximumFractionDigits: 4,
  });
};

// -----------------------------------------------------------------
// Filtros
// -----------------------------------------------------------------

const filtroProduto = ref(props.filtros.product_id ? String(props.filtros.product_id) : '');
const filtroLocal = ref(props.filtros.stock_location_id ? String(props.filtros.stock_location_id) : '');

const temFiltroAtivo = computed(() => Boolean(
  props.filtros.product_id
  || props.filtros.stock_location_id
  || props.filtros.validade
  || props.filtros.abaixo_do_minimo
));

const construirParametros = () => {
  const parametros = {};

  if (filtroProduto.value) parametros.product_id = filtroProduto.value;
  if (filtroLocal.value) parametros.stock_location_id = filtroLocal.value;
  if (props.filtros.validade) parametros.validade = props.filtros.validade;
  if (props.filtros.abaixo_do_minimo) parametros.abaixo_do_minimo = 1;

  return parametros;
};

const aplicarFiltros = () => {
  router.get(route('estoque.index'), construirParametros(), { preserveState: true, replace: true });
};

const alternarFiltro = (chave) => {
  const parametros = construirParametros();

  if (chave === 'abaixo_do_minimo') {
    if (props.filtros.abaixo_do_minimo) {
      delete parametros.abaixo_do_minimo;
    } else {
      parametros.abaixo_do_minimo = 1;
    }
  } else if (props.filtros.validade === chave) {
    delete parametros.validade;
  } else {
    parametros.validade = chave;
  }

  router.get(route('estoque.index'), parametros, { preserveState: true, replace: true });
};

const limparFiltros = () => {
  filtroProduto.value = '';
  filtroLocal.value = '';
  router.get(route('estoque.index'), {}, { preserveState: true, replace: true });
};

// -----------------------------------------------------------------
// Expandir/recolher produto
// -----------------------------------------------------------------

const expandidos = ref(new Set());

const estaExpandido = (produtoId) => expandidos.value.has(produtoId);

const alternarExpandido = (produtoId) => {
  const novo = new Set(expandidos.value);

  if (novo.has(produtoId)) {
    novo.delete(produtoId);
  } else {
    novo.add(produtoId);
  }

  expandidos.value = novo;
};

// -----------------------------------------------------------------
// Modal de movimentação
// -----------------------------------------------------------------

// Mapa produtoId -> { locais, lotes }, montado com o que já está na tela
// (a lista `produtos`, que pode estar filtrada). Nenhuma soma nova: só a
// quebra que o backend já devolveu.
const mapaDeSaldos = computed(() => {
  const mapa = {};

  props.produtos.forEach((produto) => {
    mapa[produto.id] = { locais: produto.locais, lotes: produto.lotes };
  });

  return mapa;
});

const modalAberto = ref(false);
const modalTipo = ref('entrada');
const modalProdutoFixo = ref(null);

const abrirModal = (tipo, produto = null) => {
  modalTipo.value = tipo;
  modalProdutoFixo.value = produto ? { id: produto.id, nome: produto.nome, unidade: produto.unidade } : null;
  modalAberto.value = true;
};

const fecharModal = () => {
  modalAberto.value = false;
};

const avisoLocal = ref({ tipo: '', texto: '' });
let avisoTimeout = null;

const aoMovimentar = (mensagem) => {
  modalAberto.value = false;
  avisoLocal.value = { tipo: 'sucesso', texto: mensagem };

  if (avisoTimeout) clearTimeout(avisoTimeout);
  avisoTimeout = setTimeout(() => {
    avisoLocal.value = { tipo: '', texto: '' };
  }, 6000);

  router.reload({ only: ['produtos', 'indicadores'] });
};

onUnmounted(() => {
  if (avisoTimeout) clearTimeout(avisoTimeout);
});
</script>
