<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        :title="`${veiculo.placa} - ${veiculo.marca} ${veiculo.modelo}`"
        :description="`Hodômetro em ${numero(veiculo.km_atual)} km. Apuração de ${formatarData(periodo.de)} a ${formatarData(periodo.ate)}.`"
      >
        <template #actions>
          <Link :href="route('frota.index')" class="btn-secondary">Voltar</Link>
          <button type="button" class="btn-primary" @click="modalAbastecimento = true">
            Registrar abastecimento
          </button>
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

      <!-- Indicadores -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatCard
          title="Consumo médio"
          :value="consumo.km_por_litro !== null ? `${decimal(consumo.km_por_litro, 2)} km/l` : 'Sem histórico'"
          color="green"
          :subtitle="`${consumo.intervalos} intervalo(s) de tanque cheio no período`"
        />
        <StatCard
          title="Custo por quilômetro"
          :value="custoPorKm.total_por_km ? `R$ ${decimal(custoPorKm.total_por_km, 4)}` : 'Não apurado'"
          :color="custoPorKm.origem === 'medido' ? 'green' : 'yellow'"
          :subtitle="legendaDoCusto"
        />
        <StatCard
          title="Situação"
          :value="rotuloDeSituacao(veiculo.situacao)"
          :color="veiculo.situacao === 'ativo' ? 'green' : 'yellow'"
          :subtitle="veiculo.technician?.name || 'Sem técnico responsável'"
        />
      </div>

      <!-- Aviso de origem do custo -->
      <div
        v-if="custoPorKm.origem !== 'medido'"
        class="bg-yellow-50 border border-yellow-200 rounded-md p-4"
      >
        <p class="text-sm font-medium text-yellow-800">
          O custo por quilômetro deste veículo é o valor padrão do cadastro, não uma medição.
        </p>
        <p class="mt-1 text-sm text-yellow-700">
          A apuração começa com três intervalos completos de tanque cheio, ou seja, quatro
          abastecimentos com o tanque cheio registrados. Até lá, o número serve para o rateio
          funcionar, mas não descreve o veículo.
        </p>
      </div>

      <!-- Gráfico de consumo -->
      <Card>
        <div class="flex items-baseline justify-between mb-4">
          <h3 class="text-lg font-medium text-gray-900">Consumo por intervalo de tanque cheio</h3>
          <span class="text-sm text-gray-500">km/l</span>
        </div>

        <p v-if="serie.length < 2" class="text-sm text-gray-500">
          O gráfico aparece a partir de dois intervalos completos. Cada ponto é o consumo entre dois
          abastecimentos de tanque cheio: km/l de um abastecimento isolado não existe, porque não se
          sabe quanto do tanque anterior ainda estava lá.
        </p>

        <div v-else class="overflow-x-auto">
          <svg
            :viewBox="`0 0 ${largura} ${altura}`"
            class="w-full h-56 min-w-[480px]"
            role="img"
            :aria-label="`Consumo em quilômetros por litro ao longo de ${serie.length} intervalos, do mais antigo ao mais recente.`"
          >
            <!-- Grade recessiva -->
            <g>
              <line
                v-for="linha in linhasDeGrade"
                :key="linha.valor"
                :x1="margem.esquerda"
                :x2="largura - margem.direita"
                :y1="linha.y"
                :y2="linha.y"
                stroke="#e5e7eb"
                stroke-width="1"
              />
              <text
                v-for="linha in linhasDeGrade"
                :key="`r-${linha.valor}`"
                :x="margem.esquerda - 8"
                :y="linha.y + 4"
                text-anchor="end"
                class="fill-gray-500"
                font-size="11"
              >
                {{ decimal(linha.valor, 1) }}
              </text>
            </g>

            <!-- Linha do consumo -->
            <polyline
              :points="pontosDaLinha"
              fill="none"
              stroke="#059669"
              stroke-width="2"
              stroke-linejoin="round"
              stroke-linecap="round"
            />

            <!-- Marcadores -->
            <g v-for="(ponto, indice) in pontos" :key="`p-${indice}`">
              <circle
                :cx="ponto.x"
                :cy="ponto.y"
                r="4"
                fill="#059669"
                stroke="#ffffff"
                stroke-width="2"
              />
              <!-- Alvo de clique maior que o marcador, para o toque no celular -->
              <circle
                :cx="ponto.x"
                :cy="ponto.y"
                r="12"
                fill="transparent"
                @mouseenter="pontoAtivo = indice"
                @mouseleave="pontoAtivo = null"
                @focus="pontoAtivo = indice"
                @blur="pontoAtivo = null"
                tabindex="0"
              >
                <title>
                  {{ formatarData(ponto.item.data) }}: {{ decimal(ponto.item.km_por_litro, 2) }} km/l
                  em {{ numero(ponto.item.distancia_km) }} km
                </title>
              </circle>
            </g>

            <!-- Rótulo direto no último ponto: a leitura mais recente é a que
                 importa, e um número em cada ponto vira poluição. -->
            <text
              v-if="pontos.length"
              :x="Math.min(pontos[pontos.length - 1].x, largura - margem.direita - 4)"
              :y="pontos[pontos.length - 1].y - 12"
              text-anchor="end"
              class="fill-gray-900"
              font-size="12"
              font-weight="600"
            >
              {{ decimal(pontos[pontos.length - 1].item.km_por_litro, 2) }}
            </text>

            <!-- Eixo do tempo: só a primeira e a última data, para não colidir -->
            <text
              :x="margem.esquerda"
              :y="altura - 6"
              class="fill-gray-500"
              font-size="11"
            >
              {{ formatarData(serie[0].data) }}
            </text>
            <text
              :x="largura - margem.direita"
              :y="altura - 6"
              text-anchor="end"
              class="fill-gray-500"
              font-size="11"
            >
              {{ formatarData(serie[serie.length - 1].data) }}
            </text>
          </svg>

          <p v-if="pontoAtivo !== null" class="mt-2 text-sm text-gray-700">
            {{ formatarData(serie[pontoAtivo].data) }}:
            <strong>{{ decimal(serie[pontoAtivo].km_por_litro, 2) }} km/l</strong>
            em {{ numero(serie[pontoAtivo].distancia_km) }} km,
            R$ {{ decimal(serie[pontoAtivo].custo_por_km, 4) }}/km.
          </p>

          <details class="mt-3">
            <summary class="text-sm text-gray-600 cursor-pointer">Ver os números</summary>
            <table class="mt-2 min-w-full divide-y divide-gray-200 text-sm">
              <thead>
                <tr>
                  <th class="py-1 text-left font-medium text-gray-500">Até</th>
                  <th class="py-1 text-right font-medium text-gray-500">Distância</th>
                  <th class="py-1 text-right font-medium text-gray-500">Litros</th>
                  <th class="py-1 text-right font-medium text-gray-500">km/l</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-gray-100">
                <tr v-for="(item, indice) in serie" :key="`t-${indice}`">
                  <td class="py-1 text-gray-900">{{ formatarData(item.data) }}</td>
                  <td class="py-1 text-right text-gray-900">{{ numero(item.distancia_km) }} km</td>
                  <td class="py-1 text-right text-gray-900">{{ decimal(item.litros, 2) }}</td>
                  <td class="py-1 text-right text-gray-900">{{ decimal(item.km_por_litro, 2) }}</td>
                </tr>
              </tbody>
            </table>
          </details>
        </div>
      </Card>

      <!-- Manutenções -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Próximas manutenções</h3>
          <p class="text-sm text-gray-500">
            Data e quilometragem são critérios independentes: vence o que chegar primeiro.
          </p>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Descrição</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Por data</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Por quilometragem</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Alerta</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="manutencao in proximasManutencoes" :key="manutencao.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 text-sm text-gray-900">{{ manutencao.descricao }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ manutencao.tipo }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <template v-if="manutencao.proxima_em_data">
                    {{ formatarData(manutencao.proxima_em_data) }}
                    <span class="text-gray-500">({{ diasAte(manutencao.proxima_em_data) }} dia(s))</span>
                  </template>
                  <span v-else class="text-gray-400">Não definida</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <template v-if="manutencao.proxima_em_km">
                    {{ numero(manutencao.proxima_em_km) }} km
                    <span class="text-gray-500">
                      (faltam {{ numero(manutencao.proxima_em_km - veiculo.km_atual) }} km)
                    </span>
                  </template>
                  <span v-else class="text-gray-400">Não definida</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <span
                    v-if="criterioDoAlerta(manutencao.id)"
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800"
                  >
                    Dispara por {{ criterioDoAlerta(manutencao.id) === 'data' ? 'data' : 'quilometragem' }}
                  </span>
                  <span v-else class="text-gray-400">Fora da janela</span>
                </td>
              </tr>
              <tr v-if="proximasManutencoes.length === 0">
                <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">
                  Nenhuma manutenção agendada para este veículo.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>

      <!-- Documentos -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Documentos</h3>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Número</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Validade</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Situação</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="linha in documentos" :key="linha.documento.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ linha.documento.tipo }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                  {{ linha.documento.numero || 'Não informado' }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  {{ formatarData(linha.documento.validade) }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <span
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                    :class="linha.vencido ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'"
                  >
                    {{ linha.vencido ? 'Vencido' : 'Em dia' }}
                  </span>
                </td>
              </tr>
              <tr v-if="documentos.length === 0">
                <td colspan="4" class="px-6 py-8 text-center text-sm text-gray-500">
                  Nenhum documento cadastrado.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>

      <!-- Estoque do veículo -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Estoque no veículo</h3>
          <p class="text-sm text-gray-500">Produto que já saiu do depósito e está dentro deste veículo.</p>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Produto</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Saldo</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="item in estoque" :key="item.product_id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ item.produto }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                  {{ decimal(item.quantidade, 2) }}
                </td>
              </tr>
              <tr v-if="estoque.length === 0">
                <td colspan="2" class="px-6 py-8 text-center text-sm text-gray-500">
                  Sem saldo no veículo.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>

      <!-- Abastecimentos -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Últimos abastecimentos</h3>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Hodômetro</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Litros</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Valor</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tanque</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Posto</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="item in ultimosAbastecimentos" :key="item.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ formatarData(item.data) }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">{{ numero(item.km) }} km</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">{{ decimal(item.litros, 3) }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">R$ {{ decimal(item.valor_total, 2) }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <span
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                    :class="item.tanque_cheio ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
                  >
                    {{ item.tanque_cheio ? 'Cheio' : 'Parcial' }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ item.posto || '-' }}</td>
              </tr>
              <tr v-if="ultimosAbastecimentos.length === 0">
                <td colspan="6" class="px-6 py-8 text-center text-sm text-gray-500">
                  Nenhum abastecimento registrado.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>
    </div>

    <AbastecimentoModal
      :show="modalAbastecimento"
      :veiculo-id="veiculo.id"
      :km-atual="Number(veiculo.km_atual)"
      :fornecedores="fornecedores"
      @close="modalAbastecimento = false"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import AbastecimentoModal from '@/Components/AbastecimentoModal.vue';
