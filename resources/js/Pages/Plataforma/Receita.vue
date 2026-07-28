<template>
  <PlataformaLayout>
    <template #header>
      <PageHeader
        title="Receita"
        description="Assinatura, receita recorrente e faturas em aberto, para saber onde prestar atenção." />
    </template>

    <div class="space-y-6">
      <!-- Cards de estatística -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
        <StatCard title="Assinaturas ativas" :value="assinaturas.ativa" color="green" />
        <StatCard title="Em atraso" :value="assinaturas.em_atraso" color="yellow" />
        <StatCard title="Suspensas" :value="assinaturas.suspensa" color="red" />
        <StatCard title="Receita recorrente mensal" :value="receitaRecorrenteFormatada" color="blue" />
        <StatCard
          title="Total em aberto"
          :value="valorTotalEmAbertoFormatado"
          :subtitle="`${faturasEmAberto.quantidade} fatura(s)`"
          color="purple" />
      </div>

      <!--
        Gráfico de receita recorrente nos últimos 12 meses: fora do escopo
        desta task de propósito. O backend (Task 7.8) manda
        `receitaRecorrenteMensal` como um número único, o valor de hoje, sem
        série histórica mensal — não existe endpoint que devolva os 12 meses
        anteriores. Construir esse gráfico aqui exigiria inventar dado no
        frontend, o que a task não autoriza. A receita recorrente atual já
        aparece no card acima; a série histórica fica para uma task futura
        que estenda `SubscriptionService` com um método equivalente a
        `cancelamentosPorMes()`, mas para receita.
      -->

      <!-- Gráfico de cancelamentos -->
      <Card>
        <h3 class="text-lg font-medium text-gray-900 mb-4">Cancelamentos nos últimos 6 meses</h3>
        <div class="h-64">
          <LineChart :data="cancelamentosPorMesChart" :options="chartOptions" />
        </div>
      </Card>

      <!-- Tabela de faturas em aberto -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Faturas em aberto</h3>
          <p class="text-sm text-gray-500 mt-1">
            Ordenadas por dias de atraso. Linha em vermelho está além da tolerância de
            {{ diasDeTolerancia }} {{ diasDeTolerancia === 1 ? 'dia' : 'dias' }}.
          </p>
        </div>

        <div v-if="faturasOrdenadas.length" class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Empresa
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Valor
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Vencimento
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Situação
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Atraso
                </th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr
                v-for="linha in faturasOrdenadas"
                :key="linha.id"
                :class="linha.alemDaTolerancia ? 'bg-red-50 hover:bg-red-100' : 'hover:bg-gray-50'">
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <Link
                    :href="route('plataforma.tenants.show', linha.company_id)"
                    class="font-medium text-gray-900 hover:text-green-600">
                    {{ linha.empresa }}
                  </Link>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  {{ formatarMoeda(linha.valor) }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  {{ formatarData(linha.vencimento) }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <span
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                    :class="CLASSES_SITUACAO_FATURA[linha.situacao] || CLASSES_SITUACAO_FATURA.aberta">
                    {{ ROTULOS_SITUACAO_FATURA[linha.situacao] || linha.situacao }}
                  </span>
                </td>
                <td
                  class="px-6 py-4 whitespace-nowrap text-sm"
                  :class="linha.alemDaTolerancia ? 'text-red-700 font-medium' : 'text-gray-900'">
                  {{ linha.diasAtraso > 0 ? `${linha.diasAtraso} dia(s)` : 'em dia' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p v-else class="px-6 py-4 text-sm text-gray-500">Nenhuma fatura em aberto.</p>
      </Card>
    </div>
  </PlataformaLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import { Chart as ChartJS, CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend } from 'chart.js';
import { Line } from 'vue-chartjs';
import PlataformaLayout from '@/Layouts/PlataformaLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import { formatarData, diasAte } from '@/utils/formatDate';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend);

const LineChart = Line;

const props = defineProps({
  assinaturas: {
    type: Object,
    required: true,
  },
  receitaRecorrenteMensal: {
    type: Number,
    required: true,
  },
  faturasEmAberto: {
    type: Object,
    required: true,
  },
  diasDeTolerancia: {
    type: Number,
    required: true,
  },
  cancelamentosPorMesChart: {
    type: Object,
    required: true,
  },
});

const ROTULOS_SITUACAO_FATURA = {
  aberta: 'Aberta',
  vencida: 'Vencida',
};

const CLASSES_SITUACAO_FATURA = {
  aberta: 'bg-yellow-100 text-yellow-800',
  vencida: 'bg-red-100 text-red-800',
};

const formatadorMoeda = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' });

function formatarMoeda(valor) {
  return formatadorMoeda.format(valor);
}

// Receita recorrente mensal é exibida exatamente como o backend manda, sem
// recálculo no frontend: `SubscriptionService::receitaRecorrenteMensal()` já
// aplica a regra de excluir o tenant interno e considerar só assinatura ativa.
const receitaRecorrenteFormatada = computed(() => formatarMoeda(props.receitaRecorrenteMensal));

// `InvoiceService::faturasEmAberto()` já manda o total pronto em `valor_total`
// (soma feita no banco): a tela usa esse número em vez de somar `lista` de
// novo no frontend, para não divergir do que o backend já calculou.
const valorTotalEmAbertoFormatado = computed(() => formatarMoeda(props.faturasEmAberto.valor_total));

// Dias de atraso são calculados aqui porque o backend manda só o vencimento
// (campo `date`, sem hora). `diasAte()` compara o vencimento com o dia de
// hoje no fuso do negócio (America/Sao_Paulo), nunca com `new Date()` puro:
// uma fatura vencida hoje não pode aparecer "1 dia atrasada" por causa do
// fuso do navegador.
const faturasOrdenadas = computed(() => {
  return [...props.faturasEmAberto.lista]
    .map((fatura) => {
      const dias = diasAte(fatura.vencimento);
      const diasAtraso = dias === null ? 0 : Math.max(0, -dias);

      return {
        ...fatura,
        diasAtraso,
        // Mesmo limiar de `InadimplenciaService::avaliar()`: vencida há
        // `diasDeTolerancia` dias ou mais é o ponto em que a régua suspende
        // o tenant, então é esse o ponto que a tela destaca em vermelho.
        alemDaTolerancia: diasAtraso >= props.diasDeTolerancia,
      };
    })
    // Mais atrasada primeiro: é a fatura que precisa de atenção agora.
    .sort((a, b) => b.diasAtraso - a.diasAtraso);
});

const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'bottom',
    },
  },
  scales: {
    y: {
      beginAtZero: true,
      ticks: {
        precision: 0,
      },
    },
  },
};
</script>
