<template>
  <Modal :show="show" @close="fechar">
    <template #icon>
      <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M3 21h10M4 21V5a2 2 0 012-2h5a2 2 0 012 2v16M17 8l2 2v7a2 2 0 11-4 0"
        />
      </svg>
    </template>

    <template #title>Registrar abastecimento</template>

    <template #content>
      <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Data *</label>
            <input
              v-model="form.data"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.data }"
            />
            <p v-if="form.errors.data" class="mt-1 text-sm text-red-600">{{ form.errors.data }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Quilometragem do hodômetro *</label>
            <input
              v-model="form.km"
              type="number"
              min="0"
              step="1"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.km || avisoDeQuilometragem }"
            />
            <p v-if="avisoDeQuilometragem" class="mt-1 text-sm text-red-600">{{ avisoDeQuilometragem }}</p>
            <p v-else-if="form.errors.km" class="mt-1 text-sm text-red-600">{{ form.errors.km }}</p>
            <p v-else class="mt-1 text-xs text-gray-500">
              Última registrada: {{ numero(kmAtual) }} km.
            </p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Litros *</label>
            <input
              v-model="form.litros"
              type="number"
              min="0"
              step="0.001"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.litros }"
            />
            <p v-if="form.errors.litros" class="mt-1 text-sm text-red-600">{{ form.errors.litros }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Valor total *</label>
            <input
              v-model="form.valor_total"
              type="number"
              min="0"
              step="0.01"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.valor_total }"
            />
            <p v-if="form.errors.valor_total" class="mt-1 text-sm text-red-600">{{ form.errors.valor_total }}</p>
            <p v-if="valorPorLitro" class="mt-1 text-xs text-gray-500">
              Valor por litro: R$ {{ valorPorLitro }}
            </p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Combustível *</label>
            <select
              v-model="form.tipo_combustivel"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option v-for="tipo in combustiveis" :key="tipo" :value="tipo">{{ rotuloDeCombustivel(tipo) }}</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Posto</label>
            <input
              v-model="form.posto"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>
        </div>

        <div class="rounded-md border border-gray-200 bg-gray-50 p-3">
          <label class="flex items-start gap-3">
            <input
              v-model="form.tanque_cheio"
              type="checkbox"
              class="mt-1 h-4 w-4 text-green-600 border-gray-300 rounded focus:ring-green-500"
            />
            <span>
              <span class="block text-sm font-medium text-gray-900">Enchi o tanque</span>
              <span class="block text-xs text-gray-600">
                O consumo só é calculado entre dois tanques cheios: é o único trecho em que se sabe
                quanto combustível havia no tanque nas duas pontas. Marcar sem ter enchido produz um
                número errado que ninguém percebe.
              </span>
            </span>
          </label>
        </div>

        <div class="rounded-md border border-gray-200 p-3 space-y-3">
          <label class="flex items-start gap-3">
            <input
              v-model="form.gerar_titulo"
              type="checkbox"
              class="mt-1 h-4 w-4 text-green-600 border-gray-300 rounded focus:ring-green-500"
            />
            <span>
              <span class="block text-sm font-medium text-gray-900">Lançar como conta a pagar</span>
              <span class="block text-xs text-gray-600">
                Opcional. Sem marcar, o abastecimento fica só no controle da frota e não entra no
                financeiro.
              </span>
            </span>
          </label>

          <div v-if="form.gerar_titulo">
            <label class="block text-sm font-medium text-gray-700 mb-1">Fornecedor *</label>
            <select
              v-model="form.supplier_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.supplier_id }"
            >
              <option :value="null">Selecione</option>
              <option v-for="fornecedor in fornecedores" :key="fornecedor.id" :value="fornecedor.id">
                {{ fornecedor.nome }}
              </option>
            </select>
            <p v-if="form.errors.supplier_id" class="mt-1 text-sm text-red-600">{{ form.errors.supplier_id }}</p>
          </div>
        </div>
      </div>
    </template>

    <template #actions>
      <button type="button" class="btn-secondary" :disabled="form.processing" @click="fechar">Cancelar</button>
      <button
        type="button"
        class="btn-primary"
        :disabled="form.processing || Boolean(avisoDeQuilometragem)"
        @click="salvar"
      >
        {{ form.processing ? 'Salvando...' : 'Registrar' }}
      </button>
    </template>
  </Modal>
</template>

<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { hojeISO } from '@/utils/formatDate';

const props = defineProps({
  show: { type: Boolean, default: false },
  veiculoId: { type: [Number, String], required: true },
  kmAtual: { type: Number, default: 0 },
  fornecedores: { type: Array, default: () => [] },
  combustiveis: { type: Array, default: () => ['gasolina', 'etanol', 'diesel', 'gnv'] },
});

const emit = defineEmits(['close']);

const form = useForm({
  data: hojeISO(),
  km: null,
  litros: null,
  valor_total: null,
  tipo_combustivel: 'gasolina',
  posto: '',
  tanque_cheio: true,
  gerar_titulo: false,
  supplier_id: null,
});

const rotulosDeCombustivel = {
  gasolina: 'Gasolina',
  etanol: 'Etanol',
  diesel: 'Diesel',
  gnv: 'GNV',
};

function rotuloDeCombustivel(tipo) {
  return rotulosDeCombustivel[tipo] || tipo;
}

function numero(valor) {
  return new Intl.NumberFormat('pt-BR').format(Number(valor || 0));
}

/**
 * A recusa de quilometragem retroativa também existe no servidor, que é quem
 * manda. Aqui ela aparece antes do envio para o usuário corrigir o número
 * enquanto ainda está com a nota na mão, em vez de perder o formulário inteiro
 * e voltar com um erro depois de já ter digitado tudo.
 */
const avisoDeQuilometragem = computed(() => {
  if (form.km === null || form.km === '') return null;

  const informado = Number(form.km);
  if (Number.isNaN(informado)) return null;

  if (informado >= props.kmAtual) return null;

  return `A quilometragem informada (${numero(informado)} km) é menor que a última registrada `
    + `(${numero(props.kmAtual)} km). Confira o hodômetro: um valor para trás distorce o consumo `
    + 'de todos os intervalos seguintes.';
});

const valorPorLitro = computed(() => {
  const litros = Number(form.litros);
  const total = Number(form.valor_total);

  if (!litros || Number.isNaN(litros) || Number.isNaN(total)) return null;

  return new Intl.NumberFormat('pt-BR', {
    minimumFractionDigits: 4,
    maximumFractionDigits: 4,
  }).format(total / litros);
});

watch(
  () => props.show,
  (aberto) => {
    if (!aberto) return;

    form.clearErrors();
    form.data = hojeISO();
    form.km = null;
    form.litros = null;
    form.valor_total = null;
    form.tipo_combustivel = 'gasolina';
    form.posto = '';
    form.tanque_cheio = true;
    form.gerar_titulo = false;
    form.supplier_id = null;
  }
);

function fechar() {
  emit('close');
}

function salvar() {
  if (avisoDeQuilometragem.value) return;

  form.post(route('frota.abastecimentos.store', props.veiculoId), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      emit('close');
    },
  });
}
</script>
