<!--
  Cadastro dos modelos de EPI (Plano 28, Task 28.6).

  É o cadastro do que a empresa compra, com o Certificado de Aprovação do
  fabricante. A ficha de quem recebeu o quê está em `Epi/Ficha.vue`, uma por
  técnico.

  O indicador de CA tem QUATRO estados, e o quarto é o que importa: "CA não
  informado" é CINZA, nunca vermelho. Campo em branco não é irregularidade —
  pintar de vermelho quem apenas não preencheu destrói a confiança na tela
  inteira, que é a lição registrada no checklist do Plano 24.
-->
<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Controle de EPI"
        description="Modelos de equipamento de proteção, Certificado de Aprovação e vida útil."
      >
        <template #actions>
          <button v-if="podeGerenciar" type="button" class="btn-primary" @click="abrirCadastro(null)">
            Novo EPI
          </button>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-6xl mx-auto space-y-6">
      <div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>
      <div v-if="erroDeExclusao" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ erroDeExclusao }}</p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
        <StatCard title="Modelos ativos" :value="indicadores.ativos" color="green" subtitle="Disponíveis para entrega" />
        <StatCard
          title="CA a vencer"
          :value="indicadores.aVencer"
          color="yellow"
          :subtitle="`Vencimento nos próximos ${DIAS_DE_AVISO_DO_CA} dias`"
        />
        <StatCard title="CA vencido" :value="indicadores.vencidos" color="red" subtitle="Não podem ser entregues" />
        <StatCard
          title="CA não informado"
          :value="indicadores.semCa"
          color="gray"
          subtitle="Estado neutro, não é irregularidade"
        />
      </div>

      <Card padding="small">
        <div class="flex flex-col sm:flex-row sm:items-end gap-4">
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Buscar</label>
            <input
              v-model="filtroBusca"
              type="search"
              placeholder="Nome, fabricante ou número do CA"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              @keyup.enter="aplicarFiltros"
            />
          </div>
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
            <select
              v-model="filtroTipo"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              @change="aplicarFiltros"
            >
              <option value="">Todos</option>
              <option v-for="tipo in tipos" :key="tipo" :value="tipo">{{ rotuloDeTipoDeEpi(tipo) }}</option>
            </select>
          </div>
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Situação</label>
            <select
              v-model="filtroSituacao"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              @change="aplicarFiltros"
            >
              <option value="">Todas</option>
              <option value="ativos">Ativos</option>
              <option value="inativos">Inativos</option>
            </select>
          </div>
          <div class="flex gap-2">
            <button type="button" class="btn-primary" @click="aplicarFiltros">Filtrar</button>
            <button type="button" class="btn-secondary" @click="limparFiltros">Limpar</button>
          </div>
        </div>
      </Card>

      <Card padding="none">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">EPI</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">CA</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vida útil</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Situação</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="epi in epis" :key="epi.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 text-sm font-medium text-gray-900">
                  {{ epi.nome }}
                  <span
                    v-if="epi.obrigatorio"
                    class="ml-1 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-800"
                  >
                    obrigatório
                  </span>
                  <p class="text-xs text-gray-500">
                    <span v-if="epi.fabricante">{{ epi.fabricante }}</span>
                    <span v-else class="text-gray-400">Fabricante não informado</span>
                    <template v-if="epi.ppe_deliveries_count">
                      · {{ epi.ppe_deliveries_count }} entrega(s)
                    </template>
                  </p>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                  {{ rotuloDeTipoDeEpi(epi.tipo) }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <div class="text-gray-900">
                    <span v-if="epi.numero_ca">{{ epi.numero_ca }}</span>
                    <span v-else class="text-gray-400">Número não informado</span>
                  </div>
                  <p class="text-xs text-gray-500">
                    <template v-if="epi.validade_ca">Validade {{ formatarData(epi.validade_ca) }}</template>
                    <template v-else>Sem validade informada</template>
                  </p>
                  <span
                    class="mt-1 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                    :class="situacaoDoCa(epi).cor"
                  >
                    {{ situacaoDoCa(epi).rotulo }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  <span v-if="epi.vida_util_dias !== null && epi.vida_util_dias !== undefined">
                    {{ epi.vida_util_dias }} dia(s)
                  </span>
                  <span v-else class="text-gray-400">{{ TEXTO_SEM_TROCA_PROGRAMADA }}</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <span
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                    :class="epi.ativo ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
                  >
                    {{ epi.ativo ? 'Ativo' : 'Inativo' }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-right">
                  <div v-if="podeGerenciar" class="flex justify-end gap-2">
                    <button type="button" class="btn-secondary-sm" @click="abrirCadastro(epi)">Editar</button>
                    <button
                      v-if="epi.ativo"
                      type="button"
                      class="btn-secondary-sm"
                      :disabled="processando"
                      @click="inativar(epi)"
                    >
                      Inativar
                    </button>
                    <button
                      v-else
                      type="button"
                      class="btn-secondary-sm"
                      :disabled="processando"
                      @click="reativar(epi)"
                    >
                      Reativar
                    </button>
                    <button
                      v-if="!epi.ppe_deliveries_count"
                      type="button"
                      class="btn-secondary-sm text-red-700"
                      @click="pedirExclusao(epi)"
                    >
                      Excluir
                    </button>
                  </div>
                  <span v-else class="text-gray-400">—</span>
                </td>
              </tr>
              <tr v-if="epis.length === 0">
                <td colspan="6" class="px-6 py-10 text-center text-sm text-gray-500">
                  Nenhum EPI cadastrado com esses filtros. Cadastre os modelos com o número do CA e a
                  validade antes de registrar as entregas: é o CA do dia da entrega que fica gravado
                  na ficha do técnico.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>

      <!-- Extração por período: o item 6.5.1.1 da NR-6 só aceita o registro
           eletrônico se ele permitir extrair a relação de entregas. -->
      <Card>
        <div class="mb-4">
          <h3 class="text-lg font-medium text-gray-900">Relação de entregas por período</h3>
          <p class="text-sm text-gray-500">
            Arquivo CSV com todas as entregas do período, estornadas incluídas e marcadas como tais.
            É o que se entrega à fiscalização.
          </p>
        </div>
        <div class="flex flex-col sm:flex-row sm:items-end gap-4">
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">De</label>
            <input
              v-model="extracaoInicio"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Até</label>
            <input
              v-model="extracaoFim"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>
          <div>
            <button
              type="button"
              class="btn-primary w-full sm:w-auto"
              :disabled="!periodoValido"
              @click="extrair"
            >
              Baixar CSV
            </button>
          </div>
        </div>
        <p v-if="!periodoValido" class="mt-2 text-sm text-gray-500">
          Informe as duas datas, com a final igual ou posterior à inicial.
        </p>
      </Card>

      <Card padding="small">
        <p class="text-sm text-gray-600">
          A ficha de entrega é por técnico: abra o técnico no
          <Link href="/technicians" class="text-green-700 hover:text-green-800 font-medium">cadastro de técnicos</Link>
          e use "Ficha de EPI" para registrar entrega, colher a assinatura de recebimento e baixar o
          documento em PDF.
        </p>
      </Card>
    </div>

    <EpiModal :show="modalAberto" :epi="epiEmEdicao" :tipos="tipos" @close="fecharCadastro" />

    <ConfirmDeleteModal
      :show="epiParaExcluir !== null"
      title="Excluir EPI"
      subtitle="Esta ação não pode ser desfeita."
      message="Tem certeza que deseja excluir o cadastro de"
      :item-name="epiParaExcluir?.nome || ''"
      confirm-text="Sim, excluir"
      processing-text="Excluindo..."
      :processing="processando"
      @cancel="epiParaExcluir = null"
      @confirm="excluir"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import EpiModal from '@/Components/EpiModal.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import { usePermissoes } from '@/composables/usePermissoes';
import { formatarData } from '@/utils/formatDate';
import {
  DIAS_DE_AVISO_DO_CA,
  TEXTO_SEM_TROCA_PROGRAMADA,
  rotuloDeTipoDeEpi,
  situacaoDoCa,
} from '@/utils/epi';

const props = defineProps({
  epis: { type: Array, default: () => [] },
  tipos: { type: Array, default: () => [] },
  filtros: { type: Object, default: () => ({}) },
});

const { pode } = usePermissoes();
const podeGerenciar = computed(() => pode('epi-gerenciar'));

const modalAberto = ref(false);
const epiEmEdicao = ref(null);
const epiParaExcluir = ref(null);
const processando = ref(false);
const erroDeExclusao = ref('');

const filtroBusca = ref(props.filtros.busca || '');
const filtroTipo = ref(props.filtros.tipo || '');
const filtroSituacao = ref(props.filtros.situacao || '');

const extracaoInicio = ref('');
const extracaoFim = ref('');

const periodoValido = computed(
  () => Boolean(extracaoInicio.value) && Boolean(extracaoFim.value) && extracaoFim.value >= extracaoInicio.value
);

const indicadores = computed(() => {
  const situacoes = props.epis.map((epi) => situacaoDoCa(epi).chave);

  return {
    ativos: props.epis.filter((epi) => epi.ativo).length,
    aVencer: situacoes.filter((chave) => chave === 'a_vencer').length,
    vencidos: situacoes.filter((chave) => chave === 'vencido').length,
    semCa: situacoes.filter((chave) => chave === 'nao_informado').length,
  };
});

function abrirCadastro(epi) {
  epiEmEdicao.value = epi;
  modalAberto.value = true;
}

function fecharCadastro() {
  modalAberto.value = false;
  epiEmEdicao.value = null;
}

function aplicarFiltros() {
  router.get(
    route('epi.index'),
    {
      busca: filtroBusca.value || undefined,
      tipo: filtroTipo.value || undefined,
      situacao: filtroSituacao.value || undefined,
    },
    { preserveState: true, preserveScroll: true, replace: true }
  );
}

function limparFiltros() {
  filtroBusca.value = '';
  filtroTipo.value = '';
  filtroSituacao.value = '';
  aplicarFiltros();
}

function inativar(epi) {
  processando.value = true;
  router.post(route('epi.inativar', epi.id), {}, {
    preserveScroll: true,
    onFinish: () => {
      processando.value = false;
    },
  });
}

function reativar(epi) {
  processando.value = true;
  router.post(route('epi.reativar', epi.id), {}, {
    preserveScroll: true,
    onFinish: () => {
      processando.value = false;
    },
  });
}

function pedirExclusao(epi) {
  erroDeExclusao.value = '';
  epiParaExcluir.value = epi;
}

/**
 * O cadastro só se exclui enquanto nunca foi entregue. Com entrega registrada o
 * Service recusa, e a recusa chega aqui em português, com o caminho certo
 * (inativar) — a ficha antiga aponta para este cadastro.
 */
function excluir() {
  if (!epiParaExcluir.value) return;

  processando.value = true;

  router.delete(route('epi.destroy', epiParaExcluir.value.id), {
    preserveScroll: true,
    onError: (erros) => {
      erroDeExclusao.value = erros.epi || 'Não foi possível excluir este EPI.';
    },
    onFinish: () => {
      processando.value = false;
      epiParaExcluir.value = null;
    },
  });
}

function extrair() {
  if (!periodoValido.value) return;

  window.open(
    route('epi.extracao', { inicio: extracaoInicio.value, fim: extracaoFim.value }),
    '_blank'
  );
}
</script>
