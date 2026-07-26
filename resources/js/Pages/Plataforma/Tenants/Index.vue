<template>
  <PlataformaLayout>
    <template #header>
      <PageHeader title="Tenants" description="Empresas cadastradas na plataforma">
        <template #actions>
          <Link :href="route('plataforma.tenants.create')" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            Novo tenant
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <!-- Mensagens flash -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!-- Filtros -->
      <Card>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
          <div class="sm:col-span-2">
            <label for="busca" class="block text-sm font-medium text-gray-700 mb-1">Buscar</label>
            <input
              id="busca"
              v-model="busca"
              type="text"
              @input="buscaComDebounce"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm"
              placeholder="Nome ou CNPJ..."
            />
          </div>

          <div>
            <label for="situacao" class="block text-sm font-medium text-gray-700 mb-1">Situação</label>
            <select
              id="situacao"
              v-model="situacaoFiltro"
              @change="aplicarFiltros"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm"
            >
              <option value="">Todas</option>
              <option v-for="situacao in situacoes" :key="situacao" :value="situacao">
                {{ ROTULOS_SITUACAO[situacao] || situacao }}
              </option>
            </select>
          </div>

          <div>
            <label for="plano" class="block text-sm font-medium text-gray-700 mb-1">Plano</label>
            <select
              id="plano"
              v-model="planoFiltro"
              @change="aplicarFiltros"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm"
            >
              <option value="">Todos</option>
              <option v-for="plano in planos" :key="plano.id" :value="plano.id">
                {{ plano.nome }}
              </option>
            </select>
          </div>
        </div>
      </Card>

      <!-- Lista -->
      <Card padding="none">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  <button type="button" class="flex items-center gap-1 hover:text-gray-700" @click="ordenarPor('name')">
                    Tenant
                    <span v-if="filtros.ordenar_por === 'name'">{{ filtros.direcao === 'asc' ? '▲' : '▼' }}</span>
                  </button>
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  CNPJ
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Plano
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Situação
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  <button type="button" class="flex items-center gap-1 hover:text-gray-700" @click="ordenarPor('created_at')">
                    Entrada
                    <span v-if="filtros.ordenar_por === 'created_at'">{{ filtros.direcao === 'asc' ? '▲' : '▼' }}</span>
                  </button>
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  <button type="button" class="flex items-center gap-1 hover:text-gray-700" @click="ordenarPor('ultimo_acesso_em')">
                    Último acesso
                    <span v-if="filtros.ordenar_por === 'ultimo_acesso_em'">{{ filtros.direcao === 'asc' ? '▲' : '▼' }}</span>
                  </button>
                </th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Ações
                </th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="tenant in tenants.data" :key="tenant.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="flex items-center gap-2">
                    <span class="text-sm font-medium text-gray-900">{{ tenant.name }}</span>
                    <span
                      v-if="tenant.is_internal"
                      class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-200 text-slate-700"
                    >
                      Interno
                    </span>
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                  {{ tenant.cnpj || '-' }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                  {{ tenant.plan?.nome || 'Sem plano' }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                    :class="CLASSES_SITUACAO[tenant.situacao] || CLASSES_SITUACAO.cancelada"
                  >
                    {{ ROTULOS_SITUACAO[tenant.situacao] || tenant.situacao }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                  {{ formatarData(tenant.created_at) }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                  {{ tenant.ultimo_acesso_em ? formatarDataHora(tenant.ultimo_acesso_em) : 'Nunca' }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                  <div class="flex items-center justify-end gap-3">
                    <Link
                      :href="route('plataforma.tenants.edit', tenant.id)"
                      class="text-blue-600 hover:text-blue-900 transition-colors"
                      title="Editar"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                      </svg>
                    </Link>
                    <Link
                      :href="route('plataforma.tenants.uso', tenant.id)"
                      class="text-purple-600 hover:text-purple-900 transition-colors"
                      title="Ver uso"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"></path>
                      </svg>
                    </Link>
                    <button
                      type="button"
                      class="text-amber-600 hover:text-amber-900 transition-colors"
                      title="Assumir"
                      @click="pedirAssumir(tenant)"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 16l-4-4m0 0l4-4m-4 4h14m-5 4v1a3 3 0 01-3 3H6a3 3 0 01-3-3V7a3 3 0 013-3h7a3 3 0 013 3v1"></path>
                      </svg>
                    </button>
                    <button
                      v-if="tenant.situacao === 'suspensa'"
                      type="button"
                      class="text-green-600 hover:text-green-900 transition-colors"
                      title="Reativar"
                      @click="reativar(tenant)"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                      </svg>
                    </button>
                    <button
                      v-else-if="!tenant.is_internal"
                      type="button"
                      class="text-red-600 hover:text-red-900 transition-colors"
                      title="Suspender"
                      @click="pedirSuspensao(tenant)"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.36 6.64a9 9 0 11-12.73 0M12 3v9"></path>
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>

          <!-- Estado vazio -->
          <div v-if="!tenants.data || tenants.data.length === 0" class="text-center py-12">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2M19 21H5m0 0H3m8-16h.01M11 11h.01M11 15h.01M15 7h.01M15 11h.01M15 15h.01" />
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum tenant encontrado</h3>
            <p class="mt-1 text-sm text-gray-500">Ajuste os filtros ou cadastre um novo tenant.</p>
          </div>
        </div>
      </Card>

      <!-- Paginação -->
      <div v-if="tenants.links && tenants.links.length > 3">
        <Pagination :links="tenants.links" />
      </div>
    </div>

    <!-- Modal de suspensão: pede o motivo, obrigatório -->
    <Modal :show="!!tenantParaSuspender" @close="cancelarSuspensao">
      <template #icon>
        <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.36 6.64a9 9 0 11-12.73 0M12 3v9"></path>
        </svg>
      </template>
      <template #title>
        Suspender tenant
      </template>
      <template #content>
        <p class="text-sm text-gray-700 mb-4">
          Suspender <strong>"{{ tenantParaSuspender?.name }}"</strong> tira o acesso da empresa ao sistema
          imediatamente, sem apagar nenhum dado dela. O motivo fica registrado e aparece para quem for reativar.
        </p>
        <label for="motivo" class="block text-sm font-medium text-gray-700 mb-1">Motivo *</label>
        <textarea
          id="motivo"
          v-model="suspensaoForm.motivo"
          rows="3"
          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          :class="{ 'border-red-500': suspensaoForm.errors.motivo }"
          placeholder="Descreva o motivo da suspensão..."
        ></textarea>
        <p v-if="suspensaoForm.errors.motivo" class="mt-1 text-sm text-red-600">
          {{ suspensaoForm.errors.motivo }}
        </p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="suspensaoForm.processing" @click="cancelarSuspensao">
          Cancelar
        </button>
        <button
          type="button"
          class="btn-danger disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="suspensaoForm.processing || !suspensaoForm.motivo.trim()"
          @click="confirmarSuspensao"
        >
          {{ suspensaoForm.processing ? 'Suspendendo...' : 'Suspender' }}
        </button>
      </template>
    </Modal>

    <!-- Modal de confirmação para assumir o tenant -->
    <ConfirmDeleteModal
      :show="!!tenantParaAssumir"
      variant="warning"
      title="Assumir tenant"
      subtitle="A entrada fica registrada em auditoria."
      :message="`Você vai entrar como suporte na empresa`"
      :item-name="tenantParaAssumir?.name"
      confirm-text="Assumir"
      cancel-text="Cancelar"
      processing-text="Entrando..."
      :processing="assumindo"
      @confirm="confirmarAssumir"
      @cancel="tenantParaAssumir = null"
    />
  </PlataformaLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import PlataformaLayout from '@/Layouts/PlataformaLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import { formatarData, formatarDataHora } from '@/utils/formatDate';

const props = defineProps({
  tenants: Object,
  planos: {
    type: Array,
    default: () => [],
  },
  situacoes: {
    type: Array,
    default: () => [],
  },
  filtros: {
    type: Object,
    default: () => ({}),
  },
});

const $page = usePage();

const ROTULOS_SITUACAO = {
  ativa: 'Ativa',
  suspensa: 'Suspensa',
  em_avaliacao: 'Em avaliação',
  cancelada: 'Cancelada',
};

const CLASSES_SITUACAO = {
  ativa: 'bg-green-100 text-green-800',
  suspensa: 'bg-red-100 text-red-800',
  em_avaliacao: 'bg-yellow-100 text-yellow-800',
  cancelada: 'bg-gray-100 text-gray-800',
};

// Estado local dos filtros, inicializado com o que o backend já aplicou, para
// a tela não "esquecer" o filtro ao recarregar a página com a paginação.
const busca = ref(props.filtros.busca || '');
const situacaoFiltro = ref(props.filtros.situacao || '');
const planoFiltro = ref(props.filtros.plan_id || '');

let temporizadorBusca = null;

const buscaComDebounce = () => {
  if (temporizadorBusca) {
    clearTimeout(temporizadorBusca);
  }

  temporizadorBusca = setTimeout(() => {
    aplicarFiltros();
  }, 300);
};

const aplicarFiltros = () => {
  router.get(route('plataforma.tenants.index'), {
    busca: busca.value || undefined,
    situacao: situacaoFiltro.value || undefined,
    plan_id: planoFiltro.value || undefined,
    ordenar_por: props.filtros.ordenar_por,
    direcao: props.filtros.direcao,
  }, {
    preserveState: true,
    replace: true,
  });
};

const ordenarPor = (coluna) => {
  const mesmaColuna = props.filtros.ordenar_por === coluna;
  const novaDirecao = mesmaColuna && props.filtros.direcao === 'asc' ? 'desc' : 'asc';

  router.get(route('plataforma.tenants.index'), {
    busca: busca.value || undefined,
    situacao: situacaoFiltro.value || undefined,
    plan_id: planoFiltro.value || undefined,
    ordenar_por: coluna,
    direcao: novaDirecao,
  }, {
    preserveState: true,
    replace: true,
  });
};

// Suspensão: exige motivo, nunca `confirm()` nativo.
const tenantParaSuspender = ref(null);
const suspensaoForm = useForm({ motivo: '' });

const pedirSuspensao = (tenant) => {
  suspensaoForm.reset();
  suspensaoForm.clearErrors();
  tenantParaSuspender.value = tenant;
};

const cancelarSuspensao = () => {
  if (suspensaoForm.processing) return;

  tenantParaSuspender.value = null;
  suspensaoForm.clearErrors();
};

const confirmarSuspensao = () => {
  if (!suspensaoForm.motivo.trim()) return;

  suspensaoForm.post(route('plataforma.tenants.suspender', tenantParaSuspender.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      tenantParaSuspender.value = null;
    },
  });
};

// Reativar não é destrutivo: ação direta, sem modal.
const reativar = (tenant) => {
  router.post(route('plataforma.tenants.reativar', tenant.id), {}, { preserveScroll: true });
};

// Assumir: confirmação porque a entrada fica registrada em auditoria e o
// super admin passa a ver dado de cliente real.
const tenantParaAssumir = ref(null);
const assumindo = ref(false);

const pedirAssumir = (tenant) => {
  tenantParaAssumir.value = tenant;
};

const confirmarAssumir = () => {
  assumindo.value = true;

  router.post(route('plataforma.tenants.assumir', tenantParaAssumir.value.id), {}, {
    onFinish: () => {
      assumindo.value = false;
      tenantParaAssumir.value = null;
    },
  });
};
</script>
