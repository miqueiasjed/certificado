<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Fluxo de Caixa"
        description="Histórico completo de entradas e saídas com resumo de caixa">
        <template #actions>
          <button
            @click="exportData"
            class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
          >
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            Exportar
          </button>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-5 gap-4">
          <!-- Tipo -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
            <select v-model="filters.type" @change="loadEntries" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
              <option value="">Todos os tipos</option>
              <option value="payment">Pagamentos</option>
              <option value="withdrawal">Saídas</option>
              <option value="manual">Manuais</option>
            </select>
          </div>

          <!-- Método -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Método</label>
            <select v-model="filters.payment_method" @change="loadEntries" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm">
              <option value="">Todos</option>
              <option value="cash">Dinheiro</option>
              <option value="pix">PIX</option>
              <option value="credit_card">Cartão de Crédito</option>
              <option value="debit_card">Cartão de Débito</option>
              <option value="bank_transfer">Transferência Bancária</option>
            </select>
          </div>

          <!-- Data Inicial -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Data Inicial</label>
            <input
              v-model="filters.start_date"
              type="date"
              @change="loadEntries"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
            />
          </div>

          <!-- Data Final -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Data Final</label>
            <input
              v-model="filters.end_date"
              type="date"
              @change="loadEntries"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
            />
          </div>

          <!-- Buscar -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Buscar</label>
            <input
              v-model="filters.search"
              type="text"
              placeholder="Descrição, referência..."
              @input="debounceSearch"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
            />
          </div>
        </div>
      </div>

      <!-- Estatísticas -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        <div class="bg-white overflow-hidden shadow rounded-lg">
          <div class="p-5">
            <div class="flex items-center">
              <div class="flex-shrink-0">
                <svg class="h-6 w-6 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1" />
                </svg>
              </div>
              <div class="ml-5 w-0 flex-1">
                <dl>
                  <dt class="text-sm font-medium text-gray-500 truncate">Total Recebido</dt>
                  <dd class="text-lg font-medium text-gray-900">{{ formatCurrency(stats.total_received || 0) }}</dd>
                </dl>
              </div>
            </div>
          </div>
        </div>

        <div class="bg-white overflow-hidden shadow rounded-lg">
          <div class="p-5">
            <div class="flex items-center">
              <div class="flex-shrink-0">
                <svg class="h-6 w-6 text-blue-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                </svg>
              </div>
              <div class="ml-5 w-0 flex-1">
                <dl>
                  <dt class="text-sm font-medium text-gray-500 truncate">Pagamentos Efetivos</dt>
                  <dd class="text-lg font-medium text-gray-900">{{ formatCurrency(stats.effective_payment_amount || 0) }}</dd>
                </dl>
              </div>
            </div>
          </div>
        </div>

        <div class="bg-white overflow-hidden shadow rounded-lg">
          <div class="p-5">
            <div class="flex items-center">
              <div class="flex-shrink-0">
                <svg class="h-6 w-6 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4m16 0l-4-4m4 4l-4 4M4 12l4-4m-4 4l4 4" />
                </svg>
              </div>
              <div class="ml-5 w-0 flex-1">
                <dl>
                  <dt class="text-sm font-medium text-gray-500 truncate">Total de Saídas</dt>
                  <dd class="text-lg font-medium text-gray-900">{{ formatCurrency(stats.withdrawal_amount || 0) }}</dd>
                </dl>
              </div>
            </div>
          </div>
        </div>

        <div class="bg-white overflow-hidden shadow rounded-lg">
          <div class="p-5">
            <div class="flex items-center">
              <div class="flex-shrink-0">
                <svg class="h-6 w-6 text-purple-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                </svg>
              </div>
              <div class="ml-5 w-0 flex-1">
                <dl>
                  <dt class="text-sm font-medium text-gray-500 truncate">Período</dt>
                  <dd class="text-lg font-medium text-gray-900">
                    {{ formatDate(stats.period?.start_date) }} - {{ formatDate(stats.period?.end_date) }}
                  </dd>
                </dl>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Tabela -->
      <div class="bg-white shadow overflow-hidden sm:rounded-md">
        <div class="px-4 py-5 sm:px-6">
          <h3 class="text-lg leading-6 font-medium text-gray-900">Histórico Completo</h3>
          <p class="mt-1 max-w-2xl text-sm text-gray-500">Todas as movimentações financeiras registradas</p>
        </div>
        <ul class="divide-y divide-gray-200">
          <li v-for="entry in entries?.data || []" :key="entry.id" class="px-4 py-4 sm:px-6">
            <div class="flex items-center justify-between">
              <div class="flex items-center">
                <div class="flex-shrink-0">
                  <span :class="getTypeBadgeClass(entry.type)" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                    {{ getTypeText(entry.type) }}
                  </span>
                </div>
                <div class="ml-4">
                  <div class="text-sm font-medium text-gray-900">{{ entry.description }}</div>
                  <div class="text-sm text-gray-500">
                    {{ formatDate(entry.entry_date) }} • {{ getPaymentMethodText(entry.payment_method) }}
                    <span v-if="entry.reference_number">• Ref: {{ entry.reference_number }}</span>
                    <span v-if="entry.work_order_id">• OS #{{ entry.work_order_id }}</span>
                  </div>
                </div>
              </div>
              <div class="flex items-center space-x-4">
                <div class="text-right">
                  <div class="text-sm font-medium" :class="getAmountClass(entry.type, entry.amount)">
                    {{ getAmountPrefix(entry.type) }}{{ formatCurrency(entry.amount) }}
                  </div>
                  <div class="text-sm text-gray-500">
                    <span :class="getSourceBadgeClass(entry.source)" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium">
                      {{ getSourceText(entry.source) }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </li>
        </ul>

        <!-- Paginação -->
        <div v-if="entries && entries.last_page > 1" class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
          <div class="flex-1 flex justify-between sm:hidden">
            <Link
              v-if="entries && entries.prev_page_url"
              :href="entries.prev_page_url"
              class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
            >
              Anterior
            </Link>
            <Link
              v-if="entries && entries.next_page_url"
              :href="entries.next_page_url"
              class="ml-3 relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
            >
              Próximo
            </Link>
          </div>
          <div class="hidden sm:flex-1 sm:flex sm:items-center sm:justify-between">
            <div>
              <p class="text-sm text-gray-700">
                Mostrando
                <span class="font-medium">{{ entries?.from || 0 }}</span>
                até
                <span class="font-medium">{{ entries?.to || 0 }}</span>
                de
                <span class="font-medium">{{ entries?.total || 0 }}</span>
                resultados
              </p>
            </div>
            <div>
              <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                <Link
                  v-for="link in entries?.links || []"
                  :key="link.label"
                  :href="link.url"
                  v-html="link.label"
                  :class="[
                    'relative inline-flex items-center px-4 py-2 border text-sm font-medium',
                    link.active
                      ? 'z-10 bg-blue-50 border-blue-500 text-blue-600'
                      : 'bg-white border-gray-300 text-gray-500 hover:bg-gray-50',
                    link.url === null ? 'opacity-50 cursor-not-allowed' : ''
                  ]"
                />
              </nav>
            </div>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';

