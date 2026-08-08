<!--
  Cadastro do modelo de EPI (Plano 28, Task 28.6).

  É o cadastro do que a empresa compra, não a ficha de entrega: aqui vive o
  Certificado de Aprovação do fabricante, e a entrega ao técnico é o
  `EntregaDeEpiModal`.

  Número do CA, validade e vida útil ficam em branco sem qualquer marcação de
  erro, de propósito: a empresa cadastra o EPI antes de ter o certificado em
  mãos, e exigir o CA aqui empurraria o usuário a inventar um número para
  conseguir salvar. Campo em branco é estado neutro, nunca irregularidade.
-->
<template>
  <Modal :show="show" @close="fechar">
    <template #icon>
      <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z"
        />
      </svg>
    </template>

    <template #title>{{ epi ? 'Editar EPI' : 'Novo EPI' }}</template>

    <template #content>
      <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Nome *</label>
            <input
              v-model="form.nome"
              type="text"
              maxlength="255"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.nome }"
            />
            <p v-if="form.errors.nome" class="mt-1 text-sm text-red-600">{{ form.errors.nome }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
            <select
              v-model="form.tipo"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.tipo }"
            >
              <option v-for="tipo in tipos" :key="tipo" :value="tipo">{{ rotuloDeTipo(tipo) }}</option>
            </select>
            <p v-if="form.errors.tipo" class="mt-1 text-sm text-red-600">{{ form.errors.tipo }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Fabricante</label>
            <input
              v-model="form.fabricante"
              type="text"
              maxlength="255"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Número do CA</label>
            <input
              v-model="form.numero_ca"
              type="text"
              maxlength="255"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
            <p class="mt-1 text-xs text-gray-500">
              Pode ficar em branco enquanto o certificado não estiver em mãos.
            </p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Validade do CA</label>
            <input
              v-model="form.validade_ca"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.validade_ca }"
            />
            <p v-if="form.errors.validade_ca" class="mt-1 text-sm text-red-600">{{ form.errors.validade_ca }}</p>
            <p v-else class="mt-1 text-xs text-gray-500">
              Validade do certificado do fabricante. EPI com o CA vencido não pode ser entregue.
            </p>
          </div>

          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Vida útil, em dias</label>
            <input
              v-model="form.vida_util_dias"
              type="number"
              min="0"
              max="36500"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.vida_util_dias }"
            />
            <p v-if="form.errors.vida_util_dias" class="mt-1 text-sm text-red-600">
              {{ form.errors.vida_util_dias }}
            </p>
            <p v-else class="mt-1 text-xs text-gray-500">
              Em branco significa sem troca programada. É o prazo do item na mão do técnico, contado
              a partir da entrega, e não se confunde com a validade do CA.
            </p>
          </div>

          <div class="sm:col-span-2">
            <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
            <textarea
              v-model="form.observacoes"
              rows="3"
              maxlength="5000"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            ></textarea>
          </div>

          <div class="sm:col-span-2 space-y-2">
            <label class="flex items-center gap-2 text-sm text-gray-700">
              <input
                v-model="form.obrigatorio"
                type="checkbox"
                class="rounded border-gray-300 text-green-600 focus:ring-green-500"
              />
              Uso obrigatório em serviço
            </label>
            <label class="flex items-center gap-2 text-sm text-gray-700">
              <input
                v-model="form.ativo"
                type="checkbox"
                class="rounded border-gray-300 text-green-600 focus:ring-green-500"
              />
              Ativo, disponível para novas entregas
            </label>
          </div>
        </div>

        <p v-if="form.errors.epi" class="text-sm text-red-600">{{ form.errors.epi }}</p>
      </div>
    </template>

    <template #actions>
      <button type="button" class="btn-primary" :disabled="form.processing" @click="salvar">
        {{ form.processing ? 'Salvando...' : 'Salvar' }}
      </button>
      <button type="button" class="btn-secondary" :disabled="form.processing" @click="fechar">Cancelar</button>
    </template>
  </Modal>
</template>

<script setup>
import { watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { paraInputDate } from '@/utils/formatDate';
import { ROTULOS_DE_TIPO_DE_EPI } from '@/utils/epi';

const props = defineProps({
  show: { type: Boolean, default: false },
  epi: { type: Object, default: null },
  tipos: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

const form = useForm({
  nome: '',
  tipo: 'outro',
  fabricante: '',
  numero_ca: '',
  validade_ca: '',
  vida_util_dias: '',
  obrigatorio: false,
  ativo: true,
  observacoes: '',
});

function rotuloDeTipo(tipo) {
  return ROTULOS_DE_TIPO_DE_EPI[tipo] || tipo;
}

watch(
  () => props.show,
  (aberto) => {
    if (!aberto) return;

    form.clearErrors();
    form.nome = props.epi?.nome || '';
    form.tipo = props.epi?.tipo || 'outro';
    form.fabricante = props.epi?.fabricante || '';
    form.numero_ca = props.epi?.numero_ca || '';
    // Campo `date`: `paraInputDate` lê o dia sem passar por conversão de fuso,
    // que é o que roubaria um dia da validade do certificado.
    form.validade_ca = paraInputDate(props.epi?.validade_ca) || '';
    // Ausente e zero são coisas diferentes: zero é escolha do cadastro, e o
    // branco é "sem troca programada".
    form.vida_util_dias = props.epi?.vida_util_dias ?? '';
    form.obrigatorio = Boolean(props.epi?.obrigatorio);
    form.ativo = props.epi ? Boolean(props.epi.ativo) : true;
    form.observacoes = props.epi?.observacoes || '';
  }
);

function fechar() {
  emit('close');
}

// Campo em branco vai como nulo, e não como string vazia: no `vida_util_dias`
// essa é a diferença entre "sem troca programada" e um prazo inventado.
form.transform((dados) => ({
  ...dados,
  fabricante: dados.fabricante || null,
  numero_ca: dados.numero_ca || null,
  validade_ca: dados.validade_ca || null,
  vida_util_dias:
    dados.vida_util_dias === '' || dados.vida_util_dias === null
      ? null
      : Number(dados.vida_util_dias),
  observacoes: dados.observacoes || null,
}));

function salvar() {
  const opcoes = {
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      emit('close');
    },
  };

  if (props.epi) {
    form.put(route('epi.update', props.epi.id), opcoes);
    return;
  }

  form.post(route('epi.store'), opcoes);
}
</script>
