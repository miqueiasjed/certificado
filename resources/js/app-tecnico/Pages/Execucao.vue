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

  Local de início/fim e faixa de rastreamento contínuo (Plano 22, Task 22.7):

  - `iniciarExecucao()` e `concluirVisita()` pedem a localização com
    `useLocalizacao().pedirLocalizacao()` ANTES de montar o payload, e só
    incluem `latitude`/`longitude` quando a chamada devolve coordenada -
    exatamente o contrato opcional que `AplicadorDeExecucao` já espera (ver o
    docblock daquela classe). Recusa/erro/timeout nunca impedem o
    `enfileirar()` que vem a seguir, com ou sem as duas chaves.
  - A explicação ("uma frase antes do pedido", regra da 22.7) aparece numa
    faixa simples abaixo do cabeçalho enquanto o pedido está em andamento
    (`pedindoLocalizacao`) - o composable garante sozinho que isso nunca
    repete para a mesma ação (iniciar/concluir) desta mesma OS.
  Confirmação de EPI (Plano 29, Task 29.5):

  - A aba "EPI" só existe quando os serviços daquela OS exigem algum EPI
    (`ordem.epis_exigidos`, que a carga do dia traz desde a Task 29.3). Serviço
    sem exigência cadastrada não mostra a etapa - etapa vazia em tela de campo é
    atrito puro, e "não informado" nunca é irregularidade.
  - A etapa NÃO trava nada: `podeConcluir` continua exatamente como estava, sem
    nenhuma condição de EPI. Decisão registrada do Plano 29 - pendência de EPI é
    problema de escritório, e travar o técnico em campo tira a operação do ar.
    O sinal amarelo na aba informa; a pendência em si aparece no resumo da
    visita (`ResumoDaVisita.vue`), que é onde o Plano 13 já concentra o que
    ficou faltando.
  - Toda a gravação é offline, pela mesma fila (`ConfirmacaoDeEpi.vue` e
    `confirmacaoDeEpi.js`). Nenhuma requisição sai daqui.

  - Quando o rastreamento contínuo está ligado para o técnico
    (`rastreamento_continuo_ligado` da carga do dia, buraco fechado em
    `AppDayLoadService::carregar()` nesta mesma task), uma segunda faixa,
    permanente enquanto a tela estiver aberta, avisa isso - replicada aqui do
    `AppLayout.vue`: esta tela é uma das duas do aplicativo que roda FORA da
    casca comum (a outra é `Assinatura.vue`), então a faixa do layout
    compartilhado não alcança este componente sozinha.
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

    <!-- Rastreamento contínuo ligado (Plano 22, Task 22.7): faixa permanente
         enquanto esta tela estiver aberta, mesma frase e mesmo padrão de
         `AppLayout.vue` - ver o comentário no topo do arquivo para o porquê
         de repetir aqui. -->
    <div v-if="rastreamentoContinuoLigado" class="border-b border-blue-200 bg-blue-50 px-4 py-2">
      <p class="text-xs text-blue-800">Localização sendo registrada durante o expediente.</p>
    </div>

    <!-- Explicação do pedido de localização, mostrada ANTES do próprio pedido
         (o navegador não deixa customizar o prompt nativo, então esta frase
         é a explicação própria do app - regra da Task 22.7). Desaparece
         assim que `pedirLocalizacao()` resolve, com ou sem coordenada. -->
    <div v-if="pedindoLocalizacao" class="border-b border-gray-200 bg-gray-100 px-4 py-2">
      <p class="text-xs text-gray-700">{{ explicacaoLocalizacao }}</p>
    </div>

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
              v-for="aba in abas"
              :key="aba.chave"
              type="button"
              role="tab"
              :aria-selected="abaAtual === aba.chave"
              class="-mb-px min-h-[44px] flex-1 border-b-2 px-2 py-2 text-sm font-medium"
              :class="abaAtual === aba.chave ? 'border-green-600 text-green-700' : 'border-transparent text-gray-500'"
              @click="abaAtual = aba.chave"
            >
              {{ aba.rotulo }}
              <template v-if="aba.chave === 'epi' && episSemResposta > 0">
                <span class="ml-1 inline-block h-2 w-2 rounded-full bg-yellow-500 align-middle"></span>
                <span class="sr-only">com confirmação pendente</span>
              </template>
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

          <ConfirmacaoDeEpi
            v-else-if="abaAtual === 'epi'"
            :ordem="ordem"
            :somente-leitura="osTravada"
            @salvo="atualizarPendenciaDeEpi"
          />

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
import { obterMeta, obterOrdem } from '../db/repositorio';
import { aoMudar, enfileirar, listarPelaOrdem } from '../sync/fila';
import { formatarHora } from '@/utils/formatDate';
import { useLocalizacao } from '../Composables/useLocalizacao';
import ListaDeDispositivos from '../Components/ListaDeDispositivos.vue';
import ConfirmacaoDeEpi from '../Components/ConfirmacaoDeEpi.vue';
import { resumoDeEpiDaOrdem } from '../Components/confirmacaoDeEpi';

