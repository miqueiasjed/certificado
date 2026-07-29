<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Lotes" description="Cadastro de lotes: validade, custo unitário e fornecedor.">
        <template #actions>
          <Link :href="route('estoque.index')" class="btn-secondary">Ver saldo de estoque</Link>
          <button type="button" class="btn-primary" @click="abrirCriacao">Novo lote</button>
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
      <div
        v-if="avisoLocal.texto"
        class="rounded-md p-4 border"
        :class="avisoLocal.tipo === 'erro' ? 'bg-red-50 border-red-200' : 'bg-green-50 border-green-200'"
      >
        <p class="text-sm font-medium" :class="avisoLocal.tipo === 'erro' ? 'text-red-800' : 'text-green-800'">
          {{ avisoLocal.texto }}
        </p>
      </div>

      <!-- Filtro por produto -->
      <Card padding="small">
        <div class="flex flex-col sm:flex-row sm:items-center gap-3">
          <label class="text-sm font-medium text-gray-700 shrink-0">Produto</label>
          <select
            v-model="filtroProduto"
            @change="aplicarFiltro"
            class="w-full sm:w-72 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          >
            <option value="">Todos os produtos</option>
            <option v-for="produto in produtos" :key="produto.id" :value="produto.id">{{ produto.nome }}</option>
          </select>
        </div>
      </Card>

      <!-- Lista de lotes, ordenada por validade crescente (vem assim do backend) -->
      <Card padding="none">
        <div v-if="lotes.length === 0" class="p-8 text-center text-sm text-gray-500">
          Nenhum lote cadastrado{{ filtroProduto ? ' para este produto' : '' }}.
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Produto</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Lote</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Validade</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Recebido em</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Custo unitário</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Fornecedor</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Saldo</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="lote in lotes" :key="lote.id" class="hover:bg-gray-50">
                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900">{{ lote.produto }}</td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900">{{ lote.lote }}</td>
                <td class="px-4 py-4 whitespace-nowrap text-sm">
                  <span :class="lote.vencido ? 'text-red-600 font-medium' : 'text-gray-900'">{{ formatarData(lote.validade) }}</span>
                  <span v-if="lote.vencido" class="ml-2 inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                    Vencido
                  </span>
                </td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700">{{ formatarData(lote.recebido_em) }}</td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700">
                  {{ formatarMoeda(lote.custo_unitario) }}/{{ lote.unidade }}
                </td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-700">{{ lote.fornecedor || '-' }}</td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                  {{ formatarQuantidade(lote.saldo) }} {{ lote.unidade }}
                </td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-right">
                  <div class="flex items-center justify-end gap-3">
                    <Link :href="route('lotes.rastreabilidade', lote.id)" class="text-gray-600 hover:text-gray-900 transition-colors" title="Rastreabilidade">
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                      </svg>
                    </Link>
                    <button type="button" class="text-blue-600 hover:text-blue-900 transition-colors" title="Editar" @click="abrirEdicao(lote)">
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                      </svg>
                    </button>
                    <button type="button" class="text-red-600 hover:text-red-900 transition-colors" title="Excluir" @click="pedirExclusao(lote)">
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>
    </div>

    <!-- Cadastro / edição de lote -->
    <Modal :show="mostrarFormulario" @close="fecharFormulario">
      <template #title>{{ loteEmEdicao ? `Editar lote ${loteEmEdicao.lote}` : 'Novo lote' }}</template>
      <template #content>
        <div class="space-y-4 text-left">
          <div v-if="loteEmEdicao">
            <label class="block text-sm font-medium text-gray-700 mb-1">Produto</label>
            <p class="text-sm text-gray-900 px-3 py-2 bg-gray-50 border border-gray-200 rounded-md">{{ loteEmEdicao.produto }}</p>
            <p class="mt-1 text-xs text-gray-500">O produto de um lote não muda depois de cadastrado.</p>
          </div>
          <div v-else>
            <label for="lote_produto" class="block text-sm font-medium text-gray-700 mb-1">Produto *</label>
            <select
              id="lote_produto"
              v-model="form.product_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.product_id }"
            >
              <option value="">Selecione um produto</option>
              <option v-for="produto in produtos" :key="produto.id" :value="produto.id">{{ produto.nome }}</option>
            </select>
            <p v-if="form.errors.product_id" class="mt-1 text-sm text-red-600">{{ form.errors.product_id }}</p>
          </div>

          <div>
            <label for="lote_numero" class="block text-sm font-medium text-gray-700 mb-1">Número do lote *</label>
            <input
              id="lote_numero"
              v-model="form.lote"
              type="text"
              maxlength="100"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.lote }"
            />
            <p v-if="form.errors.lote" class="mt-1 text-sm text-red-600">{{ form.errors.lote }}</p>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label for="lote_validade" class="block text-sm font-medium text-gray-700 mb-1">Validade *</label>
              <input
                id="lote_validade"
                v-model="form.validade"
                type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.validade }"
              />
              <p v-if="form.errors.validade" class="mt-1 text-sm text-red-600">{{ form.errors.validade }}</p>
            </div>
            <div>
              <label for="lote_recebido_em" class="block text-sm font-medium text-gray-700 mb-1">Recebido em *</label>
              <input
                id="lote_recebido_em"
                v-model="form.recebido_em"
                type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.recebido_em }"
              />
              <p v-if="form.errors.recebido_em" class="mt-1 text-sm text-red-600">{{ form.errors.recebido_em }}</p>
            </div>
          </div>

          <div>
            <label for="lote_custo" class="block text-sm font-medium text-gray-700 mb-1">Custo unitário *</label>
            <input
              id="lote_custo"
              v-model="form.custo_unitario"
              type="number"
              step="0.0001"
              min="0"
              class="w-full sm:w-48 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.custo_unitario }"
            />
            <p class="mt-1 text-xs text-gray-500">Dado interno de custo: nunca aparece para o cliente.</p>
            <p v-if="form.errors.custo_unitario" class="mt-1 text-sm text-red-600">{{ form.errors.custo_unitario }}</p>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
              <label for="lote_fornecedor" class="block text-sm font-medium text-gray-700 mb-1">Fornecedor</label>
              <input
                id="lote_fornecedor"
                v-model="form.fornecedor"
                type="text"
                maxlength="255"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              />
            </div>
            <div>
              <label for="lote_nota_fiscal" class="block text-sm font-medium text-gray-700 mb-1">Nota fiscal</label>
              <input
                id="lote_nota_fiscal"
                v-model="form.nota_fiscal"
                type="text"
                maxlength="255"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              />
            </div>
          </div>

          <!-- Entrada inicial, só no cadastro -->
          <div v-if="!loteEmEdicao" class="border-t border-gray-200 pt-4">
            <h4 class="text-sm font-medium text-gray-900 mb-1">Entrada inicial (opcional)</h4>
            <p class="text-xs text-gray-500 mb-3">
              Preenchendo a quantidade e o local, o saldo deste lote já entra assim que ele for cadastrado.
            </p>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div>
                <label for="lote_quantidade" class="block text-sm font-medium text-gray-700 mb-1">Quantidade</label>
                <input
                  id="lote_quantidade"
                  v-model="form.quantidade"
                  type="number"
                  step="0.0001"
                  min="0.0001"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.quantidade }"
                />
                <p v-if="form.errors.quantidade" class="mt-1 text-sm text-red-600">{{ form.errors.quantidade }}</p>
              </div>
              <div>
                <label for="lote_local" class="block text-sm font-medium text-gray-700 mb-1">Local</label>
                <select
                  id="lote_local"
                  v-model="form.stock_location_id"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.stock_location_id }"
                >
                  <option value="">Selecione um local</option>
                  <option v-for="local in locais" :key="local.id" :value="local.id">{{ local.nome }}</option>
                </select>
                <p v-if="form.errors.stock_location_id" class="mt-1 text-sm text-red-600">{{ form.errors.stock_location_id }}</p>
              </div>
            </div>
            <p v-if="form.quantidade && !form.stock_location_id" class="mt-2 text-sm text-red-600">
              Informe o local que vai receber a entrada inicial.
            </p>
          </div>
        </div>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="form.processing" @click="fecharFormulario">Cancelar</button>
        <button type="button" class="btn-primary" :disabled="form.processing || !formValido" @click="enviarFormulario">
          {{ form.processing ? 'Salvando...' : (loteEmEdicao ? 'Salvar alterações' : 'Cadastrar lote') }}
        </button>
      </template>
    </Modal>

    <!-- Confirmação de exclusão -->
    <ConfirmDeleteModal
      :show="!!loteParaExcluir"
      title="Excluir lote"
      message="Tem certeza que deseja excluir o lote"
      :item-name="loteParaExcluir?.lote || ''"
      :processing="excluindo"
      @confirm="confirmarExclusao"
      @cancel="loteParaExcluir = null"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import { formatarData, paraInputDate, hojeISO } from '@/utils/formatDate';

