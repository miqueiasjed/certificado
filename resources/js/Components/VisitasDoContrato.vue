<template>
  <Card padding="none">
    <div class="px-6 py-4 border-b border-gray-200 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <h3 class="text-lg font-medium text-gray-900">Visitas previstas</h3>
        <p class="text-sm text-gray-500">Calendário calculado a partir da periodicidade do contrato.</p>
      </div>
      <button
        v-if="mostrarBotaoGerar"
        type="button"
        class="btn-primary"
        @click="abrirConfirmacaoGerar"
      >
        <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
        </svg>
        Gerar visitas
      </button>
    </div>

    <div class="p-6 space-y-6">
      <!-- Resultado da última geração -->
      <div
        v-if="resultadoGeracao"
        class="rounded-md border p-4 text-sm flex items-start justify-between gap-3"
        :class="resultadoGeracao.criadas > 0 ? 'bg-green-50 border-green-200 text-green-800' : 'bg-gray-50 border-gray-200 text-gray-700'"
      >
        <p>{{ resultadoGeracao.message }}</p>
        <button type="button" class="text-gray-400 hover:text-gray-600" @click="resultadoGeracao = null">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>

      <!-- Alerta de visitas vencidas não executadas: precisa saltar aos olhos -->
      <div
        v-if="quantidadeVencidas > 0"
        class="rounded-md border-2 border-red-300 bg-red-50 p-4 flex items-start gap-3"
      >
        <svg class="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
        </svg>
        <p class="text-sm font-medium text-red-800">
          {{ quantidadeVencidas }} visita(s) vencida(s) sem execução: OS atrasada, sem OS gerada, ou cancelada sem
          nenhuma visita ter acontecido na data. Confira as linhas marcadas em vermelho abaixo.
        </p>
      </div>

      <!-- Resultado da última justificativa -->
      <div
        v-if="resultadoJustificativa"
        class="rounded-md border p-4 text-sm flex items-start justify-between gap-3"
        :class="resultadoJustificativa.sucesso ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'"
      >
        <p>{{ resultadoJustificativa.message }}</p>
        <button type="button" class="text-gray-400 hover:text-gray-600" @click="resultadoJustificativa = null">
          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>

      <!-- Estado vazio: precisa dizer por que está vazio -->
      <div v-if="motivoVazio" class="text-center py-10">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
        </svg>

        <h4 class="mt-3 text-sm font-medium text-gray-900">{{ textoEstadoVazio.titulo }}</h4>
        <p class="mt-1 text-sm text-gray-500 max-w-md mx-auto">{{ textoEstadoVazio.descricao }}</p>

        <div v-if="motivoVazio === 'sem_periodicidade' && pode('contrato-editar')" class="mt-4">
          <Link :href="`/contracts/${contrato.id}/edit#visit_frequency`" class="btn-secondary">
            Editar periodicidade
          </Link>
        </div>
        <div v-else-if="motivoVazio === 'fora_vigencia' && pode('contrato-editar')" class="mt-4">
          <Link :href="`/contracts/${contrato.id}/edit`" class="btn-secondary">
            Conferir início e término do contrato
          </Link>
        </div>
      </div>

      <!-- Linha do tempo das visitas -->
      <ul v-else class="space-y-3">
        <li
          v-for="visita in listaVisitas"
          :key="visita.numero"
          class="flex flex-col gap-3 rounded-lg border p-4 sm:flex-row sm:items-center sm:justify-between"
          :class="situacaoInfo(visita).destaque
            ? 'border-red-300 bg-red-50'
            : 'border-gray-200 bg-white'"
        >
          <div class="flex items-start gap-3">
            <span
              class="flex-shrink-0 flex items-center justify-center w-9 h-9 rounded-full"
              :class="situacaoInfo(visita).iconeClasses"
            >
              <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" :d="situacaoInfo(visita).icone"></path>
              </svg>
            </span>

            <div>
              <p class="text-sm font-medium text-gray-900" :class="{ 'line-through text-gray-500': visita.situacao === 'cancelada' }">
                Visita {{ visita.numero }}<template v-if="contrato.visit_count"> de {{ contrato.visit_count }}</template>
              </p>
              <p class="text-sm text-gray-600">{{ visita.data }}</p>
              <p v-if="situacaoInfo(visita).subtitulo" class="text-xs text-red-700 mt-0.5">
                {{ situacaoInfo(visita).subtitulo }}
              </p>

              <!-- A lacuna continua visível, agora com o motivo. É esta caixa
                   que responde à fiscalização por que a visita não aconteceu. -->
              <div v-if="visita.justificativa" class="mt-2 rounded-md border border-amber-200 bg-amber-50 p-3">
                <p class="text-xs font-semibold text-amber-900">Visita não realizada, justificada</p>
                <p class="text-sm text-amber-900 mt-1 whitespace-pre-line">{{ visita.justificativa.motivo }}</p>
                <p class="text-xs text-amber-700 mt-1">
                  Por {{ visita.justificativa.autor || 'não identificado' }}
                  <template v-if="visita.justificativa.registrada_em"> em {{ visita.justificativa.registrada_em }}</template>
                </p>
              </div>
            </div>
          </div>

          <div class="flex flex-wrap items-center gap-3 sm:justify-end">
            <span
              class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border"
              :class="[situacaoInfo(visita).badgeClasses, situacaoInfo(visita).bordaTracejada ? 'border-dashed' : '']"
            >
              {{ situacaoInfo(visita).label }}
            </span>

            <Link
              v-if="visita.ordem_servico && pode('ordem-servico-ver')"
              :href="`/work-orders/${visita.ordem_servico.id}`"
              class="text-sm text-blue-600 hover:text-blue-800 font-medium whitespace-nowrap"
            >
              Ver OS {{ visita.ordem_servico.order_number || `#${visita.ordem_servico.id}` }}
            </Link>

            <button
              v-if="podeJustificar(visita)"
              type="button"
              class="btn-secondary-sm whitespace-nowrap"
              @click="abrirJustificativa(visita)"
            >
              Justificar
            </button>

            <button
              v-if="visita.justificativa && pode('contrato-editar')"
              type="button"
              class="text-sm text-red-600 hover:text-red-800 font-medium whitespace-nowrap"
              @click="abrirRemocaoDeJustificativa(visita)"
            >
              Remover justificativa
            </button>
          </div>
        </li>
      </ul>
    </div>
  </Card>

  <ConfirmDeleteModal
    :show="mostrarModalGerar"
    variant="warning"
    title="Gerar visitas do contrato"
    subtitle="A ação cria Ordens de Serviço agendadas na agenda do cliente."
    :message="mensagemConfirmacaoGerar"
    confirm-text="Gerar visitas"
    processing-text="Gerando..."
    :processing="gerando"
    @confirm="confirmarGeracao"
    @cancel="mostrarModalGerar = false"
  />

  <!-- Justificar a visita que não aconteceu. Nada é criado no passado: o que
       é gravado é o motivo, e é ele que sai do painel de pendências. -->
  <Modal :show="mostrarModalJustificar" @close="fecharJustificativa">
    <template #icon>
      <svg class="h-6 w-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
      </svg>
    </template>
    <template #title>Justificar visita não realizada</template>
    <template #content>
      <p class="text-sm text-gray-700 mb-4">
        Visita {{ visitaEmJustificativa?.numero }}, prevista para {{ visitaEmJustificativa?.data }}. Nenhuma Ordem de
        Serviço será criada com data no passado. O motivo abaixo fica gravado no contrato, com seu nome e a data de
        hoje, e é ele que responde por esta lacuna perante fiscalização.
      </p>
      <label class="block text-sm font-medium text-gray-700 mb-1">Motivo *</label>
      <textarea
        v-model="motivoJustificativa"
        rows="4"
        maxlength="500"
        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
        :class="{ 'border-red-500': erroJustificativa }"
        placeholder="Ex.: contrato suspenso no período a pedido do cliente; acesso não autorizado na data; visita realizada e registrada fora do sistema."
      ></textarea>
      <p v-if="erroJustificativa" class="mt-1 text-sm text-red-600">{{ erroJustificativa }}</p>
    </template>
    <template #actions>
      <button type="button" class="btn-secondary" :disabled="justificando" @click="fecharJustificativa">
        Cancelar
      </button>
      <button type="button" class="btn-primary ml-3" :disabled="justificando" @click="confirmarJustificativa">
        {{ justificando ? 'Salvando...' : 'Salvar justificativa' }}
      </button>
    </template>
  </Modal>

  <ConfirmDeleteModal
    :show="mostrarModalRemoverJustificativa"
    title="Remover justificativa"
    subtitle="A data volta a contar como pendência de conformidade."
    :message="mensagemRemocaoDeJustificativa"
    confirm-text="Sim, remover"
    processing-text="Removendo..."
    :processing="removendoJustificativa"
    @confirm="confirmarRemocaoDeJustificativa"
    @cancel="mostrarModalRemoverJustificativa = false"
  />
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import Modal from '@/Components/Modal.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { hojeISO } from '@/utils/formatDate';

