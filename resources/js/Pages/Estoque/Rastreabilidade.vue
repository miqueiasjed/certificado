<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Rastreabilidade de lote"
        description="Qual produto foi aplicado em qual cliente, a primeira pergunta da fiscalização em caso de incidente."
      >
        <template #actions>
          <a
            v-if="lote"
            :href="route('lotes.rastreabilidade.pdf', lote.id)"
            target="_blank"
            rel="noopener"
            class="btn-primary"
          >
            Exportar em PDF
          </a>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-4xl mx-auto space-y-6">
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!-- Busca por lote, com autocompletar por produto ou por número -->
      <Card>
        <div ref="containerRef" class="relative">
          <label class="block text-sm font-medium text-gray-700 mb-1">Buscar outro lote</label>
          <input
            v-model="busca"
            type="text"
            placeholder="Digite o nome do produto ou o número do lote"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            @input="sugestoesAbertas = true"
            @focus="sugestoesAbertas = !!busca"
          />

          <div
            v-if="sugestoesAbertas && busca"
            class="absolute z-10 mt-1 w-full bg-white shadow-lg max-h-64 rounded-md py-1 ring-1 ring-black ring-opacity-5 overflow-auto"
          >
            <div v-if="sugestoes.length === 0" class="px-4 py-2 text-sm text-gray-500">
              Nenhum lote encontrado.
            </div>
            <div
              v-for="sugestao in sugestoes"
              :key="sugestao.id"
              class="cursor-pointer select-none px-4 py-2 hover:bg-green-50"
              @click="selecionarLote(sugestao)"
            >
              <p class="text-sm font-medium text-gray-900">{{ sugestao.produto }} - lote {{ sugestao.lote }}</p>
              <p class="text-xs text-gray-500">
                Validade {{ formatarData(sugestao.validade) }}
                <span v-if="sugestao.vencido" class="text-red-600 font-medium"> · vencido</span>
              </p>
            </div>
          </div>
        </div>

        <p v-if="carregando" class="text-sm text-gray-500 mt-2">Carregando rastreabilidade...</p>
      </Card>

      <template v-if="lote">
        <!-- Dados do lote -->
        <Card>
          <div class="flex items-start justify-between mb-4">
            <div>
              <h3 class="text-lg font-semibold text-gray-900">{{ lote.produto }}</h3>
              <p class="text-sm text-gray-500">Lote {{ lote.lote }}</p>
            </div>
            <span
              class="px-2 py-1 text-xs font-medium rounded-full"
              :class="lote.vencido ? 'bg-red-100 text-red-800' : 'bg-green-100 text-green-800'"
            >
              {{ lote.vencido ? 'Vencido' : 'Dentro da validade' }}
            </span>
          </div>

          <dl class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-sm">
            <div>
              <dt class="text-gray-500">Validade</dt>
              <dd class="text-gray-900 font-medium">{{ formatarData(lote.validade) }}</dd>
            </div>
            <div>
              <dt class="text-gray-500">Recebido em</dt>
              <dd class="text-gray-900 font-medium">{{ formatarData(lote.recebido_em) }}</dd>
            </div>
            <div>
              <dt class="text-gray-500">Saldo atual</dt>
              <dd class="text-gray-900 font-medium">{{ formatoNumero(lote.saldo) }} {{ lote.unidade }}</dd>
            </div>
            <div>
              <dt class="text-gray-500">Custo unitário</dt>
              <dd class="text-gray-900 font-medium">{{ formatarMoeda(lote.custo_unitario) }}</dd>
            </div>
            <div v-if="lote.fornecedor">
              <dt class="text-gray-500">Fornecedor</dt>
              <dd class="text-gray-900 font-medium">{{ lote.fornecedor }}</dd>
            </div>
            <div v-if="lote.nota_fiscal">
              <dt class="text-gray-500">Nota fiscal</dt>
              <dd class="text-gray-900 font-medium">{{ lote.nota_fiscal }}</dd>
            </div>
          </dl>
        </Card>

        <!-- Aplicações -->
        <Card padding="none">
          <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
            <h3 class="text-lg font-medium text-gray-900">Aplicações registradas</h3>
            <span class="text-sm text-gray-500">Total aplicado: {{ formatoNumero(totalAplicado) }} {{ lote.unidade }}</span>
          </div>

          <div v-if="aplicacoes.length === 0" class="p-6 text-sm text-gray-500 text-center">
            Nenhuma aplicação registrada para este lote.
          </div>

          <div v-else class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">OS</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cliente</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Endereço</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Técnico</th>
                  <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Quantidade</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr v-for="aplicacao in aplicacoes" :key="aplicacao.stock_movement_id" class="hover:bg-gray-50">
                  <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900">{{ formatarData(aplicacao.data) }}</td>
                  <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">{{ aplicacao.numero }}</td>
                  <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900">{{ aplicacao.cliente }}</td>
                  <td class="px-4 py-4 text-sm text-gray-600">{{ aplicacao.endereco }}</td>
                  <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">{{ aplicacao.tecnico || '-' }}</td>
                  <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900 text-right">
                    {{ formatoNumero(aplicacao.quantidade) }} {{ lote.unidade }}
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </Card>
      </template>

      <Card v-else>
        <p class="text-sm text-gray-500 text-center">Busque um lote para ver a rastreabilidade.</p>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, onMounted, onUnmounted } from 'vue';
