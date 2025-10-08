<template>
  <AuthenticatedLayout>
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between mb-6">
      <div class="flex-1 min-w-0">
        <h1 class="text-2xl font-bold text-gray-900 sm:text-3xl sm:truncate">Registros Ministeriais</h1>
        <p class="mt-1 text-sm text-gray-600 sm:text-base">Gerencie todos os registros ministeriais cadastrados no sistema</p>
      </div>
      <div class="flex flex-col gap-2 sm:flex-row sm:gap-3">
        <Link
          href="/organ-registrations/create"
          class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 transition-colors"
        >
          <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
          </svg>
          Novo Registro Ministerial
        </Link>
      </div>
    </div>

    <!-- Busca -->
    <Card class="mb-6">
      <div class="relative">
        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
          <svg class="h-5 w-5 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
          </svg>
        </div>
        <input
          v-model="filters.search"
          @input="debouncedSearch"
          type="text"
          placeholder="Buscar por registro..."
          class="block w-full pl-10 pr-3 py-2 border border-gray-300 rounded-md leading-5 bg-white placeholder-gray-500 focus:outline-none focus:placeholder-gray-400 focus:ring-1 focus:ring-green-500 focus:border-green-500"
        />
      </div>
      <p class="mt-2 text-sm text-gray-500">Digite pelo menos 2 caracteres para buscar</p>
    </Card>

    <!-- Tabela -->
    <Card>
      <div class="overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200">
          <thead class="bg-gray-50">
            <tr>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Registro Ministerial
              </th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Produtos Vinculados
              </th>
              <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                Criado em
              </th>
              <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                Ações
              </th>
            </tr>
          </thead>
          <tbody class="bg-white divide-y divide-gray-200">
            <tr v-if="loading || !organRegistrations" class="animate-pulse">
              <td colspan="4" class="px-6 py-4 text-center text-gray-500">
                Carregando...
              </td>
            </tr>
            <tr v-else-if="organRegistrations && organRegistrations.data && organRegistrations.data.length === 0">
              <td colspan="4" class="px-6 py-4 text-center text-gray-500">
                Nenhum registro ministerial encontrado
              </td>
            </tr>
            <tr v-else v-for="organRegistration in (organRegistrations?.data || [])" :key="organRegistration.id" class="hover:bg-gray-50">
              <td class="px-6 py-4 whitespace-nowrap">
                <div class="flex items-center">
                  <div class="flex-shrink-0 h-10 w-10">
                    <div class="h-10 w-10 rounded-full bg-yellow-100 flex items-center justify-center">
                      <svg class="h-6 w-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                      </svg>
                    </div>
                  </div>
                  <div class="ml-4">
                    <div class="text-sm font-medium text-gray-900">{{ organRegistration.record }}</div>
                    <div class="text-sm text-gray-500">ID: {{ organRegistration.id }}</div>
                  </div>
                </div>
              </td>
              <td class="px-6 py-4 whitespace-nowrap">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                      :class="organRegistration.products_count > 0 ? 'bg-yellow-100 text-yellow-800' : 'bg-gray-100 text-gray-800'">
                  {{ organRegistration.products_count || 0 }} produto(s)
                </span>
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                {{ formatDate(organRegistration.created_at) }}
              </td>
              <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                <div class="flex items-center justify-end space-x-3">
                  <Link
                    :href="`/organ-registrations/${organRegistration.id}`"
                    class="text-green-600 hover:text-green-900"
                    title="Visualizar"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                    </svg>
                  </Link>
                  <Link
                    :href="`/organ-registrations/${organRegistration.id}/edit`"
                    class="text-blue-600 hover:text-blue-900"
                    title="Editar"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                  </Link>
                  <button
                    @click="deleteOrganRegistration(organRegistration)"
                    :disabled="organRegistration.products_count > 0"
                    :class="organRegistration.products_count > 0
                      ? 'text-gray-400 cursor-not-allowed'
                      : 'text-red-600 hover:text-red-900'"
                    :title="organRegistration.products_count > 0
                      ? 'Não é possível excluir pois há produtos vinculados'
                      : 'Excluir'"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                    </svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Paginação -->
      <Pagination
        v-if="organRegistrations && organRegistrations.last_page > 1"
        :links="organRegistrations.links"
      />
    </Card>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, reactive } from 'vue'
import { router, Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import Card from '@/Components/Card.vue'
import Pagination from '@/Components/Pagination.vue'

const props = defineProps({
  organRegistrations: Object,
  stats: Object,
  filters: Object,
})

const loading = ref(false)

// Filtros reativos
const filters = reactive({
  search: props.filters?.search || '',
})

// Debounce simples
let searchTimeout = null
const debouncedSearch = () => {
  if (searchTimeout) {
    clearTimeout(searchTimeout)
  }
  searchTimeout = setTimeout(() => {
    loadOrganRegistrations()
  }, 500)
}

// Métodos
const loadOrganRegistrations = () => {
  loading.value = true
  router.get('/organ-registrations', {
    search: filters.search,
  }, {
    preserveState: true,
    onFinish: () => {
      loading.value = false
    }
  })
}

const deleteOrganRegistration = (organRegistration) => {
  if (organRegistration.products_count > 0) {
    alert(`Não é possível excluir o registro "${organRegistration.record}" pois existem ${organRegistration.products_count} produto(s) vinculado(s) a ele.`);
    return;
  }

  if (confirm(`Tem certeza que deseja excluir o registro "${organRegistration.record}"?`)) {
    router.delete(`/organ-registrations/${organRegistration.id}`, {
      onSuccess: () => {
        loadOrganRegistrations()
      }
    })
  }
}

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('pt-BR')
}
</script>
