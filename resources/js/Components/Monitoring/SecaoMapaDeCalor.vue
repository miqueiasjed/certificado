<template>
  <Card>
    <h3 class="text-lg font-medium text-gray-900">Mapa de calor por posição</h3>

    <div v-if="!mapa || !mapa.suportado" class="mt-3 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
      {{ mapa?.motivo_nao_suportado || 'Mapa de calor não disponível para este endereço.' }}
    </div>

    <template v-else>
      <p class="mt-1 text-sm text-gray-500">
        Escala absoluta do período: o ponto mais escuro corresponde a {{ mapa.escala_absoluta_maxima }}
        {{ mapa.escala_absoluta_maxima === 1 ? 'captura' : 'capturas' }} no ponto de maior ocorrência
        deste endereço. A intensidade da cor é só um reforço visual - o número absoluto de cada ponto
        está sempre ao lado.
      </p>

      <div v-if="mapa.planta" class="relative mt-4 w-full overflow-hidden rounded-lg border border-gray-200 bg-gray-100" :style="estiloContainer">
        <img
          :src="mapa.planta.arquivo_url"
          :alt="`Planta - ${mapa.planta.nome}`"
          class="pointer-events-none absolute inset-0 h-full w-full select-none object-fill">
        <div
          v-for="ponto in mapa.pontos"
          :key="ponto.device_id"
          class="absolute flex -translate-x-1/2 -translate-y-1/2 items-center justify-center rounded-full border-2 border-white text-[10px] font-semibold text-white shadow"
          :style="estiloPonto(ponto)"
          :title="`${ponto.rotulo}: ${ponto.valor_absoluto} captura(s) (${intensidadePercentual(ponto)}% da escala)`">
          {{ ponto.valor_absoluto }}
        </div>
      </div>
      <div v-else class="mt-4 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-500">
        Este endereço ainda não tem planta com dispositivo posicionado.
      </div>

      <div class="mt-4 overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 text-sm">
          <thead>
            <tr>
              <th class="px-3 py-2 text-left font-medium text-gray-500">Ponto</th>
              <th class="px-3 py-2 text-center font-medium text-gray-500">Valor absoluto</th>
              <th class="px-3 py-2 text-center font-medium text-gray-500">Intensidade normalizada</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-gray-100">
            <tr v-for="ponto in pontosOrdenados" :key="ponto.device_id">
              <td class="px-3 py-2 text-gray-900">{{ ponto.rotulo }}</td>
              <td class="px-3 py-2 text-center font-medium text-gray-900">{{ ponto.valor_absoluto }}</td>
              <td class="px-3 py-2 text-center text-gray-600">{{ intensidadePercentual(ponto) }}%</td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </Card>
</template>

<script setup>
/**
 * Mapa de calor por posição de um único endereço (`mapa_de_calor[n]`,
 * exatamente o que `MapaDeCalorService::porPosicao()` devolve). `porComodo`
 * nunca é usado aqui: sempre `suportado: false` por limitação estrutural do
 * schema (ver o cabeçalho da classe no backend), e `ConsolidadorDePeriodo`
 * nem chega a chamá-lo.
 *
 * `suportado: false` tem dois motivos possíveis, os dois já vêm prontos em
 * `motivo_nao_suportado` (endereço sem planta ativa, ou planta sem nenhum
 * dispositivo posicionado) - o componente só exibe o texto que o service já
 * escreveu, nunca inventa um motivo próprio.
 *
 * Cor é só reforço, número é a fonte: cada ponto imprime o `valor_absoluto`
 * dentro do próprio marcador (não escondido atrás de só intensidade), e a
 * tabela abaixo repete os dois números (`valor_absoluto` e a intensidade em
 * %) para cada ponto - regra inegociável da Task 21.3/21.8.
 */
import { computed } from 'vue';
import Card from '@/Components/Card.vue';
import { CORES_MONITORAMENTO } from '@/utils/paletaMonitoramento';

const props = defineProps({
  mapa: { type: Object, default: null },
});

const estiloContainer = computed(() => {
  const largura = props.mapa?.planta?.largura_px || 4;
  const altura = props.mapa?.planta?.altura_px || 3;
  return { aspectRatio: `${largura} / ${altura}` };
});

const pontosOrdenados = computed(() =>
  [...(props.mapa?.pontos ?? [])].sort((a, b) => b.valor_absoluto - a.valor_absoluto)
);

function intensidadePercentual(ponto) {
  return Math.round((ponto.intensidade ?? 0) * 100);
}

// Opacidade mínima de 0.35 para o ponto "frio" (zero captura) continuar
// visível no croqui - ele existe e foi monitorado, sumir da tela seria
// omitir um ponto que teve visita, mesma regra do backend de nunca omitir
// ponto zerado.
function estiloPonto(ponto) {
  const intensidade = ponto.intensidade ?? 0;
  const opacidade = 0.35 + intensidade * 0.65;

  return {
    left: `${ponto.x * 100}%`,
    top: `${ponto.y * 100}%`,
    width: '26px',
    height: '26px',
    backgroundColor: CORES_MONITORAMENTO.captura,
    opacity: opacidade,
  };
}
</script>
