<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Agenda"
        description="Calendário de ordens de serviço, com filtros e atribuição de técnicos"
      />
    </template>

    <div class="space-y-4">
      <!-- Aviso não bloqueante (ex.: mesmo técnico com outra visita no dia) -->
      <div
        v-if="mensagemAviso"
        class="flex items-start justify-between gap-3 rounded-md border border-amber-200 bg-amber-50 p-4"
      >
        <p class="text-sm text-amber-800">{{ mensagemAviso }}</p>
        <button type="button" class="text-amber-600 hover:text-amber-800" @click="mensagemAviso = null">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <div v-if="erroCarregamento" class="rounded-md border border-red-200 bg-red-50 p-4">
        <p class="text-sm text-red-800">{{ erroCarregamento }}</p>
      </div>

      <!-- Confirmação de criar/concluir/cancelar/promover compromisso (Task 30.5) -->
      <div
        v-if="mensagemSucessoCompromisso"
        class="flex items-start justify-between gap-3 rounded-md border border-green-200 bg-green-50 p-4"
      >
        <p class="text-sm text-green-800">{{ mensagemSucessoCompromisso }}</p>
        <button type="button" class="text-green-600 hover:text-green-800" @click="mensagemSucessoCompromisso = null">
          <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
          </svg>
        </button>
      </div>

      <!-- Seletor de visão e filtros -->
      <Card padding="small">
        <div class="flex flex-col gap-4">
          <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="flex flex-wrap items-center gap-2">
              <button
                v-for="opcao in VISOES"
                :key="opcao.valor"
                type="button"
                :class="visao === opcao.valor ? 'btn-primary' : 'btn-secondary-sm'"
                @click="selecionarVisao(opcao.valor)"
              >
                {{ opcao.rotulo }}
              </button>
            </div>

            <!-- Compromisso avulso (Plano 30, Task 30.5): mesmo padrão de
                 `usePermissoes` de Epi/Roteiros, escondido para quem não tem a
                 permissão de escrita (o backend também recusa, isto é só a tela). -->
            <button
              v-if="podeGerenciarCompromisso"
              type="button"
              class="btn-primary"
              @click="mostrarModalNovoCompromisso = true"
            >
              Novo compromisso
            </button>
          </div>

          <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Técnico</label>
              <select
                v-model="filtros.technician_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                @change="aoMudarFiltroSelect"
              >
                <option value="">Todos os técnicos</option>
                <option value="sem_tecnico">Sem técnico</option>
                <option v-for="tecnico in tecnicos" :key="tecnico.id" :value="String(tecnico.id)">
                  {{ tecnico.name }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Tipo de serviço</label>
              <select
                v-model="filtros.service_id"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                @change="aoMudarFiltroSelect"
              >
                <option value="">Todos os serviços</option>
                <option v-for="servico in servicos" :key="servico.id" :value="String(servico.id)">
                  {{ servico.name }}
                </option>
              </select>
            </div>

            <div ref="situacaoFiltroEl" class="relative">
              <label class="block text-xs font-medium text-gray-600 mb-1">Situação</label>
              <button
                type="button"
                class="w-full flex items-center justify-between px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm text-left bg-white focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                @click="situacaoAberta = !situacaoAberta"
              >
                <span class="truncate">{{ situacaoRotulo }}</span>
                <svg class="h-4 w-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                </svg>
              </button>

              <div
                v-if="situacaoAberta"
                class="absolute z-20 mt-1 w-full min-w-[12rem] rounded-md border border-gray-200 bg-white p-2 shadow-lg space-y-1"
              >
                <label
                  v-for="opcao in OPCOES_STATUS"
                  :key="opcao.valor"
                  class="flex items-center gap-2 px-2 py-1 rounded hover:bg-gray-50 text-sm text-gray-700 cursor-pointer"
                >
                  <input
                    type="checkbox"
                    :value="opcao.valor"
                    v-model="filtros.status"
                    class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded"
                    @change="aoMudarFiltroSelect"
                  />
                  {{ opcao.rotulo }}
                </label>
              </div>
            </div>

            <div>
              <label class="block text-xs font-medium text-gray-600 mb-1">Cidade</label>
              <input
                v-model="filtros.cidade"
                type="text"
                placeholder="Filtrar por cidade"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                @input="aoDigitarCidade"
              />
            </div>
          </div>

          <div v-if="temFiltroAtivo" class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs text-gray-500">
              Filtros ativos: {{ filtrosAtivosTexto.join(' · ') }}
            </p>
            <button type="button" class="text-xs font-semibold text-green-700 hover:text-green-800" @click="limparFiltros">
              Limpar filtros
            </button>
          </div>
        </div>
      </Card>

      <!-- Estado vazio: nomeia os filtros ativos para o usuário não achar que perdeu dado -->
      <div
        v-if="!carregando && visitas.length === 0"
        class="rounded-lg border border-gray-200 bg-white p-8 text-center"
      >
        <p class="text-sm font-semibold text-gray-700">Nenhuma visita no período.</p>
        <p class="mt-1 text-xs text-gray-500">
          <template v-if="temFiltroAtivo">Filtros ativos: {{ filtrosAtivosTexto.join(' · ') }}</template>
          <template v-else>Nenhum filtro aplicado.</template>
        </p>
      </div>

      <!-- Calendário: overlay de carregamento cobre só esta área, sem piscar a página -->
      <div class="relative">
        <div
          v-if="carregando"
          class="absolute inset-0 z-10 flex items-start justify-center rounded-lg bg-white/60 pt-10"
        >
          <span class="inline-flex items-center gap-2 text-sm font-medium text-gray-600">
            <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            Carregando...
          </span>
        </div>

        <CalendarioAgenda
          :visitas="visitas"
          :visao="visao"
          :data-referencia="dataReferencia"
          @mudar-periodo="aoMudarPeriodo"
          @selecionar-visita="abrirPainel"
        />
      </div>

      <!-- Painel de carga por técnico: recolhível, no rodapé da agenda -->
      <CargaPorTecnico
        :periodo="periodoAtual"
        :versao="versaoCarga"
        @selecionar-tecnico="aoSelecionarTecnicoDaCarga"
      />
    </div>

    <!-- Painel lateral da visita selecionada -->
    <Transition
      enter-active-class="transition ease-out duration-200"
      enter-from-class="opacity-0"
      enter-to-class="opacity-100"
      leave-active-class="transition ease-in duration-150"
      leave-from-class="opacity-100"
      leave-to-class="opacity-0"
    >
      <div v-if="visitaSelecionada" class="fixed inset-0 z-40 flex justify-end">
        <div class="absolute inset-0 bg-gray-900/40" @click="fecharPainel"></div>

        <div class="relative flex h-full w-full max-w-md flex-col overflow-y-auto bg-white shadow-xl">
          <div class="flex items-start justify-between gap-3 border-b border-gray-200 px-5 py-4">
            <div class="min-w-0">
              <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">
                {{ ehCompromissoSelecionado ? 'Compromisso' : 'Visita' }}
              </p>
              <h3 class="truncate text-lg font-semibold text-gray-900">
                <template v-if="ehCompromissoSelecionado">
                  {{ rotuloDeTipoDeCompromisso(visitaSelecionada.tipo) }} · {{ visitaSelecionada.titulo }}
                </template>
                <template v-else>
                  {{ visitaSelecionada.numero }} · {{ visitaSelecionada.cliente?.nome || 'Cliente não informado' }}
                </template>
              </h3>
            </div>
            <button type="button" class="text-gray-400 hover:text-gray-600" @click="fecharPainel">
              <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div class="flex-1 space-y-6 px-5 py-4">
            <template v-if="!ehCompromissoSelecionado">
              <!-- Dados da OS -->
              <div class="space-y-1.5 text-sm">
                <p class="flex justify-between gap-2">
                  <span class="text-gray-500">Data</span>
                  <span class="font-medium text-gray-900">{{ formatarData(visitaSelecionada.data) || '—' }}</span>
                </p>
                <p class="flex justify-between gap-2">
                  <span class="text-gray-500">Horário</span>
                  <span class="font-medium text-gray-900">{{ horarioDaVisitaSelecionada }}</span>
                </p>
                <p class="flex justify-between gap-2">
                  <span class="text-gray-500">Serviço</span>
                  <span class="font-medium text-gray-900">{{ visitaSelecionada.servico?.nome || 'Não informado' }}</span>
                </p>
                <p class="flex justify-between gap-2">
                  <span class="text-gray-500">Situação</span>
                  <span class="font-medium text-gray-900">{{ visitaSelecionada.status_texto }}</span>
                </p>
                <p v-if="visitaSelecionada.endereco || visitaSelecionada.cidade" class="flex justify-between gap-2">
                  <span class="text-gray-500">Endereço</span>
                  <span class="font-medium text-gray-900 text-right">
                    {{ [visitaSelecionada.endereco, visitaSelecionada.cidade].filter(Boolean).join(' · ') }}
                  </span>
                </p>
              </div>

              <!-- Atribuir técnico -->
              <div class="space-y-2 border-t border-gray-100 pt-4">
                <label class="block text-sm font-medium text-gray-700">Atribuir técnico</label>
                <select
                  :value="visitaSelecionada.tecnico?.id ?? ''"
                  :disabled="atribuindoTecnico"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  @change="atribuirTecnico($event.target.value)"
                >
                  <option value="">Sem técnico</option>
                  <option v-for="tecnico in opcoesTecnico" :key="tecnico.id" :value="tecnico.id">
                    {{ tecnico.nome }}{{ tecnico.tem_aviso ? ' (aviso)' : '' }}
                  </option>
                </select>
                <p v-if="carregandoTecnicos" class="text-xs text-gray-400">Carregando técnicos disponíveis...</p>
                <p v-if="atribuirErro" class="text-sm text-red-600">{{ atribuirErro }}</p>
              </div>

              <!-- Reagendar -->
              <form class="space-y-3 border-t border-gray-100 pt-4" @submit.prevent="enviarReagendamento">
                <label class="block text-sm font-medium text-gray-700">Reagendar</label>

                <div>
                  <label class="block text-xs text-gray-500 mb-1">Data agendada *</label>
                  <input
                    v-model="reagendarForm.scheduled_date"
                    type="date"
                    required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                    :class="{ 'border-red-500': reagendarErros.scheduled_date }"
                  />
                  <p v-if="reagendarErros.scheduled_date" class="mt-1 text-xs text-red-600">
                    {{ reagendarErros.scheduled_date[0] }}
                  </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                  <div>
                    <label class="block text-xs text-gray-500 mb-1">Início</label>
                    <input
                      v-model="reagendarForm.hora_inicio"
                      type="time"
                      class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                      :class="{ 'border-red-500': reagendarErros.start_time }"
                    />
                    <p v-if="reagendarErros.start_time" class="mt-1 text-xs text-red-600">
                      {{ reagendarErros.start_time[0] }}
                    </p>
                  </div>
                  <div>
                    <label class="block text-xs text-gray-500 mb-1">Término</label>
                    <input
                      v-model="reagendarForm.hora_fim"
                      type="time"
                      class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                      :class="{ 'border-red-500': reagendarErros.end_time }"
                    />
                    <p v-if="reagendarErros.end_time" class="mt-1 text-xs text-red-600">
                      {{ reagendarErros.end_time[0] }}
                    </p>
                  </div>
                </div>

                <p v-if="reagendarErros.status" class="text-xs text-red-600">{{ reagendarErros.status[0] }}</p>
                <p v-if="reagendarErroGeral" class="text-sm text-red-600">{{ reagendarErroGeral }}</p>

                <button
                  type="submit"
                  class="btn-primary w-full justify-center"
                  :disabled="reagendando || !reagendarForm.scheduled_date"
                >
                  {{ reagendando ? 'Reagendando...' : 'Reagendar' }}
                </button>
              </form>
            </template>

            <!-- Compromisso avulso (Plano 30, Task 30.5): tipo, título, cliente/
                 endereço, técnico e observações são só leitura aqui; nada de
                 reagendar/atribuir técnico para compromisso nesta task. -->
            <template v-else>
              <div class="space-y-1.5 text-sm">
                <p class="flex justify-between gap-2">
                  <span class="text-gray-500">Tipo</span>
                  <span class="font-medium text-gray-900">{{ rotuloDeTipoDeCompromisso(visitaSelecionada.tipo) }}</span>
                </p>
                <p class="flex justify-between gap-2">
                  <span class="text-gray-500">Data</span>
                  <span class="font-medium text-gray-900">{{ formatarData(visitaSelecionada.data) || '—' }}</span>
                </p>
                <p class="flex justify-between gap-2">
                  <span class="text-gray-500">Horário</span>
                  <span class="font-medium text-gray-900">{{ horarioDaVisitaSelecionada }}</span>
                </p>
                <p class="flex justify-between gap-2">
                  <span class="text-gray-500">Situação</span>
                  <span class="font-medium text-gray-900">{{ visitaSelecionada.situacao_texto }}</span>
                </p>
                <p v-if="visitaSelecionada.cliente?.nome" class="flex justify-between gap-2">
                  <span class="text-gray-500">Cliente</span>
                  <span class="font-medium text-gray-900 text-right">{{ visitaSelecionada.cliente.nome }}</span>
                </p>
                <p v-if="visitaSelecionada.endereco || visitaSelecionada.cidade" class="flex justify-between gap-2">
                  <span class="text-gray-500">Endereço</span>
                  <span class="font-medium text-gray-900 text-right">
                    {{ [visitaSelecionada.endereco, visitaSelecionada.cidade].filter(Boolean).join(' · ') }}
                  </span>
                </p>
                <p class="flex justify-between gap-2">
                  <span class="text-gray-500">Técnico</span>
                  <span class="font-medium text-gray-900">
                    {{ visitaSelecionada.tecnico?.nome || 'Sem técnico atribuído' }}
                  </span>
                </p>
              </div>

              <!-- `observacoes` só existe no compromisso, nunca em OS. -->
              <div v-if="visitaSelecionada.observacoes" class="border-t border-gray-100 pt-4 text-sm">
                <p class="text-gray-500 mb-1">Observações</p>
                <p class="text-gray-900 whitespace-pre-line">{{ visitaSelecionada.observacoes }}</p>
              </div>

              <div v-if="podeGerenciarCompromisso" class="space-y-3 border-t border-gray-100 pt-4">
                <p v-if="acaoCompromissoErro" class="text-sm text-red-600">{{ acaoCompromissoErro }}</p>

                <div>
                  <label class="block text-xs text-gray-500 mb-1">Promover para (serviço opcional)</label>
                  <select
                    v-model="servicoParaPromocao"
                    :disabled="compromissoJaPromovido || processandoAcaoCompromisso"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  >
                    <option :value="null">Sem serviço definido</option>
                    <option v-for="servico in servicos" :key="servico.id" :value="servico.id">
                      {{ servico.name }}
                    </option>
                  </select>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                  <button
                    type="button"
                    class="btn-secondary-sm w-full justify-center"
                    :disabled="processandoAcaoCompromisso"
                    @click="concluirCompromisso"
                  >
                    Concluir
                  </button>
                  <button
                    type="button"
                    class="btn-secondary-sm w-full justify-center text-red-700"
                    :disabled="processandoAcaoCompromisso"
                    @click="pedirCancelamentoDeCompromisso"
                  >
                    Cancelar
                  </button>
                  <button
                    type="button"
                    class="btn-primary w-full justify-center"
                    :disabled="compromissoJaPromovido || processandoAcaoCompromisso"
                    :title="compromissoJaPromovido ? 'Este compromisso já foi promovido para uma ordem de serviço.' : ''"
                    @click="promoverCompromisso"
                  >
                    Promover para OS
                  </button>
                </div>
              </div>
            </template>
          </div>

          <div v-if="!ehCompromissoSelecionado" class="border-t border-gray-200 px-5 py-4">
            <Link :href="`/work-orders/${visitaSelecionada.id}`" class="btn-secondary w-full justify-center">
              Abrir ordem de serviço completa
            </Link>
          </div>
        </div>
      </div>
    </Transition>

    <!-- Criação de compromisso avulso (Task 30.5) -->
    <ModalNovoCompromisso
      :show="mostrarModalNovoCompromisso"
      :tecnicos="tecnicos"
      :data-inicial="dataReferencia"
      @close="mostrarModalNovoCompromisso = false"
      @criado="aoCriarCompromisso"
    />

    <!-- Confirmação de cancelamento de compromisso (Task 30.5): nunca `confirm()`
         nativo, mesmo padrão de exclusão do design system, com o tom "warning"
         (âmbar) porque cancelar não é destrutivo: o compromisso continua no
         histórico, só muda de situação. -->
    <ConfirmDeleteModal
      :show="mostrarConfirmacaoDeCancelamento"
      variant="warning"
      title="Cancelar compromisso"
      subtitle="O compromisso continua no histórico, só muda de situação."
      message="Tem certeza que deseja cancelar o compromisso"
      :item-name="visitaSelecionada?.titulo || ''"
      confirm-text="Sim, cancelar"
      cancel-text="Voltar"
      processing-text="Cancelando..."
      :processing="processandoAcaoCompromisso"
      @cancel="mostrarConfirmacaoDeCancelamento = false"
      @confirm="confirmarCancelamentoDeCompromisso"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, reactive, computed, onMounted, onUnmounted } from 'vue';
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import CalendarioAgenda from '@/Components/Calendario/CalendarioAgenda.vue';
import CargaPorTecnico from '@/Components/Calendario/CargaPorTecnico.vue';
import ModalNovoCompromisso from '@/Components/ModalNovoCompromisso.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { partesDeIso, gradeDoMes, diasDaSemana } from '@/utils/calendario';
import { hojeISO, formatarData, inputDateTimeParaUtc } from '@/utils/formatDate';
import { reagendarOrdem, tokenCsrf } from '@/utils/agendaApi';
import { rotuloDeTipoDeCompromisso } from '@/utils/compromisso';

