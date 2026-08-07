<template>
  <Card>
    <h3 class="text-lg font-medium text-gray-900">Ocorrência por espécie</h3>
    <p class="mt-1 text-sm text-gray-500">
      Contagem por avistamento de espécie (não por captura em dispositivo).
      <span v-if="especies?.periodo_anterior">
        Período anterior: {{ formatarData(especies.periodo_anterior.de) }} a {{ formatarData(especies.periodo_anterior.ate) }}.
      </span>
    </p>

    <div class="mt-4 overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead>
          <tr>
            <th class="px-3 py-2 text-left font-medium text-gray-500">Espécie</th>
            <th class="px-3 py-2 text-center font-medium text-gray-500">Período anterior</th>
            <th class="px-3 py-2 text-center font-medium text-gray-500">Período atual</th>
            <th class="px-3 py-2 text-left font-medium text-gray-500">Variação</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="linha in evolucao" :key="linha.pest_type">
            <td class="px-3 py-2 text-gray-900">{{ rotuloEspecie(linha.pest_type) }}</td>
            <td class="px-3 py-2 text-center text-gray-500">{{ linha.de }}</td>
            <td class="px-3 py-2">
              <div class="flex items-center justify-center gap-2">
                <div class="h-2 w-16 overflow-hidden rounded-full bg-gray-100">
                  <div
                    class="h-full rounded-full"
                    :style="{ width: `${largura(linha.para)}%`, backgroundColor: CORES_MONITORAMENTO.captura }">
                  </div>
                </div>
                <span class="font-medium text-gray-900">{{ linha.para }}</span>
              </div>
            </td>
            <td class="px-3 py-2 text-gray-700">
              <template v-if="linha.percentual === null">
                <span v-if="linha.a_partir_de_zero">De 0 para {{ linha.para }}</span>
                <span v-else>-</span>
              </template>
              <template v-else>
                {{ linha.percentual >= 0 ? '+' : '' }}{{ formatarPercentual(linha.percentual) }}%
              </template>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </Card>
</template>

<script setup>
/**
 * Ocorrência por espécie de um único endereço (`ocorrencia_por_especie[n]`,
 * exatamente o que `OcorrenciaPorEspecieService::porPeriodo()` devolve).
 * Tabela, igual ao PDF (`pdf.monitoring-report`, seção "Ocorrência por
 * espécie") - não existe gráfico de espécie no PDF, então esta tela também
 * não inventa um, para as duas superfícies continuarem mostrando a mesma
 * coisa (regra "mesma paleta em todas as telas e no PDF"). Todas as 13
 * espécies do vocabulário fechado aparecem sempre, mesmo com zero
 * ocorrência - omitir uma quebraria a categoria fixa entre relatórios
 * diferentes, mesma razão documentada no backend.
 */
import { computed } from 'vue';
import Card from '@/Components/Card.vue';
import { formatarData } from '@/utils/formatDate';
import { CORES_MONITORAMENTO, rotuloEspecie } from '@/utils/paletaMonitoramento';

const props = defineProps({
  especies: { type: Object, default: null },
});

const evolucao = computed(() => props.especies?.evolucao_por_especie ?? []);

const maiorValor = computed(() => {
  const valores = evolucao.value.map((linha) => linha.para);
  return valores.length > 0 ? Math.max(...valores, 1) : 1;
});

function largura(valor) {
  return valor > 0 ? Math.max(6, Math.round((valor / maiorValor.value) * 100)) : 0;
}

function formatarPercentual(valor) {
  return Number(valor).toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
}
</script>
