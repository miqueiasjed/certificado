<template>
  <PlataformaLayout>
    <template #header>
      <PageHeader title="Planos" description="Catálogo de planos comerciais da plataforma">
        <template #actions>
          <button type="button" class="btn-primary" @click="abrirCriacao">
            Novo plano
          </button>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <!-- Mensagens flash -->
      <div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!-- Busca -->
      <Card>
        <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="buscar">
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Buscar por nome ou slug</label>
            <input
              v-model="busca"
              type="text"
              placeholder="Ex: profissional, plano-anual"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500" />
          </div>
          <button type="submit" class="btn-secondary">Filtrar</button>
        </form>
      </Card>

      <!-- Tabela -->
      <Card padding="none">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Nome</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Valor</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Periodicidade</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usuários</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Clientes</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">OS/mês</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Armazenamento</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tenants</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="plano in planos.data" :key="plano.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                  {{ plano.nome }}
                  <span class="block text-xs text-gray-400">{{ plano.slug }}</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatarValor(plano.valor) }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 capitalize">{{ plano.periodicidade }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatarLimite(plano.limite_usuarios) }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatarLimite(plano.limite_clientes) }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatarLimite(plano.limite_os_mes) }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatarLimite(plano.limite_armazenamento_mb, 'MB') }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ plano.companies_count }}</td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                    :class="plano.ativo ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'">
                    {{ plano.ativo ? 'Ativo' : 'Inativo' }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                  <button type="button" class="text-green-600 hover:text-green-800 font-medium" @click="abrirEdicao(plano)">
                    Editar
                  </button>
                  <button type="button" class="ml-4 text-red-600 hover:text-red-800 font-medium" @click="pedirExclusao(plano)">
                    Excluir
                  </button>
                </td>
              </tr>
              <tr v-if="!planos.data.length">
                <td colspan="10" class="px-6 py-4 text-sm text-gray-500 text-center">Nenhum plano cadastrado.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>

      <Pagination :links="planos.links" />
    </div>

    <!-- Modal de criação/edição, mesmo formulário para os dois casos -->
    <Modal :show="showModal" @close="fecharModal">
      <template #icon>
        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
        </svg>
      </template>

      <template #title>
        {{ planoEmEdicao ? `Editar plano: ${planoEmEdicao.nome}` : 'Novo plano' }}
      </template>

      <template #content>
        <div class="space-y-4">
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Nome *</label>
              <input
                v-model="form.nome"
                type="text"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.nome }" />
              <p v-if="form.errors.nome" class="mt-1 text-sm text-red-600">{{ form.errors.nome }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Slug *</label>
              <input
                v-model="form.slug"
                type="text"
                placeholder="Ex: plano-profissional"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.slug }" />
              <p v-if="form.errors.slug" class="mt-1 text-sm text-red-600">{{ form.errors.slug }}</p>
            </div>
          </div>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Valor (R$) *</label>
              <input
                v-model="form.valor"
                type="number"
                step="0.01"
                min="0"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.valor }" />
              <p v-if="form.errors.valor" class="mt-1 text-sm text-red-600">{{ form.errors.valor }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Periodicidade *</label>
              <select
                v-model="form.periodicidade"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.periodicidade }">
                <option value="mensal">Mensal</option>
                <option value="anual">Anual</option>
              </select>
              <p v-if="form.errors.periodicidade" class="mt-1 text-sm text-red-600">{{ form.errors.periodicidade }}</p>
            </div>
          </div>

          <p class="text-xs text-gray-500">Deixe um limite em branco para ilimitado.</p>

          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Limite de usuários</label>
              <input
                v-model="form.limite_usuarios"
                type="number"
                min="1"
                placeholder="Ilimitado"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.limite_usuarios }" />
              <p v-if="form.errors.limite_usuarios" class="mt-1 text-sm text-red-600">{{ form.errors.limite_usuarios }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Limite de clientes</label>
              <input
                v-model="form.limite_clientes"
                type="number"
                min="1"
                placeholder="Ilimitado"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.limite_clientes }" />
              <p v-if="form.errors.limite_clientes" class="mt-1 text-sm text-red-600">{{ form.errors.limite_clientes }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Limite de OS por mês</label>
              <input
                v-model="form.limite_os_mes"
                type="number"
                min="1"
                placeholder="Ilimitado"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.limite_os_mes }" />
              <p v-if="form.errors.limite_os_mes" class="mt-1 text-sm text-red-600">{{ form.errors.limite_os_mes }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Limite de armazenamento (MB)</label>
              <input
                v-model="form.limite_armazenamento_mb"
                type="number"
                min="1"
                placeholder="Ilimitado"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.limite_armazenamento_mb }" />
              <p v-if="form.errors.limite_armazenamento_mb" class="mt-1 text-sm text-red-600">{{ form.errors.limite_armazenamento_mb }}</p>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ordem de exibição</label>
            <input
              v-model="form.ordem"
              type="number"
              min="0"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.ordem }" />
            <p v-if="form.errors.ordem" class="mt-1 text-sm text-red-600">{{ form.errors.ordem }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Descrição</label>
            <textarea
              v-model="form.descricao"
              rows="3"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.descricao }"></textarea>
            <p v-if="form.errors.descricao" class="mt-1 text-sm text-red-600">{{ form.errors.descricao }}</p>
          </div>

          <label class="flex items-center gap-2">
            <input v-model="form.ativo" type="checkbox" class="rounded border-gray-300 text-green-600 focus:ring-green-500" />
            <span class="text-sm text-gray-700">
              Plano ativo (visível para contratação). Desative em vez de excluir quando houver tenant vinculado.
            </span>
          </label>
        </div>
      </template>

      <template #actions>
        <button type="button" class="btn-secondary" @click="fecharModal">Cancelar</button>
        <button type="submit" class="btn-primary" :disabled="form.processing" @click="salvar">
          {{ form.processing ? 'Salvando...' : 'Salvar' }}
        </button>
      </template>
    </Modal>

    <!-- Confirmação de exclusão -->
    <ConfirmDeleteModal
      :show="!!planoParaExcluir"
      variant="danger"
      title="Excluir plano"
      :message="`Tem certeza que deseja excluir o plano`"
      :item-name="planoParaExcluir?.nome"
      :processing="excluindo"
      @cancel="planoParaExcluir = null"
      @confirm="confirmarExclusao" />
  </PlataformaLayout>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { router, useForm, usePage } from '@inertiajs/vue3';
