<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Entradas Financeiras"
        description="Controle de receitas e pagamentos recebidos">
        <template #actions>
          <button
            @click="showCreateModal = true"
            class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
          >
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
            </svg>
            Nova Entrada
          </button>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <div class="bg-white rounded-lg shadow p-6 mb-6">
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <!-- Tipo -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
            <select v-model="filters.type" @change="loadEntries" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm">
              <option value="">Todos os tipos</option>
              <option value="payment">Pagamentos</option>
              <option value="withdrawal">Retiradas</option>
              <option value="manual">Manuais</option>
            </select>
          </div>

          <!-- Status -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
            <select v-model="filters.status" @change="loadEntries" class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm">
              <option value="">Todos os status</option>
              <option value="confirmed">Confirmado</option>
              <option value="pending">Pendente</option>
              <option value="cancelled">Cancelado</option>
            </select>
          </div>

          <!-- Data Inicial -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Data Inicial</label>
            <input
              v-model="filters.start_date"
              type="date"
              @change="loadEntries"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
            />
          </div>

          <!-- Data Final -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Data Final</label>
            <input
              v-model="filters.end_date"
              type="date"
              @change="loadEntries"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
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
                  <dd class="text-lg font-medium text-gray-900">{{ formatCurrency(stats.total_received || stats.total_amount || 0) }}</dd>
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

      <!-- Tabela -->
      <div class="bg-white shadow overflow-hidden sm:rounded-md">
        <div class="px-4 py-5 sm:px-6">
          <h3 class="text-lg leading-6 font-medium text-gray-900">Lista de Entradas</h3>
          <p class="mt-1 max-w-2xl text-sm text-gray-500">Todas as entradas financeiras registradas</p>
        </div>
        <ul class="divide-y divide-gray-200">
          <li v-for="entry in entries.data" :key="entry.id" class="px-4 py-4 sm:px-6">
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
                    <span v-if="entry.reference_number" class="ml-2">
                      • Ref: {{ entry.reference_number }}
                    </span>
                  </div>
                </div>
              </div>
              <div class="flex items-center">
                <div class="text-right">
                  <p class="text-lg font-semibold text-gray-900">{{ formatCurrency(entry.amount) }}</p>
                  <p v-if="entry.work_order_id" class="text-sm text-gray-500">OS #{{ entry.work_order_id }}</p>
                </div>
                <div class="ml-4 flex space-x-2">
                  <button
                    v-if="!isFromWorkOrder(entry)"
                    @click="editEntry(entry)"
                    class="text-green-600 hover:text-green-900 text-sm font-medium"
                  >
                    Editar
                  </button>
                  <button
                    v-if="!isFromWorkOrder(entry)"
                    @click="deleteEntry(entry.id)"
                    class="text-red-600 hover:text-red-900 text-sm font-medium"
                  >
                    Excluir
                  </button>
                  <span
                    v-if="isFromWorkOrder(entry)"
                    class="text-gray-400 text-sm font-medium"
                    title="Entrada gerada automaticamente por OS - não pode ser editada"
                  >
                    Gerada por OS
                  </span>
                </div>
              </div>
            </div>
          </li>
        </ul>

        <!-- Paginação -->
        <div v-if="entries.last_page > 1" class="bg-white px-4 py-3 flex items-center justify-between border-t border-gray-200 sm:px-6">
          <div class="flex-1 flex justify-between sm:hidden">
            <Link
              v-if="entries.prev_page_url"
              :href="entries.prev_page_url"
              class="relative inline-flex items-center px-4 py-2 border border-gray-300 text-sm font-medium rounded-md text-gray-700 bg-white hover:bg-gray-50"
            >
              Anterior
            </Link>
            <Link
              v-if="entries.next_page_url"
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
                <span class="font-medium">{{ entries.from }}</span>
                até
                <span class="font-medium">{{ entries.to }}</span>
                de
                <span class="font-medium">{{ entries.total }}</span>
                resultados
              </p>
            </div>
            <div>
              <nav class="relative z-0 inline-flex rounded-md shadow-sm -space-x-px">
                <Link
                  v-for="link in entries.links"
                  :key="link.label"
                  :href="link.url"
                  v-html="link.label"
                  :class="[
                    'relative inline-flex items-center px-4 py-2 border text-sm font-medium',
                    link.active
                      ? 'z-10 bg-green-50 border-green-500 text-green-600'
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

    <!-- Modal de Criação/Edição -->
    <div v-if="showCreateModal || showEditModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
      <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900">
              {{ showCreateModal ? 'Nova Entrada Financeira' : 'Editar Entrada Financeira' }}
            </h3>
            <button
              @click="closeModal"
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
                  <option value="withdrawal">Retirada</option>
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

              <!-- Número de Referência -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Número de Referência</label>
                <input
                  v-model="form.reference_number"
                  type="text"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                  placeholder="Número do comprovante, transação, etc."
                />
              </div>

              <!-- Observações -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
                <textarea
                  v-model="form.notes"
                  rows="3"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                  placeholder="Observações adicionais..."
                ></textarea>
              </div>

              <!-- Status -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Status</label>
                <select
                  v-model="form.status"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                >
                  <option value="confirmed">Confirmado</option>
                  <option value="pending">Pendente</option>
                  <option value="cancelled">Cancelado</option>
                </select>
              </div>
            </div>

            <div class="flex justify-end space-x-3 mt-6">
              <button
                type="button"
                @click="closeModal"
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
import { ref, onMounted, reactive } from 'vue'
import { Link, router } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'

