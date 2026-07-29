<!--
  Assinatura do cliente ao final da visita, ou recusa (Plano 13, Task 13.9).

  Tela cheia, fora do `AppLayout`, mesmo padrão de `Execucao.vue` (Task
  13.6): sem a navegação inferior, alcançada a partir do botão "Concluir
  visita" daquela tela (`App.vue` troca `ordemEmExecucaoId` por
  `ordemEmAssinaturaId`). Chegar aqui pressupõe a OS já concluída
  (`status === 'completed'`), porque `WorkOrderSignatureService::coletar()`
  só aceita a assinatura depois disso.

  DIVERGÊNCIA ENCONTRADA ENTRE A ESPECIFICAÇÃO (13.9.md) E O CONTRATO REAL DO
  BACKEND, conferida antes de implementar (leitura de
  `app/Services/Sync/AplicadorDeAssinatura.php`,
  `app/Services/WorkOrderSignatureService.php` e `AppSyncService.php`):

  A 13.9 pede uma "operação separada" para a recusa, mas NÃO EXISTE, hoje,
  nenhum aplicador de sincronização para recusa:

  - `AplicadorDeAssinatura::aplicar()` SEMPRE chama
    `WorkOrderSignatureService::coletar()`, que exige `assinante_nome` e
    `imagem` preenchidos (`garantirDadosObrigatorios()`) - não existe nenhum
    ramo para um campo `recusada: true`/`motivo` dentro do tipo `assinatura`.
    Mandar uma "recusa" por esse tipo, sem imagem, sempre lançaria
    `ValidationException`, que `AppSyncService::processar()` transforma num
    `sync_conflict` (motivo `regra_de_negocio`). Pior: se alguém tentasse
    resolver esse "conflito" pelo painel escolhendo o lado do aplicativo,
    `AppSyncService::aplicarLadoDoAplicativo()` chamaria o mesmo aplicador
    com o mesmo payload inválido, sem nenhum `catch` ali - um erro 500 no
    painel, não um conflito tratado. Por isso este arquivo NUNCA usa o tipo
    `assinatura` para a recusa.
  - `WorkOrderSignatureService::registrarRecusa()` existe e é exatamente o
    método certo, mas só é chamado por `WorkOrderSignatureController::recusar()`,
    uma rota WEB (`POST /work-orders/{workOrder}/signature/refusal`,
    autenticada por sessão/CSRF do painel) - o aplicativo do técnico não
    estabelece sessão web nenhuma, só token Sanctum, e não teria como chamar
    essa rota offline de qualquer forma (o Plano 13 exige que toda escrita de
    campo passe pelo lote idempotente de sincronização, não por uma rota
    HTTP direta).

  Ou seja: não existe hoje nenhuma porta de entrada, no aplicativo, para
  `registrarRecusa()`. Enquanto essa lacuna de backend não for fechada (um
  `AplicadorDeRecusaDeAssinatura` novo, ou um ramo dentro do próprio
  `AplicadorDeAssinatura` para um tipo `recusa_assinatura`), a solução aqui é
  deliberadamente conservadora: a recusa é enfileirada com o tipo
  `recusa_assinatura`, que HOJE não tem aplicador registrado em
  `AppSyncService::$aplicadores`. Isso cai no ramo já existente e seguro de
  "tipo não suportado" (`processar()`: `$aplicador === null`), que marca a
  operação como `recusada` de forma definitiva e sem exceção - ela aparece
  em Pendências como "Falhou ao enviar", com a mensagem do servidor, para o
  técnico ver e o time de desenvolvimento tratar como prioridade (criar o
  aplicador que falta). Nunca fica presa num conflito nem derruba o painel.
  De propósito, o payload não leva `updated_at_conhecido`: a checagem de
  "registro alterado por outra pessoa" existe para edição de campo
  concorrente, não para o fechamento da própria visita.

  Este é o motivo pelo qual a visita "fecha mesmo assim" ao recusar: a
  conclusão da OS (`execucao`/`concluir`) já foi enfileirada por
  `Execucao.vue` antes de chegar aqui, e o registro da recusa em si segue
  para a fila (visível, nunca perdido), mesmo sem um aplicador do lado de lá
  ainda.
