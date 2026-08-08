<!--
  Confirmação de EPI na execução da OS (Plano 29, Task 29.5).

  O técnico responde, por EPI exigido pelos serviços daquela ordem, se vestiu ou
  não. "Não usei" abre o motivo, que é obrigatório.

  Três decisões que este arquivo carrega, e nenhuma delas é acidente:

  1. **Nada aqui bloqueia a execução da OS.** Não confirmar não impede avançar,
     assinar nem concluir — é a decisão registrada do Plano 29, a mesma já feita
     no checklist do Plano 24: o sistema informa, não impede. Pendência de EPI é
     problema de escritório, e travar o técnico em campo por causa dela tira a
     operação do ar. Por isso não existe botão desabilitado por falta de
     resposta em nenhum lugar fora desta aba.

  2. **A justificativa é cobrada aqui, antes de a operação entrar na fila.** O
     servidor recusa uma falta sem motivo (`ConfirmacaoDeEpiService`), e recusa
     como conflito de regra de negócio — que o técnico só veria horas depois, na
     tela de Pendências, longe do cliente e sem lembrar o motivo. Cobrar antes é
     o que faz a etapa funcionar sem sinal de verdade.

  3. **Nenhuma chamada de rede.** A lista vem de `ordem.epis_exigidos`, que a
     carga do dia já gravou no IndexedDB (`AppDayLoadService`, Task 29.3), e a
     resposta sai por `sync/fila.js`, pela mesma fila offline de todo o resto do
     aplicativo. O aparelho em modo avião faz exatamente o mesmo caminho.

  A carga do dia manda só `id`, `nome`, `tipo` e `obrigatorio` de cada EPI —
  decisão registrada do plano: CA, validade e fabricante não vão para o
  aparelho, porque não mudam nada do que o técnico responde e a carga é
  justamente onde o aplicativo fica pesado para quem está com sinal ruim. Esta
  tela, portanto, não tem como mostrar nem cobrar validade de CA.

  O estado das respostas mora em `meta` (ver `confirmacaoDeEpi.js`), não na
  fila: sobrevive à sincronização, e é o que impede o técnico de voltar aqui
  depois de o aparelho pegar sinal e achar que não confirmou nada.
-->
<template>
  <div class="space-y-4">
    <div class="rounded-md border border-gray-200 bg-white p-3">
      <p class="text-sm text-gray-700">
        Confirme o equipamento de proteção usado nesta visita.
      </p>
      <p class="mt-1 text-xs text-gray-500">
        Responder não é obrigatório para concluir a visita. O que ficar sem resposta aparece como pendência
        para o escritório.
      </p>
    </div>

    <ul class="space-y-3">
      <li
        v-for="epi in epis"
        :key="epi.id"
        class="rounded-lg border bg-white p-4 shadow-sm"
        :class="respostaDe(epi.id).confirmado === false ? 'border-yellow-300' : 'border-gray-200'"
      >
        <div class="flex items-start justify-between gap-3">
          <div class="min-w-0">
            <p class="text-base font-semibold text-gray-900">{{ epi.nome }}</p>
            <p class="text-xs text-gray-500">{{ rotuloDeTipoDeEpi(epi.tipo) }}</p>
          </div>

          <span
            class="shrink-0 rounded-full px-2 py-1 text-xs font-medium"
            :class="epi.obrigatorio ? 'bg-gray-800 text-white' : 'bg-gray-100 text-gray-800'"
          >
            {{ epi.obrigatorio ? 'Obrigatório' : 'Recomendado' }}
          </span>
        </div>

        <div class="mt-3 grid grid-cols-2 gap-2">
          <button
            type="button"
            class="min-h-[56px] rounded-lg border-2 px-3 py-3 text-base font-semibold"
            :class="
              respostaDe(epi.id).confirmado === true
                ? 'border-green-600 bg-green-600 text-white'
                : 'border-gray-300 bg-white text-gray-700'
            "
            :aria-pressed="respostaDe(epi.id).confirmado === true"
            :disabled="somenteLeitura"
            @click="responder(epi.id, true)"
          >
            Usei
          </button>

          <button
            type="button"
            class="min-h-[56px] rounded-lg border-2 px-3 py-3 text-base font-semibold"
            :class="
              respostaDe(epi.id).confirmado === false
                ? 'border-red-600 bg-red-600 text-white'
                : 'border-gray-300 bg-white text-gray-700'
            "
            :aria-pressed="respostaDe(epi.id).confirmado === false"
            :disabled="somenteLeitura"
            @click="responder(epi.id, false)"
          >
            Não usei
          </button>
        </div>

        <div v-if="respostaDe(epi.id).confirmado === false" class="mt-3">
          <label :for="`motivo-epi-${epi.id}`" class="mb-1 block text-sm font-medium text-gray-700">
            Motivo de não ter usado *
          </label>
          <textarea
            :id="`motivo-epi-${epi.id}`"
            :ref="(elemento) => guardarCampoDeMotivo(epi.id, elemento)"
            :value="respostaDe(epi.id).justificativa || ''"
            rows="3"
            :disabled="somenteLeitura"
            placeholder="Ex.: respirador com a troca vencida, sem reposição na base."
            class="w-full rounded-md border px-3 py-3 text-base shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
            :class="faltaMotivoEm.includes(epi.id) ? 'border-red-500' : 'border-gray-300'"
            @input="escreverMotivo(epi.id, $event.target.value)"
          ></textarea>
          <p v-if="faltaMotivoEm.includes(epi.id)" class="mt-1 text-sm text-red-600">
            Escreva o motivo. Sem ele, esta falta não é enviada.
          </p>
          <p v-else class="mt-1 text-xs text-gray-500">
            A falta fica registrada, e é o motivo que a explica ao gestor e à fiscalização.
          </p>
        </div>
      </li>
    </ul>

    <p v-if="erro" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm font-medium text-red-800">
      {{ erro }}
    </p>

    <div v-if="!somenteLeitura" class="space-y-2">
      <button
        type="button"
        class="btn-primary w-full py-4 text-base"
        :disabled="salvando || !temAlgoParaSalvar"
        @click="salvar"
      >
        {{ salvando ? 'Salvando...' : rotuloDoBotao }}
      </button>

      <p v-if="registroSalvoEm" class="text-center text-xs text-gray-500">
        Confirmação registrada neste aparelho às {{ formatarHora(registroSalvoEm) }}. Vai junto na próxima
        sincronização.
      </p>
    </div>

    <p v-else class="text-sm text-gray-600">
      Esta ordem de serviço está em modo leitura: a confirmação de EPI não pode mais ser alterada aqui.
    </p>
  </div>
