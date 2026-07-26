<template>
  <Card padding="small">
    <button
      type="button"
      class="flex w-full items-center justify-between gap-2 text-left"
      @click="aberto = !aberto"
    >
      <div class="flex items-baseline gap-2">
        <h3 class="text-sm font-semibold text-gray-900">Carga por técnico</h3>
        <span v-if="totais" class="text-xs text-gray-500">
          {{ totais.total_os }} OS · {{ formatarHoras(totais.total_horas) }} no período
        </span>
      </div>
      <svg
        class="h-4 w-4 flex-shrink-0 text-gray-400 transition-transform"
        :class="{ 'rotate-180': aberto }"
        fill="none"
        stroke="currentColor"
        viewBox="0 0 24 24"
      >
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
      </svg>
    </button>

    <div v-if="aberto" class="mt-3 space-y-2">
      <p v-if="erro" class="text-sm text-red-600">{{ erro }}</p>
      <p v-else-if="carregando && linhas.length === 0" class="text-xs text-gray-400">Carregando carga...</p>
      <p v-else-if="linhas.length === 0" class="text-xs text-gray-400">Sem dados de carga para o período.</p>

      <button
        v-for="linha in linhas"
        :key="linha.technician_id ?? 'sem_tecnico'"
        type="button"
        class="w-full rounded-md border p-2 text-left transition-colors hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-1"
        :class="linha.sem_tecnico ? 'border-red-300 bg-red-50 hover:bg-red-100' : 'border-gray-200 bg-white'"
        @click="selecionar(linha)"
      >
        <div class="flex items-center justify-between gap-2">
          <span
            class="truncate text-sm font-medium"
            :class="linha.sem_tecnico ? 'text-red-800' : 'text-gray-900'"
          >
            {{ linha.nome }}
          </span>
          <span class="flex-shrink-0 text-xs text-gray-600">
            {{ linha.total_os }} OS · {{ formatarHoras(linha.total_horas) }}
          </span>
        </div>
        <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
          <div
            class="h-full rounded-full"
            :class="linha.sem_tecnico ? 'bg-red-400' : 'bg-green-500'"
            :style="{ width: `${barraLargura(linha)}%` }"
          ></div>
        </div>
      </button>
    </div>
  </Card>
</template>

<script setup>
import { ref, computed, watch } from 'vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  // { inicio: 'Y-m-d', fim: 'Y-m-d' }: o período visível da agenda.
  periodo: {
    type: Object,
    required: true,
  },
  // Incrementado pelo pai (Agenda/Index.vue) a cada reagendamento ou
  // atribuição de técnico bem-sucedida, para o painel refletir a mudança sem
  // esperar a próxima navegação de período.
  versao: {
    type: [Number, String],
    default: 0,
  },
});

const emit = defineEmits(['selecionar-tecnico']);

const aberto = ref(true);
const carregando = ref(false);
const erro = ref(null);
const linhas = ref([]);
const totais = ref(null);

// A linha "sem técnico" fecha o array que `cargaPorTecnico()` devolve; aqui
// ela vira a primeira, porque é o item mais urgente de uma agenda (OS sem
// responsável), independente da ordem que o backend mandou.
function ordenarComSemTecnicoNoTopo(tecnicos) {
  const semTecnico = tecnicos.filter((linha) => linha.sem_tecnico);
  const comTecnico = tecnicos.filter((linha) => !linha.sem_tecnico);

  return [...semTecnico, ...comTecnico];
}

async function buscarCarga() {
  if (!props.periodo?.inicio || !props.periodo?.fim) return;

  carregando.value = true;
  erro.value = null;

  try {
    const params = new URLSearchParams({ inicio: props.periodo.inicio, fim: props.periodo.fim });
    const resposta = await fetch(`/agenda/carga?${params.toString()}`, {
      headers: { Accept: 'application/json' },
    });

    if (!resposta.ok) {
      throw new Error('Falha ao carregar a carga por técnico.');
    }

    const dados = await resposta.json();
    linhas.value = ordenarComSemTecnicoNoTopo(dados.tecnicos || []);
    totais.value = dados.totais || null;
  } catch (erroCapturado) {
    erro.value = 'Não foi possível carregar a carga por técnico.';
    linhas.value = [];
    totais.value = null;
  } finally {
    carregando.value = false;
  }
}

watch(
  () => [props.periodo?.inicio, props.periodo?.fim, props.versao],
  () => buscarCarga(),
  { immediate: true }
);

// A barra compara o total de OS entre técnicos, não as horas: nem toda OS
// tem horário definido, e uma barra por horas ficaria zerada justo nos casos
// em que a agenda mais precisa mostrar quem está sobrecarregado.
const maiorTotalDeOs = computed(() => Math.max(1, ...linhas.value.map((linha) => linha.total_os || 0)));

function barraLargura(linha) {
  return Math.round(((linha.total_os || 0) / maiorTotalDeOs.value) * 100);
}

function formatarHoras(valor) {
  const horas = Number(valor || 0);

  return `${horas.toFixed(1).replace('.', ',')} h`;
}

function selecionar(linha) {
  const valorDoFiltro = linha.sem_tecnico ? 'sem_tecnico' : String(linha.technician_id);

  emit('selecionar-tecnico', valorDoFiltro);
}
</script>
