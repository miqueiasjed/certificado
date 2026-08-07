<template>
  <Card>
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h3 class="text-lg font-medium text-gray-900">Evolução no período</h3>
      <div class="inline-flex rounded-md border border-gray-300 text-sm">
        <button
          type="button"
          class="px-3 py-1.5 font-medium"
          :class="granularidade === 'semana' ? 'bg-green-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
          @click="granularidade = 'semana'">
          Por semana
        </button>
        <button
          type="button"
          class="border-l border-gray-300 px-3 py-1.5 font-medium"
          :class="granularidade === 'mes' ? 'bg-green-600 text-white' : 'bg-white text-gray-700 hover:bg-gray-50'"
          @click="granularidade = 'mes'">
          Por mês
        </button>
      </div>
    </div>

    <div v-if="periodosAgregados.length === 0" class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-500">
      Sem período apurado para este endereço.
    </div>

    <template v-else>
      <div class="mt-4 flex flex-wrap items-center gap-4 text-xs text-gray-600">
        <span class="inline-flex items-center gap-1.5">
          <span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: CORES_MONITORAMENTO.captura }"></span>
          Capturas registradas no período
        </span>
        <span class="inline-flex items-center gap-1.5">
          <span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: CORES_MONITORAMENTO.semVisita }"></span>
          Sem visita (nenhum dispositivo teve evento registrado - não é zero captura)
        </span>
      </div>

      <div class="mt-3 h-64">
        <Bar :data="dadosDoGrafico" :options="opcoesDoGrafico" />
      </div>

      <div class="mt-4 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
          <thead>
            <tr>
              <th class="px-3 py-2 text-left font-medium text-gray-500">Período</th>
              <th class="px-3 py-2 text-center font-medium text-gray-500">Visitas</th>
              <th class="px-3 py-2 text-center font-medium text-gray-500">Capturas</th>
              <th class="px-3 py-2 text-left font-medium text-gray-500">Situação</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="periodo in periodosAgregados" :key="periodo.chave">
              <td class="px-3 py-2 text-gray-900">{{ periodo.chave }}</td>
              <td class="px-3 py-2 text-center text-gray-900">{{ periodo.visitas }}</td>
              <td class="px-3 py-2 text-center font-medium text-gray-900">
                {{ periodo.semVisita ? '-' : periodo.capturas }}
              </td>
              <td class="px-3 py-2">
                <span
                  class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="periodo.semVisita ? 'bg-gray-100 text-gray-600' : 'bg-green-100 text-green-800'">
                  {{ periodo.semVisita ? 'Sem visita no período' : 'Visitado' }}
                </span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </Card>
</template>

<script setup>
/**
 * Evolução de capturas de um único endereço, por semana ou por mês
 * (`evolucaoSemanal`/`evolucaoMensal`, exatamente o que
 * `TendenciaService::evolucaoPorDispositivo()` devolve). Agregação
 * (`agregarEvolucaoPorPeriodo`) soma todos os dispositivos por período, mesma
 * regra de `RelatorioPdfService::graficoEvolucaoMensal()`.
 *
 * "Sem visita" nunca vira barra de valor zero
 * -----------------------------------------------------------------------
 * Regra de negócio inegociável da Task 21.8: um período sem visita é
 * distinto de zero captura, sempre marcado. A barra do Chart.js não pode
 * desenhar `null`/`0` como "altura zero igual a zero captura" sem
 * distinção, então o período sem visita ganha uma altura fixa pequena,
 * sempre cinza (nunca a cor de captura real), com o tooltip e a linha da
 * tabela dizendo "Sem visita" em vez de um número. A tabela abaixo do
 * gráfico é a fonte exata: mostra "-" (não "0") na coluna de capturas do
 * período sem visita.
 */
import { computed, ref } from 'vue';
import { Chart as ChartJS, BarElement, CategoryScale, LinearScale, Tooltip, Legend } from 'chart.js';
import { Bar } from 'vue-chartjs';
import Card from '@/Components/Card.vue';
import { CORES_MONITORAMENTO } from '@/utils/paletaMonitoramento';
import { agregarEvolucaoPorPeriodo } from '@/utils/monitoramento';

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip, Legend);

const props = defineProps({
  evolucaoSemanal: { type: Object, default: null },
  evolucaoMensal: { type: Object, default: null },
});

const granularidade = ref('mes');

const evolucaoAtual = computed(() => (granularidade.value === 'mes' ? props.evolucaoMensal : props.evolucaoSemanal));

const periodosAgregados = computed(() => agregarEvolucaoPorPeriodo(evolucaoAtual.value));

const maximoCapturas = computed(() => {
  const valores = periodosAgregados.value.filter((p) => !p.semVisita).map((p) => p.capturas);
  return valores.length > 0 ? Math.max(...valores) : 0;
});

// Altura visível (não-zero) só para a barra "sem visita" não desaparecer no
// eixo - nunca representa uma quantidade real, ver o docblock acima.
const alturaSemVisita = computed(() => Math.max(maximoCapturas.value * 0.12, 0.4));

const dadosDoGrafico = computed(() => ({
  labels: periodosAgregados.value.map((p) => p.chave),
  datasets: [
    {
      label: 'Capturas',
      data: periodosAgregados.value.map((p) => (p.semVisita ? alturaSemVisita.value : p.capturas)),
      backgroundColor: periodosAgregados.value.map((p) =>
        p.semVisita ? CORES_MONITORAMENTO.semVisita : CORES_MONITORAMENTO.captura
      ),
      borderRadius: 4,
      maxBarThickness: 48,
    },
  ],
}));

const opcoesDoGrafico = computed(() => ({
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: { display: false },
    tooltip: {
      callbacks: {
        label: (contexto) => {
          const periodo = periodosAgregados.value[contexto.dataIndex];
          return periodo?.semVisita ? 'Sem visita no período' : `${periodo?.capturas ?? 0} captura(s)`;
        },
      },
    },
  },
  scales: {
    y: { beginAtZero: true, ticks: { precision: 0 } },
  },
}));
</script>
