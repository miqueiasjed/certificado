<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Criar Contrato" description="Configure os dados do contrato">
      </PageHeader>
    </template>

    <div class="max-w-4xl mx-auto">
      <Card>
        <form @submit.prevent="submit">
          <div class="p-6 space-y-6">
            <!-- Seleção de Endereço -->
            <div v-if="!address">
              <label for="address_id" class="block text-sm font-medium text-gray-700 mb-2">
                Endereço * <span class="text-gray-500 font-normal">(Cliente - Endereço)</span>
              </label>
              <select
                id="address_id"
                v-model="form.address_id"
                required
                @change="onAddressChange"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.address_id }"
              >
                <option value="">Selecione um endereço</option>
                <option v-for="addr in addresses" :key="addr.id" :value="addr.id">
                  {{ addr.client.name }} - {{ addr.nickname || `${addr.street}, ${addr.number}` }}
                </option>
              </select>
              <p v-if="form.errors.address_id" class="mt-1 text-sm text-red-600">
                {{ form.errors.address_id }}
              </p>
            </div>

            <!-- Informações do Endereço e Cliente (Readonly quando já selecionado) -->
            <div v-if="address || selectedAddress" class="bg-gray-50 p-4 rounded-lg">
              <h3 class="text-sm font-medium text-gray-700 mb-2">Endereço do Contrato</h3>
              <p class="text-gray-900">{{ (address || selectedAddress)?.nickname || (selectedAddress ? `${selectedAddress.street}, ${selectedAddress.number}` : '') }}</p>
              <p class="text-sm text-gray-600">
                {{ (address || selectedAddress)?.street }}, {{ (address || selectedAddress)?.number }}
                <span v-if="(address || selectedAddress)?.district"> - {{ (address || selectedAddress)?.district }}</span>
              </p>
              <p class="text-sm text-gray-600">{{ (address || selectedAddress)?.city }}/{{ (address || selectedAddress)?.state }}</p>
              <p class="text-sm text-gray-600 mt-2">Cliente: <strong>{{ (address || selectedAddress)?.client?.name || selectedAddressClient }}</strong></p>
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
                @change="updateEndDateIfPeriodSelected"
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
                Frequência de Visitas *
              </label>
              <select
                id="visit_frequency"
                v-model="form.visit_frequency"
                @change="onFrequencyChange"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.visit_frequency }"
              >
                <option value="">Selecione a frequência</option>
                <option value="weekly">Semanal</option>
                <option value="biweekly">Quinzenal</option>
                <option value="monthly">Mensal</option>
              </select>
              <p v-if="form.errors.visit_frequency" class="mt-1 text-sm text-red-600">
                {{ form.errors.visit_frequency }}
              </p>
            </div>

            <!-- Quantidade de Visitas (baseada na frequência) -->
            <div v-if="form.visit_frequency">
              <label for="visit_count" class="block text-sm font-medium text-gray-700 mb-2">
                Quantidade de Visitas {{ getFrequencyLabel() }} *
              </label>
              <input
                id="visit_count"
                v-model.number="form.visit_count"
                type="number"
                min="1"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.visit_count }"
                :placeholder="`Ex: 2 visitas ${getFrequencyLabel(true)}`"
              />
              <p v-if="form.errors.visit_count" class="mt-1 text-sm text-red-600">
                {{ form.errors.visit_count }}
              </p>
            </div>

            <!-- Valor do Serviço -->
            <div>
              <label for="service_value" class="block text-sm font-medium text-gray-700 mb-2">
                Valor do Serviço (R$)
              </label>
              <input
                id="service_value"
                v-model="serviceValueDisplay"
                @input="formatServiceValue"
                type="text"
                placeholder="0,00"
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


            <!-- Botões -->
            <div class="flex justify-end gap-3 pt-4 border-t">
              <Link
                href="/addresses"
                class="px-4 py-2 border border-gray-300 rounded-md text-gray-700 hover:bg-gray-50 transition-colors"
              >
                Cancelar
              </Link>
              <button
                type="submit"
                :disabled="form.processing"
                class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 transition-colors disabled:opacity-50"
              >
                {{ form.processing ? 'Salvando...' : 'Salvar Contrato' }}
              </button>
            </div>
          </div>
        </form>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  address: Object,
  addresses: Array,
});

const selectedPeriod = ref(null);
const selectedAddress = ref(null);
const selectedAddressClient = ref('');

const onAddressChange = () => {
  if (form.address_id) {
    const addr = props.addresses.find(a => a.id == form.address_id);
    if (addr) {
      selectedAddress.value = addr;
      selectedAddressClient.value = addr.client.name;
    }
  } else {
    selectedAddress.value = null;
    selectedAddressClient.value = '';
  }
};

const contractPeriods = [
  { months: 6, label: '6 Meses' },
  { months: 12, label: '1 Ano' },
  { months: 24, label: '2 Anos' },
  { months: 36, label: '3 Anos' },
];

const form = useForm({
  address_id: props.address?.id || '',
  start_date: new Date().toISOString().split('T')[0],
  end_date: null,
  service_value: null,
  service_type: 'pontual',
  visit_frequency: '',
  visit_count: null,
  pest_target: '',
  payment_method: '',
  payment_details: '',
});

const serviceValueDisplay = ref('');

// Se já veio com endereço, definir como selecionado
if (props.address) {
  selectedAddress.value = props.address;
  selectedAddressClient.value = props.address.client?.name || '';
}

const formatDate = (date) => {
  if (!date) return null;
  if (typeof date === 'string') {
    return date.split('T')[0];
  }
  return new Date(date).toISOString().split('T')[0];
};

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

// Função para formatar valor de moeda
const formatServiceValue = (event) => {
  const input = event.target;
  let value = input.value;

  // Remove tudo exceto números
  let numbers = value.replace(/\D/g, '');

  if (numbers === '') {
    serviceValueDisplay.value = '';
    form.service_value = null;
    return;
  }

  // Converte para centavos e depois para reais
  let amount = parseFloat(numbers) / 100;

  // Formata o valor
  let formatted = amount.toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });

  // Atualiza o display e o valor numérico
  serviceValueDisplay.value = formatted;
  form.service_value = amount;
};

// Função para obter o label da frequência
const getFrequencyLabel = (lowercase = false) => {
  const labels = {
    'weekly': lowercase ? 'por semana' : 'Por Semana',
    'biweekly': lowercase ? 'quinzenalmente' : 'Quinzenalmente',
    'monthly': lowercase ? 'por mês' : 'Por Mês'
  };
  return labels[form.visit_frequency] || '';
};

// Função quando mudar a frequência
const onFrequencyChange = () => {
  // Limpar a quantidade quando mudar a frequência
  form.visit_count = null;
};

// Função para converter valor formatado antes de enviar
const parseServiceValue = (value) => {
  if (!value || value === '') return null;
  const cleanValue = value.toString().replace(/[^\d,]/g, '');
  if (cleanValue === '') return null;
  const numericValue = cleanValue.replace(',', '.');
  return parseFloat(numericValue) || null;
};

const submit = () => {
  // Converter valor formatado para número antes de enviar
  form.service_value = parseServiceValue(serviceValueDisplay.value);

  // Se veio com endereço pré-selecionado, usar a rota específica
  if (props.address?.id) {
    form.post(`/addresses/${props.address.id}/contracts`, {
      onSuccess: () => {
        router.visit('/contracts');
      },
    });
  } else {
    // Senão, usar a rota geral
    form.post('/contracts', {
      onSuccess: () => {
        router.visit('/contracts');
      },
    });
  }
};
</script>

