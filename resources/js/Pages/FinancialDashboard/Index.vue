<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Dashboard Financeiro"
        description="Visão geral das receitas e entradas financeiras">
        <div class="flex space-x-3">
          <Link
            href="/financial-entries"
            class="inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
          >
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
            </svg>
            Ver Entradas
          </Link>
          <button
            @click="showCreateModal = true"
            class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
          >
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Nova Entrada
          </button>
        </div>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
          <!-- Período -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Período</label>
            <select v-model="selectedPeriod" @change="updateDateRange" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm">
              <option value="this_month">Este Mês</option>
              <option value="last_month">Mês Anterior</option>
              <option value="this_year">Este Ano</option>
              <option value="last_year">Ano Anterior</option>
              <option value="custom">Personalizado</option>
            </select>
          </div>

          <!-- Data Inicial -->
          <div v-if="selectedPeriod === 'custom'">
            <label class="block text-sm font-medium text-gray-700 mb-1">Data Inicial</label>
            <input
              v-model="filters.start_date"
              type="date"
              @change="loadData"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
            />
          </div>

          <!-- Data Final -->
          <div v-if="selectedPeriod === 'custom'">
            <label class="block text-sm font-medium text-gray-700 mb-1">Data Final</label>
            <input
              v-model="filters.end_date"
              type="date"
              @change="loadData"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
            />
          </div>
        </div>
      </div>

      <!-- Cards de Resumo -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-8">
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
                  <dd class="text-lg font-medium text-gray-900">{{ formatCurrency(stats.total_amount) }}</dd>
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
                  <dt class="text-sm font-medium text-gray-500 truncate">De Pagamentos</dt>
                  <dd class="text-lg font-medium text-gray-900">{{ formatCurrency(stats.payment_amount) }}</dd>
                </dl>
              </div>
            </div>
          </div>
        </div>

        <div class="bg-white overflow-hidden shadow rounded-lg">
          <div class="p-5">
            <div class="flex items-center">
              <div class="flex-shrink-0">
                <svg class="h-6 w-6 text-orange-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                </svg>
              </div>
              <div class="ml-5 w-0 flex-1">
                <dl>
                  <dt class="text-sm font-medium text-gray-500 truncate">Manuais</dt>
                  <dd class="text-lg font-medium text-gray-900">{{ formatCurrency(stats.manual_amount) }}</dd>
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
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                </svg>
              </div>
              <div class="ml-5 w-0 flex-1">
                <dl>
                  <dt class="text-sm font-medium text-gray-500 truncate">Total de Entradas</dt>
                  <dd class="text-lg font-medium text-gray-900">{{ stats.total_entries }}</dd>
                </dl>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Gráficos -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-8 mb-8">
        <!-- Gráfico por Tipo -->
        <div class="bg-white p-6 rounded-lg shadow">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Receitas por Tipo</h3>
          <div class="h-64">
            <DoughnutChart
              v-if="chartData.typeChart"
              :data="chartData.typeChart"
              :options="chartOptions"
            />
          </div>
        </div>

        <!-- Gráfico por Forma de Pagamento -->
        <div class="bg-white p-6 rounded-lg shadow">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Por Forma de Pagamento</h3>
          <div class="h-64">
            <BarChart
              v-if="chartData.methodChart"
              :data="chartData.methodChart"
              :options="chartOptions"
            />
          </div>
        </div>
      </div>

      <!-- Gráfico de Evolução Mensal -->
      <div class="bg-white p-6 rounded-lg shadow mb-8">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Evolução Mensal</h3>
        <div class="h-64">
          <LineChart
            v-if="chartData.monthlyChart"
            :data="chartData.monthlyChart"
            :options="chartOptions"
          />
        </div>
      </div>

      <!-- Tabela de Entradas Recentes -->
      <div class="bg-white shadow overflow-hidden sm:rounded-md">
        <div class="px-4 py-5 sm:px-6">
          <h3 class="text-lg leading-6 font-medium text-gray-900">Entradas Recentes</h3>
          <p class="mt-1 max-w-2xl text-sm text-gray-500">Últimas 10 entradas financeiras</p>
        </div>
        <ul class="divide-y divide-gray-200">
          <li v-for="entry in recentEntries" :key="entry.id" class="px-4 py-4 sm:px-6">
            <div class="flex items-center justify-between">
              <div class="flex items-center">
                <div class="flex-shrink-0">
                  <span :class="getTypeBadgeClass(entry.type)" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                    {{ entry.type_text }}
                  </span>
                </div>
                <div class="ml-4">
                  <div class="flex items-center">
                    <p class="text-sm font-medium text-gray-900">{{ entry.description }}</p>
                    <span :class="getStatusBadgeClass(entry.status)" class="ml-2 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                      {{ entry.status_text }}
                    </span>
                  </div>
                  <div class="mt-1 flex items-center text-sm text-gray-500">
                    <svg class="flex-shrink-0 mr-1.5 h-4 w-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    {{ formatDate(entry.entry_date) }}
                    <span v-if="entry.payment_method" class="ml-2">
                      • {{ entry.payment_method_text }}
                    </span>
                  </div>
                </div>
              </div>
              <div class="text-right">
                <p class="text-lg font-semibold text-gray-900">{{ formatCurrency(entry.amount) }}</p>
                <p v-if="entry.work_order_id" class="text-sm text-gray-500">OS #{{ entry.work_order_id }}</p>
              </div>
            </div>
          </li>
        </ul>
      </div>
    </div>

    <!-- Modal de Criação (reutilizado da página de entradas) -->
    <div v-if="showCreateModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
      <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900">Nova Entrada Financeira</h3>
            <button
              @click="showCreateModal = false"
              class="text-gray-400 hover:text-gray-600"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>
          </div>

          <form @submit.prevent="submitForm">
            <div class="space-y-4">
              <!-- Tipo -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Tipo *</label>
                <select
                  v-model="form.type"
                  required
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                >
                  <option value="">Selecione o tipo</option>
                  <option value="payment">Pagamento</option>
                  <option value="manual">Manual</option>
                </select>
              </div>

              <!-- Fonte -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Origem *</label>
                <select
                  v-model="form.source"
                  required
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                >
                  <option value="">Selecione a origem</option>
                  <option value="work_order">Ordem de Serviço</option>
                  <option value="manual">Manual</option>
                </select>
              </div>

              <!-- Valor -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Valor *</label>
                <input
                  :value="form.amount"
                  @input="formatCurrencyField"
                  type="text"
                  required
                  placeholder="0,00"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                />
              </div>

              <!-- Descrição -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Descrição *</label>
                <input
                  v-model="form.description"
                  type="text"
                  required
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                  placeholder="Descrição da entrada"
                />
              </div>

              <!-- Data -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Data *</label>
                <input
                  v-model="form.entry_date"
                  type="date"
                  required
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                />
              </div>

              <!-- Forma de Pagamento -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Forma de Pagamento</label>
                <select
                  v-model="form.payment_method"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                >
                  <option value="">Selecione a forma de pagamento</option>
                  <option value="pix">PIX</option>
                  <option value="credit_card">Cartão de Crédito</option>
                  <option value="debit_card">Cartão de Débito</option>
                  <option value="boleto">Boleto Bancário</option>
                  <option value="cash">Dinheiro</option>
                  <option value="bank_transfer">Transferência Bancária</option>
                </select>
              </div>
            </div>

            <div class="flex justify-end space-x-3 mt-6">
              <button
                type="button"
                @click="showCreateModal = false"
                class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
              >
                Cancelar
              </button>
              <button
                type="submit"
                :disabled="isSubmitting"
                class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50"
              >
                {{ isSubmitting ? 'Salvando...' : 'Salvar' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, onMounted, reactive, computed } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import { Chart as ChartJS, ArcElement, Tooltip, Legend, CategoryScale, LinearScale, BarElement, PointElement, LineElement } from 'chart.js'
import { Doughnut, Bar, Line } from 'vue-chartjs'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'

// Registrar componentes do Chart.js
ChartJS.register(ArcElement, Tooltip, Legend, CategoryScale, LinearScale, BarElement, PointElement, LineElement)

// Componentes de gráfico
const DoughnutChart = Doughnut
const BarChart = Bar
const LineChart = Line

// Props
const props = defineProps({
  stats: {
    type: Object,
    required: true
  },
  chartData: {
    type: Object,
    required: true
  },
  recentEntries: {
    type: Array,
    required: true
  }
})

// Reactive data
const showCreateModal = ref(false)
const isSubmitting = ref(false)
const selectedPeriod = ref('this_month')

const filters = reactive({
  start_date: '',
  end_date: ''
})

const form = reactive({
  type: '',
  source: '',
  amount: '',
  description: '',
  entry_date: '',
  payment_method: '',
  reference_number: '',
  notes: '',
  status: 'confirmed'
})

// Opções dos gráficos
const chartOptions = {
  responsive: true,
  maintainAspectRatio: false,
  plugins: {
    legend: {
      position: 'bottom'
    }
  }
}

// Methods
const updateDateRange = () => {
  const today = new Date()

  switch (selectedPeriod.value) {
    case 'this_month':
      filters.start_date = new Date(today.getFullYear(), today.getMonth(), 1).toISOString().split('T')[0]
      filters.end_date = new Date(today.getFullYear(), today.getMonth() + 1, 0).toISOString().split('T')[0]
      break
    case 'last_month':
      const lastMonth = new Date(today.getFullYear(), today.getMonth() - 1, 1)
      filters.start_date = lastMonth.toISOString().split('T')[0]
      filters.end_date = new Date(lastMonth.getFullYear(), lastMonth.getMonth() + 1, 0).toISOString().split('T')[0]
      break
    case 'this_year':
      filters.start_date = new Date(today.getFullYear(), 0, 1).toISOString().split('T')[0]
      filters.end_date = new Date(today.getFullYear(), 11, 31).toISOString().split('T')[0]
      break
    case 'last_year':
      const lastYear = today.getFullYear() - 1
      filters.start_date = new Date(lastYear, 0, 1).toISOString().split('T')[0]
      filters.end_date = new Date(lastYear, 11, 31).toISOString().split('T')[0]
      break
  }

  loadData()
}

const loadData = () => {
  const params = {}
  if (filters.start_date) params.start_date = filters.start_date
  if (filters.end_date) params.end_date = filters.end_date

  router.get('/financial-dashboard', params, {
    preserveState: true,
    preserveScroll: true,
    replace: true
  })
}

const formatCurrencyField = (event) => {
  const input = event.target
  let value = input.value

  // Remove tudo que não é número
  value = value.replace(/\D/g, '')

  // Se estiver vazio, define como vazio
  if (value === '') {
    form.amount = ''
    return
  }

  // Converte para centavos e depois para formato brasileiro
  const numericValue = parseInt(value) / 100
  const formattedValue = numericValue.toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  })

  form.amount = formattedValue
}