-->
<template>
  <div class="flex min-h-screen flex-col bg-gray-50">
    <header class="flex items-center gap-3 border-b border-gray-200 bg-white px-4 py-3">
      <button
        type="button"
        class="-ml-2 min-h-[44px] min-w-[44px] p-2 text-gray-500 hover:text-gray-700"
        aria-label="Voltar para o dia"
        @click="$emit('voltar')"
      >
        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
      </button>

      <div class="min-w-0">
        <p class="truncate text-sm font-semibold text-gray-900">
          {{ ordem?.cliente?.nome || 'Cliente não identificado' }}
        </p>
        <p class="text-xs text-gray-500">Assinatura da visita</p>
      </div>
    </header>

    <main class="flex-1 overflow-y-auto px-4 py-4">
      <p v-if="carregandoOrdem" class="text-sm text-gray-500">Carregando ordem de serviço...</p>

      <p v-else-if="!ordem" class="text-sm text-gray-600">
        Esta ordem de serviço não foi encontrada na base local deste aparelho.
      </p>

      <!-- Modo leitura: já assinada ou já recusada (no servidor ou só localmente). -->
      <div v-else-if="resolvido" class="space-y-4">
        <div class="rounded-lg border border-gray-200 bg-white p-4">
          <p class="text-sm font-medium text-gray-900">
            {{ tipoDeResultado === 'assinada' ? 'Assinatura registrada.' : 'Recusa de assinatura registrada.' }}
          </p>
          <p class="mt-1 text-sm text-gray-600">
            {{
              tipoDeResultado === 'assinada'
                ? 'O cliente assinou a conclusão desta visita. Nada mais é editável nesta ordem de serviço pelo aplicativo.'
                : 'O cliente não assinou. O motivo informado foi registrado junto com a visita.'
            }}
          </p>
          <p v-if="pendenteDeEnvio" class="mt-3 text-xs font-medium text-yellow-700">
            Ainda pendente de envio para o servidor. Assim que o aparelho tiver conexão, isso sai sozinho.
          </p>
        </div>

        <button type="button" class="btn-primary w-full py-3 text-base" @click="$emit('voltar')">
          Voltar para o dia
        </button>
      </div>

      <!-- Formulário de assinatura -->
      <div v-else class="space-y-4">
        <ResumoDaVisita :work-order-id="workOrderIdNumerico" />

        <div class="space-y-4 rounded-lg border border-gray-200 bg-white p-4">
          <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">Nome de quem assina *</label>
            <input
              v-model="nome"
              type="text"
              autocomplete="off"
              placeholder="Nome completo"
              class="w-full rounded-md border border-gray-300 px-3 py-3 text-base shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
            />
          </div>

          <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">Documento (opcional)</label>
            <input
              :value="documento"
              type="text"
              inputmode="numeric"
              placeholder="CPF ou CNPJ"
              class="w-full rounded-md border border-gray-300 px-3 py-3 text-base shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
              @input="documento = cpfCnpjMask($event.target.value)"
            />
          </div>

          <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">Vínculo com o cliente (opcional)</label>
            <input
              v-model="vinculo"
              type="text"
              placeholder="Ex.: responsável, síndico, proprietário"
              class="w-full rounded-md border border-gray-300 px-3 py-3 text-base shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
            />
          </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white p-4">
          <p class="mb-2 text-sm font-medium text-gray-700">Assinatura do cliente *</p>
          <QuadroDeAssinatura ref="quadroRef" @mudou="quadroVazio = $event" />
        </div>

        <p class="text-xs text-gray-500">
          Ao confirmar, este aparelho vai pedir a localização, só para registrar onde a assinatura foi
          coletada. Recusar essa permissão não impede a conclusão da visita.
        </p>

        <p v-if="erro" class="text-sm text-red-600">{{ erro }}</p>

        <button
          type="button"
          class="btn-primary w-full py-4 text-base"
          :disabled="!podeConfirmar"
          @click="confirmarAssinatura"
        >
          {{ confirmando ? 'Concluindo...' : 'Concluir e assinar' }}
        </button>

        <button
          type="button"
          class="btn-secondary w-full py-3 text-base"
          :disabled="confirmando"
          @click="abrirRecusa"
        >
          Cliente recusou assinar
        </button>
      </div>
    </main>

    <!-- Recusa de assinatura: nunca `confirm()` nativo, sempre modal explícito. -->
    <Modal :show="mostrandoRecusa" @close="fecharRecusa">
      <template #icon>
        <svg class="h-6 w-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"
          />
        </svg>
      </template>
      <template #title>Cliente recusou assinar</template>
      <template #content>
        <div class="space-y-3 text-left">
          <p class="text-sm text-gray-700">
            O serviço foi prestado normalmente. A recusa não impede concluir a visita - registre o motivo
            para não haver dúvida depois.
          </p>

          <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">Motivo *</label>
            <textarea
              v-model="motivoRecusa"
              rows="3"
              placeholder="Ex.: cliente não estava presente no encerramento da visita"
              class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
            ></textarea>
            <p class="mt-1 text-xs text-gray-500">Mínimo de {{ MOTIVO_MINIMO }} caracteres.</p>
          </div>

          <p v-if="erroRecusa" class="text-sm text-red-600">{{ erroRecusa }}</p>
        </div>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="confirmandoRecusa" @click="fecharRecusa">
          Cancelar
        </button>
        <button
          type="button"
          class="btn-danger"
          :disabled="!podeConfirmarRecusa || confirmandoRecusa"
          @click="confirmarRecusa"
        >
          {{ confirmandoRecusa ? 'Registrando...' : 'Registrar recusa e concluir' }}
        </button>
      </template>
    </Modal>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import Modal from '@/Components/Modal.vue';
