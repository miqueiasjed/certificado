<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Novo Orçamento" description="Preencha os dados abaixo para criar um novo orçamento">
      </PageHeader>
    </template>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <BudgetForm :clients="clients" :services="services" :products="products" />
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import BudgetForm from './BudgetForm.vue';

defineProps({
  clients: Array,
  services: Array,
  products: Array,
});
</script>
