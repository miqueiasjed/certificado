<template>
  <Head title="Monitoramento" />

  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Monitoramento"
        description="Visão ao vivo da evolução de infestação por cliente e endereço." />
    </template>

    <div class="space-y-6">
      <div v-if="$page.props.flash?.success" class="rounded-md border border-green-200 bg-green-50 p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <FiltroMonitoramento :clientes="clientes" :filtros-iniciais="filtros" rota="monitoramento.index" />

      <div v-if="!consolidado" class="rounded-lg border border-gray-200 bg-white p-8 text-center">
        <svg class="mx-auto h-10 w-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
        </svg>
        <h3 class="mt-3 text-sm font-medium text-gray-900">Selecione cliente e período</h3>
        <p class="mt-1 text-sm text-gray-500">
          Escolha ao menos o cliente, a data inicial e a data final para carregar a visão ao vivo do
          monitoramento.
        </p>
      </div>

      <template v-else>
        <AvisoStatusRelatorio :congelado="congelado" />

        <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white p-4">
          <div class="flex flex-wrap gap-6 text-sm">
            <div>
              <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Período</p>
              <p class="mt-0.5 font-medium text-gray-900">
                {{ formatarData(consolidado.periodo.de) }} a {{ formatarData(consolidado.periodo.ate) }}
              </p>
            </div>
            <div>
              <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Visitas no período</p>
              <p class="mt-0.5 font-medium text-gray-900">{{ consolidado.visitas.quantidade }}</p>
            </div>
          </div>

          <button
            v-if="podeGerar"
            type="button"
            class="btn-primary"
            :disabled="formGerar.processing"
            @click="gerarRelatorio">
            {{ formGerar.processing ? 'Gerando...' : 'Gerar relatório do período' }}
          </button>
        </div>

        <div v-if="porEndereco.length === 0" class="rounded-lg border border-gray-200 bg-white p-8 text-center text-sm text-gray-500">
          Nenhum endereço ativo encontrado para este cliente.
        </div>

        <div v-for="endereco in porEndereco" :key="endereco.address_id" class="space-y-6">
          <h2 v-if="porEndereco.length > 1" class="text-xl font-semibold text-gray-900">{{ endereco.endereco }}</h2>

          <SecaoEvolucao :evolucao-semanal="endereco.evolucaoSemanal" :evolucao-mensal="endereco.evolucaoMensal" />
          <SecaoComparacao />
          <SecaoRanking :ranking="endereco.ranking" />
          <SecaoMapaDeCalor :mapa="endereco.mapa" />
          <SecaoEspecies :especies="endereco.especies" />
        </div>

        <SecaoAdequacoes :adequacoes="consolidado.adequacoes" />
      </template>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
/**
 * Visão ao vivo do relatório de monitoramento (Plano 21, Task 21.8).
 * `consolidado` é o array completo de `ConsolidadorDePeriodo::consolidar()`
 * (ver `MonitoringReportController::index()`), recalculado a cada
 * requisição - nunca gravado. `congelado` sempre chega `false` desta rota; o
 * valor não muda dinamicamente aqui, é passado fixo só para o mesmo
 * `AvisoStatusRelatorio` servir também o relatório congelado
 * (`Relatorios/Show.vue`).
 */
import { computed } from 'vue';
import { Head, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import FiltroMonitoramento from '@/Components/Monitoring/FiltroMonitoramento.vue';
import AvisoStatusRelatorio from '@/Components/Monitoring/AvisoStatusRelatorio.vue';
import SecaoEvolucao from '@/Components/Monitoring/SecaoEvolucao.vue';
import SecaoComparacao from '@/Components/Monitoring/SecaoComparacao.vue';
import SecaoRanking from '@/Components/Monitoring/SecaoRanking.vue';
import SecaoMapaDeCalor from '@/Components/Monitoring/SecaoMapaDeCalor.vue';
import SecaoEspecies from '@/Components/Monitoring/SecaoEspecies.vue';
import SecaoAdequacoes from '@/Components/Monitoring/SecaoAdequacoes.vue';
import { formatarData } from '@/utils/formatDate';
import { montarPorEndereco } from '@/utils/monitoramento';
import { usePermissoes } from '@/Composables/usePermissoes';

const props = defineProps({
  clientes: { type: Array, required: true },
  filtros: { type: Object, required: true },
  consolidado: { type: Object, default: null },
  congelado: { type: Boolean, required: true },
});

const { pode } = usePermissoes();

// Esconder o botão para quem não tem `monitoramento-gerar` é só conveniência
// visual - a rota `monitoramento.relatorios.store` confere a mesma permissão
// no backend independente disto (ver `StoreMonitoringReportRequest`).
const podeGerar = computed(() => pode('monitoramento-gerar'));

const porEndereco = computed(() => (props.consolidado ? montarPorEndereco(props.consolidado) : []));

const formGerar = useForm({
  client_id: '',
  address_id: '',
  de: '',
  ate: '',
});

function gerarRelatorio() {
  if (!props.consolidado) return;

  formGerar.client_id = props.filtros.client_id;
  formGerar.address_id = props.filtros.address_id;
  formGerar.de = props.filtros.de;
  formGerar.ate = props.filtros.ate;

  // O backend redireciona para `monitoramento.relatorios.show` em caso de
  // sucesso (ver `MonitoringReportController::store()`); o Inertia segue o
  // redirect automaticamente, então não há navegação manual aqui.
  formGerar.post(route('monitoramento.relatorios.store'), { preserveScroll: true });
}
</script>
