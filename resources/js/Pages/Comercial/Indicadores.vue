<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Painel comercial"
        description="Orçamentos enviados, taxa de conversão, ticket médio e tempo até o fechamento"
      />
    </template>

    <div class="max-w-6xl mx-auto space-y-6">
      <!-- Período -->
      <Card>
        <form class="flex flex-col gap-3 sm:flex-row sm:items-end sm:flex-wrap" @submit.prevent="aplicarFiltro">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">De</label>
            <input
              v-model="filtroDe"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Até</label>
            <input
              v-model="filtroAte"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>
          <button type="submit" class="btn-primary">Filtrar</button>
        </form>
      </Card>

      <!-- Os quatro indicadores gerais -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard title="Orçamentos enviados" :value="geral.enviados" color="blue" />
        <StatCard
          title="Taxa de conversão"
          :value="formatarConversaoValor(geral)"
          :subtitle="formatarConversaoDetalhe(geral)"
          color="green"
        />
        <StatCard
          title="Ticket médio"
          :value="formatarMoeda(geral.ticket_medio)"
          :subtitle="`${geral.amostra_ticket_medio} orçamento(s) aprovado(s)`"
          color="purple"
        />
        <StatCard
          title="Tempo médio até o fechamento"
          :value="formatarTempo(geral)"
          :subtitle="`${geral.amostra_tempo_fechamento} orçamento(s) considerado(s)`"
          color="yellow"
        />
      </div>

      <!-- Evolução mês a mês -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <Card>
          <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
            <h3 class="text-lg font-medium text-gray-900">Enviados x aprovados por mês</h3>
            <div class="flex items-center gap-3 text-xs text-gray-600">
              <span class="flex items-center gap-1.5">
                <span class="inline-block w-3 h-3 rounded-sm bg-green-600"></span> Enviados
              </span>
              <span class="flex items-center gap-1.5">
                <span class="inline-block w-3 h-3 rounded-sm bg-blue-500"></span> Aprovados
              </span>
            </div>
          </div>
          <div v-if="evolucao_mensal.length === 0" class="h-64 flex items-center justify-center text-sm text-gray-500">
            Sem orçamento no período.
          </div>
          <div v-else class="h-64">
            <Bar :data="dadosBarras" :options="opcoesBarras" />
          </div>
        </Card>

        <Card>
          <h3 class="text-lg font-medium text-gray-900 mb-4">Taxa de conversão por mês</h3>
          <div v-if="evolucao_mensal.length === 0" class="h-64 flex items-center justify-center text-sm text-gray-500">
            Sem orçamento no período.
          </div>
          <div v-else class="h-64">
            <Line :data="dadosConversao" :options="opcoesConversao" />
          </div>
          <p class="mt-3 text-xs text-gray-500">
            Mês com menos de {{ minimo_de_orcamentos_para_conversao }} orçamentos não entra na linha, por amostra
            insuficiente. A contagem exata de cada mês está na tabela abaixo.
          </p>
        </Card>
      </div>

      <!-- Tabela de detalhamento mensal: fonte exata por trás dos dois gráficos -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Detalhamento mensal</h3>
        </div>
        <div v-if="evolucao_mensal.length === 0" class="p-6 text-sm text-gray-500">Sem orçamento no período.</div>
        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mês</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Enviados</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Aprovados</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Conversão</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ticket médio</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Tempo até fechar</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="mes in evolucao_mensal" :key="mes.periodo" class="hover:bg-gray-50">
                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">{{ mes.rotulo }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-700">{{ mes.enviados }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-700">{{ mes.aprovados }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-700">{{ formatarConversaoTabela(mes) }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-700">{{ formatarMoeda(mes.ticket_medio) }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-700">{{ formatarTempo(mes) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>

      <!-- Tabela por vendedor -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Por vendedor</h3>
          <p class="text-sm text-gray-500 mt-0.5">
            Vendedor com menos de {{ minimo_de_orcamentos_para_conversao }} orçamentos no período mostra a contagem
            no lugar da taxa.
          </p>
        </div>
        <div v-if="por_pessoa.length === 0" class="p-6 text-sm text-gray-500">
          Nenhum orçamento com vendedor atribuído no período.
        </div>
        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vendedor</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Enviados</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Aprovados</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Conversão</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ticket médio</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Tempo até fechar</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="pessoa in por_pessoa" :key="pessoa.user_id" class="hover:bg-gray-50">
                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">{{ pessoa.usuario || `Usuário #${pessoa.user_id}` }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-700">{{ pessoa.enviados }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-700">{{ pessoa.aprovados }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-700">{{ formatarConversaoTabela(pessoa) }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-700">{{ formatarMoeda(pessoa.ticket_medio) }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-700">{{ formatarTempo(pessoa) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
/**
 * Painel comercial (Plano 23, Task 23.8): consome os indicadores já apurados
 * por `IndicadoresComerciaisController::index()` (Task 23.6) e
 * `IndicadoresComerciaisService` (Task 23.3). Nada é recalculado aqui além de
 * formatação — a taxa de conversão, o ticket médio e o tempo de fechamento já
 * chegam prontos, inclusive a decisão de omitir a taxa por amostra pequena
 * (`conversao_omitida`).
 *
 * Paleta do gráfico: verde (`#059669`) e azul (`#3b82f6`), a mesma dupla já
 * usada em `Financeiro/Previsao.vue` e `Monitoring/SecaoEvolucao.vue` para
 * "principal" e "secundário" — reaproveitar o par já validado no próprio
 * projeto em vez de introduzir uma paleta categórica nova para um painel
 * interno de duas séries.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  BarElement,
  PointElement,
  LineElement,
  Tooltip,
  Legend,
} from 'chart.js';
import { Bar, Line } from 'vue-chartjs';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';

ChartJS.register(CategoryScale, LinearScale, BarElement, PointElement, LineElement, Tooltip, Legend);

const props = defineProps({
  periodo: { type: Object, required: true },
  minimo_de_orcamentos_para_conversao: { type: Number, required: true },
  geral: { type: Object, required: true },
  por_pessoa: { type: Array, default: () => [] },
  evolucao_mensal: { type: Array, default: () => [] },
});

// -----------------------------------------------------------------
// Filtro de período
// -----------------------------------------------------------------

const filtroDe = ref(props.periodo.de);
const filtroAte = ref(props.periodo.ate);

function aplicarFiltro() {
  router.get(
    route('comercial.indicadores'),
    { de: filtroDe.value, ate: filtroAte.value },
    { preserveState: true, preserveScroll: true, replace: true }
  );
}

// -----------------------------------------------------------------
// Formatação: toda porcentagem sempre acompanhada do número absoluto
// (Task 23.3), nunca sozinha nem escondida quando a amostra é pequena.
// -----------------------------------------------------------------

function formatarMoeda(valor) {
  if (valor === null || valor === undefined) return '-';

  return Number(valor).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function formatarTempo(indicador) {
  return indicador.tempo_medio_fechamento_dias !== null ? `${indicador.tempo_medio_fechamento_dias} dia(s)` : '-';
}

// Valor de destaque do StatCard: a taxa quando a amostra é suficiente, ou a
// contagem crua quando `conversao_omitida` é true — nunca "0%".
function formatarConversaoValor(indicador) {
  return indicador.conversao_omitida ? `${indicador.aprovados} de ${indicador.enviados}` : `${indicador.conversao}%`;
}

function formatarConversaoDetalhe(indicador) {
  return indicador.conversao_omitida
    ? `Amostra insuficiente (mínimo de ${props.minimo_de_orcamentos_para_conversao} orçamentos)`
    : `${indicador.aprovados} de ${indicador.enviados} enviados`;
}

// Para tabela: a porcentagem sempre com a contagem absoluta ao lado, entre
// parênteses, quando a amostra é suficiente.
function formatarConversaoTabela(indicador) {
  return indicador.conversao_omitida
    ? `${indicador.aprovados} de ${indicador.enviados} (amostra insuficiente)`
    : `${indicador.conversao}% (${indicador.aprovados} de ${indicador.enviados})`;
}

// -----------------------------------------------------------------
// Gráfico 1: enviados x aprovados por mês (mesma unidade, contagem)
// -----------------------------------------------------------------

const dadosBarras = computed(() => ({
  labels: props.evolucao_mensal.map((mes) => mes.rotulo),
  datasets: [
    {
      label: 'Enviados',
      data: props.evolucao_mensal.map((mes) => mes.enviados),
      backgroundColor: '#059669',
      borderRadius: 4,
      maxBarThickness: 36,
    },
    {
      label: 'Aprovados',
      data: props.evolucao_mensal.map((mes) => mes.aprovados),
      backgroundColor: '#3b82f6',
      borderRadius: 4,
      maxBarThickness: 36,
    },
  ],
}));

const opcoesBarras = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: { legend: { display: false } },
  scales: {
    y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#e5e7eb' } },
    x: { grid: { display: false } },
  },
};

// -----------------------------------------------------------------
// Gráfico 2: taxa de conversão por mês (percentual, eixo próprio - nunca
// combinado com o eixo de contagem do gráfico 1, regra de "um eixo só").
// Mês com amostra insuficiente vira um vão na linha (nunca 0%): a contagem
// exata de cada mês está sempre na tabela abaixo dos dois gráficos.
// -----------------------------------------------------------------

const dadosConversao = computed(() => ({
  labels: props.evolucao_mensal.map((mes) => mes.rotulo),
  datasets: [
    {
      label: 'Taxa de conversão',
      data: props.evolucao_mensal.map((mes) => (mes.conversao_omitida ? null : mes.conversao)),
      borderColor: '#059669',
      backgroundColor: '#059669',
      pointBackgroundColor: '#059669',
      pointBorderColor: '#ffffff',
      pointBorderWidth: 2,
      pointRadius: 4,
      borderWidth: 2,
      spanGaps: false,
      tension: 0.15,
    },
  ],
}));

const opcoesConversao = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (contexto) => {
          const mes = props.evolucao_mensal[contexto.dataIndex];
          if (!mes) return '';

          return mes.conversao_omitida
            ? `Amostra insuficiente (${mes.aprovados} de ${mes.enviados})`
            : `${mes.conversao}% (${mes.aprovados} de ${mes.enviados})`;
        },
      },
    },
  },
  scales: {
    y: {
      beginAtZero: true,
      max: 100,
      ticks: { callback: (valor) => `${valor}%` },
      grid: { color: '#e5e7eb' },
    },
    x: { grid: { display: false } },
  },
}));
</script>
