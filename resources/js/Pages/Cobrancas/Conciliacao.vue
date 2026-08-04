<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Conciliação de cobrança"
        :description="`Período de ${formatarData(periodo.de)} até ${formatarData(periodo.ate)}`"
      >
        <template #actions>
          <Link href="/cobrancas" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Cobranças
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <Alert
        v-if="mensagemResultado"
        :key="alertaChave"
        :type="mensagemResultado.tipo"
        :title="mensagemResultado.titulo"
        :message="mensagemResultado.detalhe"
      />

      <!-- Período -->
      <Card>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 items-end">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">De</label>
            <input v-model="filtros.de" type="date" class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Até</label>
            <input v-model="filtros.ate" type="date" class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm" />
          </div>
          <div>
            <button type="button" class="btn-primary w-full sm:w-auto" @click="aplicarFiltros">Filtrar</button>
          </div>
        </div>
      </Card>

      <!-- Bloco 1: eventos sem baixa - falha de verdade -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200 flex items-start justify-between gap-3">
          <div class="flex-1 min-w-0">
            <h3 class="text-lg font-medium text-gray-900">Eventos sem baixa</h3>
            <p class="text-sm text-gray-500">O gateway confirmou o pagamento, mas a cobrança não foi baixada. Precisa de atenção.</p>
          </div>
          <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-red-100 text-red-800 shrink-0">
            {{ eventos_sem_baixa.length }}
          </span>
        </div>

        <div v-if="eventos_sem_baixa.length === 0" class="p-6 flex items-center gap-2 text-sm text-green-700 bg-green-50">
          <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          Nada divergente no período.
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Evento</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Valor no gateway</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pago em</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Motivo</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ação</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="evento in eventos_sem_baixa" :key="evento.gateway_event_id" class="hover:bg-gray-50">
                <td class="px-4 py-4 text-sm text-gray-900">
                  {{ evento.evento_id }}
                  <div class="text-xs text-gray-500">{{ evento.gateway }} · {{ evento.tentativas }} tentativa(s)</div>
                </td>
                <td class="px-4 py-4 text-sm text-gray-700 text-right">{{ formatarMoeda(evento.valor_pago_no_gateway) }}</td>
                <td class="px-4 py-4 text-sm text-gray-700">{{ formatarData(evento.pago_em) }}</td>
                <td class="px-4 py-4 text-sm text-red-700 max-w-sm">
                  {{ evento.motivo }}
                  <div v-if="evento.erro" class="text-xs text-gray-500 mt-1">Último erro: {{ evento.erro }}</div>
                </td>
                <td class="px-4 py-4 text-right">
                  <button
                    v-if="evento.pode_reprocessar && pode('cobranca-emitir')"
                    type="button"
                    :disabled="processandoEventoId === evento.gateway_event_id"
                    class="text-sm font-medium text-green-600 hover:text-green-900 disabled:opacity-50"
                    @click="reprocessarEvento(evento)"
                  >
                    {{ processandoEventoId === evento.gateway_event_id ? 'Reprocessando...' : 'Reprocessar' }}
                  </button>
                  <span v-else-if="!evento.pode_reprocessar" class="text-xs text-gray-400">Já processado</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>

      <!-- Bloco 2: baixas sem evento - informação, não é erro -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200 flex items-start justify-between gap-3">
          <div class="flex-1 min-w-0">
            <h3 class="text-lg font-medium text-gray-900">Baixas sem evento</h3>
            <p class="text-sm text-gray-500">Parcela quitada sem webhook do gateway por trás - recebimento manual ou baixa direta. Não é uma falha.</p>
          </div>
          <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-blue-100 text-blue-800 shrink-0">
            {{ baixas_sem_evento.length }}
          </span>
        </div>

        <div v-if="baixas_sem_evento.length === 0" class="p-6 flex items-center gap-2 text-sm text-green-700 bg-green-50">
          <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          Nada divergente no período.
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cobrança</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Valor pago</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pago em</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Observação</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="baixa in baixas_sem_evento" :key="baixa.charge_id" class="hover:bg-gray-50">
                <td class="px-4 py-4 text-sm text-gray-900">#{{ baixa.charge_id }}</td>
                <td class="px-4 py-4 text-sm text-gray-700">{{ TIPO_LABEL[baixa.tipo] || baixa.tipo }}</td>
                <td class="px-4 py-4 text-sm text-gray-700 text-right">{{ formatarMoeda(baixa.valor_pago) }}</td>
                <td class="px-4 py-4 text-sm text-gray-700">{{ formatarData(baixa.pago_em) }}</td>
                <td class="px-4 py-4 text-sm text-blue-700 max-w-sm">{{ baixa.observacao }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>

      <!-- Bloco 3: valores divergentes - falha de verdade -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200 flex items-start justify-between gap-3">
          <div class="flex-1 min-w-0">
            <h3 class="text-lg font-medium text-gray-900">Valores divergentes</h3>
            <p class="text-sm text-gray-500">Gateway e sistema concordam que foi pago, mas por valores diferentes.</p>
          </div>
          <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-amber-100 text-amber-800 shrink-0">
            {{ valores_divergentes.length }}
          </span>
        </div>

        <div v-if="valores_divergentes.length === 0" class="p-6 flex items-center gap-2 text-sm text-green-700 bg-green-50">
          <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          Nada divergente no período.
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cobrança</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Valor no gateway</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Valor baixado</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Diferença</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Pago em</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="divergencia in valores_divergentes" :key="divergencia.charge_id" class="hover:bg-gray-50">
                <td class="px-4 py-4 text-sm text-gray-900">#{{ divergencia.charge_id }}</td>
                <td class="px-4 py-4 text-sm text-gray-700 text-right">{{ formatarMoeda(divergencia.valor_pago_no_gateway) }}</td>
                <td class="px-4 py-4 text-sm text-gray-700 text-right">{{ formatarMoeda(divergencia.valor_baixado_no_sistema) }}</td>
                <td class="px-4 py-4 text-sm font-semibold text-amber-700 text-right">{{ formatarMoeda(divergencia.diferenca) }}</td>
                <td class="px-4 py-4 text-sm text-gray-700">{{ formatarData(divergencia.pago_em) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Alert from '@/Components/Alert.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { formatarData } from '@/utils/formatDate';

// Formato exato de cada prop: `ConciliacaoService::relatorio()` (Task
// 19.6), devolvido direto pelo Inertia::render() de
// `ChargeController::conciliacao()` - por isso os props chegam soltos, sem
// um wrapper `relatorio`.
const props = defineProps({
  periodo: { type: Object, required: true },
  eventos_sem_baixa: { type: Array, default: () => [] },
  baixas_sem_evento: { type: Array, default: () => [] },
  valores_divergentes: { type: Array, default: () => [] },
});

const { pode } = usePermissoes();

const TIPO_LABEL = { boleto: 'Boleto', pix: 'Pix' };

function formatarMoeda(valor) {
  const numero = typeof valor === 'number' ? valor : parseFloat(valor || 0);
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number.isFinite(numero) ? numero : 0);
}

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
}

const mensagemResultado = ref(null);
const alertaChave = ref(0);

function mostrarResultado(tipo, titulo, detalhe) {
  mensagemResultado.value = { tipo, titulo, detalhe };
  alertaChave.value += 1;
}

// -----------------------------------------------------------------
// Período
// -----------------------------------------------------------------

const filtros = reactive({ de: props.periodo.de, ate: props.periodo.ate });

function aplicarFiltros() {
  router.get('/cobrancas/conciliacao', { de: filtros.de, ate: filtros.ate }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  });
}

// -----------------------------------------------------------------
// Reprocessar evento pendente
// -----------------------------------------------------------------

const processandoEventoId = ref(null);

async function reprocessarEvento(evento) {
  if (processandoEventoId.value !== null) return;

  processandoEventoId.value = evento.gateway_event_id;

  try {
    const resposta = await fetch(`/cobrancas/conciliacao/eventos/${evento.gateway_event_id}/reprocessar`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken(),
        Accept: 'application/json',
      },
    });

    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) {
      mostrarResultado('error', 'Não foi possível reprocessar', dados.message || 'Tente novamente em instantes.');
      return;
    }

    const sucesso = dados.evento?.processado_em !== null && dados.evento?.processado_em !== undefined;
    mostrarResultado(sucesso ? 'success' : 'warning', 'Reprocessamento', dados.message);
    router.reload({ only: ['eventos_sem_baixa', 'baixas_sem_evento', 'valores_divergentes'] });
  } finally {
    processandoEventoId.value = null;
  }
}
</script>
