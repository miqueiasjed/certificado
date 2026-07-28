<template>
  <PlataformaLayout>
    <template #header>
      <PageHeader
        title="Módulos"
        description="Catálogo de módulos controláveis do produto e como cada um está distribuído entre planos e tenants." />
    </template>

    <div class="space-y-6">
      <!-- Mensagens flash -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!-- Atalhos para as duas telas de edição: cada uma exige escolher um
           plano ou um tenant antes de abrir, e essa escolha já é feita nas
           telas de Planos e de Tenants, que ganharam a ação "Módulos". -->
      <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <Card>
          <h3 class="text-base font-medium text-gray-900 mb-1">Editar por plano</h3>
          <p class="text-sm text-gray-500 mb-4">
            Abra um plano na lista de planos e use a ação "Módulos" para marcar o que ele libera para quem o assina.
          </p>
          <Link :href="route('plataforma.planos.index')" class="btn-secondary">Ver planos</Link>
        </Card>

        <Card>
          <h3 class="text-base font-medium text-gray-900 mb-1">Editar por tenant</h3>
          <p class="text-sm text-gray-500 mb-4">
            Busque um tenant na lista de tenants e use a ação "Módulos" para liberar ou bloquear pontualmente.
          </p>
          <Link :href="route('plataforma.tenants.index')" class="btn-secondary">Ver tenants</Link>
        </Card>
      </div>

      <!-- Catálogo -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Catálogo de módulos</h3>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Módulo</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Descrição</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Sempre ativo</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Planos que liberam</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tenants ativos</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="modulo in modulos" :key="modulo.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                  {{ modulo.nome }}
                  <span class="block text-xs text-gray-400">{{ modulo.chave }}</span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-500 max-w-xs">
                  {{ modulo.descricao || '-' }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span
                    v-if="modulo.sempre_ativo"
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                    Sempre ativo
                  </span>
                  <span v-else class="text-sm text-gray-400">-</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ modulo.planos_count }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ modulo.tenants_count }}</td>
              </tr>
              <tr v-if="!modulos.length">
                <td colspan="5" class="px-6 py-4 text-sm text-gray-500 text-center">Nenhum módulo cadastrado.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  </PlataformaLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import PlataformaLayout from '@/Layouts/PlataformaLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

defineProps({
  modulos: {
    type: Array,
    default: () => [],
  },
});
</script>
