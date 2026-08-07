<template>
  <Head title="Relatório de Monitoramento" />

  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Relatório de Monitoramento"
        :description="`${formatarData(relatorio.periodo_inicio)} a ${formatarData(relatorio.periodo_fim)}`">
        <template #actions>
          <a :href="route('monitoramento.relatorios.pdf', relatorio.id)" class="btn-secondary">Baixar PDF</a>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <Link :href="route('monitoramento.relatorios.index')" class="inline-flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-gray-700">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7" />
        </svg>
        Voltar para relatórios
      </Link>

      <AvisoStatusRelatorio :congelado="congelado" :gerado-em="formatarDataHora(relatorio.gerado_em)" />

      <Card>
        <dl class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Cliente</dt>
            <dd class="mt-0.5 text-sm text-gray-900">{{ relatorio.client?.name || '-' }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Endereço</dt>
            <dd class="mt-0.5 text-sm text-gray-900">{{ relatorio.address?.nickname || 'Todos os endereços' }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Gerado em</dt>
            <dd class="mt-0.5 text-sm text-gray-900">{{ formatarDataHora(relatorio.gerado_em) }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Gerado por</dt>
            <dd class="mt-0.5 text-sm text-gray-900">{{ relatorio.gerado_por?.name || '-' }}</dd>
          </div>
        </dl>
        <div class="mt-4 flex flex-wrap gap-6 border-t border-gray-100 pt-4 text-sm">
          <div>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Visitas no período</p>
            <p class="mt-0.5 font-medium text-gray-900">{{ relatorio.dados?.visitas?.quantidade ?? 0 }}</p>
          </div>
          <div>
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Publicado no portal</p>
            <span
              class="mt-0.5 inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium"
              :class="relatorio.publicado_no_portal ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'">
              {{ relatorio.publicado_no_portal ? 'Sim' : 'Não' }}
            </span>
          </div>
        </div>
      </Card>

      <div v-if="porEndereco.length === 0" class="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">
        Nenhum endereço apurado neste relatório.
      </div>

      <div v-for="endereco in porEndereco" :key="endereco.address_id" class="space-y-6">
        <h2 v-if="porEndereco.length > 1" class="text-xl font-semibold text-gray-900">{{ endereco.endereco }}</h2>

        <SecaoEvolucao :evolucao-semanal="endereco.evolucaoSemanal" :evolucao-mensal="endereco.evolucaoMensal" />
        <SecaoComparacao />
        <SecaoRanking :ranking="endereco.ranking" />
        <SecaoMapaDeCalor :mapa="endereco.mapa" />
        <SecaoEspecies :especies="endereco.especies" />
      </div>

      <SecaoAdequacoes :adequacoes="relatorio.dados?.adequacoes ?? []" />
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
/**
 * Detalhe do relatório congelado (Plano 21, Task 21.8). `relatorio.dados` é
 * exatamente o que foi gravado no momento da geração (ver
 * `MonitoringReportController::show()`) - nunca recalculado, por isso o
 * mesmo formato de `consolidado` da visão ao vivo (`Monitoring/Index.vue`)
 * serve aqui sem adaptação, inclusive `montarPorEndereco()`.
 *
 * `relatorio.gerado_por` é a relação `geradoPor` carregada no controller
 * (`{id, name}`), não o inteiro da coluna `gerado_por`: o model tem uma
 * relação chamada `geradoPor()` que, ao serializar, vira a chave
 * `gerado_por` (Eloquent aplica `Str::snake()` no nome da relação) e
 * SOBRESCREVE o valor bruto da coluna de mesmo nome no array final
 * (`Model::toArray()` faz `array_merge(atributos, relações)`, nessa ordem).
 * Confirmado rodando `MonitoringReport::toArray()` com a relação carregada
 * antes de escrever este template - por isso `relatorio.gerado_por?.name`,
 * não `relatorio.geradoPor?.name`.
 */
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import AvisoStatusRelatorio from '@/Components/Monitoring/AvisoStatusRelatorio.vue';
import SecaoEvolucao from '@/Components/Monitoring/SecaoEvolucao.vue';
import SecaoComparacao from '@/Components/Monitoring/SecaoComparacao.vue';
import SecaoRanking from '@/Components/Monitoring/SecaoRanking.vue';
import SecaoMapaDeCalor from '@/Components/Monitoring/SecaoMapaDeCalor.vue';
import SecaoEspecies from '@/Components/Monitoring/SecaoEspecies.vue';
import SecaoAdequacoes from '@/Components/Monitoring/SecaoAdequacoes.vue';
import { formatarData, formatarDataHora } from '@/utils/formatDate';
import { montarPorEndereco } from '@/utils/monitoramento';

const props = defineProps({
  relatorio: { type: Object, required: true },
  congelado: { type: Boolean, required: true },
});

const porEndereco = computed(() => montarPorEndereco(props.relatorio.dados ?? {}));
</script>
