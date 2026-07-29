<template>
  <Head title="Definir senha" />

  <div class="flex min-h-screen flex-col items-center justify-center bg-gray-50 px-4 py-12" :style="variaveisDeCor">
    <div class="w-full max-w-sm">
      <div class="flex flex-col items-center text-center">
        <img
          v-if="empresa?.logo_url"
          :src="empresa.logo_url"
          :alt="empresa.nome"
          class="h-16 w-16 rounded-md bg-white object-contain p-1 shadow-sm">
        <div v-else class="flex h-16 w-16 items-center justify-center rounded-full" style="background-color: var(--portal-cor-primaria)">
          <svg class="h-8 w-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"></path>
          </svg>
        </div>
        <h1 class="mt-4 text-xl font-semibold text-gray-900">
          {{ empresa?.nome ? `Portal ${empresa.nome}` : 'Portal do Cliente' }}
        </h1>
      </div>

      <!-- Link vencido: token inexistente, já usado ou expirado -->
      <div v-if="linkVencido" class="mt-8 rounded-md border border-yellow-200 bg-yellow-50 p-4">
        <p class="text-sm font-medium text-yellow-800">Link expirado ou inválido</p>
        <p class="mt-1 text-sm text-yellow-700">{{ $page.props.errors.token }}</p>
        <Link
          :href="route('portal.senha.esqueci', undefined, false)"
          class="mt-4 inline-flex justify-center rounded-md px-4 py-2 text-sm font-medium text-white shadow-sm transition-opacity hover:opacity-90"
          style="background-color: var(--portal-cor-primaria)">
          Pedir novo link
        </Link>
      </div>

      <!-- Formulário de definir senha -->
      <template v-else>
        <h2 class="mt-6 text-center text-lg font-medium text-gray-900">Escolha sua senha</h2>

        <ul class="mt-4 space-y-1.5 rounded-md border border-gray-200 bg-white p-4 text-sm">
          <li class="flex items-center gap-2" :class="regraTamanhoOk ? 'text-green-700' : 'text-gray-500'">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            Pelo menos 8 caracteres
          </li>
          <li class="flex items-center gap-2" :class="regraConfirmacaoOk ? 'text-green-700' : 'text-gray-500'">
            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
            As duas senhas precisam ser iguais
          </li>
        </ul>

        <form class="mt-6 space-y-5" @submit.prevent="enviar">
          <div>
            <label for="senha" class="mb-1 block text-sm font-medium text-gray-700">Nova senha</label>
            <input
              id="senha"
              v-model="form.senha"
              type="password"
              autocomplete="new-password"
              required
              class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
              :class="{ 'border-red-500': form.errors.senha }">
            <p v-if="form.errors.senha" class="mt-1 text-sm text-red-600">{{ form.errors.senha }}</p>
          </div>

          <div>
            <label for="senha_confirmation" class="mb-1 block text-sm font-medium text-gray-700">Confirme a nova senha</label>
            <input
              id="senha_confirmation"
              v-model="form.senha_confirmation"
              type="password"
              autocomplete="new-password"
              required
              class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500">
          </div>

          <button
            type="submit"
            :disabled="form.processing"
            class="flex w-full justify-center rounded-md px-4 py-2 text-sm font-medium text-white shadow-sm transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
            style="background-color: var(--portal-cor-primaria)">
            {{ form.processing ? 'Salvando...' : 'Salvar senha' }}
          </button>
        </form>
      </template>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { resolverCoresDoPortal } from '@/utils/corDoPortal';

const props = defineProps({
  token: {
    type: String,
    required: true,
  },
  // `PortalAuthController::showDefinirSenha()` já resolve a empresa pelo
  // token, mesmo antes do login: diferente de Login.vue/EsqueciSenha.vue,
  // aqui não há a lacuna de identificação do tenant (o token na URL já
  // identifica o acesso). Mesmo assim pode vir `null`, para token
  // inexistente - a tela cai no verde padrão do sistema sem nome de empresa.
  empresa: {
    type: Object,
    default: null,
  },
});

const $page = usePage();

/**
 * O backend nunca valida o token no GET (`showDefinirSenha()` sempre
 * renderiza o formulário): quem recusa token inválido/expirado é
 * `definirSenha()` no POST, devolvendo `withErrors(['token' => mensagem])` e
 * redirecionando de volta para esta mesma tela (Task 15.2). Por isso o estado
 * de "link vencido" só aparece depois de uma tentativa de envio - não há como
 * saber antes disso sem duplicar a validação de expiração aqui no frontend.
 */
const linkVencido = computed(() => Boolean($page.props.errors?.token));

const variaveisDeCor = computed(() => {
  const { corPrimaria, corDestaque } = resolverCoresDoPortal(props.empresa);

  return {
    '--portal-cor-primaria': corPrimaria,
    '--portal-cor-destaque': corDestaque,
  };
});

const form = useForm({
  senha: '',
  senha_confirmation: '',
});

const regraTamanhoOk = computed(() => form.senha.length >= 8);
const regraConfirmacaoOk = computed(() => form.senha.length > 0 && form.senha === form.senha_confirmation);

const enviar = () => {
  form.post(route('portal.senha.definir.post', { token: props.token }, false));
};
</script>
