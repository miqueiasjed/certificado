<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Detalhes do Endereço" description="Visualizar informações completas do endereço">
        <template #actions>
          <button @click="goBack" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </button>
          <Link :href="`/addresses/${address.id}/edit`" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
            </svg>
            Editar
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-6xl mx-auto space-y-6">
      <!-- Mensagens Flash -->
      <div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <div class="flex">
          <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-green-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
            </svg>
          </div>
          <div class="ml-3">
            <p class="text-sm font-medium text-green-800">
              {{ $page.props.flash.success }}
            </p>
          </div>
        </div>
      </div>

      <div v-if="$page.props.flash.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <div class="flex">
          <div class="flex-shrink-0">
            <svg class="h-5 w-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
            </svg>
          </div>
          <div class="ml-3">
            <p class="text-sm font-medium text-red-800">
              {{ $page.props.flash.error }}
            </p>
          </div>
        </div>
      </div>

      <!-- Informações do Endereço -->
      <Card>
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Informações do Endereço</h3>
        </div>
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label class="block text-sm font-medium text-gray-500">Apelido</label>
              <div class="mt-1">
                <span class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-800">
                  {{ address.nickname }}
                </span>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Status</label>
              <div class="mt-1">
                <span
                  :class="[
                    'inline-flex items-center px-3 py-1 rounded-full text-sm font-medium',
                    address.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                  ]"
                >
                  {{ address.active ? 'Ativo' : 'Inativo' }}
                </span>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">CEP</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ address.zip }}
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Endereço Completo</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ address.street }}, {{ address.number }}
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Bairro</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ address.district }}
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Cidade/Estado</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ address.city }}/{{ address.state }}
              </div>
            </div>
            <div v-if="address.reference" class="md:col-span-2">
              <label class="block text-sm font-medium text-gray-500">Referência</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ address.reference }}
              </div>
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
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label class="block text-sm font-medium text-gray-500">Nome</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ address.client?.name }}
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">CPF/CNPJ</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ address.client?.cnpj || address.client?.cpf }}
              </div>
            </div>
            <div v-if="address.client?.phone" class="md:col-span-2">
              <label class="block text-sm font-medium text-gray-500">Telefone</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ address.client.phone }}
              </div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Cômodos do Endereço -->
      <Card>
        <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
          <h3 class="text-lg font-medium text-gray-900">Cômodos do Endereço</h3>
          <button
            @click="showRoomModal = true"
            class="btn-primary"
          >
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            Novo Cômodo
          </button>
        </div>
        <div class="p-6">
          <div v-if="address.rooms && address.rooms.length > 0" class="space-y-4">
            <div
              v-for="room in address.rooms"
              :key="room.id"
              class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow"
            >
              <div class="flex items-start justify-between">
                <div class="flex-1">
                  <div class="flex items-center gap-3 mb-2">
                    <h4 class="text-md font-semibold text-gray-900">
                      {{ room.name }}
                    </h4>
                    <span
                      :class="[
                        'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                        room.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                      ]"
                    >
                      {{ room.active ? 'Ativo' : 'Inativo' }}
                    </span>
                  </div>

                  <div v-if="room.notes" class="text-sm text-gray-600 mb-3">
                    {{ room.notes }}
                  </div>

                  <div class="flex items-center gap-4 text-sm text-gray-500">
                    <div class="flex items-center gap-1">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 002 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>
                      </svg>
                      {{ room.devices?.length || 0 }} dispositivos
                    </div>
                  </div>
                </div>

                <div class="flex items-center gap-2">
                  <Link
                    :href="`/rooms/${room.id}`"
                    class="px-3 py-1 text-sm text-blue-600 hover:text-blue-800 font-medium"
                  >
                    Ver
                  </Link>
                  <Link
                    :href="`/rooms/${room.id}/edit`"
                    class="px-3 py-1 text-sm text-green-600 hover:text-green-800 font-medium"
                  >
                    Editar
                  </Link>
                </div>
              </div>
            </div>
          </div>
          <div v-else class="text-center py-8">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum cômodo cadastrado</h3>
            <p class="mt-1 text-sm text-gray-500">
              Comece criando o primeiro cômodo para este endereço.
            </p>
            <div class="mt-6">
              <button
                @click="showRoomModal = true"
                class="btn-primary"
              >
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Adicionar Cômodo
              </button>
            </div>
          </div>
        </div>
      </Card>

      <!-- Ordens de Serviço -->
      <Card v-if="address.work_orders && address.work_orders.length > 0">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Ordens de Serviço</h3>
        </div>
        <div class="p-6">
          <div class="space-y-4">
            <div
              v-for="workOrder in address.work_orders"
              :key="workOrder.id"
              class="border border-gray-200 rounded-lg p-4"
            >
              <div class="flex items-center justify-between">
                <div>
                  <h4 class="text-md font-semibold text-gray-900">
                    OS #{{ workOrder.id }}
                  </h4>
                  <p class="text-sm text-gray-600">
                    {{ workOrder.notes }}
                  </p>
                </div>
                <div class="flex items-center gap-2">
                  <span
                    :class="[
                      'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                      getStatusColor(workOrder.status)
                    ]"
                  >
                    {{ getStatusLabel(workOrder.status) }}
                  </span>
                  <Link
                    :href="`/work-orders/${workOrder.id}`"
                    class="px-3 py-1 text-sm text-blue-600 hover:text-blue-800 font-medium"
                  >
                    Ver
                  </Link>
                </div>
              </div>
            </div>
          </div>
        </div>
      </Card>
    </div>

    <!-- Modal para Criar Cômodo -->
    <RoomModal
      :show="showRoomModal"
      :address="address"
      :errors="errors"
      @close="showRoomModal = false"
      @room-created="refreshRooms"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import RoomModal from '@/Components/RoomModal.vue';

const props = defineProps({
  address: Object,
});

const showRoomModal = ref(false);

const refreshRooms = () => {
  // Recarregar a página para atualizar a lista de cômodos
  router.reload();
};

const getStatusColor = (status) => {
  const colors = {
    'open': 'bg-blue-100 text-blue-800',
    'in_progress': 'bg-yellow-100 text-yellow-800',
    'done': 'bg-green-100 text-green-800',
    'canceled': 'bg-red-100 text-red-800',
  };
  return colors[status] || 'bg-gray-100 text-gray-800';
};

const getStatusLabel = (status) => {
  const labels = {
    'open': 'Aberta',
    'in_progress': 'Em Andamento',
    'done': 'Concluída',
    'canceled': 'Cancelada',
  };
  return labels[status] || status;
};

const goBack = () => {
  window.history.back();
};
</script>
