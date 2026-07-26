<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        :title="contract.contract_number || `Contrato #${contract.id}`"
        :description="address?.client?.name ? `Cliente: ${address.client.name}` : 'Detalhes do contrato'"
      >
        <template #actions>
          <Link href="/contracts" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </Link>
          <button type="button" class="btn-secondary" @click="gerarPDF">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
            </svg>
            PDF
          </button>
          <Link v-if="pode('contrato-editar')" :href="`/contracts/${contract.id}/edit`" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
            </svg>
            Editar
          </Link>
          <button v-if="pode('contrato-editar')" type="button" class="btn-danger" @click="abrirEncerramento">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
            </svg>
            Encerrar contrato
          </button>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-6xl mx-auto space-y-6">
      <!-- Mensagens Flash -->
      <div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!-- Informações do Contrato -->
      <Card>
        <div class="px-6 py-4 border-b border-gray-200 flex items-center gap-3">
          <h3 class="text-lg font-medium text-gray-900">Informações do Contrato</h3>
          <span
            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
            :class="contract.service_type === 'periodico' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'"
          >
            {{ contract.service_type === 'periodico' ? 'Periódico' : 'Pontual' }}
          </span>
        </div>
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label class="block text-sm font-medium text-gray-500">Início</label>
              <div class="mt-1 text-sm text-gray-900">{{ formatarData(contract.start_date) }}</div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Término</label>
              <div class="mt-1 text-sm text-gray-900">{{ formatarData(contract.end_date) || 'Sem data de término' }}</div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Periodicidade</label>
              <div class="mt-1 text-sm text-gray-900">{{ textoPeriodicidade }}</div>
            </div>
            <div v-if="contract.visit_count">
              <label class="block text-sm font-medium text-gray-500">Total de visitas previstas</label>
              <div class="mt-1 text-sm text-gray-900">{{ contract.visit_count }}</div>
            </div>
            <div v-if="contract.service_value">
              <label class="block text-sm font-medium text-gray-500">Valor do serviço</label>
              <div class="mt-1 text-sm text-gray-900">R$ {{ formatarMoeda(contract.service_value) }}</div>
            </div>
            <div v-if="contract.pest_target">
              <label class="block text-sm font-medium text-gray-500">Praga-alvo</label>
              <div class="mt-1 text-sm text-gray-900">{{ contract.pest_target }}</div>
            </div>
            <div v-if="contract.payment_method">
              <label class="block text-sm font-medium text-gray-500">Forma de pagamento</label>
              <div class="mt-1 text-sm text-gray-900">{{ contract.payment_method }}</div>
            </div>
            <div v-if="contract.jurisdiction">
              <label class="block text-sm font-medium text-gray-500">Foro</label>
              <div class="mt-1 text-sm text-gray-900">{{ contract.jurisdiction }}</div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Cliente e Endereço -->
      <Card>
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Cliente e Endereço</h3>
        </div>
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label class="block text-sm font-medium text-gray-500">Cliente</label>
              <div class="mt-1 text-sm text-gray-900">{{ address?.client?.name }}</div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Endereço</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ address?.nickname }}<template v-if="address?.street">, {{ address.street }}, {{ address.number }}</template>
              </div>
              <div v-if="address?.city" class="text-sm text-gray-600">{{ address.city }}/{{ address.state }}</div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Visitas do contrato -->
      <VisitasDoContrato :contrato="contract" :visitas="visitas" />

      <!-- Histórico de alterações -->
      <HistoricoRegistro tipo="contrato" :id="contract.id" />
    </div>

    <!-- Modal de Encerramento de Contrato: cancela as visitas futuras não
         executadas e fecha a vigência. É o caminho que a recusa de exclusão
         de contrato com visita já executada indica ao usuário. -->
    <Modal :show="mostrarEncerramento" @close="cancelarEncerramento">
      <template #icon>
        <svg class="h-6 w-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
        </svg>
      </template>
      <template #title>Encerrar contrato</template>
      <template #content>
        <p class="text-sm text-gray-700 mb-4">
          {{ mensagemEncerramento }}
          Visitas já executadas não são alteradas.
        </p>
        <label class="block text-sm font-medium text-gray-700 mb-1">Motivo do encerramento</label>
        <textarea
          v-model="motivoEncerramento"
          rows="3"
          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          placeholder="Explique por que o contrato está sendo encerrado (opcional). Este texto é anexado a cada visita futura cancelada."
        ></textarea>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="encerrandoContrato" @click="cancelarEncerramento">
          Cancelar
        </button>
        <button type="button" class="btn-danger ml-3" :disabled="encerrandoContrato" @click="confirmarEncerramento">
          {{ encerrandoContrato ? 'Encerrando...' : 'Encerrar contrato' }}
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import VisitasDoContrato from '@/Components/VisitasDoContrato.vue';
import HistoricoRegistro from '@/Components/HistoricoRegistro.vue';
import { formatarData } from '@/utils/formatDate';
import { usePermissoes } from '@/Composables/usePermissoes';

