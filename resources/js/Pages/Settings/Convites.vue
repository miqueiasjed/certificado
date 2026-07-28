<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Convites"
        description="Convide pessoas para a empresa e acompanhe a situação de cada convite."
      >
        <template #actions>
          <button v-if="podeCriar" type="button" class="btn-primary" @click="abrirCriacao">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            Novo Convite
          </button>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-6xl mx-auto space-y-6">

      <!-- Mensagens Flash -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!-- Lista de Convites -->
      <Card padding="none">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  E-mail
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Papel
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Situação
                </th>
                <th class="hidden px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider lg:table-cell">
                  Convidado por
                </th>
                <th class="hidden px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider md:table-cell">
                  Convidado em
                </th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Ações
                </th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="convite in convites" :key="convite.id" class="hover:bg-gray-50">
                <td class="px-6 py-4">
                  <div class="text-sm font-medium text-gray-900">{{ convite.email }}</div>
                  <div v-if="convite.nome" class="text-sm text-gray-500">{{ convite.nome }}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                    {{ rotuloPapel(convite.papel) }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                    :class="situacaoClasses(convite.situacao)"
                  >
                    {{ rotuloSituacao(convite.situacao) }}
                  </span>
                  <div class="mt-1 text-xs text-gray-500">{{ subtextoSituacao(convite) }}</div>
                </td>
                <td class="hidden px-6 py-4 whitespace-nowrap lg:table-cell">
                  <span class="text-sm text-gray-600">{{ convite.convidado_por || 'Não informado' }}</span>
                </td>
                <td class="hidden px-6 py-4 whitespace-nowrap md:table-cell">
                  <span class="text-sm text-gray-600">{{ formatarDataHora(convite.created_at) }}</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                  <div class="flex items-center justify-end gap-3">
                    <button
                      v-if="convite.link"
                      type="button"
                      class="relative text-gray-500 hover:text-gray-900 transition-colors"
                      title="Copiar link do convite"
                      @click="copiarLink(convite)"
                    >
                      <svg v-if="linkCopiadoId !== convite.id" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"></path>
                      </svg>
                      <svg v-else class="h-5 w-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                      </svg>
                    </button>
                    <button
                      v-if="podeCriar && podeReenviar(convite)"
                      type="button"
                      :disabled="reenviandoId === convite.id"
                      class="text-blue-600 hover:text-blue-900 transition-colors disabled:opacity-50"
                      title="Reenviar convite"
                      @click="reenviar(convite)"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
                      </svg>
                    </button>
                    <button
                      v-if="podeCriar && podeCancelar(convite)"
                      type="button"
                      class="text-red-600 hover:text-red-900 transition-colors"
                      title="Cancelar convite"
                      @click="pedirCancelamento(convite)"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>

          <!-- Estado vazio -->
          <div v-if="!convites || convites.length === 0" class="text-center py-12">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum convite enviado</h3>
            <p class="mt-1 text-sm text-gray-500">Convide a primeira pessoa para acessar o sistema.</p>
          </div>
        </div>
      </Card>
    </div>

    <!-- Modal de Novo Convite -->
    <Modal :show="showFormModal" @close="fecharFormModal">
      <template #icon>
        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
        </svg>
      </template>

      <template #title>
        Novo Convite
      </template>

      <template #content>
        <form class="space-y-4" @submit.prevent="salvar">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">E-mail *</label>
            <input
              v-model="form.email"
              type="email"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.email }"
              placeholder="email@empresa.com"
            />
            <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nome</label>
            <input
              v-model="form.nome"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.nome }"
              placeholder="Opcional"
            />
            <p v-if="form.errors.nome" class="mt-1 text-sm text-red-600">{{ form.errors.nome }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Papel *</label>
            <select
              v-model="form.papel"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.papel }"
            >
              <option value="" disabled>Selecione um papel</option>
              <option v-for="papel in papeis" :key="papel" :value="papel">
                {{ rotuloPapel(papel) }}
              </option>
            </select>
            <p v-if="form.errors.papel" class="mt-1 text-sm text-red-600">{{ form.errors.papel }}</p>
          </div>

          <!-- Erro geral vindo do backend (limite de usuários, convite pendente, e-mail já cadastrado) -->
          <p v-if="form.errors.error" class="text-sm text-red-600">{{ form.errors.error }}</p>
        </form>
      </template>

      <template #actions>
        <button type="button" class="btn-secondary" @click="fecharFormModal">
          Cancelar
        </button>
        <button type="button" :disabled="form.processing" class="btn-primary" @click="salvar">
          <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          {{ form.processing ? 'Enviando...' : 'Enviar convite' }}
        </button>
      </template>
    </Modal>

    <!-- Modal de Confirmação de Cancelamento -->
    <ConfirmDeleteModal
      :show="!!convitePraCancelar"
      title="Cancelar convite"
      :message="mensagemCancelamento"
      confirm-text="Sim, cancelar"
      cancel-text="Voltar"
      processing-text="Cancelando..."
      subtitle="O link deste convite deixa de funcionar."
      :processing="cancelForm.processing"
      @confirm="confirmarCancelamento"
      @cancel="cancelarCancelamento"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { formatarData, formatarDataHora } from '@/utils/formatDate';

