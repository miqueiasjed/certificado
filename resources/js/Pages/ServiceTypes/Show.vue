<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader :title="serviceType.name" description="Detalhes do tipo de serviço">
        <template #actions>
          <div class="flex space-x-3">
            <Link
              :href="`/service-types/${serviceType.id}/edit`"
              class="btn-secondary"
            >
              <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
              </svg>
              Editar
            </Link>
            <button @click="goBack" class="btn-secondary">
              <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
              </svg>
              Voltar
            </button>
          </div>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-4xl mx-auto">
      <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Informações principais -->
        <div class="lg:col-span-2">
          <Card>
            <div class="p-6">
              <div class="flex items-start justify-between mb-6">
                <div>
                  <h2 class="text-2xl font-bold text-gray-900">{{ serviceType.name }}</h2>
                  <p class="text-sm text-gray-500 mt-1">{{ serviceType.slug }}</p>
                </div>
                <span
                  class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium"
                  :class="serviceType.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                >
                  {{ serviceType.active ? 'Ativo' : 'Inativo' }}
                </span>
              </div>

              <!-- Descrição -->
              <div class="mb-6">
                <h3 class="text-lg font-medium text-gray-900 mb-3">Descrição</h3>
                <div class="prose max-w-none">
                  <p v-if="serviceType.description" class="text-gray-700">
                    {{ serviceType.description }}
                  </p>
                  <p v-else class="text-gray-500 italic">
                    Nenhuma descrição fornecida
                  </p>
                </div>
              </div>

              <!-- Estatísticas -->
              <div class="grid grid-cols-2 gap-4">
                <div class="bg-gray-50 p-4 rounded-lg">
                  <div class="text-sm text-gray-500">Ordem de Exibição</div>
                  <div class="text-2xl font-semibold text-gray-900">{{ serviceType.sort_order }}</div>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg">
                  <div class="text-sm text-gray-500">Ordens de Serviço</div>
                  <div class="text-2xl font-semibold text-gray-900">{{ serviceOrdersCount }}</div>
                </div>
              </div>
            </div>
          </Card>
        </div>

        <!-- Informações do sistema -->
        <div>
          <Card>
            <div class="p-6">
              <h3 class="text-lg font-medium text-gray-900 mb-4">Informações do Sistema</h3>

              <dl class="space-y-4">
                <div>
                  <dt class="text-sm font-medium text-gray-500">ID</dt>
                  <dd class="text-sm text-gray-900">{{ serviceType.id }}</dd>
                </div>

                <div>
                  <dt class="text-sm font-medium text-gray-500">Slug</dt>
                  <dd class="text-sm text-gray-900 font-mono">{{ serviceType.slug }}</dd>
                </div>

                <div>
                  <dt class="text-sm font-medium text-gray-500">Status</dt>
                  <dd class="text-sm">
                    <span
                      class="inline-flex items-center px-2 py-1 rounded-full text-xs font-medium"
                      :class="serviceType.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                    >
                      {{ serviceType.active ? 'Ativo' : 'Inativo' }}
                    </span>
                  </dd>
                </div>

                <div>
                  <dt class="text-sm font-medium text-gray-500">Ordem</dt>
                  <dd class="text-sm text-gray-900">{{ serviceType.sort_order }}</dd>
                </div>

                <div>
                  <dt class="text-sm font-medium text-gray-500">Criado em</dt>
                  <dd class="text-sm text-gray-900">{{ formatDate(serviceType.created_at) }}</dd>
                </div>

                <div>
                  <dt class="text-sm font-medium text-gray-500">Atualizado em</dt>
                  <dd class="text-sm text-gray-900">{{ formatDate(serviceType.updated_at) }}</dd>
                </div>
              </dl>
            </div>
          </Card>

          <!-- Ações -->
          <Card class="mt-6">
            <div class="p-6">
              <h3 class="text-lg font-medium text-gray-900 mb-4">Ações</h3>

              <div class="space-y-3">
                <Link
                  :href="`/service-types/${serviceType.id}/edit`"
                  class="w-full flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-purple-600 hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500"
                >
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                  </svg>
                  Editar Tipo
                </Link>

                <button
                  @click="deleteServiceType"
                  class="w-full flex items-center justify-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                >
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                  </svg>
                  Excluir Tipo
                </button>
              </div>
            </div>
          </Card>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  serviceType: Object,
  serviceOrdersCount: Number,
});

const goBack = () => {
  window.history.back();
};

const deleteServiceType = () => {
  if (confirm(`Tem certeza que deseja excluir o tipo "${props.serviceType.name}"?`)) {
    router.delete(`/service-types/${props.serviceType.id}`, {
      onSuccess: () => {
        router.visit('/service-types');
      }
    });
  }
};

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('pt-BR');
};
</script>
