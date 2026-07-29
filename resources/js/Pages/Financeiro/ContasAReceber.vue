<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Contas a receber"
        :description="`${indicadores.parcelas_em_aberto} parcela(s) em aberto no momento`"
      >
        <template #actions>
          <Link href="/financeiro/inadimplencia" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
            </svg>
            Inadimplência
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <Alert
        v-if="mensagemResultado"
        :key="alertaChave"
        :type="mensagemResultado.tipo"
        :title="mensagemResultado.titulo"
        :message="mensagemResultado.detalhe"
      />

      <!-- Indicadores do topo -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard title="Vence hoje" :value="formatarMoeda(indicadores.vence_hoje)" color="yellow" />
        <StatCard title="Vencido" :value="formatarMoeda(indicadores.vencido)" color="red" />
        <StatCard title="A vencer no mês" :value="formatarMoeda(indicadores.a_vencer_no_mes)" color="blue" />
        <StatCard title="Recebido no mês" :value="formatarMoeda(indicadores.recebido_no_mes)" color="green" />
      </div>

      <!-- Filtros -->
      <Card>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">De</label>
            <input v-model="filtros.de" type="date" class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm" @change="aplicarFiltros" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Até</label>
            <input v-model="filtros.ate" type="date" class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm" @change="aplicarFiltros" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Situação</label>
            <select v-model="filtros.situacao" class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm" @change="aplicarFiltros">
              <option value="">Todas</option>
              <option v-for="(rotulo, chave) in SITUACAO_LABEL" :key="chave" :value="chave">{{ rotulo }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Cliente</label>
            <select v-model="filtros.client_id" class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm" @change="aplicarFiltros">
              <option value="">Todos</option>
              <option v-for="cliente in clientes" :key="cliente.id" :value="cliente.id">{{ cliente.nome }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Origem</label>
            <select v-model="origem" class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm">
              <option value="">Todas</option>
              <option v-for="(rotulo, chave) in ORIGEM_LABEL" :key="chave" :value="chave">{{ rotulo }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Categoria</label>
            <select v-model="filtros.chart_of_account_id" class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm" @change="aplicarFiltros">
              <option value="">Todas</option>
              <option v-for="categoria in categorias" :key="categoria.id" :value="categoria.id">{{ categoria.codigo }} - {{ categoria.nome }}</option>
            </select>
          </div>
        </div>
      </Card>

      <!-- Barra de seleção em lote -->
      <div v-if="selecionados.size > 0" class="bg-green-50 border border-green-200 rounded-md p-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <p class="text-sm text-green-900">
          {{ selecionados.size }} parcela(s) selecionada(s) de <strong>{{ clienteDaSelecao }}</strong> ·
          total {{ formatarMoeda(totalSelecionado) }}
        </p>
        <div class="flex gap-3">
          <button type="button" class="btn-secondary-sm" @click="limparSelecao">Limpar seleção</button>
          <button type="button" class="btn-primary" @click="abrirBaixaEmLote">Baixar selecionadas</button>
        </div>
      </div>

      <!-- Lista de parcelas -->
      <Card padding="none">
        <div v-if="parcelasFiltradas.length === 0" class="p-12 text-center">
          <p class="text-sm text-gray-500">Nenhuma parcela encontrada com os filtros atuais.</p>
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 w-10"></th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cliente</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Descrição</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vencimento</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Valor</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Pago</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Saldo</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Situação</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr
                v-for="parcela in parcelasFiltradas"
                :key="parcela.id"
                :class="parcela.vencida ? 'bg-red-50 hover:bg-red-100' : 'hover:bg-gray-50'"
              >
                <td class="px-4 py-4">
                  <input
                    type="checkbox"
                    :checked="selecionados.has(parcela.id)"
                    :disabled="!podeSelecionar(parcela)"
                    class="h-4 w-4 text-green-600 border-gray-300 rounded disabled:opacity-30"
                    :title="!temSaldo(parcela) ? 'Parcela sem saldo devedor' : (clienteBloqueado(parcela) ? 'Selecione parcelas do mesmo cliente' : '')"
                    @change="alternarSelecao(parcela)"
                  />
                </td>
                <td class="px-4 py-4 text-sm text-gray-900">
                  {{ parcela.cliente }}
                  <div class="text-xs text-gray-500">
                    {{ ORIGEM_LABEL[parcela.origem] || parcela.origem }}
                    <span v-if="parcela.ordem_de_servico"> · OS #{{ parcela.ordem_de_servico }}</span>
                  </div>
                </td>
                <td class="px-4 py-4 text-sm text-gray-700">{{ parcela.descricao }}</td>
                <td class="px-4 py-4 text-sm text-gray-700">
                  {{ formatarData(parcela.vencimento) }}
                  <div v-if="parcela.vencida" class="text-xs text-red-700 font-medium">
                    Vencida há {{ diasDeAtraso(parcela) }} dia(s)
                  </div>
                  <div v-else-if="parcela.pago_em" class="text-xs text-gray-500">
                    Pago em {{ formatarData(parcela.pago_em) }}
                  </div>
                </td>
                <td class="px-4 py-4 text-sm text-gray-700 text-right">{{ formatarMoeda(parcela.valor) }}</td>
                <td class="px-4 py-4 text-sm text-gray-700 text-right">{{ formatarMoeda(parcela.valor_pago) }}</td>
                <td class="px-4 py-4 text-sm font-semibold text-gray-900 text-right">{{ formatarMoeda(parcela.saldo) }}</td>
                <td class="px-4 py-4">
                  <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium', SITUACAO_BADGE[parcela.situacao] || 'bg-gray-100 text-gray-800']">
                    {{ SITUACAO_LABEL[parcela.situacao] || parcela.situacao }}
                  </span>
                </td>
                <td class="px-4 py-4 text-right whitespace-nowrap">
                  <button
                    v-if="temSaldo(parcela) && pode('financeiro-baixar')"
                    type="button"
                    class="text-green-600 hover:text-green-900 text-sm font-medium mr-3"
                    @click="abrirBaixaUnica(parcela)"
                  >
                    Baixar
                  </button>
                  <button
                    v-if="temPagamento(parcela) && pode('financeiro-estornar')"
                    type="button"
                    class="text-red-600 hover:text-red-900 text-sm font-medium"
                    @click="abrirEstorno(parcela)"
                  >
                    Estornar
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="parcelas.length >= 300" class="px-4 py-3 bg-yellow-50 border-t border-yellow-200 text-sm text-yellow-800">
          Mostrando as 300 parcelas mais próximas do vencimento. Refine o período para ver as demais.
        </div>
      </Card>
    </div>

    <!-- Baixa: única (a partir do botão da linha) ou em lote (a partir da seleção) -->
    <BaixaDeParcelaModal
      :show="mostrarModalBaixa"
      :parcelas="parcelasParaBaixa"
      @close="mostrarModalBaixa = false"
      @sucesso="aoConcluirBaixa"
    />

    <!-- Estorno: ação separada, com motivo obrigatório -->
    <Modal :show="mostrarModalEstorno" @close="fecharEstorno">
      <template #icon>
        <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
        </svg>
      </template>
      <template #title>Estornar recebimento</template>
      <template #content>
        <p class="text-sm text-gray-700 mb-4">
          {{ parcelaEmEstorno?.cliente }} · {{ parcelaEmEstorno?.descricao }}. O valor recebido volta a ficar em
          aberto na parcela. Explique o motivo: é o que responde por esta reversão mais tarde.
        </p>
        <label class="block text-sm font-medium text-gray-700 mb-1">Motivo *</label>
        <textarea
          v-model="motivoEstorno"
          rows="3"
          maxlength="1000"
          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          :class="{ 'border-red-500': erroEstorno }"
          placeholder="Ex.: depósito estornado pelo banco, valor lançado na parcela errada."
        ></textarea>
        <p v-if="erroEstorno" class="mt-1 text-sm text-red-600">{{ erroEstorno }}</p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="estornando" @click="fecharEstorno">Cancelar</button>
        <button type="button" class="btn-danger ml-3" :disabled="estornando" @click="confirmarEstorno">
          {{ estornando ? 'Estornando...' : 'Confirmar estorno' }}
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import Alert from '@/Components/Alert.vue';
import Modal from '@/Components/Modal.vue';
import BaixaDeParcelaModal from '@/Components/BaixaDeParcelaModal.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { formatarData, diasAte } from '@/utils/formatDate';

const props = defineProps({
  filtros: { type: Object, required: true },
  parcelas: { type: Array, default: () => [] },
  indicadores: { type: Object, required: true },
  clientes: { type: Array, default: () => [] },
  categorias: { type: Array, default: () => [] },
});

const { pode } = usePermissoes();

const SITUACAO_LABEL = {
  aberta: 'Aberta',
  parcial: 'Parcial',
  paga: 'Paga',
  vencida: 'Vencida',
  cancelada: 'Cancelada',
};

const SITUACAO_BADGE = {
  aberta: 'bg-blue-100 text-blue-800',
  parcial: 'bg-yellow-100 text-yellow-800',
  paga: 'bg-green-100 text-green-800',
  vencida: 'bg-red-100 text-red-800',
  cancelada: 'bg-gray-100 text-gray-800',
};

const ORIGEM_LABEL = {
  avulso: 'Avulso',
  ordem_de_servico: 'Ordem de serviço',
  contrato: 'Contrato',
};

function formatarMoeda(valor) {
  const numero = typeof valor === 'number' ? valor : parseFloat(valor || 0);
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number.isFinite(numero) ? numero : 0);
}

function diasDeAtraso(parcela) {
  const dias = diasAte(parcela.vencimento);
  return dias === null ? '?' : Math.abs(dias);
}

// -----------------------------------------------------------------
// Filtros (situação, período, cliente e categoria vão para o backend;
// origem é um campo já calculado por parcela e por isso filtrado aqui,
// sem viagem ao servidor)
// -----------------------------------------------------------------

const filtros = reactive({
  situacao: props.filtros.situacao || '',
  de: props.filtros.de || '',
  ate: props.filtros.ate || '',
  client_id: props.filtros.client_id || '',
  chart_of_account_id: props.filtros.chart_of_account_id || '',
});

const origem = ref('');

function aplicarFiltros() {
  const params = {};

  Object.entries(filtros).forEach(([chave, valor]) => {
    if (valor !== '' && valor !== null) params[chave] = valor;
  });

  router.get('/contas-a-receber', params, { preserveState: true, preserveScroll: true, replace: true });
}

const parcelasFiltradas = computed(() => {
  if (!origem.value) return props.parcelas;

  return props.parcelas.filter((parcela) => parcela.origem === origem.value);
});

// -----------------------------------------------------------------
// Seleção múltipla, restrita ao mesmo cliente, para a baixa em lote
// -----------------------------------------------------------------

const selecionados = ref(new Set());

function temSaldo(parcela) {
  return parseFloat(parcela.saldo || 0) > 0.001;
}

function temPagamento(parcela) {
  return parseFloat(parcela.valor_pago || 0) > 0.001;
}

const clienteDaSelecaoId = computed(() => {
  if (selecionados.value.size === 0) return null;

  const primeiraId = selecionados.value.values().next().value;
  const primeira = props.parcelas.find((p) => p.id === primeiraId);

  return primeira?.client_id ?? null;
});

const clienteDaSelecao = computed(() => {
  const parcela = props.parcelas.find((p) => p.client_id === clienteDaSelecaoId.value);
  return parcela?.cliente || '';
});

function clienteBloqueado(parcela) {
  return clienteDaSelecaoId.value !== null && parcela.client_id !== clienteDaSelecaoId.value;
}

function podeSelecionar(parcela) {
  return temSaldo(parcela) && !clienteBloqueado(parcela);
}

function alternarSelecao(parcela) {
  if (!podeSelecionar(parcela)) return;

  const novo = new Set(selecionados.value);

  if (novo.has(parcela.id)) {
    novo.delete(parcela.id);
  } else {
    novo.add(parcela.id);
  }

  selecionados.value = novo;
}

function limparSelecao() {
  selecionados.value = new Set();
}

const totalSelecionado = computed(() => (
  props.parcelas
    .filter((p) => selecionados.value.has(p.id))
    .reduce((soma, p) => soma + parseFloat(p.saldo || 0), 0)
));

// -----------------------------------------------------------------
// Baixa: modal único reaproveitado para uma parcela ou para o lote
// -----------------------------------------------------------------

const mostrarModalBaixa = ref(false);
const parcelasParaBaixa = ref([]);

function abrirBaixaUnica(parcela) {
  parcelasParaBaixa.value = [parcela];
  mostrarModalBaixa.value = true;
}

function abrirBaixaEmLote() {
  if (selecionados.value.size === 0) return;

  parcelasParaBaixa.value = props.parcelas.filter((p) => selecionados.value.has(p.id));
  mostrarModalBaixa.value = true;
}

const mensagemResultado = ref(null);
const alertaChave = ref(0);

function aoConcluirBaixa({ sucesso, falhas, mantemAberto }) {
  mensagemResultado.value = {
    tipo: falhas > 0 ? 'warning' : 'success',
    titulo: falhas > 0 ? 'Baixa concluída com falhas' : 'Baixa registrada',
    detalhe: falhas > 0
      ? `${sucesso} parcela(s) baixada(s), ${falhas} não puderam ser processadas.`
      : `${sucesso} parcela(s) baixada(s) com sucesso.`,
  };
  alertaChave.value += 1;

  if (!mantemAberto) {
    mostrarModalBaixa.value = false;
  }

  limparSelecao();
  router.reload({ only: ['parcelas', 'indicadores'] });
}

// -----------------------------------------------------------------
// Estorno: ação separada, com motivo obrigatório e confirmação em modal
// -----------------------------------------------------------------

const mostrarModalEstorno = ref(false);
const parcelaEmEstorno = ref(null);
const motivoEstorno = ref('');
const erroEstorno = ref('');
const estornando = ref(false);

function abrirEstorno(parcela) {
  parcelaEmEstorno.value = parcela;
  motivoEstorno.value = '';
  erroEstorno.value = '';
  mostrarModalEstorno.value = true;
}

function fecharEstorno() {
  if (estornando.value) return;
  mostrarModalEstorno.value = false;
  parcelaEmEstorno.value = null;
}

async function confirmarEstorno() {
  const motivo = motivoEstorno.value.trim();

  if (motivo.length < 10) {
    erroEstorno.value = 'O motivo é obrigatório e precisa ter pelo menos 10 caracteres.';
    return;
  }

  estornando.value = true;
  erroEstorno.value = '';

  try {
    const resposta = await fetch(`/contas-a-receber/parcelas/${parcelaEmEstorno.value.id}/estornar`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        Accept: 'application/json',
      },
      body: JSON.stringify({ motivo }),
    });

    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) {
      erroEstorno.value = dados.message || Object.values(dados.errors ?? {}).flat()[0] || 'Não foi possível estornar.';
      return;
    }

    mensagemResultado.value = { tipo: 'success', titulo: 'Recebimento estornado', detalhe: dados.message };
    alertaChave.value += 1;

    mostrarModalEstorno.value = false;
    parcelaEmEstorno.value = null;
    router.reload({ only: ['parcelas', 'indicadores'] });
  } catch (erro) {
    erroEstorno.value = 'Não foi possível estornar. Tente novamente.';
  } finally {
    estornando.value = false;
  }
}
</script>
