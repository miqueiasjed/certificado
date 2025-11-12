<template>
  <AuthenticatedLayout>
    <PageHeader
      title="Novo Evento de Dispositivo"
      subtitle="Registre um novo evento para um dispositivo"
    />

    <div class="max-w-4xl mx-auto">
      <Card>
        <form @submit.prevent="submit" class="p-6 space-y-6">
          <!-- Dispositivo -->
          <div>
            <label for="device_id" class="block text-sm font-medium text-gray-700 mb-2">
              Dispositivo *
            </label>
            <select
              id="device_id"
              v-model="form.device_id"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.device_id }"
            >
              <option value="">Selecione um dispositivo</option>
              <option v-for="device in devices" :key="device.id" :value="device.id">
                {{ device.label }} ({{ device.number }}) - {{ device.room?.address?.client?.name }} - {{ device.room?.address?.street }}, {{ device.room?.address?.number }}
              </option>
            </select>
            <p v-if="form.errors.device_id" class="mt-1 text-sm text-red-600">
              {{ form.errors.device_id }}
            </p>
          </div>

          <!-- Ordem de Serviço -->
          <div>
            <label for="work_order_id" class="block text-sm font-medium text-gray-700 mb-2">
              Ordem de Serviço *
            </label>
            <select
              id="work_order_id"
              v-model="form.work_order_id"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.work_order_id }"
            >
              <option value="">Selecione uma ordem de serviço</option>
              <option v-for="workOrder in workOrders" :key="workOrder.id" :value="workOrder.id">
                {{ workOrder.order_number }} - {{ workOrder.client?.name }} - {{ workOrder.address?.street }}, {{ workOrder.address?.number }}
              </option>
            </select>
            <p v-if="form.errors.work_order_id" class="mt-1 text-sm text-red-600">
              {{ form.errors.work_order_id }}
            </p>
          </div>

          <!-- Tipo de Evento -->
          <div>
            <label for="event_type" class="block text-sm font-medium text-gray-700 mb-2">
              Tipo de Evento *
            </label>
            <select
              id="event_type"
              v-model="form.event_type"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.event_type }"
            >
              <option value="">Selecione o tipo de evento</option>
              <option value="bait_consumption">Consumo de Isca</option>
              <option value="cleaning">Limpeza</option>
              <option value="bait_change">Troca de Isca</option>
              <option value="technician_notes">Observações do Técnico</option>
            </select>
            <p v-if="form.errors.event_type" class="mt-1 text-sm text-red-600">
              {{ form.errors.event_type }}
            </p>
          </div>

          <!-- Data do Evento -->
          <div>
            <label for="event_date" class="block text-sm font-medium text-gray-700 mb-2">
              Data do Evento *
            </label>
            <input
              id="event_date"
              v-model="form.event_date"
              type="datetime-local"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.event_date }"
            />
            <p v-if="form.errors.event_date" class="mt-1 text-sm text-red-600">
              {{ form.errors.event_date }}
            </p>
          </div>

          <!-- Campos específicos por tipo de evento -->

          <!-- Consumo de Isca -->
          <div v-if="form.event_type === 'bait_consumption'" class="space-y-4">
            <div>
              <label for="bait_consumption_status" class="block text-sm font-medium text-gray-700 mb-2">
                Status do Consumo *
              </label>
              <select
                id="bait_consumption_status"
                v-model="form.bait_consumption_status"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.bait_consumption_status }"
              >
                <option value="">Selecione o status</option>
                <option value="partial">Parcial</option>
                <option value="total">Total</option>
                <option value="none">Não houve</option>
                <option value="spoiled">Estragada</option>
                <option value="replacement">Reposição</option>
              </select>
              <p v-if="form.errors.bait_consumption_status" class="mt-1 text-sm text-red-600">
                {{ form.errors.bait_consumption_status }}
              </p>
            </div>

            <div>
              <label for="bait_consumption_quantity" class="block text-sm font-medium text-gray-700 mb-2">
                Quantidade Consumida
              </label>
              <input
                id="bait_consumption_quantity"
                v-model="form.bait_consumption_quantity"
                type="number"
                step="0.01"
                min="0"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.bait_consumption_quantity }"
                placeholder="0.00"
              />
              <p v-if="form.errors.bait_consumption_quantity" class="mt-1 text-sm text-red-600">
                {{ form.errors.bait_consumption_quantity }}
              </p>
            </div>
          </div>

          <!-- Limpeza -->
          <div v-if="form.event_type === 'cleaning'" class="space-y-4">
            <div>
              <label for="cleaning_done" class="block text-sm font-medium text-gray-700 mb-2">
                Limpeza Realizada *
              </label>
              <div class="flex items-center space-x-4">
                <label class="flex items-center">
                  <input
                    type="radio"
                    v-model="form.cleaning_done"
                    :value="true"
                    class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300"
                  />
                  <span class="ml-2 text-sm text-gray-700">Sim</span>
                </label>
                <label class="flex items-center">
                  <input
                    type="radio"
                    v-model="form.cleaning_done"
                    :value="false"
                    class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300"
                  />
                  <span class="ml-2 text-sm text-gray-700">Não</span>
                </label>
              </div>
              <p v-if="form.errors.cleaning_done" class="mt-1 text-sm text-red-600">
                {{ form.errors.cleaning_done }}
              </p>
            </div>

            <div>
              <label for="cleaning_notes" class="block text-sm font-medium text-gray-700 mb-2">
                Observações da Limpeza
              </label>
              <textarea
                id="cleaning_notes"
                v-model="form.cleaning_notes"
                rows="3"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.cleaning_notes }"
                placeholder="Descreva detalhes da limpeza realizada..."
              ></textarea>
              <p v-if="form.errors.cleaning_notes" class="mt-1 text-sm text-red-600">
                {{ form.errors.cleaning_notes }}
              </p>
            </div>
          </div>

          <!-- Troca de Isca -->
          <div v-if="form.event_type === 'bait_change'" class="space-y-4">
            <div>
              <label for="bait_change_type" class="block text-sm font-medium text-gray-700 mb-2">
                Tipo da Nova Isca
              </label>
              <input
                id="bait_change_type"
                v-model="form.bait_change_type"
                type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.bait_change_type }"
                placeholder="Ex: Isca para Ratos, Isca para Baratas"
              />
              <p v-if="form.errors.bait_change_type" class="mt-1 text-sm text-red-600">
                {{ form.errors.bait_change_type }}
              </p>
            </div>

            <div>
              <label for="bait_change_lot" class="block text-sm font-medium text-gray-700 mb-2">
                Lote da Nova Isca
              </label>
              <input
                id="bait_change_lot"
                v-model="form.bait_change_lot"
                type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.bait_change_lot }"
                placeholder="Ex: LOTE-2024-001"
              />
              <p v-if="form.errors.bait_change_lot" class="mt-1 text-sm text-red-600">
                {{ form.errors.bait_change_lot }}
              </p>
            </div>

            <div>
              <label for="bait_change_quantity" class="block text-sm font-medium text-gray-700 mb-2">
                Quantidade da Nova Isca
              </label>
              <input
                id="bait_change_quantity"
                v-model="form.bait_change_quantity"
                type="number"
                step="0.01"
                min="0"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.bait_change_quantity }"
                placeholder="0.00"
              />
              <p v-if="form.errors.bait_change_quantity" class="mt-1 text-sm text-red-600">
                {{ form.errors.bait_change_quantity }}
              </p>
            </div>
          </div>

          <!-- Observações do Técnico -->
          <div v-if="form.event_type === 'technician_notes'">
            <label for="technician_notes" class="block text-sm font-medium text-gray-700 mb-2">
              Observações do Técnico *
            </label>
            <textarea
              id="technician_notes"
              v-model="form.technician_notes"
              rows="4"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.technician_notes }"
              placeholder="Descreva suas observações sobre o dispositivo..."
            ></textarea>
            <p v-if="form.errors.technician_notes" class="mt-1 text-sm text-red-600">
              {{ form.errors.technician_notes }}
            </p>
          </div>

          <!-- Status Ativo -->
          <div class="flex items-center">
            <input
              id="active"
              v-model="form.active"
              type="checkbox"
              class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded"
            />
            <label for="active" class="ml-2 block text-sm text-gray-900">
              Evento ativo
            </label>
          </div>

          <!-- Botões -->
          <div class="flex justify-end space-x-3">
            <Link :href="route('device-events.index')" class="btn-secondary">
              Cancelar
            </Link>
            <button
              type="submit"
              :disabled="form.processing"
              class="btn-primary"
            >
              <svg v-if="form.processing" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ form.processing ? 'Salvando...' : 'Salvar Evento' }}
            </button>
          </div>
        </form>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  devices: Array,
  workOrders: Array,
  preselectedDevice: Number,
  preselectedWorkOrder: Number,
  errors: Object,
});

const form = useForm({
  device_id: props.preselectedDevice || '',
  work_order_id: props.preselectedWorkOrder || '',
  event_type: '',
  event_date: new Date().toISOString().slice(0, 16),
  bait_consumption_status: '',
  bait_consumption_quantity: '',
  cleaning_done: false,
  cleaning_notes: '',
  bait_change_type: '',
  bait_change_lot: '',
  bait_change_quantity: '',
  technician_notes: '',
  active: true,
});

// Limpar campos específicos quando o tipo de evento muda
watch(() => form.event_type, (newType) => {
  // Limpar campos específicos do tipo anterior
  form.bait_consumption_status = '';
  form.bait_consumption_quantity = '';
  form.cleaning_done = false;
  form.cleaning_notes = '';
  form.bait_change_type = '';
  form.bait_change_lot = '';
  form.bait_change_quantity = '';
  form.technician_notes = '';
});

const submit = () => {
  form.post(route('device-events.store'));
};
</script>





















