<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Dashboard"
        description="Visão geral do sistema de certificação">
      </PageHeader>
    </template>

    <div class="space-y-6">
      <!-- Estatísticas Gerais -->
      <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <StatCard
          title="Total de Clientes"
          :value="stats.clients"
          icon="users"
          color="blue"
          :href="'/clients'"
        />
        <StatCard
          title="Total de Produtos"
          :value="stats.products"
          icon="cube"
          color="green"
          :href="'/products'"
        />
        <StatCard
          title="Total de Técnicos"
          :value="stats.technicians"
          icon="user-circle"
          color="indigo"
          :href="'/technicians'"
        />
        <StatCard
          title="Total de Serviços"
          :value="stats.services"
          icon="lightning-bolt"
          color="purple"
          :href="'/services'"
        />
        <StatCard
          title="Ordens de Serviço"
          :value="stats.serviceOrders"
          icon="document-text"
          color="orange"
          :href="'/service-orders'"
        />
        <StatCard
          title="Total de Certificados"
          :value="stats.certificates"
          icon="shield-check"
          color="emerald"
          :href="'/certificates'"
        />
      </div>

      <!-- Ações Rápidas -->
      <Card>
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Ações Rápidas</h3>
        </div>
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4">
            <QuickAction
              title="Novo Cliente"
              description="Cadastrar novo cliente"
              icon="user-plus"
              href="/clients/create"
              color="blue"
            />
            <QuickAction
              title="Novo Produto"
              description="Cadastrar novo produto"
              icon="cube-transparent"
              href="/products/create"
              color="green"
            />
            <QuickAction
              title="Nova Ordem"
              description="Criar ordem de serviço"
              icon="document-add"
              href="/service-orders/create"
              color="orange"
            />
            <QuickAction
              title="Novo Certificado"
              description="Emitir certificado"
              icon="shield-check"
              href="/certificates/create"
              color="emerald"
            />
          </div>
        </div>
      </Card>

      <!-- Resumo das Atividades Recentes -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Últimas Ordens de Serviço -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Últimas Ordens de Serviço</h3>
          </div>
          <div class="p-6">
            <div v-if="recentServiceOrders.length === 0" class="text-center py-8">
              <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
              </svg>
              <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhuma ordem de serviço</h3>
              <p class="mt-1 text-sm text-gray-500">Comece criando sua primeira ordem de serviço.</p>
            </div>
            <div v-else class="space-y-3">
              <div v-for="order in recentServiceOrders" :key="order.id" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                <div class="flex items-center space-x-3">
                  <div class="flex-shrink-0">
                    <div class="h-8 w-8 rounded-full bg-orange-100 flex items-center justify-center">
                      <svg class="h-4 w-4 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                      </svg>
                    </div>
                  </div>
                  <div>
                    <p class="text-sm font-medium text-gray-900">OS-{{ order.id.toString().padStart(6, '0') }}</p>
                    <p class="text-xs text-gray-500">{{ order.client?.name || 'Cliente não informado' }}</p>
                  </div>
                </div>
                <div class="flex items-center space-x-2">
                  <span :class="getStatusBadgeClass(order.status)" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium">
                    {{ getStatusText(order.status) }}
                  </span>
                  <Link :href="`/service-orders/${order.id}`" class="text-green-600 hover:text-green-900">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                  </Link>
                </div>
              </div>
            </div>
          </div>
        </Card>

        <!-- Últimos Certificados -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Últimos Certificados</h3>
          </div>
          <div class="p-6">
            <div v-if="recentCertificates.length === 0" class="text-center py-8">
              <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"></path>
              </svg>
              <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum certificado</h3>
              <p class="mt-1 text-sm text-gray-500">Comece emitindo seu primeiro certificado.</p>
            </div>
            <div v-else class="space-y-3">
              <div v-for="certificate in recentCertificates" :key="certificate.id" class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                <div class="flex items-center space-x-3">
                  <div class="flex-shrink-0">
                    <div class="h-8 w-8 rounded-full bg-emerald-100 flex items-center justify-center">
                      <svg class="h-4 w-4 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"></path>
                      </svg>
                    </div>
                  </div>
                  <div>
                    <p class="text-sm font-medium text-gray-900">CERT-{{ certificate.id.toString().padStart(6, '0') }}</p>
                    <p class="text-xs text-gray-500">{{ certificate.client?.name || 'Cliente não informado' }}</p>
                  </div>
                </div>
                <div class="flex items-center space-x-2">
                  <span :class="getStatusBadgeClass(certificate.status)" class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium">
                    {{ getStatusText(certificate.status) }}
                  </span>
                  <Link :href="`/certificates/${certificate.id}`" class="text-green-600 hover:text-green-900">
                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
                    </svg>
                  </Link>
                </div>
              </div>
            </div>
          </div>
        </Card>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import QuickAction from '@/Components/QuickAction.vue';

const props = defineProps({
  stats: Object,
  recentServiceOrders: Array,
  recentCertificates: Array,
});

const getStatusBadgeClass = (status) => {
  const baseClasses = 'inline-flex items-center px-2 py-1 rounded-full text-xs font-medium';

  switch (status) {
    case 'pending':
      return `${baseClasses} bg-yellow-100 text-yellow-800`;
    case 'in_progress':
      return `${baseClasses} bg-blue-100 text-blue-800`;
    case 'completed':
      return `${baseClasses} bg-green-100 text-green-800`;
    case 'cancelled':
      return `${baseClasses} bg-red-100 text-red-800`;
    case 'draft':
      return `${baseClasses} bg-gray-100 text-gray-800`;
    case 'issued':
      return `${baseClasses} bg-green-100 text-green-800`;
    case 'expired':
      return `${baseClasses} bg-red-100 text-red-800`;
    default:
      return `${baseClasses} bg-gray-100 text-gray-800`;
  }
};

const getStatusText = (status) => {
  switch (status) {
    case 'pending':
      return 'Pendente';
    case 'in_progress':
      return 'Em Andamento';
    case 'completed':
      return 'Concluída';
    case 'cancelled':
      return 'Cancelada';
    case 'draft':
      return 'Rascunho';
    case 'issued':
      return 'Emitido';
    case 'expired':
      return 'Expirado';
    default:
      return 'Não definido';
  }
};
</script>