const props = defineProps({
  filtros: Object,
  lotes: Array,
  produtos: Array,
  locais: Array,
});

const formatarQuantidade = (valor) => {
  if (valor === null || valor === undefined) return '-';

  return Number(valor).toLocaleString('pt-BR', { maximumFractionDigits: 4 });
};

const formatarMoeda = (valor) => {
  if (valor === null || valor === undefined) return '-';

  return Number(valor).toLocaleString('pt-BR', {
    style: 'currency',
    currency: 'BRL',
    minimumFractionDigits: 2,
    maximumFractionDigits: 4,
  });
};

const avisoLocal = ref({ tipo: '', texto: '' });

// -----------------------------------------------------------------
// Filtro por produto
// -----------------------------------------------------------------

const filtroProduto = ref(props.filtros.product_id ? String(props.filtros.product_id) : '');

const aplicarFiltro = () => {
  router.get(
    route('lotes.index'),
    filtroProduto.value ? { product_id: filtroProduto.value } : {},
    { preserveState: true, replace: true }
  );
};

// -----------------------------------------------------------------
// Cadastro e edição
// -----------------------------------------------------------------

const mostrarFormulario = ref(false);
const loteEmEdicao = ref(null);

const form = useForm({
  product_id: '',
  lote: '',
  validade: '',
  recebido_em: '',
  custo_unitario: '',
  fornecedor: '',
  nota_fiscal: '',
  quantidade: '',
  stock_location_id: '',
});

