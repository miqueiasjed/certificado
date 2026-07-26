<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Contratos" description="Gerenciar contratos de dedetização">
        <template #actions>
          <Link v-if="pode('contrato-ver')" href="/contracts/pendencias" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
            </svg>
            Pendências
          </Link>
          <Link v-if="pode('contrato-criar')" href="/contracts/create" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            Novo Contrato
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-7xl mx-auto">
      <!-- Mensagens Flash: a rejeição de exclusão de contrato com visita executada
           chega aqui como flash.error, e precisa aparecer, não sumir em silêncio. -->
      <div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4 mb-6">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash.error" class="bg-red-50 border border-red-200 rounded-md p-4 mb-6">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!-- Filtros e Busca -->
      <Card class="mb-6">
        <div class="p-6">
          <div class="flex flex-col md:flex-row gap-4">
            <div class="flex-1">
              <label for="search" class="block text-sm font-medium text-gray-700 mb-1">
                Buscar por Cliente
              </label>
              <input
                type="text"
                id="search"
                v-model="searchQuery"
                @input="debouncedSearch"
                placeholder="Digite o nome do cliente..."
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
              />
            </div>
            <div class="flex gap-2 items-end">
              <button
                @click="clearSearch"
                v-if="searchQuery"
                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-colors"
              >
                Limpar
              </button>
            </div>
          </div>
        </div>
      </Card>

      <!-- Lista de Contratos -->
      <Card>
        <div class="p-6">
          <div v-if="contracts.data.length === 0" class="text-center py-12">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum contrato encontrado</h3>
            <p class="mt-1 text-sm text-gray-500">
              {{ searchQuery ? 'Tente ajustar os termos de busca.' : 'Comece criando o primeiro contrato.' }}
            </p>
          </div>

          <div v-else class="space-y-4">
            <div
              v-for="contract in contracts.data"
              :key="contract.id"
              class="border border-gray-200 rounded-lg p-6 hover:shadow-md transition-shadow"
            >
              <div class="flex flex-col sm:flex-row sm:items-start justify-between gap-4">
                <div class="flex-1 w-full">
                  <div class="flex items-center gap-3 mb-2">
                    <h3 class="text-lg font-semibold text-gray-900">
                      {{ contract.contract_number || `Contrato #${contract.id}` }}
                    </h3>
                    <span
                      :class="[
                        'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                        contract.service_type === 'periodico' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'
                      ]"
                    >
                      {{ contract.service_type === 'periodico' ? 'Periódico' : 'Pontual' }}
                    </span>
                  </div>

                  <div class="text-sm text-gray-600 mb-3">
                    <p class="font-medium">{{ contract.address?.nickname || 'Endereço' }}</p>
                    <p>{{ contract.address?.street }}, {{ contract.address?.number }}</p>
                    <p>{{ contract.address?.city }}/{{ contract.address?.state }}</p>
                    <p class="mt-1">Cliente: {{ contract.address?.client?.name }}</p>
                  </div>

                  <div class="flex flex-wrap items-center gap-4 text-sm text-gray-500">
                    <div class="flex items-center gap-1">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                      </svg>
                      Início: {{ formatarData(contract.start_date) }}
                    </div>
                    <div v-if="contract.end_date" class="flex items-center gap-1">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
                      </svg>
                      Fim: {{ formatarData(contract.end_date) }}
                    </div>
                    <div v-if="contract.service_value" class="flex items-center gap-1">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
                      </svg>
                      R$ {{ formatCurrency(contract.service_value) }}
                    </div>
                  </div>
                </div>

                <div class="flex items-center gap-2 w-full sm:w-auto justify-end mt-2 sm:mt-0">
                  <button
                    @click="generateContractPDF(contract.address_id)"
                    class="px-3 py-1 text-sm text-purple-600 hover:text-purple-800 font-medium"
                  >
                    <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                    </svg>
                    PDF
                  </button>
                  <Link
                    v-if="pode('contrato-ver')"
                    :href="`/contracts/${contract.id}`"
                    class="px-3 py-1 text-sm text-blue-600 hover:text-blue-800 font-medium"
                  >
                    Ver
                  </Link>
                  <Link
                    v-if="pode('contrato-editar')"
                    :href="`/contracts/${contract.id}/edit`"
                    class="px-3 py-1 text-sm text-green-600 hover:text-green-800 font-medium"
                  >
                    Editar
                  </Link>
                  <button
                    v-if="pode('contrato-editar')"
                    @click="abrirEncerramento(contract)"
                    class="px-3 py-1 text-sm text-amber-600 hover:text-amber-800 font-medium"
                  >
                    Encerrar
                  </button>
                  <button
                    v-if="pode('contrato-excluir')"
                    @click="deleteContract(contract.id)"
                    class="px-3 py-1 text-sm text-red-600 hover:text-red-800 font-medium"
                  >
                    Excluir
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Paginação -->
          <Pagination
            v-if="contracts.data.length > 0"
            :links="contracts.links"
            class="mt-6"
          />
        </div>
      </Card>
    </div>

    <!-- Modal de Confirmação de Exclusão -->
    <ConfirmDeleteModal
      :show="!!contractIdParaExcluir"
      message="Tem certeza que deseja excluir este contrato?"
      :processing="excluindoContract"
      @confirm="confirmarExclusaoContract"
      @cancel="contractIdParaExcluir = null"
    />

    <!-- Modal de Encerramento de Contrato: cancela as visitas futuras não
         executadas e fecha a vigência. É o caminho que a recusa de exclusão
         (flash.error acima) indica quando o contrato tem visita já
         executada. -->
    <Modal :show="!!contratoParaEncerrar" @close="cancelarEncerramento">
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
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Pagination from '@/Components/Pagination.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import Modal from '@/Components/Modal.vue';
import { formatarData } from '@/utils/formatDate';
import { usePermissoes } from '@/Composables/usePermissoes';

