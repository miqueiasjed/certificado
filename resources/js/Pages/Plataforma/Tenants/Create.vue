<template>
  <PlataformaLayout>
    <template #header>
      <PageHeader title="Novo tenant" description="Cadastre uma nova empresa na plataforma">
        <template #actions>
          <Link :href="route('plataforma.tenants.index')" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-4xl mx-auto">
      <Form :planos="planos" :tenant="null" :criando="true" />
    </div>
  </PlataformaLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import PlataformaLayout from '@/Layouts/PlataformaLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Form from './Form.vue';

defineProps({
  planos: {
    type: Array,
    default: () => [],
  },
});
</script>
