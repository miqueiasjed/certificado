<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader :title="`Editar Orçamento #${budget.id}`" description="Altere os dados do orçamento abaixo">
      </PageHeader>
    </template>

    <div class="max-w-7xl mx-auto py-6 sm:px-6 lg:px-8">
        <BudgetForm :budget="budget" :clients="clients" :services="services" :products="products" isEditing />
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import BudgetForm from './BudgetForm.vue';

defineProps({
  budget: Object,
  clients: Array,
  services: Array,
  products: Array,
});
</script>
