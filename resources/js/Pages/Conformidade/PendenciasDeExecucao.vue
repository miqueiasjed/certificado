<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Pendências de execução"
        description="Ordens de serviço concluídas com documentação incompleta ou com aviso de registro de produto."
      >
        <template #actions>
          <Link :href="rotaDoChecklist" class="btn-secondary">Voltar ao checklist</Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-6xl mx-auto space-y-6">
      <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
        <h2 class="text-sm font-semibold text-amber-900">O que esta lista é</h2>
        <p class="mt-1 text-sm text-amber-800">{{ ressalva }}</p>
        <p class="mt-2 text-sm text-amber-800">
          Nada aqui impede concluir ordem de serviço, assinar ou emitir documento. É uma lista para
          completar o que falta antes de uma fiscalização.
        </p>
      </div>

      <Card>
        <form class="flex flex-col gap-4 sm:flex-row sm:items-end" @submit.prevent="filtrar">
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">De</label>
            <input
              v-model="filtro.de"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Até</label>
            <input
              v-model="filtro.ate"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>
          <div>
            <button type="submit" class="btn-primary w-full sm:w-auto">Filtrar</button>
          </div>
        </form>
      </Card>

      <Card v-if="pendencias.length === 0">
        <p class="text-sm text-gray-600">
          Nenhuma ordem de serviço concluída no período está com documentação incompleta.
        </p>
      </Card>

      <Card v-for="linha in pendencias" :key="linha.work_order_id" padding="none">
        <div class="px-6 py-4 border-b border-gray-200 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h3 class="text-base font-semibold text-gray-900">
              Ordem de serviço {{ linha.order_number || linha.work_order_id }}
            </h3>
            <p class="text-sm text-gray-500">
              Execução em {{ linha.data_da_execucao ? formatarData(linha.data_da_execucao) : 'data não informada' }}.
            </p>
          </div>
          <Link :href="route('work-orders.show', linha.work_order_id)" class="btn-secondary-sm whitespace-nowrap">
            Abrir ordem de serviço
          </Link>
        </div>

        <ul class="divide-y divide-gray-200">
          <li v-for="(item, indice) in linha.pendencias" :key="`p-${indice}`" class="px-6 py-3">
            <div class="flex flex-wrap items-center gap-2">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                Falta documentar
              </span>
              <span class="text-sm font-medium text-gray-900">{{ item.rotulo }}</span>
            </div>
            <p class="mt-1 text-sm text-gray-700">{{ item.detalhe }}</p>
            <p class="mt-1 text-sm text-gray-500">{{ item.exigencia }}</p>
          </li>

          <li v-for="(item, indice) in linha.avisos" :key="`a-${indice}`" class="px-6 py-3">
            <div class="flex flex-wrap items-center gap-2">
              <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800">
                Aviso de registro
              </span>
              <span class="text-sm font-medium text-gray-900">{{ item.rotulo }}</span>
            </div>
            <p class="mt-1 text-sm text-gray-700">{{ item.detalhe }}</p>
            <p class="mt-1 text-sm text-gray-500">{{ item.exigencia }}</p>
          </li>
        </ul>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, reactive } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import { formatarData } from '@/utils/formatDate';

const props = defineProps({
  de: {
    type: String,
    required: true,
  },
  ate: {
    type: String,
    required: true,
  },
  ressalva: {
    type: String,
    required: true,
  },
  pendencias: {
    type: Array,
    required: true,
  },
});

const rotaDoChecklist = computed(() => route('conformidade.index'));

// As datas circulam como 'yyyy-MM-dd' do começo ao fim: é o formato que o
// backend interpreta no fuso do negócio e o que `<input type="date">` usa.
// Nenhuma instância de Date no caminho, e por isso nenhum deslocamento de um
// dia.
const filtro = reactive({
  de: props.de,
  ate: props.ate,
});

function filtrar() {
  router.get(route('conformidade.pendencias-de-execucao'), { de: filtro.de, ate: filtro.ate }, {
    preserveScroll: true,
    preserveState: true,
  });
}
</script>