const formValido = computed(() => {
  // A entrada inicial exige local quando a quantidade é informada, mesma
  // regra do `ProductBatchRequest`. Não bloqueia o resto do formulário.
  if (form.quantidade && !form.stock_location_id) return false;

  return true;
});

const abrirCriacao = () => {
  loteEmEdicao.value = null;
  form.clearErrors();
  form.reset();
  form.recebido_em = hojeISO();
  mostrarFormulario.value = true;
};

const abrirEdicao = (lote) => {
  loteEmEdicao.value = lote;
  form.clearErrors();
  form.product_id = lote.product_id;
  form.lote = lote.lote;
  form.validade = paraInputDate(lote.validade);
  form.recebido_em = paraInputDate(lote.recebido_em);
  form.custo_unitario = lote.custo_unitario;
  form.fornecedor = lote.fornecedor || '';
  form.nota_fiscal = lote.nota_fiscal || '';
  form.quantidade = '';
  form.stock_location_id = '';
  mostrarFormulario.value = true;
};

const fecharFormulario = () => {
  if (form.processing) return;
  mostrarFormulario.value = false;
  loteEmEdicao.value = null;
};

const enviarFormulario = () => {
  if (!formValido.value) return;

  if (loteEmEdicao.value) {
    form.put(route('lotes.update', loteEmEdicao.value.id), {
      preserveScroll: true,
      onSuccess: () => fecharFormulario(),
    });
  } else {
    form.post(route('lotes.store'), {
      preserveScroll: true,
      onSuccess: () => fecharFormulario(),
    });
  }
};

// -----------------------------------------------------------------
// Exclusão (com o modal de confirmação do projeto, nunca confirm() nativo)
// -----------------------------------------------------------------

const loteParaExcluir = ref(null);
const excluindo = ref(false);

const pedirExclusao = (lote) => {
  loteParaExcluir.value = lote;
};

const cabecalhosJson = () => ({
  'Content-Type': 'application/json',
  Accept: 'application/json',
  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
});

const confirmarExclusao = async () => {
  if (!loteParaExcluir.value) return;

  excluindo.value = true;

  try {
    const resposta = await fetch(route('lotes.destroy', loteParaExcluir.value.id), {
      method: 'DELETE',
      headers: cabecalhosJson(),
    });

    const dados = await resposta.json().catch(() => null);

    if (!resposta.ok) {
      // Lote com movimento (422): a mensagem exata vem do backend, sem
      // reimplementar a regra aqui.
      avisoLocal.value = {
        tipo: 'erro',
        texto: dados?.message || 'Não foi possível excluir este lote.',
      };
      return;
    }

    avisoLocal.value = { tipo: 'sucesso', texto: dados?.message || 'Lote excluído.' };
    router.reload({ only: ['lotes'] });
  } catch (e) {
    avisoLocal.value = { tipo: 'erro', texto: 'Não foi possível conectar ao servidor. Tente novamente.' };
  } finally {
    excluindo.value = false;
    loteParaExcluir.value = null;
  }
};
</script>