const props = defineProps({
  contract: {
    type: Object,
    required: true,
  },
  address: {
    type: Object,
    default: null,
  },
  visitas: {
    type: Array,
    default: () => [],
  },
  visitasFuturasCount: {
    type: Number,
    default: 0,
  },
});

const { pode } = usePermissoes();

const UNIDADE_LABEL = {
  dias: (valor) => (valor === 1 ? 'dia' : 'dias'),
  semanas: (valor) => (valor === 1 ? 'semana' : 'semanas'),
  meses: (valor) => (valor === 1 ? 'mês' : 'meses'),
};

const textoPeriodicidade = computed(() => {
  const valor = props.contract.visit_frequency_valor;
  const unidade = props.contract.visit_frequency_unidade;

  if (props.contract.service_type !== 'periodico') {
    return 'Não se aplica (contrato pontual)';
  }

  if (!valor || !unidade) {
    return 'Não configurada';
  }

  const rotuloUnidade = UNIDADE_LABEL[unidade] ? UNIDADE_LABEL[unidade](valor) : unidade;

  return `A cada ${valor} ${rotuloUnidade}`;
});

const formatarMoeda = (valor) => {
  if (!valor) return '0,00';
  return parseFloat(valor).toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
};

const gerarPDF = () => {
  if (!props.address?.id) return;
  window.open(`/addresses/${props.address.id}/contract/pdf`, '_blank');
};

// Encerramento de contrato: cancela as visitas futuras não executadas e fecha
// a vigência (grava end_date). `visitasFuturasCount` vem calculado pelo
// controller, para o aviso ser exato sem precisar de uma segunda requisição.
const mostrarEncerramento = ref(false);
const motivoEncerramento = ref('');
const encerrandoContrato = ref(false);

const mensagemEncerramento = computed(() => {
  const quantidade = props.visitasFuturasCount;

  if (quantidade === 0) {
    return 'Este contrato não tem visita futura agendada para cancelar. O encerramento só fecha a vigência.';
  }

  return quantidade === 1
    ? 'Isso cancela 1 visita futura ainda não executada deste contrato.'
    : `Isso cancela ${quantidade} visitas futuras ainda não executadas deste contrato.`;
});

const abrirEncerramento = () => {
  motivoEncerramento.value = '';
  mostrarEncerramento.value = true;
};

const cancelarEncerramento = () => {
  if (encerrandoContrato.value) return;
  mostrarEncerramento.value = false;
  motivoEncerramento.value = '';
};

const confirmarEncerramento = () => {
  encerrandoContrato.value = true;

  router.post(`/contracts/${props.contract.id}/encerrar`, {
    motivo: motivoEncerramento.value,
  }, {
    preserveScroll: true,
    onFinish: () => {
      encerrandoContrato.value = false;
      mostrarEncerramento.value = false;
      motivoEncerramento.value = '';
    },
  });
};
</script>