import { useMasks } from '@/Composables/useMasks';
import { obterOrdem } from '../db/repositorio';
import { aoMudar, enfileirar, listarPelaOrdem } from '../sync/fila';
import ResumoDaVisita from '../Components/ResumoDaVisita.vue';
import QuadroDeAssinatura from '../Components/QuadroDeAssinatura.vue';

const TIPO_ASSINATURA = 'assinatura';

// Ver o docblock no topo deste arquivo: tipo enfileirado hoje sem aplicador
// no servidor, de propósito - lacuna de backend documentada, não um bug
// deste arquivo.
const TIPO_RECUSA = 'recusa_assinatura';

const MOTIVO_MINIMO = 10;
const TEMPO_LIMITE_LOCALIZACAO_MS = 7000;

const props = defineProps({
  ordemId: {
    type: [Number, String],
    required: true,
  },
});

const emit = defineEmits(['voltar']);

const { cpfCnpjMask } = useMasks();

const ordem = ref(null);
const carregandoOrdem = ref(true);
const operacoesDaOrdem = ref([]);
let pararDeEscutarFila = null;

const nome = ref('');
const documento = ref('');
const vinculo = ref('');

const quadroRef = ref(null);
const quadroVazio = ref(true);
const confirmando = ref(false);
const erro = ref(null);

const mostrandoRecusa = ref(false);
const motivoRecusa = ref('');
const confirmandoRecusa = ref(false);
const erroRecusa = ref(null);

const workOrderIdNumerico = computed(() => Number(props.ordemId));

const operacaoLocalDeAssinatura = computed(
  () => operacoesDaOrdem.value.find((operacao) => operacao.tipo === TIPO_ASSINATURA) || null,
);

const operacaoLocalDeRecusa = computed(
  () => operacoesDaOrdem.value.find((operacao) => operacao.tipo === TIPO_RECUSA) || null,
);

const situacaoNoServidor = computed(() => ordem.value?.situacao_assinatura || null);

// "Resolvido" conta tanto a confirmação vinda do servidor (carga do dia,
// `AppDayLoadService::mapearOrdem()`) quanto uma operação ainda só local,
// nunca sincronizada - para o técnico não ver o formulário de novo ao reabrir
// esta OS antes da próxima sincronização.
const resolvido = computed(
  () =>
    situacaoNoServidor.value === 'assinada'
    || situacaoNoServidor.value === 'recusada'
    || operacaoLocalDeAssinatura.value !== null
    || operacaoLocalDeRecusa.value !== null,
);

const tipoDeResultado = computed(() => {
  if (situacaoNoServidor.value === 'assinada' || operacaoLocalDeAssinatura.value) {
    return 'assinada';
  }

  if (situacaoNoServidor.value === 'recusada' || operacaoLocalDeRecusa.value) {
    return 'recusada';
  }

  return null;
});