const props = defineProps({
  periodoInicial: {
    type: Object,
    required: true,
  },
  tecnicos: {
    type: Array,
    default: () => [],
  },
  servicos: {
    type: Array,
    default: () => [],
  },
});

const { pode } = usePermissoes();
const podeGerenciarCompromisso = computed(() => pode('compromisso-gerenciar'));

const VISOES = [
  { valor: 'dia', rotulo: 'Dia' },
  { valor: 'semana', rotulo: 'Semana' },
  { valor: 'mes', rotulo: 'Mês' },
];

const OPCOES_STATUS = [
  { valor: 'pending', rotulo: 'Pendente' },
  { valor: 'scheduled', rotulo: 'Agendada' },
  { valor: 'in_progress', rotulo: 'Em Andamento' },
  { valor: 'completed', rotulo: 'Concluída' },
  { valor: 'cancelled', rotulo: 'Cancelada' },
  { valor: 'on_hold', rotulo: 'Em Espera' },
];

const STATUS_LABELS = Object.fromEntries(OPCOES_STATUS.map((opcao) => [opcao.valor, opcao.rotulo]));

// --- Estado de visão, período e filtros (a mesma tríade que vive na URL) ------------
const visao = ref('mes');
const dataReferencia = ref(hojeISO());
const filtros = reactive({
  technician_id: '',
  service_id: '',
  status: [],
  cidade: '',
});

