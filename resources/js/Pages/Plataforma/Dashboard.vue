<template>
  <PlataformaLayout>
    <template #header>
      <PageHeader
        title="Visão geral"
        description="Números da plataforma, para saber onde prestar atenção sem abrir tenant por tenant." />
    </template>

    <div class="space-y-6">
      <!-- Cards de estatística -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          title="Total de tenants"
          :value="estatisticas.total"
          :subtitle="`${estatisticas.criados_ultimos_30_dias} criados nos últimos 30 dias`"
          color="blue" />
        <StatCard title="Ativos" :value="estatisticas.ativos" color="green" />
        <StatCard title="Suspensos" :value="estatisticas.suspensos" color="red" />
        <StatCard title="Em avaliação" :value="estatisticas.em_avaliacao" color="yellow" />
      </div>

      <!-- Gráfico de entradas por mês -->
      <Card>
        <h3 class="text-lg font-medium text-gray-900 mb-4">Novos tenants por mês</h3>
        <div class="h-64">
          <LineChart :data="entradasPorMesChart" :options="chartOptions" />
        </div>
      </Card>

      <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
        <!-- Sem acesso recente -->
        <Card padding="none">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Sem acesso recente</h3>
            <p class="text-sm text-gray-500 mt-1">Tenants sem acesso há mais de 30 dias</p>
          </div>
          <ul v-if="semAcessoRecente.length" class="divide-y divide-gray-200">
            <li v-for="tenant in semAcessoRecente" :key="tenant.id" class="px-6 py-3 flex items-center justify-between">
              <Link :href="`/plataforma/tenants/${tenant.id}/uso`" class="text-sm font-medium text-gray-900 hover:text-green-600">
                {{ tenant.name }}
              </Link>
              <span class="text-sm text-gray-500">
                {{ tenant.ultimo_acesso_em ? formatarDataHora(tenant.ultimo_acesso_em) : 'nunca acessou' }}
              </span>
            </li>
          </ul>
          <p v-else class="px-6 py-4 text-sm text-gray-500">Nenhum tenant nessa condição.</p>
        </Card>

        <!-- Avaliações perto do fim -->
        <Card padding="none">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Avaliação perto do fim</h3>
            <p class="text-sm text-gray-500 mt-1">Trial encerrando nos próximos 7 dias</p>
          </div>
          <ul v-if="avaliacoesProximasDoFim.length" class="divide-y divide-gray-200">
            <li v-for="tenant in avaliacoesProximasDoFim" :key="tenant.id" class="px-6 py-3 flex items-center justify-between">
              <Link :href="`/plataforma/tenants/${tenant.id}/uso`" class="text-sm font-medium text-gray-900 hover:text-green-600">
                {{ tenant.name }}
              </Link>
              <span class="text-sm text-gray-500">
                {{ formatarData(tenant.trial_ends_at) }} · faltam {{ diasAte(tenant.trial_ends_at) }} {{ diasAte(tenant.trial_ends_at) === 1 ? 'dia' : 'dias' }}
              </span>
            </li>
          </ul>
          <p v-else class="px-6 py-4 text-sm text-gray-500">Nenhum tenant nessa condição.</p>
        </Card>
      </div>

      <!-- Armazenamento total -->
      <Card>
        <div class="flex items-center justify-between">
          <div>
            <h3 class="text-lg font-medium text-gray-900">Armazenamento total</h3>
            <p class="text-sm text-gray-500 mt-1">Soma da apuração mais recente de cada tenant</p>
          </div>
          <p class="text-2xl font-semibold text-gray-900">{{ armazenamentoFormatado }}</p>
        </div>
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
import { formatarData, formatarDataHora, diasAte } from '@/utils/formatDate';

ChartJS.register(CategoryScale, LinearScale, PointElement, LineElement, Tooltip, Legend);

const LineChart = Line;

const props = defineProps({
  estatisticas: {
    type: Object,
    required: true,
  },
  entradasPorMesChart: {
    type: Object,
    required: true,
  },
  semAcessoRecente: {
    type: Array,
    required: true,
  },
  avaliacoesProximasDoFim: {
    type: Array,
    required: true,
  },
  armazenamentoTotalMb: {
    type: Number,
    required: true,
  },
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

// Acima de 1024 MB o número em MB fica difícil de ler de relance; a partir daí
// a soma passa a fazer mais sentido em GB, com uma casa decimal.
const armazenamentoFormatado = computed(() => {
  const mb = props.armazenamentoTotalMb;

  return mb > 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${mb} MB`;
});
</script>