const props = defineProps({
  contrato: {
    type: Object,
    required: true,
  },
  visitas: {
    type: Array,
    default: () => [],
  },
});

const { pode } = usePermissoes();

const listaVisitas = ref([...props.visitas]);
const gerando = ref(false);
const mostrarModalGerar = ref(false);
const resultadoGeracao = ref(null);

// Descrição visual de cada situação: label, cor E ícone diferente, para quem
// enxerga mal cor ou imprime em preto e branco conseguir distinguir do mesmo
// jeito. `destaque` marca as duas situações de visita vencida (com e sem OS
// gerada), que precisam saltar aos olhos na tela.
const SITUACOES = {
  agendada: {
    label: 'Agendada',
    badgeClasses: 'bg-blue-100 text-blue-800 border-blue-200',
    iconeClasses: 'bg-blue-100 text-blue-700',
    icone: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    destaque: false,
    bordaTracejada: false,
  },
  atrasada: {
    label: 'Atrasada (OS não executada)',
    badgeClasses: 'bg-red-100 text-red-800 border-red-300',
    iconeClasses: 'bg-red-100 text-red-700',
    icone: 'M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z',
    destaque: true,
    bordaTracejada: false,
  },
  executada: {
    label: 'Executada',
    badgeClasses: 'bg-green-100 text-green-800 border-green-200',
    iconeClasses: 'bg-green-100 text-green-700',
    icone: 'M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z',
    destaque: false,
    bordaTracejada: false,
  },
  em_execucao: {
    label: 'Em execução',
    badgeClasses: 'bg-yellow-100 text-yellow-800 border-yellow-300',
    iconeClasses: 'bg-yellow-100 text-yellow-700',
    icone: 'M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664zM21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    destaque: false,
    bordaTracejada: false,
  },
  cancelada: {
    label: 'Cancelada',
    badgeClasses: 'bg-gray-100 text-gray-600 border-gray-300',
    iconeClasses: 'bg-gray-100 text-gray-500',
    icone: 'M18.364 5.636l-12.728 12.728M5.636 5.636l12.728 12.728',
    destaque: false,
    bordaTracejada: false,
  },
  // Não é uma situação separada vinda do backend: 'cancelada' com data no
  // passado é a mesma situação 'cancelada', mas com um segundo significado.
  // A tela do contrato mostra o que aconteceu (foi cancelada); o painel de
  // pendências mostra o que falta (data vencida sem nenhuma visita
  // realizada). Os dois estão certos ao mesmo tempo, então aqui ela precisa
  // do alerta vermelho, senão o usuário vê o painel acusando pendência e a
  // tela do contrato parecendo em ordem, sem entender de onde veio a
  // acusação. O ícone de alerta (igual ao de 'pendente') soma com o texto
  // riscado (igual ao de 'cancelada' neutra): a combinação dos dois é o que
  // distingue esta linha das outras duas sem depender só da cor vermelha.
  cancelada_vencida: {
    label: 'Cancelada, vencida sem visita',
    badgeClasses: 'bg-red-100 text-red-800 border-red-300',
    iconeClasses: 'bg-red-100 text-red-700',
    icone: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z',
    destaque: true,
    bordaTracejada: false,
    subtitulo: 'A data já passou e a visita foi cancelada sem que nenhuma outra a substituísse: conta como pendência de conformidade no painel.',
  },
  pendente: {
    label: 'Pendente (sem OS gerada)',
    badgeClasses: 'bg-red-100 text-red-800 border-red-300',
    iconeClasses: 'bg-red-100 text-red-700',
    icone: 'M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z',
    destaque: true,
    bordaTracejada: false,
  },
  // Mesma lacuna de 'pendente', com motivo registrado. Continua na lista, e é
  // o ponto: quem abre o contrato meses depois precisa ver a visita que não
  // aconteceu e por quê. O que ela deixa de ser é alerta vermelho no painel.
  justificada: {
    label: 'Não realizada, justificada',
    badgeClasses: 'bg-amber-100 text-amber-800 border-amber-300',
    iconeClasses: 'bg-amber-100 text-amber-700',
    icone: 'M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z',
    destaque: false,
    bordaTracejada: false,
  },
  prevista: {
    label: 'Prevista (ainda não gerada)',
    badgeClasses: 'bg-gray-100 text-gray-700 border-gray-300',
    iconeClasses: 'bg-gray-100 text-gray-500',
    icone: 'M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z',
    destaque: false,
    bordaTracejada: true,
  },
};