const TIPO_EXECUCAO = 'execucao';

// Mesmos tipos usados por `Assinatura.vue` (Task 13.9): esta tela só precisa
// saber SE já existe um dos dois localmente, para decidir o rótulo do
// rodapé, nunca o conteúdo da assinatura/recusa em si.
const TIPO_ASSINATURA = 'assinatura';
const TIPO_RECUSA_ASSINATURA = 'recusa_assinatura';

// Explicação exigida pela Task 22.7 ("uma frase antes do pedido"), uma para
// cada ação - iniciar e concluir são momentos diferentes, e por isso pedem a
// localização (e podem ser recusados) de forma independente.
const EXPLICACAO_LOCALIZACAO_INICIO =
  'Este aparelho vai pedir sua localização para registrar onde a visita começou. Recusar não impede iniciar.';
const EXPLICACAO_LOCALIZACAO_FIM =
  'Este aparelho vai pedir sua localização para registrar onde a visita terminou. Recusar não impede concluir.';

const ABAS = [
  { chave: 'dispositivos', rotulo: 'Dispositivos' },
  { chave: 'comodos', rotulo: 'Cômodos' },
  { chave: 'adequacoes', rotulo: 'Adequações' },
  { chave: 'fotos', rotulo: 'Fotos' },
];

const ABA_DE_EPI = { chave: 'epi', rotulo: 'EPI' };

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
const rastreamentoContinuoLigado = ref(false);
const episSemResposta = ref(0);
let pararDeEscutarFila = null;

// Ver o comentário no topo do arquivo: pedido de localização com explicação
// prévia e sem repetição na mesma ação/OS, delegado inteiro a este composable.
const { pedirLocalizacao, pedindoLocalizacao, explicacaoAtual: explicacaoLocalizacao } = useLocalizacao();

const workOrderIdNumerico = computed(() => Number(props.ordemId));

// A aba de EPI só entra quando os serviços desta OS exigem algum. Ver o
// comentário no topo do arquivo: serviço sem exigência cadastrada não mostra a
// etapa.
const exigeEpi = computed(() => (ordem.value?.epis_exigidos?.length || 0) > 0);

const abas = computed(() => (exigeEpi.value ? [...ABAS, ABA_DE_EPI] : ABAS));

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

/**
 * Quantos EPIs exigidos ainda estão sem resposta nesta visita, só para o sinal
 * amarelo na aba. Nada aqui entra em `podeConcluir`.
 */
async function atualizarPendenciaDeEpi() {
  episSemResposta.value = (await resumoDeEpiDaOrdem(workOrderIdNumerico.value)).semResposta;
}

async function iniciarExecucao() {
  if (enviandoAcaoExecucao.value || mostrarAbas.value) {
    return;
  }

  enviandoAcaoExecucao.value = true;

  try {
    // Localização pedida ANTES de montar o payload (Task 22.7): `null` em
    // recusa/erro/timeout/pedido repetido, nunca uma exceção - o
    // `enfileirar()` a seguir roda sempre, com ou sem coordenada. Funciona
    // offline: `navigator.geolocation` não depende de rede (usa o GPS do
    // aparelho quando há sinal de satélite).
    const coordenada = await pedirLocalizacao(EXPLICACAO_LOCALIZACAO_INICIO, {
      workOrderId: workOrderIdNumerico.value,
      acao: 'iniciar',
    });

    await enfileirar({
      tipo: TIPO_EXECUCAO,
      work_order_id: workOrderIdNumerico.value,
      payload: { acao: 'iniciar', ...(coordenada ?? {}) },
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
    // Mesma lógica de `iniciarExecucao()`: pede antes de montar o payload,
    // `null` nunca impede a conclusão. Ação diferente ('concluir'), então o
    // composable pede de novo mesmo que 'iniciar' já tenha sido recusado
    // para esta OS - são pedidos independentes por design (Task 22.7).
    const coordenada = await pedirLocalizacao(EXPLICACAO_LOCALIZACAO_FIM, {
      workOrderId: workOrderIdNumerico.value,
      acao: 'concluir',
    });

    await enfileirar({
      tipo: TIPO_EXECUCAO,
      work_order_id: workOrderIdNumerico.value,
      payload: { acao: 'concluir', ...(coordenada ?? {}) },
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
  await atualizarPendenciaDeEpi();
  pararDeEscutarFila = aoMudar(atualizarOperacoesDaOrdem);

  // Ver o comentário no topo do arquivo: mesma leitura de `AppLayout.vue`,
  // repetida aqui porque esta tela roda fora daquela casca. Só leitura, uma
  // vez na montagem - mesmo padrão já usado ali para `meta.empresa`.
  rastreamentoContinuoLigado.value = (await obterMeta('rastreamento_continuo_ligado')) === true;
});

onUnmounted(() => {
  pararDeEscutarFila?.();
});
</script>