// Enquanto falso, mudanças em visao/dataReferencia/filtros não disparam busca:
// evita fetch duplicado durante a leitura inicial do estado a partir da URL, que
// muda vários campos de uma vez.
const estadoInicializado = ref(false);

const visitas = ref([]);
const carregando = ref(false);
const erroCarregamento = ref(null);
const mensagemAviso = ref(null);

const situacaoAberta = ref(false);
const situacaoFiltroEl = ref(null);

let temporizadorCidade = null;

function calcularPeriodo(visaoAtual, dataRef) {
  if (visaoAtual === 'mes') {
    const partes = partesDeIso(dataRef);
    const grade = gradeDoMes(partes.ano, partes.mes);

    return { inicio: grade[0][0].data, fim: grade[grade.length - 1][6].data };
  }

  if (visaoAtual === 'semana') {
    const dias = diasDaSemana(dataRef);

    return { inicio: dias[0].data, fim: dias[6].data };
  }

  return { inicio: dataRef, fim: dataRef };
}

const periodoAtual = computed(() => calcularPeriodo(visao.value, dataReferencia.value));

const temFiltroAtivo = computed(
  () => Boolean(filtros.technician_id) || Boolean(filtros.service_id) || filtros.status.length > 0 || Boolean(filtros.cidade.trim())
);