// A data da visita chega do backend já formatada em texto 'dd/mm/yyyy'
// (BusinessDate, fuso do negócio). Comparar como texto 'yyyy-mm-dd', nunca
// instanciando `new Date('dd/mm/yyyy')`: o construtor nem reconhece esse
// formato de forma confiável entre navegadores, e a skill de datas do
// projeto proíbe tratar data sem hora como instante.
function dataVisitaComparavel(dataBr) {
  const partes = String(dataBr || '').split('/');

  if (partes.length !== 3) {
    return null;
  }

  const [dia, mes, ano] = partes;

  return `${ano}-${mes}-${dia}`;
}

function estaNoPassado(dataBr) {
  const comparavel = dataVisitaComparavel(dataBr);

  return comparavel !== null && comparavel < hojeISO();
}

// Data cancelada e vencida com motivo registrado deixa de ser alerta: o painel
// de conformidade também parou de contá-la, e as duas telas precisam dizer a
// mesma coisa. O motivo continua aparecendo na caixa âmbar da linha.
function ehCanceladaVencida(visita) {
  return visita.situacao === 'cancelada' && estaNoPassado(visita.data) && !visita.justificativa;
}

function situacaoInfo(visita) {
  if (ehCanceladaVencida(visita)) {
    return SITUACOES.cancelada_vencida;
  }

  return SITUACOES[visita.situacao] ?? {
    label: visita.situacao,
    badgeClasses: 'bg-gray-100 text-gray-700 border-gray-300',
    iconeClasses: 'bg-gray-100 text-gray-500',
    icone: 'M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z',
    destaque: false,
    bordaTracejada: false,
  };
}

