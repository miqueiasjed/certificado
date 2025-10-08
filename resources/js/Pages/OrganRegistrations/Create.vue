<template>
  <AuthenticatedLayout>
    <PageHeader title="Novo Registro Ministerial" />

    <Card>
      <form @submit.prevent="submit">
        <div class="space-y-6">
          <div>
            <label for="record" class="block text-sm font-medium text-gray-700 mb-2">
              Registro *
            </label>
            <input
              id="record"
              v-model="form.record"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              :class="{ 'border-red-500': form.errors.record }"
              placeholder="Digite o número do registro ministerial"
            />
            <div v-if="form.errors.record" class="mt-1 text-sm text-red-600">
              {{ form.errors.record }}
            </div>
          </div>
        </div>

        <div class="flex justify-end space-x-3 mt-8">
          <Link
            href="/organ-registrations"
            class="px-4 py-2 text-gray-600 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors"
          >
            Cancelar
          </Link>
          <button
            type="submit"
            :disabled="form.processing"
            class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >
            {{ form.processing ? 'Salvando...' : 'Salvar' }}
          </button>
        </div>
      </form>
    </Card>
  </AuthenticatedLayout>
</template>

<script setup>
import { useForm } from '@inertiajs/vue3'
import { Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import Card from '@/Components/Card.vue'

const form = useForm({
  record: '',
})

const submit = () => {
  form.post('/organ-registrations')
}
</script>
