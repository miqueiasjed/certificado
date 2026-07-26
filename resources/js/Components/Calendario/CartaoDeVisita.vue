<template>
  <button
    type="button"
    class="w-full text-left transition-colors focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-1"
    :class="classesDoCartao"
    @click="$emit('selecionar', visita)"
  >
    <!-- Variante compacta: uma linha, usada na grade do mês e nas colunas da semana -->
    <template v-if="variante === 'compacta'">
      <span class="flex items-center gap-1 min-w-0">
        <svg
          v-if="semTecnico"
          class="w-3 h-3 text-red-600 flex-shrink-0"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
        </svg>
        <span v-if="visita.hora_inicio" class="font-semibold text-gray-700 flex-shrink-0">
          {{ visita.hora_inicio }}
        </span>
        <span class="truncate" :class="semTecnico ? 'text-red-800 font-semibold' : 'text-gray-800'">
          {{ nomeDoCliente }}
        </span>
      </span>
    </template>

    <!-- Variante detalhada: usada na visão de dia, com mais informação por visita -->
    <template v-else>
      <div class="flex items-start justify-between gap-2">
        <div class="min-w-0">
          <p class="text-sm font-semibold text-gray-900 truncate">
            {{ visita.numero }} · {{ nomeDoCliente }}
          </p>
          <p class="text-xs text-gray-600 mt-0.5">{{ horarioLabel }}</p>
          <p v-if="visita.endereco || visita.cidade" class="text-xs text-gray-500 mt-0.5 truncate">
            {{ [visita.endereco, visita.cidade].filter(Boolean).join(' · ') }}
          </p>
          <p v-if="visita.servico?.nome" class="text-xs text-gray-500 mt-0.5">{{ visita.servico.nome }}</p>
        </div>

        <span
          class="flex-shrink-0 inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-semibold border whitespace-nowrap"
          :class="statusInfo.badge"
        >
          {{ statusInfo.label }}
        </span>
      </div>

      <!-- Visita sem técnico precisa saltar aos olhos: ícone, texto em vermelho e o
           cartão inteiro já vem com fundo e borda vermelhos (ver classesDoCartao). -->
      <div class="mt-2 flex items-center gap-1.5" :class="semTecnico ? 'text-red-700' : 'text-gray-600'">
        <svg
          v-if="semTecnico"
          class="w-4 h-4 flex-shrink-0"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24"
        >
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
        </svg>
        <svg v-else class="w-4 h-4 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
        </svg>
        <span class="text-xs font-semibold">
          {{ semTecnico ? 'Sem técnico atribuído' : visita.tecnico.nome }}
        </span>
      </div>
    </template>
  </button>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  visita: {
    type: Object,
    required: true,
  },
  variante: {
    type: String,
    default: 'compacta',
    validator: (valor) => ['compacta', 'detalhada'].includes(valor),
  },
});

defineEmits(['selecionar']);

// Cor por situação da OS. As chaves seguem o campo `status` de WorkOrder
// (pending/scheduled/in_progress/completed/cancelled/on_hold); qualquer valor fora
// dessa lista cai no `CORES_PADRAO`, para uma situação nova no backend não quebrar o
// cartão. O rótulo em si prefere `status_texto`, que o AgendaService já manda pronto
// em português (WorkOrder::status_text); a chave `label` aqui é só o retrovisor para
// quando `status_texto` não vier (ex.: dado mockado usado durante o desenvolvimento).
const CORES_POR_SITUACAO = {
  pending: { label: 'Pendente', badge: 'bg-gray-100 text-gray-800 border-gray-300', borda: 'border-gray-400' },
  scheduled: { label: 'Agendada', badge: 'bg-blue-100 text-blue-800 border-blue-300', borda: 'border-blue-500' },
  in_progress: { label: 'Em andamento', badge: 'bg-yellow-100 text-yellow-800 border-yellow-300', borda: 'border-yellow-500' },
  completed: { label: 'Concluída', badge: 'bg-green-100 text-green-800 border-green-300', borda: 'border-green-500' },
  cancelled: { label: 'Cancelada', badge: 'bg-red-100 text-red-800 border-red-300', borda: 'border-red-500' },
  on_hold: { label: 'Em espera', badge: 'bg-orange-100 text-orange-800 border-orange-300', borda: 'border-orange-500' },
};

const CORES_PADRAO = { badge: 'bg-gray-100 text-gray-800 border-gray-300', borda: 'border-gray-400' };

const statusInfo = computed(() => {
  const cores = CORES_POR_SITUACAO[props.visita.status] ?? CORES_PADRAO;
  const label = props.visita.status_texto || cores.label || props.visita.status || 'Situação desconhecida';

  return { label, badge: cores.badge, borda: cores.borda };
});

// `cliente` chega como objeto `{ id, nome }` (ou null quando a OS perdeu o vínculo).
const nomeDoCliente = computed(() => props.visita.cliente?.nome || 'Cliente não informado');

// O backend manda o booleano pronto (`sem_tecnico`); a ausência de `tecnico` é o
// fallback caso esse campo não venha, para o cartão nunca deixar de destacar por
// causa de uma propriedade faltando.
const semTecnico = computed(() => props.visita.sem_tecnico === true || !props.visita.tecnico);

const horarioLabel = computed(() => {
  const inicio = props.visita.hora_inicio;
  const fim = props.visita.hora_fim;

  if (inicio && fim) {
    return `${inicio} - ${fim}`;
  }

  if (inicio) {
    return `A partir das ${inicio}`;
  }

  return 'Sem horário definido';
});

const classesDoCartao = computed(() => {
  if (props.variante === 'compacta') {
    const base = ['rounded', 'border-l-4', 'px-1.5', 'py-1', 'text-xs', 'leading-tight', 'truncate', statusInfo.value.borda];

    return semTecnico.value
      ? [...base, 'bg-red-50', 'hover:bg-red-100']
      : [...base, 'bg-white', 'hover:bg-gray-50'];
  }

  const base = ['rounded-lg', 'border', 'p-3', 'hover:shadow-md'];

  return semTecnico.value
    ? [...base, 'border-red-300', 'bg-red-50', 'ring-1', 'ring-red-300']
    : [...base, 'border-gray-200', 'bg-white'];
});
</script>
