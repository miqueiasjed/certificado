<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Plano de Contas" description="Categorias de receita e de despesa usadas para classificar títulos.">
        <template #actions>
          <button type="button" class="btn-secondary" @click="abrirCriacao('receita')">Nova categoria de receita</button>
          <button type="button" class="btn-primary" @click="abrirCriacao('despesa')">Nova categoria de despesa</button>
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

      <!-- Categorias de receita e de despesa, mesma tabela para os dois grupos -->
      <Card v-for="grupo in grupos" :key="grupo.tipo" padding="none">
        <div class="px-4 py-3 border-b border-gray-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold text-gray-900">{{ grupo.titulo }}</h3>
          <button type="button" class="text-sm text-green-700 hover:text-green-900 font-medium" @click="abrirCriacao(grupo.tipo)">
            + Nova categoria
          </button>
        </div>

        <div v-if="grupo.linhas.length === 0" class="p-6 text-center text-sm text-gray-500">
          Nenhuma categoria de {{ grupo.titulo.toLowerCase() }} cadastrada.
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="linha in grupo.linhas" :key="linha.id" class="hover:bg-gray-50">
                <td class="px-4 py-3 text-sm text-gray-900" :style="{ paddingLeft: `${1 + linha.nivel * 1.5}rem` }">
                  <span class="font-mono text-xs text-gray-400 mr-2">{{ linha.codigo }}</span>
                  <span>{{ linha.nome }}</span>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-right">
                  <span
                    v-if="linha.titulos_vinculados > 0"
                    class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-700"
                  >
                    {{ linha.titulos_vinculados }} título(s)
                  </span>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-right">
                  <span
                    class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium"
                    :class="linha.ativo ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'"
                  >
                    {{ linha.ativo ? 'Ativo' : 'Inativo' }}
                  </span>
                </td>
                <td class="px-4 py-3 whitespace-nowrap text-right text-sm">
                  <div class="flex items-center justify-end gap-3">
                    <button
                      type="button"
                      class="text-gray-500 hover:text-gray-800 transition-colors"
                      title="Nova subcategoria"
                      @click="abrirCriacao(linha.tipo, linha.id)"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                      </svg>
                    </button>
                    <button
                      type="button"
                      class="text-blue-600 hover:text-blue-900 transition-colors"
                      title="Editar"
                      @click="abrirEdicao(linha)"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                      </svg>
                    </button>
                    <button
                      type="button"
                      class="text-gray-600 hover:text-gray-900 transition-colors disabled:opacity-50"
                      :title="linha.ativo ? 'Desativar' : 'Ativar'"
                      :disabled="alternandoAtivo === linha.id"
                      @click="alternarAtivo(linha)"
                    >
                      <svg v-if="linha.ativo" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.36 6.64a9 9 0 11-12.73 0M12 3v9" />
                      </svg>
                      <svg v-else class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                      </svg>
                    </button>
                    <button
                      type="button"
                      class="text-red-600 hover:text-red-900 transition-colors"
                      title="Excluir"
                      @click="pedirExclusao(linha)"
                    >
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

    <!-- Criação / edição -->
    <Modal :show="mostrarFormulario" @close="fecharFormulario">
      <template #title>{{ categoriaEmEdicao ? 'Editar categoria' : 'Nova categoria' }}</template>
      <template #content>
        <div class="space-y-4 text-left">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Código *</label>
            <input
              v-model="form.codigo"
              type="text"
              maxlength="20"
              class="w-full sm:w-48 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.codigo }"
            />
            <p v-if="form.errors.codigo" class="mt-1 text-sm text-red-600">{{ form.errors.codigo }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nome *</label>
            <input
              v-model="form.nome"
              type="text"
              maxlength="255"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.nome }"
            />
            <p v-if="form.errors.nome" class="mt-1 text-sm text-red-600">{{ form.errors.nome }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Categoria-mãe</label>
            <select
              v-model="form.parent_id"
              @change="aoSelecionarMae"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.parent_id }"
            >
              <option value="">Nenhuma (categoria raiz)</option>
              <option v-for="opcao in opcoesDeMae" :key="opcao.id" :value="String(opcao.id)">{{ opcao.codigo }} - {{ opcao.nome }}</option>
            </select>
            <p class="mt-1 text-xs text-gray-500">Subcategoria herda sempre o tipo da categoria-mãe.</p>
            <p v-if="form.errors.parent_id" class="mt-1 text-sm text-red-600">{{ form.errors.parent_id }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo *</label>
            <select
              v-model="form.tipo"
              :disabled="!!form.parent_id"
              class="w-full sm:w-48 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 disabled:bg-gray-100 disabled:text-gray-500"
              :class="{ 'border-red-500': form.errors.tipo }"
            >
              <option value="receita">Receita</option>
              <option value="despesa">Despesa</option>
            </select>
            <p v-if="form.errors.tipo" class="mt-1 text-sm text-red-600">{{ form.errors.tipo }}</p>
          </div>
        </div>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="form.processing" @click="fecharFormulario">Cancelar</button>
        <button type="button" class="btn-primary" :disabled="form.processing" @click="enviarFormulario">
          {{ form.processing ? 'Salvando...' : (categoriaEmEdicao ? 'Salvar alterações' : 'Cadastrar categoria') }}
        </button>
      </template>
    </Modal>

    <!-- Explicação: categoria em uso ou com subcategoria não é excluída -->
    <Modal :show="!!categoriaBloqueada" @close="categoriaBloqueada = null">
      <template #icon>
        <svg class="h-6 w-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.228 9c.549-1.165 2.03-2 3.772-2 2.21 0 4 1.343 4 3 0 1.4-1.278 2.575-3.006 2.907-.542.104-.994.54-.994 1.093m0 3h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </template>
      <template #title>Esta categoria não pode ser excluída</template>
      <template #content>
        <p class="text-sm text-gray-700">{{ motivoBloqueio }}</p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" @click="categoriaBloqueada = null">Entendi</button>
        <button
          v-if="categoriaBloqueada?.ativo"
          type="button"
          class="btn-primary"
          :disabled="alternandoAtivo === categoriaBloqueada?.id"
          @click="desativarAPartirDoBloqueio"
        >
          Desativar agora
        </button>
      </template>
    </Modal>

    <!-- Exclusão de categoria sem uso -->
    <ConfirmDeleteModal
      :show="!!categoriaParaExcluir"
      title="Excluir categoria"
      message="Tem certeza que deseja excluir a categoria"
      :item-name="categoriaParaExcluir ? `${categoriaParaExcluir.codigo} - ${categoriaParaExcluir.nome}` : ''"
      :processing="excluindo"
      @confirm="confirmarExclusao"
      @cancel="categoriaParaExcluir = null"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';

const props = defineProps({
  contas: Array,
});

// -----------------------------------------------------------------
// Achatamento da árvore para exibição em tabela (sem componente recursivo)
// -----------------------------------------------------------------

function achatar(nos, nivel = 0) {
  const linhas = [];

  for (const no of nos) {
    linhas.push({ ...no, nivel });

    if (no.filhos && no.filhos.length) {
      linhas.push(...achatar(no.filhos, nivel + 1));
    }
  }

  return linhas;
}

function todasAsCategorias(nos) {
  const linhas = [];

  for (const no of nos) {
    linhas.push(no);

    if (no.filhos && no.filhos.length) {
      linhas.push(...todasAsCategorias(no.filhos));
    }
  }

  return linhas;
}

const arvoreReceita = computed(() => achatar(props.contas.filter((conta) => conta.tipo === 'receita')));
const arvoreDespesa = computed(() => achatar(props.contas.filter((conta) => conta.tipo === 'despesa')));
const categoriasPlanas = computed(() => todasAsCategorias(props.contas));

// Um Card por tipo, com a mesma tabela: evita duplicar o markup para receita
// e despesa.
const grupos = computed(() => [
  { tipo: 'receita', titulo: 'Receita', linhas: arvoreReceita.value },
  { tipo: 'despesa', titulo: 'Despesa', linhas: arvoreDespesa.value },
]);

// -----------------------------------------------------------------
// Criação e edição
// -----------------------------------------------------------------

const mostrarFormulario = ref(false);
const categoriaEmEdicao = ref(null);

const form = useForm({
  codigo: '',
  nome: '',
  tipo: 'despesa',
  parent_id: '',
});

const opcoesDeMae = computed(() =>
  categoriasPlanas.value.filter(
    (categoria) => categoria.tipo === form.tipo && (!categoriaEmEdicao.value || categoria.id !== categoriaEmEdicao.value.id)
  )
);

const aoSelecionarMae = () => {
  if (!form.parent_id) return;

  const mae = categoriasPlanas.value.find((categoria) => String(categoria.id) === String(form.parent_id));

  if (mae) form.tipo = mae.tipo;
};

const abrirCriacao = (tipoPadrao = 'despesa', maeId = '') => {
  categoriaEmEdicao.value = null;
  form.clearErrors();
  form.reset();
  form.tipo = tipoPadrao;
  form.parent_id = maeId ? String(maeId) : '';
  mostrarFormulario.value = true;
};

const abrirEdicao = (categoria) => {
  categoriaEmEdicao.value = categoria;
  form.clearErrors();
  form.codigo = categoria.codigo;
  form.nome = categoria.nome;
  form.tipo = categoria.tipo;
  form.parent_id = categoria.parent_id ? String(categoria.parent_id) : '';
  mostrarFormulario.value = true;
};

const fecharFormulario = () => {
  if (form.processing) return;
  mostrarFormulario.value = false;
  categoriaEmEdicao.value = null;
};

const enviarFormulario = () => {
  if (categoriaEmEdicao.value) {
    form.put(route('contas.update', categoriaEmEdicao.value.id), {
      preserveScroll: true,
      onSuccess: () => fecharFormulario(),
    });
  } else {
    form.post(route('contas.store'), {
      preserveScroll: true,
      onSuccess: () => fecharFormulario(),
    });
  }
};

// -----------------------------------------------------------------
// Ativar / desativar
// -----------------------------------------------------------------

const alternandoAtivo = ref(null);

const alternarAtivo = (categoria) => {
  alternandoAtivo.value = categoria.id;

  router.put(
    route('contas.update', categoria.id),
    {
      codigo: categoria.codigo,
      nome: categoria.nome,
      tipo: categoria.tipo,
      parent_id: categoria.parent_id,
      ativo: !categoria.ativo,
    },
    {
      preserveScroll: true,
      onFinish: () => {
        alternandoAtivo.value = null;
      },
    }
  );
};

// -----------------------------------------------------------------
// Exclusão: só quando não há uso nem subcategoria (`pode_excluir`).
// Do contrário a tela explica e oferece desativar em vez de excluir.
// -----------------------------------------------------------------

const categoriaParaExcluir = ref(null);
const categoriaBloqueada = ref(null);
const excluindo = ref(false);

const pedirExclusao = (categoria) => {
  if (!categoria.pode_excluir) {
    categoriaBloqueada.value = categoria;
    return;
  }

  categoriaParaExcluir.value = categoria;
};

const motivoBloqueio = computed(() => {
  const categoria = categoriaBloqueada.value;

  if (!categoria) return '';

  if (categoria.motivoForcado) return categoria.motivoForcado;

  if (categoria.titulos_vinculados > 0) {
    return `Esta categoria já classifica ${categoria.titulos_vinculados} título(s) e por isso não pode ser excluída. `
      + 'Desative-a: ela sai dos seletores usados na criação de novos títulos, e o histórico continua classificado exatamente como está.';
  }

  return 'Esta categoria tem subcategorias. Mova ou exclua as subcategorias primeiro, ou desative esta categoria '
    + 'para tirá-la dos seletores sem apagar a hierarquia.';
});

const desativarAPartirDoBloqueio = () => {
  if (!categoriaBloqueada.value) return;

  alternarAtivo(categoriaBloqueada.value);
  categoriaBloqueada.value = null;
};

const confirmarExclusao = () => {
  excluindo.value = true;

  router.delete(route('contas.destroy', categoriaParaExcluir.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      categoriaParaExcluir.value = null;
    },
    onError: (errors) => {
      // `pode_excluir` já evita chegar aqui na maioria dos casos; isto cobre
      // a categoria que passou a ter uso entre o carregamento da tela e o
      // clique, sem deixar o usuário sem explicação.
      categoriaBloqueada.value = { ...categoriaParaExcluir.value, motivoForcado: errors.conta };
      categoriaParaExcluir.value = null;
    },
    onFinish: () => {
      excluindo.value = false;
    },
  });
};
</script>