// Props
const props = defineProps({
  entries: {
    type: Object,
    required: true
  },
  stats: {
    type: Object,
    default: () => ({
      total_received: 0,
      total_amount: 0,
      payment_amount: 0,
      manual_amount: 0,
      total_entries: 0
    })
  }
})

// Reactive data
const showCreateModal = ref(false)
const showEditModal = ref(false)
const isSubmitting = ref(false)
const editingEntry = ref(null)

const filters = reactive({
  type: '',
  status: '',
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

// Methods
const loadEntries = () => {
  const params = {}
  Object.keys(filters).forEach(key => {
    if (filters[key]) {
      params[key] = filters[key]
    }
  })

  router.get('/financial-entries', params, {
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
  switch (type) {
    case 'payment': return 'bg-blue-100 text-blue-800'
    case 'withdrawal': return 'bg-orange-100 text-orange-800'
    case 'manual': return 'bg-green-100 text-green-800'
    default: return 'bg-gray-100 text-gray-800'
  }
}

const getStatusBadgeClass = (status) => {
  switch (status) {
    case 'confirmed': return 'bg-green-100 text-green-800'
    case 'pending': return 'bg-yellow-100 text-yellow-800'
    case 'cancelled': return 'bg-red-100 text-red-800'
    default: return 'bg-gray-100 text-gray-800'
  }
}

const editEntry = (entry) => {
  editingEntry.value = entry
  Object.keys(form).forEach(key => {
    if (key === 'amount') {
      form[key] = formatCurrency(entry[key])
    } else {
      form[key] = entry[key] || ''
    }
  })
  showEditModal.value = true
}

const isFromWorkOrder = (entry) => {
  return entry.source === 'work_order' || entry.source === 'payment_reopen'
}

const deleteEntry = async (id) => {
  if (!confirm('Tem certeza que deseja excluir esta entrada?')) return

  try {
    const response = await fetch(`/financial-entries/${id}`, {
      method: 'DELETE',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        'Accept': 'application/json',
      },
    })

    const data = await response.json()

    if (data.success) {
      window.location.reload()
    } else {
      alert('Erro ao excluir entrada: ' + data.message)
    }
  } catch (error) {
    alert('Erro ao excluir entrada: ' + error.message)
  }
}

const submitForm = async () => {
  isSubmitting.value = true

  try {
    const formData = {
      ...form,
      amount: parseCurrencyValue(form.amount)
    }

    const url = showEditModal.value
      ? `/financial-entries/${editingEntry.value.id}`
      : '/financial-entries'

    const method = showEditModal.value ? 'PUT' : 'POST'

    const response = await fetch(url, {
      method,
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify(formData)
    })

    const data = await response.json()

    if (data.success) {
      closeModal()
      window.location.reload()
    } else {
      alert('Erro ao salvar entrada: ' + data.message)
    }
  } catch (error) {
    alert('Erro ao salvar entrada: ' + error.message)
  } finally {
    isSubmitting.value = false
  }
}

const closeModal = () => {
  showCreateModal.value = false
  showEditModal.value = false
  editingEntry.value = null

  // Reset form
  Object.keys(form).forEach(key => {
    if (key === 'status') {
      form[key] = 'confirmed'
    } else {
      form[key] = ''
    }
  })
}

// Lifecycle
onMounted(() => {
  // Set default date to today
  const today = new Date().toISOString().split('T')[0]
  form.entry_date = today
})
</script>