const quantidadeVencidas = computed(
  () => listaVisitas.value.filter(
    (v) => v.situacao === 'atrasada' || v.situacao === 'pendente' || ehCanceladaVencida(v)
  ).length
);

// Justificar só faz sentido para a lacuna que a geração não resolve: data
// vencida sem OS ('pendente') e data vencida cuja única OS foi cancelada, que
// é a outra forma de o período ficar sem visita. Linha já justificada oferece
// remover, não justificar de novo.
function podeJustificar(visita) {
  if (!pode('contrato-editar') || visita.justificativa) {
    return false;
  }

  return visita.situacao === 'pendente' || ehCanceladaVencida(visita);
}

// Só a situação 'prevista' (data futura sem OS) é resolvida pelo botão:
// GeracaoDeVisitasService nunca cria OS com data retroativa, então visita
// 'pendente' (vencida sem OS) continua pendente mesmo depois de gerar.
const quantidadeAGerar = computed(
  () => listaVisitas.value.filter((v) => v.situacao === 'prevista').length
);

const mostrarBotaoGerar = computed(() => quantidadeAGerar.value > 0 && pode('contrato-editar'));

const mensagemConfirmacaoGerar = computed(
  () => `Isso vai criar ${quantidadeAGerar.value} Ordem(ns) de Serviço agendada(s), uma para cada data prevista que ainda não tem OS. Deseja continuar?`
);

