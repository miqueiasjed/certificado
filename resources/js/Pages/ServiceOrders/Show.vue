<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Detalhes da Ordem de Serviço"
        :description="`OS-${serviceOrder.id.toString().padStart(6, '0')}`">
        <template #actions>
          <Link :href="`/service-orders/${serviceOrder.id}/edit`" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
            </svg>
            Editar Ordem
          </Link>
          <Link href="/service-orders" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-4xl mx-auto space-y-6">
      <!-- Informações Principais -->
      <Card>
        <div class="p-6">
          <div class="flex items-center">
            <div class="flex-shrink-0 h-16 w-16">
              <div class="h-16 w-16 rounded-full bg-orange-100 flex items-center justify-center">
                <svg class="h-8 w-8 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
              </div>
            </div>
            <div class="ml-6">
              <h1 class="text-2xl font-bold text-gray-900">
                Ordem de Serviço #{{ serviceOrder.id }}
              </h1>
              <p class="text-lg text-gray-600">
                OS-{{ serviceOrder.id.toString().padStart(6, '0') }}
              </p>
              <p class="text-sm text-gray-500">
                Criada em {{ formatDate(serviceOrder.created_at) }}
              </p>
            </div>
          </div>
        </div>
      </Card>

      <!-- Informações do Cliente -->
      <Card>
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Informações do Cliente</h3>
        </div>
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-500">Nome do Cliente</label>
              <div class="mt-1">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                  {{ serviceOrder.client?.name || 'Cliente não informado' }}
                </span>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">CPF/CNPJ</label>
              <div class="mt-1">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-purple-100 text-purple-800">
                  {{ serviceOrder.client?.cnpj || 'Não informado' }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Informações do Serviço -->
      <Card>
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Informações do Serviço</h3>
        </div>
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-500">Nome do Serviço</label>
              <div class="mt-1">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-green-100 text-green-800">
                  {{ serviceOrder.service?.name || 'Serviço não informado' }}
                </span>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Preço</label>
              <div class="mt-1">
                <span v-if="serviceOrder.service?.price" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-teal-100 text-teal-800">
                  {{ formatPrice(serviceOrder.service.price) }}
                </span>
                <span v-else class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                  Não informado
                </span>
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Informações da Ordem -->
      <Card>
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Informações da Ordem</h3>
        </div>
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-500">Status</label>
              <div class="mt-1">
                <span v-if="serviceOrder.status === 'pending'" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800">
                  Pendente
                </span>
                <span v-else-if="serviceOrder.status === 'in_progress'" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                  Em Andamento
                </span>
                <span v-else-if="serviceOrder.status === 'completed'" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-green-100 text-green-800">
                  Concluída
                </span>
                <span v-else-if="serviceOrder.status === 'cancelled'" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-red-100 text-red-800">
                  Cancelada
                </span>
                <span v-else class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                  {{ serviceOrder.status || 'Não definido' }}
                </span>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Prioridade</label>
              <div class="mt-1">
                <span v-if="serviceOrder.priority === 'low'" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                  Baixa
                </span>
                <span v-else-if="serviceOrder.priority === 'medium'" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                  Média
                </span>
                <span v-else-if="serviceOrder.priority === 'high'" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-orange-100 text-orange-800">
                  Alta
                </span>
                <span v-else-if="serviceOrder.priority === 'urgent'" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-red-100 text-red-800">
                  Urgente
                </span>
                <span v-else class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                  Não definida
                </span>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Data de Início</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ formatDate(serviceOrder.start_date) }}
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Data de Conclusão Prevista</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ formatDate(serviceOrder.due_date) }}
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Observações -->
      <Card v-if="serviceOrder.notes">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Observações</h3>
        </div>
        <div class="p-6">
          <p class="text-sm text-gray-700">{{ serviceOrder.notes }}</p>
        </div>
      </Card>

      <!-- Informações do Sistema -->
      <Card>
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Informações do Sistema</h3>
        </div>
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-500">Data de Criação</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ formatDate(serviceOrder.created_at) }}
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Última Atualização</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ formatDate(serviceOrder.updated_at) }}
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Botões de Ação -->
      <div class="flex justify-center space-x-4">
        <Link :href="`/service-orders/${serviceOrder.id}/edit`" class="btn-primary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
          </svg>
          Editar Ordem
        </Link>
        <Link href="/service-orders" class="btn-secondary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
          </svg>
          Voltar à Lista
        </Link>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  serviceOrder: Object,
});

const formatDate = (dateString) => {
  if (!dateString) return '-';
  return new Date(dateString).toLocaleDateString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit'
  });
};

const formatPrice = (price) => {
  if (!price) return 'Não informado';
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL'
  }).format(price);
};
</script>
