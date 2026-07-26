<template>
  <AuthenticatedLayout>
    <PageHeader
      title="Dispositivos"
      description="Gerencie todos os dispositivos do sistema"
    >
      <template #actions>
        <Link :href="route('devices.create')" class="btn-primary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
          </svg>
          Novo Dispositivo
        </Link>
      </template>
    </PageHeader>

    <!-- Filtros -->
    <Card class="mb-6">
      <div class="p-6">
        <form @submit.prevent="applyFilters" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
          <!-- Cliente -->
          <div>
            <label for="client_id" class="block text-sm font-medium text-gray-700 mb-2">
              Cliente
            </label>
            <select
              id="client_id"
              v-model="filters.client_id"
              @change="onClientChange"
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
              :disabled="!filters.client_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
            >
              <option value="">Todos os endereços</option>
              <option v-for="address in filteredAddresses" :key="address.id" :value="address.id">
                {{ address.nickname }} - {{ address.street }}, {{ address.number }}
              </option>
            </select>
          </div>

          <!-- Tipo de Isca -->
          <div>
            <label for="bait_type_id" class="block text-sm font-medium text-gray-700 mb-2">
              Tipo de Isca
            </label>
            <select
              id="bait_type_id"
              v-model="filters.bait_type_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todos os tipos</option>
              <option v-for="baitType in baitTypes" :key="baitType.id" :value="baitType.id">
                {{ baitType.name }} - {{ baitType.brand }}
              </option>
            </select>
          </div>

          <!-- Situação: filtro aplicado no frontend, ver nota abaixo do campo -->
          <div>
            <label for="situacao" class="block text-sm font-medium text-gray-700 mb-2">
              Situação
            </label>
            <select
              id="situacao"
              v-model="filtroSituacao"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Todas</option>
              <option value="ativo">Ativo</option>
              <option value="substituido">Substituído</option>
              <option value="removido">Removido</option>
            </select>
            <p class="mt-1 text-xs text-gray-500">
              Filtra só os dispositivos já carregados nesta página.
            </p>
          </div>

          <!-- Botões de Filtro -->
          <div class="md:col-span-2 lg:col-span-4 flex justify-end space-x-3">
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

    <!-- Lista de Dispositivos -->
    <Card>
      <div class="p-6">
        <!-- Barra de ações em lote -->
        <div class="mb-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-gray-200 pb-4">
          <p class="text-sm text-gray-600">
            {{ selecionados.length }} selecionado(s)
          </p>

          <div class="flex flex-col sm:flex-row gap-2">
            <button
              v-if="pode('dispositivo-criar')"
              type="button"
              class="btn-secondary"
              :disabled="!enderecoParaLote"
              :title="enderecoParaLote ? '' : 'Filtre por um único endereço para cadastrar em lote'"
              @click="mostrarModalLote = true"
            >
              Cadastrar em lote
            </button>

            <button
              type="button"
              class="btn-secondary"
              :disabled="!podeImprimirSelecionados"
              :title="dicaImpressao"
              @click="imprimirEtiquetasSelecionados"
            >
              Imprimir etiquetas
            </button>
          </div>
        </div>

        <p v-if="dicaImpressao" class="mb-4 text-xs text-gray-500">
          {{ dicaImpressao }}
        </p>

        <div v-if="devicesFiltrados.length === 0" class="text-center py-8">
          <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
          </svg>
          <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum dispositivo encontrado</h3>
          <p class="mt-1 text-sm text-gray-500">
            {{ devices.data.length === 0 ? 'Comece criando um novo dispositivo.' : 'Nenhum dispositivo corresponde ao filtro de situação.' }}
          </p>
        </div>

        <div v-else class="space-y-4">
          <div
            v-for="device in devicesFiltrados"
            :key="device.id"
            class="border border-gray-200 rounded-lg p-4 hover:bg-gray-50 transition-colors"
          >
            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
              <div class="flex-1 w-full">
                <div class="flex items-start space-x-3">
                  <!-- Seleção -->
                  <input
                    type="checkbox"
                    class="mt-1 h-5 w-5 rounded border-gray-300 text-green-600 focus:ring-green-500 flex-shrink-0"
                    :checked="selecionados.includes(device.id)"
                    aria-label="Selecionar dispositivo"
                    @change="alternarSelecao(device.id)"
                  />

                  <!-- Ícone do dispositivo -->
                  <div class="flex-shrink-0">
                    <div class="h-8 w-8 rounded-full flex items-center justify-center" :class="ehAtivo(device) ? 'bg-green-100' : 'bg-gray-100'">
                      <svg class="h-4 w-4" :class="ehAtivo(device) ? 'text-green-600' : 'text-gray-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                      </svg>
                    </div>
                  </div>

                  <!-- Informações do dispositivo -->
                  <div class="flex-1 min-w-0">
                    <div class="flex flex-wrap items-center gap-2">
                      <h3 class="text-sm font-medium text-gray-900">
                        {{ device.label }} ({{ device.number }})
                      </h3>
                      <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-mono font-medium bg-gray-100 text-gray-800">
                        {{ device.codigo_publico }}
                      </span>
                      <span v-if="device.bait_type" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                        {{ device.bait_type.name }}
                      </span>
                      <span v-if="!ehAtivo(device)" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                        {{ situacaoTexto(device) }}
                      </span>
                    </div>
                    <p class="text-sm text-gray-500">
                      {{ device.address?.client?.name }} - {{ device.address?.street }}, {{ device.address?.number }}
                    </p>
                    <p class="text-sm text-gray-500">
                      {{ device.address?.city }}/{{ device.address?.state }}
                    </p>
                  </div>
                </div>
              </div>

              <!-- Ações -->
              <div class="flex items-center space-x-2 w-full sm:w-auto justify-end mt-2 sm:mt-0">
                <Link
                  :href="route('devices.show', device.id)"
                  class="text-green-600 hover:text-green-900 text-sm font-medium"
                >
                  Ver Detalhes
                </Link>
                <Link
                  v-if="ehAtivo(device)"
                  :href="`${route('devices.edit', device.id)}?return_url=${encodeURIComponent($page.url)}`"
                  class="text-blue-600 hover:text-blue-900 text-sm font-medium"
                >
                  Editar
                </Link>
                <button
                  v-if="ehAtivo(device)"
                  @click="checkAndDeleteDevice(device)"
                  class="text-red-600 hover:text-red-900 text-sm font-medium disabled:text-gray-400 disabled:cursor-not-allowed"
                  :disabled="isCheckingDelete"
                >
                  {{ isCheckingDelete ? 'Verificando...' : 'Excluir' }}
                </button>
              </div>
            </div>
          </div>
        </div>

        <!-- Paginação -->
        <Pagination
          v-if="devices.data.length > 0"
          :links="devices.links"
          class="mt-6"
        />
      </div>
    </Card>

    <!-- Modal de Confirmação de Exclusão -->
    <ConfirmDeleteModal
      :show="!!deviceParaExcluir"
      message="Tem certeza que deseja excluir o dispositivo"
      :item-name="deviceParaExcluir ? `${deviceParaExcluir.label} (${deviceParaExcluir.number})` : ''"
      :processing="isCheckingDelete"
      @confirm="confirmarExclusaoDevice"
      @cancel="deviceParaExcluir = null"
    />

    <!-- Modal de Cadastro em Lote -->
    <DeviceBatchModal
      :show="mostrarModalLote"
      :address="enderecoParaLote"
      :bait-types="baitTypes"
      @close="mostrarModalLote = false"
      @criado="aoCriarLote"
    />
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
import DeviceBatchModal from '@/Components/DeviceBatchModal.vue';
import { usePermissoes } from '@/Composables/usePermissoes';

