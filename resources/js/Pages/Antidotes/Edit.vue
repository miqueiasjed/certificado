<template>
  <AuthenticatedLayout>
    <PageHeader title="Editar Antídoto" />

    <Card>
      <form @submit.prevent="submit">
        <div class="space-y-6">
          <div>
            <label for="name" class="block text-sm font-medium text-gray-700 mb-2">
              Nome *
            </label>
            <input
              id="name"
              v-model="form.name"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-blue-500"
              :class="{ 'border-red-500': form.errors.name }"
              placeholder="Digite o nome do antídoto"
            />
            <div v-if="form.errors.name" class="mt-1 text-sm text-red-600">
              {{ form.errors.name }}
            </div>
          </div>
        </div>

        <div class="flex justify-end space-x-3 mt-8">
          <Link
            href="/antidotes"
            class="px-4 py-2 text-gray-600 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors"
          >
            Cancelar
          </Link>
          <button
            type="submit"
            :disabled="form.processing"
            class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 disabled:opacity-50 transition-colors"
          >
            {{ form.processing ? 'Salvando...' : 'Atualizar' }}
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

const props = defineProps({
  antidote: Object,
})

const form = useForm({
  name: props.antidote.name,
})

const submit = () => {
  form.put(`/antidotes/${props.antidote.id}`)
}
</script>
