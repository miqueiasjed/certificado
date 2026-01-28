<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader :title="`Orçamento #${budget.id}`" description="Detalhes do orçamento">
        <template #actions>
          <div class="space-x-2">
            <a :href="`/budgets/${budget.id}/pdf`" target="_blank" class="btn-secondary">
              <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path></svg>
              PDF
            </a>
            <Link :href="`/budgets/${budget.id}/edit`" class="btn-secondary">
              <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg>
              Editar
            </Link>
             <button v-if="budget.status !== 'converted'" @click="convertToOs" class="btn-primary">
              <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7v8a2 2 0 002 2h6M8 7V5a2 2 0 012-2h4.586a1 1 0 01.707.293l4.414 4.414a1 1 0 01.293.707V15a2 2 0 01-2 2h-2M8 7H6a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2v-2"></path></svg>
              Converter em OS
            </button>
          </div>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <Card title="Dados do Cliente/Prospecto">
         <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-xs font-medium text-gray-500">Nome</label>
                <div class="text-sm font-medium">{{ budget.client ? budget.client.name : budget.prospect_name }}</div>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-500">Telefone</label>
                <div class="text-sm">{{ budget.client ? budget.client.phone : budget.prospect_phone }}</div>
            </div>
             <div>
                <label class="block text-xs font-medium text-gray-500">Endereço</label>
                <div class="text-sm">{{ budget.client ? (budget.client.addresses?.[0]?.street || 'Ver cadastro') : budget.prospect_address }}</div>
            </div>
             <div>
                <label class="block text-xs font-medium text-gray-500">Status</label>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                    {{ statusLabel(budget.status) }}
                </span>
            </div>
         </div>
      </Card>

      <Card title="Serviços">
          <table class="min-w-full divide-y divide-gray-200">
             <thead>
                 <tr>
                     <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase">Serviço</th>
                     <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Qtd</th>
                     <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Unitário</th>
                     <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase">Subtotal</th>
                 </tr>
             </thead>
             <tbody class="divide-y divide-gray-200">
                 <tr v-for="service in budget.services" :key="service.id">
                     <td class="px-4 py-2 text-sm">{{ service.description }}</td>
                     <td class="px-4 py-2 text-sm text-right">{{ service.pivot.quantity }}</td>
                     <td class="px-4 py-2 text-sm text-right">{{ formatCurrency(service.pivot.unit_price) }}</td>
                     <td class="px-4 py-2 text-sm text-right">{{ formatCurrency(service.pivot.subtotal) }}</td>
                 </tr>
             </tbody>
             <tfoot>
                 <tr v-if="budget.discount > 0">
                     <td colspan="3" class="px-4 py-2 text-right font-medium">Desconto:</td>
                     <td class="px-4 py-2 text-right text-red-600">- {{ formatCurrency(budget.discount) }}</td>
                 </tr>
                  <tr>
                     <td colspan="3" class="px-4 py-2 text-right font-bold">Total:</td>
                     <td class="px-4 py-2 text-right font-bold">{{ formatCurrency(totalValue) }}</td>
                 </tr>
             </tfoot>
          </table>
      </Card>
      
      <Card title="Outras Informações">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                  <label class="block text-xs font-medium text-gray-500">Data</label>
                  <div class="text-sm">{{ new Date(budget.date).toLocaleDateString() }}</div>
              </div>
              <div>
                  <label class="block text-xs font-medium text-gray-500">Prioridade</label>
                  <div class="text-sm">{{ budget.priority === 'urgent' ? 'Urgente' : 'Normal' }}</div>
              </div>
               <div>
                  <label class="block text-xs font-medium text-gray-500">Ambiente</label>
                  <div class="text-sm">{{ budget.environment_type }}</div>
              </div>
              <div class="col-span-2">
                  <label class="block text-xs font-medium text-gray-500">Observações</label>
                  <div class="text-sm whitespace-pre-wrap">{{ budget.observations || '-' }}</div>
              </div>
          </div>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  budget: Object,
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

const formatCurrency = (val) => {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(val);
};

const totalValue = computed(() => {
    let subtotal = 0;
    if (props.budget.services) {
        subtotal = props.budget.services.reduce((acc, s) => acc + Number(s.pivot.subtotal), 0);
    }
    return subtotal - Number(props.budget.discount || 0);
});

const convertToOs = () => {
    if (confirm('Deseja converter este orçamento em Ordem de Serviço? Se o cliente não estiver cadastrado, um cadastro provisório será criado.')) {
        router.post(`/budgets/${props.budget.id}/convert`);
    }
};
</script>
