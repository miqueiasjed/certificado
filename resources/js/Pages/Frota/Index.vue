<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Frota"
        description="Veículos, consumo médio e o que está perto de vencer."
      >
        <template #actions>
          <button type="button" class="btn-primary" @click="abrirCadastro()">Novo veículo</button>
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

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatCard
          title="Veículos ativos"
          :value="indicadores.ativos"
          color="green"
          subtitle="Em operação hoje"
        />
        <StatCard
          title="Manutenções a vencer"
          :value="indicadores.manutencoes_a_vencer"
          color="yellow"
          subtitle="Por data ou por quilometragem"
        />
        <StatCard
          title="Documentos vencendo"
          :value="indicadores.documentos_a_vencer"
          color="red"
          subtitle="Validade nos próximos 60 dias"
        />
      </div>

      <Card padding="small">
        <div class="flex flex-col sm:flex-row sm:items-end gap-4">
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Situação</label>
            <select
              v-model="filtroSituacao"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              @change="aplicarFiltros"
            >
              <option value="">Todas</option>
              <option v-for="situacao in situacoes" :key="situacao" :value="situacao">
                {{ rotuloDeSituacao(situacao) }}
              </option>
            </select>
          </div>
          <div>
            <button type="button" class="btn-secondary w-full sm:w-auto" @click="limparFiltros">
              Limpar filtros
            </button>
          </div>
        </div>
      </Card>

      <Card padding="none">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Placa</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Veículo</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Técnico</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Hodômetro</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Consumo</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Custo/km</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Alertas</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Situação</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="linha in veiculos" :key="linha.veiculo.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                  <Link :href="route('frota.show', linha.veiculo.id)" class="text-green-700 hover:text-green-800">
                    {{ linha.veiculo.placa }}
                  </Link>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  {{ linha.veiculo.marca }} {{ linha.veiculo.modelo }}
                  <span v-if="linha.veiculo.ano" class="text-gray-500">({{ linha.veiculo.ano }})</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                  {{ linha.veiculo.technician?.name || 'Sem responsável' }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                  {{ numero(linha.veiculo.km_atual) }} km
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                  <span v-if="linha.km_por_litro !== null">{{ decimal(linha.km_por_litro, 2) }} km/l</span>
                  <span v-else class="text-gray-400">Sem histórico</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                  <template v-if="linha.custo_por_km">
                    {{ dinheiroPorKm(linha.custo_por_km) }}
                    <span
                      v-if="linha.origem_custo_km === 'padrao'"
                      class="ml-1 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800"
                    >
                      padrão
                    </span>
                  </template>
                  <span v-else class="text-gray-400">Não apurado</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <span
                    v-if="linha.manutencoes_a_vencer > 0"
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800 mr-1"
                  >
                    {{ linha.manutencoes_a_vencer }} manutenção(ões)
                  </span>
                  <span
                    v-if="linha.documentos_a_vencer > 0"
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-red-100 text-red-800"
                  >
                    {{ linha.documentos_a_vencer }} documento(s)
                  </span>
                  <span
                    v-if="linha.manutencoes_a_vencer === 0 && linha.documentos_a_vencer === 0"
                    class="text-gray-400"
                  >
                    Nada a vencer
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <span
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                    :class="corDaSituacao(linha.veiculo.situacao)"
                  >
                    {{ rotuloDeSituacao(linha.veiculo.situacao) }}
                  </span>
                </td>
              </tr>
              <tr v-if="veiculos.length === 0">
                <td colspan="8" class="px-6 py-10 text-center text-sm text-gray-500">
                  Nenhum veículo cadastrado. O consumo e o custo por quilômetro só aparecem depois de
                  quatro abastecimentos de tanque cheio, que é o mínimo para a medição não ser ruído.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>
    </div>

    <VeiculoModal
      :show="modalAberto"
      :tipos="tipos"
      :situacoes="situacoes"
      @close="modalAberto = false"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import VeiculoModal from '@/Components/VeiculoModal.vue';

const props = defineProps({
  veiculos: { type: Array, default: () => [] },
  indicadores: { type: Object, default: () => ({ ativos: 0, manutencoes_a_vencer: 0, documentos_a_vencer: 0 }) },
  filtros: { type: Object, default: () => ({}) },
  tipos: { type: Array, default: () => [] },
  situacoes: { type: Array, default: () => [] },
});

const modalAberto = ref(false);
const filtroSituacao = ref(props.filtros.situacao || '');

const rotulosDeSituacao = {
  ativo: 'Ativo',
  manutencao: 'Em manutenção',
  inativo: 'Inativo',
};

function rotuloDeSituacao(situacao) {
  return rotulosDeSituacao[situacao] || situacao;
}

function corDaSituacao(situacao) {
  if (situacao === 'ativo') return 'bg-green-100 text-green-800';
  if (situacao === 'manutencao') return 'bg-yellow-100 text-yellow-800';
  return 'bg-gray-100 text-gray-800';
}

function numero(valor) {
  return new Intl.NumberFormat('pt-BR').format(Number(valor || 0));
}

function decimal(valor, casas) {
  return new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: casas,
    maximumFractionDigits: casas,
  }).format(Number(valor || 0));
}

/**
 * O custo por quilômetro tem quatro casas de propósito: ele vive na terceira e
 * na quarta, e arredondar para centavo aqui esconderia a diferença entre dois
 * veículos.
 */
function dinheiroPorKm(valor) {
  return `R$ ${decimal(valor, 4)}/km`;
}

function abrirCadastro() {
  modalAberto.value = true;
}

function aplicarFiltros() {
  router.get(route('frota.index'), { situacao: filtroSituacao.value || undefined }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  });
}

function limparFiltros() {
  filtroSituacao.value = '';
  aplicarFiltros();
}
</script>
