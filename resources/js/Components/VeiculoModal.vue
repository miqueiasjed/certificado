<template>
  <Modal :show="show" @close="fechar">
    <template #icon>
      <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M8 17a2 2 0 100-4 2 2 0 000 4zm8 0a2 2 0 100-4 2 2 0 000 4zM3 13h18l-1.5-5H4.5L3 13z"
        />
      </svg>
    </template>

    <template #title>{{ veiculo ? 'Editar veículo' : 'Novo veículo' }}</template>

    <template #content>
      <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Placa *</label>
            <input
              v-model="form.placa"
              type="text"
              maxlength="10"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 uppercase"
              :class="{ 'border-red-500': form.errors.placa }"
            />
            <p v-if="form.errors.placa" class="mt-1 text-sm text-red-600">{{ form.errors.placa }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
            <select
              v-model="form.tipo"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option v-for="tipo in tipos" :key="tipo" :value="tipo">{{ rotuloDeTipo(tipo) }}</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Marca *</label>
            <input
              v-model="form.marca"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.marca }"
            />
            <p v-if="form.errors.marca" class="mt-1 text-sm text-red-600">{{ form.errors.marca }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Modelo *</label>
            <input
              v-model="form.modelo"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.modelo }"
            />
            <p v-if="form.errors.modelo" class="mt-1 text-sm text-red-600">{{ form.errors.modelo }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ano</label>
            <input
              v-model="form.ano"
              type="number"
              min="1900"
              max="2100"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Situação</label>
            <select
              v-model="form.situacao"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option v-for="situacao in situacoes" :key="situacao" :value="situacao">
                {{ rotuloDeSituacao(situacao) }}
              </option>
            </select>
          </div>

          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Custo por quilômetro padrão</label>
            <input
              v-model="form.custo_km_padrao"
              type="number"
              step="0.0001"
              min="0"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
            <p class="mt-1 text-xs text-gray-500">
              Usado enquanto o veículo não tiver quatro abastecimentos de tanque cheio registrados.
              Até lá, o custo aparece marcado como padrão em vez de medido.
            </p>
          </div>
        </div>
      </div>
    </template>

    <template #actions>
      <button type="button" class="btn-secondary" :disabled="form.processing" @click="fechar">Cancelar</button>
      <button type="button" class="btn-primary" :disabled="form.processing" @click="salvar">
        {{ form.processing ? 'Salvando...' : 'Salvar' }}
      </button>
    </template>
  </Modal>
</template>

<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
  show: { type: Boolean, default: false },
  veiculo: { type: Object, default: null },
  tipos: { type: Array, default: () => ['carro', 'moto', 'utilitario', 'caminhao'] },
  situacoes: { type: Array, default: () => ['ativo', 'manutencao', 'inativo'] },
});

const emit = defineEmits(['close']);

const form = useForm({
  placa: '',
  marca: '',
  modelo: '',
  ano: null,
  tipo: 'carro',
  situacao: 'ativo',
  custo_km_padrao: null,
});

const rotulosDeTipo = {
  carro: 'Carro',
  moto: 'Moto',
  utilitario: 'Utilitário',
  caminhao: 'Caminhão',
};

const rotulosDeSituacao = {
  ativo: 'Ativo',
  manutencao: 'Em manutenção',
  inativo: 'Inativo',
};

function rotuloDeTipo(tipo) {
  return rotulosDeTipo[tipo] || tipo;
}

function rotuloDeSituacao(situacao) {
  return rotulosDeSituacao[situacao] || situacao;
}

watch(
  () => props.show,
  (aberto) => {
    if (!aberto) return;

    form.clearErrors();
    form.placa = props.veiculo?.placa || '';
    form.marca = props.veiculo?.marca || '';
    form.modelo = props.veiculo?.modelo || '';
    form.ano = props.veiculo?.ano ?? null;
    form.tipo = props.veiculo?.tipo || 'carro';
    form.situacao = props.veiculo?.situacao || 'ativo';
    form.custo_km_padrao = props.veiculo?.custo_km_padrao ?? null;
  }
);

function fechar() {
  emit('close');
}

function salvar() {
  const opcoes = {
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      emit('close');
    },
  };

  if (props.veiculo) {
    form.put(route('frota.update', props.veiculo.id), opcoes);
    return;
  }

  form.post(route('frota.store'), opcoes);
}
</script>