// Motivo de a lista estar vazia: contrato pontual (sem calendário), sem
// periodicidade reconhecida pelo backfill, ou período de vigência que não
// comporta nenhuma data com a periodicidade configurada.
const motivoVazio = computed(() => {
  if (listaVisitas.value.length > 0) {
    return null;
  }

  if (props.contrato.service_type !== 'periodico') {
    return 'pontual';
  }

  if (!props.contrato.visit_frequency_valor || !props.contrato.visit_frequency_unidade) {
    return 'sem_periodicidade';
  }

  return 'fora_vigencia';
});

const TEXTOS_ESTADO_VAZIO = {
  pontual: {
    titulo: 'Contrato pontual, sem calendário de visitas',
    descricao: 'Este contrato é de visita única (pontual) e não tem periodicidade recorrente. Nenhuma visita é gerada automaticamente para este tipo de contrato.',
  },
  sem_periodicidade: {
    titulo: 'Periodicidade não configurada',
    descricao: 'O contrato é periódico, mas o valor gravado em "frequência de visita" não foi reconhecido. Sem essa informação, o sistema não calcula nenhuma data prevista.',
  },
  fora_vigencia: {
    titulo: 'Nenhuma visita dentro da vigência',
    descricao: 'A data de início e a data de término deste contrato não comportam nenhuma visita com a periodicidade configurada. Confira as duas datas.',
  },
};

const textoEstadoVazio = computed(
  () => TEXTOS_ESTADO_VAZIO[motivoVazio.value] ?? TEXTOS_ESTADO_VAZIO.sem_periodicidade
);

function abrirConfirmacaoGerar() {
  resultadoGeracao.value = null;
  mostrarModalGerar.value = true;
}

async function confirmarGeracao() {
  gerando.value = true;

  try {
    const resposta = await fetch(`/contracts/${props.contrato.id}/visitas/gerar`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        Accept: 'application/json',
      },
    });

    const dados = await resposta.json();

    if (resposta.ok && dados.success) {
      listaVisitas.value = dados.visitas ?? listaVisitas.value;
      resultadoGeracao.value = { criadas: dados.criadas ?? 0, message: dados.message };
    } else {
      resultadoGeracao.value = {
        criadas: 0,
        message: dados.message || 'Não foi possível gerar as visitas deste contrato.',
      };
    }
  } catch (erro) {
    resultadoGeracao.value = { criadas: 0, message: 'Não foi possível gerar as visitas deste contrato.' };
  } finally {
    gerando.value = false;
    mostrarModalGerar.value = false;
  }
}

// -----------------------------------------------------------------
// Justificativa de visita não realizada
// -----------------------------------------------------------------

