<template>
  <PlataformaLayout>
    <template #header>
      <PageHeader title="Relatos de problema" description="O que usuários do painel e do portal relataram pelo botão &quot;Relatar problema&quot;" />
    </template>

    <div class="space-y-6">
      <!-- Mensagens flash -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>

      <!-- Filtros -->
      <Card>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-4">
          <div>
            <label for="situacao" class="block text-sm font-medium text-gray-700 mb-1">Situação</label>
            <select
              id="situacao"
              v-model="situacaoFiltro"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm"
              @change="aplicarFiltros"
            >
              <option value="">Todas</option>
              <option v-for="situacao in situacoes" :key="situacao" :value="situacao">
                {{ ROTULOS_SITUACAO_RELATO[situacao] || situacao }}
              </option>
            </select>
          </div>
        </div>
      </Card>

      <!-- Lista -->
      <Card padding="none">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Relato</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tenant</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Quem relatou</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Recebido em</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Situação</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Suporte técnico</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="relato in relatos.data" :key="relato.id" class="hover:bg-gray-50">
                <td class="px-6 py-4">
                  <Link
                    :href="route('plataforma.relatos.show', relato.id)"
                    class="text-sm font-medium text-gray-900 hover:text-green-700"
                  >
                    {{ relato.titulo }}
                  </Link>
                  <p class="text-xs text-gray-500">#{{ relato.id }}</p>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                  {{ relato.company?.name || '-' }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                  {{ quemRelatou(relato) }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                  {{ formatarDataHora(relato.created_at) }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                    :class="CLASSES_SITUACAO_RELATO[relato.situacao] || CLASSES_SITUACAO_RELATO.novo"
                  >
                    {{ ROTULOS_SITUACAO_RELATO[relato.situacao] || relato.situacao }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <span
                    v-if="relato.enviado_em"
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"
                  >
                    Enviado ao suporte técnico
                  </span>
                  <span v-else class="text-gray-500">Não enviado</span>
                </td>
              </tr>
            </tbody>
          </table>

          <!-- Estado vazio -->
          <div v-if="!relatos.data || relatos.data.length === 0" class="text-center py-12">
            <h3 class="text-sm font-medium text-gray-900">Nenhum relato encontrado</h3>
            <p class="mt-1 text-sm text-gray-500">Os relatos feitos pelo botão "Relatar problema" aparecem aqui.</p>
          </div>
        </div>
      </Card>

      <Pagination v-if="relatos.data?.length" :links="relatos.links" />
    </div>
  </PlataformaLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import PlataformaLayout from '@/Layouts/PlataformaLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatarDataHora } from '@/utils/formatDate';
import { CLASSES_SITUACAO_RELATO, ROTULOS_SITUACAO_RELATO, quemRelatou } from '@/utils/relatos';

const props = defineProps({
  relatos: {
    type: Object,
    required: true,
  },
  situacoes: {
    type: Array,
    required: true,
  },
  filtros: {
    type: Object,
    default: () => ({}),
  },
});

const situacaoFiltro = ref(props.filtros.situacao || '');

const aplicarFiltros = () => {
  router.get(
    route('plataforma.relatos.index'),
    situacaoFiltro.value ? { situacao: situacaoFiltro.value } : {},
    { preserveState: true, preserveScroll: true, replace: true }
  );
};
</script>
