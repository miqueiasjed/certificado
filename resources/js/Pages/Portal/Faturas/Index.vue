<template>
  <PortalLayout>
    <template #header>
      <PageHeader
        title="Faturas"
        description="Vencimento, valor e situação de cada fatura. O pagamento pelo portal ainda não está disponível." />
    </template>

    <div class="space-y-6">
      <div v-if="faturas.data.length === 0" class="rounded-lg border border-gray-200 bg-white p-8 text-center">
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-100">
          <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <h3 class="mt-3 text-sm font-medium text-gray-900">Nenhuma fatura registrada</h3>
        <p class="mt-1 text-sm text-gray-500">Ainda não há faturas lançadas para o seu cadastro.</p>
      </div>

      <Card v-else padding="none">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Vencimento</th>
                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Valor</th>
                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Situação</th>
                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Forma de pagamento</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
              <tr v-for="fatura in faturas.data" :key="fatura.id" class="hover:bg-gray-50">
                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                  {{ formatarData(fatura.payment_due_date) }}
                </td>
                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                  {{ formatarMoeda(fatura.amount) }}
                  <p v-if="fatura.is_partial_payment" class="text-xs text-gray-500">
                    Pago até agora: {{ formatarMoeda(fatura.amount_paid) }}
                  </p>
                </td>
                <td class="whitespace-nowrap px-6 py-4">
                  <span
                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                    :class="corDaSituacao(fatura.payment_status)">
                    {{ fatura.payment_status_text }}
                  </span>
                </td>
                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">
                  {{ fatura.payment_method_text || 'Não informada' }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>

      <Pagination v-if="faturas.data.length > 0" :links="faturas.links" />
    </div>
  </PortalLayout>
</template>

<script setup>
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatarData } from '@/utils/formatDate';

// `faturas` é o `LengthAwarePaginator` de `PortalController::faturas()` (Task 15.4)
// sobre `CamposVisiveisAoCliente::fatura()` (Task 15.3): nenhum campo de ação de
// pagamento sai daqui, e esta tela não adiciona nenhum - nem um botão desabilitado
// (o pagamento pelo portal é o Plano 19, fora desta entrega).
defineProps({
  faturas: {
    type: Object,
    required: true,
  },
});

// Mesmo mapeamento de `PaymentDetail::getPaymentStatusColorAttribute()` no backend:
// `CamposVisiveisAoCliente::FATURA` não expõe a cor pronta, só `payment_status` e
// `payment_status_text`, então a cor do badge é decidida aqui, com a mesma paleta.
const CORES_DA_SITUACAO = {
  pending: 'bg-yellow-100 text-yellow-800',
  paid: 'bg-green-100 text-green-800',
  partial: 'bg-blue-100 text-blue-800',
  overdue: 'bg-red-100 text-red-800',
};

function corDaSituacao(situacao) {
  return CORES_DA_SITUACAO[situacao] || 'bg-gray-100 text-gray-800';
}

function formatarMoeda(valor) {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
  }).format(Number(valor) || 0);
}
</script>
