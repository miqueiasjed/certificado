<template>
  <PlataformaLayout>
    <template #header>
      <PageHeader title="Editar tenant" :description="`Editando: ${tenant.name}`">
        <template #actions>
          <Link :href="route('plataforma.tenants.show', tenant.id)" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
            </svg>
            Ver tenant
          </Link>
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
      <Form :planos="planos" :tenant="tenant" :criando="false" />
    </div>
  </PlataformaLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import PlataformaLayout from '@/Layouts/PlataformaLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Form from './Form.vue';

defineProps({
  tenant: {
    type: Object,
    required: true,
  },
  planos: {
    type: Array,
    default: () => [],
  },
});
</script>