const mostrarModalJustificar = ref(false);
const visitaEmJustificativa = ref(null);
const motivoJustificativa = ref('');
const erroJustificativa = ref('');
const justificando = ref(false);
const resultadoJustificativa = ref(null);

const mostrarModalRemoverJustificativa = ref(false);
const visitaEmRemocao = ref(null);
const removendoJustificativa = ref(false);

const mensagemRemocaoDeJustificativa = computed(() => {
  if (!visitaEmRemocao.value) return '';

  return `A justificativa da visita ${visitaEmRemocao.value.numero}, prevista para ${visitaEmRemocao.value.data}, será apagada e a data volta a aparecer no painel de pendências de conformidade.`;
});

function cabecalhosJson() {
  return {
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
    Accept: 'application/json',
  };
}

function abrirJustificativa(visita) {
  resultadoJustificativa.value = null;
  erroJustificativa.value = '';
  motivoJustificativa.value = '';
  visitaEmJustificativa.value = visita;
  mostrarModalJustificar.value = true;
}

function fecharJustificativa() {
  if (justificando.value) return;

  mostrarModalJustificar.value = false;
  visitaEmJustificativa.value = null;
  motivoJustificativa.value = '';
  erroJustificativa.value = '';
}

async function confirmarJustificativa() {
  const visita = visitaEmJustificativa.value;

  if (!visita) return;

  const motivo = motivoJustificativa.value.trim();

  if (motivo.length < 5) {
    erroJustificativa.value = 'O motivo é obrigatório e precisa ter pelo menos 5 caracteres.';
    return;
  }

  const dataIso = dataVisitaComparavel(visita.data);

  if (!dataIso) {
    erroJustificativa.value = 'Não foi possível identificar a data desta visita.';
    return;
  }

  justificando.value = true;
  erroJustificativa.value = '';

  try {
    const resposta = await fetch(`/contracts/${props.contrato.id}/visitas/justificativas`, {
      method: 'POST',
      headers: cabecalhosJson(),
      body: JSON.stringify({ datas: [dataIso], motivo }),
    });

    const dados = await resposta.json();

    if (resposta.ok && dados.success) {
      listaVisitas.value = dados.visitas ?? listaVisitas.value;
      resultadoJustificativa.value = { sucesso: true, message: dados.message };
      mostrarModalJustificar.value = false;
      visitaEmJustificativa.value = null;
      motivoJustificativa.value = '';
    } else {
      erroJustificativa.value = dados.message
        || Object.values(dados.errors ?? {}).flat()[0]
        || 'Não foi possível registrar a justificativa.';
    }
  } catch (erro) {
    erroJustificativa.value = 'Não foi possível registrar a justificativa.';
  } finally {
    justificando.value = false;
  }
}

function abrirRemocaoDeJustificativa(visita) {
  resultadoJustificativa.value = null;
  visitaEmRemocao.value = visita;
  mostrarModalRemoverJustificativa.value = true;
}

async function confirmarRemocaoDeJustificativa() {
  const visita = visitaEmRemocao.value;

  if (!visita?.justificativa) return;

  removendoJustificativa.value = true;

  try {
    const resposta = await fetch(
      `/contracts/${props.contrato.id}/visitas/justificativas/${visita.justificativa.id}`,
      { method: 'DELETE', headers: cabecalhosJson() }
    );

    const dados = await resposta.json();

    if (resposta.ok && dados.success) {
      listaVisitas.value = dados.visitas ?? listaVisitas.value;
      resultadoJustificativa.value = { sucesso: true, message: dados.message };
    } else {
      resultadoJustificativa.value = {
        sucesso: false,
        message: dados.message || 'Não foi possível remover a justificativa.',
      };
    }
  } catch (erro) {
    resultadoJustificativa.value = { sucesso: false, message: 'Não foi possível remover a justificativa.' };
  } finally {
    removendoJustificativa.value = false;
    mostrarModalRemoverJustificativa.value = false;
    visitaEmRemocao.value = null;
  }
}
</script>
