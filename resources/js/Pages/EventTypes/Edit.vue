<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Editar Tipo de Evento" description="Atualize as informações do tipo de evento">
        <template #actions>
          <Link
            :href="route('event-types.index')"
            class="inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 active:bg-gray-900 focus:outline-none focus:border-gray-900 focus:ring focus:ring-gray-300 disabled:opacity-25 transition"
          >
            Voltar
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-3xl mx-auto">
      <Card>
        <form @submit.prevent="submit" class="p-6 space-y-6">
          <!-- Nome -->
          <div>
            <label for="name" class="block text-sm font-medium text-gray-700">
              Nome *
            </label>
            <input
              id="name"
              v-model="form.name"
              type="text"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
              :class="{ 'border-red-300': props.errors.name }"
              required
            />
            <p v-if="props.errors.name" class="mt-1 text-sm text-red-600">{{ props.errors.name }}</p>
          </div>

          <!-- Descrição -->
          <div>
            <label for="description" class="block text-sm font-medium text-gray-700">
              Descrição
            </label>
            <textarea
              id="description"
              v-model="form.description"
              rows="3"
              class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
              :class="{ 'border-red-300': props.errors.description }"
            ></textarea>
            <p v-if="props.errors.description" class="mt-1 text-sm text-red-600">{{ props.errors.description }}</p>
          </div>

          <!-- Cor -->
          <div>
            <label for="color" class="block text-sm font-medium text-gray-700">
              Cor
            </label>
            <div class="mt-1 flex items-center space-x-4">
              <input
                id="color"
                v-model="form.color"
                type="color"
                class="h-10 w-20 rounded border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                :class="{ 'border-red-300': props.errors.color }"
              />
              <input
                v-model="form.color"
                type="text"
                class="block w-32 rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 sm:text-sm"
                :class="{ 'border-red-300': props.errors.color }"
                placeholder="#1e40af"
              />
            </div>
            <p v-if="props.errors.color" class="mt-1 text-sm text-red-600">{{ props.errors.color }}</p>
          </div>

          <!-- Status -->
          <div class="flex items-center">
            <input
              id="is_active"
              v-model="form.is_active"
              type="checkbox"
              class="h-4 w-4 text-blue-600 focus:ring-blue-500 border-gray-300 rounded"
            />
            <label for="is_active" class="ml-2 block text-sm text-gray-900">
              Tipo de evento ativo
            </label>
          </div>

          <!-- Botões -->
          <div class="flex items-center justify-end space-x-4 pt-4 border-t border-gray-200">
            <Link
              :href="route('event-types.index')"
              class="inline-flex items-center px-4 py-2 bg-gray-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-gray-700 active:bg-gray-900 focus:outline-none focus:border-gray-900 focus:ring focus:ring-gray-300 disabled:opacity-25 transition"
            >
              Cancelar
            </Link>
            <button
              type="submit"
              :disabled="form.processing"
              class="inline-flex items-center px-4 py-2 bg-blue-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-blue-700 active:bg-blue-900 focus:outline-none focus:border-blue-900 focus:ring focus:ring-blue-300 disabled:opacity-25 transition"
            >
              <span v-if="form.processing">Atualizando...</span>
              <span v-else>Atualizar</span>
            </button>
          </div>
        </form>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { reactive } from 'vue';
import { router, Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  eventType: {
    type: Object,
    required: true,
  },
  errors: {
    type: Object,
    default: () => ({}),
  },
});

const form = useForm({
  name: props.eventType?.name || '',
  description: props.eventType?.description || '',
  color: props.eventType?.color || '#1e40af',
  is_active: props.eventType?.is_active ?? true,
});

const submit = () => {
  form.put(route('event-types.update', props.eventType?.id));
};
</script>

