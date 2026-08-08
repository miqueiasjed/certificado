<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Metas" description="Acompanhamento do atingimento da equipe por competência.">
        <template #actions>
          <Link v-if="pode('comissoes-apurar')" :href="route('comissoes.index')" class="btn-secondary">Comissões</Link>
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

      <!-- Competência -->
      <Card>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <label for="competencia" class="block text-sm font-medium text-gray-700 mb-1">Competência</label>
            <input id="competencia" v-model="competenciaSelecionada" type="month" class="campo" @change="mudarCompetencia" />
          </div>
          <p v-if="acompanhamento && !acompanhamento.projecao_disponivel" class="text-sm text-gray-500 sm:max-w-xs">
            A projeção para o fim do período aparece a partir do {{ acompanhamento.dia_util_minimo_para_projecao }}º dia útil da competência.
          </p>
        </div>
      </Card>

      <p v-if="erroAcompanhamento" class="text-sm text-red-600">{{ erroAcompanhamento }}</p>

      <!-- Acompanhamento -->
      <Card v-if="acompanhamento && acompanhamento.metas.length === 0">
        <p class="py-8 text-center text-sm text-gray-500">Nenhuma meta cadastrada para esta competência.</p>
      </Card>

      <Card
        v-for="linha in acompanhamento?.metas || []"
        :key="linha.goal_id"
        padding="small"
        :class="metaAtingida(linha) ? 'border-green-200 bg-green-50/60' : ''">
        <div class="flex items-start justify-between gap-3">
          <div>
            <p class="font-semibold text-gray-900">{{ linha.usuario || `Usuário #${linha.user_id}` }}</p>
            <p class="text-sm text-gray-500">{{ TIPO_LABEL[linha.tipo] || linha.tipo }}</p>
          </div>
          <span v-if="metaAtingida(linha)" class="badge bg-green-100 text-green-800">Meta atingida</span>
        </div>

        <div class="mt-3">
          <div class="flex flex-wrap items-center justify-between gap-2 text-sm text-gray-700">
            <span>{{ formatarValorPorTipo(linha.tipo, linha.realizado) }} de {{ formatarValorPorTipo(linha.tipo, linha.alvo) }}</span>
            <span v-if="linha.percentual_atingido !== null" class="font-medium text-gray-900">
              {{ formatarNumero(linha.percentual_atingido, 1) }}%
            </span>
          </div>
          <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-100">
            <div
              class="h-full rounded-full"
              :class="metaAtingida(linha) ? 'bg-green-500' : 'bg-blue-500'"
              :style="{ width: `${Math.min(100, Math.max(0, linha.percentual_atingido || 0))}%` }"></div>
          </div>
        </div>

        <p v-if="linha.tipo === 'conversao' && linha.amostra_insuficiente" class="mt-2 text-xs text-gray-500">
          Amostra insuficiente ({{ linha.enviados }} orçamento(s) enviado(s)) para calcular a conversão com confiança.
        </p>
        <p v-if="linha.projecao !== null && linha.projecao !== undefined" class="mt-2 text-xs text-gray-500">
          Projeção para o fim do período: {{ formatarValorPorTipo(linha.tipo, linha.projecao) }}
        </p>

        <div v-if="pode('meta-gerenciar')" class="mt-3 flex gap-4 border-t border-gray-100 pt-2">
          <button type="button" class="acao" @click="abrirEdicaoMeta(linha)">Editar meta</button>
          <button type="button" class="acao text-red-600 hover:text-red-800" @click="abrirExclusaoMeta(linha)">Excluir</button>
        </div>
      </Card>

      <!-- Criação em lote: só quem gerencia metas -->
      <Card v-if="pode('meta-gerenciar')">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Definir metas em lote</h3>
          <p class="mt-1 text-sm text-gray-500">
            Uma linha por pessoa, para a competência {{ competenciaFormatada(competenciaSelecionada) }}. Reenviar o
            lote atualiza a meta de quem já tinha, sem duplicar.
          </p>
        </div>
        <div class="p-6 space-y-4">
          <div v-for="(linha, indice) in loteForm.metas" :key="indice" class="grid grid-cols-1 gap-3 sm:grid-cols-12 sm:items-end">
            <div class="sm:col-span-3">
              <label class="block text-sm font-medium text-gray-700 mb-1">ID da pessoa</label>
              <input v-model="linha.pessoa_id" type="number" min="1" list="pessoas-conhecidas" class="campo" />
            </div>
            <div class="sm:col-span-3">
              <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de meta</label>
              <select v-model="linha.tipo" class="campo">
                <option v-for="tipo in TIPOS_META" :key="tipo" :value="tipo">{{ TIPO_LABEL[tipo] }}</option>
              </select>
            </div>
            <div class="sm:col-span-2">
              <label class="block text-sm font-medium text-gray-700 mb-1">Alvo</label>
              <input v-model="linha.alvo" type="number" min="0" step="0.01" class="campo" />
            </div>
            <div class="sm:col-span-3">
              <label class="block text-sm font-medium text-gray-700 mb-1">Observação</label>
              <input v-model="linha.observacao" type="text" maxlength="500" class="campo" />
            </div>
            <div class="sm:col-span-1">
              <button
                type="button"
                class="btn-secondary-sm w-full"
                :disabled="loteForm.metas.length === 1"
                @click="removerLinha(indice)">
                Remover
              </button>
            </div>
          </div>

          <datalist id="pessoas-conhecidas">
            <option v-for="p in pessoasConhecidas" :key="p.id" :value="p.id">{{ p.name }}</option>
          </datalist>

          <button type="button" class="btn-secondary" @click="adicionarLinha">Adicionar linha</button>

          <p v-if="primeiroErroLote" class="text-sm text-red-600">{{ primeiroErroLote }}</p>

          <div class="pt-2">
            <button type="button" class="btn-primary" :disabled="loteForm.processing" @click="salvarLote">
              {{ loteForm.processing ? 'Salvando...' : 'Salvar metas em lote' }}
            </button>
          </div>
        </div>
      </Card>
    </div>

    <!-- Editar meta individual -->
    <Modal :show="!!metaEmEdicao" @close="fecharEdicaoMeta">
      <template #title>Editar meta</template>
      <template #content>
        <p class="text-sm text-gray-600 mb-4">
          {{ metaEmEdicao?.usuario }} · {{ TIPO_LABEL[metaEmEdicao?.tipo] || metaEmEdicao?.tipo }}
        </p>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Alvo *</label>
            <input
              v-model="metaForm.alvo"
              type="number"
              min="0"
              step="0.01"
              class="campo"
              :class="{ 'border-red-500': metaForm.errors.alvo }" />
            <p v-if="metaForm.errors.alvo" class="mt-1 text-sm text-red-600">{{ metaForm.errors.alvo }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Observação</label>
            <textarea
              v-model="metaForm.observacao"
              rows="3"
              maxlength="500"
              class="campo"
              :class="{ 'border-red-500': metaForm.errors.observacao }"></textarea>
            <p v-if="metaForm.errors.observacao" class="mt-1 text-sm text-red-600">{{ metaForm.errors.observacao }}</p>
          </div>
        </div>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="metaForm.processing" @click="fecharEdicaoMeta">Cancelar</button>
        <button type="button" class="btn-primary ml-3" :disabled="metaForm.processing" @click="confirmarEdicaoMeta">
          {{ metaForm.processing ? 'Salvando...' : 'Salvar' }}
        </button>
      </template>
    </Modal>

    <ConfirmDeleteModal
      :show="!!metaParaExcluir"
      title="Excluir meta"
      :message="`Tem certeza que deseja excluir a meta de ${metaParaExcluir?.usuario || ''}`"
      :item-name="metaParaExcluir ? TIPO_LABEL[metaParaExcluir.tipo] : ''"
      :processing="excluindoMeta"
      @cancel="metaParaExcluir = null"
      @confirm="confirmarExclusaoMeta" />
  </AuthenticatedLayout>
</template>

<script setup>
/**
 * Acompanhamento e gestão de metas (Task 23.7).
 *
 * `metas` (prop Inertia, de `GoalController::index()`) é a listagem crua,
 * usada aqui só como fonte de `observacao` ao editar (o acompanhamento não
 * traz esse campo) e de pessoas já conhecidas para o lote. `acompanhamento`
 * (alvo, realizado, percentual, projeção) vem de `metas.acompanhamento` via
 * fetch, porque essa rota não é Inertia (ver o contrato da Task 23.6).
 *
 * Esta página só é alcançável com `meta-gerenciar` (middleware da rota
 * `metas.index`); os `v-if="pode('meta-gerenciar')"` abaixo são defesa em
 * profundidade e preparação para o dia em que outra rota expuser este mesmo
 * componente sem essa permissão, mesmo critério de várias telas do projeto
 * que checam a permissão de novo no frontend.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { hojeISO } from '@/utils/formatDate';

const props = defineProps({
  competencia: { type: String, default: null },
  metas: { type: Array, default: () => [] },
});

const { pode } = usePermissoes();

const TIPO_LABEL = {
  valor_vendido: 'Valor vendido',
  valor_recebido: 'Valor recebido',
  quantidade_os: 'Quantidade de OS',
  conversao: 'Taxa de conversão',
};
const TIPOS_META = Object.keys(TIPO_LABEL);

function formatarMoeda(valor) {
  const numero = typeof valor === 'number' ? valor : parseFloat(valor || 0);
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number.isFinite(numero) ? numero : 0);
}

function formatarNumero(valor, casas = 2) {
  return Number(valor ?? 0).toLocaleString('pt-BR', { minimumFractionDigits: casas, maximumFractionDigits: casas });
}

function formatarValorPorTipo(tipo, valor) {
  if (valor === null || valor === undefined) return '-';
  if (tipo === 'quantidade_os') return `${formatarNumero(valor, 0)} OS`;
  if (tipo === 'conversao') return `${formatarNumero(valor, 1)}%`;

  return formatarMoeda(valor);
}

function metaAtingida(linha) {
  return linha.percentual_atingido !== null && linha.percentual_atingido !== undefined && linha.percentual_atingido >= 100;
}

function competenciaFormatada(competencia) {
  if (!competencia) return '';
  const [ano, mes] = competencia.split('-');
  return `${mes}/${ano}`;
}

// -----------------------------------------------------------------
// Competência e acompanhamento (fetch, não é página Inertia)
// -----------------------------------------------------------------

const competenciaSelecionada = ref(props.competencia || hojeISO().slice(0, 7));
const acompanhamento = ref(null);
const erroAcompanhamento = ref('');

async function carregarAcompanhamento() {
  erroAcompanhamento.value = '';

  try {
    const resposta = await fetch(route('metas.acompanhamento', { competencia: competenciaSelecionada.value }), {
      headers: { Accept: 'application/json' },
    });

    if (!resposta.ok) throw new Error('Falha ao carregar o acompanhamento.');

    acompanhamento.value = await resposta.json();
  } catch (erro) {
    erroAcompanhamento.value = 'Não foi possível carregar o acompanhamento desta competência.';
  }
}

function mudarCompetencia() {
  router.get(route('metas.index'), { competencia: competenciaSelecionada.value }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
    onSuccess: carregarAcompanhamento,
  });
}

watch(() => props.competencia, (nova) => {
  if (nova && nova !== competenciaSelecionada.value) {
    competenciaSelecionada.value = nova;
  }
});

onMounted(() => {
  if (!props.competencia) {
    // Primeira carga sem filtro: `GoalController::index()` sem `competencia`
    // devolve o histórico inteiro. Fixa o mês corrente como padrão da tela.
    mudarCompetencia();
  } else {
    carregarAcompanhamento();
  }
});

// -----------------------------------------------------------------
// Pessoas conhecidas (sem endpoint de lista de usuários/técnicos: sugere
// quem já apareceu em alguma meta desta ou de outra competência)
// -----------------------------------------------------------------

const pessoasConhecidas = computed(() => {
  const mapa = new Map();

  props.metas.forEach((meta) => { if (meta.user) mapa.set(meta.user.id, meta.user.name); });
  (acompanhamento.value?.metas || []).forEach((linha) => { if (linha.usuario) mapa.set(linha.user_id, linha.usuario); });

  return Array.from(mapa, ([id, name]) => ({ id, name }));
});

function metaBrutaPeloId(id) {
  return props.metas.find((meta) => meta.id === id) || null;
}

// -----------------------------------------------------------------
// Criação em lote
// -----------------------------------------------------------------

function criarLinhaLote() {
  return { pessoa_id: '', tipo: 'valor_vendido', alvo: '', observacao: '' };
}

const loteForm = useForm({ metas: [criarLinhaLote()] });

const primeiroErroLote = computed(() => Object.values(loteForm.errors)[0] || null);

function adicionarLinha() {
  loteForm.metas.push(criarLinhaLote());
}

function removerLinha(indice) {
  if (loteForm.metas.length === 1) return;
  loteForm.metas.splice(indice, 1);
}

function salvarLote() {
  loteForm
    .transform((dados) => ({
      competencia: competenciaSelecionada.value,
      metas: dados.metas
        .filter((linha) => linha.pessoa_id !== '' && linha.alvo !== '')
        .map((linha) => ({
          user_id: Number(linha.pessoa_id),
          tipo: linha.tipo,
          alvo: linha.alvo,
          observacao: linha.observacao || null,
        })),
    }))
    .post(route('metas.lote'), {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => {
        loteForm.reset();
        carregarAcompanhamento();
      },
    });
}

// -----------------------------------------------------------------
// Editar/excluir uma meta
// -----------------------------------------------------------------

const metaForm = useForm({ alvo: '', observacao: '' });
const metaEmEdicao = ref(null);

function abrirEdicaoMeta(linha) {
  const bruta = metaBrutaPeloId(linha.goal_id);

  metaEmEdicao.value = { id: linha.goal_id, usuario: linha.usuario, tipo: linha.tipo };
  metaForm.defaults({ alvo: bruta?.alvo ?? linha.alvo, observacao: bruta?.observacao || '' });
  metaForm.reset();
  metaForm.clearErrors();
}

function fecharEdicaoMeta() {
  if (metaForm.processing) return;
  metaEmEdicao.value = null;
}

function confirmarEdicaoMeta() {
  metaForm.put(route('metas.update', metaEmEdicao.value.id), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      metaEmEdicao.value = null;
      carregarAcompanhamento();
    },
  });
}

const metaParaExcluir = ref(null);
const excluindoMeta = ref(false);

function abrirExclusaoMeta(linha) {
  metaParaExcluir.value = { id: linha.goal_id, usuario: linha.usuario, tipo: linha.tipo };
}

function confirmarExclusaoMeta() {
  excluindoMeta.value = true;

  router.delete(route('metas.destroy', metaParaExcluir.value.id), {
    preserveScroll: true,
    onSuccess: () => { carregarAcompanhamento(); },
    onFinish: () => {
      excluindoMeta.value = false;
      metaParaExcluir.value = null;
    },
  });
}
</script>

<style scoped>
.campo { @apply w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm; }
.badge { @apply inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium; }
.acao { @apply text-sm font-medium text-green-700 hover:text-green-900; }
</style>