</template>

<script setup>
import { computed, onMounted, reactive, ref } from 'vue';
import { formatarHora } from '@/utils/formatDate';
import { rotuloDeTipoDeEpi } from '@/utils/epi';
import { faltamJustificativas, obterRegistroDeEpi, respostaCompleta, salvarConfirmacoesDeEpi } from './confirmacaoDeEpi';

const props = defineProps({
  ordem: {
    type: Object,
    required: true,
  },
  somenteLeitura: {
    type: Boolean,
    default: false,
  },
});

const emit = defineEmits(['salvo']);

const RESPOSTA_VAZIA = Object.freeze({ confirmado: null, justificativa: '', confirmado_em: null });

// Chave de EPI é sempre string aqui: `respostas` é um objeto comum, e o mesmo
// id lido de `meta` (JSON) e de `epi.id` (número) precisa cair na mesma chave.
const respostas = reactive({});
const faltaMotivoEm = ref([]);
const erro = ref('');
const salvando = ref(false);
const registroSalvoEm = ref(null);
const assinaturaSalva = ref('');
const camposDeMotivo = new Map();

const epis = computed(() => (Array.isArray(props.ordem?.epis_exigidos) ? props.ordem.epis_exigidos : []));

// Uma entrada por EPI exigido, criada já na montagem do componente e nunca
// durante a renderização: criar chave reativa dentro do próprio render é o que
// faz o Vue disparar o efeito que está executando, e vira aviso de atualização
// recursiva.
for (const epi of epis.value) {
  respostas[String(epi.id)] = { confirmado: null, justificativa: '', confirmado_em: null };
}

const respostasCompletas = computed(() =>
  Object.entries(respostas).filter(([, resposta]) => respostaCompleta(resposta)),
);

// "Algo para salvar" é ter ao menos uma resposta que ainda não foi gravada do
// jeito que está na tela agora. Sem isso, o botão continuaria convidando a
// reenviar exatamente o que já está na fila.
const temAlgoParaSalvar = computed(
  () => respostasCompletas.value.length > 0 && assinaturaAtual() !== assinaturaSalva.value,
);

const rotuloDoBotao = computed(() => (registroSalvoEm.value ? 'Atualizar confirmação' : 'Salvar confirmação'));

