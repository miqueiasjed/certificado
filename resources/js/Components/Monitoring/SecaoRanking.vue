<template>
  <Card>
    <h3 class="text-lg font-medium text-gray-900">Ranking dos pontos críticos</h3>
    <p class="mt-1 text-sm text-gray-500">
      Ordenado pela média de capturas por visita (não pelo total bruto): normaliza pela frequência de
      visita de cada dispositivo, para não confundir "visitado com frequência" com "infestado".
      <span v-if="ranking?.periodo_anterior">
        Tendência contra {{ formatarData(ranking.periodo_anterior.de) }} a {{ formatarData(ranking.periodo_anterior.ate) }}.
      </span>
    </p>

    <div v-if="!dispositivos.length" class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-500">
      Nenhum dispositivo com visita registrada neste endereço no período.
    </div>

    <div v-else class="mt-4 overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead>
          <tr>
            <th class="px-3 py-2 text-left font-medium text-gray-500">Dispositivo</th>
            <th class="px-3 py-2 text-left font-medium text-gray-500">Código</th>
            <th class="px-3 py-2 text-center font-medium text-gray-500">Visitas</th>
            <th class="px-3 py-2 text-center font-medium text-gray-500">Capturas</th>
            <th class="px-3 py-2 text-left font-medium text-gray-500">Média por visita</th>
            <th class="px-3 py-2 text-left font-medium text-gray-500">Tendência</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="dispositivo in dispositivos" :key="dispositivo.device_id">
            <td class="px-3 py-2 text-gray-900">{{ dispositivo.rotulo }}</td>
            <td class="px-3 py-2 text-gray-500">{{ dispositivo.codigo_publico || '-' }}</td>
            <td class="px-3 py-2 text-center text-gray-900">{{ dispositivo.visitas }}</td>
            <td class="px-3 py-2 text-center font-medium text-gray-900">{{ dispositivo.capturas_total }}</td>
            <td class="px-3 py-2">
              <div class="flex items-center gap-2">
                <div class="h-2 w-20 overflow-hidden rounded-full bg-gray-100">
                  <div
                    class="h-full rounded-full"
                    :style="{ width: `${larguraBarra(dispositivo.media_por_visita)}%`, backgroundColor: CORES_MONITORAMENTO.captura }">
                  </div>
                </div>
                <span class="text-gray-900">{{ formatarMedia(dispositivo.media_por_visita) }}</span>
              </div>
            </td>
            <td class="px-3 py-2">
              <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-medium" :class="classeTendencia(dispositivo.tendencia.estado)">
                {{ rotuloTendencia(dispositivo.tendencia.estado) }}
                <template v-if="dispositivo.tendencia.percentual !== null">
                  ({{ dispositivo.tendencia.percentual >= 0 ? '+' : '' }}{{ formatarPercentual(dispositivo.tendencia.percentual) }}%)
                </template>
              </span>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </Card>
</template>

<script setup>
/**
 * Ranking de pontos críticos de um único endereço
 * (`ranking_pontos_criticos[n]`, exatamente o que
 * `PontosCriticosService::ranking()` devolve). A barra ao lado da média por
 * visita é só reforço visual - o número exato sempre acompanha, nunca
 * substituído pela barra (regra "número absoluto sempre ao lado do
 * gráfico").
 */
import { computed } from 'vue';
import Card from '@/Components/Card.vue';
import { formatarData } from '@/utils/formatDate';
import { CORES_MONITORAMENTO, rotuloTendencia, classeTendencia } from '@/utils/paletaMonitoramento';

const props = defineProps({
  ranking: { type: Object, default: null },
});

const dispositivos = computed(() => props.ranking?.dispositivos ?? []);

const maiorMedia = computed(() => {
  const valores = dispositivos.value.map((d) => d.media_por_visita);
  return valores.length > 0 ? Math.max(...valores, 0.0001) : 0.0001;
});

function larguraBarra(media) {
  return Math.max(4, Math.round((media / maiorMedia.value) * 100));
}

function formatarMedia(valor) {
  return Number(valor).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function formatarPercentual(valor) {
  return Number(valor).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
}
</script>
