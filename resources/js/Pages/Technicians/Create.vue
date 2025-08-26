<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Novo Técnico" description="Cadastre um novo técnico no sistema">
        <template #actions>
          <Link href="/technicians" class="btn-secondary">
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
        <!-- Informações Pessoais -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Informações Pessoais</h3>
          </div>
          <div class="p-6 space-y-4">
            <div>
              <label for="name" class="block text-sm font-medium text-gray-700 mb-1">
                Nome Completo *
              </label>
              <input
                type="text"
                id="name"
                v-model="form.name"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                :class="{ 'border-red-500': errors.name }"
                placeholder="Digite o nome completo"
              />
              <p v-if="errors.name" class="mt-1 text-sm text-red-600">{{ errors.name }}</p>
            </div>

            <div>
              <label for="email" class="block text-sm font-medium text-gray-700 mb-1">
                E-mail *
              </label>
              <input
                type="email"
                id="email"
                v-model="form.email"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                :class="{ 'border-red-500': errors.email }"
                placeholder="Digite o e-mail"
              />
              <p v-if="errors.email" class="mt-1 text-sm text-red-600">{{ errors.email }}</p>
            </div>

            <div>
              <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">
                Telefone *
              </label>
              <input
                type="tel"
                id="phone"
                v-model="form.phone"
                @input="formatPhone"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                :class="{ 'border-red-500': errors.phone }"
                placeholder="(11) 99999-9999"
              />
              <p v-if="errors.phone" class="mt-1 text-sm text-red-600">{{ errors.phone }}</p>
            </div>
          </div>
        </Card>

        <!-- Informações Profissionais -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Informações Profissionais</h3>
          </div>
          <div class="p-6 space-y-4">
            <div>
              <label for="specialty" class="block text-sm font-medium text-gray-700 mb-1">
                Especialidade
              </label>
              <input
                type="text"
                id="specialty"
                v-model="form.specialty"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                :class="{ 'border-red-500': errors.specialty }"
                placeholder="Ex: Química, Biologia, Engenharia..."
              />
              <p v-if="errors.specialty" class="mt-1 text-sm text-red-600">{{ errors.specialty }}</p>
            </div>

            <div>
              <label for="registration_number" class="block text-sm font-medium text-gray-700 mb-1">
                Número de Registro
              </label>
              <input
                type="text"
                id="registration_number"
                v-model="form.registration_number"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                :class="{ 'border-red-500': errors.registration_number }"
                placeholder="Número de registro profissional"
              />
              <p v-if="errors.registration_number" class="mt-1 text-sm text-red-600">{{ errors.registration_number }}</p>
            </div>

            <div>
              <label for="is_active" class="flex items-center">
                <input
                  type="checkbox"
                  id="is_active"
                  v-model="form.is_active"
                  class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded"
                />
                <span class="ml-2 text-sm text-gray-700">Técnico ativo</span>
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
                placeholder="Observações sobre o técnico..."
              ></textarea>
              <p v-if="errors.notes" class="mt-1 text-sm text-red-600">{{ errors.notes }}</p>
            </div>
          </div>
        </Card>

        <!-- Botões de Ação -->
        <div class="flex justify-end space-x-3">
          <Link href="/technicians" class="btn-secondary">
            Cancelar
          </Link>
          <button type="submit" class="btn-primary" :disabled="form.processing">
            <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ form.processing ? 'Criando...' : 'Criar Técnico' }}
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
  errors: Object,
});

const form = useForm({
  name: '',
  email: '',
  phone: '',
  specialty: '',
  registration_number: '',
  is_active: true,
  notes: '',
});

const formatPhone = (event) => {
  let value = event.target.value.replace(/\D/g, '');
  if (value.length <= 11) {
    value = value.replace(/^(\d{2})(\d)/g, '($1) $2');
    value = value.replace(/(\d)(\d{4})$/, '$1-$2');
    form.phone = value;
  }
};

const submitForm = () => {
  form.post('/technicians', {
    onSuccess: () => {
      // Sucesso
    },
  });
};
</script>
