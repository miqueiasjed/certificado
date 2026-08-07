<template>
  <Head :title="`Relatório ${formatarData(relatorio.periodo_inicio)} a ${formatarData(relatorio.periodo_fim)}`" />

  <PortalLayout>
    <template #header>
      <PageHeader
        title="Relatório de Monitoramento"
        :description="`${formatarData(relatorio.periodo_inicio)} a ${formatarData(relatorio.periodo_fim)}`">
        <template #actions>
          <a
            :href="route('portal.relatorios.pdf', relatorio.id)"
            class="inline-flex items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium text-white shadow-sm transition-opacity hover:opacity-90"
            style="background-color: var(--portal-cor-primaria)">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
            </svg>
            Baixar PDF
          </a>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <Link :href="route('portal.relatorios.index')" class="inline-flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-gray-700">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        Voltar para relatórios
      </Link>

      <Card>
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <p class="text-sm font-semibold text-gray-900">{{ relatorio.endereco || 'Todos os endereços' }}</p>
            <p class="mt-1 text-sm text-gray-500">Gerado em {{ formatarData(relatorio.gerado_em) }}</p>
          </div>
          <div class="text-right">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Visitas no período</p>
            <p class="text-lg font-semibold text-gray-900">{{ dados?.visitas?.quantidade ?? 0 }}</p>
          </div>
        </div>
      </Card>

      <div v-if="porEndereco.length === 0" class="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">
        Nenhum dado apurado neste relatório.
      </div>

      <div v-for="endereco in porEndereco" :key="endereco.address_id" class="space-y-6">
        <h2 v-if="porEndereco.length > 1" class="text-lg font-semibold text-gray-900">{{ endereco.endereco }}</h2>

        <!-- Evolução (resumida): total de capturas por mês, sem detalhar dispositivo por dispositivo. -->
        <Card>
          <h3 class="text-base font-semibold text-gray-900">Evolução no período</h3>
          <div v-if="endereco.periodos.length === 0" class="mt-3 text-sm text-gray-500">
            Sem período apurado para este endereço.
          </div>
          <template v-else>
            <div class="mt-3 flex flex-wrap items-center gap-4 text-xs text-gray-600">
              <span class="inline-flex items-center gap-1.5">
                <span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: CORES_MONITORAMENTO.captura }"></span>
                Capturas registradas no mês
              </span>
              <span class="inline-flex items-center gap-1.5">
                <span class="inline-block h-2.5 w-2.5 rounded-sm" :style="{ backgroundColor: CORES_MONITORAMENTO.semVisita }"></span>
                Sem visita no mês
              </span>
            </div>
            <div class="mt-3 h-48">
              <Bar :data="dadosDoGrafico(endereco)" :options="opcoesDoGrafico(endereco)" />
            </div>
          </template>
        </Card>

        <!-- Pontos críticos (resumido): sem código de dispositivo nem número técnico de média. -->
        <Card>
          <h3 class="text-base font-semibold text-gray-900">Pontos com mais ocorrência</h3>
          <div v-if="!endereco.ranking?.dispositivos?.length" class="mt-3 text-sm text-gray-500">
            Nenhum ponto com ocorrência registrada neste endereço no período.
          </div>
          <ul v-else class="mt-3 divide-y divide-gray-100">
            <li v-for="ponto in endereco.ranking.dispositivos.slice(0, 5)" :key="ponto.device_id" class="flex items-center justify-between gap-3 py-2.5">
              <div class="min-w-0">
                <p class="truncate text-sm font-medium text-gray-900">{{ ponto.rotulo }}</p>
                <p class="text-xs text-gray-500">{{ ponto.capturas_total }} {{ ponto.capturas_total === 1 ? 'ocorrência' : 'ocorrências' }} no período</p>
              </div>
              <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium" :class="classeTendencia(ponto.tendencia.estado)">
                {{ rotuloTendencia(ponto.tendencia.estado) }}
              </span>
            </li>
          </ul>
        </Card>
      </div>

      <!-- Adequações em aberto: mesmo componente da tela interna - já é genérico
           e não carrega custo, observação interna nem dado de técnico. -->
      <SecaoAdequacoes :adequacoes="dados?.adequacoes ?? []" />
    </div>
  </PortalLayout>
</template>

<script setup>
/**
 * Leitura resumida do relatório publicado, em linguagem de cliente final
 * (Plano 21, Task 21.8): evolução, pontos com mais ocorrência e adequações
 * em aberto - só as três seções que a task pede para o portal, sem o mapa de
 * calor nem a tabela completa de espécies da tela interna (granularidade
 * técnica demais para um "resumo").
 *
 * `relatorio.dados` já vem filtrado por `PortalRelatorioController::paraPortal()`
 * (hoje um pass-through documentado - nenhuma das chaves carrega custo,
 * observação interna ou dado pessoal de técnico além do nome, ver o
 * docblock do controller). Este componente não teve acesso a nenhum campo
 * novo além do que a tela interna já usa; a diferença aqui é só de
 * apresentação (sem código de dispositivo, sem tabela técnica completa),
 * nunca de dado exposto a mais.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import { Chart as ChartJS, BarElement, CategoryScale, LinearScale, Tooltip } from 'chart.js';
import { Bar } from 'vue-chartjs';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import SecaoAdequacoes from '@/Components/Monitoring/SecaoAdequacoes.vue';
import { formatarData } from '@/utils/formatDate';
import { montarPorEndereco, agregarEvolucaoPorPeriodo } from '@/utils/monitoramento';
import { CORES_MONITORAMENTO, rotuloTendencia, classeTendencia } from '@/utils/paletaMonitoramento';

ChartJS.register(BarElement, CategoryScale, LinearScale, Tooltip);

const props = defineProps({
  relatorio: { type: Object, required: true },
});

const dados = computed(() => props.relatorio.dados ?? {});

const porEndereco = computed(() =>
  montarPorEndereco(dados.value).map((endereco) => ({
    ...endereco,
    periodos: agregarEvolucaoPorPeriodo(endereco.evolucaoMensal),
  }))
);

function dadosDoGrafico(endereco) {
  return {
    labels: endereco.periodos.map((p) => p.chave),
    datasets: [
      {
        label: 'Capturas',
        data: endereco.periodos.map((p) => (p.semVisita ? Math.max(...endereco.periodos.map((x) => x.capturas), 1) * 0.1 : p.capturas)),
        backgroundColor: endereco.periodos.map((p) => (p.semVisita ? CORES_MONITORAMENTO.semVisita : CORES_MONITORAMENTO.captura)),
        borderRadius: 4,
        maxBarThickness: 40,
      },
    ],
  };
}

function opcoesDoGrafico(endereco) {
  return {
    responsive: true,
    maintainAspectRatio: false,
    plugins: {
      legend: { display: false },
      tooltip: {
        callbacks: {
          label: (contexto) => {
            const periodo = endereco.periodos[contexto.dataIndex];
            return periodo?.semVisita ? 'Sem visita no mês' : `${periodo?.capturas ?? 0} captura(s)`;
          },
        },
      },
    },
    scales: {
      y: { beginAtZero: true, ticks: { precision: 0 } },
    },
  };
}
</script>