const props = defineProps({
  entries: {
    type: Object,
    default: () => ({ data: [], current_page: 1, last_page: 1, from: 0, to: 0, total: 0 })
  },
  stats: {
    type: Object,
    default: () => ({
      total_received: 0,
      effective_payment_amount: 0,
      payment_amount: 0,
      withdrawal_amount: 0,
      manual_amount: 0,
      net_amount: 0,
      total_entries: 0,
      by_type: {},
      by_method: {},
      daily_breakdown: [],
      period: { start_date: '', end_date: '' }
    })
  },
  filters: {
    type: Object,
    default: () => ({})
  }
});

// Reactive data
const searchTimeout = ref(null);

const filters = reactive({
  start_date: props.filters.start_date || '',
  end_date: props.filters.end_date || '',
  type: props.filters.type || '',
  payment_method: props.filters.payment_method || '',
  search: props.filters.search || ''
});

// Methods
const loadEntries = (page = 1) => {
  const params = new URLSearchParams({
    page: page,
    ...filters
  });

  router.get(`/cash-flow?${params}`, {}, {
    preserveState: true,
    preserveScroll: true
  });
};

const loadStats = () => {
  const params = new URLSearchParams(filters);

  fetch(`/cash-flow/stats?${params}`)
    .then(response => response.json())
    .then(data => {
      if (data.success) {
        Object.assign(props.stats, data.stats);
      }
    })
    .catch(error => {
      console.error('Erro ao carregar estatísticas:', error);
    });
};

