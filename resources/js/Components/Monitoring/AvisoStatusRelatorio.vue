<template>
  <div
    class="rounded-lg border p-4"
    :class="congelado ? 'border-blue-400 bg-blue-50' : 'border-yellow-400 bg-yellow-50'"
  >
    <div class="flex items-start gap-3">
      <svg
        v-if="congelado"
        class="mt-0.5 h-5 w-5 shrink-0 text-blue-400"
        fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
      </svg>
      <svg
        v-else
        class="mt-0.5 h-5 w-5 shrink-0 text-yellow-400"
        fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
      </svg>
      <div class="min-w-0">
        <p class="text-sm font-medium" :class="congelado ? 'text-blue-800' : 'text-yellow-800'">
          {{ congelado ? 'Documento congelado' : 'Visão ao vivo' }}
        </p>
        <p class="mt-1 text-sm" :class="congelado ? 'text-blue-700' : 'text-yellow-700'">
          <template v-if="congelado">
            Este relatório é o retrato do período no momento em que foi gerado, em
            <strong v-if="geradoEm">{{ geradoEm }}</strong><span v-else>data registrada</span>.
            Ele não muda mesmo que uma ordem de serviço deste período seja corrigida depois.
          </template>
          <template v-else>
            Os números abaixo são recalculados a cada visita a esta página e mudam conforme
            as ordens de serviço do período forem corrigidas. Para congelar este retrato como
            documento definitivo, use "Gerar relatório do período".
          </template>
        </p>
      </div>
    </div>
  </div>
</template>

<script setup>
defineProps({
  congelado: { type: Boolean, required: true },
  geradoEm: { type: String, default: '' },
});
</script>