const pendenteDeEnvio = computed(
  () =>
    situacaoNoServidor.value !== 'assinada'
    && situacaoNoServidor.value !== 'recusada'
    && (operacaoLocalDeAssinatura.value !== null || operacaoLocalDeRecusa.value !== null),
);

const podeConfirmar = computed(() => nome.value.trim() !== '' && !quadroVazio.value && !confirmando.value);

const podeConfirmarRecusa = computed(
  () => motivoRecusa.value.trim().length >= MOTIVO_MINIMO && !confirmandoRecusa.value,
);

async function carregarOrdem() {
  carregandoOrdem.value = true;
  ordem.value = await obterOrdem(workOrderIdNumerico.value);
  carregandoOrdem.value = false;
}

async function atualizarOperacoesDaOrdem() {
  operacoesDaOrdem.value = await listarPelaOrdem(workOrderIdNumerico.value);
}

/**
 * Localização pedida UMA VEZ, com a explicação já visível na tela acima
 * (não dá para customizar o texto do prompt nativo do navegador, então a
 * frase própria vem antes de qualquer chamada). Recusa, indisponibilidade
 * (`geolocation` inexistente) ou timeout curto resolvem para "sem
 * coordenada" - nunca rejeitam a promise, para nunca travar o fluxo de
 * assinatura.
 */
function obterCoordenadaComConsentimento() {
  return new Promise((resolve) => {
    if (typeof navigator === 'undefined' || !navigator.geolocation) {
      resolve({});
      return;
    }

    navigator.geolocation.getCurrentPosition(
      (posicao) => {
        resolve({
          latitude: posicao.coords.latitude,
          longitude: posicao.coords.longitude,
          precisao_metros: posicao.coords.accuracy != null ? Math.round(posicao.coords.accuracy) : null,
        });
      },
      () => resolve({}),
      { enableHighAccuracy: false, timeout: TEMPO_LIMITE_LOCALIZACAO_MS, maximumAge: 60000 },
    );
  });
}

async function confirmarAssinatura() {
  if (!podeConfirmar.value) {
    return;
  }

  confirmando.value = true;
  erro.value = null;

  try {
    const [coordenada, imagem] = await Promise.all([
      obterCoordenadaComConsentimento(),
      quadroRef.value?.exportarPng(),
    ]);

    if (!imagem) {
      erro.value = 'Não foi possível gerar a imagem da assinatura. Tente assinar de novo.';
      return;
    }

    await enfileirar({
      tipo: TIPO_ASSINATURA,
      work_order_id: workOrderIdNumerico.value,
      payload: {
        assinante_nome: nome.value.trim(),
        assinante_documento: documento.value.trim() || null,
        assinante_vinculo: vinculo.value.trim() || null,
        imagem,
        coletada_em: new Date().toISOString(),
        ...coordenada,
      },
      // Sem `updated_at_conhecido` de propósito: ver o docblock no topo do
      // arquivo - esta operação fecha a visita, não edita um campo
      // concorrente da OS, e não deve competir com a checagem de "alguém
      // mudou este registro enquanto você editava".
    });

    await atualizarOperacoesDaOrdem();
  } finally {
    confirmando.value = false;
  }
}

function abrirRecusa() {
  erroRecusa.value = null;
  motivoRecusa.value = '';
  mostrandoRecusa.value = true;
}

function fecharRecusa() {
  if (confirmandoRecusa.value) {
    return;
  }

  mostrandoRecusa.value = false;
}

async function confirmarRecusa() {
  if (!podeConfirmarRecusa.value) {
    return;
  }

  confirmandoRecusa.value = true;
  erroRecusa.value = null;

  try {
    await enfileirar({
      tipo: TIPO_RECUSA,
      work_order_id: workOrderIdNumerico.value,
      payload: {
        motivo: motivoRecusa.value.trim(),
        recusada_em: new Date().toISOString(),
      },
    });

    await atualizarOperacoesDaOrdem();
    mostrandoRecusa.value = false;
  } finally {
    confirmandoRecusa.value = false;
  }
}

onMounted(async () => {
  await carregarOrdem();
  await atualizarOperacoesDaOrdem();
  pararDeEscutarFila = aoMudar(atualizarOperacoesDaOrdem);
});

onUnmounted(() => {
  pararDeEscutarFila?.();
});
</script>