const filtrosAtivosTexto = computed(() => {
  const partes = [];

  if (filtros.technician_id === 'sem_tecnico') {
    partes.push('técnico: sem técnico');
  } else if (filtros.technician_id) {
    const tecnico = props.tecnicos.find((item) => String(item.id) === String(filtros.technician_id));
    partes.push(`técnico: ${tecnico?.name || filtros.technician_id}`);
  }

  if (filtros.service_id) {
    const servico = props.servicos.find((item) => String(item.id) === String(filtros.service_id));
    partes.push(`serviço: ${servico?.name || filtros.service_id}`);
  }

  if (filtros.status.length > 0) {
    partes.push(`situação: ${filtros.status.map((valor) => STATUS_LABELS[valor] || valor).join(', ')}`);
  }

  if (filtros.cidade.trim()) {
    partes.push(`cidade: ${filtros.cidade.trim()}`);
  }

  return partes;
});

const situacaoRotulo = computed(() => {
  if (filtros.status.length === 0) return 'Todas as situações';
  if (filtros.status.length === 1) return STATUS_LABELS[filtros.status[0]] || filtros.status[0];

  return `${filtros.status.length} situações selecionadas`;
});

function selecionarVisao(novaVisao) {
  if (novaVisao === visao.value) return;

  visao.value = novaVisao;
  disparar(buscarDados);
}