const props = defineProps({
  devices: Object,
  filters: Object,
  clients: Array,
  addresses: Array,
  baitTypes: Array,
});

const { pode } = usePermissoes();

const filters = ref({
  client_id: props.filters?.client_id || '',
  address_id: props.filters?.address_id || '',
  bait_type_id: props.filters?.bait_type_id || '',
});

// Filtro de situação: aplicado só no array já carregado no cliente. O
// `index()` do DeviceController não recebe esse filtro nesta task (o backend
// não foi alterado), então uma página paginada pode ter dispositivos da
// situação escondida sem aparecer aqui até o usuário mudar de página. Levar
// isso ao backend é melhoria de uma próxima task, não desta.
const filtroSituacao = ref('ativo');

function ehAtivo(device) {
  return (device.situacao || 'ativo') === 'ativo';
}

function situacaoTexto(device) {
  return device.situacao === 'removido' ? 'Removido' : 'Substituído';
}

const devicesFiltrados = computed(() => {
  if (!filtroSituacao.value) return props.devices.data;
  return props.devices.data.filter((device) => (device.situacao || 'ativo') === filtroSituacao.value);
});

// Filtrar endereços baseado no cliente selecionado
const filteredAddresses = computed(() => {
  if (!filters.value.client_id) return props.addresses || [];
  return (props.addresses || []).filter(address => address.client_id == filters.value.client_id);
});

