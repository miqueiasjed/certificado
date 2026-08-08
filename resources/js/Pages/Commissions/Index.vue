<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Comissões"
        :description="ve_todas
          ? 'Apuração e fechamento da comissão de vendedores e técnicos, por competência.'
          : 'Sua comissão apurada na competência selecionada.'">
        <template #actions>
          <Link v-if="pode('comissoes-apurar')" :href="route('comissoes.regras.index')" class="btn-secondary">
            Regras de comissão
          </Link>
          <button v-if="pode('comissoes-apurar')" type="button" class="btn-primary" @click="abrirApurar">
            Apurar competência
          </button>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-5xl mx-auto space-y-6">
      <div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!-- Seletor de competência -->
      <Card>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <label for="competencia" class="block text-sm font-medium text-gray-700 mb-1">Competência</label>
            <input
              id="competencia"
              v-model="competenciaSelecionada"
              type="month"
              class="campo"
              @change="mudarCompetencia" />
          </div>
          <p class="text-sm text-gray-500">
            {{ comissoes.length }} comissão(ões) apurada(s) para {{ competenciaFormatada(competencia) }}.
          </p>
        </div>
      </Card>

      <!-- Lista vazia -->
      <Card v-if="comissoes.length === 0">
        <div class="py-8 text-center">
          <p class="text-sm text-gray-500">
            {{ ve_todas
              ? 'Nenhuma comissão apurada para esta competência.'
              : 'Você ainda não tem comissão apurada nesta competência.' }}
          </p>
          <button v-if="pode('comissoes-apurar')" type="button" class="btn-primary mt-4" @click="abrirApurar">
            Apurar agora
          </button>
        </div>
      </Card>

      <!-- Uma comissão por pessoa (ou a própria, quando sem comissoes-ver-todas) -->
      <Card v-for="comissao in comissoes" :key="comissao.id" padding="small">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="min-w-0">
            <p v-if="ve_todas" class="font-semibold text-gray-900 truncate">{{ nomeDaPessoa(comissao) }}</p>
            <p class="text-sm text-gray-500">{{ tipoDaPessoa(comissao) }}</p>
          </div>
          <span class="badge" :class="SITUACAO_BADGE[comissao.situacao]">{{ SITUACAO_LABEL[comissao.situacao] || comissao.situacao }}</span>
        </div>

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
          <p class="text-2xl font-semibold text-gray-900">{{ formatarMoeda(comissao.valor_total) }}</p>
          <button type="button" class="acao" @click="alternarExpandido(comissao.id)">
            {{ expandido[comissao.id] ? 'Ocultar itens' : `Ver itens (${comissao.items.length})` }}
          </button>
        </div>

        <p v-if="comissao.situacao === 'fechada' && comissao.fechada_em" class="mt-1 text-xs text-gray-500">
          Fechada em {{ formatarDataHora(comissao.fechada_em) }}
        </p>
        <p v-if="comissao.situacao === 'paga' && comissao.paga_em" class="mt-1 text-xs text-gray-500">
          Paga em {{ formatarDataHora(comissao.paga_em) }}
        </p>

        <!-- Itens: origem, base, percentual/valor aplicado e valor. É a memória de
             cálculo que resolve discussão com o vendedor sem consultar o banco. -->
        <div v-if="expandido[comissao.id]" class="mt-4 overflow-x-auto rounded-md border border-gray-100">
          <table class="min-w-full divide-y divide-gray-200 text-sm">
            <thead class="bg-gray-50">
              <tr>
                <th class="cabecalho">Origem</th>
                <th class="cabecalho">Data do fato</th>
                <th class="cabecalho text-right">Base</th>
                <th class="cabecalho text-right">Aplicado</th>
                <th class="cabecalho text-right">Valor</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 bg-white">
              <tr v-for="item in comissao.items" :key="item.id">
                <td class="celula text-gray-700">{{ ORIGEM_LABEL[item.origem_tipo] || item.origem_tipo }} #{{ item.origem_id }}</td>
                <td class="celula text-gray-700">{{ formatarData(item.ocorrido_em) }}</td>
                <td class="celula text-right text-gray-700">{{ formatarMoeda(item.base_valor) }}</td>
                <td class="celula text-right text-gray-700">{{ aplicadoFormatado(item) }}</td>
                <td class="celula text-right font-medium" :class="Number(item.valor) < 0 ? 'text-red-700' : 'text-gray-900'">
                  {{ formatarMoeda(item.valor) }}
                </td>
              </tr>
              <tr v-if="comissao.items.length === 0">
                <td colspan="5" class="celula text-center text-gray-500">Nenhum item apurado nesta competência.</td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="mt-4 flex flex-wrap gap-4 border-t border-gray-100 pt-3">
          <button v-if="pode('comissoes-fechar') && comissao.situacao === 'aberta'" type="button" class="acao" @click="abrirFechar(comissao)">
            Fechar
          </button>
          <button v-if="pode('comissoes-reabrir') && comissao.situacao !== 'aberta'" type="button" class="acao text-amber-700 hover:text-amber-900" @click="abrirReabrir(comissao)">
            Reabrir
          </button>
          <button v-if="pode('comissoes-fechar') && comissao.situacao === 'fechada'" type="button" class="acao" @click="abrirPagar(comissao)">
            Marcar como paga
          </button>
        </div>
      </Card>
    </div>

    <!-- Apurar: substitui do zero os itens de toda comissão ainda ABERTA da competência. -->
    <Modal :show="mostrarApurar" @close="fecharApurar">
      <template #icon>
        <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
        </svg>
      </template>
      <template #title>Apurar a competência {{ competenciaFormatada(competencia) }}</template>
      <template #content>
        <p class="text-sm text-gray-700">
          Isso recalcula do zero os itens de toda comissão ainda <strong>aberta</strong> desta competência, a partir
          das vendas aprovadas, das parcelas recebidas e das ordens de serviço concluídas no período. Comissões já
          <strong>fechadas</strong> ou <strong>pagas</strong> não são alteradas.
        </p>
        <p v-if="apurarForm.errors.competencia" class="mt-2 text-sm text-red-600">{{ apurarForm.errors.competencia }}</p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="apurarForm.processing" @click="fecharApurar">Cancelar</button>
        <button type="button" class="btn-primary ml-3" :disabled="apurarForm.processing" @click="confirmarApurar">
          {{ apurarForm.processing ? 'Apurando...' : 'Apurar agora' }}
        </button>
      </template>
    </Modal>

    <!-- Fechar: mostra o total e o efeito de imutabilidade antes de confirmar. -->
    <Modal :show="mostrarFechar" @close="fecharModalFechar">
      <template #icon>
        <svg class="h-6 w-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
        </svg>
      </template>
      <template #title>Fechar comissão</template>
      <template #content>
        <p class="text-sm text-gray-600">
          {{ ve_todas ? nomeDaPessoa(comissaoAlvo) + ' · ' : '' }}competência {{ competenciaFormatada(comissaoAlvo?.competencia) }}
        </p>
        <p class="mt-1 text-2xl font-semibold text-gray-900">{{ formatarMoeda(comissaoAlvo?.valor_total) }}</p>
        <p class="mt-3 text-sm text-gray-700">
          Depois de fechada, esta comissão fica imutável: a apuração automática não recalcula mais os itens dela.
          Ela só volta a ficar aberta por meio de uma reabertura, que exige justificativa.
        </p>
        <p v-if="fecharForm.errors.comissao" class="mt-2 text-sm text-red-600">{{ fecharForm.errors.comissao }}</p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="fecharForm.processing" @click="fecharModalFechar">Cancelar</button>
        <button type="button" class="btn-primary ml-3" :disabled="fecharForm.processing" @click="confirmarFechar">
          {{ fecharForm.processing ? 'Fechando...' : 'Fechar comissão' }}
        </button>
      </template>
    </Modal>

    <!-- Reabrir: exige justificativa com pelo menos 10 caracteres. -->
    <Modal :show="mostrarReabrir" @close="fecharModalReabrir">
      <template #icon>
        <svg class="h-6 w-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
        </svg>
      </template>
      <template #title>Reabrir comissão</template>
      <template #content>
        <p class="text-sm text-gray-700 mb-4">
          {{ ve_todas ? nomeDaPessoa(comissaoAlvo) + ' · ' : '' }}competência {{ competenciaFormatada(comissaoAlvo?.competencia) }}.
          A comissão volta a ficar aberta e sujeita a reapuração. Explique o motivo: fica registrado na auditoria.
        </p>
        <label for="justificativa" class="block text-sm font-medium text-gray-700 mb-1">Justificativa *</label>
        <textarea
          id="justificativa"
          v-model="reabrirForm.justificativa"
          rows="3"
          maxlength="1000"
          class="campo"
          :class="{ 'border-red-500': reabrirForm.errors.justificativa }"
          placeholder="Explique por que esta comissão fechada precisa ser reaberta (mínimo 10 caracteres)."></textarea>
        <p v-if="reabrirForm.errors.justificativa" class="mt-1 text-sm text-red-600">{{ reabrirForm.errors.justificativa }}</p>
        <p v-else-if="reabrirForm.errors.comissao" class="mt-1 text-sm text-red-600">{{ reabrirForm.errors.comissao }}</p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="reabrirForm.processing" @click="fecharModalReabrir">Cancelar</button>
        <button
          type="button"
          class="btn-primary ml-3"
          :disabled="reabrirForm.processing || reabrirForm.justificativa.trim().length < 10"
          @click="confirmarReabrir">
          {{ reabrirForm.processing ? 'Reabrindo...' : 'Reabrir comissão' }}
        </button>
      </template>
    </Modal>

    <!-- Marcar como paga: opção de gerar o título a pagar do financeiro. -->
    <Modal :show="mostrarPagar" @close="fecharModalPagar">
      <template #title>Marcar comissão como paga</template>
      <template #content>
        <p class="text-sm text-gray-600">
          {{ ve_todas ? nomeDaPessoa(comissaoAlvo) + ' · ' : '' }}total {{ formatarMoeda(comissaoAlvo?.valor_total) }}
        </p>
        <label class="mt-4 flex items-center gap-2">
          <input v-model="pagarForm.gerar_titulo" type="checkbox" class="rounded border-gray-300 text-green-600 focus:ring-green-500" />
          <span class="text-sm text-gray-700">Gerar título a pagar no financeiro para esta comissão</span>
        </label>

        <div v-if="pagarForm.gerar_titulo" class="mt-4 space-y-4">
          <p class="text-xs text-gray-500">
            Informe o fornecedor já cadastrado que representa esta pessoa para fins de contas a pagar (o schema
            atual paga comissão pelo mesmo cadastro de fornecedor do módulo financeiro).
          </p>
          <div>
            <label for="supplier_id" class="block text-sm font-medium text-gray-700 mb-1">ID do fornecedor *</label>
            <input
              id="supplier_id"
              v-model="pagarForm.supplier_id"
              type="number"
              min="1"
              class="campo"
              :class="{ 'border-red-500': pagarForm.errors.supplier_id }" />
            <p v-if="pagarForm.errors.supplier_id" class="mt-1 text-sm text-red-600">{{ pagarForm.errors.supplier_id }}</p>
          </div>
          <div>
            <label for="vencimento" class="block text-sm font-medium text-gray-700 mb-1">Vencimento *</label>
            <input
              id="vencimento"
              v-model="pagarForm.vencimento"
              type="date"
              class="campo"
              :class="{ 'border-red-500': pagarForm.errors.vencimento }" />
            <p v-if="pagarForm.errors.vencimento" class="mt-1 text-sm text-red-600">{{ pagarForm.errors.vencimento }}</p>
          </div>
          <div>
            <label for="descricao" class="block text-sm font-medium text-gray-700 mb-1">Descrição (opcional)</label>
            <input id="descricao" v-model="pagarForm.descricao" type="text" maxlength="255" class="campo" />
          </div>
          <div>
            <label for="chart_of_account_id" class="block text-sm font-medium text-gray-700 mb-1">ID da categoria do plano de contas (opcional)</label>
            <input id="chart_of_account_id" v-model="pagarForm.chart_of_account_id" type="number" min="1" class="campo" />
          </div>
        </div>

        <p v-if="pagarForm.errors.comissao" class="mt-3 text-sm text-red-600">{{ pagarForm.errors.comissao }}</p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="pagarForm.processing" @click="fecharModalPagar">Cancelar</button>
        <button type="button" class="btn-primary ml-3" :disabled="pagarForm.processing" @click="confirmarPagar">
          {{ pagarForm.processing ? 'Salvando...' : 'Marcar como paga' }}
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
/**
 * Tela de comissão (Task 23.7): apuração e fechamento por competência.
 *
 * Sem `ve_todas` (permissão `comissoes-ver-todas`), o backend já filtra
 * `comissoes` para conter só a própria comissão do usuário logado (como
 * vendedor e/ou como técnico) - por isso esta tela nunca mostra coluna de
 * pessoa, seletor de pessoa nem qualquer filtro por pessoa quando `ve_todas`
 * é falso: não há dado de outra pessoa aqui para indicar que uma lista existe.
 */
