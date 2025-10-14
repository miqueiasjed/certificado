<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader :title="eventType?.name" description="Detalhes do tipo de evento">
        <template #actions>
          <Link
            :href="route('event-types.edit', eventType?.id)"
            class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 active:bg-indigo-900 focus:outline-none focus:border-indigo-900 focus:ring focus:ring-indigo-300 disabled:opacity-25 transition mr-3"
          >
            Editar
          </Link>
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
        <div class="p-6 space-y-6">
          <!-- Nome -->
          <div>
            <label class="block text-sm font-medium text-gray-500 mb-1">
              Nome
            </label>
            <p class="text-lg font-semibold text-gray-900">{{ eventType?.name }}</p>
          </div>

          <!-- Descrição -->
          <div>
            <label class="block text-sm font-medium text-gray-500 mb-1">
              Descrição
            </label>
            <p class="text-gray-900">{{ eventType?.description || '-' }}</p>
          </div>

          <!-- Cor -->
          <div>
            <label class="block text-sm font-medium text-gray-500 mb-1">
              Cor
            </label>
            <div class="flex items-center space-x-3">
              <div
                class="w-12 h-12 rounded-lg border-2 border-gray-300"
                :style="{ backgroundColor: eventType?.color }"
              ></div>
              <span class="text-gray-900 font-mono">{{ eventType?.color }}</span>
            </div>
          </div>

          <!-- Status -->
          <div>
            <label class="block text-sm font-medium text-gray-500 mb-1">
              Status
            </label>
            <span
              :class="[
                'px-3 py-1 inline-flex text-sm leading-5 font-semibold rounded-full',
                eventType?.is_active
                  ? 'bg-green-100 text-green-800'
                  : 'bg-red-100 text-red-800'
              ]"
            >
              {{ eventType?.is_active ? 'Ativo' : 'Inativo' }}
            </span>
          </div>

          <!-- Informações Adicionais -->
          <div class="pt-6 border-t border-gray-200">
            <h3 class="text-sm font-medium text-gray-500 mb-4">Informações Adicionais</h3>
            <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <dt class="text-sm font-medium text-gray-500">Criado em</dt>
                <dd class="mt-1 text-sm text-gray-900">
                  {{ formatDate(eventType?.created_at) }}
                </dd>
              </div>
              <div>
                <dt class="text-sm font-medium text-gray-500">Última atualização</dt>
                <dd class="mt-1 text-sm text-gray-900">
                  {{ formatDate(eventType?.updated_at) }}
                </dd>
              </div>
            </dl>
          </div>
        </div>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  eventType: {
    type: Object,
    required: true,
  },
});

const formatDate = (date) => {
  if (!date) return '-';
  const d = new Date(date);
  return d.toLocaleDateString('pt-BR', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  });
};
</script>