import { router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import { formatarData } from '@/utils/formatDate';

/**
 * `GET /lotes/{lote}/rastreabilidade` (Task 17.7) exige um lote na URL, então
 * a primeira carga desta página sempre chega com `lote` preenchido (o link de
 * origem é uma linha da lista de lotes). O campo de busca abaixo deixa trocar
 * de lote sem sair da página: a troca usa `router.get` com `preserveState`
 * para o mesmo componente Inertia, e não uma navegação cheia.
 */
const props = defineProps({
  lote: { type: Object, default: null },
  aplicacoes: { type: Array, default: () => [] },
  total_aplicado: { type: Number, default: 0 },
});

const lote = computed(() => props.lote);
const aplicacoes = computed(() => props.aplicacoes);
const totalAplicado = computed(() => props.total_aplicado);

const listaDeLotes = ref([]);
const busca = ref('');
const sugestoesAbertas = ref(false);
const carregando = ref(false);
const containerRef = ref(null);

const sugestoes = computed(() => {
  const consulta = busca.value.trim().toLowerCase();

  if (!consulta) {
    return [];
  }

  return listaDeLotes.value
    .filter((item) => item.lote.toLowerCase().includes(consulta) || item.produto.toLowerCase().includes(consulta))
    .slice(0, 8);
});

function selecionarLote(loteEncontrado) {
  busca.value = '';
  sugestoesAbertas.value = false;

  if (props.lote && Number(props.lote.id) === Number(loteEncontrado.id)) {
    return;
  }

  carregando.value = true;

  router.get(route('lotes.rastreabilidade', loteEncontrado.id), {}, {
    preserveState: true,
    preserveScroll: true,
    onFinish: () => {
      carregando.value = false;
    },
  });
}

function formatoNumero(valor) {
  return Number(valor).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 4 });
}

function formatarMoeda(valor) {
  return Number(valor).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function handleClickOutside(event) {
  if (containerRef.value && !containerRef.value.contains(event.target)) {
    sugestoesAbertas.value = false;
  }
}

onMounted(async () => {
  document.addEventListener('click', handleClickOutside);

  try {
    const resposta = await fetch('/lotes', { headers: { Accept: 'application/json' } });

    if (resposta.ok) {
      const dados = await resposta.json();
      listaDeLotes.value = dados.lotes || [];
    }
  } catch (erro) {
    // A lista de apoio para o autocompletar é um extra de usabilidade: uma
    // falha aqui não pode impedir a exibição da rastreabilidade já carregada.
  }
});

onUnmounted(() => {
  document.removeEventListener('click', handleClickOutside);
});
</script>
