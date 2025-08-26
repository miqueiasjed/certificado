<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Editar Serviço" :description="`Editando: ${service.name}`">
        <template #actions>
          <Link :href="`/services/${service.id}`" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path>
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path>
            </svg>
            Ver Serviço
          </Link>
          <Link href="/services" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-2xl mx-auto">
      <form @submit.prevent="submitForm" class="space-y-6">
        <!-- Informações Básicas -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Informações Básicas</h3>
          </div>
          <div class="p-6 space-y-4">
            <div>
              <label for="name" class="block text-sm font-medium text-gray-700 mb-1">
                Nome do Serviço *
              </label>
              <input
                type="text"
                id="name"
                v-model="form.name"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                :class="{ 'border-red-500': errors.name }"
                placeholder="Digite o nome do serviço"
              />
              <p v-if="errors.name" class="mt-1 text-sm text-red-600">{{ errors.name }}</p>
            </div>

            <div>
              <label for="description" class="block text-sm font-medium text-gray-700 mb-1">
                Descrição
              </label>
              <textarea
                id="description"
                v-model="form.description"
                rows="3"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                :class="{ 'border-red-500': errors.description }"
                placeholder="Descreva o serviço oferecido..."
              ></textarea>
              <p v-if="errors.description" class="mt-1 text-sm text-red-600">{{ errors.description }}</p>
            </div>
          </div>
        </Card>

        <!-- Informações Comerciais -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Informações Comerciais</h3>
          </div>
          <div class="p-6 space-y-4">
            <div>
              <label for="category" class="block text-sm font-medium text-gray-700 mb-1">
                Categoria
              </label>
              <input
                type="text"
                id="category"
                v-model="form.category"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                :class="{ 'border-red-500': errors.category }"
                placeholder="Ex: Análise, Certificação, Consultoria..."
              />
              <p v-if="errors.category" class="mt-1 text-sm text-red-600">{{ errors.category }}</p>
            </div>

            <div>
              <label for="price" class="block text-sm font-medium text-gray-700 mb-1">
                Preço
              </label>
              <div class="relative">
                <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                  <span class="text-gray-500 sm:text-sm">R$</span>
                </div>
                <input
                  type="number"
                  id="price"
                  v-model="form.price"
                  step="0.01"
                  min="0"
                  class="w-full pl-12 pr-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.price }"
                  placeholder="0,00"
                />
              </div>
              <p v-if="errors.price" class="mt-1 text-sm text-red-600">{{ errors.price }}</p>
            </div>

            <div>
              <label for="is_active" class="flex items-center">
                <input
                  type="checkbox"
                  id="is_active"
                  v-model="form.is_active"
                  class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded"
                />
                <span class="ml-2 text-sm text-gray-700">Serviço ativo</span>
              </label>
            </div>
          </div>
        </Card>

        <!-- Observações -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Observações</h3>
          </div>
          <div class="p-6 space-y-4">
            <div>
              <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">
                Observações Adicionais
              </label>
              <textarea
                id="notes"
                v-model="form.notes"
                rows="3"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                :class="{ 'border-red-500': errors.notes }"
                placeholder="Observações sobre o serviço..."
              ></textarea>
              <p v-if="errors.notes" class="mt-1 text-sm text-red-600">{{ errors.notes }}</p>
            </div>
          </div>
        </Card>

        <!-- Botões de Ação -->
        <div class="flex justify-end space-x-3">
          <Link :href="`/services/${service.id}`" class="btn-secondary">
            Cancelar
          </Link>
          <button type="submit" class="btn-primary" :disabled="form.processing">
            <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ form.processing ? 'Salvando...' : 'Salvar Alterações' }}
          </button>
        </div>
      </form>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3';
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  service: Object,
  errors: Object,
});

const form = useForm({
  name: props.service.name || '',
  description: props.service.description || '',
  category: props.service.category || '',
  price: props.service.price || '',
  is_active: props.service.is_active || false,
  notes: props.service.notes || '',
});

const submitForm = () => {
  form.put(`/services/${props.service.id}`, {
    onSuccess: () => {
      // Sucesso
    },
  });
};
</script>