const props = defineProps({
  contracts: Object,
  search: String,
});

const { pode } = usePermissoes();

const searchQuery = ref(props.search || '');

// Busca com debounce
let searchTimeout;
const debouncedSearch = () => {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    if (searchQuery.value) {
      router.get('/contracts', { search: searchQuery.value }, {
        preserveState: true,
        replace: true,
      });
    } else {
      router.get('/contracts', {}, {
        preserveState: true,
        replace: true,
      });
    }
  }, 300);
};

const clearSearch = () => {
  searchQuery.value = '';
  router.get('/contracts', {}, {
    preserveState: true,
    replace: true,
  });
};

const formatCurrency = (value) => {
  if (!value) return '0,00';
  return parseFloat(value).toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
};

const contractIdParaExcluir = ref(null);
const excluindoContract = ref(false);

const deleteContract = (id) => {
  contractIdParaExcluir.value = id;
};

const confirmarExclusaoContract = () => {
  excluindoContract.value = true;
  router.delete(`/contracts/${contractIdParaExcluir.value}`, {
    onFinish: () => {
      excluindoContract.value = false;
      contractIdParaExcluir.value = null;
    },
  });
};

const generateContractPDF = (addressId) => {
  const url = `/addresses/${addressId}/contract/pdf`;
  window.open(url, '_blank');
};

// Encerramento de contrato: cancela as visitas futuras não executadas e fecha
// a vigência (grava end_date). `visitas_futuras_count` já vem calculado pelo
// controller, para o aviso ser exato sem precisar de uma segunda requisição.
const contratoParaEncerrar = ref(null);
const motivoEncerramento = ref('');
const encerrandoContrato = ref(false);

const mensagemEncerramento = computed(() => {
  const quantidade = contratoParaEncerrar.value?.visitas_futuras_count ?? 0;

  if (quantidade === 0) {
    return 'Este contrato não tem visita futura agendada para cancelar. O encerramento só fecha a vigência.';
  }

  return quantidade === 1
    ? 'Isso cancela 1 visita futura ainda não executada deste contrato.'
    : `Isso cancela ${quantidade} visitas futuras ainda não executadas deste contrato.`;
});

const abrirEncerramento = (contract) => {
  contratoParaEncerrar.value = contract;
  motivoEncerramento.value = '';
};

const cancelarEncerramento = () => {
  if (encerrandoContrato.value) return;
  contratoParaEncerrar.value = null;
  motivoEncerramento.value = '';
};

const confirmarEncerramento = () => {
  encerrandoContrato.value = true;

  router.post(`/contracts/${contratoParaEncerrar.value.id}/encerrar`, {
    motivo: motivoEncerramento.value,
  }, {
    preserveScroll: true,
    onFinish: () => {
      encerrandoContrato.value = false;
      contratoParaEncerrar.value = null;
      motivoEncerramento.value = '';
    },
  });
};
</script>

