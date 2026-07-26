<template>
  <div class="flex flex-col gap-4">
    <!-- Avisos do arrastar-e-soltar (Task 10.6): aviso não bloqueia o movimento, erro
         some porque a visita já voltou para a posição original. -->
    <div
      v-if="mensagemAvisoArrasto"
      class="flex items-start justify-between gap-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800"
    >
      <p>{{ mensagemAvisoArrasto }}</p>
      <button type="button" class="text-amber-600 hover:text-amber-800" @click="mensagemAvisoArrasto = null">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <div
      v-if="mensagemErroArrasto"
      class="flex items-start justify-between gap-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800"
    >
      <p>{{ mensagemErroArrasto }}</p>
      <button type="button" class="text-red-600 hover:text-red-800" @click="mensagemErroArrasto = null">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Navegação de período: o calendário controla a data de referência e avisa o
         pai (que busca os dados) por `mudarPeriodo`. O seletor de visão (dia/semana/
         mês) fica na página que usa este componente, não aqui. -->
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="text-lg font-semibold text-gray-900">{{ rotuloPeriodo }}</h2>

      <div class="flex items-center gap-2">
        <button type="button" class="btn-secondary-sm" @click="irParaAnterior">
          <svg class="w-3.5 h-3.5 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
          </svg>
          Anterior
        </button>
        <button type="button" class="btn-secondary-sm" @click="irParaHoje">Hoje</button>
        <button type="button" class="btn-secondary-sm" @click="irParaProximo">
          Próximo
          <svg class="w-3.5 h-3.5 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
          </svg>
        </button>
      </div>
    </div>

    <!-- ============================= Visão de mês ============================= -->
    <div v-if="visao === 'mes'">
      <!-- Desktop/tablet: grade 7 colunas -->
      <div class="hidden md:block border-t border-l border-gray-200 rounded-lg overflow-hidden">
        <div class="grid grid-cols-7 bg-gray-50">
          <div
            v-for="nome in NOMES_DIAS_SEMANA_ABREVIADO"
            :key="nome"
            class="px-2 py-2 text-xs font-semibold text-gray-500 text-center uppercase tracking-wide border-r border-b border-gray-200"
          >
            {{ nome }}
          </div>
        </div>

        <div class="grid grid-cols-7">
          <div
            v-for="dia in diasDoMesAchatado"
            :key="dia.data"
            class="min-h-[7rem] p-1.5 border-r border-b border-gray-200 flex flex-col gap-1"
            :class="[dia.mesAtual ? 'bg-white' : 'bg-gray-50', classeDoAlvo(alvoDia(dia.data))]"
            @dragover="aoArrastarSobre($event, alvoDia(dia.data))"
            @dragleave="aoSairDoAlvo(alvoDia(dia.data))"
            @drop="aoSoltar($event, alvoDia(dia.data))"
          >
            <span
              class="text-xs font-semibold w-6 h-6 flex items-center justify-center rounded-full"
              :class="[
                ehHoje(dia.data) ? 'bg-green-600 text-white' : '',
                !ehHoje(dia.data) && dia.mesAtual ? 'text-gray-700' : '',
                !ehHoje(dia.data) && !dia.mesAtual ? 'text-gray-400' : '',
              ]"
            >
              {{ dia.dia }}
            </span>

            <div class="flex flex-col gap-1 overflow-hidden">
              <CartaoDeVisita
                v-for="visita in visitasVisiveis(dia.data)"
                :key="visita.id"
                :visita="visita"
                variante="compacta"
                :draggable="podeArrastar(visita)"
                @dragstart="aoIniciarArrasto($event, visita)"
                @dragend="aoTerminarArrasto"
                @selecionar="emit('selecionarVisita', $event)"
              />

              <button
                v-if="temExtras(dia.data)"
                type="button"
                class="text-[11px] font-semibold text-green-700 hover:text-green-800 text-left px-0.5"
                @click="alternarExpansao(dia.data)"
              >
                {{ diasExpandidos.has(dia.data) ? 'Mostrar menos' : `+${totalNoDia(dia.data) - LIMITE_VISITAS_POR_DIA} mais` }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Celular: lista por dia, sem grade -->
      <div class="md:hidden space-y-2">
        <div
          v-for="dia in diasDoMesAtual"
          :key="dia.data"
          class="rounded-lg border p-3"
          :class="ehHoje(dia.data) ? 'border-green-400 bg-green-50' : 'border-gray-200 bg-white'"
        >
          <div class="flex items-center justify-between mb-2">
            <p class="text-sm font-semibold text-gray-900">
              {{ NOMES_DIAS_SEMANA_ABREVIADO[dia.diaSemana] }}, {{ dia.dia }} de {{ nomeDoMes(referencia.mes) }}
            </p>
            <span v-if="ehHoje(dia.data)" class="text-[11px] font-semibold text-green-700 uppercase">Hoje</span>
          </div>

          <div v-if="totalNoDia(dia.data) > 0" class="space-y-1.5">
            <CartaoDeVisita
              v-for="visita in visitasDoDia(dia.data)"
              :key="visita.id"
              :visita="visita"
              variante="compacta"
              @selecionar="emit('selecionarVisita', $event)"
            />
          </div>
          <p v-else class="text-xs text-gray-400">Nenhuma visita</p>
        </div>
      </div>
    </div>

    <!-- ============================ Visão de semana ============================ -->
    <div v-else-if="visao === 'semana'" class="border border-gray-200 rounded-lg overflow-x-auto">
      <div class="min-w-[46rem]">
        <div class="grid grid-cols-[4rem_repeat(7,1fr)] bg-gray-50 border-b border-gray-200">
          <div></div>
          <div
            v-for="dia in semana"
            :key="dia.data"
            class="px-2 py-2 text-center border-l border-gray-200"
            :class="ehHoje(dia.data) ? 'bg-green-50' : ''"
          >
            <p class="text-[11px] font-semibold text-gray-500 uppercase">{{ NOMES_DIAS_SEMANA_ABREVIADO[dia.diaSemana] }}</p>
            <p class="text-sm font-semibold" :class="ehHoje(dia.data) ? 'text-green-700' : 'text-gray-800'">
              {{ dia.dia }}
            </p>
          </div>
        </div>

        <!-- Visita sem horário: faixa própria no topo, nunca some -->
        <div class="grid grid-cols-[4rem_repeat(7,1fr)] border-b border-gray-200 bg-amber-50">
          <div class="px-2 py-1.5 text-[11px] font-medium text-gray-500 flex items-center">Sem horário</div>
          <div
            v-for="dia in semana"
            :key="`sem-horario-${dia.data}`"
            class="px-1 py-1 border-l border-gray-200 space-y-1 min-h-[2.25rem]"
            :class="classeDoAlvo(alvoSemHorario(dia.data))"
            @dragover="aoArrastarSobre($event, alvoSemHorario(dia.data))"
            @dragleave="aoSairDoAlvo(alvoSemHorario(dia.data))"
            @drop="aoSoltar($event, alvoSemHorario(dia.data))"
          >
            <CartaoDeVisita
              v-for="visita in semHorario(dia.data)"
              :key="visita.id"
              :visita="visita"
              variante="compacta"
              :draggable="podeArrastar(visita)"
              @dragstart="aoIniciarArrasto($event, visita)"
              @dragend="aoTerminarArrasto"
              @selecionar="emit('selecionarVisita', $event)"
            />
          </div>
        </div>

        <div
          v-for="faixa in faixas"
          :key="faixa.hora"
          class="grid grid-cols-[4rem_repeat(7,1fr)] border-b border-gray-100"
        >
          <div class="px-2 py-1.5 text-[11px] text-gray-400">{{ faixa.label }}</div>
          <div
            v-for="dia in semana"
            :key="`${faixa.hora}-${dia.data}`"
            class="px-1 py-1 border-l border-gray-100 space-y-1 min-h-[2.75rem]"
            :class="[ehHoje(dia.data) ? 'bg-green-50/50' : '', classeDoAlvo(alvoFaixa(dia.data, faixa.hora))]"
            @dragover="aoArrastarSobre($event, alvoFaixa(dia.data, faixa.hora))"
            @dragleave="aoSairDoAlvo(alvoFaixa(dia.data, faixa.hora))"
            @drop="aoSoltar($event, alvoFaixa(dia.data, faixa.hora))"
          >
            <CartaoDeVisita
              v-for="visita in visitasNaFaixa(dia.data, faixa.hora)"
              :key="visita.id"
              :visita="visita"
              variante="compacta"
              :draggable="podeArrastar(visita)"
              @dragstart="aoIniciarArrasto($event, visita)"
              @dragend="aoTerminarArrasto"
              @selecionar="emit('selecionarVisita', $event)"
            />
          </div>
        </div>
      </div>
    </div>

    <!-- ============================== Visão de dia ============================== -->
    <div v-else class="border border-gray-200 rounded-lg overflow-hidden">
      <div
        class="px-3 py-2 border-b border-gray-200"
        :class="ehHoje(dataReferencia) ? 'bg-green-50' : 'bg-gray-50'"
      >
        <p class="text-sm font-semibold text-gray-800">{{ rotuloPeriodo }}</p>
      </div>

      <div v-if="visitasDoDiaTotal === 0" class="p-6 text-center text-sm text-gray-400">
        Nenhuma visita neste dia.
      </div>

      <template v-else>
        <div
          v-if="semHorario(dataReferencia).length"
          class="p-3 border-b border-gray-100 bg-amber-50"
          :class="classeDoAlvo(alvoSemHorario(dataReferencia))"
          @dragover="aoArrastarSobre($event, alvoSemHorario(dataReferencia))"
          @dragleave="aoSairDoAlvo(alvoSemHorario(dataReferencia))"
          @drop="aoSoltar($event, alvoSemHorario(dataReferencia))"
        >
          <p class="text-[11px] font-semibold text-gray-500 uppercase mb-2">Sem horário</p>
          <div class="space-y-2">
            <CartaoDeVisita
              v-for="visita in semHorario(dataReferencia)"
              :key="visita.id"
              :visita="visita"
              variante="detalhada"
              :draggable="podeArrastar(visita)"
              @dragstart="aoIniciarArrasto($event, visita)"
              @dragend="aoTerminarArrasto"
              @selecionar="emit('selecionarVisita', $event)"
            />
          </div>
        </div>

        <div class="divide-y divide-gray-100">
          <div v-for="faixa in faixas" :key="faixa.hora" class="flex gap-3 px-3 py-2">
            <div class="w-14 flex-shrink-0 text-xs text-gray-400 pt-1">{{ faixa.label }}</div>
            <div
              class="flex-1 space-y-2 min-h-[2.5rem]"
              :class="classeDoAlvo(alvoFaixa(dataReferencia, faixa.hora))"
              @dragover="aoArrastarSobre($event, alvoFaixa(dataReferencia, faixa.hora))"
              @dragleave="aoSairDoAlvo(alvoFaixa(dataReferencia, faixa.hora))"
              @drop="aoSoltar($event, alvoFaixa(dataReferencia, faixa.hora))"
            >
              <CartaoDeVisita
                v-for="visita in visitasNaFaixa(dataReferencia, faixa.hora)"
                :key="visita.id"
                :visita="visita"
                variante="detalhada"
                :draggable="podeArrastar(visita)"
                @dragstart="aoIniciarArrasto($event, visita)"
                @dragend="aoTerminarArrasto"
                @selecionar="emit('selecionarVisita', $event)"
              />
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>

  <!-- Conflito de horário ao arrastar uma visita (Task 10.6): nomeia a OS
       conflitante, com a opção de cancelar (a visita já voltou para a posição
       original) ou abrir a outra ordem de serviço. -->
  <Modal :show="conflitoArrasto !== null" @close="fecharConflitoArrasto">
    <template #icon>
      <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
      </svg>
    </template>
    <template #title>Conflito de horário</template>
    <template #content>
      <p class="text-sm text-gray-600 mb-3">{{ conflitoArrasto?.mensagem }}</p>
      <ul class="space-y-2">
        <li
          v-for="conflitante in conflitoArrasto?.conflitos || []"
          :key="conflitante.id"
          class="flex items-start justify-between gap-3 rounded-md border border-red-200 bg-red-50 p-3 text-sm"
        >
          <div class="min-w-0">
            <p class="font-semibold text-gray-900 truncate">
              {{ conflitante.order_number }} · {{ conflitante.cliente }}
            </p>
            <p class="text-gray-600">{{ conflitante.horario }}</p>
          </div>
          <Link :href="`/work-orders/${conflitante.id}`" class="btn-secondary-sm flex-shrink-0">
            Abrir OS
          </Link>
        </li>
      </ul>
    </template>
    <template #actions>
      <button type="button" class="btn-secondary" @click="fecharConflitoArrasto">Cancelar</button>
    </template>
  </Modal>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import CartaoDeVisita from './CartaoDeVisita.vue';
import Modal from '@/Components/Modal.vue';
import {
  NOMES_DIAS_SEMANA,
  NOMES_DIAS_SEMANA_ABREVIADO,
  gradeDoMes,
  diasDaSemana,
  faixasDeHora,
  partesDeIso,
  nomeDoMes,
  somarDias,
  somarMeses,
  diaDaSemanaSegunda,
} from '@/utils/calendario';
import { hojeISO } from '@/utils/formatDate';
import { useArrastarVisita } from '@/Composables/useArrastarVisita';

const props = defineProps({
  visitas: {
    type: Array,
    default: () => [],
  },
  visao: {
    type: String,
    default: 'mes',
    validator: (valor) => ['dia', 'semana', 'mes'].includes(valor),
  },
  dataReferencia: {
    type: String,
    required: true,
  },
});

const emit = defineEmits(['mudarPeriodo', 'selecionarVisita']);

// `hoje` não precisa ser reativo: se a aba ficar aberta virando o dia, uma
// atualização de página (navegação de período, F5) já resolve.
const hoje = hojeISO();

function ehHoje(dataIso) {
  return dataIso === hoje;
}

const referencia = computed(() => partesDeIso(props.dataReferencia));

// --- Visão de mês -----------------------------------------------------------------
const grade = computed(() => gradeDoMes(referencia.value.ano, referencia.value.mes));
const diasDoMesAchatado = computed(() => grade.value.flat());
const diasDoMesAtual = computed(() => diasDoMesAchatado.value.filter((dia) => dia.mesAtual));

// --- Visão de semana ----------------------------------------------------------------
const semana = computed(() => diasDaSemana(props.dataReferencia));

// --- Faixas de hora (semana e dia) -------------------------------------------------
const faixas = computed(() => faixasDeHora());
const horaMinima = computed(() => faixas.value[0]?.hora ?? 0);
const horaMaxima = computed(() => faixas.value[faixas.value.length - 1]?.hora ?? 23);

// --- Visitas indexadas por dia ------------------------------------------------------
const visitasPorDia = computed(() => {
  const mapa = {};

  for (const visita of props.visitas) {
    if (!visita?.data) continue;

    if (!mapa[visita.data]) {
      mapa[visita.data] = [];
    }

    mapa[visita.data].push(visita);
  }

  // Sem horário primeiro dentro do próprio dia (a faixa "sem horário" já as separa
  // nas visões de dia/semana; na visão de mês, ficam nos primeiros 3 lugares, o que é
  // aceitável: são a exceção, não a regra).
  for (const data in mapa) {
    mapa[data].sort((a, b) => (a.hora_inicio || '').localeCompare(b.hora_inicio || ''));
  }

  return mapa;
});

function visitasDoDia(data) {
  return visitasPorDia.value[data] || [];
}

function totalNoDia(data) {
  return visitasDoDia(data).length;
}

// --- Visão de mês: limite de 3 cartões por dia, com expansão local ------------------
const LIMITE_VISITAS_POR_DIA = 3;
const diasExpandidos = ref(new Set());

function alternarExpansao(data) {
  const novo = new Set(diasExpandidos.value);

  if (novo.has(data)) {
    novo.delete(data);
  } else {
    novo.add(data);
  }

  diasExpandidos.value = novo;
}

function temExtras(data) {
  return totalNoDia(data) > LIMITE_VISITAS_POR_DIA;
}

function visitasVisiveis(data) {
  const todas = visitasDoDia(data);

  return diasExpandidos.value.has(data) ? todas : todas.slice(0, LIMITE_VISITAS_POR_DIA);
}

// --- Visões de semana/dia: sem horário e por faixa ----------------------------------
function semHorario(data) {
  return visitasDoDia(data).filter((visita) => !visita.hora_inicio);
}

// Visita com horário fora da janela padrão (antes das 7h ou depois das 19h) ainda
// precisa aparecer em algum lugar: cai na faixa da borda mais próxima em vez de
// desaparecer da grade.
function horaDaVisita(visita) {
  const hora = Number(String(visita.hora_inicio).split(':')[0]);

  if (Number.isNaN(hora)) {
    return horaMinima.value;
  }

  return Math.min(Math.max(hora, horaMinima.value), horaMaxima.value);
}

function visitasNaFaixa(data, hora) {
  return visitasDoDia(data).filter((visita) => visita.hora_inicio && horaDaVisita(visita) === hora);
}

const visitasDoDiaTotal = computed(() => totalNoDia(props.dataReferencia));

// --- Rótulo do período --------------------------------------------------------------
const rotuloPeriodo = computed(() => {
  if (props.visao === 'mes') {
    return `${nomeDoMes(referencia.value.mes)} de ${referencia.value.ano}`;
  }

  if (props.visao === 'semana') {
    const primeiro = semana.value[0];
    const ultimo = semana.value[6];
    const partesPrimeiro = partesDeIso(primeiro.data);
    const partesUltimo = partesDeIso(ultimo.data);

    if (partesPrimeiro.mes === partesUltimo.mes && partesPrimeiro.ano === partesUltimo.ano) {
      return `${primeiro.dia} a ${ultimo.dia} de ${nomeDoMes(partesUltimo.mes)} de ${partesUltimo.ano}`;
    }

    return `${primeiro.dia} de ${nomeDoMes(partesPrimeiro.mes)} a ${ultimo.dia} de ${nomeDoMes(partesUltimo.mes)} de ${partesUltimo.ano}`;
  }

  const indiceSemana = diaDaSemanaSegunda(referencia.value.ano, referencia.value.mes, referencia.value.dia);

  return `${NOMES_DIAS_SEMANA[indiceSemana]}, ${referencia.value.dia} de ${nomeDoMes(referencia.value.mes)} de ${referencia.value.ano}`;
});

// --- Navegação entre períodos --------------------------------------------------------
function periodoDoMes(ano, mes) {
  const gradeDoAlvo = gradeDoMes(ano, mes);

  return { inicio: gradeDoAlvo[0][0].data, fim: gradeDoAlvo[gradeDoAlvo.length - 1][6].data };
}

function emitirMudancaDePeriodo(novaDataReferencia) {
  let inicio;
  let fim;

  if (props.visao === 'mes') {
    const partes = partesDeIso(novaDataReferencia);
    ({ inicio, fim } = periodoDoMes(partes.ano, partes.mes));
  } else if (props.visao === 'semana') {
    const diasDaNovaSemana = diasDaSemana(novaDataReferencia);
    inicio = diasDaNovaSemana[0].data;
    fim = diasDaNovaSemana[6].data;
  } else {
    inicio = novaDataReferencia;
    fim = novaDataReferencia;
  }

  emit('mudarPeriodo', { dataReferencia: novaDataReferencia, inicio, fim });
}

function irParaAnterior() {
  if (props.visao === 'mes') {
    emitirMudancaDePeriodo(somarMeses(props.dataReferencia, -1));
  } else if (props.visao === 'semana') {
    emitirMudancaDePeriodo(somarDias(props.dataReferencia, -7));
  } else {
    emitirMudancaDePeriodo(somarDias(props.dataReferencia, -1));
  }
}

function irParaProximo() {
  if (props.visao === 'mes') {
    emitirMudancaDePeriodo(somarMeses(props.dataReferencia, 1));
  } else if (props.visao === 'semana') {
    emitirMudancaDePeriodo(somarDias(props.dataReferencia, 7));
  } else {
    emitirMudancaDePeriodo(somarDias(props.dataReferencia, 1));
  }
}

function irParaHoje() {
  emitirMudancaDePeriodo(hoje);
}

// --- Reagendamento por arrastar e soltar (Task 10.6) --------------------------------
// Em tela de toque o arrasto nativo não é confiável (ver o comentário em
// useArrastarVisita.js); a ação "Reagendar" do painel lateral (Task 10.5) continua
// sendo o caminho em qualquer dispositivo, sem nada extra aqui: o clique/toque no
// cartão já emite `selecionarVisita` independentemente de arrasto.
const mensagemAvisoArrasto = ref(null);
const mensagemErroArrasto = ref(null);
const conflitoArrasto = ref(null);

function fecharConflitoArrasto() {
  conflitoArrasto.value = null;
}

const {
  podeArrastar,
  ehAlvoAtivo,
  aoIniciarArrasto,
  aoTerminarArrasto,
  aoArrastarSobre,
  aoSairDoAlvo,
  aoSoltar,
} = useArrastarVisita({
  onConflito: (dados) => {
    mensagemErroArrasto.value = null;
    conflitoArrasto.value = { mensagem: dados.message, conflitos: dados.conflitos || [] };
  },
  onAviso: (mensagem) => {
    mensagemAvisoArrasto.value = mensagem;
  },
  onErro: (mensagem) => {
    mensagemAvisoArrasto.value = null;
    mensagemErroArrasto.value = mensagem;
  },
});

function alvoDia(data) {
  return { tipo: 'dia', data };
}

function alvoSemHorario(data) {
  return { tipo: 'semHorario', data };
}

function alvoFaixa(data, hora) {
  return { tipo: 'faixa', data, hora };
}

// Classe de destaque do alvo sob o cursor durante o arrasto.
function classeDoAlvo(alvo) {
  return ehAlvoAtivo(alvo) ? 'ring-2 ring-inset ring-green-500 bg-green-50' : '';
}
</script>