import { reactive, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { formatarData, formatarDataHora } from '@/utils/formatDate';

const props = defineProps({
  competencia: { type: String, required: true },
  ve_todas: { type: Boolean, default: false },
  comissoes: { type: Array, default: () => [] },
});

const { pode } = usePermissoes();

const SITUACAO_LABEL = { aberta: 'Aberta', fechada: 'Fechada', paga: 'Paga' };
const SITUACAO_BADGE = {
  aberta: 'bg-blue-100 text-blue-800',
  fechada: 'bg-yellow-100 text-yellow-800',
  paga: 'bg-green-100 text-green-800',
};
const ORIGEM_LABEL = {
  orcamento: 'Orçamento (venda)',
  parcela: 'Parcela recebida',
  ordem_servico: 'Ordem de serviço (execução)',
};

function nomeDaPessoa(comissao) {
  if (!comissao) return '';
  return comissao.user?.name || comissao.technician?.name || 'Pessoa não identificada';
}

function tipoDaPessoa(comissao) {
  if (!comissao) return '';
  return comissao.user_id ? 'Comissão de vendedor' : 'Comissão de técnico';
}

function competenciaFormatada(competencia) {
  if (!competencia) return '';
  const [ano, mes] = competencia.split('-');
  return `${mes}/${ano}`;
}

function formatarMoeda(valor) {
  const numero = typeof valor === 'number' ? valor : parseFloat(valor || 0);
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number.isFinite(numero) ? numero : 0);
}