/**
 * Leitura pura, usada também pelo template: devolve a resposta vazia congelada
 * para um EPI que não está mais na lista, em vez de criar a chave na hora.
 */
function respostaDe(epiId) {
  return respostas[String(epiId)] || RESPOSTA_VAZIA;
}

/**
 * Assinatura do que está na tela, para comparar com o que já foi gravado. Só as
 * respostas completas entram: uma falta ainda sem motivo não é estado salvável.
 */
function assinaturaAtual() {
  return JSON.stringify(
    respostasCompletas.value
      .map(([epiId, resposta]) => [epiId, resposta.confirmado, (resposta.justificativa || '').trim()])
      .sort((a, b) => Number(a[0]) - Number(b[0])),
  );
}

function guardarCampoDeMotivo(epiId, elemento) {
  if (elemento) {
    camposDeMotivo.set(String(epiId), elemento);
  } else {
    camposDeMotivo.delete(String(epiId));
  }
}

function responder(epiId, confirmado) {
  const resposta = respostas[String(epiId)];

  if (props.somenteLeitura || !resposta) {
    return;
  }

  resposta.confirmado = confirmado;

  if (confirmado) {
    // A justificativa some junto com a falta: manter o motivo antigo colado
    // numa confirmação corrigida faria o registro dizer duas coisas
    // contraditórias, e é o registro que vai à fiscalização (mesma regra do
    // `ConfirmacaoDeEpiService::atributos()`).
    resposta.justificativa = '';
    faltaMotivoEm.value = faltaMotivoEm.value.filter((id) => id !== Number(epiId));
  }

  // O instante da resposta é o de agora, não o do "Salvar": é a hora em que o
  // técnico de fato respondeu sobre este EPI. Instante de aparelho em UTC, o
  // mesmo que `fila.js` já faz com `registrada_em`.
  resposta.confirmado_em = new Date().toISOString();

  erro.value = '';
}

function escreverMotivo(epiId, texto) {
  const resposta = respostas[String(epiId)];

  if (!resposta) {
    return;
  }

  resposta.justificativa = texto;
  resposta.confirmado_em = new Date().toISOString();

  if (texto.trim() !== '') {
    faltaMotivoEm.value = faltaMotivoEm.value.filter((id) => id !== Number(epiId));
    erro.value = '';
  }
}

async function salvar() {
  if (salvando.value || props.somenteLeitura) {
    return;
  }

  // A cobrança da justificativa acontece ANTES de enfileirar. Ver o comentário
  // no topo do arquivo: depois da fila, isso vira conflito na tela de
  // Pendências, longe do momento em que o técnico sabe o motivo.
  const pendentes = faltamJustificativas(respostas);

  if (pendentes.length) {
    faltaMotivoEm.value = pendentes;
    erro.value =
      pendentes.length === 1
        ? 'Escreva o motivo do EPI marcado como não usado antes de salvar.'
        : `Escreva o motivo dos ${pendentes.length} EPIs marcados como não usados antes de salvar.`;

    camposDeMotivo.get(String(pendentes[0]))?.focus();

    return;
  }

  salvando.value = true;

  try {
    const registro = await salvarConfirmacoesDeEpi({
      workOrderId: props.ordem.id,
      respostas,
    });

    if (registro) {
      aplicarRegistro(registro);
      emit('salvo');
    }
  } catch (falha) {
    erro.value = falha?.message || 'Não foi possível salvar a confirmação de EPI neste aparelho.';
  } finally {
    salvando.value = false;
  }
}

function aplicarRegistro(registro) {
  for (const [epiId, resposta] of Object.entries(registro?.respostas || {})) {
    // Resposta guardada de um EPI que o serviço não exige mais é ignorada: ela
    // não tem linha na tela, e reenviá-la só recriaria no servidor um item que
    // o escritório removeu da exigência.
    const atual = respostas[String(epiId)];

    if (!atual) {
      continue;
    }

    atual.confirmado = resposta.confirmado;
    atual.justificativa = resposta.justificativa || '';
    atual.confirmado_em = resposta.confirmado_em || null;
  }

  registroSalvoEm.value = registro?.atualizado_em || null;
  assinaturaSalva.value = assinaturaAtual();
  faltaMotivoEm.value = [];
  erro.value = '';
}

onMounted(async () => {
  const registro = await obterRegistroDeEpi(props.ordem.id);

  if (registro) {
    aplicarRegistro(registro);
  }
});
</script>