function aoMudarPeriodo(evento) {
  dataReferencia.value = evento.dataReferencia;
  disparar(buscarDados);
}

// Dispara `buscarDados` só quando o estado inicial já foi lido: durante a leitura
// da URL várias mudanças acontecem de uma vez, e cada uma delas chamaria isto sem
// a guarda, gerando fetches redundantes.
function disparar(acao) {
  if (!estadoInicializado.value) return;

  acao();
}

// --- Busca dos dados do período (endpoint JSON, não navegação Inertia) -------------
function construirParametrosDeDados() {
  const params = new URLSearchParams();
  params.set('inicio', periodoAtual.value.inicio);
  params.set('fim', periodoAtual.value.fim);

  if (filtros.technician_id) params.set('technician_id', filtros.technician_id);
  if (filtros.service_id) params.set('service_id', filtros.service_id);
  filtros.status.forEach((situacao) => params.append('status[]', situacao));
  if (filtros.cidade.trim()) params.set('cidade', filtros.cidade.trim());

  return params;
}

async function buscarDados() {
  carregando.value = true;
  erroCarregamento.value = null;

  try {
    const resposta = await fetch(`/agenda/dados?${construirParametrosDeDados().toString()}`, {
      headers: { Accept: 'application/json' },
    });

    if (!resposta.ok) {
      throw new Error('Falha ao carregar a agenda.');
    }

    const dados = await resposta.json();
    visitas.value = dados.ordens || [];
  } catch (erro) {
    erroCarregamento.value = 'Não foi possível carregar a agenda. Tente novamente.';
  } finally {
    carregando.value = false;
  }

  atualizarUrl();
}

// --- URL compartilhável: visão, período (via data de referência) e filtros --------
function atualizarUrl() {
  const params = new URLSearchParams();
  params.set('visao', visao.value);
  params.set('data', dataReferencia.value);

  if (filtros.technician_id) params.set('technician_id', filtros.technician_id);
  if (filtros.service_id) params.set('service_id', filtros.service_id);
  filtros.status.forEach((situacao) => params.append('status[]', situacao));
  if (filtros.cidade.trim()) params.set('cidade', filtros.cidade.trim());

  const novaUrl = `${window.location.pathname}?${params.toString()}`;
  window.history.replaceState(null, '', novaUrl);
}

function lerEstadoDaUrl() {
  const params = new URLSearchParams(window.location.search);

  const visaoDaUrl = params.get('visao');
  if (['dia', 'semana', 'mes'].includes(visaoDaUrl)) {
    visao.value = visaoDaUrl;
  }

  const dataDaUrl = params.get('data');
  if (dataDaUrl && /^\d{4}-\d{2}-\d{2}$/.test(dataDaUrl)) {
    dataReferencia.value = dataDaUrl;
  }

  filtros.technician_id = params.get('technician_id') || '';
  filtros.service_id = params.get('service_id') || '';
  filtros.status = params.getAll('status[]');
  filtros.cidade = params.get('cidade') || '';
}

