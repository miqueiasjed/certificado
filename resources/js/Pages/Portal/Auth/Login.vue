<template>
  <Head title="Entrar" />

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
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 21h18M5 21V7l8-4v18M19 21V11l-6-4"></path>
          </svg>
        </div>

        <h1 class="mt-4 text-xl font-semibold text-gray-900">
          {{ empresa?.nome || 'Portal do Cliente' }}
        </h1>
        <p class="mt-1 text-sm text-gray-500">
          Entre com o e-mail e a senha do seu acesso.
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
        </div>

        <div>
          <label for="password" class="mb-1 block text-sm font-medium text-gray-700">Senha</label>
          <input
            id="password"
            v-model="form.password"
            type="password"
            autocomplete="current-password"
            required
            class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
            :class="{ 'border-red-500': form.errors.email }">
        </div>

        <p v-if="form.errors.email" class="text-sm text-red-600">
          {{ form.errors.email }}
        </p>

        <button
          type="submit"
          :disabled="form.processing"
          class="flex w-full justify-center rounded-md px-4 py-2 text-sm font-medium text-white shadow-sm transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
          style="background-color: var(--portal-cor-primaria)">
          {{ form.processing ? 'Entrando...' : 'Entrar' }}
        </button>

        <div class="text-center">
          <Link :href="route('portal.senha.esqueci', undefined, false)" class="text-sm font-medium" style="color: var(--portal-cor-primaria)">
            Esqueci minha senha
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

/**
 * `$page.props.empresa` vem de `HandleInertiaRequests` e, nesta tela, é
 * sempre `null` hoje: o login ainda não sabe quem é o cliente (nem senha foi
 * conferida), e não existe nenhum identificador de tenant na URL de
 * `/portal/login` (sem subdomínio, sem slug) para resolver a empresa antes
 * disso. Ver o docblock de `brandingDoPortalCliente()` em
 * `HandleInertiaRequests` para o detalhe da lacuna.
 *
 * A tela já está pronta para o dia em que essa lacuna for fechada (ex.: URL
 * com slug por tenant): assim que o backend passar a mandar `empresa`
 * preenchida para esta rota, o logo e o nome aparecem sozinhos, sem mexer
 * neste componente.
 */
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
  password: '',
});

const enviar = () => {
  form.post(route('portal.login.post', undefined, false), {
    onSuccess: () => form.reset('password'),
    onError: () => form.reset('password'),
  });
};
</script>
