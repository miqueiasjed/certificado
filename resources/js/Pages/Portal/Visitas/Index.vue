<template>
  <Head title="Visitas" />

  <PortalLayout>
    <template #header>
      <PageHeader
        title="Visitas"
        description="Histórico das visitas agendadas e realizadas no seu endereço." />
    </template>

    <div class="space-y-6">
      <!-- Estado vazio: cliente sem nenhuma visita registrada -->
      <div v-if="visitas.data.length === 0" class="rounded-lg border border-gray-200 bg-white p-8 text-center">
        <svg class="mx-auto h-10 w-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"></path>
        </svg>
        <h3 class="mt-3 text-sm font-medium text-gray-900">Nenhuma visita por aqui ainda</h3>
        <p class="mt-1 text-sm text-gray-500">
          Assim que uma visita for agendada ou realizada no seu endereço, ela aparece nesta lista.
        </p>
      </div>

      <template v-else>
        <!-- Filtros: sempre client-side, sobre os itens já carregados nesta página
             (ver comentário em `visitasFiltradas` para o porquê). -->
        <div class="rounded-lg border border-gray-200 bg-white p-4">
          <div class="grid gap-4 sm:grid-cols-3">
            <div v-if="enderecos.length > 1">
              <label class="mb-1 block text-sm font-medium text-gray-700">Endereço</label>
              <select
                v-model="filtroEndereco"
                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500">
                <option value="">Todos os endereços</option>
                <option v-for="endereco in enderecos" :key="endereco" :value="endereco">{{ endereco }}</option>
              </select>
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-gray-700">De</label>
              <input
                v-model="filtroDe"
                type="date"
                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
            <div>
              <label class="mb-1 block text-sm font-medium text-gray-700">Até</label>
              <input
                v-model="filtroAte"
                type="date"
                class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500">
            </div>
          </div>
          <button
            v-if="filtroEndereco || filtroDe || filtroAte"
            type="button"
            class="mt-3 text-sm font-medium text-gray-500 hover:text-gray-700"
            @click="limparFiltros">
            Limpar filtros
          </button>
        </div>

        <!-- Nenhum resultado para o filtro atual, mas existem visitas na página -->
        <div v-if="visitasFiltradas.length === 0" class="rounded-lg border border-gray-200 bg-white p-8 text-center">
          <p class="text-sm text-gray-500">Nenhuma visita encontrada com esse filtro nesta página. Ajuste o endereço ou o período, ou veja outra página da lista.</p>
        </div>

        <div v-else class="space-y-3">
          <Link
            v-for="visita in visitasFiltradas"
            :key="visita.id"
            :href="route('portal.visitas.show', visita.id)"
            class="block">
            <Card variant="hover" padding="normal">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-gray-900">{{ formatarData(visita.scheduled_date) }}</p>
                  <p class="mt-1 truncate text-sm text-gray-600">{{ visita.address || 'Endereço não informado' }}</p>
                </div>
                <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium" :class="classeDaSituacao(visita.status)">
                  {{ visita.status_text }}
                </span>
              </div>
              <div class="mt-3 flex flex-wrap gap-x-6 gap-y-1 text-sm text-gray-600">
                <span v-if="visita.service"><strong class="font-medium text-gray-700">Serviço:</strong> {{ visita.service }}</span>
                <span><strong class="font-medium text-gray-700">Técnico:</strong> {{ visita.technician || 'Não atribuído' }}</span>
              </div>
            </Card>
          </Link>
        </div>

        <Pagination :links="visitas.links" />
      </template>
    </div>
  </PortalLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatarData } from '@/utils/formatDate.js';

/**
 * `visitas` é o `LengthAwarePaginator` montado em `PortalController::visitas()`
 * (20 por página), já ordenado por `scheduled_date` decrescente pelo
 * `PortalService::visitas()` - nenhum sort é refeito aqui.
 */
const props = defineProps({
  visitas: Object,
});

/**
 * Endereços distintos entre as visitas desta página, para o filtro. Não é uma
 * lista de endereços do cliente vinda de uma chamada nova: é derivada dos
 * próprios dados já recebidos, como pedido na Task 15.7 ("sem chamada nova").
 * Some sozinho quando o cliente só tem um endereço (nada a filtrar).
 */
const enderecos = computed(() => {
  const vistos = new Set();
  props.visitas.data.forEach((visita) => {
    if (visita.address) {
      vistos.add(visita.address);
    }
  });
  return Array.from(vistos);
});

const filtroEndereco = ref('');
const filtroDe = ref('');
const filtroAte = ref('');

/**
 * Filtro client-side, aplicado só sobre os itens já paginados nesta página
 * (`visitas.data`, até 20 itens). `PortalController::visitas()`/
 * `PortalService::visitas()` não aceitam parâmetro de endereço ou período
 * hoje - adicionar isso pediria mexer no controller/service da Task 15.4,
 * fora do escopo desta task de frontend. Filtrar aqui é limitado (não cobre
 * outras páginas da listagem), então documentado como a opção escolhida: o
 * cliente que quiser um período fora da página atual precisa navegar pela
 * paginação. Comparação de string funciona porque `scheduled_date` chega como
 * `yyyy-MM-dd` (ordem lexicográfica = ordem cronológica).
 */
const visitasFiltradas = computed(() => {
  return props.visitas.data.filter((visita) => {
    if (filtroEndereco.value && visita.address !== filtroEndereco.value) {
      return false;
    }
    if (filtroDe.value && (!visita.scheduled_date || visita.scheduled_date < filtroDe.value)) {
      return false;
    }
    if (filtroAte.value && (!visita.scheduled_date || visita.scheduled_date > filtroAte.value)) {
      return false;
    }
    return true;
  });
});

const limparFiltros = () => {
  filtroEndereco.value = '';
  filtroDe.value = '';
  filtroAte.value = '';
};

// Cores por `status` bruto de `WorkOrder` (ver `WorkOrder::getStatusTextAttribute()`
// no backend para os valores possíveis), seguindo a tabela de cores de status
// da skill de design.
const classeDaSituacao = (status) => ({
  pending: 'bg-yellow-100 text-yellow-800',
  scheduled: 'bg-blue-100 text-blue-800',
  in_progress: 'bg-blue-100 text-blue-800',
  completed: 'bg-green-100 text-green-800',
  cancelled: 'bg-red-100 text-red-800',
  on_hold: 'bg-gray-100 text-gray-800',
}[status] || 'bg-gray-100 text-gray-800');
</script>