const debounceSearch = () => {
  clearTimeout(searchTimeout.value);
  searchTimeout.value = setTimeout(() => {
    loadEntries();
    loadStats();
  }, 500);
};

const exportData = () => {
  const params = new URLSearchParams(filters);
  window.open(`/cash-flow/export?${params}`, '_blank');
};

// Utility functions
const formatCurrency = (value) => {
  if (!value) return 'R$ 0,00';
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(value);
};

const formatDate = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleDateString('pt-BR');
};

const getTypeText = (type) => {
  const types = {
    payment: 'Pagamento',
    withdrawal: 'Saída',
    manual: 'Manual'
  };
  return types[type] || type;
};

const getTypeBadgeClass = (type) => {
  const classes = {
    payment: 'bg-green-100 text-green-800',
    withdrawal: 'bg-red-100 text-red-800',
    manual: 'bg-blue-100 text-blue-800'
  };
  return `px-2 py-1 text-xs font-medium rounded-full ${classes[type] || 'bg-gray-100 text-gray-800'}`;
};

const getAmountClass = (type, amount) => {
  if (type === 'withdrawal') return 'text-red-600';
  return 'text-green-600';
};

const getAmountPrefix = (type) => {
  return type === 'withdrawal' ? '-' : '+';
};

const getPaymentMethodText = (method) => {
  const methods = {
    cash: 'Dinheiro',
    pix: 'PIX',
    credit_card: 'Cartão de Crédito',
    debit_card: 'Cartão de Débito',
    bank_transfer: 'Transferência Bancária'
  };
  return methods[method] || method;
};

const getPaymentMethodBadgeClass = (method) => {
  const classes = {
    cash: 'bg-green-100 text-green-800',
    pix: 'bg-blue-100 text-blue-800',
    credit_card: 'bg-purple-100 text-purple-800',
    debit_card: 'bg-indigo-100 text-indigo-800',
    bank_transfer: 'bg-gray-100 text-gray-800'
  };
  return `px-2 py-1 text-xs font-medium rounded-full ${classes[method] || 'bg-gray-100 text-gray-800'}`;
};

const getSourceText = (source) => {
  const sources = {
    work_order: 'Ordem de Serviço',
    payment_reopen: 'Reabertura de Pagamento',
    manual: 'Manual'
  };
  return sources[source] || source;
};

const getSourceBadgeClass = (source) => {
  const classes = {
    work_order: 'bg-blue-100 text-blue-800',
    payment_reopen: 'bg-orange-100 text-orange-800',
    manual: 'bg-gray-100 text-gray-800'
  };
  return `px-2 py-1 text-xs font-medium rounded-full ${classes[source] || 'bg-gray-100 text-gray-800'}`;
};

const getBalanceClass = (amount) => {
  if (amount > 0) return 'text-green-600';
  if (amount < 0) return 'text-red-600';
  return 'text-gray-600';
};

// Lifecycle
onMounted(() => {
  loadStats();
});
</script>