import { formatarData, diasAte } from '@/utils/formatDate';

const props = defineProps({
  veiculo: { type: Object, required: true },
  periodo: { type: Object, default: () => ({ de: null, ate: null }) },
  consumo: { type: Object, default: () => ({ km_por_litro: null, intervalos: 0 }) },
  serie_de_consumo: { type: Array, default: () => [] },
  custo_por_km: { type: Object, default: () => ({ total_por_km: null, origem: 'padrao' }) },
  proximas_manutencoes: { type: Array, default: () => [] },
  ultimos_abastecimentos: { type: Array, default: () => [] },
  documentos: { type: Array, default: () => [] },
  estoque: { type: Array, default: () => [] },
  fornecedores: { type: Array, default: () => [] },
  alertas: { type: Object, default: () => ({ manutencoes: [] }) },
});

const modalAbastecimento = ref(false);
const pontoAtivo = ref(null);

const serie = computed(() => props.serie_de_consumo);
const custoPorKm = computed(() => props.custo_por_km);
const proximasManutencoes = computed(() => props.proximas_manutencoes);
const ultimosAbastecimentos = computed(() => props.ultimos_abastecimentos);

const largura = 640;
const altura = 200;
const margem = { esquerda: 44, direita: 16, topo: 20, base: 32 };