// O contrato do item não traz a `forma` da regra aplicada (só o valor bruto
// gravado no momento da apuração). A mesma fórmula de
// `CommissionCalculationService::calcularValor()` permite inferir: em
// `percentual`, valor = base * fator / 100; em `valor_fixo`, valor = fator.
function ehPercentual(item) {
  const base = Number(item.base_valor);
  const fator = Number(item.percentual_ou_fixo);
  const valor = Math.abs(Number(item.valor));

  if (base === 0) return true;

  return Math.abs(Math.abs(base * fator / 100) - valor) < 0.01;
}

function aplicadoFormatado(item) {
  const fator = Number(item.percentual_ou_fixo);

  if (ehPercentual(item)) {
    return `${fator.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`;
  }

  return `${formatarMoeda(fator)} (fixo)`;
}

// -----------------------------------------------------------------
// Competência
// -----------------------------------------------------------------

const competenciaSelecionada = ref(props.competencia);

function mudarCompetencia() {
  router.get(route('comissoes.index'), { competencia: competenciaSelecionada.value }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  });
}

// -----------------------------------------------------------------
// Expandir itens
// -----------------------------------------------------------------

const expandido = reactive({});

function alternarExpandido(id) {
  expandido[id] = !expandido[id];
}

// -----------------------------------------------------------------
// Alvo compartilhado pelas ações de fechar/reabrir/pagar (só um modal
// fica aberto por vez).
// -----------------------------------------------------------------

