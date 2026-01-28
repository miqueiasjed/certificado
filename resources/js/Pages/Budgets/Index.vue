<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Orçamentos" description="Gerencie seus orçamentos e propostas comerciais">
        <template #actions>
          <Link href="/budgets/create" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            Novo Orçamento
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <Card padding="none">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">ID</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cliente/Prospecto</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prioridade</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="budget in budgets.data" :key="budget.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                  #{{ budget.id }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="text-sm font-medium text-gray-900">
                    {{ budget.client ? budget.client.name : budget.prospect_name }}
                    <span v-if="!budget.client" class="text-xs text-gray-500 italic">(Prospecto)</span>
                  </div>
                  <div class="text-xs text-gray-500">
                    {{ budget.client ? budget.client.phone : budget.prospect_phone }}
                  </div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span :class="statusClass(budget.status)" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full">
                    {{ statusLabel(budget.status) }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                  {{ new Date(budget.date).toLocaleDateString() }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                   <span :class="budget.priority === 'urgent' ? 'text-red-600 bg-red-100' : 'text-gray-600 bg-gray-100'" class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full">
                    {{ budget.priority === 'urgent' ? 'Urgente' : 'Normal' }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                  <div class="flex items-center space-x-2">
                    <a
                      :href="`/budgets/${budget.id}/pdf`"
                      target="_blank"
                      class="text-gray-600 hover:text-gray-900 transition-colors"
                      title="Gerar PDF"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                      </svg>
                    </a>
                    <Link
                      :href="`/budgets/${budget.id}/edit`"
                      class="text-blue-600 hover:text-blue-900 transition-colors"
                      title="Editar"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                      </svg>
                    </Link>
                    <button
                      @click="deleteBudget(budget.id)"
                      class="text-red-600 hover:text-red-900 transition-colors"
                      title="Excluir"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
          
             <!-- Estado vazio -->
          <div v-if="!budgets.data || budgets.data.length === 0" class="text-center py-12">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
               <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum orçamento encontrado</h3>
             <div class="mt-6">
              <Link
                href="/budgets/create"
                class="inline-flex items-center px-4 py-2 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
              >
                Novo Orçamento
              </Link>
            </div>
          </div>
        </div>
      </Card>
      
      <div v-if="budgets.links && budgets.links.length > 3">
        <Pagination :links="budgets.links" />
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
  budgets: Object,
});

const statusLabel = (status) => {
  const map = {
    draft: 'Rascunho',
    sent: 'Enviado',
    negotiating: 'Negociando',
    approved: 'Aprovado',
    refused: 'Recusado',
    expired: 'Expirado',
    converted: 'Convertido',
  };
  return map[status] || status;
};

const statusClass = (status) => {
  const map = {
    draft: 'bg-gray-100 text-gray-800',
    sent: 'bg-blue-100 text-blue-800',
    negotiating: 'bg-yellow-100 text-yellow-800',
    approved: 'bg-green-100 text-green-800',
    refused: 'bg-red-100 text-red-800',
    expired: 'bg-orange-100 text-orange-800',
    converted: 'bg-purple-100 text-purple-800',
  };
  return map[status] || 'bg-gray-100 text-gray-800';
};

const deleteBudget = (id) => {
  if (confirm('Tem certeza que deseja excluir este orçamento?')) {
    router.delete(`/budgets/${id}`);
  }
};
</script>
