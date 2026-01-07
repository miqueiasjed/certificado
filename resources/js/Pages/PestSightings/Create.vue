<template>
  <AuthenticatedLayout>
    <PageHeader
      title="Novo Avistamento de Praga"
      subtitle="Registre um novo avistamento de praga"
    />

    <div class="max-w-4xl mx-auto">
      <Card>
        <form @submit.prevent="submit" class="p-6 space-y-6">
          <!-- Endereço -->
          <div>
            <label for="address_id" class="block text-sm font-medium text-gray-700 mb-2">
              Endereço *
            </label>
            <select
              id="address_id"
              v-model="form.address_id"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.address_id }"
            >
              <option value="">Selecione um endereço</option>
              <option v-for="address in addresses" :key="address.id" :value="address.id">
                {{ address.nickname }} - {{ address.street }}, {{ address.number }} - {{ address.client?.name }}
              </option>
            </select>
            <p v-if="form.errors.address_id" class="mt-1 text-sm text-red-600">
              {{ form.errors.address_id }}
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

          <!-- Data do Avistamento -->
          <div>
            <label for="sighting_date" class="block text-sm font-medium text-gray-700 mb-2">
              Data do Avistamento *
            </label>
            <input
              id="sighting_date"
              v-model="form.sighting_date"
              type="datetime-local"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.sighting_date }"
            />
            <p v-if="form.errors.sighting_date" class="mt-1 text-sm text-red-600">
              {{ form.errors.sighting_date }}
            </p>
          </div>

          <!-- Tipo de Praga -->
          <div>
            <label for="pest_type" class="block text-sm font-medium text-gray-700 mb-2">
              Tipo de Praga *
            </label>
            <select
              id="pest_type"
              v-model="form.pest_type"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.pest_type }"
            >
              <option value="">Selecione o tipo de praga</option>
              <option value="rats">Ratos</option>
              <option value="mice">Camundongos</option>
              <option value="cockroaches">Baratas</option>
              <option value="ants">Formigas</option>
              <option value="termites">Cupins</option>
              <option value="flies">Moscas</option>
              <option value="fleas">Pulgas</option>
              <option value="ticks">Carrapatos</option>
              <option value="scorpions">Escorpiões</option>
              <option value="spiders">Aranhas</option>
              <option value="bees">Abelhas</option>
              <option value="wasps">Vespas</option>
              <option value="other">Outros</option>
            </select>
            <p v-if="form.errors.pest_type" class="mt-1 text-sm text-red-600">
              {{ form.errors.pest_type }}
            </p>
          </div>

          <!-- Nível de Severidade -->
          <div>
            <label for="severity_level" class="block text-sm font-medium text-gray-700 mb-2">
              Nível de Severidade *
            </label>
            <select
              id="severity_level"
              v-model="form.severity_level"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.severity_level }"
            >
              <option value="">Selecione o nível de severidade</option>
              <option value="low">Baixa</option>
              <option value="medium">Média</option>
              <option value="high">Alta</option>
              <option value="critical">Crítica</option>
            </select>
            <p v-if="form.errors.severity_level" class="mt-1 text-sm text-red-600">
              {{ form.errors.severity_level }}
            </p>
          </div>

          <!-- Descrição da Localização -->
          <div>
            <label for="location_description" class="block text-sm font-medium text-gray-700 mb-2">
              Descrição da Localização *
            </label>
            <textarea
              id="location_description"
              v-model="form.location_description"
              rows="3"
              required
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.location_description }"
              placeholder="Descreva onde a praga foi avistada (ex: cozinha, banheiro, área externa, etc.)"
            ></textarea>
            <p v-if="form.errors.location_description" class="mt-1 text-sm text-red-600">
              {{ form.errors.location_description }}
            </p>
          </div>

          <!-- Condições Ambientais -->
          <div>
            <label for="environmental_conditions" class="block text-sm font-medium text-gray-700 mb-2">
              Condições Ambientais
            </label>
            <textarea
              id="environmental_conditions"
              v-model="form.environmental_conditions"
              rows="3"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.environmental_conditions }"
              placeholder="Descreva as condições ambientais (ex: umidade, temperatura, iluminação, etc.)"
            ></textarea>
            <p v-if="form.errors.environmental_conditions" class="mt-1 text-sm text-red-600">
              {{ form.errors.environmental_conditions }}
            </p>
          </div>

          <!-- Medidas de Controle Aplicadas -->
          <div>
            <label for="control_measures_applied" class="block text-sm font-medium text-gray-700 mb-2">
              Medidas de Controle Aplicadas
            </label>
            <textarea
              id="control_measures_applied"
              v-model="form.control_measures_applied"
              rows="3"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.control_measures_applied }"
              placeholder="Descreva as medidas de controle aplicadas (ex: aplicação de inseticida, armadilhas, etc.)"
            ></textarea>
            <p v-if="form.errors.control_measures_applied" class="mt-1 text-sm text-red-600">
              {{ form.errors.control_measures_applied }}
            </p>
          </div>

          <!-- Observações do Técnico -->
          <div>
            <label for="technician_notes" class="block text-sm font-medium text-gray-700 mb-2">
              Observações do Técnico
            </label>
            <textarea
              id="technician_notes"
              v-model="form.technician_notes"
              rows="4"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.technician_notes }"
              placeholder="Adicione observações adicionais sobre o avistamento..."
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
              Avistamento ativo
            </label>
          </div>

          <!-- Botões -->
          <div class="flex justify-end space-x-3">
            <Link :href="route('pest-sightings.index')" class="btn-secondary">
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
              {{ form.processing ? 'Salvando...' : 'Salvar Avistamento' }}
            </button>
          </div>
        </form>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  addresses: Array,
  workOrders: Array,
  preselectedAddress: Number,
  preselectedWorkOrder: Number,
  errors: Object,
});

const form = useForm({
  address_id: props.preselectedAddress || '',
  work_order_id: props.preselectedWorkOrder || '',
  sighting_date: new Date().toISOString().slice(0, 16),
  pest_type: '',
  severity_level: '',
  location_description: '',
  environmental_conditions: '',
  control_measures_applied: '',
  technician_notes: '',
  active: true,
});

const submit = () => {
  form.post(route('pest-sightings.store'));
};
</script>
























