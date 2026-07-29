<template>
  <Head :title="`Pedir horário - ${empresa?.nome || ''}`" />

  <PublicoLayout :empresa="empresa">
    <!-- Confirmação de recebimento: substitui o formulário inteiro, na mesma
         tela, sem redirecionar para outra página. O texto vem pronto do
         backend (AgendamentoPublicoController::MENSAGEM_DE_RECEBIMENTO) e já
         deixa explícito que o pedido não é um agendamento confirmado. -->
    <div v-if="pedidoRecebido" class="rounded-lg border border-gray-200 bg-white p-6 text-center shadow-sm sm:p-8">
      <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-green-100">
        <svg class="h-7 w-7 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
        </svg>
      </div>
      <h1 class="mt-4 text-lg font-semibold text-gray-900">Pedido recebido</h1>
      <p class="mt-2 text-sm text-gray-600">{{ mensagemDeRecebimento }}</p>
    </div>

    <template v-else>
      <div class="mb-6">
        <h1 class="text-xl font-semibold text-gray-900">Pedir horário</h1>
        <p class="mt-1 text-sm text-gray-600">
          Escolha um dia e um período disponível. Isto é um pedido: a empresa confirma por telefone ou e-mail.
        </p>
      </div>

      <div v-if="form.errors.limite" class="mb-6 rounded-md border border-red-200 bg-red-50 p-4">
        <p class="text-sm font-medium text-red-800">{{ form.errors.limite }}</p>
      </div>
      <div v-else-if="form.errors.carregado_em" class="mb-6 rounded-md border border-red-200 bg-red-50 p-4">
        <p class="text-sm font-medium text-red-800">{{ form.errors.carregado_em }}</p>
      </div>

      <form class="space-y-6" @submit.prevent="enviar">
        <!-- Calendário e período -->
        <Card padding="none">
          <div class="border-b border-gray-200 px-4 py-3 sm:px-6">
            <h2 class="text-base font-medium text-gray-900">Dia e período</h2>
          </div>

          <div class="p-4 sm:p-6">
            <div class="flex items-center justify-between">
              <button
                type="button"
                class="flex h-9 w-9 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-30 disabled:hover:bg-transparent"
                :disabled="carregandoGrade || !mesAnteriorPermitido"
                @click="irParaMes(-1)">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
              </button>

              <p class="text-sm font-medium capitalize text-gray-900">{{ rotuloDoMes }}</p>

              <button
                type="button"
                class="flex h-9 w-9 items-center justify-center rounded-full text-gray-500 hover:bg-gray-100 disabled:cursor-not-allowed disabled:opacity-30"
                :disabled="carregandoGrade"
                @click="irParaMes(1)">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
              </button>
            </div>

            <div class="mt-4 grid grid-cols-7 gap-1 text-center text-xs font-medium text-gray-500">
              <span v-for="nomeDoDia in NOMES_DIAS_SEMANA_ABREVIADO" :key="nomeDoDia">{{ nomeDoDia }}</span>
            </div>

            <div class="mt-1 grid grid-cols-7 gap-1" :class="{ 'opacity-50': carregandoGrade }">
              <template v-for="semana in semanasDoMes" :key="semana[0].data">
                <button
                  v-for="dia in semana"
                  :key="dia.data"
                  type="button"
                  class="flex h-10 w-10 items-center justify-center rounded-full text-sm transition-colors sm:h-11 sm:w-11"
                  :class="classeDoDia(dia)"
                  :style="estiloDoDia(dia)"
                  :disabled="!diaEhSelecionavel(dia)"
                  @click="selecionarDia(dia)">
                  {{ dia.dia }}
                </button>
              </template>
            </div>

            <p v-if="erroDaGrade" class="mt-3 text-sm text-red-600">
              Não foi possível carregar o calendário deste mês. Tente novamente.
            </p>
            <p v-if="form.errors.data_preferida" class="mt-3 text-sm text-red-600">
              {{ form.errors.data_preferida }}
            </p>

            <div class="mt-5">
              <p class="mb-2 block text-sm font-medium text-gray-700">Período *</p>
              <div class="grid grid-cols-2 gap-3">
                <button
                  v-for="opcao in periodos"
                  :key="opcao.valor"
                  type="button"
                  class="rounded-md border px-3 py-2 text-sm font-medium capitalize transition-colors disabled:cursor-not-allowed disabled:opacity-40"
                  :class="form.periodo === opcao.valor
                    ? 'border-transparent text-white'
                    : 'border-gray-300 text-gray-700 hover:bg-gray-50'"
                  :style="form.periodo === opcao.valor ? { backgroundColor: 'var(--publico-cor-primaria)' } : undefined"
                  :disabled="!periodoDisponivel(opcao.valor)"
                  @click="form.periodo = opcao.valor">
                  {{ opcao.rotulo }}
                </button>
              </div>
              <p v-if="!form.data_preferida" class="mt-2 text-xs text-gray-500">Escolha um dia no calendário primeiro.</p>
              <p v-if="form.errors.periodo" class="mt-1 text-sm text-red-600">{{ form.errors.periodo }}</p>
            </div>
          </div>
        </Card>

        <!-- Tipo de serviço e dados de contato -->
        <Card padding="none">
          <div class="border-b border-gray-200 px-4 py-3 sm:px-6">
            <h2 class="text-base font-medium text-gray-900">Seus dados</h2>
          </div>

          <div class="space-y-4 p-4 sm:p-6">
            <div v-if="tipos_de_servico.length > 0">
              <label class="mb-1 block text-sm font-medium text-gray-700">Tipo de serviço</label>
              <select
                v-model="form.service_type_id"
                class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
                :class="{ 'border-red-500': form.errors.service_type_id }">
                <option value="">Sem preferência</option>
                <option v-for="tipo in tipos_de_servico" :key="tipo.id" :value="tipo.id">{{ tipo.nome }}</option>
              </select>
              <p v-if="form.errors.service_type_id" class="mt-1 text-sm text-red-600">{{ form.errors.service_type_id }}</p>
            </div>

            <div>
              <label class="mb-1 block text-sm font-medium text-gray-700">Nome *</label>
              <input
                v-model="form.nome"
                type="text"
                autocomplete="name"
                class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
                :class="{ 'border-red-500': form.errors.nome }">
              <p v-if="form.errors.nome" class="mt-1 text-sm text-red-600">{{ form.errors.nome }}</p>
            </div>

            <div>
              <label class="mb-1 block text-sm font-medium text-gray-700">E-mail *</label>
              <input
                v-model="form.email"
                type="email"
                autocomplete="email"
                class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
                :class="{ 'border-red-500': form.errors.email }">
              <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
            </div>

            <div>
              <label class="mb-1 block text-sm font-medium text-gray-700">Telefone *</label>
              <input
                v-model="form.telefone"
                type="tel"
                autocomplete="tel"
                placeholder="(11) 99999-9999"
                class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
                :class="{ 'border-red-500': form.errors.telefone }">
              <p v-if="form.errors.telefone" class="mt-1 text-sm text-red-600">{{ form.errors.telefone }}</p>
            </div>

            <div>
              <label class="mb-1 block text-sm font-medium text-gray-700">Endereço do atendimento *</label>
              <textarea
                v-model="form.endereco_texto"
                rows="2"
                placeholder="Rua, número, bairro e cidade"
                class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
                :class="{ 'border-red-500': form.errors.endereco_texto }"></textarea>
              <p v-if="form.errors.endereco_texto" class="mt-1 text-sm text-red-600">{{ form.errors.endereco_texto }}</p>
            </div>

            <div>
              <label class="mb-1 block text-sm font-medium text-gray-700">Observação</label>
              <textarea
                v-model="form.observacao"
                rows="2"
                class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
                :class="{ 'border-red-500': form.errors.observacao }"></textarea>
              <p v-if="form.errors.observacao" class="mt-1 text-sm text-red-600">{{ form.errors.observacao }}</p>
            </div>

            <!-- Campo armadilha: invisível para uma pessoa, presente no DOM para
                 um robô de preenchimento automático encontrar. O nome vem do
                 backend (StoreAppointmentRequestRequest::CAMPO_ARMADILHA), nunca
                 fixo aqui. Preenchido, o pedido é descartado em silêncio, com a
                 mesma confirmação de um envio válido. -->
            <div class="absolute -left-[9999px] top-auto h-px w-px overflow-hidden" aria-hidden="true">
              <label :for="campoArmadilha">Site</label>
              <input
                :id="campoArmadilha"
                v-model="form[campoArmadilha]"
                type="text"
                tabindex="-1"
                autocomplete="off">
            </div>
          </div>
        </Card>

        <button
          type="submit"
          class="flex w-full justify-center rounded-md px-4 py-2.5 text-sm font-medium text-white shadow-sm transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
          style="background-color: var(--publico-cor-primaria)"
          :disabled="form.processing">
          <span v-if="form.processing">Enviando...</span>
          <span v-else>Enviar pedido de horário</span>
        </button>
        <p class="text-center text-xs text-gray-500">
          Este pedido não confirma o horário. A empresa entra em contato para combinar a visita.
        </p>
      </form>
    </template>
  </PublicoLayout>
