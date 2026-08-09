<!--
  Resumo da visita, mostrado ao cliente antes de assinar (Plano 13, Task
  13.9): quantidade de dispositivos verificados, cômodos tratados, produtos
  aplicados e adequações recomendadas, em linguagem de cliente, nunca em
  jargão técnico ("evento de dispositivo", "avistamento").

  Fidelidade das quatro contagens (importante para quem for revisar):

  - Cômodos tratados e produtos aplicados vêm de `comodoAvistamento.js`
    (Task 13.7), que grava o registro de cada cômodo em `meta` - e não em
    `fila` -, exatamente para SOBREVIVER à sincronização (ver o docblock de
    `obterRegistroDoComodo()` naquele arquivo). Por isso estas duas contagens
    já somam o que foi registrado nesta visita, tenha sincronizado ou não.

  - Dispositivos verificados e adequações recomendadas vêm de
    `listarPorOrdemETipo()` (`sync/fila.js`), a fila local de sincronização.
    Nem `RegistroDeEvento.vue` (Task 13.6) nem `RegistroDeAdequacao.vue`
    (Task 13.8) guardam um registro próprio fora da fila (ao contrário dos
    cômodos), então uma operação `evento_dispositivo`/`adequacao` já
    confirmada pelo servidor (e por isso removida da fila, ver a "regra de
    ouro" no topo de `sync/fila.js`) deixa de ser contada aqui - a mesma
    limitação já aceita e documentada em `ListaDeDispositivos.vue` e
    `ListaDeAdequacoes.vue`. Na prática isso só subestima a contagem quando o
    aparelho teve conexão e sincronizou uma operação NO MEIO da própria
    visita (o caso comum é chegar a esta tela com tudo ainda pendente,
    porque "Concluir visita" é o último passo antes de assinar). Fechar essa
    lacuna de vez exigiria um registro persistente em `meta` também para
    dispositivo e adequação (mesmo padrão de `comodoAvistamento.js`), fora do
    escopo desta task.

  Confirmação de EPI (Plano 29, Task 29.5):

  - A linha só aparece quando os serviços desta OS exigem algum EPI. Sem
    exigência cadastrada, a etapa não existe no aplicativo e nada é mostrado
    aqui - "não informado" nunca é irregularidade.
  - O contador vem de `confirmacaoDeEpi.js`, que guarda as respostas em `meta`
    (e não na fila): diferente das quatro contagens acima, este número NÃO
    encolhe depois de sincronizar.
  - A pendência aparece como número e como frase, e a frase diz explicitamente
    que não impede concluir nem assinar. Nada aqui bloqueia a visita - decisão
    registrada do Plano 29.
  - O que esta tela NUNCA mostra: qual EPI faltou e o motivo escrito pelo
    técnico. O resumo é lido pelo CLIENTE antes de assinar, e o motivo de uma
    falta é registro para o gestor e para a fiscalização (vai no aviso
    `EXECUCAO_SEM_EPI_EXIGIDO` e na ficha do escritório), não documento de
    cliente.
-->
<template>
  <div class="rounded-lg border border-gray-200 bg-white p-4">
    <h2 class="text-sm font-semibold text-gray-900">O que foi feito nesta visita</h2>
    <p class="mt-1 text-xs text-gray-500">Confira antes de assinar.</p>

    <ul class="mt-3 divide-y divide-gray-100 text-sm text-gray-700">
      <li class="flex items-center justify-between py-2">
        <span>Dispositivos de controle verificados</span>
        <span class="font-semibold text-gray-900">{{ contagens.dispositivos }}</span>
      </li>
      <li class="flex items-center justify-between py-2">
        <span>Cômodos tratados</span>
        <span class="font-semibold text-gray-900">{{ contagens.comodos }}</span>
      </li>
      <li class="flex items-center justify-between py-2">
        <span>Produtos aplicados</span>
        <span class="font-semibold text-gray-900">{{ contagens.produtos }}</span>
      </li>
      <li class="flex items-center justify-between py-2">
        <span>Adequações recomendadas para o local</span>
        <span class="font-semibold text-gray-900">{{ contagens.adequacoes }}</span>
      </li>

      <!-- Só quando os serviços desta OS exigem EPI. Ver o comentário no topo
           do arquivo para o que esta linha mostra e o que ela nunca mostra. -->
      <li v-if="epi.exigidos > 0" class="flex items-center justify-between py-2">
        <span>Equipamentos de proteção confirmados</span>
        <span class="font-semibold text-gray-900">{{ epi.confirmados }} de {{ epi.exigidos }}</span>
      </li>
    </ul>

    <p v-if="epi.semResposta > 0" class="mt-3 text-xs font-medium text-yellow-700">
      {{
        epi.semResposta === 1
          ? 'Falta confirmar 1 equipamento de proteção.'
          : `Faltam confirmar ${epi.semResposta} equipamentos de proteção.`
      }}
      Isso não impede concluir nem assinar a visita.
    </p>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, reactive } from 'vue';
import { listarComodosDaOrdem } from '../db/repositorio';
import { aoMudar, listarPorOrdemETipo } from '../sync/fila';
import { obterRegistroDoComodo } from './comodoAvistamento';
import { resumoDeEpiDaOrdem } from './confirmacaoDeEpi';

const props = defineProps({
  workOrderId: {
    type: [Number, String],
    required: true,
  },
});

const contagens = reactive({
  dispositivos: 0,
  comodos: 0,
  produtos: 0,
  adequacoes: 0,
});

const epi = reactive({
  exigidos: 0,
  confirmados: 0,
  semResposta: 0,
});

let pararDeEscutarFila = null;

async function recalcular() {
  const workOrderId = Number(props.workOrderId);

  const [eventosDeDispositivo, adequacoes, comodos] = await Promise.all([
    listarPorOrdemETipo(workOrderId, 'evento_dispositivo'),
    listarPorOrdemETipo(workOrderId, 'adequacao'),
    listarComodosDaOrdem(workOrderId),
  ]);

  // Um mesmo dispositivo pode ter mais de um evento registrado (o técnico
  // reabriu e editou, ver `RegistroDeEvento.vue`); conta o dispositivo, não
  // a operação.
  contagens.dispositivos = new Set(
    eventosDeDispositivo.map((operacao) => operacao.payload?.codigo_publico).filter(Boolean),
  ).size;

  contagens.adequacoes = adequacoes.length;

  const registrosDosComodos = await Promise.all(
    comodos.map((comodo) => obterRegistroDoComodo(workOrderId, comodo.id)),
  );

  contagens.comodos = registrosDosComodos.filter(Boolean).length;
  contagens.produtos = registrosDosComodos.reduce(
    (total, registro) => total + (registro?.produtos?.length || 0),
    0,
  );

  const situacaoDeEpi = await resumoDeEpiDaOrdem(workOrderId);

  epi.exigidos = situacaoDeEpi.exigidos;
  epi.confirmados = situacaoDeEpi.confirmados;
  epi.semResposta = situacaoDeEpi.semResposta;
}

onMounted(async () => {
  await recalcular();
  pararDeEscutarFila = aoMudar(recalcular);
});

onBeforeUnmount(() => {
  pararDeEscutarFila?.();
});
</script>
