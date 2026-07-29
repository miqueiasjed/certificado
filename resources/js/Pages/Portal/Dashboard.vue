<template>
  <PortalLayout>
    <template #header>
      <PageHeader title="Início" description="Resumo do que precisa da sua atenção." />
    </template>

    <div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
      <!-- Próxima visita -->
      <Card padding="none" class="flex flex-col">
        <div class="border-b border-gray-200 px-6 py-4">
          <h3 class="text-sm font-semibold text-gray-900">Próxima visita</h3>
        </div>

        <div class="flex-1 px-6 py-5">
          <div v-if="!proximaVisita" class="text-center">
            <p class="text-sm text-gray-500">Nenhuma visita agendada no momento.</p>
          </div>
          <div v-else>
            <p class="text-lg font-semibold text-gray-900">{{ formatarData(proximaVisita.scheduled_date) }}</p>
            <p v-if="proximaVisita.start_time" class="text-sm text-gray-500">
              A partir de {{ formatarHora(proximaVisita.start_time) }}
            </p>
            <p v-if="proximaVisita.service" class="mt-2 text-sm text-gray-700">{{ proximaVisita.service }}</p>
            <p v-if="proximaVisita.address" class="mt-1 text-sm text-gray-500">{{ proximaVisita.address }}</p>
          </div>
        </div>

        <div class="border-t border-gray-100 px-6 py-3">
          <Link :href="route('portal.visitas')" class="text-sm font-medium text-green-700 hover:text-green-800">
            Ver todas as visitas
          </Link>
        </div>
      </Card>

      <!-- Pendências em aberto -->
      <Card padding="none" class="flex flex-col">
        <div class="border-b border-gray-200 px-6 py-4">
          <h3 class="text-sm font-semibold text-gray-900">Pendências em aberto</h3>
        </div>

        <div class="flex-1 px-6 py-5">
          <div v-if="adequacoesEmAberto.length === 0" class="text-center">
            <p class="text-sm text-gray-500">Nenhuma pendência em aberto.</p>
          </div>
          <div v-else class="space-y-3">
            <p class="text-lg font-semibold text-gray-900">
              {{ adequacoesEmAberto.length }} {{ adequacoesEmAberto.length === 1 ? 'pendência' : 'pendências' }}
            </p>
            <ul class="space-y-2">
              <li v-for="adequacao in adequacoesEmAberto.slice(0, 3)" :key="adequacao.id" class="text-sm">
                <p class="truncate text-gray-700">{{ adequacao.description }}</p>
                <p v-if="adequacao.deadline" class="text-xs" :class="estaVencida(adequacao.deadline) ? 'font-medium text-red-700' : 'text-gray-500'">
                  Prazo: {{ formatarData(adequacao.deadline) }}
                </p>
              </li>
            </ul>
          </div>
        </div>

        <div class="border-t border-gray-100 px-6 py-3">
          <Link :href="route('portal.adequacoes')" class="text-sm font-medium text-green-700 hover:text-green-800">
            Ver todas as pendências
          </Link>
        </div>
      </Card>

      <!-- Faturas vencendo -->
      <Card padding="none" class="flex flex-col">
        <div class="border-b border-gray-200 px-6 py-4">
          <h3 class="text-sm font-semibold text-gray-900">Faturas vencendo</h3>
        </div>

        <div class="flex-1 px-6 py-5">
          <div v-if="faturasVencendo.length === 0" class="text-center">
            <p class="text-sm text-gray-500">Nenhuma fatura vencendo.</p>
          </div>
          <div v-else class="space-y-3">
            <p class="text-lg font-semibold text-gray-900">
              {{ faturasVencendo.length }} {{ faturasVencendo.length === 1 ? 'fatura' : 'faturas' }}
            </p>
            <ul class="space-y-2">
              <li v-for="fatura in faturasVencendo.slice(0, 3)" :key="fatura.id" class="flex items-center justify-between text-sm">
                <span class="text-gray-700">{{ formatarData(fatura.payment_due_date) }}</span>
                <span class="font-medium text-gray-900">{{ formatarMoeda(fatura.amount) }}</span>
              </li>
            </ul>
          </div>
        </div>

        <div class="border-t border-gray-100 px-6 py-3">
          <Link :href="route('portal.faturas')" class="text-sm font-medium text-green-700 hover:text-green-800">
            Ver todas as faturas
          </Link>
        </div>
      </Card>
    </div>
  </PortalLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import { formatarData, formatarHora, diasAte } from '@/utils/formatDate';

// As três listas vêm prontas de `PortalController::index()` (Task 15.4), cada uma já
// filtrada e ordenada pelo `PortalService` (Task 15.3): `proximasVisitas` da mais
// próxima para a mais distante, `adequacoesEmAberto` por prazo, `faturasVencendo` por
// vencimento. Nenhuma paginação aqui - é só a prévia do painel.
const props = defineProps({
  proximasVisitas: {
    type: Array,
    required: true,
  },
  adequacoesEmAberto: {
    type: Array,
    required: true,
  },
  faturasVencendo: {
    type: Array,
    required: true,
  },
});

// A mais próxima é a primeira: `PortalService::proximasVisitas()` já ordena por
// `scheduled_date` crescente.
const proximaVisita = computed(() => props.proximasVisitas[0] ?? null);

function estaVencida(deadline) {
  if (!deadline) return false;

  const dias = diasAte(deadline);

  return dias !== null && dias < 0;
}

function formatarMoeda(valor) {
  return new Intl.NumberFormat('pt-BR', {
    style: 'currency',
    currency: 'BRL',
  }).format(Number(valor) || 0);
}
</script>
