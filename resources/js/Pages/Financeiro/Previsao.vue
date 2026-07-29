<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Previsão de Caixa" description="Quanto entra e sai nos próximos meses, a partir do que já está em aberto hoje.">
        <template #actions>
          <div class="flex items-center gap-2">
            <label for="horizonte" class="text-sm font-medium text-gray-700">Horizonte</label>
            <select
              id="horizonte"
              v-model="horizonteSelecionado"
              @change="aplicarHorizonte"
              class="px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option v-for="opcao in horizontes" :key="opcao" :value="opcao">{{ opcao }} meses</option>
            </select>
          </div>
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

      <!-- Aviso de saldo previsto negativo: a informação que justifica a tela existir -->
      <div v-if="primeiroMesNegativo" class="bg-red-50 border border-red-300 rounded-md p-4 flex items-start gap-3">
        <svg class="h-5 w-5 text-red-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
        </svg>
        <div>
          <p class="text-sm font-semibold text-red-800">
            O saldo de caixa previsto fica negativo em {{ formatarCompetencia(primeiroMesNegativo.competencia) }}.
          </p>
          <p class="text-sm text-red-700 mt-1">
            Saldo acumulado projetado: {{ formatarMoeda(primeiroMesNegativo.saldo_acumulado_previsto) }}. Os meses em vermelho
            na tabela abaixo são os que ficam com o caixa previsto negativo neste horizonte.
          </p>
        </div>
      </div>

      <!-- Realizado x Previsto -->
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <StatCard
          title="Saldo de caixa atual (realizado)"
          :value="formatarMoeda(previsao.realizado.saldo_de_caixa_atual)"
          :subtitle="`Referência ${formatarData(previsao.realizado.referencia)}`"
          color="green"
        />
        <StatCard
          title="Saldo previsto ao final do horizonte"
          :value="formatarMoeda(ultimoMes?.saldo_acumulado_previsto ?? previsao.realizado.saldo_de_caixa_atual)"
          :subtitle="ultimoMes ? `Competência ${formatarCompetencia(ultimoMes.competencia)}` : ''"
          :color="saldoFinalNegativo ? 'red' : 'blue'"
        />
      </div>

      <!-- Gráfico de evolução do saldo -->
      <Card>
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-medium text-gray-900">Evolução do saldo</h3>
          <div class="flex items-center gap-4 text-xs text-gray-600">
            <span class="flex items-center gap-1.5">
              <span class="inline-block w-3 h-0.5 bg-green-600"></span> Realizado
            </span>
            <span class="flex items-center gap-1.5">
              <span class="inline-block w-3 h-0.5 border-t-2 border-dashed border-blue-500"></span> Previsto
            </span>
          </div>
        </div>
        <div class="h-72">
          <Line :data="dadosDoGrafico" :options="opcoesDoGrafico" />
        </div>
        <p class="mt-3 text-xs text-gray-500">
          Realizado é o saldo de caixa já fechado até hoje. Previsto é a expectativa por competência, a partir das
          parcelas a receber e a pagar que ainda estão em aberto: pode não se confirmar exatamente assim.
        </p>
      </Card>

      <!-- Tabela por mês -->
      <Card padding="none">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Competência</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">A receber</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">A pagar</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Resultado</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Saldo acumulado previsto</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr
                v-for="mes in previsao.previsto.meses"
                :key="mes.competencia"
                :class="Number(mes.saldo_acumulado_previsto) < 0 ? 'bg-red-50' : 'hover:bg-gray-50'"
              >
                <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900 capitalize">
                  {{ formatarCompetencia(mes.competencia) }}
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-700">{{ formatarMoeda(mes.a_receber) }}</td>
                <td class="px-4 py-3 whitespace-nowrap text-sm text-right text-gray-700">{{ formatarMoeda(mes.a_pagar) }}</td>
                <td
                  class="px-4 py-3 whitespace-nowrap text-sm text-right font-medium"
                  :class="Number(mes.resultado) < 0 ? 'text-red-600' : 'text-gray-900'"
                >
                  {{ formatarMoeda(mes.resultado) }}
                </td>
                <td
                  class="px-4 py-3 whitespace-nowrap text-sm text-right font-semibold"
                  :class="Number(mes.saldo_acumulado_previsto) < 0 ? 'text-red-700' : 'text-gray-900'"
                >
                  {{ formatarMoeda(mes.saldo_acumulado_previsto) }}
                  <span
                    v-if="Number(mes.saldo_acumulado_previsto) < 0"
                    class="ml-2 inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800"
                  >
                    Negativo
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router } from '@inertiajs/vue3';
import {
  Chart as ChartJS,
  CategoryScale,
  LinearScale,
  PointElement,
  LineElement,
  Tooltip,
  Legend,
} from 'chart.js';
import { Line } from 'vue-chartjs';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import { formatarData, formatarDataExtensa } from '@/utils/formatDate';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend);

