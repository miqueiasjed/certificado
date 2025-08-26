<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Detalhes do Técnico"
        :description="technician.name">
        <template #actions>
          <Link :href="`/technicians/${technician.id}/edit`" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
            </svg>
            Editar Técnico
          </Link>
          <Link href="/technicians" class="btn-secondary">
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
              <div class="h-16 w-16 rounded-full bg-indigo-100 flex items-center justify-center">
                <svg class="h-8 w-8 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                </svg>
              </div>
            </div>
            <div class="ml-6">
              <h1 class="text-2xl font-bold text-gray-900">
                {{ technician.name }}
              </h1>
              <p class="text-lg text-gray-600">
                {{ technician.specialty || 'Sem especialidade definida' }}
              </p>
              <p class="text-sm text-gray-500">
                Criado em {{ formatDate(technician.created_at) }}
              </p>
            </div>
          </div>
        </div>
      </Card>

      <!-- Informações de Contato -->
      <Card>
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Informações de Contato</h3>
        </div>
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-500">E-mail</label>
              <div class="mt-1">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-green-100 text-green-800">
                  {{ technician.email }}
                </span>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Telefone</label>
              <div class="mt-1">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                  {{ technician.phone }}
                </span>
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Informações Profissionais -->
      <Card>
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Informações Profissionais</h3>
        </div>
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label class="block text-sm font-medium text-gray-500">Especialidade</label>
              <div class="mt-1">
                <span v-if="technician.specialty" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-blue-100 text-blue-800">
                  {{ technician.specialty }}
                </span>
                <span v-else class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                  Não informado
                </span>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Número de Registro</label>
              <div class="mt-1">
                <span v-if="technician.registration_number" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-purple-100 text-purple-800">
                  {{ technician.registration_number }}
                </span>
                <span v-else class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                  Não informado
                </span>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Status</label>
              <div class="mt-1">
                <span v-if="technician.is_active" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-green-100 text-green-800">
                  Ativo
                </span>
                <span v-else class="inline-flex items-center px-2.5 py-0.5 rounded-full text-sm font-medium bg-red-100 text-red-800">
                  Inativo
                </span>
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Observações -->
      <Card v-if="technician.notes">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Observações</h3>
        </div>
        <div class="p-6">
          <p class="text-sm text-gray-700">{{ technician.notes }}</p>
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
                {{ formatDate(technician.created_at) }}
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Última Atualização</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ formatDate(technician.updated_at) }}
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Botões de Ação -->
      <div class="flex justify-center space-x-4">
        <Link :href="`/technicians/${technician.id}/edit`" class="btn-primary">
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
          </svg>
          Editar Técnico
        </Link>
        <Link href="/technicians" class="btn-secondary">
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
  technician: Object,
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
