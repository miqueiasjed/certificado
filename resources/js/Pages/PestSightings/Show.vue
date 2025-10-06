<template>
  <AuthenticatedLayout>
    <PageHeader
      title="Detalhes do Avistamento"
      subtitle="Visualize todas as informações do avistamento de praga"
    />

    <div class="max-w-4xl mx-auto space-y-6">
      <!-- Breadcrumb de Navegação -->
      <Card>
        <div class="p-4">
          <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-4">
              <li>
                <Link :href="route('pest-sightings.index')" class="text-gray-400 hover:text-gray-500">
                  Avistamentos de Pragas
                </Link>
              </li>
              <li>
                <div class="flex items-center">
                  <svg class="flex-shrink-0 h-5 w-5 text-gray-300" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                  </svg>
                  <span class="ml-4 text-sm font-medium text-gray-500">Detalhes</span>
                </div>
              </li>
            </ol>
          </nav>
        </div>
      </Card>

      <!-- Informações Principais -->
      <Card>
        <div class="p-6">
          <div class="flex items-center space-x-4">
            <div class="flex-shrink-0">
              <div class="h-16 w-16 rounded-full bg-red-100 flex items-center justify-center">
                <svg class="h-8 w-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                </svg>
              </div>
            </div>
            <div class="flex-1">
              <h1 class="text-2xl font-bold text-gray-900">{{ pestSighting.pest_type_text }}</h1>
              <p class="text-sm text-gray-500">ID: {{ pestSighting.id }}</p>
              <p class="text-sm text-gray-500">Criado em: {{ formatDate(pestSighting.created_at) }}</p>
            </div>
            <div class="flex-shrink-0 space-y-2">
              <span
                :class="`inline-flex items-center px-3 py-1 rounded-full text-sm font-medium ${
                  pestSighting.severity_level === 'critical' ? 'bg-red-100 text-red-800' :
                  pestSighting.severity_level === 'high' ? 'bg-orange-100 text-orange-800' :
                  pestSighting.severity_level === 'medium' ? 'bg-yellow-100 text-yellow-800' :
                  'bg-green-100 text-green-800'
                }`"
              >
                {{ pestSighting.severity_level_text }}
              </span>
              <div>
                <span v-if="pestSighting.active" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                  Ativo
                </span>
                <span v-else class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                  Inativo
                </span>
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Detalhes do Avistamento -->
      <Card>
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Detalhes do Avistamento</h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Informações Básicas -->
            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-500">Tipo de Praga</label>
                <p class="mt-1 text-sm text-gray-900">{{ pestSighting.pest_type_text }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-500">Data do Avistamento</label>
                <p class="mt-1 text-sm text-gray-900">{{ formatDateTime(pestSighting.sighting_date) }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-500">Nível de Severidade</label>
                <p class="mt-1 text-sm text-gray-900">{{ pestSighting.severity_level_text }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-500">Ordem de Serviço</label>
                <p class="mt-1 text-sm text-gray-900">
                  {{ pestSighting.work_order?.order_number }}
                </p>
              </div>
            </div>

            <!-- Informações do Local -->
            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-500">Cliente</label>
                <p class="mt-1 text-sm text-gray-900">
                  {{ pestSighting.address?.client?.name }}
                </p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-500">Endereço</label>
                <p class="mt-1 text-sm text-gray-900">
                  {{ pestSighting.address?.street }}, {{ pestSighting.address?.number }}
                </p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-500">Cidade/Estado</label>
                <p class="mt-1 text-sm text-gray-900">
                  {{ pestSighting.address?.city }}/{{ pestSighting.address?.state }}
                </p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-500">CEP</label>
                <p class="mt-1 text-sm text-gray-900">
                  {{ pestSighting.address?.zip_code }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Detalhes Específicos -->
      <Card v-if="pestSighting.location_description || pestSighting.environmental_conditions || pestSighting.control_measures_applied || pestSighting.technician_notes">
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Informações Adicionais</h3>
          <div class="space-y-4">
            <div v-if="pestSighting.location_description">
              <label class="block text-sm font-medium text-gray-500">Descrição da Localização</label>
              <p class="mt-1 text-sm text-gray-900">{{ pestSighting.location_description }}</p>
            </div>
            <div v-if="pestSighting.environmental_conditions">
              <label class="block text-sm font-medium text-gray-500">Condições Ambientais</label>
              <p class="mt-1 text-sm text-gray-900">{{ pestSighting.environmental_conditions }}</p>
            </div>
            <div v-if="pestSighting.control_measures_applied">
              <label class="block text-sm font-medium text-gray-500">Medidas de Controle Aplicadas</label>
              <p class="mt-1 text-sm text-gray-900">{{ pestSighting.control_measures_applied }}</p>
            </div>
            <div v-if="pestSighting.technician_notes">
              <label class="block text-sm font-medium text-gray-500">Observações do Técnico</label>
              <p class="mt-1 text-sm text-gray-900">{{ pestSighting.technician_notes }}</p>
            </div>
          </div>
        </div>
      </Card>

      <!-- Ações -->
      <Card>
        <div class="p-6">
          <div class="flex justify-end space-x-3">
            <Link :href="route('pest-sightings.index')" class="btn-secondary">
              Voltar à Lista
            </Link>
            <Link :href="route('pest-sightings.edit', pestSighting.id)" class="btn-primary">
              <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
              </svg>
              Editar Avistamento
            </Link>
          </div>
        </div>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  pestSighting: Object,
});

const formatDate = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleDateString('pt-BR');
};

const formatDateTime = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleString('pt-BR');
};
</script>










