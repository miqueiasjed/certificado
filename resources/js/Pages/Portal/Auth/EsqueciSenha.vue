<template>
  <Head title="Esqueci minha senha" />

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
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
          </svg>
        </div>

        <h1 class="mt-4 text-xl font-semibold text-gray-900">Esqueci minha senha</h1>
        <p class="mt-1 text-sm text-gray-500">
          Informe o e-mail cadastrado no portal{{ empresa?.nome ? ` de ${empresa.nome}` : '' }}. Se ele tiver acesso, você recebe um link para redefinir a senha.
        </p>
      </div>

      <div v-if="$page.props.flash?.success" class="mt-6 rounded-md border border-green-200 bg-green-50 p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>

      <form class="mt-8 space-y-5" @submit.prevent="enviar">
        <div>
          <label for="email" class="mb-1 block text-sm font-medium text-gray-700">E-mail</label>
          <input
            id="email"
            v-model="form.email"
            type="email"
            autocomplete="username"
            required
            class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
            :class="{ 'border-red-500': form.errors.email }">
          <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
        </div>

        <button
          type="submit"
          :disabled="form.processing"
          class="flex w-full justify-center rounded-md px-4 py-2 text-sm font-medium text-white shadow-sm transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
          style="background-color: var(--portal-cor-primaria)">
          {{ form.processing ? 'Enviando...' : 'Enviar link de recuperação' }}
        </button>

        <div class="text-center">
          <Link :href="route('portal.login', undefined, false)" class="text-sm font-medium" style="color: var(--portal-cor-primaria)">
            Voltar para o login
          </Link>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { resolverCoresDoPortal } from '@/utils/corDoPortal';

const $page = usePage();

// Mesma lacuna do Login.vue: sem identificador de tenant na URL, `empresa`
// vem `null` do compartilhamento de `HandleInertiaRequests` nesta tela.
const empresa = computed(() => $page.props.empresa);

const variaveisDeCor = computed(() => {
  const { corPrimaria, corDestaque } = resolverCoresDoPortal(empresa.value);

  return {
    '--portal-cor-primaria': corPrimaria,
    '--portal-cor-destaque': corDestaque,
  };
});

const form = useForm({
  email: '',
});

const enviar = () => {
  form.post(route('portal.senha.esqueci.post', undefined, false), {
    onSuccess: () => form.reset(),
  });
};
</script>
