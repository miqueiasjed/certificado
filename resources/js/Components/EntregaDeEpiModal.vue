<!--
  Registro da entrega de um EPI ao técnico (Plano 28, Task 28.6).

  Duas escolhas de tela sustentam este modal:

  - O EPI com o CA VENCIDO aparece na lista desabilitado, com a razão escrita.
    A recusa existe no `PpeDeliveryService` de qualquer forma; mostrá-la aqui
    evita que o usuário preencha tudo e só descubra o problema no envio.
  - O quadro abaixo do campo mostra o CA e a validade QUE SERÃO GRAVADOS na
    ficha. O CA é copiado no ato da entrega e nunca mais é lido pelo cadastro:
    a ficha diz o que foi entregue naquele dia, e o usuário precisa ver isso
    antes de confirmar.

  EPI sem CA cadastrado é aceito e aparece em cinza, como estado neutro: campo em
  branco não é irregularidade, e só o CA vencido é que impede a entrega.
-->
<template>
  <Modal :show="show" @close="fechar">
    <template #icon>
      <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"
        />
      </svg>
    </template>

    <template #title>Registrar entrega de EPI</template>

    <template #content>
      <div class="space-y-4">
        <p class="text-sm text-gray-600">
          Entrega para <strong class="text-gray-900">{{ tecnico.name }}</strong>.
        </p>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">EPI *</label>
          <select
            v-model="form.personal_protective_equipment_id"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            :class="{ 'border-red-500': form.errors.personal_protective_equipment_id }"
          >
            <option :value="null" disabled>Escolha o EPI</option>
            <option
              v-for="opcao in epis"
              :key="opcao.id"
              :value="opcao.id"
              :disabled="caVencido(opcao)"
            >
              {{ opcao.nome }}{{ opcao.fabricante ? ` — ${opcao.fabricante}` : '' }}
              <template v-if="caVencido(opcao)">
                (CA vencido em {{ formatarData(opcao.validade_ca) }} — renove o CA no cadastro)
              </template>
            </option>
          </select>
          <p v-if="form.errors.personal_protective_equipment_id" class="mt-1 text-sm text-red-600">
            {{ form.errors.personal_protective_equipment_id }}
          </p>
          <p v-else-if="temEpiComCaVencido" class="mt-1 text-xs text-gray-500">
            Os itens desabilitados estão com o Certificado de Aprovação vencido. Entregar EPI com o
            CA vencido é a própria infração que este registro deveria evitar: renove o CA no cadastro
            do EPI e volte aqui.
          </p>
        </div>

        <!-- O que vai para a ficha -->
        <div v-if="epiEscolhido" class="rounded-md border border-gray-200 bg-gray-50 p-4 space-y-2">
          <p class="text-xs font-medium uppercase tracking-wider text-gray-500">
            O que será gravado na ficha
          </p>
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm">
            <div>
              <span class="text-gray-500">Número do CA:</span>
              <span v-if="epiEscolhido.numero_ca" class="ml-1 text-gray-900">
                {{ epiEscolhido.numero_ca }}
              </span>
              <span v-else class="ml-1 text-gray-500">Não informado</span>
            </div>
            <div>
              <span class="text-gray-500">Validade do CA:</span>
              <span v-if="epiEscolhido.validade_ca" class="ml-1 text-gray-900">
                {{ formatarData(epiEscolhido.validade_ca) }}
              </span>
              <span v-else class="ml-1 text-gray-500">Não informada</span>
            </div>
            <div class="sm:col-span-2">
              <span class="text-gray-500">Troca programada:</span>
              <span v-if="trocaPrevista" class="ml-1 text-gray-900">
                {{ formatarData(trocaPrevista) }}
                <span class="text-gray-500">
                  (vida útil de {{ epiEscolhido.vida_util_dias }} dia(s))
                </span>
              </span>
              <span v-else class="ml-1 text-gray-500">{{ TEXTO_SEM_TROCA_PROGRAMADA }}</span>
            </div>
          </div>
          <p class="text-xs text-gray-500">
            O CA é copiado agora e não muda depois: renovar o certificado no cadastro não reescreve
            esta ficha.
          </p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Quantidade *</label>
            <input
              v-model="form.quantidade"
              type="number"
              min="1"
              max="1000"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.quantidade }"
            />
            <p v-if="form.errors.quantidade" class="mt-1 text-sm text-red-600">
              {{ form.errors.quantidade }}
            </p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Data da entrega *</label>
            <input
              v-model="form.entregue_em"
              type="date"
              :max="hoje"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.entregue_em }"
            />
            <p v-if="form.errors.entregue_em" class="mt-1 text-sm text-red-600">
              {{ form.errors.entregue_em }}
            </p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Motivo *</label>
            <select
              v-model="form.motivo"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.motivo }"
            >
              <option v-for="motivo in motivos" :key="motivo" :value="motivo">
                {{ rotuloDeMotivoDeEntrega(motivo) }}
              </option>
            </select>
            <p v-if="form.errors.motivo" class="mt-1 text-sm text-red-600">{{ form.errors.motivo }}</p>
          </div>
        </div>

        <p v-if="form.errors.technician_id" class="text-sm text-red-600">
          {{ form.errors.technician_id }}
        </p>

        <p class="text-xs text-gray-500">
          A entrega não se edita depois de registrada: a correção de um erro é o estorno, com motivo,
          e a linha errada continua na ficha.
        </p>
      </div>
    </template>

    <template #actions>
      <button
        type="button"
        class="btn-primary"
        :disabled="form.processing || !form.personal_protective_equipment_id"
        @click="salvar"
      >
        {{ form.processing ? 'Registrando...' : 'Registrar entrega' }}
      </button>
      <button type="button" class="btn-secondary" :disabled="form.processing" @click="fechar">
        Cancelar
      </button>
    </template>
  </Modal>
