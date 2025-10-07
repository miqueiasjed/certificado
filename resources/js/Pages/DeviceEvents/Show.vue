<template>
  <AuthenticatedLayout>
    <PageHeader
      title="Detalhes do Evento"
      subtitle="Visualize todas as informações do evento do dispositivo"
    />

    <div class="max-w-4xl mx-auto space-y-6">
      <!-- Breadcrumb de Navegação -->
      <Card>
        <div class="p-4">
          <nav class="flex" aria-label="Breadcrumb">
            <ol class="flex items-center space-x-4">
              <li>
                <Link :href="route('device-events.index')" class="text-gray-400 hover:text-gray-500">
                  Eventos de Dispositivos
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
              <div class="h-16 w-16 rounded-full bg-green-100 flex items-center justify-center">
                <svg class="h-8 w-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path v-if="deviceEvent.event_type === 'bait_consumption'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10"></path>
                  <path v-else-if="deviceEvent.event_type === 'cleaning'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2.586a1 1 0 01-.293.707l-6.414 6.414a1 1 0 00-.293.707V17l-4 4v-6.586a1 1 0 00-.293-.707L3.293 7.207A1 1 0 013 6.5V4z"></path>
                  <path v-else-if="deviceEvent.event_type === 'bait_change'" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                  <path v-else stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                </svg>
              </div>
            </div>
            <div class="flex-1">
              <h1 class="text-2xl font-bold text-gray-900">{{ deviceEvent.event_type_text }}</h1>
              <p class="text-sm text-gray-500">ID: {{ deviceEvent.id }}</p>
              <p class="text-sm text-gray-500">Criado em: {{ formatDate(deviceEvent.created_at) }}</p>
            </div>
            <div class="flex-shrink-0">
              <span v-if="deviceEvent.active" class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                Ativo
              </span>
              <span v-else class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-800">
                Inativo
              </span>
            </div>
          </div>
        </div>
      </Card>

      <!-- Detalhes do Evento -->
      <Card>
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Detalhes do Evento</h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Informações Básicas -->
            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-500">Tipo de Evento</label>
                <p class="mt-1 text-sm text-gray-900">{{ deviceEvent.event_type_text }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-500">Data do Evento</label>
                <p class="mt-1 text-sm text-gray-900">{{ formatDateTime(deviceEvent.event_date) }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-500">Dispositivo</label>
                <p class="mt-1 text-sm text-gray-900">
                  {{ deviceEvent.device?.label }} ({{ deviceEvent.device?.number }})
                </p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-500">Ordem de Serviço</label>
                <p class="mt-1 text-sm text-gray-900">
                  {{ deviceEvent.work_order?.order_number }}
                </p>
              </div>
            </div>

            <!-- Informações do Local -->
            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-500">Cliente</label>
                <p class="mt-1 text-sm text-gray-900">
                  {{ deviceEvent.device?.room?.address?.client?.name }}
                </p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-500">Endereço</label>
                <p class="mt-1 text-sm text-gray-900">
                  {{ deviceEvent.device?.room?.address?.street }}, {{ deviceEvent.device?.room?.address?.number }}
                </p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-500">Cidade/Estado</label>
                <p class="mt-1 text-sm text-gray-900">
                  {{ deviceEvent.device?.room?.address?.city }}/{{ deviceEvent.device?.room?.address?.state }}
                </p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-500">Cômodo</label>
                <p class="mt-1 text-sm text-gray-900">
                  {{ deviceEvent.device?.room?.name }}
                </p>
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Detalhes Específicos por Tipo -->
      <Card v-if="deviceEvent.event_type === 'bait_consumption'">
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Consumo de Isca</h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label class="block text-sm font-medium text-gray-500">Status do Consumo</label>
              <p class="mt-1 text-sm text-gray-900">{{ deviceEvent.bait_consumption_status_text }}</p>
            </div>
            <div v-if="deviceEvent.bait_consumption_quantity">
              <label class="block text-sm font-medium text-gray-500">Quantidade Consumida</label>
              <p class="mt-1 text-sm text-gray-900">{{ deviceEvent.bait_consumption_quantity }}</p>
            </div>
          </div>
        </div>
      </Card>

      <Card v-if="deviceEvent.event_type === 'cleaning'">
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Limpeza</h3>
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label class="block text-sm font-medium text-gray-500">Limpeza Realizada</label>
              <p class="mt-1 text-sm text-gray-900">{{ deviceEvent.cleaning_status_text }}</p>
            </div>
            <div v-if="deviceEvent.cleaning_notes">
              <label class="block text-sm font-medium text-gray-500">Observações da Limpeza</label>
              <p class="mt-1 text-sm text-gray-900">{{ deviceEvent.cleaning_notes }}</p>
            </div>
          </div>
        </div>
      </Card>

      <Card v-if="deviceEvent.event_type === 'bait_change'">
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Troca de Isca</h3>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div v-if="deviceEvent.bait_change_type">
              <label class="block text-sm font-medium text-gray-500">Tipo da Nova Isca</label>
              <p class="mt-1 text-sm text-gray-900">{{ deviceEvent.bait_change_type }}</p>
            </div>
            <div v-if="deviceEvent.bait_change_lot">
              <label class="block text-sm font-medium text-gray-500">Lote da Nova Isca</label>
              <p class="mt-1 text-sm text-gray-900">{{ deviceEvent.bait_change_lot }}</p>
            </div>
            <div v-if="deviceEvent.bait_change_quantity">
              <label class="block text-sm font-medium text-gray-500">Quantidade da Nova Isca</label>
              <p class="mt-1 text-sm text-gray-900">{{ deviceEvent.bait_change_quantity }}</p>
            </div>
          </div>
        </div>
      </Card>

      <Card v-if="deviceEvent.event_type === 'technician_notes'">
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Observações do Técnico</h3>
          <div>
            <label class="block text-sm font-medium text-gray-500">Observações</label>
            <p class="mt-1 text-sm text-gray-900">{{ deviceEvent.technician_notes }}</p>
          </div>
        </div>
      </Card>

      <!-- Ações -->
      <Card>
        <div class="p-6">
          <div class="flex justify-end space-x-3">
            <Link :href="route('device-events.index')" class="btn-secondary">
              Voltar à Lista
            </Link>
            <Link :href="route('device-events.edit', deviceEvent.id)" class="btn-primary">
              <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
              </svg>
              Editar Evento
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
  deviceEvent: Object,
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












