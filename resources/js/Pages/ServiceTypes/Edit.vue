<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Editar Tipo de Serviço" description="Editar tipo de ordem de serviço">
        <template #actions>
          <button @click="goBack" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </button>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-3xl mx-auto">
      <Card>
        <form @submit.prevent="submit">
          <div class="p-6 space-y-6">
            <!-- Nome -->
            <div>
              <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
                Nome *
              </label>
              <input
                id="name"
                v-model="form.name"
                type="text"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500"
                :class="{ 'border-red-500': form.errors.name }"
                placeholder="Ex: Dedetização, Desinsetização..."
              />
              <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">
                {{ form.errors.name }}
              </p>
            </div>

            <!-- Descrição -->
            <div>
              <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
                Descrição
              </label>
              <textarea
                id="description"
                v-model="form.description"
                rows="4"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500"
                :class="{ 'border-red-500': form.errors.description }"
                placeholder="Descrição detalhada do tipo de serviço..."
              ></textarea>
              <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">
                {{ form.errors.description }}
              </p>
            </div>

            <!-- Status -->
            <div>
              <label class="flex items-center">
                <input
                  v-model="form.active"
                  type="checkbox"
                  class="rounded border-gray-300 text-purple-600 shadow-sm focus:border-purple-300 focus:ring focus:ring-purple-200 focus:ring-opacity-50"
                />
                <span class="ml-2 text-sm text-gray-700">
                  Tipo de serviço ativo
                </span>
              </label>
              <p v-if="form.errors.active" class="mt-1 text-sm text-red-600">
                {{ form.errors.active }}
              </p>
            </div>

            <!-- Informações adicionais -->
            <div class="bg-gray-50 p-4 rounded-md">
              <h4 class="text-sm font-medium text-gray-700 mb-2">Informações do Sistema</h4>
              <div class="grid grid-cols-2 gap-4 text-sm">
                <div>
                  <span class="text-gray-500">Slug:</span>
                  <span class="ml-2 text-gray-900">{{ serviceType.slug }}</span>
                </div>
                <div>
                  <span class="text-gray-500">Ordem:</span>
                  <span class="ml-2 text-gray-900">{{ serviceType.sort_order }}</span>
                </div>
                <div>
                  <span class="text-gray-500">Criado em:</span>
                  <span class="ml-2 text-gray-900">{{ formatDate(serviceType.created_at) }}</span>
                </div>
                <div>
                  <span class="text-gray-500">Atualizado em:</span>
                  <span class="ml-2 text-gray-900">{{ formatDate(serviceType.updated_at) }}</span>
                </div>
              </div>
            </div>
          </div>

          <!-- Botões -->
          <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-end space-x-3">
            <button
              type="button"
              @click="goBack"
              class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500"
            >
              Cancelar
            </button>
            <button
              type="submit"
              :disabled="form.processing"
              class="px-4 py-2 text-sm font-medium text-white bg-purple-600 border border-transparent rounded-md hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 disabled:opacity-50"
            >
              <span v-if="form.processing">Salvando...</span>
              <span v-else>Atualizar Tipo</span>
            </button>
          </div>
        </form>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  serviceType: Object,
});

const form = useForm({
  name: props.serviceType.name,
  description: props.serviceType.description,
  active: props.serviceType.active,
});

const submit = () => {
  form.put(`/service-types/${props.serviceType.id}`, {
    onSuccess: () => {
      // Redirecionar para a lista após sucesso
      router.visit('/service-types');
    }
  });
};

const goBack = () => {
  window.history.back();
};

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('pt-BR');
};
</script>
