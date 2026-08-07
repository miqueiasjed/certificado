<template>
  <Card>
    <h3 class="text-lg font-medium text-gray-900">Adequações em aberto</h3>

    <div v-if="!adequacoes || adequacoes.length === 0" class="mt-3 rounded-md border border-gray-200 bg-gray-50 p-4 text-sm text-gray-500">
      Nenhuma adequação em aberto registrada neste período.
    </div>

    <div v-else class="mt-4 overflow-x-auto">
      <table class="min-w-full divide-y divide-gray-200 text-sm">
        <thead>
          <tr>
            <th class="px-3 py-2 text-left font-medium text-gray-500">Adequação</th>
            <th class="px-3 py-2 text-left font-medium text-gray-500">Prazo</th>
            <th class="px-3 py-2 text-center font-medium text-gray-500">Dias em aberto</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
          <tr v-for="(adequacao, indice) in adequacoes" :key="indice">
            <td class="px-3 py-2 text-gray-900">{{ adequacao.descricao || '-' }}</td>
            <td class="px-3 py-2 text-gray-700">{{ adequacao.prazo || '-' }}</td>
            <td class="px-3 py-2 text-center text-gray-900">{{ adequacao.dias_em_aberto ?? '-' }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </Card>
</template>

<script setup>
/**
 * `dados.adequacoes` é sempre `[]` hoje: `ConsolidadorDePeriodo::consolidar()`
 * documenta explicitamente que a integração de adequações fica fora do
 * escopo das Tasks 21.2/21.3/21.5 ("Adequações em aberto e prazo de
 * atendimento" no PRD do Plano 21, ainda não implementado). O componente já
 * sabe ler a lista quando ela existir (mesmas três chaves que o PDF usa em
 * `pdf.monitoring-report`), sem quebrar layout enquanto ela estiver vazia.
 */
defineProps({
  adequacoes: { type: Array, default: () => [] },
});
</script>
