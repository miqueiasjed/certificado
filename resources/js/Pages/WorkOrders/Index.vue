<template>
  <AuthenticatedLayout>
    <PageHeader
      title="Ordens de Serviço"
      subtitle="Gerencie todas as ordens de serviço do sistema"
    >
      <template #actions>
        <Link v-if="pode('ordem-servico-criar')" :href="route('work-orders.create')" class="btn-primary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
          </svg>
          Nova Ordem
        </Link>
      </template>
    </PageHeader>

    <!-- Filtros -->
    <Card class="mb-6 mt-8">
      <div class="p-6">
        <form @submit.prevent="applyFilters" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
          <!-- Cliente -->
          <div>
            <label for="client_id" class="block text-sm font-medium text-gray-700 mb-2">
              Cliente
            </label>
            <select
              id="client_id"
              v-model="filters.client_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os clientes</option>
              <option v-for="client in clients" :key="client.id" :value="client.id">
                {{ client.name }}
              </option>
            </select>
          </div>

          <!-- Endereço -->
          <div>
            <label for="address_id" class="block text-sm font-medium text-gray-700 mb-2">
              Endereço
            </label>
            <select
              id="address_id"
              v-model="filters.address_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os endereços</option>
              <option v-for="address in addresses" :key="address.id" :value="address.id">
                {{ address.nickname }} - {{ address.street }}, {{ address.number }}
              </option>
            </select>
          </div>

          <!-- Técnico -->
          <div>
            <label for="technician_id" class="block text-sm font-medium text-gray-700 mb-2">
              Técnico
            </label>
            <select
              id="technician_id"
              v-model="filters.technician_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os técnicos</option>
              <option v-for="technician in technicians" :key="technician.id" :value="technician.id">
                {{ technician.name }}
              </option>
            </select>
          </div>

          <!-- Status -->
          <div>
            <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
              Status
            </label>
            <select
              id="status"
              v-model="filters.status"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os status</option>
              <option value="pending">Pendente</option>
              <option value="scheduled">Agendada</option>
              <option value="in_progress">Em Andamento</option>
              <option value="completed">Concluída</option>
              <option value="cancelled">Cancelada</option>
              <option value="on_hold">Em Espera</option>
            </select>
          </div>

          <!-- Prioridade -->
          <div>
            <label for="priority_level" class="block text-sm font-medium text-gray-700 mb-2">
              Prioridade
            </label>
            <select
              id="priority_level"
              v-model="filters.priority_level"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todas as prioridades</option>
              <option value="low">Baixa</option>
              <option value="medium">Média</option>
              <option value="high">Alta</option>
              <option value="urgent">Urgente</option>
              <option value="emergency">Emergência</option>
            </select>
          </div>

          <!-- Serviço -->
          <div>
            <label for="service_id" class="block text-sm font-medium text-gray-700 mb-2">
              Serviço
            </label>
            <select
              id="service_id"
              v-model="filters.service_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os serviços</option>
              <option v-for="service in services" :key="service.id" :value="service.id">
                {{ service.name }}
              </option>
            </select>
          </div>

          <!-- Data (De) -->
          <div>
            <label for="date_from" class="block text-sm font-medium text-gray-700 mb-2">
              Data (De)
            </label>
            <input
              id="date_from"
              v-model="filters.date_from"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>

          <!-- Data (Até) -->
          <div>
            <label for="date_to" class="block text-sm font-medium text-gray-700 mb-2">
              Data (Até)
            </label>
            <input
              id="date_to"
              v-model="filters.date_to"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>

          <!-- Botões de Filtro -->
          <div class="md:col-span-2 lg:col-span-3 flex justify-end space-x-3">
            <button
              type="button"
              @click="clearFilters"
              class="btn-secondary"
            >
              Limpar Filtros
            </button>
            <button
              type="submit"
              class="btn-primary"
            >
              Aplicar Filtros
            </button>
          </div>
        </form>
      </div>
    </Card>

    <div v-if="selecionadas.size > 0" class="mb-6 flex flex-col gap-3 rounded-lg border border-green-200 bg-green-50 p-4 sm:flex-row sm:items-center sm:justify-between">
      <p class="text-sm text-green-900">{{ selecionadas.size }} ordem(ns) selecionada(s) para emissão fiscal.</p>
      <div class="flex flex-wrap gap-2">
        <button type="button" class="btn-secondary-sm" @click="limparSelecaoFiscal">Limpar seleção</button>
        <button type="button" class="btn-primary" @click="abrirEmissaoFiscal(ordensSelecionadas)">Emitir NFS-e</button>
      </div>
    </div>

    <!-- Lista de Ordens de Serviço -->
    <Card>
      <div class="p-6">
        <div v-if="workOrders.data.length === 0" class="text-center py-8">
          <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
          </svg>
          <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhuma ordem de serviço encontrada</h3>
          <p class="mt-1 text-sm text-gray-500">Comece criando uma nova ordem de serviço.</p>
        </div>

        <div v-else class="space-y-4">
          <div
            v-for="workOrder in workOrders.data"
            :key="workOrder.id"
            class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors"
          >
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div class="flex-1 w-full">
                <div class="flex items-start space-x-3">
                  <input
                    v-if="podeEmitirNota"
                    type="checkbox"
                    :checked="selecionadas.has(workOrder.id)"
                    class="mt-2 h-4 w-4 rounded border-gray-300 text-green-600 focus:ring-green-500"
                    :aria-label="`Selecionar a ordem ${workOrder.order_number}`"
                    @change="alternarSelecaoFiscal(workOrder)"
                  />
                  <!-- Ícone da ordem -->
                  <div class="flex-shrink-0">
                    <div class="h-8 w-8 rounded-full bg-blue-100 flex items-center justify-center">
                      <svg class="h-4 w-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                      </svg>
                    </div>
                  </div>

                  <!-- Informações da ordem -->
                  <div class="flex-1 min-w-0">
                    <div class="flex items-center space-x-2">
                      <h3 class="text-sm font-medium text-gray-900">
                        {{ workOrder.order_number }}
                      </h3>
                      <span
                        :class="`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                          workOrder.status === 'completed' ? 'bg-green-100 text-green-800' :
                          workOrder.status === 'in_progress' ? 'bg-yellow-100 text-yellow-800' :
                          workOrder.status === 'cancelled' ? 'bg-red-100 text-red-800' :
                          workOrder.status === 'on_hold' ? 'bg-orange-100 text-orange-800' :
                          'bg-gray-100 text-gray-800'
                        }`"
                      >
                        {{ workOrder.status_text }}
                      </span>
                      <span
                        :class="`inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium ${
                          workOrder.priority_level === 'emergency' ? 'bg-purple-100 text-purple-800' :
                          workOrder.priority_level === 'urgent' ? 'bg-red-100 text-red-800' :
                          workOrder.priority_level === 'high' ? 'bg-orange-100 text-orange-800' :
                          workOrder.priority_level === 'medium' ? 'bg-yellow-100 text-yellow-800' :
                          'bg-green-100 text-green-800'
                        }`"
                      >
                        {{ workOrder.priority_level_text }}
                      </span>
                    </div>
                    <p class="text-sm text-gray-500">
                      {{ workOrder.client?.name }} - {{ workOrder.address?.street }}, {{ workOrder.address?.number }} - {{ workOrder.address?.city }}/{{ workOrder.address?.state }}
                    </p>
                    <p class="text-sm text-gray-500">
                      {{ formatarData(workOrder.scheduled_date) }} • {{ workOrder.technician?.name }}
                    </p>
                  </div>
                </div>

                <!-- Descrição -->
                <div class="mt-3 ml-11">
                  <div v-if="workOrder.description" class="text-sm text-gray-600 mb-2">
                    <span class="font-medium">Descrição:</span> {{ workOrder.description }}
                  </div>
                  <div v-if="workOrder.observations" class="text-sm text-gray-600">
                    <span class="font-medium">Observações:</span> {{ workOrder.observations }}
                  </div>
                </div>
              </div>

              <!-- Ações -->
              <div class="flex w-full flex-wrap items-center justify-end gap-2 mt-2 sm:w-auto sm:mt-0">
                <button
                  v-if="podeEmitirNota"
                  type="button"
                  class="text-green-700 hover:text-green-900 text-sm font-medium"
                  @click="abrirEmissaoFiscal([workOrder])"
                >
                  Emitir NFS-e
                </button>
                <Link
                  :href="route('work-orders.show', workOrder.id)"
                  class="text-green-600 hover:text-green-900 text-sm font-medium"
                >
                  Ver Detalhes
                </Link>
                <Link
                  v-if="pode('ordem-servico-editar')"
                  :href="route('work-orders.edit', workOrder.id)"
                  class="text-blue-600 hover:text-blue-900 text-sm font-medium"
                >
                  Editar
                </Link>
                <button
                  v-if="pode('ordem-servico-excluir')"
                  @click="deleteWorkOrder(workOrder)"
                  class="inline-flex items-center text-red-600 hover:text-red-900 text-sm font-medium"
                  title="Excluir ordem de serviço"
                >
                  <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                  </svg>
                  Excluir
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Paginação -->
        <Pagination
          v-if="workOrders.data.length > 0"
          :links="workOrders.links"
          class="mt-6"
        />
      </div>
    </Card>

    <!-- Modal de confirmação de exclusão -->
    <ConfirmDeleteModal
      :show="!!workOrderParaExcluir"
      :message="mensagemExclusaoWorkOrder"
      :processing="excluindoWorkOrder"
      @confirm="confirmarExclusaoWorkOrder"
      @cancel="cancelarExclusaoWorkOrder"
    />

    <Modal :show="mostrarModalNota" @close="fecharEmissaoFiscal">
      <template #icon>
        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6M9 8h6m2 13H7a2 2 0 01-2-2V5a2 2 0 012-2h7l5 5v11a2 2 0 01-2 2z" />
        </svg>
      </template>
      <template #title>Emitir notas das ordens</template>
      <template #content>
        <p v-if="resultadosNota.length === 0" class="text-sm text-gray-700">
          Será solicitada uma NFS-e para cada uma das {{ ordensParaNota.length }} ordem(ns). O resultado aparecerá por item.
        </p>
        <ul v-else class="max-h-72 space-y-2 overflow-y-auto">
          <li v-for="item in resultadosNota" :key="item.id" :class="['rounded-md border p-3 text-sm', classeResultadoFiscal(item.estado)]">
            <p :class="['font-medium', classeTextoResultadoFiscal(item.estado)]">{{ item.rotulo }}</p>
            <p :class="classeDetalheResultadoFiscal(item.estado)">{{ item.mensagem }}</p>
          </li>
        </ul>
      </template>
      <template #actions>
        <template v-if="resultadosNota.length === 0">
          <button type="button" class="btn-secondary" :disabled="emitindoNota" @click="fecharEmissaoFiscal">Voltar</button>
          <button type="button" class="btn-primary ml-3" :disabled="emitindoNota" @click="confirmarEmissaoFiscal">
            {{ emitindoNota ? 'Emitindo...' : 'Emitir NFS-e' }}
          </button>
        </template>
        <button v-else type="button" class="btn-primary" @click="fecharEmissaoFiscal">Fechar</button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Pagination from '@/Components/Pagination.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import Modal from '@/Components/Modal.vue';
import { formatarData } from '@/utils/formatDate';
import { usePermissoes } from '@/Composables/usePermissoes';
import { useModulos } from '@/Composables/useModulos';

const props = defineProps({
  workOrders: Object,
  filters: Object,
  clients: Array,
  addresses: Array,
  technicians: Array,
  services: Array,
});

const { pode } = usePermissoes();
const { temModulo } = useModulos();
const podeEmitirNota = computed(() => pode('fiscal-emitir') && temModulo('nfse'));

const filters = ref({
  client_id: props.filters?.client_id || '',
  address_id: props.filters?.address_id || '',
  technician_id: props.filters?.technician_id || '',
  status: props.filters?.status || '',
  priority_level: props.filters?.priority_level || '',
  service_id: props.filters?.service_id || '',
  date_from: props.filters?.date_from || '',
  date_to: props.filters?.date_to || '',
});

const applyFilters = () => {
  router.get(route('work-orders.index'), filters.value, {
    preserveState: true,
    preserveScroll: true,
  });
};

const clearFilters = () => {
  filters.value = {
    client_id: '',
    address_id: '',
    technician_id: '',
    status: '',
    priority_level: '',
    service_id: '',
    date_from: '',
    date_to: '',
  };
  router.get(route('work-orders.index'));
};

// Estado do modal de confirmação de exclusão
const workOrderParaExcluir = ref(null);
const excluindoWorkOrder = ref(false);

// A mensagem original tinha quebras de linha com dados do cliente e da data;
// o modal renderiza texto simples, então mantemos tudo em uma única string
// (a linha "Esta ação não pode ser desfeita" já aparece fixa no modal, então
// não é repetida aqui).
const mensagemExclusaoWorkOrder = computed(() => {
  const workOrder = workOrderParaExcluir.value;
  if (!workOrder) return '';

  return `Tem certeza que deseja excluir a ordem de serviço ${workOrder.order_number}?\n\nCliente: ${workOrder.client?.name}\nData: ${formatarData(workOrder.scheduled_date)}`;
});

const deleteWorkOrder = (workOrder) => {
  workOrderParaExcluir.value = workOrder;
};

const confirmarExclusaoWorkOrder = () => {
  excluindoWorkOrder.value = true;

  router.delete(route('work-orders.destroy', workOrderParaExcluir.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      // Sucesso - a página será recarregada automaticamente
    },
    onError: (errors) => {
      alert('Erro ao excluir ordem de serviço: ' + (errors.message || 'Erro desconhecido'));
    },
    onFinish: () => {
      excluindoWorkOrder.value = false;
      workOrderParaExcluir.value = null;
    }
  });
};

const cancelarExclusaoWorkOrder = () => {
  workOrderParaExcluir.value = null;
};

const selecionadas = ref(new Set());
const ordensSelecionadas = computed(() => props.workOrders.data.filter((ordem) => selecionadas.value.has(ordem.id)));

function alternarSelecaoFiscal(ordem) {
  const nova = new Set(selecionadas.value);
  if (nova.has(ordem.id)) nova.delete(ordem.id);
  else nova.add(ordem.id);
  selecionadas.value = nova;
}

function limparSelecaoFiscal() {
  selecionadas.value = new Set();
}

const mostrarModalNota = ref(false);
const ordensParaNota = ref([]);
const resultadosNota = ref([]);
const emitindoNota = ref(false);

function abrirEmissaoFiscal(ordens) {
  ordensParaNota.value = ordens;
  resultadosNota.value = [];
  mostrarModalNota.value = true;
}

function fecharEmissaoFiscal() {
  if (emitindoNota.value) return;
  const concluiu = resultadosNota.value.length > 0;
  mostrarModalNota.value = false;
  resultadosNota.value = [];
  if (concluiu) limparSelecaoFiscal();
}

function estadoDoRetornoFiscal(dados) {
  if (dados.resultado_fiscal === 'erro' || dados.nota?.situacao === 'erro') return 'erro';
  if (dados.resultado_fiscal === 'pendente' || ['pendente', 'processando'].includes(dados.nota?.situacao)) return 'pendente';
  return 'concluido';
}

function classeResultadoFiscal(estado) {
  return { concluido: 'border-green-200 bg-green-50', pendente: 'border-yellow-200 bg-yellow-50', erro: 'border-red-200 bg-red-50' }[estado];
}

function classeTextoResultadoFiscal(estado) {
  return { concluido: 'text-green-900', pendente: 'text-yellow-900', erro: 'text-red-900' }[estado];
}

function classeDetalheResultadoFiscal(estado) {
  return { concluido: 'text-green-700', pendente: 'text-yellow-800', erro: 'text-red-700' }[estado];
}

async function confirmarEmissaoFiscal() {
  if (emitindoNota.value || ordensParaNota.value.length === 0) return;
  emitindoNota.value = true;
  const resultados = [];

  for (const ordem of ordensParaNota.value) {
    try {
      const resposta = await fetch('/notas', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
          Accept: 'application/json',
        },
        body: JSON.stringify({ work_order_id: ordem.id }),
      });
      const dados = await resposta.json().catch(() => ({}));
      resultados.push({
        id: ordem.id,
        rotulo: `${ordem.order_number} - ${ordem.client?.name || 'Cliente'}`,
        estado: resposta.ok ? estadoDoRetornoFiscal(dados) : 'erro',
        mensagem: Object.values(dados.errors || {}).flat()[0] || dados.message || 'A nota não pôde ser emitida.',
      });
    } catch (erro) {
      resultados.push({ id: ordem.id, rotulo: ordem.order_number, estado: 'erro', mensagem: 'Falha de comunicação ao solicitar a nota.' });
    }
  }

  resultadosNota.value = resultados;
  emitindoNota.value = false;
}

watch(() => props.workOrders.data, (atuais) => {
  const idsAtuais = new Set(atuais.map((ordem) => ordem.id));
  selecionadas.value = new Set([...selecionadas.value].filter((id) => idsAtuais.has(id)));
  ordensParaNota.value = ordensParaNota.value.filter((ordem) => idsAtuais.has(ordem.id));

  if (mostrarModalNota.value && resultadosNota.value.length === 0 && ordensParaNota.value.length === 0) {
    mostrarModalNota.value = false;
  }
}, { immediate: true });
</script>
