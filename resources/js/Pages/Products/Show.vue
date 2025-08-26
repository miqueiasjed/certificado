<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Detalhes do Produto"
        :description="product.name">
        <template #actions>
          <Link :href="`/products/${product.id}/edit`" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
            </svg>
            Editar Produto
          </Link>
          <Link href="/products" class="btn-secondary">
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
              <div class="h-16 w-16 rounded-full bg-green-100 flex items-center justify-center">
                <svg class="h-8 w-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z"></path>
                </svg>
              </div>
            </div>
            <div class="flex-1">
              <h1 class="text-2xl font-bold text-gray-900">{{ product.name }}</h1>
              <p class="text-sm text-gray-500">ID: {{ product.id }}</p>
              <p class="text-sm text-gray-500">Criado em: {{ formatDate(product.created_at) }}</p>
            </div>
          </div>
        </div>
      </Card>

      <!-- Classificação Técnica -->
      <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- Princípio Ativo -->
        <Card>
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-medium text-gray-900">Princípio Ativo</h3>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                Obrigatório
              </span>
            </div>
            <div v-if="product.active_ingredient" class="space-y-2">
              <p class="text-sm text-gray-900 font-medium">{{ product.active_ingredient.name }}</p>
              <p class="text-xs text-gray-500">ID: {{ product.active_ingredient.id }}</p>
            </div>
            <div v-else class="text-sm text-gray-500 italic">
              Nenhum princípio ativo definido
            </div>
          </div>
        </Card>

        <!-- Grupo Químico -->
        <Card>
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-medium text-gray-900">Grupo Químico</h3>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                Obrigatório
              </span>
            </div>
            <div v-if="product.chemical_group" class="space-y-2">
              <p class="text-sm text-gray-900 font-medium">{{ product.chemical_group.name }}</p>
              <p class="text-xs text-gray-500">ID: {{ product.chemical_group.id }}</p>
            </div>
            <div v-else class="text-sm text-gray-500 italic">
              Nenhum grupo químico definido
            </div>
          </div>
        </Card>

        <!-- Antídoto -->
        <Card>
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-medium text-gray-900">Antídoto</h3>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                Obrigatório
              </span>
            </div>
            <div v-if="product.antidote" class="space-y-2">
              <p class="text-sm text-gray-900 font-medium">{{ product.antidote.name }}</p>
              <p class="text-xs text-gray-500">ID: {{ product.antidote.id }}</p>
            </div>
            <div v-else class="text-sm text-gray-500 italic">
              Nenhum antídoto definido
            </div>
          </div>
        </Card>

        <!-- Registro Ministerial -->
        <Card>
          <div class="p-6">
            <div class="flex items-center justify-between mb-4">
              <h3 class="text-lg font-medium text-gray-900">Reg. Min da Saude</h3>
              <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                Opcional
              </span>
            </div>
            <div v-if="product.organ_registration" class="space-y-2">
              <p class="text-sm text-gray-900 font-medium">{{ product.organ_registration.record }}</p>
              <p class="text-xs text-gray-500">ID: {{ product.organ_registration.id }}</p>
            </div>
            <div v-else class="text-sm text-gray-500 italic">
              Nenhum registro ministerial definido
            </div>
          </div>
        </Card>
      </div>

      <!-- Informações do Sistema -->
      <Card>
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Informações do Sistema</h3>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
              <dt class="text-sm font-medium text-gray-500">Data de Criação</dt>
              <dd class="mt-1 text-sm text-gray-900">{{ formatDate(product.created_at) }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Última Atualização</dt>
              <dd class="mt-1 text-sm text-gray-900">{{ formatDate(product.updated_at) }}</dd>
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
        <Link :href="`/products/${product.id}/edit`" class="btn-primary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
          </svg>
          Editar Produto
        </Link>
        <Link href="/products" class="btn-secondary">
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
  product: Object,
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
