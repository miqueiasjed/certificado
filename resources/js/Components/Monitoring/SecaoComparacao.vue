<template>
  <Card>
    <h3 class="text-lg font-medium text-gray-900">Comparação com o período anterior</h3>
    <div class="mt-3 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-600">
      <p>
        Comparação de totais entre o período filtrado e o período anterior de mesma duração não está
        disponível nesta tela.
      </p>
      <p class="mt-1">
        A tendência por dispositivo, contra o período anterior de mesma duração, já aparece na coluna
        "Tendência" do ranking de pontos críticos abaixo.
      </p>
    </div>
  </Card>
</template>

<script setup>
/**
 * Seção explícita de "comparação com o período anterior", pedida pela Task
 * 21.8. `ConsolidadorDePeriodo::consolidar()` não chama
 * `TendenciaService::comparar()` (documentado no cabeçalho da classe: a
 * integração ficou fora do escopo da Task 21.5), então não existe hoje um
 * número de comparação agregado pronto para esta seção. Em vez de calcular
 * uma variação por conta própria aqui no frontend (o que inventaria uma
 * regra de negócio nova fora dos services), a seção fica com o aviso
 * explícito - a mesma tendência por dispositivo (que usa
 * `PontosCriticosService::ranking()`, esse sim já integrado) continua visível
 * na seção de ranking, ver `SecaoRanking.vue`.
 */
import Card from '@/Components/Card.vue';
</script>
