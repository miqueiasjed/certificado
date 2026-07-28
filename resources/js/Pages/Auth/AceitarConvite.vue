<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-md w-full space-y-8">
      <div>
        <div
          class="mx-auto h-12 w-12 rounded-full flex items-center justify-center"
          :class="valido ? 'bg-green-600' : 'bg-red-100'"
        >
          <svg v-if="valido" class="h-8 w-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
          </svg>
          <svg v-else class="h-8 w-8 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
          </svg>
        </div>
        <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
          {{ valido ? 'Você foi convidado' : 'Convite indisponível' }}
        </h2>
        <p v-if="valido" class="mt-2 text-center text-sm text-gray-600">
          {{ empresa }} convidou você para acessar o sistema como {{ rotuloPapel(papel) }}.
        </p>
        <p v-if="valido && expira_em" class="mt-1 text-center text-xs text-gray-500">
          Este convite vale até {{ formatarData(expira_em) }}.
        </p>
      </div>

      <!-- Token inválido, expirado, aceito ou cancelado -->
      <div v-if="!valido" class="bg-white shadow rounded-lg p-6 sm:p-8 text-center space-y-6">
        <p class="text-sm text-gray-700">{{ mensagem }}</p>
        <Link :href="route('login')" class="btn-primary px-6">
          Ir para o login
        </Link>
      </div>

      <!-- Formulário de aceite -->
      <div v-else class="bg-white shadow rounded-lg p-6 sm:p-8">
        <form class="space-y-4" @submit.prevent="submit">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              E-mail
            </label>
            <input
              :value="email"
              type="email"
              disabled
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm bg-gray-100 text-gray-500"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Nome completo *
            </label>
            <input
              v-model="form.nome"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.nome }"
              placeholder="Nome e sobrenome"
            />
            <p v-if="form.errors.nome" class="mt-1 text-sm text-red-600">{{ form.errors.nome }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Senha *
            </label>
            <input
              v-model="form.senha"
              type="password"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.senha }"
              placeholder="Mínimo 8 caracteres"
            />
            <p v-if="form.errors.senha" class="mt-1 text-sm text-red-600">{{ form.errors.senha }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Confirme a senha *
            </label>
            <input
              v-model="form.senha_confirmation"
              type="password"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.senha_confirmation }"
              placeholder="Repita a senha"
            />
            <p v-if="form.errors.senha_confirmation" class="mt-1 text-sm text-red-600">{{ form.errors.senha_confirmation }}</p>
          </div>

          <p v-if="form.errors.token" class="text-sm text-red-600">{{ form.errors.token }}</p>

          <button type="submit" :disabled="form.processing" class="btn-primary w-full py-2.5 disabled:opacity-50 disabled:cursor-not-allowed">
            <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ form.processing ? 'Criando acesso...' : 'Criar acesso e entrar' }}
          </button>
        </form>
      </div>

      <p v-if="valido" class="text-center text-sm text-gray-600">
        Já tem uma conta?
        <Link :href="route('login')" class="font-medium text-green-600 hover:text-green-500">
          Entrar
        </Link>
      </p>
    </div>
  </div>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { formatarData } from '@/utils/formatDate';

const props = defineProps({
  token: {
    type: String,
    required: true,
  },
  valido: {
    type: Boolean,
    required: true,
  },
  mensagem: {
    type: String,
    default: null,
  },
  empresa: {
    type: String,
    default: null,
  },
  papel: {
    type: String,
    default: null,
  },
  email: {
    type: String,
    default: null,
  },
  nome: {
    type: String,
    default: null,
  },
  expira_em: {
    type: String,
    default: null,
  },
});

// Mesmos rótulos de Settings/Users/Index.vue: papel conhecido ganha
// acentuação correta, um papel novo cai no fallback com a inicial maiúscula.
const ROTULOS_PAPEL = {
  administrador: 'Administrador',
  financeiro: 'Financeiro',
  tecnico: 'Técnico',
};

const rotuloPapel = (papel) => {
  if (!papel) return 'usuário';

  return ROTULOS_PAPEL[papel] || papel.charAt(0).toUpperCase() + papel.slice(1);
};

const form = useForm({
  nome: props.nome || '',
  senha: '',
  senha_confirmation: '',
});

const submit = () => {
  form.post(route('convite.aceitar', { token: props.token }), {
    onError: () => {
      form.reset('senha', 'senha_confirmation');
    },
  });
};
</script>
