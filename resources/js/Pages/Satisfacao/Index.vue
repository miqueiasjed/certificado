<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Satisfação"
        description="Indicadores das pesquisas respondidas depois da visita, por período, técnico e tipo de serviço."
      />
    </template>

    <div class="max-w-6xl mx-auto space-y-6">
      <div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!-- Filtro de período -->
      <Card padding="small">
        <form @submit.prevent="aplicarFiltro" class="flex flex-col sm:flex-row sm:items-end gap-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">De</label>
            <input v-model="filtro.de" type="date" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Até</label>
            <input v-model="filtro.ate" type="date" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500" />
          </div>
          <button type="submit" class="btn-primary">Filtrar</button>
        </form>
      </Card>

      <!-- Média geral e contagem -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatCard
          title="Média geral"
          :value="valorDaMedia(indicadores.geral)"
          :subtitle="`${indicadores.geral.respostas} resposta(s) no período`"
          color="green"
        />
        <StatCard
          title="Respostas no período"
          :value="indicadores.geral.respostas"
          color="blue"
        />
        <StatCard
          title="Pendências de contato"
          :value="pendenciasDeContato.length"
          subtitle="Notas 1 e 2 ainda sem retorno da empresa"
          color="red"
        />
      </div>

      <!-- Evolução por mês -->
      <Card>
        <h3 class="text-lg font-medium text-gray-900 mb-4">Evolução por mês</h3>

        <div v-if="indicadores.por_periodo.length === 0" class="text-sm text-gray-500">
          Nenhuma resposta no período selecionado.
        </div>

        <div v-else class="space-y-2">
          <div v-for="linha in indicadores.por_periodo" :key="linha.periodo" class="flex items-center gap-3">
            <span class="w-16 shrink-0 text-sm text-gray-600">{{ linha.rotulo }}</span>
            <div class="flex-1 bg-gray-100 rounded-full h-3 overflow-hidden">
              <div
                class="h-3 bg-green-500 rounded-full"
                :style="{ width: larguraDaBarra(linha) }"
              ></div>
            </div>
            <span class="w-36 shrink-0 text-sm text-right text-gray-700">{{ valorDaMedia(linha) }}</span>
            <span class="w-20 shrink-0 text-xs text-right text-gray-400">{{ linha.respostas }} resp.</span>
          </div>
        </div>
      </Card>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Por técnico -->
        <Card padding="none">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Por técnico</h3>
          </div>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Técnico</th>
                  <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Respostas</th>
                  <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Média</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr v-if="indicadores.por_tecnico.length === 0">
                  <td colspan="3" class="px-4 py-4 text-sm text-gray-500 text-center">Sem respostas no período.</td>
                </tr>
                <tr v-for="linha in indicadores.por_tecnico" :key="linha.technician_id ?? 'sem-tecnico'">
                  <td class="px-4 py-3 text-sm text-gray-900">{{ linha.tecnico }}</td>
                  <td class="px-4 py-3 text-sm text-right text-gray-700">{{ linha.respostas }}</td>
                  <td class="px-4 py-3 text-sm text-right" :class="linha.media_omitida ? 'text-gray-400 italic' : 'text-gray-900 font-medium'">
                    {{ valorDaMedia(linha) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </Card>

        <!-- Por tipo de serviço -->
        <Card padding="none">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Por tipo de serviço</h3>
          </div>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Serviço</th>
                  <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Respostas</th>
                  <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Média</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr v-if="indicadores.por_tipo_de_servico.length === 0">
                  <td colspan="3" class="px-4 py-4 text-sm text-gray-500 text-center">Sem respostas no período.</td>
                </tr>
                <tr v-for="linha in indicadores.por_tipo_de_servico" :key="linha.service_type_id ?? 'sem-tipo'">
                  <td class="px-4 py-3 text-sm text-gray-900">{{ linha.tipo_de_servico }}</td>
                  <td class="px-4 py-3 text-sm text-right text-gray-700">{{ linha.respostas }}</td>
                  <td class="px-4 py-3 text-sm text-right" :class="linha.media_omitida ? 'text-gray-400 italic' : 'text-gray-900 font-medium'">
                    {{ valorDaMedia(linha) }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </Card>
      </div>

      <!-- Pendências de contato -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Notas baixas com contato pendente</h3>
          <p class="text-sm text-gray-500 mt-1">Nota 1 ou 2, ainda sem retorno da empresa ao cliente.</p>
        </div>

        <div v-if="pendenciasDeContato.length === 0" class="p-8 text-center text-sm text-gray-500">
          Nenhuma pendência de contato aberta.
        </div>

        <ul v-else class="divide-y divide-gray-200">
          <li v-for="pesquisa in pendenciasDeContato" :key="pesquisa.id" class="p-4 flex flex-col sm:flex-row sm:items-start gap-3">
            <span
              class="shrink-0 inline-flex items-center justify-center h-9 w-9 rounded-full text-sm font-semibold"
              :class="pesquisa.nota === 1 ? 'bg-red-100 text-red-800' : 'bg-yellow-100 text-yellow-800'"
            >
              {{ pesquisa.nota }}
            </span>

            <div class="flex-1 min-w-0">
              <p class="text-sm font-medium text-gray-900">{{ pesquisa.client?.name || 'Cliente removido' }}</p>
              <p class="text-xs text-gray-500">
                Técnico: {{ pesquisa.technician?.name || 'não informado' }}
                <template v-if="pesquisa.serviceType?.name"> · Serviço: {{ pesquisa.serviceType.name }}</template>
                <template v-if="pesquisa.workOrder?.order_number"> · OS {{ pesquisa.workOrder.order_number }}</template>
              </p>
              <p class="text-xs text-gray-400 mt-1">Respondido em {{ formatarDataHora(pesquisa.respondida_em) }}</p>
              <p v-if="pesquisa.comentario" class="text-sm text-gray-700 mt-2 italic">"{{ pesquisa.comentario }}"</p>
              <p v-else class="text-sm text-gray-400 mt-2 italic">O cliente não deixou comentário.</p>
            </div>

            <div class="shrink-0">
              <button type="button" class="btn-secondary-sm" @click="marcarContatoFeito(pesquisa)">
                Marcar contato feito
              </button>
            </div>
          </li>
        </ul>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import { formatarDataHora, paraInputDate } from '@/utils/formatDate';

const props = defineProps({
  indicadores: Object,
  pendenciasDeContato: Array,
  filtro: Object,
});

const filtro = ref({
  de: props.filtro?.de || paraInputDate(props.indicadores.periodo.de),
  ate: props.filtro?.ate || paraInputDate(props.indicadores.periodo.ate),
});

const aplicarFiltro = () => {
  router.get(
    route('satisfacao.index'),
    { de: filtro.value.de || undefined, ate: filtro.value.ate || undefined },
    { preserveState: true, replace: true }
  );
};

// Corte com menos de `minimo_de_respostas` respostas não mostra média: nota
// isolada de um técnico só vira injustiça, mesma regra do backend
// (SatisfactionSurveyService::corte()).
const valorDaMedia = (corte) => {
  if (corte.media_omitida) {
    return `${props.indicadores.minimo_de_respostas} respostas necessárias`;
  }

  return corte.media.toFixed(2).replace('.', ',');
};

const larguraDaBarra = (linha) => {
  if (linha.media_omitida || !linha.media) {
    return '4px';
  }

  // Nota máxima é 5: a barra ocupa a fração da média em relação ao teto.
  return `${Math.max(4, Math.round((linha.media / 5) * 100))}%`;
};

const marcarContatoFeito = (pesquisa) => {
  router.post(
    route('satisfacao.contato-feito', pesquisa.id),
    {},
    { preserveScroll: true }
  );
};
</script>