const rotulosDeSituacao = {
  ativo: 'Ativo',
  manutencao: 'Em manutenção',
  inativo: 'Inativo',
};

function rotuloDeSituacao(situacao) {
  return rotulosDeSituacao[situacao] || situacao;
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

const legendaDoCusto = computed(() => {
  if (custoPorKm.value.origem === 'medido') {
    return 'Apurado sobre abastecimentos de tanque cheio';
  }

  return custoPorKm.value.total_por_km
    ? 'Valor padrão do cadastro, não uma medição'
    : 'Sem histórico e sem valor padrão cadastrado';
});

/**
 * Qual critério dispara o alerta desta manutenção, segundo o backend. A regra
 * de "o que vier primeiro" é do `AlertaDeFrotaService`, e a tela só mostra a
 * decisão dele: recalcular aqui daria, mais cedo ou mais tarde, uma tela
 * dizendo "data" e um e-mail dizendo "quilometragem".
 */
function criterioDoAlerta(manutencaoId) {
  const alerta = (props.alertas.manutencoes || []).find(
    (item) => Number(item.manutencao?.id) === Number(manutencaoId)
  );

  return alerta ? alerta.criterio : null;
}

const escala = computed(() => {
  const valores = serie.value.map((item) => Number(item.km_por_litro));

  if (valores.length === 0) return { minimo: 0, maximo: 1 };

  const minimo = Math.min(...valores);
  const maximo = Math.max(...valores);

  // Série plana (todos os pontos iguais) precisa de uma faixa artificial, ou a
  // divisão por zero jogaria a linha para fora do desenho.
  if (maximo === minimo) {
    return { minimo: minimo - 1, maximo: maximo + 1 };
  }

  const folga = (maximo - minimo) * 0.15;

  return { minimo: minimo - folga, maximo: maximo + folga };
});

function paraY(valor) {
  const { minimo, maximo } = escala.value;
  const util = altura - margem.topo - margem.base;
  const proporcao = (Number(valor) - minimo) / (maximo - minimo);

  return margem.topo + util - proporcao * util;
}

const pontos = computed(() => {
  const total = serie.value.length;
  if (total === 0) return [];

  const util = largura - margem.esquerda - margem.direita;

  return serie.value.map((item, indice) => ({
    x: total === 1 ? margem.esquerda + util / 2 : margem.esquerda + (util * indice) / (total - 1),
    y: paraY(item.km_por_litro),
    item,
  }));
});

const pontosDaLinha = computed(() =>
  pontos.value.map((ponto) => `${ponto.x.toFixed(1)},${ponto.y.toFixed(1)}`).join(' ')
);

const linhasDeGrade = computed(() => {
  const { minimo, maximo } = escala.value;

  return [0, 0.5, 1].map((fracao) => {
    const valor = minimo + (maximo - minimo) * fracao;

    return { valor, y: paraY(valor) };
  });
});
</script>
