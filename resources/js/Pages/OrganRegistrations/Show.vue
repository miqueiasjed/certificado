<template>
  <AuthenticatedLayout>
    <PageHeader title="Detalhes do Registro Ministerial" />

    <Card>
      <div class="space-y-6">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            Registro
          </label>
          <div class="text-lg text-gray-900">{{ organRegistration.record }}</div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            Criado em
          </label>
          <div class="text-gray-900">{{ formatDate(organRegistration.created_at) }}</div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-2">
            Atualizado em
          </label>
          <div class="text-gray-900">{{ formatDate(organRegistration.updated_at) }}</div>
        </div>
      </div>

      <div class="flex justify-end space-x-3 mt-8">
        <Link
          href="/organ-registrations"
          class="px-4 py-2 text-gray-600 bg-gray-100 rounded-md hover:bg-gray-200 transition-colors"
        >
          Voltar
        </Link>
        <Link
          :href="`/organ-registrations/${organRegistration.id}/edit`"
          class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 transition-colors"
        >
          Editar
        </Link>
      </div>
    </Card>
  </AuthenticatedLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3'
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue'
import PageHeader from '@/Components/PageHeader.vue'
import Card from '@/Components/Card.vue'

const props = defineProps({
  organRegistration: Object,
})

const formatDate = (date) => {
  return new Date(date).toLocaleDateString('pt-BR')
}
</script>