</template>

<script setup>
import { computed, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { formatarData, hojeISO } from '@/utils/formatDate';
import {
  TEXTO_SEM_TROCA_PROGRAMADA,
  caVencido,
  rotuloDeMotivoDeEntrega,
  somarDias,
} from '@/utils/epi';

const props = defineProps({
  show: { type: Boolean, default: false },
  tecnico: { type: Object, required: true },
  epis: { type: Array, default: () => [] },
  motivos: { type: Array, default: () => [] },
});

const emit = defineEmits(['close']);

// "Hoje" vem do fuso do negócio, nunca de `toISOString`, que das 21h à
// meia-noite de Brasília já devolve o dia seguinte — e data futura é recusada.
const hoje = hojeISO();

const form = useForm({
  technician_id: props.tecnico.id,
  personal_protective_equipment_id: null,
  quantidade: 1,
  entregue_em: hoje,
  motivo: 'primeira_entrega',
});

const epiEscolhido = computed(() =>
  props.epis.find((item) => Number(item.id) === Number(form.personal_protective_equipment_id)) || null
);

const temEpiComCaVencido = computed(() => props.epis.some((item) => caVencido(item)));

/**
 * Prévia da troca programada, com a mesma conta do `PpeDeliveryService`:
 * dia da entrega + vida útil, e nada quando a vida útil não está cadastrada.
 */
const trocaPrevista = computed(() => {
  const vidaUtil = epiEscolhido.value?.vida_util_dias;

  if (vidaUtil === null || vidaUtil === undefined || !form.entregue_em) {
    return '';
  }

  return somarDias(form.entregue_em, vidaUtil);
});

watch(
  () => props.show,
  (aberto) => {
    if (!aberto) return;

    form.clearErrors();
    form.technician_id = props.tecnico.id;
    form.personal_protective_equipment_id = null;
    form.quantidade = 1;
    form.entregue_em = hojeISO();
    form.motivo = 'primeira_entrega';
  }
);

function fechar() {
  emit('close');
}

function salvar() {
  form.post(route('epi.entregas.store'), {
    preserveScroll: true,
    onSuccess: () => {
      form.reset();
      emit('close');
    },
  });
}
</script>
