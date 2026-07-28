<template>
  <AuthenticatedLayout>
    <div class="max-w-2xl mx-auto">
      <Card>
        <div class="flex flex-col items-center text-center gap-4 py-6">
          <div class="flex h-14 w-14 items-center justify-center rounded-full bg-green-100">
            <svg class="h-7 w-7 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
          </div>

          <div>
            <h1 class="text-xl font-semibold text-gray-900">{{ nome }}</h1>
            <p class="mt-2 text-sm text-gray-600 max-w-md">{{ descricao }}</p>
          </div>

          <p class="text-sm text-gray-500 max-w-md">
            Esse módulo não faz parte do seu plano atual. Nenhum dado foi afetado, e o recurso volta a aparecer
            assim que o plano for ampliado.
          </p>

          <p class="text-sm text-gray-500 max-w-md">
            Fale com o seu consultor comercial ou com o suporte para conhecer os planos que incluem
            {{ nome.toLowerCase() }}.
          </p>

          <Link :href="route('dashboard')" class="btn-primary mt-2">Voltar ao início</Link>
        </div>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/Card.vue';

// Nome e descrição vêm prontos da rota `modulo-indisponivel` (lidos de
// `Module` no banco, fonte única em `App\Support\CatalogoDeModulos`): nada
// disso é redeclarado aqui, para não divergir se o catálogo mudar.
defineProps({
  modulo: {
    type: String,
    required: true,
  },
  nome: {
    type: String,
    required: true,
  },
  descricao: {
    type: String,
    required: true,
  },
});
</script>
