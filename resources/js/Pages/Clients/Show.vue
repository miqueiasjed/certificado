<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Detalhes do Cliente"
        :description="client.name">
        <template #actions>
          <Link :href="`/clients/${client.id}/edit`" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
            </svg>
            Editar Cliente
          </Link>
          <Link href="/clients" class="btn-secondary">
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
          <div class="flex items-center space-x-4">
            <div class="flex-shrink-0">
              <div class="h-16 w-16 rounded-full bg-blue-100 flex items-center justify-center">
                <svg class="h-8 w-8 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
              </div>
            </div>
            <div class="flex-1">
              <h1 class="text-2xl font-bold text-gray-900">{{ client.name }}</h1>
              <p class="text-sm text-gray-500">ID: {{ client.id }}</p>
              <p class="text-sm text-gray-500">Criado em: {{ formatDate(client.created_at) }}</p>
            </div>
          </div>
        </div>
      </Card>

      <!-- Informações de Contato -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Email -->
        <Card>
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-medium text-gray-900">Email</h3>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                Obrigatório
              </span>
            </div>
            <div class="space-y-2">
              <p class="text-sm text-gray-900 font-medium">{{ client.email }}</p>
              <p class="text-xs text-gray-500">Endereço de email principal</p>
            </div>
          </div>
        </Card>

        <!-- Telefone -->
        <Card>
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-medium text-gray-900">Telefone</h3>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                Obrigatório
              </span>
            </div>
            <div class="space-y-2">
              <p class="text-sm text-gray-900 font-medium">{{ client.phone }}</p>
              <p class="text-xs text-gray-500">Telefone para contato</p>
            </div>
          </div>
        </Card>

        <!-- CPF/CNPJ -->
        <Card>
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-medium text-gray-900">CPF/CNPJ</h3>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                Obrigatório
              </span>
            </div>
            <div class="space-y-2">
              <p class="text-sm text-gray-900 font-medium">{{ client.cnpj }}</p>
              <p class="text-xs text-gray-500">Documento de identificação</p>
            </div>
          </div>
        </Card>

        <!-- Cidade -->
        <Card>
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-medium text-gray-900">Cidade</h3>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                Obrigatório
              </span>
            </div>
            <div class="space-y-2">
              <p class="text-sm text-gray-900 font-medium">{{ client.city }}</p>
              <p class="text-xs text-gray-500">Cidade de localização</p>
            </div>
          </div>
        </Card>
      </div>

      <!-- Informações Adicionais -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Estado -->
        <Card>
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-medium text-gray-900">Estado</h3>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                Opcional
              </span>
            </div>
            <div v-if="client.state" class="space-y-2">
              <p class="text-sm text-gray-900 font-medium">{{ client.state }}</p>
              <p class="text-xs text-gray-500">Unidade Federativa</p>
            </div>
            <div v-else class="text-sm text-gray-500 italic">
              Estado não informado
            </div>
          </div>
        </Card>

        <!-- CEP -->
        <Card>
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-medium text-gray-900">CEP</h3>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                Opcional
              </span>
            </div>
            <div v-if="client.zip_code" class="space-y-2">
              <p class="text-sm text-gray-900 font-medium">{{ client.zip_code }}</p>
              <p class="text-xs text-gray-500">Código de Endereçamento Postal</p>
            </div>
            <div v-else class="text-sm text-gray-500 italic">
              CEP não informado
            </div>
          </div>
        </Card>
      </div>

      <!-- Endereço -->
      <Card v-if="client.address">
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Endereço</h3>
          <div class="space-y-2">
            <p class="text-sm text-gray-900 font-medium">{{ client.address }}</p>
            <p class="text-xs text-gray-500">Endereço completo do cliente</p>
          </div>
        </div>
      </Card>

      <!-- Observações -->
      <Card v-if="client.notes">
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Observações</h3>
          <div class="space-y-2">
            <p class="text-sm text-gray-900">{{ client.notes }}</p>
            <p class="text-xs text-gray-500">Informações adicionais</p>
          </div>
        </div>
      </Card>

      <!-- Informações do Sistema -->
      <Card>
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Informações do Sistema</h3>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
              <dt class="text-sm font-medium text-gray-500">Data de Criação</dt>
              <dd class="mt-1 text-sm text-gray-900">{{ formatDate(client.created_at) }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Última Atualização</dt>
              <dd class="mt-1 text-sm text-gray-900">{{ formatDate(client.updated_at) }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Status</dt>
              <dd class="mt-1">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                  Ativo
                </span>
              </dd>
            </div>
          </div>
        </div>
      </Card>

      <!-- Ações -->
      <div class="flex justify-center space-x-4">
        <Link :href="`/clients/${client.id}/edit`" class="btn-primary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
          </svg>
          Editar Cliente
        </Link>
        <Link href="/clients" class="btn-secondary">
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
  client: Object,
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
