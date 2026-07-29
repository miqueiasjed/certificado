<!--
  Execução da OS em campo (Plano 13, Task 13.6).

  Tela cheia, fora do `AppLayout` (sem a navegação inferior de Dia/Pendências/
  Conta): igual à `Login.vue`, é um estado de tela que substitui a casca
  normal enquanto o técnico está dentro de uma visita, com rodapé fixo próprio
  ("Concluir visita" / "Ir para assinatura do cliente", conforme o estado).
  Sem Vue Router (decisão do Plano 12): `App.vue` decide mostrar esta página
  trocando o estado `ordemEmExecucaoId`, volta para `Dia.vue` quando esta
  página emite `voltar`, e troca para `Assinatura.vue` (Task 13.9) quando
  emite `ir-para-assinatura`.

  "Iniciar execução" e "Concluir visita" enfileiram operações do tipo
  `execucao` (`AplicadorDeExecucao`, Task 13.2), com `payload.acao` igual a
  `iniciar` ou `concluir` - `work_order_id` e `registrada_em` são preenchidos
  pelo `AppSyncService` a partir dos campos de nível superior da operação, não
  fazem parte deste payload. "Concluir visita" e "ir para a assinatura" são,
  para o técnico, um só gesto: `concluirVisita()` emite `ir-para-assinatura`
  assim que a conclusão é enfileirada, sem esperar nenhuma confirmação do
  servidor (mesmo espírito de tudo neste aplicativo).

  "Execução já iniciada" e "pelo menos um registro" (para liberar "Concluir
  visita") são decididos localmente por dois sinais somados: o `status` da OS
  já confirmado pelo servidor na última carga, OU uma operação ainda não
  confirmada na fila deste aparelho (`sync/fila.js`, Task 12.8). É o segundo
  sinal que faz a tela funcionar o dia inteiro em modo avião, sem esperar
  nenhuma sincronização para refletir o que o técnico já fez.

  `situacao_assinatura` chega na carga do dia desde a correção aplicada em
  `AppDayLoadService::mapearOrdem()` (ver o campo homônimo no retorno daquele
  método) - por isso `osTravada` abaixo já reflete de verdade uma OS assinada
  que o aparelho só viu numa carga posterior à sincronização da assinatura,
  e não fica mais permanentemente falsa como antes dessa correção.
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
        <p class="truncate text-xs text-gray-600">{{ enderecoResumido }}</p>
        <p class="text-xs text-gray-500">{{ horarioDaOrdem }}</p>
      </div>
    </header>

    <main class="flex-1 overflow-y-auto px-4 py-4 pb-28">
      <p v-if="carregandoOrdem" class="text-sm text-gray-500">Carregando ordem de serviço...</p>

      <p v-else-if="!ordem" class="text-sm text-gray-600">
        Esta ordem de serviço não foi encontrada na base local deste aparelho. Sincronize a carga do dia e tente de
        novo.
      </p>

      <template v-else>
        <div v-if="osTravada" class="mb-4 rounded-md border border-gray-200 bg-gray-50 p-3">
          <p class="text-sm text-gray-700">
            Esta ordem de serviço já foi assinada pelo cliente e está em modo leitura: nenhum registro novo é
            aceito.
          </p>
        </div>

        <button
          v-if="!mostrarAbas"
          type="button"
          class="btn-primary w-full py-4 text-base"
          :disabled="enviandoAcaoExecucao"
          @click="iniciarExecucao"
        >
          {{ enviandoAcaoExecucao ? 'Iniciando...' : 'Iniciar execução' }}
        </button>

        <div v-else class="space-y-4">
          <nav class="flex border-b border-gray-200" role="tablist" aria-label="Etapas da execução">
            <button
              v-for="aba in ABAS"
              :key="aba.chave"
              type="button"
              role="tab"
              :aria-selected="abaAtual === aba.chave"
              class="-mb-px min-h-[44px] flex-1 border-b-2 px-2 py-2 text-sm font-medium"
              :class="abaAtual === aba.chave ? 'border-green-600 text-green-700' : 'border-transparent text-gray-500'"
              @click="abaAtual = aba.chave"
            >
              {{ aba.rotulo }}
            </button>
          </nav>

          <ListaDeDispositivos v-if="abaAtual === 'dispositivos'" :ordem="ordem" :somente-leitura="osTravada" />

          <!-- Cômodos, Adequações e Fotos: só a estrutura da aba nesta task.
               O conteúdo de cada uma entra por fora deste arquivo. -->
          <p v-else-if="abaAtual === 'comodos'" class="py-8 text-center text-sm text-gray-600">
            Registro por cômodo em breve.
          </p>
          <p v-else-if="abaAtual === 'adequacoes'" class="py-8 text-center text-sm text-gray-600">
            Adequações em breve.
          </p>
          <p v-else class="py-8 text-center text-sm text-gray-600">Fotos em breve.</p>
        </div>
      </template>
    </main>

    <footer v-if="mostrarAbas" class="fixed inset-x-0 bottom-0 border-t border-gray-200 bg-white p-3">
      <!-- Assinatura ou recusa já registrada (no servidor ou só localmente):
           nada mais para o técnico fazer aqui, ver `Assinatura.vue`. -->
      <div v-if="assinaturaResolvida" class="rounded-md border border-gray-200 bg-gray-50 p-3 text-center">
        <p class="text-sm font-medium text-gray-900">
          {{ assinaturaResultado === 'assinada' ? 'Visita concluída e assinada.' : 'Visita concluída. Cliente recusou assinar.' }}
        </p>
        <p v-if="assinaturaPendenteDeEnvio" class="mt-1 text-xs font-medium text-yellow-700">
          Ainda pendente de envio para o servidor.
        </p>
      </div>

      <!-- Visita concluída em uma sessão anterior, sem assinatura ainda
           coletada: reabre o caminho para `Assinatura.vue` sem repetir
           "Concluir visita" (a conclusão já está feita). -->
      <button
        v-else-if="visitaConcluida"
        type="button"
        class="btn-primary w-full py-4 text-base"
        @click="$emit('ir-para-assinatura')"
      >
        Ir para assinatura do cliente
      </button>

      <button
        v-else
        type="button"
        class="btn-primary w-full py-4 text-base"
        :disabled="!podeConcluir"
        @click="concluirVisita"
      >
        {{ enviandoAcaoExecucao ? 'Enviando...' : 'Concluir visita' }}
      </button>
    </footer>
  </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { obterOrdem } from '../db/repositorio';
import { aoMudar, enfileirar, listarPelaOrdem } from '../sync/fila';
import { formatarHora } from '@/utils/formatDate';
import ListaDeDispositivos from '../Components/ListaDeDispositivos.vue';

const TIPO_EXECUCAO = 'execucao';

// Mesmos tipos usados por `Assinatura.vue` (Task 13.9): esta tela só precisa
// saber SE já existe um dos dois localmente, para decidir o rótulo do
// rodapé, nunca o conteúdo da assinatura/recusa em si.
const TIPO_ASSINATURA = 'assinatura';
const TIPO_RECUSA_ASSINATURA = 'recusa_assinatura';

const ABAS = [
  { chave: 'dispositivos', rotulo: 'Dispositivos' },
  { chave: 'comodos', rotulo: 'Cômodos' },
  { chave: 'adequacoes', rotulo: 'Adequações' },
  { chave: 'fotos', rotulo: 'Fotos' },
];

const props = defineProps({
  ordemId: {
    type: [Number, String],
    required: true,
  },
});

const emit = defineEmits(['voltar', 'ir-para-assinatura']);

const ordem = ref(null);
const carregandoOrdem = ref(true);
const abaAtual = ref('dispositivos');
const operacoesDaOrdem = ref([]);
const enviandoAcaoExecucao = ref(false);
let pararDeEscutarFila = null;

const workOrderIdNumerico = computed(() => Number(props.ordemId));

const enderecoResumido = computed(() => {
  const endereco = ordem.value?.endereco;

  if (!endereco) {
    return 'Endereço não informado';
  }

  const partes = [
    [endereco.logradouro, endereco.numero].filter(Boolean).join(', '),
    endereco.bairro,
    endereco.cidade,
  ].filter(Boolean);

  return partes.join(' - ') || 'Endereço não informado';
});

const horarioDaOrdem = computed(() => {
  const inicio = formatarHora(ordem.value?.inicio);
  const fim = formatarHora(ordem.value?.fim);

  if (inicio && fim) {
    return `${inicio} - ${fim}`;
  }

  return inicio || 'Horário não definido';
});

// Ver o comentário no topo do arquivo: `situacao_assinatura` já chega na carga do dia.
const osTravada = computed(() => ordem.value?.situacao_assinatura === 'assinada');

const execucaoJaIniciadaLocalmente = computed(() =>
  operacoesDaOrdem.value.some((operacao) => operacao.tipo === TIPO_EXECUCAO && operacao.payload?.acao === 'iniciar'),
);

const execucaoIniciada = computed(
  () => ['in_progress', 'completed'].includes(ordem.value?.status) || execucaoJaIniciadaLocalmente.value,
);

// As abas aparecem tanto quando a execução já começou quanto quando a OS está
// travada (modo leitura): não faz sentido oferecer "Iniciar execução" para
// uma OS já assinada, então este é o estado que decide trocar o botão grande
// pelas abas.
const mostrarAbas = computed(() => execucaoIniciada.value || osTravada.value);

const execucaoConcluidaLocalmente = computed(() =>
  operacoesDaOrdem.value.some((operacao) => operacao.tipo === TIPO_EXECUCAO && operacao.payload?.acao === 'concluir'),
);

const visitaConcluida = computed(() => ordem.value?.status === 'completed' || execucaoConcluidaLocalmente.value);

const existePeloMenosUmRegistro = computed(() => operacoesDaOrdem.value.length > 0);

const podeConcluir = computed(
  () =>
    existePeloMenosUmRegistro.value
    && !visitaConcluida.value
    && !enviandoAcaoExecucao.value
    && !osTravada.value,
);

// Assinatura/recusa já registrada, no servidor (`situacao_assinatura` da
// carga) OU só localmente ainda (operação `assinatura`/`recusa_assinatura`
// na fila deste aparelho, não confirmada) - ver `Assinatura.vue` para o
// contrato completo dos dois tipos.
const assinaturaResultado = computed(() => {
  const temOperacaoLocal = (tipo) =>
    operacoesDaOrdem.value.some((operacao) => operacao.tipo === tipo);

  if (ordem.value?.situacao_assinatura === 'assinada' || temOperacaoLocal(TIPO_ASSINATURA)) {
    return 'assinada';
  }

  if (ordem.value?.situacao_assinatura === 'recusada' || temOperacaoLocal(TIPO_RECUSA_ASSINATURA)) {
    return 'recusada';
  }

  return null;
});

const assinaturaResolvida = computed(() => assinaturaResultado.value !== null);

const assinaturaPendenteDeEnvio = computed(
  () =>
    ordem.value?.situacao_assinatura !== 'assinada'
    && ordem.value?.situacao_assinatura !== 'recusada'
    && assinaturaResolvida.value,
);

async function carregarOrdem() {
  carregandoOrdem.value = true;
  ordem.value = await obterOrdem(workOrderIdNumerico.value);
  carregandoOrdem.value = false;
}

async function atualizarOperacoesDaOrdem() {
  operacoesDaOrdem.value = await listarPelaOrdem(workOrderIdNumerico.value);
}

async function iniciarExecucao() {
  if (enviandoAcaoExecucao.value || mostrarAbas.value) {
    return;
  }

  enviandoAcaoExecucao.value = true;

  try {
    await enfileirar({
      tipo: TIPO_EXECUCAO,
      work_order_id: workOrderIdNumerico.value,
      payload: { acao: 'iniciar' },
      updated_at_conhecido: ordem.value?.updated_at ?? null,
    });

    await atualizarOperacoesDaOrdem();
  } finally {
    enviandoAcaoExecucao.value = false;
  }
}

async function concluirVisita() {
  if (!podeConcluir.value) {
    return;
  }

  enviandoAcaoExecucao.value = true;

  try {
    await enfileirar({
      tipo: TIPO_EXECUCAO,
      work_order_id: workOrderIdNumerico.value,
      payload: { acao: 'concluir' },
      updated_at_conhecido: ordem.value?.updated_at ?? null,
    });

    await atualizarOperacoesDaOrdem();

    // Concluir a visita e ir assinar são, para o técnico, um só gesto: a
    // Task 13.9 pede a navegação para `Assinatura.vue` a partir deste mesmo
    // botão. `App.vue` troca `ordemEmExecucaoId` por `ordemEmAssinaturaId`
    // ao ouvir este evento.
    emit('ir-para-assinatura');
  } finally {
    enviandoAcaoExecucao.value = false;
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