// Aplica uma mudança de estado (leitura da URL, limpar filtros) sem deixar os
// observadores individuais de cada campo disparar uma busca cada um: a guarda
// desliga, a mudança acontece inteira, busca uma vez, guarda liga de novo.
async function aplicarComUmaBusca(mutador) {
  estadoInicializado.value = false;
  mutador();
  await buscarDados();
  estadoInicializado.value = true;
}

function limparFiltros() {
  aplicarComUmaBusca(() => {
    filtros.technician_id = '';
    filtros.service_id = '';
    filtros.status = [];
    filtros.cidade = '';
  });
}

function aoVoltarNoNavegador() {
  aplicarComUmaBusca(lerEstadoDaUrl);
}

function aoClicarFora(evento) {
  if (situacaoFiltroEl.value && !situacaoFiltroEl.value.contains(evento.target)) {
    situacaoAberta.value = false;
  }
}

function aoMudarFiltroSelect() {
  disparar(buscarDados);
}

// Clique numa linha do painel de carga aplica o mesmo filtro de técnico da
// barra de filtros (mesmo formato: id como string, ou "sem_tecnico").
function aoSelecionarTecnicoDaCarga(technicianId) {
  filtros.technician_id = technicianId;
  disparar(buscarDados);
}

function aoDigitarCidade() {
  clearTimeout(temporizadorCidade);
  temporizadorCidade = setTimeout(() => disparar(buscarDados), 400);
}

onMounted(() => {
  aplicarComUmaBusca(lerEstadoDaUrl);
  document.addEventListener('click', aoClicarFora);
  window.addEventListener('popstate', aoVoltarNoNavegador);
});

onUnmounted(() => {
  document.removeEventListener('click', aoClicarFora);
  window.removeEventListener('popstate', aoVoltarNoNavegador);
  clearTimeout(temporizadorCidade);
});

// --- Painel lateral da visita selecionada -------------------------------------------
const visitaSelecionada = ref(null);
const tecnicosDisponiveis = ref([]);
const carregandoTecnicos = ref(false);

const reagendarForm = reactive({ scheduled_date: '', hora_inicio: '', hora_fim: '' });
const reagendarErros = reactive({});
const reagendarErroGeral = ref(null);
const reagendando = ref(false);

const atribuindoTecnico = ref(false);
const atribuirErro = ref(null);

// Incrementado a cada reagendamento/atribuição bem-sucedidos, para o painel
// de carga por técnico refazer a busca sem esperar a próxima navegação de
// período (ver watch em CargaPorTecnico.vue).
const versaoCarga = ref(0);

// Compromisso avulso (Plano 30, Task 30.5): decide o que o painel lateral mostra
// (dados de OS ou dados de compromisso) e quais ações fazem sentido.
const ehCompromissoSelecionado = computed(() => visitaSelecionada.value?.tipo_item === 'compromisso');

const horarioDaVisitaSelecionada = computed(() => {
  const inicio = visitaSelecionada.value?.hora_inicio;
  const fim = visitaSelecionada.value?.hora_fim;

  if (inicio && fim) return `${inicio} - ${fim}`;
  if (inicio) return `A partir das ${inicio}`;

  return 'Sem horário definido';
});

// A visita já tem um técnico atribuído que pode não constar em `tecnicosDisponiveis`
// (ex.: técnico inativo). Sem isto o select mostraria "Sem técnico" mesmo com a
// ordem de serviço atribuída.
const opcoesTecnico = computed(() => {
  const atual = visitaSelecionada.value?.tecnico;

  if (atual && !tecnicosDisponiveis.value.some((tecnico) => tecnico.id === atual.id)) {
    return [{ id: atual.id, nome: atual.nome, tem_aviso: false }, ...tecnicosDisponiveis.value];
  }

  return tecnicosDisponiveis.value;
});

function preencherFormularioDeReagendamento(visita) {
  reagendarForm.scheduled_date = visita?.data || '';
  reagendarForm.hora_inicio = visita?.hora_inicio || '';
  reagendarForm.hora_fim = visita?.hora_fim || '';
}

function abrirPainel(visita) {
  visitaSelecionada.value = visita;
  acaoCompromissoErro.value = null;
  servicoParaPromocao.value = null;

  // Compromisso não tem reagendamento nem atribuição de técnico pelo painel
  // nesta task (só Concluir/Cancelar/Promover): pular a busca de técnicos
  // disponíveis e o preenchimento do formulário de OS evita uma chamada de
  // rede que o compromisso nunca vai usar.
  if (visita.tipo_item === 'compromisso') {
    tecnicosDisponiveis.value = [];

    return;
  }

  atribuirErro.value = null;
  reagendarErroGeral.value = null;
  Object.keys(reagendarErros).forEach((chave) => delete reagendarErros[chave]);
  preencherFormularioDeReagendamento(visita);
  buscarTecnicosDisponiveis(visita);
}