const props = defineProps({
  filtros: Object,
  horizontes: Array,
  previsao: Object,
});

const formatarMoeda = (valor) => {
  if (valor === null || valor === undefined) return '-';

  return Number(valor).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
};

// "2026-07" -> "julho de 2026". Reaproveita `formatarDataExtensa` (dia 1 do
// mês, tratado como texto, nunca como `Date`) e só remove o "1 de " inicial,
// em vez de reimplementar os nomes dos meses aqui.
const formatarCompetencia = (competencia) => {
  const porExtenso = formatarDataExtensa(`${competencia}-01`);

  return porExtenso.replace(/^\d{1,2} de /, '');
};

// Versão curta para o eixo do gráfico ("jul/26"), a partir do mesmo texto por
// extenso, sem tocar em fuso horário: é só recorte de string.
const formatarCompetenciaCurta = (competencia) => {
  const [mes, , ano] = formatarCompetencia(competencia).split(' ');

  return `${mes.slice(0, 3)}/${ano.slice(2)}`;
};

// -----------------------------------------------------------------
// Seletor de horizonte (3, 6 ou 12 meses)
// -----------------------------------------------------------------

const horizonteSelecionado = ref(props.filtros.meses);

const aplicarHorizonte = () => {
  router.get(
    route('financeiro.previsao'),
    { meses: horizonteSelecionado.value },
    { preserveState: true, replace: true }
  );
};

// -----------------------------------------------------------------
// Leituras derivadas da previsão
// -----------------------------------------------------------------

const ultimoMes = computed(() => {
  const meses = props.previsao.previsto.meses;

  return meses.length ? meses[meses.length - 1] : null;
});

const saldoFinalNegativo = computed(() => Number(ultimoMes.value?.saldo_acumulado_previsto ?? 0) < 0);

// Primeiro mês em que o saldo acumulado previsto vira negativo: é o dado que
// justifica esta tela existir (Task 18.9).
const primeiroMesNegativo = computed(
  () => props.previsao.previsto.meses.find((mes) => Number(mes.saldo_acumulado_previsto) < 0) || null
);

// -----------------------------------------------------------------
// Gráfico de evolução do saldo: "Realizado" (hoje, sólido) e "Previsto"
// (tracejado), nunca misturados no mesmo traço. Os dois datasets partilham o
// primeiro ponto (hoje) só para a linha nascer conectada; o próprio estilo
// (cor e traço tracejado) e a legenda deixam claro qual é qual.
// -----------------------------------------------------------------

const dadosDoGrafico = computed(() => {
  const meses = props.previsao.previsto.meses;
  const saldoAtual = Number(props.previsao.realizado.saldo_de_caixa_atual);

  const rotulos = ['Hoje', ...meses.map((mes) => formatarCompetenciaCurta(mes.competencia))];
  const pontosPrevisto = [saldoAtual, ...meses.map((mes) => Number(mes.saldo_acumulado_previsto))];
  const pontosRealizado = [saldoAtual, ...meses.map(() => null)];

  return {
    labels: rotulos,
    datasets: [
      {
        label: 'Realizado',
        data: pontosRealizado,
        borderColor: '#059669',
        backgroundColor: '#059669',
        pointBackgroundColor: '#059669',
        pointBorderColor: '#ffffff',
        pointBorderWidth: 2,
        pointRadius: 6,
        borderWidth: 2,
        spanGaps: false,
      },
      {
        label: 'Previsto',
        data: pontosPrevisto,
        borderColor: '#3b82f6',
        backgroundColor: 'transparent',
        pointBackgroundColor: '#ffffff',
        pointBorderColor: '#3b82f6',
        pointBorderWidth: 2,
        pointRadius: 4,
        borderWidth: 2,
        borderDash: [6, 4],
        tension: 0,
      },
    ],
  };
});

const opcoesDoGrafico = {
  responsive: true,
  maintainAspectRatio: false,
  interaction: { mode: 'index', intersect: false },
  plugins: {
    legend: { position: 'bottom' },
    tooltip: {
      callbacks: {
        label: (contexto) => {
          if (contexto.parsed.y === null) return null;

          return `${contexto.dataset.label}: ${formatarMoeda(contexto.parsed.y)}`;
        },
      },
    },
  },
  scales: {
    y: {
      ticks: {
        callback: (valor) => Number(valor).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 0 }),
      },
      grid: { color: '#e5e7eb' },
    },
    x: {
      grid: { display: false },
    },
  },
};
</script>