// Limpar endereço quando cliente muda
const onClientChange = () => {
  filters.value.address_id = '';
};

const applyFilters = () => {
  router.get(route('devices.index'), filters.value, {
    preserveState: true,
    preserveScroll: true,
  });
};

const clearFilters = () => {
  filters.value = {
    client_id: '',
    address_id: '',
    bait_type_id: '',
  };
  filtroSituacao.value = 'ativo';
  router.get(route('devices.index'));
};

// --- Seleção múltipla e impressão de etiquetas ---

const selecionados = ref([]);

function alternarSelecao(deviceId) {
  const indice = selecionados.value.indexOf(deviceId);
  if (indice === -1) {
    selecionados.value.push(deviceId);
  } else {
    selecionados.value.splice(indice, 1);
  }
}

const dispositivosSelecionados = computed(
  () => props.devices.data.filter((device) => selecionados.value.includes(device.id)),
);

const enderecosDosSelecionados = computed(
  () => new Set(dispositivosSelecionados.value.map((device) => device.address_id)),
);

// A folha de etiquetas é sempre por endereço (rota `addresses.devices.etiquetas`).
// Em vez de abrir uma aba por endereço quando a seleção mistura endereços
// diferentes, a escolha aqui é mais simples: exigir que a seleção seja de um
// único endereço, avisando o motivo quando o botão está desabilitado.
const podeImprimirSelecionados = computed(
  () => selecionados.value.length > 0 && enderecosDosSelecionados.value.size === 1,
);

const dicaImpressao = computed(() => {
  if (selecionados.value.length === 0) {
    return 'Selecione ao menos um dispositivo para imprimir etiquetas.';
  }
  if (enderecosDosSelecionados.value.size > 1) {
    return 'Selecione dispositivos de um único endereço para imprimir juntos.';
  }
  return '';
});

function abrirFolhaDeEtiquetas(addressId, deviceIds) {
  const parametros = new URLSearchParams();
  deviceIds.forEach((id) => parametros.append('device_ids[]', id));

  const url = `${route('addresses.devices.etiquetas', addressId)}?${parametros.toString()}`;
  window.open(url, '_blank');
}

function imprimirEtiquetasSelecionados() {
  if (!podeImprimirSelecionados.value) return;

  const addressId = dispositivosSelecionados.value[0].address_id;
  abrirFolhaDeEtiquetas(addressId, selecionados.value);
}

// --- Cadastro em lote ---

const mostrarModalLote = ref(false);

// Endereço usado pelo lote: o filtro de endereço já existente na tela. Sem um
// endereço filtrado o botão fica desabilitado, porque o lote precisa saber
// em qual endereço os dispositivos nascem.
const enderecoParaLote = computed(() => {
  if (!filters.value.address_id) return null;
  return (props.addresses || []).find((address) => address.id == filters.value.address_id) || null;
});

function aoCriarLote() {
  // Recarrega só a prop `devices`, preservando os filtros aplicados na URL,
  // para os dispositivos recém-criados aparecerem na lista sem perder a
  // posição de navegação.
  router.reload({ only: ['devices'] });
}

// --- Exclusão (comportamento existente, sem mudanças de regra) ---

const isCheckingDelete = ref(false);
const deviceParaExcluir = ref(null);

const checkAndDeleteDevice = (device) => {
  deviceParaExcluir.value = device;
};

const confirmarExclusaoDevice = async () => {
  const device = deviceParaExcluir.value;
  if (!device) return;

  isCheckingDelete.value = true;

  try {
    const response = await fetch(route('devices.can-delete', device.id), {
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      }
    });

    const result = await response.json();

    if (!result.can_delete) {
      alert(result.message);
      return;
    }

    router.delete(route('devices.destroy', device.id), {
      preserveScroll: true,
      onSuccess: () => {
        // A página será automaticamente atualizada pelo Inertia após o redirect
      },
      onError: (errors) => {
        alert('Erro ao excluir dispositivo: ' + (errors.message || 'Erro desconhecido'));
      }
    });

  } catch (error) {
    console.error('Erro ao verificar exclusão:', error);
    alert('Erro ao verificar se o dispositivo pode ser excluído');
  } finally {
    isCheckingDelete.value = false;
    deviceParaExcluir.value = null;
  }
};
</script>