function fecharPainel() {
  visitaSelecionada.value = null;
  tecnicosDisponiveis.value = [];
}

async function buscarTecnicosDisponiveis(visita) {
  if (!visita?.data) {
    tecnicosDisponiveis.value = [];

    return;
  }

  carregandoTecnicos.value = true;

  try {
    const params = new URLSearchParams({ data: visita.data });
    if (visita.hora_inicio) params.set('inicio', visita.hora_inicio);
    if (visita.hora_fim) params.set('fim', visita.hora_fim);

    const resposta = await fetch(`/agenda/tecnicos-disponiveis?${params.toString()}`, {
      headers: { Accept: 'application/json' },
    });

    if (!resposta.ok) {
      throw new Error('Falha ao buscar técnicos disponíveis.');
    }

    const dados = await resposta.json();
    tecnicosDisponiveis.value = dados.tecnicos || [];
  } catch (erro) {
    tecnicosDisponiveis.value = [];
  } finally {
    carregandoTecnicos.value = false;
  }
}

function primeiraMensagemDeErro(dados) {
  const primeiraChave = dados?.errors ? Object.keys(dados.errors)[0] : null;

  return primeiraChave ? dados.errors[primeiraChave][0] : dados?.message || null;
}

// Atualiza a visita no array local com a resposta do backend, sem refazer a busca
// do período inteiro. Uma visita reagendada para fora do período visível some da
// lista; sem isso ficaria presa no dia antigo até a próxima navegação.
function atualizarVisitaLocal(ordemAtualizada) {
  if (!ordemAtualizada) return;

  const dentroDoPeriodo =
    ordemAtualizada.data >= periodoAtual.value.inicio && ordemAtualizada.data <= periodoAtual.value.fim;
  const indice = visitas.value.findIndex((visita) => visita.id === ordemAtualizada.id);

  if (!dentroDoPeriodo) {
    if (indice !== -1) visitas.value.splice(indice, 1);
  } else if (indice !== -1) {
    visitas.value.splice(indice, 1, ordemAtualizada);
  } else {
    visitas.value.push(ordemAtualizada);
  }

  if (visitaSelecionada.value?.id === ordemAtualizada.id) {
    visitaSelecionada.value = ordemAtualizada;
    preencherFormularioDeReagendamento(ordemAtualizada);
  }
}

function aplicarSucesso(dados) {
  atualizarVisitaLocal(dados.ordem);
  mensagemAviso.value = dados.tem_aviso ? dados.mensagem_aviso : null;
  versaoCarga.value += 1;

  // A disponibilidade dos técnicos mudou com a própria atribuição/reagendamento.
  if (visitaSelecionada.value) {
    buscarTecnicosDisponiveis(visitaSelecionada.value);
  }
}

async function atribuirTecnico(valorSelecionado) {
  if (!visitaSelecionada.value) return;

  atribuindoTecnico.value = true;
  atribuirErro.value = null;

  const technicianId = valorSelecionado === '' ? null : Number(valorSelecionado);

  try {
    const resposta = await fetch(`/agenda/${visitaSelecionada.value.id}/tecnico`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': tokenCsrf(),
        Accept: 'application/json',
      },
      body: JSON.stringify({ technician_id: technicianId }),
    });

    const dados = await resposta.json();

    if (!resposta.ok) {
      atribuirErro.value = dados.conflito ? dados.message : primeiraMensagemDeErro(dados) || 'Não foi possível atribuir o técnico.';

      return;
    }

    aplicarSucesso(dados);
  } catch (erro) {
    atribuirErro.value = 'Não foi possível atribuir o técnico. Tente novamente.';
  } finally {
    atribuindoTecnico.value = false;
  }
}

async function enviarReagendamento() {
  if (!visitaSelecionada.value) return;

  reagendando.value = true;
  reagendarErroGeral.value = null;
  Object.keys(reagendarErros).forEach((chave) => delete reagendarErros[chave]);

  const corpo = {
    scheduled_date: reagendarForm.scheduled_date,
    start_time: reagendarForm.hora_inicio
      ? inputDateTimeParaUtc(`${reagendarForm.scheduled_date}T${reagendarForm.hora_inicio}`)
      : null,
    end_time: reagendarForm.hora_fim
      ? inputDateTimeParaUtc(`${reagendarForm.scheduled_date}T${reagendarForm.hora_fim}`)
      : null,
  };

  try {
    const { ok, dados } = await reagendarOrdem(visitaSelecionada.value.id, corpo);

    if (!ok) {
      if (dados.conflito) {
        reagendarErroGeral.value = dados.message;
      } else if (dados.errors) {
        Object.assign(reagendarErros, dados.errors);
        reagendarErroGeral.value = dados.message || 'Verifique os campos do reagendamento.';
      } else {
        reagendarErroGeral.value = dados.message || 'Não foi possível reagendar.';
      }

      return;
    }

    aplicarSucesso(dados);
  } catch (erro) {
    reagendarErroGeral.value = 'Não foi possível reagendar. Tente novamente.';
  } finally {
    reagendando.value = false;
  }
}

