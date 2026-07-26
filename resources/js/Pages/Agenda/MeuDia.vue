<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Meu dia" description="Ordens de serviço agendadas para você" />
    </template>

    <div class="max-w-2xl mx-auto space-y-4">
      <!-- Navegação entre dias: grande o bastante para toque em celular -->
      <Card padding="small">
        <div class="flex items-center justify-between gap-2">
          <button
            type="button"
            class="flex items-center justify-center rounded-md p-2.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-green-500"
            aria-label="Dia anterior"
            @click="irParaDia(-1)"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
            </svg>
          </button>

          <div class="min-w-0 flex-1 text-center">
            <p class="truncate text-sm font-semibold text-gray-900">{{ dataExtensa }}</p>
            <button
              v-if="!ehHoje"
              type="button"
              class="mt-0.5 text-xs font-medium text-green-700 hover:text-green-800"
              @click="irParaHoje"
            >
              Voltar para hoje
            </button>
            <p v-else class="mt-0.5 text-xs text-gray-400">Hoje</p>
          </div>

          <button
            type="button"
            class="flex items-center justify-center rounded-md p-2.5 text-gray-500 hover:bg-gray-100 hover:text-gray-700 focus:outline-none focus:ring-2 focus:ring-green-500"
            aria-label="Próximo dia"
            @click="irParaDia(1)"
          >
            <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
            </svg>
          </button>
        </div>
      </Card>

      <div v-if="erro" class="rounded-md border border-red-200 bg-red-50 p-4">
        <p class="text-sm text-red-800">{{ erro }}</p>
      </div>

      <div v-if="carregando" class="flex justify-center py-10">
        <span class="inline-flex items-center gap-2 text-sm font-medium text-gray-600">
          <svg class="h-4 w-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          Carregando...
        </span>
      </div>

      <div
        v-else-if="visitasOrdenadas.length === 0"
        class="rounded-lg border border-gray-200 bg-white p-8 text-center"
      >
        <p class="text-sm font-semibold text-gray-700">Nenhuma ordem de serviço neste dia.</p>
      </div>

      <div v-else class="space-y-3">
        <div
          v-for="visita in visitasOrdenadas"
          :key="visita.id"
          class="rounded-lg border p-4 shadow-sm"
          :class="visita.hora_inicio ? 'border-gray-200 bg-white' : 'border-amber-200 bg-amber-50'"
        >
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <p class="text-sm font-semibold text-gray-900 truncate">
                {{ visita.numero }} · {{ visita.cliente?.nome || 'Cliente não informado' }}
              </p>
              <p class="mt-0.5 text-sm font-medium text-gray-700">{{ horarioDaVisita(visita) }}</p>
            </div>
            <span
              class="flex-shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border whitespace-nowrap"
              :class="corDaSituacao(visita.status).badge"
            >
              {{ visita.status_texto || corDaSituacao(visita.status).label }}
            </span>
          </div>

          <p v-if="visita.servico?.nome" class="mt-2 text-sm text-gray-600">{{ visita.servico.nome }}</p>

          <p v-if="enderecoCompleto(visita)" class="mt-1 text-sm text-gray-500">
            {{ enderecoCompleto(visita) }}
          </p>

          <div class="mt-3 grid grid-cols-2 gap-2">
            <Link :href="`/work-orders/${visita.id}`" class="btn-secondary-sm justify-center">
              Abrir OS
            </Link>
            <a
              v-if="enderecoCompleto(visita)"
              :href="linkDoMapa(visita)"
              target="_blank"
              rel="noopener noreferrer"
              class="btn-secondary-sm justify-center"
            >
              Ver no mapa
            </a>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import { somarDias } from '@/utils/calendario';
import { formatarDataExtensa, hojeISO } from '@/utils/formatDate';

const props = defineProps({
  dataInicial: {
    type: String,
    required: true,
  },
});

const dataAtual = ref(props.dataInicial);
const visitas = ref([]);
const carregando = ref(false);
const erro = ref(null);

const dataExtensa = computed(() => formatarDataExtensa(dataAtual.value) || dataAtual.value);
const ehHoje = computed(() => dataAtual.value === hojeISO());

// Ordem de horário, com quem não tem horário marcado num grupo à parte, ao
// final da lista: o técnico primeiro confere o que tem hora certa para não
// se atrasar, e só depois olha o que pode encaixar a qualquer momento do dia.
const visitasOrdenadas = computed(() => {
  const comHorario = visitas.value
    .filter((visita) => visita.hora_inicio)
    .sort((a, b) => a.hora_inicio.localeCompare(b.hora_inicio));

  const semHorario = visitas.value.filter((visita) => !visita.hora_inicio);

  return [...comHorario, ...semHorario];
});

function horarioDaVisita(visita) {
  if (visita.hora_inicio && visita.hora_fim) return `${visita.hora_inicio} - ${visita.hora_fim}`;
  if (visita.hora_inicio) return `A partir das ${visita.hora_inicio}`;

  return 'Sem horário definido';
}

function enderecoCompleto(visita) {
  return [visita.endereco, visita.cidade].filter(Boolean).join(' · ');
}

// Nenhum link de mapa pronto foi encontrado no resto do projeto: esta é a
// busca padrão do Google Maps por endereço em texto, sem depender de chave de
// API.
function linkDoMapa(visita) {
  return `https://www.google.com/maps/search/?api=1&query=${encodeURIComponent(enderecoCompleto(visita))}`;
}

const CORES_POR_SITUACAO = {
  pending: { label: 'Pendente', badge: 'bg-gray-100 text-gray-800 border-gray-300' },
  scheduled: { label: 'Agendada', badge: 'bg-blue-100 text-blue-800 border-blue-300' },
  in_progress: { label: 'Em andamento', badge: 'bg-yellow-100 text-yellow-800 border-yellow-300' },
  completed: { label: 'Concluída', badge: 'bg-green-100 text-green-800 border-green-300' },
  cancelled: { label: 'Cancelada', badge: 'bg-red-100 text-red-800 border-red-300' },
  on_hold: { label: 'Em espera', badge: 'bg-orange-100 text-orange-800 border-orange-300' },
};

const COR_PADRAO = { label: 'Situação desconhecida', badge: 'bg-gray-100 text-gray-800 border-gray-300' };

function corDaSituacao(status) {
  return CORES_POR_SITUACAO[status] ?? COR_PADRAO;
}

async function buscarVisitasDoDia() {
  carregando.value = true;
  erro.value = null;

  try {
    const params = new URLSearchParams({ inicio: dataAtual.value, fim: dataAtual.value });
    const resposta = await fetch(`/agenda/dados?${params.toString()}`, {
      headers: { Accept: 'application/json' },
    });

    if (!resposta.ok) {
      throw new Error('Falha ao carregar as ordens de serviço do dia.');
    }

    const dados = await resposta.json();
    visitas.value = dados.ordens || [];
  } catch (erroCapturado) {
    erro.value = 'Não foi possível carregar as ordens de serviço do dia. Tente novamente.';
    visitas.value = [];
  } finally {
    carregando.value = false;
  }
}

function irParaDia(deslocamento) {
  dataAtual.value = somarDias(dataAtual.value, deslocamento);
}

function irParaHoje() {
  dataAtual.value = hojeISO();
}

watch(dataAtual, buscarVisitasDoDia, { immediate: true });
</script>
