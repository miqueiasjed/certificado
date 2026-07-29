<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Inadimplência"
        :description="`Saldo em aberto por cliente, faixa de atraso, referência ${formatarData(referencia)}`"
      >
        <template #actions>
          <Link href="/contas-a-receber" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Contas a receber
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-6xl mx-auto space-y-6">
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatCard title="Clientes inadimplentes" :value="aging.por_cliente.length" color="red" />
        <StatCard title="Saldo total em aberto" :value="formatarMoeda(aging.total_geral.total.valor)" color="gray" />
        <StatCard title="Parcelas em aberto" :value="aging.total_geral.total.quantidade" color="blue" />
      </div>

      <Card v-if="aging.por_cliente.length === 0">
        <div class="text-center py-12">
          <svg class="mx-auto h-12 w-12 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum saldo em aberto</h3>
          <p class="mt-1 text-sm text-gray-500">Todas as parcelas estão quitadas ou canceladas.</p>
        </div>
      </Card>

      <Card v-else padding="none">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cliente</th>
                <th v-for="faixa in FAIXAS" :key="faixa.chave" class="px-4 py-3 text-right text-xs font-medium uppercase tracking-wider" :class="faixa.corCabecalho">
                  {{ faixa.rotulo }}
                </th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Total</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Cobrança</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="cliente in aging.por_cliente" :key="cliente.client_id" class="hover:bg-gray-50">
                <td class="px-4 py-4 text-sm font-medium text-gray-900">{{ cliente.cliente }}</td>
                <td v-for="faixa in FAIXAS" :key="faixa.chave" class="px-4 py-4 text-right">
                  <button
                    v-if="cliente.faixas[faixa.chave].quantidade > 0 && podeAbrirParcelas"
                    type="button"
                    class="text-sm font-medium text-blue-700 hover:text-blue-900 hover:underline"
                    :title="`Ver as ${cliente.faixas[faixa.chave].quantidade} parcela(s) desta faixa`"
                    @click="abrirParcelasDaFaixa(cliente, faixa.chave)"
                  >
                    {{ formatarMoeda(cliente.faixas[faixa.chave].valor) }}
                  </button>
                  <span v-else-if="cliente.faixas[faixa.chave].quantidade > 0" class="text-sm text-gray-700">
                    {{ formatarMoeda(cliente.faixas[faixa.chave].valor) }}
                  </span>
                  <span v-else class="text-sm text-gray-300">-</span>
                  <div v-if="cliente.faixas[faixa.chave].quantidade > 0" class="text-xs text-gray-500">
                    {{ cliente.faixas[faixa.chave].quantidade }} parcela(s)
                  </div>
                </td>
                <td class="px-4 py-4 text-right text-sm font-semibold text-gray-900">
                  {{ formatarMoeda(cliente.total.valor) }}
                </td>
                <td class="px-4 py-4 text-right">
                  <BotaoWhatsapp
                    :telefone="cliente.aceita_whatsapp ? cliente.telefone : null"
                    :mensagem="mensagemCobranca(cliente)"
                  />
                </td>
              </tr>
            </tbody>
            <tfoot class="bg-gray-50">
              <tr>
                <td class="px-4 py-4 text-sm font-semibold text-gray-900">Total geral</td>
                <td v-for="faixa in FAIXAS" :key="faixa.chave" class="px-4 py-4 text-right text-sm font-semibold text-gray-900">
                  {{ formatarMoeda(aging.total_geral.faixas[faixa.chave].valor) }}
                </td>
                <td class="px-4 py-4 text-right text-sm font-semibold text-gray-900">
                  {{ formatarMoeda(aging.total_geral.total.valor) }}
                </td>
                <td></td>
              </tr>
            </tfoot>
          </table>
        </div>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import BotaoWhatsapp from '@/Components/BotaoWhatsapp.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { formatarData } from '@/utils/formatDate';

const props = defineProps({
  filtros: { type: Object, default: () => ({ client_id: null }) },
  aging: { type: Object, required: true },
  referencia: { type: String, required: true },
});

const { pode } = usePermissoes();

// A tela de contas a receber (destino do drill-down) exige a permissão de
// título; sem ela, a faixa continua legível, só deixa de ser um link.
const podeAbrirParcelas = pode('financeiro-titulos');

const FAIXAS = [
  { chave: 'a_vencer', rotulo: 'A vencer', corCabecalho: 'text-gray-500' },
  { chave: 'de_1_a_30', rotulo: '1 a 30 dias', corCabecalho: 'text-amber-700' },
  { chave: 'de_31_a_60', rotulo: '31 a 60 dias', corCabecalho: 'text-orange-700' },
  { chave: 'de_61_a_90', rotulo: '61 a 90 dias', corCabecalho: 'text-red-700' },
  { chave: 'acima_de_90', rotulo: 'Acima de 90 dias', corCabecalho: 'text-red-900' },
];

function formatarMoeda(valor) {
  const numero = typeof valor === 'number' ? valor : parseFloat(valor || 0);
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number.isFinite(numero) ? numero : 0);
}

// Janela de vencimento equivalente a cada faixa do aging, calculada em cima
// do mesmo dia de referência que o backend usou (`referencia`, dia no fuso do
// negócio), sempre por aritmética de calendário em UTC: nunca `new Date(str)`
// direto, para não escorregar um dia por causa do fuso do navegador.
function somarDias(diaISO, quantidade) {
  const [ano, mes, dia] = diaISO.split('-').map(Number);
  const data = new Date(Date.UTC(ano, mes - 1, dia + quantidade));

  return `${data.getUTCFullYear()}-${String(data.getUTCMonth() + 1).padStart(2, '0')}-${String(data.getUTCDate()).padStart(2, '0')}`;
}

const JANELAS = {
  a_vencer: (hoje) => ({ de: hoje, ate: null }),
  de_1_a_30: (hoje) => ({ de: somarDias(hoje, -30), ate: somarDias(hoje, -1) }),
  de_31_a_60: (hoje) => ({ de: somarDias(hoje, -60), ate: somarDias(hoje, -31) }),
  de_61_a_90: (hoje) => ({ de: somarDias(hoje, -90), ate: somarDias(hoje, -61) }),
  acima_de_90: () => ({ de: null, ate: somarDias(props.referencia, -91) }),
};

function abrirParcelasDaFaixa(cliente, faixaChave) {
  if (!podeAbrirParcelas) return;

  const janela = JANELAS[faixaChave](props.referencia);
  const params = { client_id: cliente.client_id };

  if (janela.de) params.de = janela.de;
  if (janela.ate) params.ate = janela.ate;

  router.get('/contas-a-receber', params);
}

function mensagemCobranca(cliente) {
  return `Olá, ${cliente.cliente}! Identificamos um saldo em aberto de ${formatarMoeda(cliente.total.valor)} em nosso controle financeiro. Poderia nos ajudar a regularizar o pagamento? Qualquer dúvida, estamos à disposição.`;
}
</script>
