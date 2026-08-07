<template>
  <Head title="Relatórios de Monitoramento" />

  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Relatórios de Monitoramento"
        description="Relatórios já gerados e congelados, com o controle de publicação no portal do cliente.">
        <template #actions>
          <Link :href="route('monitoramento.index')" class="btn-secondary">Visão ao vivo</Link>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <div v-if="$page.props.flash?.success" class="rounded-md border border-green-200 bg-green-50 p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <div class="rounded-lg border border-gray-200 bg-white p-4">
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">De</label>
            <input
              v-model="filtroPeriodo.de"
              type="date"
              class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500">
          </div>
          <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">Até</label>
            <input
              v-model="filtroPeriodo.ate"
              type="date"
              class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500">
          </div>
        </div>
        <div class="mt-4 flex flex-wrap items-center gap-3">
          <button type="button" class="btn-primary" @click="filtrarPorPeriodo">Filtrar</button>
          <button
            v-if="filtros.de || filtros.ate || filtros.client_id || filtros.address_id"
            type="button"
            class="text-sm font-medium text-gray-500 hover:text-gray-700"
            @click="limparFiltros">
            Limpar filtros
          </button>
        </div>
      </div>

      <div v-if="relatorios.data.length === 0" class="rounded-lg border border-gray-200 bg-white p-8 text-center">
        <p class="text-sm text-gray-500">Nenhum relatório gerado ainda para este filtro.</p>
      </div>

      <Card v-else padding="none">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Período</th>
                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Cliente</th>
                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Endereço</th>
                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Gerado em</th>
                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Gerado por</th>
                <th class="px-6 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500">Portal do cliente</th>
                <th class="px-6 py-3 text-right text-xs font-medium uppercase tracking-wider text-gray-500">Ações</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
              <tr v-for="relatorio in relatorios.data" :key="relatorio.id" class="hover:bg-gray-50">
                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">
                  {{ formatarData(relatorio.periodo_inicio) }} a {{ formatarData(relatorio.periodo_fim) }}
                </td>
                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-900">{{ relatorio.client?.name || '-' }}</td>
                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ relatorio.address?.nickname || 'Todos os endereços' }}</td>
                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ formatarDataHora(relatorio.gerado_em) }}</td>
                <td class="whitespace-nowrap px-6 py-4 text-sm text-gray-500">{{ relatorio.gerado_por?.name || '-' }}</td>
                <td class="whitespace-nowrap px-6 py-4">
                  <span
                    class="inline-flex rounded-full px-2.5 py-0.5 text-xs font-medium"
                    :class="relatorio.publicado_no_portal ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'">
                    {{ relatorio.publicado_no_portal ? 'Publicado' : 'Não publicado' }}
                  </span>
                </td>
                <td class="whitespace-nowrap px-6 py-4 text-right text-sm">
                  <div class="flex justify-end gap-3">
                    <Link :href="route('monitoramento.relatorios.show', relatorio.id)" class="font-medium text-green-600 hover:text-green-800">
                      Ver
                    </Link>
                    <a :href="route('monitoramento.relatorios.pdf', relatorio.id)" class="font-medium text-gray-600 hover:text-gray-800">
                      PDF
                    </a>
                    <button
                      v-if="podePublicar && !relatorio.publicado_no_portal"
                      type="button"
                      class="font-medium text-blue-600 hover:text-blue-800"
                      @click="abrirModalPublicacao(relatorio, 'publicar')">
                      Publicar
                    </button>
                    <button
                      v-if="podePublicar && relatorio.publicado_no_portal"
                      type="button"
                      class="font-medium text-red-600 hover:text-red-800"
                      @click="abrirModalPublicacao(relatorio, 'despublicar')">
                      Despublicar
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>

      <Pagination :links="relatorios.links" />
    </div>

    <!-- Modal de confirmação de publicação/despublicação: nunca `confirm()` nativo. -->
    <Modal :show="modalAberto" @close="fecharModal">
      <template #icon>
        <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
        </svg>
      </template>
      <template #title>
        {{ acaoModal === 'publicar' ? 'Publicar relatório no portal do cliente' : 'Despublicar relatório do portal do cliente' }}
      </template>
      <template #content>
        <p v-if="acaoModal === 'publicar'" class="text-sm text-gray-700">
          A partir de agora, o cliente passará a ver este documento no portal dele, com download do PDF liberado.
          Confirma a publicação do relatório do período
          <strong>{{ relatorioSelecionado ? `${formatarData(relatorioSelecionado.periodo_inicio)} a ${formatarData(relatorioSelecionado.periodo_fim)}` : '' }}</strong>?
        </p>
        <p v-else class="text-sm text-gray-700">
          O cliente deixará de ver este documento no portal dele. Confirma a despublicação do relatório do período
          <strong>{{ relatorioSelecionado ? `${formatarData(relatorioSelecionado.periodo_inicio)} a ${formatarData(relatorioSelecionado.periodo_fim)}` : '' }}</strong>?
        </p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="processando" @click="fecharModal">Cancelar</button>
        <button type="button" class="btn-primary ml-3" :disabled="processando" @click="confirmarPublicacao">
          {{ processando ? 'Aguarde...' : 'Confirmar' }}
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { Head, Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatarData, formatarDataHora } from '@/utils/formatDate';
import { usePermissoes } from '@/Composables/usePermissoes';