// --- Compromisso avulso (Plano 30, Task 30.5) ---------------------------------------
//
// `POST /compromissos`, `.../concluir`, `.../cancelar` e `.../promover-os`
// devolvem `{ mensagem, compromisso }` em JSON puro (ver o cabeçalho de
// `CompromissoController`), no formato bruto do model Eloquent, NÃO no formato
// mesclado que `/agenda/dados` devolve (cliente como objeto, `situacao_texto`,
// `tipo_item`...). Tentar encaixar essa resposta direto no array `visitas`
// quebraria o cartão (viraria uma OS "fantasma" sem `tipo_item`, sem
// `situacao_texto` etc.). Por isso toda ação de compromisso, com sucesso,
// fecha o painel e recarrega o período inteiro pela mesma `buscarDados()` que
// a Agenda já usa ao trocar de período, mesmo padrão pedido pela Task 30.5
// para a criação ("fecha e recarrega o período da Agenda no sucesso").
const mostrarModalNovoCompromisso = ref(false);
const mensagemSucessoCompromisso = ref(null);
const processandoAcaoCompromisso = ref(false);
const acaoCompromissoErro = ref(null);
const servicoParaPromocao = ref(null);
const mostrarConfirmacaoDeCancelamento = ref(false);

// `work_order_id` vem no item de `/agenda/dados` (`AgendaService::formatarCompromisso()`),
// preenchido só depois de uma promoção bem-sucedida. Como toda ação de
// compromisso recarrega o período pela mesma `buscarDados()`, o painel
// sempre reflete o estado real do backend, mesmo depois de reabrir a tela.
const compromissoJaPromovido = computed(() => Boolean(visitaSelecionada.value?.work_order_id));

function aoCriarCompromisso() {
  mostrarModalNovoCompromisso.value = false;
  mensagemSucessoCompromisso.value = 'Compromisso criado.';
  buscarDados();
}

async function concluirCompromisso() {
  if (!visitaSelecionada.value) return;

  processandoAcaoCompromisso.value = true;
  acaoCompromissoErro.value = null;

  try {
    const resposta = await fetch(route('compromissos.concluir', visitaSelecionada.value.id), {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': tokenCsrf(), Accept: 'application/json' },
    });

    const dados = await resposta.json();

    if (!resposta.ok) {
      acaoCompromissoErro.value = primeiraMensagemDeErro(dados) || 'Não foi possível concluir o compromisso.';

      return;
    }

    mensagemSucessoCompromisso.value = dados.mensagem;
    fecharPainel();
    await buscarDados();
  } catch (erro) {
    acaoCompromissoErro.value = 'Não foi possível concluir o compromisso. Tente novamente.';
  } finally {
    processandoAcaoCompromisso.value = false;
  }
}

function pedirCancelamentoDeCompromisso() {
  acaoCompromissoErro.value = null;
  mostrarConfirmacaoDeCancelamento.value = true;
}

async function confirmarCancelamentoDeCompromisso() {
  if (!visitaSelecionada.value) return;

  processandoAcaoCompromisso.value = true;
  acaoCompromissoErro.value = null;

  try {
    const resposta = await fetch(route('compromissos.cancelar', visitaSelecionada.value.id), {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': tokenCsrf(), Accept: 'application/json' },
    });

    const dados = await resposta.json();
    mostrarConfirmacaoDeCancelamento.value = false;

    if (!resposta.ok) {
      acaoCompromissoErro.value = primeiraMensagemDeErro(dados) || 'Não foi possível cancelar o compromisso.';

      return;
    }

    mensagemSucessoCompromisso.value = dados.mensagem;
    fecharPainel();
    await buscarDados();
  } catch (erro) {
    mostrarConfirmacaoDeCancelamento.value = false;
    acaoCompromissoErro.value = 'Não foi possível cancelar o compromisso. Tente novamente.';
  } finally {
    processandoAcaoCompromisso.value = false;
  }
}

async function promoverCompromisso() {
  if (!visitaSelecionada.value || compromissoJaPromovido.value) return;

  processandoAcaoCompromisso.value = true;
  acaoCompromissoErro.value = null;

  try {
    const resposta = await fetch(route('compromissos.promover-os', visitaSelecionada.value.id), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': tokenCsrf(),
        Accept: 'application/json',
      },
      body: JSON.stringify({ service_id: servicoParaPromocao.value || null }),
    });

    const dados = await resposta.json();

    if (!resposta.ok) {
      acaoCompromissoErro.value = primeiraMensagemDeErro(dados) || 'Não foi possível promover o compromisso.';

      return;
    }

    mensagemSucessoCompromisso.value = dados.mensagem;
    fecharPainel();
    await buscarDados();
  } catch (erro) {
    acaoCompromissoErro.value = 'Não foi possível promover o compromisso. Tente novamente.';
  } finally {
    processandoAcaoCompromisso.value = false;
  }
}
</script>