</template>

<script setup>
// Página pública de pedido de horário (Plano 16, Task 16.6). Sem login, sem
// menu, primeira impressão de quem talvez nunca tenha ouvido falar da
// empresa. A confirmação do envio acontece na mesma tela (troca de
// `pedidoRecebido`), nunca em outra página, para o texto "isto ainda não é
// um agendamento" ficar exatamente onde a pessoa está olhando.
import { computed, ref } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import PublicoLayout from '@/Layouts/PublicoLayout.vue';
import Card from '@/Components/Card.vue';
import { NOMES_DIAS_SEMANA_ABREVIADO, gradeDoMes, nomeDoMes, somarMeses } from '@/utils/calendario';
import { hojeISO } from '@/utils/formatDate';

const props = defineProps({
  slug: { type: String, required: true },
  empresa: { type: Object, default: null },
  tipos_de_servico: { type: Array, default: () => [] },
  periodos: { type: Array, required: true },
  mes: { type: String, required: true },
  grade: { type: Array, default: () => [] },
  carregado_em: { type: String, required: true },
  campo_armadilha: { type: String, required: true },
});

const campoArmadilha = props.campo_armadilha;

const page = usePage();
const pedidoRecebido = computed(() => Boolean(page.props.flash?.success));
const mensagemDeRecebimento = computed(() => page.props.flash?.success ?? '');