const comissaoAlvo = ref(null);

// -----------------------------------------------------------------
// Apurar
// -----------------------------------------------------------------

const apurarForm = useForm({ competencia: props.competencia });
const mostrarApurar = ref(false);

function abrirApurar() {
  apurarForm.competencia = props.competencia;
  apurarForm.clearErrors();
  mostrarApurar.value = true;
}

function fecharApurar() {
  if (apurarForm.processing) return;
  mostrarApurar.value = false;
}

function confirmarApurar() {
  apurarForm.post(route('comissoes.apurar'), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => { mostrarApurar.value = false; },
  });
}

// -----------------------------------------------------------------
// Fechar
// -----------------------------------------------------------------

const fecharForm = useForm({});
const mostrarFechar = ref(false);

function abrirFechar(comissao) {
  comissaoAlvo.value = comissao;
  fecharForm.clearErrors();
  mostrarFechar.value = true;
}

function fecharModalFechar() {
  if (fecharForm.processing) return;
  mostrarFechar.value = false;
  comissaoAlvo.value = null;
}

function confirmarFechar() {
  fecharForm.post(route('comissoes.fechar', comissaoAlvo.value.id), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => { mostrarFechar.value = false; comissaoAlvo.value = null; },
  });
}

// -----------------------------------------------------------------
// Reabrir
// -----------------------------------------------------------------