/**
 * `MonitoringReportController::relatorios()` só devolve `relatorios`
 * (paginado) e `filtros` - sem lista de clientes (diferente de `index()`,
 * que tem `clientes` porque a visão ao vivo precisa montar o dropdown do
 * zero). Por isso o filtro nesta tela é só por período: um dropdown de
 * cliente/endereço aqui exigiria uma chamada nova fora do que a Task 21.5
 * já expõe, fora do escopo desta task de frontend. `client_id`/`address_id`
 * continuam funcionando via querystring (a mesma `MonitoringReportFilterRequest`
 * do backend aceita os dois) para quem chegar aqui com um link já filtrado.
 */
const props = defineProps({
  relatorios: { type: Object, required: true },
  filtros: { type: Object, required: true },
});

const { pode } = usePermissoes();

const filtroPeriodo = reactive({
  de: props.filtros.de ?? '',
  ate: props.filtros.ate ?? '',
});

function filtrarPorPeriodo() {
  const parametros = {};
  if (props.filtros.client_id) parametros.client_id = props.filtros.client_id;
  if (props.filtros.address_id) parametros.address_id = props.filtros.address_id;
  if (filtroPeriodo.de) parametros.de = filtroPeriodo.de;
  if (filtroPeriodo.ate) parametros.ate = filtroPeriodo.ate;

  router.get(route('monitoramento.relatorios.index'), parametros, { preserveState: true, preserveScroll: true, replace: true });
}

function limparFiltros() {
  filtroPeriodo.de = '';
  filtroPeriodo.ate = '';
  router.get(route('monitoramento.relatorios.index'), {}, { preserveState: true, preserveScroll: true, replace: true });
}

// Esconder as ações é conveniência visual - `monitoramento.relatorios.publicar`/
// `despublicar` confere `monitoramento-publicar` no backend independente disto.
const podePublicar = pode('monitoramento-publicar');

const modalAberto = ref(false);
const acaoModal = ref('publicar');
const relatorioSelecionado = ref(null);
const processando = ref(false);

function abrirModalPublicacao(relatorio, acao) {
  relatorioSelecionado.value = relatorio;
  acaoModal.value = acao;
  modalAberto.value = true;
}

function fecharModal() {
  if (processando.value) return;
  modalAberto.value = false;
  relatorioSelecionado.value = null;
}

function confirmarPublicacao() {
  if (!relatorioSelecionado.value) return;

  processando.value = true;

  const rota = acaoModal.value === 'publicar' ? 'monitoramento.relatorios.publicar' : 'monitoramento.relatorios.despublicar';

  router.post(route(rota, relatorioSelecionado.value.id), {}, {
    preserveScroll: true,
    onFinish: () => {
      processando.value = false;
      modalAberto.value = false;
      relatorioSelecionado.value = null;
    },
  });
}
</script>
