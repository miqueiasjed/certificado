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

      <!-- Cômodos e Dispositivos do Endereço -->
      <Card>
        <!-- Tabs -->
        <div class="border-b border-gray-200">
          <nav class="-mb-px flex space-x-8 px-6" aria-label="Tabs">
            <button
              @click="activeTab = 'rooms'"
              :class="[
                'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm',
                activeTab === 'rooms'
                  ? 'border-green-500 text-green-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              ]"
            >
              Cômodos
              <span v-if="address.rooms" class="ml-2 bg-gray-100 text-gray-900 py-0.5 px-2.5 rounded-full text-xs font-medium">
                {{ address.rooms.length }}
              </span>
            </button>
            <button
              @click="activeTab = 'devices'"
              :class="[
                'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm',
                activeTab === 'devices'
                  ? 'border-green-500 text-green-600'
                  : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
              ]"
            >
              Dispositivos
              <span v-if="address.devices" class="ml-2 bg-gray-100 text-gray-900 py-0.5 px-2.5 rounded-full text-xs font-medium">
                {{ address.devices.length }}
              </span>
            </button>
          </nav>
        </div>

        <!-- Conteúdo da Aba Cômodos -->
        <div v-show="activeTab === 'rooms'" class="p-6">
          <div class="flex items-center justify-between mb-4">
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

                  <div v-if="room.notes" class="text-sm text-gray-600">
                    {{ room.notes }}
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

        <!-- Conteúdo da Aba Dispositivos -->
        <div v-show="activeTab === 'devices'" class="p-6">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900">Dispositivos do Endereço</h3>
            <button
              @click="showDeviceModal = true"
              class="btn-primary"
            >
              <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
              </svg>
              Novo Dispositivo
            </button>
          </div>

          <div v-if="address.devices && address.devices.length > 0" class="space-y-4">
            <div
              v-for="device in address.devices"
              :key="device.id"
              class="border border-gray-200 rounded-lg p-4 hover:shadow-md transition-shadow"
            >
              <div class="flex items-start justify-between">
                <div class="flex-1">
                  <div class="flex items-center gap-3 mb-2">
                    <h4 class="text-md font-semibold text-gray-900">
                      {{ device.label }} ({{ device.number }})
                    </h4>
                    <span
                      :class="[
                        'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                        device.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                      ]"
                    >
                      {{ device.active ? 'Ativo' : 'Inativo' }}
                    </span>
                    <span v-if="device.bait_type || device.baitType" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                      {{ (device.bait_type || device.baitType)?.name }}
                    </span>
                  </div>

                  <div v-if="device.default_location_note" class="text-sm text-gray-600 mb-3">
                    <strong>Localização:</strong> {{ device.default_location_note }}
                  </div>
                </div>

                <div class="flex items-center gap-2">
                  <Link
                    :href="`/devices/${device.id}`"
                    class="px-3 py-1 text-sm text-blue-600 hover:text-blue-800 font-medium"
                  >
                    Ver
                  </Link>
                  <Link
                    :href="`/devices/${device.id}/edit`"
                    class="px-3 py-1 text-sm text-green-600 hover:text-green-800 font-medium"
                  >
                    Editar
                  </Link>
                  <button
                    @click="deleteDevice(device.id)"
                    class="px-3 py-1 text-sm text-red-600 hover:text-red-800 font-medium"
                  >
                    Excluir
                  </button>
                </div>
              </div>
            </div>
          </div>
          <div v-else class="text-center py-8">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum dispositivo cadastrado</h3>
            <p class="mt-1 text-sm text-gray-500">
              Comece criando o primeiro dispositivo para este endereço.
            </p>
            <div class="mt-6">
              <button
                @click="showDeviceModal = true"
                class="btn-primary"
              >
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Adicionar Dispositivo
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

    <!-- Modal para Criar Dispositivo -->
    <div v-if="showDeviceModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4">
      <div class="relative bg-white rounded-xl shadow-xl max-w-2xl w-full mx-4 transform transition-all">
        <div class="p-6">
          <div class="flex items-center justify-between mb-6">
            <h3 class="text-xl font-semibold text-gray-900">Novo Dispositivo</h3>
            <button @click="showDeviceModal = false" class="text-gray-400 hover:text-gray-600 transition-colors">
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>
          </div>

          <form @submit.prevent="saveDevice" class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                Nome/Etiqueta *
              </label>
              <input
                v-model="deviceForm.label"
                type="text"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                placeholder="Ex: Ratoeira 1"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                Número/Identificação *
              </label>
              <input
                v-model="deviceForm.number"
                type="text"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                placeholder="Ex: 001"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                Tipo de Isca
              </label>
              <select
                v-model="deviceForm.bait_type_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              >
                <option value="">Selecione um tipo de isca...</option>
                <option
                  v-for="baitType in baitTypes"
                  :key="baitType.id"
                  :value="baitType.id"
                >
                  {{ baitType.name }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                Localização Padrão
              </label>
              <textarea
                v-model="deviceForm.default_location_note"
                rows="2"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                placeholder="Ex: Perto da porta de entrada"
              ></textarea>
            </div>

            <div class="flex items-center">
              <input
                id="device-active"
                v-model="deviceForm.active"
                type="checkbox"
                class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded"
              />
              <label for="device-active" class="ml-2 block text-sm text-gray-900">
                Dispositivo ativo
              </label>
            </div>

            <div class="flex justify-end space-x-3 pt-4 border-t border-gray-200">
              <button
                type="button"
                @click="showDeviceModal = false"
                class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500"
                :disabled="isSavingDevice"
              >
                Cancelar
              </button>
              <button
                type="submit"
                class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 disabled:opacity-50 disabled:cursor-not-allowed"
                :disabled="isSavingDevice"
              >
                <span v-if="isSavingDevice">Salvando...</span>
                <span v-else>Salvar Dispositivo</span>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import RoomModal from '@/Components/RoomModal.vue';

const props = defineProps({
  address: Object,
  baitTypes: {
    type: Array,
    default: () => []
  }
});

const activeTab = ref('rooms');
const showRoomModal = ref(false);
const showDeviceModal = ref(false);
const isSavingDevice = ref(false);

const deviceForm = useForm({
  label: '',
  number: '',
  bait_type_id: '',
  default_location_note: '',
  active: true,
});

const saveDevice = async () => {
  isSavingDevice.value = true;
  try {
    const response = await fetch(`/addresses/${props.address.id}/devices`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        'Accept': 'application/json',
      },
      body: JSON.stringify(deviceForm.data()),
    });

    const data = await response.json();

    if (response.ok) {
      showDeviceModal.value = false;
      deviceForm.reset();
      router.reload();
    } else {
      alert(data.message || 'Erro ao criar dispositivo');
    }
  } catch (error) {
    console.error('Erro ao criar dispositivo:', error);
    alert('Erro ao criar dispositivo');
  } finally {
    isSavingDevice.value = false;
  }
};

const refreshRooms = () => {
  // Recarregar a página para atualizar a lista de cômodos
  router.reload();
};

const deleteDevice = async (deviceId) => {
  if (!confirm('Tem certeza que deseja excluir este dispositivo?')) {
    return;
  }

  try {
    const response = await fetch(`/addresses/${props.address.id}/devices/${deviceId}`, {
      method: 'DELETE',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        'Accept': 'application/json',
      },
    });

    const data = await response.json();

    if (response.ok) {
      router.reload();
    } else {
      alert(data.message || 'Erro ao excluir dispositivo');
    }
  } catch (error) {
    console.error('Erro ao excluir dispositivo:', error);
    alert('Erro ao excluir dispositivo');
  }
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