const reabrirForm = useForm({ justificativa: '' });
const mostrarReabrir = ref(false);

function abrirReabrir(comissao) {
  comissaoAlvo.value = comissao;
  reabrirForm.reset();
  reabrirForm.clearErrors();
  mostrarReabrir.value = true;
}

function fecharModalReabrir() {
  if (reabrirForm.processing) return;
  mostrarReabrir.value = false;
  comissaoAlvo.value = null;
}

function confirmarReabrir() {
  reabrirForm.post(route('comissoes.reabrir', comissaoAlvo.value.id), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => { mostrarReabrir.value = false; comissaoAlvo.value = null; },
  });
}

// -----------------------------------------------------------------
// Marcar como paga
// -----------------------------------------------------------------

const pagarForm = useForm({
  gerar_titulo: false,
  supplier_id: '',
  vencimento: '',
  descricao: '',
  chart_of_account_id: '',
});
const mostrarPagar = ref(false);

function abrirPagar(comissao) {
  comissaoAlvo.value = comissao;
  pagarForm.reset();
  pagarForm.clearErrors();
  mostrarPagar.value = true;
}

function fecharModalPagar() {
  if (pagarForm.processing) return;
  mostrarPagar.value = false;
  comissaoAlvo.value = null;
}

function confirmarPagar() {
  pagarForm
    .transform((dados) => ({
      gerar_titulo: dados.gerar_titulo,
      supplier_id: dados.gerar_titulo ? dados.supplier_id : null,
      vencimento: dados.gerar_titulo ? dados.vencimento : null,
      descricao: dados.descricao || null,
      chart_of_account_id: dados.chart_of_account_id || null,
    }))
    .post(route('comissoes.pagar', comissaoAlvo.value.id), {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => { mostrarPagar.value = false; comissaoAlvo.value = null; },
    });
}
</script>

<style scoped>
.campo { @apply w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm; }
.cabecalho { @apply px-3 py-2 text-left text-xs font-medium uppercase tracking-wider text-gray-500; }
.celula { @apply whitespace-nowrap px-3 py-2; }
.badge { @apply inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium; }
.acao { @apply text-sm font-medium text-green-700 hover:text-green-900; }
</style>