const props = defineProps({
  convites: {
    type: Array,
    default: () => [],
  },
  papeis: {
    type: Array,
    default: () => [],
  },
});

const $page = usePage();

const { pode } = usePermissoes();

const podeCriar = computed(() => pode('usuario-criar'));

// Mesmos rótulos de Settings/Users/Index.vue: papel conhecido ganha
// acentuação correta, um papel novo cai no fallback com a inicial maiúscula.
const ROTULOS_PAPEL = {
  administrador: 'Administrador',
  financeiro: 'Financeiro',
  tecnico: 'Técnico',
};

const rotuloPapel = (papel) => {
  if (!papel) return 'Sem papel';

  return ROTULOS_PAPEL[papel] || papel.charAt(0).toUpperCase() + papel.slice(1);
};

const ROTULOS_SITUACAO = {
  pendente: 'Pendente',
  aceito: 'Aceito',
  expirado: 'Expirado',
  cancelado: 'Cancelado',
};

const rotuloSituacao = (situacao) => ROTULOS_SITUACAO[situacao] || situacao;

const situacaoClasses = (situacao) => {
  switch (situacao) {
    case 'pendente':
      return 'bg-yellow-100 text-yellow-800';
    case 'aceito':
      return 'bg-green-100 text-green-800';
    case 'cancelado':
      return 'bg-red-100 text-red-800';
    case 'expirado':
    default:
      return 'bg-gray-100 text-gray-800';
  }
};

const subtextoSituacao = (convite) => {
  switch (convite.situacao) {
    case 'pendente':
      return `Expira em ${formatarData(convite.expira_em)}`;
    case 'aceito':
      return `Aceito em ${formatarDataHora(convite.aceito_em)}`;
    case 'cancelado':
      return `Cancelado em ${formatarDataHora(convite.cancelado_em)}`;
    case 'expirado':
      return `Expirou em ${formatarData(convite.expira_em)}`;
    default:
      return '';
  }
};

// Reenviar e cancelar continuam disponíveis para convite pendente ou
// expirado: são as duas situações em que o InvitationService aceita a ação.
// Convite aceito ou já cancelado não mostra nenhum dos dois botões.
const podeReenviar = (convite) => convite.situacao === 'pendente' || convite.situacao === 'expirado';
const podeCancelar = (convite) => convite.situacao === 'pendente' || convite.situacao === 'expirado';

// Criação de convite, em modal.
const showFormModal = ref(false);

const form = useForm({
  email: '',
  nome: '',
  papel: '',
});

const abrirCriacao = () => {
  form.reset();
  form.clearErrors();
  showFormModal.value = true;
};

const fecharFormModal = () => {
  showFormModal.value = false;
  form.reset();
  form.clearErrors();
};

const salvar = () => {
  form.post(route('settings.convites.store'), {
    preserveScroll: true,
    onSuccess: () => fecharFormModal(),
  });
};

// Copiar o link do convite: é por ele que o convite se completa quando o
// e-mail não chega, então a ação precisa de retorno visual claro.
const linkCopiadoId = ref(null);
let temporizadorCopia = null;

const copiarLink = async (convite) => {
  if (!convite.link) return;

  try {
    await navigator.clipboard.writeText(convite.link);
  } catch (erro) {
    return;
  }

  linkCopiadoId.value = convite.id;

  if (temporizadorCopia) clearTimeout(temporizadorCopia);
  temporizadorCopia = setTimeout(() => {
    linkCopiadoId.value = null;
  }, 2000);
};

// Reenvio: sem campos de formulário, apenas a ação em si.
const reenviandoId = ref(null);
const reenviarForm = useForm({});

const reenviar = (convite) => {
  reenviandoId.value = convite.id;

  reenviarForm.post(route('settings.convites.reenviar', convite.id), {
    preserveScroll: true,
    onFinish: () => {
      reenviandoId.value = null;
    },
  });
};

// Cancelamento, sempre atrás de modal de confirmação (nunca confirm() nativo).
const convitePraCancelar = ref(null);
const cancelForm = useForm({});

const mensagemCancelamento = computed(() => {
  if (!convitePraCancelar.value) return '';

  return `Cancelar o convite enviado para "${convitePraCancelar.value.email}"?`;
});

const pedirCancelamento = (convite) => {
  cancelForm.clearErrors();
  convitePraCancelar.value = convite;
};

const cancelarCancelamento = () => {
  if (cancelForm.processing) return;

  convitePraCancelar.value = null;
  cancelForm.clearErrors();
};

const confirmarCancelamento = () => {
  cancelForm.delete(route('settings.convites.destroy', convitePraCancelar.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      convitePraCancelar.value = null;
    },
  });
};
</script>
