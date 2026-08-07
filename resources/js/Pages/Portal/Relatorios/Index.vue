<template>
  <Head title="Relatórios de Monitoramento" />

  <PortalLayout>
    <template #header>
      <PageHeader
        title="Relatórios de Monitoramento"
        description="Relatórios de monitoramento de pragas do seu endereço, com o histórico de evolução do período." />
    </template>

    <div class="space-y-6">
      <div v-if="relatorios.data.length === 0" class="rounded-lg border border-gray-200 bg-white p-8 text-center">
        <svg class="mx-auto h-10 w-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
        </svg>
        <h3 class="mt-3 text-sm font-medium text-gray-900">Nenhum relatório disponível ainda</h3>
        <p class="mt-1 text-sm text-gray-500">
          Assim que a empresa publicar um relatório de monitoramento, ele aparece nesta lista.
        </p>
      </div>

      <div v-else class="space-y-3">
        <Link
          v-for="relatorio in relatorios.data"
          :key="relatorio.id"
          :href="route('portal.relatorios.show', relatorio.id)"
          class="block">
          <Card variant="hover" padding="normal">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-900">
                  {{ formatarData(relatorio.periodo_inicio) }} a {{ formatarData(relatorio.periodo_fim) }}
                </p>
                <p class="mt-1 truncate text-sm text-gray-600">{{ relatorio.endereco || 'Endereço não informado' }}</p>
              </div>
              <span class="shrink-0 text-xs text-gray-500">
                Gerado em {{ formatarData(relatorio.gerado_em) }}
              </span>
            </div>
            <div class="mt-3">
              <a
                :href="route('portal.relatorios.pdf', relatorio.id)"
                class="inline-flex items-center gap-1.5 text-sm font-medium"
                :style="{ color: 'var(--portal-cor-primaria)' }"
                @click.stop>
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3" />
                </svg>
                Baixar PDF
              </a>
            </div>
          </Card>
        </Link>
      </div>

      <Pagination :links="relatorios.links" />
    </div>
  </PortalLayout>
</template>

<script setup>
/**
 * Lista dos relatórios de monitoramento já publicados para o cliente logado
 * (Plano 21, Task 21.8). `relatorios` vem de `PortalRelatorioController::index()`,
 * já filtrado a `publicado_no_portal = true` e ao cliente/empresa do usuário
 * autenticado (ver o docblock do controller) - todo relatório que aparece
 * aqui já é seguro de mostrar, sem filtro adicional no frontend.
 *
 * Cada item é só `{id, endereco, periodo_inicio, periodo_fim, gerado_em}`
 * (`through()` no controller): nenhum dado de custo, observação interna ou
 * de técnico chega a esta tela.
 */
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatarData } from '@/utils/formatDate';

defineProps({
  relatorios: { type: Object, required: true },
});
</script>