// --- Formulário ------------------------------------------------------

const form = useForm({
  nome: '',
  email: '',
  telefone: '',
  endereco_texto: '',
  service_type_id: '',
  data_preferida: '',
  periodo: '',
  observacao: '',
  carregado_em: props.carregado_em,
  [campoArmadilha]: '',
});

form.transform((dados) => ({
  ...dados,
  service_type_id: dados.service_type_id === '' ? null : dados.service_type_id,
}));

function enviar() {
  form.post(route('publico.agendar.store', { slug: props.slug }), {
    preserveScroll: true,
  });
}

// --- Calendário --------------------------------------------------------

const mesAtual = ref(props.mes);
const gradeAtual = ref(props.grade);
const carregandoGrade = ref(false);
const erroDaGrade = ref(false);

const hoje = hojeISO();
const mesDeHoje = hoje.slice(0, 7);

const rotuloDoMes = computed(() => {
  const [ano, mes] = mesAtual.value.split('-').map(Number);
  return `${nomeDoMes(mes)} de ${ano}`;
});

const mesAnteriorPermitido = computed(() => mesAtual.value > mesDeHoje);

const gradePorData = computed(() => {
  const mapa = new Map();
  (gradeAtual.value || []).forEach((dia) => mapa.set(dia.data, dia));
  return mapa;
});

const semanasDoMes = computed(() => {
  const [ano, mes] = mesAtual.value.split('-').map(Number);
  return gradeDoMes(ano, mes);
});

function registroDoDia(data) {
  return gradePorData.value.get(data);
}

function diaDisponivel(data) {
  const registro = registroDoDia(data);
  return Boolean(registro && (registro.manha?.disponivel || registro.tarde?.disponivel));
}

// Dia indisponível fica desabilitado sem explicar o motivo: nenhuma diferença
// visual entre "sem técnico" e "fora da janela de antecedência".
function diaEhSelecionavel(dia) {
  return dia.mesAtual && diaDisponivel(dia.data);
}

function classeDoDia(dia) {
  if (!dia.mesAtual) {
    return 'text-gray-300 cursor-default';
  }

  if (!diaDisponivel(dia.data)) {
    return 'text-gray-300 cursor-not-allowed';
  }

  if (form.data_preferida === dia.data) {
    return 'text-white cursor-pointer';
  }

  const anel = dia.data === hoje ? 'ring-1 ring-inset ring-gray-300 ' : '';

  return `${anel}text-gray-700 hover:bg-gray-100 cursor-pointer`;
}

function estiloDoDia(dia) {
  return form.data_preferida === dia.data ? { backgroundColor: 'var(--publico-cor-primaria)' } : undefined;
}

function selecionarDia(dia) {
  if (!diaEhSelecionavel(dia)) {
    return;
  }

  form.data_preferida = dia.data;

  const registro = registroDoDia(dia.data);
  if (form.periodo && !registro?.[form.periodo]?.disponivel) {
    form.periodo = '';
  }
}

function periodoDisponivel(valor) {
  const registro = registroDoDia(form.data_preferida);
  return Boolean(registro?.[valor]?.disponivel);
}

async function irParaMes(deslocamento) {
  if (carregandoGrade.value) {
    return;
  }

  const novoMes = somarMeses(`${mesAtual.value}-01`, deslocamento).slice(0, 7);

  if (deslocamento < 0 && novoMes < mesDeHoje) {
    return;
  }

  carregandoGrade.value = true;
  erroDaGrade.value = false;

  try {
    const resposta = await fetch(
      `${route('publico.agendar.grade', { slug: props.slug })}?mes=${encodeURIComponent(novoMes)}`,
      { headers: { Accept: 'application/json' } }
    );

    if (!resposta.ok) {
      throw new Error('Falha ao carregar a grade do mês.');
    }

    const dados = await resposta.json();
    mesAtual.value = dados.mes;
    gradeAtual.value = dados.grade;
  } catch (erro) {
    erroDaGrade.value = true;
  } finally {
    carregandoGrade.value = false;
  }
}
</script>
