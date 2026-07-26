<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Usuários"
        description="Gerencie os usuários da empresa, papéis de acesso e vínculo com técnicos."
      >
        <template #actions>
          <button v-if="podeCriar" type="button" class="btn-primary" @click="abrirCriacao">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            Novo Usuário
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

      <!-- Lista de Usuários -->
      <Card padding="none">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Nome
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  E-mail
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Papel
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Técnico vinculado
                </th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Status
                </th>
                <th class="hidden px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider lg:table-cell">
                  Criado em
                </th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">
                  Ações
                </th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="usuario in users" :key="usuario.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="text-sm font-medium text-gray-900">{{ usuario.name }}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <div class="text-sm text-gray-600">{{ usuario.email }}</div>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-blue-100 text-blue-800">
                    {{ rotuloPapel(usuario.role) }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span v-if="usuario.technician" class="text-sm text-gray-600">
                    {{ usuario.technician.name }}
                  </span>
                  <span v-else class="text-sm text-gray-400">Nenhum</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span v-if="usuario.is_active" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                    Ativo
                  </span>
                  <span v-else class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                    Inativo
                  </span>
                </td>
                <td class="hidden px-6 py-4 whitespace-nowrap lg:table-cell">
                  <span class="text-sm text-gray-600">{{ formatarData(usuario.created_at) }}</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                  <div class="flex items-center justify-end gap-3">
                    <button
                      v-if="podeEditar"
                      type="button"
                      class="text-blue-600 hover:text-blue-900 transition-colors"
                      title="Editar"
                      @click="abrirEdicao(usuario)"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                      </svg>
                    </button>
                    <button
                      v-if="podeDesativar"
                      type="button"
                      :class="usuario.is_active ? 'text-red-600 hover:text-red-900' : 'text-green-600 hover:text-green-900'"
                      class="transition-colors"
                      :title="usuario.is_active ? 'Desativar' : 'Ativar'"
                      @click="pedirAlteracaoStatus(usuario)"
                    >
                      <svg v-if="usuario.is_active" class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.36 6.64a9 9 0 11-12.73 0M12 3v9"></path>
                      </svg>
                      <svg v-else class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                      </svg>
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>

          <!-- Estado vazio -->
          <div v-if="!users || users.length === 0" class="text-center py-12">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum usuário cadastrado</h3>
            <p class="mt-1 text-sm text-gray-500">Comece cadastrando o primeiro usuário da empresa.</p>
          </div>
        </div>
      </Card>
    </div>

    <!-- Modal de Criação / Edição -->
    <Modal :show="showFormModal" @close="fecharFormModal">
      <template #icon>
        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
        </svg>
      </template>

      <template #title>
        {{ emEdicao ? 'Editar Usuário' : 'Novo Usuário' }}
      </template>

      <template #content>
        <form class="space-y-4" @submit.prevent="salvar">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Nome *</label>
            <input
              v-model="form.name"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.name }"
              placeholder="Nome completo"
            />
            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
          </div>

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
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Senha {{ emEdicao ? '' : '*' }}
            </label>
            <input
              v-model="form.password"
              type="password"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.password }"
              placeholder="Mínimo 8 caracteres"
            />
            <p class="mt-1 text-xs text-gray-500">
              {{ emEdicao ? 'Deixe em branco para manter a senha atual.' : 'Obrigatória para o novo usuário.' }}
            </p>
            <p v-if="form.errors.password" class="mt-1 text-sm text-red-600">{{ form.errors.password }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Papel *</label>
            <select
              v-model="form.role"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.role }"
            >
              <option value="" disabled>Selecione um papel</option>
              <option v-for="papel in roles" :key="papel" :value="papel">
                {{ rotuloPapel(papel) }}
              </option>
            </select>
            <p v-if="form.errors.role" class="mt-1 text-sm text-red-600">{{ form.errors.role }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Técnico vinculado</label>
            <select
              v-model="form.technician_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.technician_id }"
            >
              <option :value="null">Nenhum</option>
              <option v-for="tecnico in opcoesTecnico" :key="tecnico.id" :value="tecnico.id">
                {{ tecnico.name }}
              </option>
            </select>
            <p v-if="form.errors.technician_id" class="mt-1 text-sm text-red-600">{{ form.errors.technician_id }}</p>
          </div>

          <!-- Erro geral vindo do backend (último administrador, e-mail duplicado) -->
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
          {{ form.processing ? 'Salvando...' : 'Salvar' }}
        </button>
      </template>
    </Modal>

    <!-- Modal de Confirmação de Ativação / Desativação -->
    <div
      v-if="usuarioParaAlterarStatus"
      class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4"
      @click.self="cancelarAlteracaoStatus"
    >
      <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full mx-4" role="dialog" aria-modal="true">
        <div class="p-6">
          <div class="flex items-center gap-4 mb-4">
            <div
              class="flex-shrink-0 w-12 h-12 rounded-full flex items-center justify-center"
              :class="statusEhAtivacao ? 'bg-green-100' : 'bg-red-100'"
            >
              <svg
                class="w-6 h-6"
                :class="statusEhAtivacao ? 'text-green-600' : 'text-red-600'"
                fill="none"
                stroke="currentColor"
                viewBox="0 0 24 24"
              >
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
              </svg>
            </div>
            <div>
              <h3 class="text-lg font-semibold text-gray-900">
                {{ statusEhAtivacao ? 'Ativar usuário' : 'Desativar usuário' }}
              </h3>
            </div>
          </div>

          <p class="text-sm text-gray-700 mb-2">{{ mensagemStatus }}</p>
          <p v-if="statusForm.errors.error" class="text-sm text-red-600 mb-4">{{ statusForm.errors.error }}</p>

          <div class="flex justify-end gap-3 mt-6">
            <button
              type="button"
              class="btn-secondary disabled:opacity-50"
              :disabled="statusForm.processing"
              @click="cancelarAlteracaoStatus"
            >
              Cancelar
            </button>
            <button
              type="button"
              :class="statusEhAtivacao ? 'btn-primary' : 'btn-danger'"
              class="disabled:opacity-50"
              :disabled="statusForm.processing"
              @click="confirmarAlteracaoStatus"
            >
              {{ statusForm.processing ? 'Processando...' : (statusEhAtivacao ? 'Sim, ativar' : 'Sim, desativar') }}
            </button>
          </div>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { formatarData } from '@/utils/formatDate';

const props = defineProps({
  users: {
    type: Array,
    default: () => [],
  },
  roles: {
    type: Array,
    default: () => [],
  },
  techniciansWithoutUser: {
    type: Array,
    default: () => [],
  },
});

const $page = usePage();

const { pode } = usePermissoes();

const podeCriar = computed(() => pode('usuario-criar'));
const podeEditar = computed(() => pode('usuario-editar'));
const podeDesativar = computed(() => pode('usuario-desativar'));

// Rótulo de exibição do papel. Papéis conhecidos ganham acentuação correta,
// um papel novo cai no fallback com a primeira letra maiúscula.
const ROTULOS_PAPEL = {
  administrador: 'Administrador',
  financeiro: 'Financeiro',
  tecnico: 'Técnico',
};

const rotuloPapel = (papel) => {
  if (!papel) return 'Sem papel';

  return ROTULOS_PAPEL[papel] || papel.charAt(0).toUpperCase() + papel.slice(1);
};

// Criação e edição, no mesmo modal e no mesmo formulário.
const showFormModal = ref(false);
const usuarioEmEdicao = ref(null);
const emEdicao = computed(() => !!usuarioEmEdicao.value);

const form = useForm({
  name: '',
  email: '',
  password: '',
  role: '',
  technician_id: null,
});

// O técnico já vinculado ao usuário em edição não aparece em
// techniciansWithoutUser, porque já tem user_id preenchido. Sem isto ele
// some do seletor assim que o modal de edição abre.
const opcoesTecnico = computed(() => {
  const lista = [...props.techniciansWithoutUser];
  const atual = usuarioEmEdicao.value?.technician;

  if (atual && !lista.some((tecnico) => tecnico.id === atual.id)) {
    lista.unshift(atual);
  }

  return lista;
});

const abrirCriacao = () => {
  usuarioEmEdicao.value = null;
  form.reset();
  form.clearErrors();
  showFormModal.value = true;
};

const abrirEdicao = (usuario) => {
  usuarioEmEdicao.value = usuario;
  form.clearErrors();
  form.name = usuario.name;
  form.email = usuario.email;
  form.password = '';
  form.role = usuario.role || '';
  form.technician_id = usuario.technician?.id ?? null;
  showFormModal.value = true;
};

const fecharFormModal = () => {
  showFormModal.value = false;
  usuarioEmEdicao.value = null;
  form.reset();
  form.clearErrors();
};

const salvar = () => {
  if (emEdicao.value) {
    form.put(route('settings.users.update', usuarioEmEdicao.value.id), {
      preserveScroll: true,
      onSuccess: () => fecharFormModal(),
    });
  } else {
    form.post(route('settings.users.store'), {
      preserveScroll: true,
      onSuccess: () => fecharFormModal(),
    });
  }
};

// Ativação e desativação, sempre atrás de modal de confirmação.
const usuarioParaAlterarStatus = ref(null);
const statusForm = useForm({ is_active: true });

const statusEhAtivacao = computed(() => usuarioParaAlterarStatus.value && !usuarioParaAlterarStatus.value.is_active);

const mensagemStatus = computed(() => {
  if (!usuarioParaAlterarStatus.value) return '';

  const nome = usuarioParaAlterarStatus.value.name;

  return statusEhAtivacao.value
    ? `Ativar ${nome}? O usuário recupera o acesso ao sistema imediatamente.`
    : `Desativar ${nome}? O usuário perde o acesso ao sistema imediatamente.`;
});

const pedirAlteracaoStatus = (usuario) => {
  statusForm.clearErrors();
  usuarioParaAlterarStatus.value = usuario;
};

const cancelarAlteracaoStatus = () => {
  if (statusForm.processing) return;

  usuarioParaAlterarStatus.value = null;
  statusForm.clearErrors();
};

const confirmarAlteracaoStatus = () => {
  statusForm.is_active = !usuarioParaAlterarStatus.value.is_active;

  statusForm.patch(route('settings.users.status', usuarioParaAlterarStatus.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      usuarioParaAlterarStatus.value = null;
    },
  });
};
</script>
