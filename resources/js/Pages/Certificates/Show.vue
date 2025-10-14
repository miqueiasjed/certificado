<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Detalhes do Certificado"
        :description="`CERT-${certificate.id.toString().padStart(6, '0')}`">
        <template #actions>
          <Link :href="`/certificates/${certificate.id}/edit`" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
            </svg>
            Editar Certificado
          </Link>
          <a
            :href="`/certificates/${certificate.id}/pdf`"
            target="_blank"
            class="btn-secondary"
          >
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
            </svg>
            Baixar PDF
          </a>
          <Link href="/certificates" class="btn-secondary">
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
              <div class="h-16 w-16 rounded-full bg-emerald-100 flex items-center justify-center">
                <svg class="h-8 w-8 text-emerald-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4M7.835 4.697a3.42 3.42 0 001.946-.806 3.42 3.42 0 014.438 0 3.42 3.42 0 001.946.806 3.42 3.42 0 013.138 3.138 3.42 3.42 0 00.806 1.946 3.42 3.42 0 010 4.438 3.42 3.42 0 00-.806 1.946 3.42 3.42 0 01-3.138 3.138 3.42 3.42 0 00-1.946.806 3.42 3.42 0 01-4.438 0 3.42 3.42 0 00-1.946-.806 3.42 3.42 0 01-3.138-3.138 3.42 3.42 0 00-.806-1.946 3.42 3.42 0 010-4.438 3.42 3.42 0 00.806-1.946 3.42 3.42 0 013.138-3.138z"></path>
                </svg>
              </div>
            </div>
            <div class="ml-6">
              <h1 class="text-2xl font-bold text-gray-900">
                Certificado #{{ certificate.id }}
              </h1>
              <p class="text-lg text-gray-600">
                CERT-{{ certificate.id.toString().padStart(6, '0') }}
              </p>
              <p class="text-sm text-gray-500">
                Criado em {{ formatDate(certificate.created_at) }}
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
                  {{ certificate.client?.name || 'Cliente não informado' }}
                </span>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">CPF/CNPJ</label>
              <div class="mt-1">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-purple-100 text-purple-800">
                  {{ certificate.client?.cnpj || 'Não informado' }}
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
          <div v-if="certificate.service" class="flex items-center space-x-2">
            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-green-100 text-green-800">
              {{ certificate.service.name }}
            </span>
            <span v-if="certificate.service.category" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
              {{ certificate.service.category }}
            </span>
          </div>
          <div v-else class="text-gray-500 text-sm">
            Nenhum serviço informado
          </div>
        </div>
      </Card>

      <!-- Informações dos Produtos -->
      <Card v-if="certificate.products && certificate.products.length > 0">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Produtos</h3>
        </div>
        <div class="p-6">
          <div class="space-y-4">
            <div v-for="product in certificate.products" :key="product.id" class="border border-gray-200 rounded-lg p-4">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-500">Nome do Produto</label>
                  <div class="mt-1">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-green-100 text-green-800">
                      {{ product.name || 'Nome não informado' }}
                    </span>
                  </div>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-500">Princípio Ativo</label>
                  <div class="mt-1">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                      {{ product.active_ingredient?.name || 'Não informado' }}
                    </span>
                  </div>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-500">Grupo Químico</label>
                  <div class="mt-1">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-purple-100 text-purple-800">
                      {{ product.chemical_group?.name || 'Não informado' }}
                    </span>
                  </div>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-500">Antídoto</label>
                  <div class="mt-1">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-yellow-100 text-yellow-800">
                      {{ product.antidote?.name || 'Não informado' }}
                    </span>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Informações do Certificado -->
      <Card>
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Informações do Certificado</h3>
        </div>
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-500">Status</label>
              <div class="mt-1">
                <span v-if="certificate.status === 'active'" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-green-100 text-green-800">
                  ATIVO
                </span>
                <span v-else-if="certificate.status === 'expired'" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-red-100 text-red-800">
                  VENCIDO
                </span>
                <span v-else class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                  {{ certificate.status || 'Não definido' }}
                </span>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Data da Execução</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ formatDate(certificate.execution_date) }}
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Data da Garantia</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ formatDate(certificate.warranty) }}
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Procedimento Utilizado -->
      <Card v-if="certificate.procedure_used">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Procedimento Utilizado</h3>
        </div>
        <div class="p-6">
          <p class="text-sm text-gray-700 whitespace-pre-line">{{ certificate.procedure_used }}</p>
        </div>
      </Card>

      <!-- Observações -->
      <Card v-if="certificate.notes">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Observações</h3>
        </div>
        <div class="p-6">
          <p class="text-sm text-gray-700">{{ certificate.notes }}</p>
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
                {{ formatDate(certificate.created_at) }}
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Última Atualização</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ formatDate(certificate.updated_at) }}
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Botões de Ação -->
      <div class="flex justify-center space-x-4">
        <Link :href="`/certificates/${certificate.id}/edit`" class="btn-primary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
          </svg>
          Editar Certificado
        </Link>
        <a
          :href="`/certificates/${certificate.id}/pdf`"
          target="_blank"
          class="btn-secondary"
        >
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
          </svg>
          Baixar PDF
        </a>
        <Link href="/certificates" class="btn-secondary">
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
  certificate: Object,
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
</script>