const parseCurrencyValue = (value) => {
  if (!value) return 0
  const cleanValue = value.replace(/[^\d,]/g, '').replace(',', '.')
  return parseFloat(cleanValue) || 0
}

const formatCurrency = (value) => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(value)
}

const formatDate = (dateString) => {
  if (!dateString) return '-'
  const date = new Date(dateString)
  return date.toLocaleDateString('pt-BR')
}

const getTypeBadgeClass = (type) => {
  return type === 'payment'
    ? 'bg-blue-100 text-blue-800'
    : 'bg-orange-100 text-orange-800'
}

const getStatusBadgeClass = (status) => {
  switch (status) {
    case 'confirmed': return 'bg-green-100 text-green-800'
    case 'pending': return 'bg-yellow-100 text-yellow-800'
    case 'cancelled': return 'bg-red-100 text-red-800'
    default: return 'bg-gray-100 text-gray-800'
  }
}

const submitForm = async () => {
  isSubmitting.value = true

  try {
    const formData = {
      ...form,
      amount: parseCurrencyValue(form.amount)
    }

    // Usar router.post do Inertia
    router.post('/financial-entries', formData, {
      preserveScroll: true,
      onSuccess: () => {
        showCreateModal.value = false
        router.reload({ only: ['entries', 'summary'] })
      },
      onError: (errors) => {
        alert('Erro ao salvar entrada: ' + (errors.message || 'Erro desconhecido'))
      }
    })
  } catch (error) {
    alert('Erro ao salvar entrada: ' + error.message)
  } finally {
    isSubmitting.value = false
  }
}

// Lifecycle
onMounted(() => {
  // Set default date to today
  const today = new Date().toISOString().split('T')[0]
  form.entry_date = today

  // Initialize date range only if no dates are set
  if (!filters.start_date && !filters.end_date) {
    updateDateRange()
  }
})
</script>
