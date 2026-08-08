<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Regras de comissão" description="Percentual ou valor fixo por vendedor, por técnico ou geral da empresa, com vigência.">
        <template #actions>
          <Link :href="route('comissoes.index')" class="btn-secondary">Voltar para comissões</Link>
          <button type="button" class="btn-primary" @click="abrirCriacao">Nova regra</button>
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

      <Card v-if="regras.length === 0">
        <p class="py-8 text-center text-sm text-gray-500">Nenhuma regra de comissão cadastrada ainda.</p>
      </Card>

      <!-- Cartões: telas pequenas -->
      <div v-else class="space-y-3 md:hidden">
        <Card v-for="regra in regras" :key="regra.id" padding="small">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate font-semibold text-gray-900">{{ pessoaLabel(regra) }}</p>
              <p class="text-sm text-gray-600">{{ TIPO_LABEL[regra.tipo] }} · {{ BASE_LABEL[regra.base] }}</p>
            </div>
            <span class="badge" :class="regra.ativa ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'">
              {{ regra.ativa ? 'Ativa' : 'Inativa' }}
            </span>
          </div>
          <dl class="mt-3 grid grid-cols-2 gap-3 text-sm">
            <div><dt class="text-gray-500">Valor</dt><dd class="font-medium text-gray-900">{{ valorFormatado(regra) }}</dd></div>
            <div><dt class="text-gray-500">Serviço</dt><dd class="font-medium text-gray-900">{{ servicoLabel(regra) }}</dd></div>
            <div class="col-span-2"><dt class="text-gray-500">Vigência</dt><dd class="font-medium text-gray-900">{{ vigenciaFormatada(regra) }}</dd></div>
          </dl>
          <div class="mt-3 flex gap-4 border-t border-gray-100 pt-3">
            <button type="button" class="acao" @click="abrirEdicao(regra)">Editar</button>
            <button type="button" class="acao text-red-600 hover:text-red-800" @click="abrirExclusao(regra)">Excluir</button>
          </div>
        </Card>
      </div>

      <!-- Tabela: telas médias/grandes -->
      <Card v-if="regras.length > 0" padding="none" class="hidden md:block">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="cabecalho">Pessoa</th>
                <th class="cabecalho">Tipo</th>
                <th class="cabecalho">Base</th>
                <th class="cabecalho text-right">Valor</th>
                <th class="cabecalho">Serviço</th>
                <th class="cabecalho">Vigência</th>
                <th class="cabecalho">Situação</th>
                <th class="cabecalho text-right">Ações</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
              <tr v-for="regra in regras" :key="regra.id" class="hover:bg-gray-50">
                <td class="celula font-medium text-gray-900">{{ pessoaLabel(regra) }}</td>
                <td class="celula text-gray-700">{{ TIPO_LABEL[regra.tipo] }}</td>
                <td class="celula text-gray-700">{{ BASE_LABEL[regra.base] }}</td>
                <td class="celula text-right text-gray-700">{{ valorFormatado(regra) }}</td>
                <td class="celula text-gray-700">{{ servicoLabel(regra) }}</td>
                <td class="celula text-gray-700">{{ vigenciaFormatada(regra) }}</td>
                <td class="celula">
                  <span class="badge" :class="regra.ativa ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'">
                    {{ regra.ativa ? 'Ativa' : 'Inativa' }}
                  </span>
                </td>
                <td class="celula text-right">
                  <div class="flex justify-end gap-4">
                    <button type="button" class="acao" @click="abrirEdicao(regra)">Editar</button>
                    <button type="button" class="acao text-red-600 hover:text-red-800" @click="abrirExclusao(regra)">Excluir</button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>
    </div>

    <!-- Criar/editar: mesmo formulário para os dois casos -->
    <Modal :show="mostrarFormulario" @close="fecharFormulario">
      <template #title>{{ regraEmEdicao ? 'Editar regra de comissão' : 'Nova regra de comissão' }}</template>
      <template #content>
        <div class="space-y-4">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tipo *</label>
              <select v-model="form.tipo" class="campo" :class="{ 'border-red-500': form.errors.tipo }">
                <option value="vendedor">Vendedor</option>
                <option value="tecnico">Técnico</option>
              </select>
              <p v-if="form.errors.tipo" class="mt-1 text-sm text-red-600">{{ form.errors.tipo }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Base de cálculo *</label>
              <select v-model="form.base" class="campo" :class="{ 'border-red-500': form.errors.base }">
                <option value="recebido">Recebido (padrão)</option>
                <option value="vendido">Vendido</option>
                <option value="executado">Executado</option>
              </select>
              <p v-if="form.errors.base" class="mt-1 text-sm text-red-600">{{ form.errors.base }}</p>
            </div>
          </div>

          <label class="flex items-center gap-2">
            <input v-model="form.regra_geral" type="checkbox" class="rounded border-gray-300 text-green-600 focus:ring-green-500" />
            <span class="text-sm text-gray-700">
              Regra geral da empresa (aplica a toda pessoa deste tipo sem regra própria)
            </span>
          </label>

          <div v-if="!form.regra_geral">
            <label class="block text-sm font-medium text-gray-700 mb-1">
              ID {{ form.tipo === 'vendedor' ? 'do vendedor' : 'do técnico' }} *
            </label>
            <input
              v-model="form.pessoa_id"
              type="number"
              min="1"
              list="pessoas-conhecidas"
              class="campo"
              :class="{ 'border-red-500': erroPessoa }" />
            <datalist id="pessoas-conhecidas">
              <option v-for="p in pessoasConhecidas" :key="p.id" :value="p.id">{{ p.name }}</option>
            </datalist>
            <p class="mt-1 text-xs text-gray-500">Sugestões vêm de pessoas já usadas em outra regra.</p>
            <p v-if="erroPessoa" class="mt-1 text-sm text-red-600">{{ erroPessoa }}</p>
          </div>

          <p v-if="regraAnteriorExistente" class="rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
            Já existe uma regra ativa para esta combinação de pessoa e base. Ela continua valendo para os fatos
            ocorridos antes do início de vigência desta nova regra: comissão já apurada não é recalculada
            retroativamente.
          </p>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Forma *</label>
              <select v-model="form.forma" class="campo" :class="{ 'border-red-500': form.errors.forma }">
                <option value="percentual">Percentual</option>
                <option value="valor_fixo">Valor fixo</option>
              </select>
              <p v-if="form.errors.forma" class="mt-1 text-sm text-red-600">{{ form.errors.forma }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                Valor * {{ form.forma === 'percentual' ? '(%)' : '(R$)' }}
              </label>
              <input
                v-model="form.valor"
                type="number"
                min="0"
                step="0.01"
                class="campo"
                :class="{ 'border-red-500': form.errors.valor }" />
              <p v-if="form.errors.valor" class="mt-1 text-sm text-red-600">{{ form.errors.valor }}</p>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">ID do tipo de serviço (opcional)</label>
            <input
              v-model="form.service_type_id"
              type="number"
              min="1"
              class="campo"
              :class="{ 'border-red-500': form.errors.service_type_id }"
              placeholder="Em branco vale para qualquer serviço" />
            <p v-if="form.errors.service_type_id" class="mt-1 text-sm text-red-600">{{ form.errors.service_type_id }}</p>
          </div>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Início da vigência *</label>
              <input
                v-model="form.vigencia_inicio"
                type="date"
                class="campo"
                :class="{ 'border-red-500': form.errors.vigencia_inicio }" />
              <p v-if="form.errors.vigencia_inicio" class="mt-1 text-sm text-red-600">{{ form.errors.vigencia_inicio }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Fim da vigência (opcional)</label>
              <input
                v-model="form.vigencia_fim"
                type="date"
                class="campo"
                :class="{ 'border-red-500': form.errors.vigencia_fim }" />
              <p v-if="form.errors.vigencia_fim" class="mt-1 text-sm text-red-600">{{ form.errors.vigencia_fim }}</p>
            </div>
          </div>

          <label class="flex items-center gap-2">
            <input v-model="form.ativa" type="checkbox" class="rounded border-gray-300 text-green-600 focus:ring-green-500" />
            <span class="text-sm text-gray-700">Regra ativa</span>
          </label>
        </div>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="form.processing" @click="fecharFormulario">Cancelar</button>
        <button type="button" class="btn-primary ml-3" :disabled="form.processing" @click="salvar">
          {{ form.processing ? 'Salvando...' : 'Salvar' }}
        </button>
      </template>
    </Modal>

    <ConfirmDeleteModal
      :show="!!regraParaExcluir"
      title="Excluir regra de comissão"
      message="Tem certeza que deseja excluir esta regra? Comissões já apuradas com ela não são alteradas."
      :processing="excluindo"
      @cancel="regraParaExcluir = null"
      @confirm="confirmarExclusao" />
  </AuthenticatedLayout>
</template>

<script setup>
/**
 * Cadastro de regra de comissão (Task 23.7). O backend
 * (`CommissionRuleController::index()`) não envia lista de usuários/técnicos
 * cadastrados - a tela sugere pessoas já usadas em outra regra por
 * `<datalist>`, e aceita o ID de uma pessoa nova digitado à mão. É o mesmo
 * padrão que o próprio backend já usa para `supplier_id` no pagamento de
 * comissão (Task 23.6): ID de um cadastro já existente, sem seletor.
 */
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import { formatarData, paraInputDate, hojeISO } from '@/utils/formatDate';

const props = defineProps({
  regras: { type: Array, default: () => [] },
});

const TIPO_LABEL = { vendedor: 'Vendedor', tecnico: 'Técnico' };
const BASE_LABEL = { recebido: 'Recebido', vendido: 'Vendido', executado: 'Executado' };

function pessoaLabel(regra) {
  return regra.user?.name || regra.technician?.name || 'Geral (todas as pessoas deste tipo)';
}

function servicoLabel(regra) {
  // Eloquent snake-caseia a chave da relação no JSON (`serviceType()` vira
  // `service_type`), diferente de `user`/`technician`, que já são
  // minúsculos e não mudam - conferido empiricamente via tinker.
  return regra.service_type?.name || 'Todos os serviços';
}

function valorFormatado(regra) {
  if (regra.forma === 'percentual') {
    return `${Number(regra.valor).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}%`;
  }

  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(regra.valor));
}

function vigenciaFormatada(regra) {
  const inicio = formatarData(regra.vigencia_inicio);
  const fim = regra.vigencia_fim ? formatarData(regra.vigencia_fim) : 'sem data de fim';

  return `${inicio} até ${fim}`;
}

// -----------------------------------------------------------------
// Formulário de criação/edição
// -----------------------------------------------------------------

function valoresPadrao() {
  return {
    tipo: 'vendedor',
    regra_geral: false,
    pessoa_id: '',
    base: 'recebido',
    forma: 'percentual',
    valor: '',
    service_type_id: '',
    vigencia_inicio: hojeISO(),
    vigencia_fim: '',
    ativa: true,
  };
}

const form = useForm(valoresPadrao());
const mostrarFormulario = ref(false);
const regraEmEdicao = ref(null);

// user_id/technician_id não têm campo próprio no formulário (pessoa_id decide
// qual dos dois enviar, conforme o tipo) - por isso o erro de qualquer um dos
// dois é mostrado junto do campo de pessoa.
const erroPessoa = computed(() => form.errors.user_id || form.errors.technician_id);

const pessoasConhecidas = computed(() => {
  const mapa = new Map();

  props.regras.forEach((regra) => {
    if (form.tipo === 'vendedor' && regra.user) mapa.set(regra.user.id, regra.user.name);
    if (form.tipo === 'tecnico' && regra.technician) mapa.set(regra.technician.id, regra.technician.name);
  });

  return Array.from(mapa, ([id, name]) => ({ id, name }));
});

// Frase informativa (Task 23.7, item 4): ao criar, avisa que já existe regra
// ativa para a mesma pessoa/base - sem calcular sobreposição de vigência.
const regraAnteriorExistente = computed(() => {
  if (regraEmEdicao.value) return false;

  return props.regras.some((regra) => {
    if (regra.tipo !== form.tipo || regra.base !== form.base || !regra.ativa) return false;

    if (form.regra_geral) return regra.user_id === null && regra.technician_id === null;

    const pessoaId = Number(form.pessoa_id);

    if (!pessoaId) return false;

    return form.tipo === 'vendedor' ? regra.user_id === pessoaId : regra.technician_id === pessoaId;
  });
});

function abrirCriacao() {
  regraEmEdicao.value = null;
  form.defaults(valoresPadrao());
  form.reset();
  form.clearErrors();
  mostrarFormulario.value = true;
}

function abrirEdicao(regra) {
  regraEmEdicao.value = regra;

  form.defaults({
    tipo: regra.tipo,
    regra_geral: regra.user_id === null && regra.technician_id === null,
    pessoa_id: regra.user_id ?? regra.technician_id ?? '',
    base: regra.base,
    forma: regra.forma,
    valor: regra.valor,
    service_type_id: regra.service_type_id ?? '',
    vigencia_inicio: paraInputDate(regra.vigencia_inicio),
    vigencia_fim: regra.vigencia_fim ? paraInputDate(regra.vigencia_fim) : '',
    ativa: !!regra.ativa,
  });
  form.reset();
  form.clearErrors();
  mostrarFormulario.value = true;
}

function fecharFormulario() {
  if (form.processing) return;
  mostrarFormulario.value = false;
  regraEmEdicao.value = null;
  form.clearErrors();
}

function salvar() {
  form.transform((dados) => ({
    tipo: dados.tipo,
    user_id: dados.tipo === 'vendedor' && !dados.regra_geral ? Number(dados.pessoa_id) : null,
    technician_id: dados.tipo === 'tecnico' && !dados.regra_geral ? Number(dados.pessoa_id) : null,
    base: dados.base,
    forma: dados.forma,
    valor: dados.valor,
    service_type_id: dados.service_type_id || null,
    vigencia_inicio: dados.vigencia_inicio,
    vigencia_fim: dados.vigencia_fim || null,
    ativa: dados.ativa,
  }));

  const opcoes = { preserveScroll: true, preserveState: true, onSuccess: fecharFormulario };

  if (regraEmEdicao.value) {
    form.put(route('comissoes.regras.update', regraEmEdicao.value.id), opcoes);
  } else {
    form.post(route('comissoes.regras.store'), opcoes);
  }
}

// -----------------------------------------------------------------
// Exclusão
// -----------------------------------------------------------------

const regraParaExcluir = ref(null);
const excluindo = ref(false);

function abrirExclusao(regra) {
  regraParaExcluir.value = regra;
}

function confirmarExclusao() {
  excluindo.value = true;

  router.delete(route('comissoes.regras.destroy', regraParaExcluir.value.id), {
    preserveScroll: true,
    onFinish: () => {
      excluindo.value = false;
      regraParaExcluir.value = null;
    },
  });
}
</script>

<style scoped>
.campo { @apply w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm; }
.cabecalho { @apply px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500; }
.celula { @apply whitespace-nowrap px-4 py-4 text-sm; }
.badge { @apply inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium; }
.acao { @apply text-sm font-medium text-green-700 hover:text-green-900; }
</style>