import PlataformaLayout from '@/Layouts/PlataformaLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import Pagination from '@/Components/Pagination.vue';

const props = defineProps({
  planos: {
    type: Object,
    required: true,
  },
  filtros: {
    type: Object,
    default: () => ({}),
  },
});

const $page = usePage();

const busca = ref(props.filtros?.busca ?? '');

const buscar = () => {
  router.get('/plataforma/planos', { busca: busca.value }, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  });
};

const valoresPadrao = {
  nome: '',
  slug: '',
  valor: '',
  periodicidade: 'mensal',
  limite_usuarios: '',
  limite_clientes: '',
  limite_os_mes: '',
  limite_armazenamento_mb: '',
  ordem: 0,
  descricao: '',
  ativo: true,
};

const form = useForm({ ...valoresPadrao });

const showModal = ref(false);
const planoEmEdicao = ref(null);

const abrirCriacao = () => {
  planoEmEdicao.value = null;
  form.defaults({ ...valoresPadrao });
  form.reset();
  form.clearErrors();
  showModal.value = true;
};

const abrirEdicao = (plano) => {
  planoEmEdicao.value = plano;

  const dadosDoPlano = {
    nome: plano.nome,
    slug: plano.slug,
    valor: plano.valor,
    periodicidade: plano.periodicidade,
    limite_usuarios: plano.limite_usuarios ?? '',
    limite_clientes: plano.limite_clientes ?? '',
    limite_os_mes: plano.limite_os_mes ?? '',
    limite_armazenamento_mb: plano.limite_armazenamento_mb ?? '',
    ordem: plano.ordem ?? 0,
    descricao: plano.descricao ?? '',
    ativo: !!plano.ativo,
  };

  form.defaults({ ...dadosDoPlano });
  form.reset();
  form.clearErrors();
  showModal.value = true;
};

const fecharModal = () => {
  showModal.value = false;
  planoEmEdicao.value = null;
  form.clearErrors();
};

const salvar = () => {
  const opcoes = {
    preserveScroll: true,
    onSuccess: () => {
      fecharModal();
    },
  };

  if (planoEmEdicao.value) {
    form.put(route('plataforma.planos.update', planoEmEdicao.value.id), opcoes);
  } else {
    form.post(route('plataforma.planos.store'), opcoes);
  }
};

const planoParaExcluir = ref(null);
const excluindo = ref(false);

const pedirExclusao = (plano) => {
  planoParaExcluir.value = plano;
};

const confirmarExclusao = () => {
  excluindo.value = true;

  router.delete(route('plataforma.planos.destroy', planoParaExcluir.value.id), {
    preserveScroll: true,
    onFinish: () => {
      excluindo.value = false;
      planoParaExcluir.value = null;
    },
  });
};

const formatarValor = (valor) => {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
  }).format(Number(valor));
};

const formatarLimite = (limite, sufixo = '') => {
  if (limite === null || limite === undefined) {
    return 'Ilimitado';
  }

  return sufixo ? `${limite} ${sufixo}` : String(limite);
};
</script>
