<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Editar Contrato" description="Atualize os dados do contrato">
      </PageHeader>
    </template>

    <div class="max-w-4xl mx-auto">
      <Card>
        <form @submit.prevent="submit">
          <div class="p-6 space-y-6">
            <!-- Informações do Endereço e Cliente (Readonly) -->
            <div class="bg-gray-50 p-4 rounded-lg">
              <h3 class="text-sm font-medium text-gray-700 mb-2">Endereço do Contrato</h3>
              <p class="text-gray-900">{{ address.nickname }}</p>
              <p class="text-sm text-gray-600">{{ address.street }}, {{ address.number }} - {{ address.district }}</p>
              <p class="text-sm text-gray-600">{{ address.city }}/{{ address.state }}</p>
              <p class="text-sm text-gray-600 mt-2">Cliente: <strong>{{ address.client?.name }}</strong></p>
            </div>

            <!-- Tipo de Serviço -->
            <div>
              <label for="service_type" class="block text-sm font-medium text-gray-700 mb-2">
                Tipo de Serviço *
              </label>
              <select
                id="service_type"
                v-model="form.service_type"
                @change="handleServiceTypeChange"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              >
                <option value="pontual">Pontual (Visita Única)</option>
                <option value="periodico">Periódico (Manutenção Programada)</option>
              </select>
            </div>

            <!-- Data de Início -->
            <div>
              <label for="start_date" class="block text-sm font-medium text-gray-700 mb-2">
                Data de Início *
              </label>
              <input
                id="start_date"
                v-model="form.start_date"
                type="date"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              />
            </div>

            <!-- Data de Término -->
            <div>
              <label for="end_date" class="block text-sm font-medium text-gray-700 mb-2">
                Data de Término *
              </label>
              <input
                id="end_date"
                v-model="form.end_date"
                type="date"
                required
                :min="form.start_date"
                @change="updateEndDateIfPeriodSelected"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.end_date }"
              />
              <p v-if="form.errors.end_date" class="mt-1 text-sm text-red-600">
                {{ form.errors.end_date }}
              </p>
            </div>

            <!-- Duração do Contrato -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">
                Duração do Contrato
              </label>
              <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <button
                  type="button"
                  v-for="period in contractPeriods"
                  :key="period.months"
                  @click="setContractPeriod(period.months)"
                  class="px-4 py-2 border border-gray-300 rounded-md hover:bg-green-50 hover:border-green-500 transition-colors"
                  :class="{ 'bg-green-100 border-green-500': selectedPeriod === period.months }"
                >
                  {{ period.label }}
                </button>
              </div>
            </div>

            <!-- Frequência de Visitas -->
            <div>
              <label for="visit_frequency" class="block text-sm font-medium text-gray-700 mb-2">
                Frequência de Visitas (meses) *
              </label>
              <input
                id="visit_frequency"
                v-model.number="form.visit_frequency"
                type="number"
                min="1"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.visit_frequency }"
              />
              <p v-if="form.errors.visit_frequency" class="mt-1 text-sm text-red-600">
                {{ form.errors.visit_frequency }}
              </p>
            </div>

            <!-- Valor do Serviço -->
            <div>
              <label for="service_value" class="block text-sm font-medium text-gray-700 mb-2">
                Valor do Serviço (R$)
              </label>
              <input
                id="service_value"
                v-model.number="form.service_value"
                type="number"
                step="0.01"
                min="0"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              />
            </div>

            <!-- Pragas-Alvo -->
            <div>
              <label for="pest_target" class="block text-sm font-medium text-gray-700 mb-2">
                Pragas-Alvo do Tratamento
              </label>
              <textarea
                id="pest_target"
                v-model="form.pest_target"
                rows="3"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                placeholder="Ex: baratas, formigas, mosquitos, ratos, cupins, etc."
              ></textarea>
            </div>

            <!-- Forma de Pagamento -->
            <div>
              <label for="payment_method" class="block text-sm font-medium text-gray-700 mb-2">
                Forma de Pagamento
              </label>
              <textarea
                id="payment_method"
                v-model="form.payment_method"
                rows="2"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                placeholder="Ex: Pagamento à vista, PIX, parcelado em X vezes..."
              ></textarea>
            </div>

            <!-- Detalhes de Pagamento -->
            <div>
              <label for="payment_details" class="block text-sm font-medium text-gray-700 mb-2">
                Dados para Pagamento
              </label>
              <textarea
                id="payment_details"
                v-model="form.payment_details"
                rows="4"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                placeholder="Ex: Banco: ... Agência: ... Conta: ... Chave PIX: ..."
              ></textarea>
            </div>

            <!-- Período de Garantia -->
            <div>
              <label for="warranty_period_days" class="block text-sm font-medium text-gray-700 mb-2">
                Período de Garantia (dias)
              </label>
              <input
                id="warranty_period_days"
                v-model.number="form.warranty_period_days"
                type="number"
                min="1"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              />
            </div>

            <!-- Botões -->
            <div class="flex justify-end gap-3 pt-4 border-t">
              <Link
                href="/contracts"
                class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 transition-colors"
              >
                Cancelar
              </Link>
              <button
                type="submit"
                :disabled="form.processing"
                class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 transition-colors disabled:opacity-50"
              >
                {{ form.processing ? 'Salvando...' : 'Atualizar Contrato' }}
              </button>
            </div>
          </div>
        </form>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, onMounted } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  contract: Object,
  address: Object,
});

const selectedPeriod = ref(null);

const contractPeriods = [
  { months: 6, label: '6 Meses' },
  { months: 12, label: '1 Ano' },
  { months: 24, label: '2 Anos' },
  { months: 36, label: '3 Anos' },
];

const form = useForm({
  start_date: props.contract.start_date || new Date().toISOString().split('T')[0],
  end_date: props.contract.end_date || null,
  service_value: props.contract.service_value || null,
  service_type: props.contract.service_type || 'pontual',
  visit_frequency: props.contract.visit_frequency || 1,
  pest_target: props.contract.pest_target || '',
  payment_method: props.contract.payment_method || '',
  payment_details: props.contract.payment_details || '',
  warranty_period_days: props.contract.warranty_period_days || 90,
});

const setContractPeriod = (months) => {
  selectedPeriod.value = months;
  if (form.start_date) {
    const start = new Date(form.start_date);
    const end = new Date(start);
    end.setMonth(end.getMonth() + months);
    form.end_date = end.toISOString().split('T')[0];
  }
};

const updateEndDateIfPeriodSelected = () => {
  // Se tiver período selecionado, recalcular data de término
  if (selectedPeriod.value && form.start_date) {
    setContractPeriod(selectedPeriod.value);
  }
};

const handleServiceTypeChange = () => {
  // Não limpar mais os campos ao mudar o tipo
  // Todos os campos são obrigatórios agora
};

const submit = () => {
  form.put(`/contracts/${props.contract.id}`, {
    onSuccess: () => {
      router.visit('/contracts');
    },
  });
};

onMounted(() => {
  // Calcular período se tiver data de fim
  if (form.end_date && form.start_date) {
    const start = new Date(form.start_date);
    const end = new Date(form.end_date);
    const months = (end.getFullYear() - start.getFullYear()) * 12 + (end.getMonth() - start.getMonth());
    const period = contractPeriods.find(p => p.months === months);
    if (period) {
      selectedPeriod.value = months;
    }
  }
});
</script>

