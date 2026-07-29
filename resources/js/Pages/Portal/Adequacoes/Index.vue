<template>
  <PortalLayout>
    <template #header>
      <PageHeader
        title="Pendências"
        description="O que precisa ser resolvido no seu espaço para manter o controle de pragas em dia." />
    </template>

    <div class="space-y-6">
      <div v-if="grupos.length === 0" class="rounded-lg border border-gray-200 bg-white p-8 text-center">
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-100">
          <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <h3 class="mt-3 text-sm font-medium text-gray-900">Nenhuma pendência em aberto</h3>
        <p class="mt-1 text-sm text-gray-500">Não há nada pendente de ajuste no seu espaço no momento.</p>
      </div>

      <Card v-for="grupo in grupos" :key="grupo.chave" padding="none">
        <div class="border-b border-gray-200 px-6 py-4">
          <h3 class="text-sm font-semibold text-gray-900">
            {{ grupo.visita ? `Visita ${grupo.visita}` : 'Visita não identificada' }}
          </h3>
          <p v-if="grupo.dataDaVisita" class="mt-0.5 text-xs text-gray-500">
            Realizada em {{ formatarData(grupo.dataDaVisita) }}
          </p>
        </div>

        <ul class="divide-y divide-gray-100">
          <li
            v-for="item in grupo.itens"
            :key="item.id"
            class="px-6 py-4"
            :class="estaVencida(item.deadline) ? 'bg-red-50' : ''">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div class="min-w-0 flex-1">
                <span class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                  {{ rotuloDoTipo(item.type) }}
                </span>
                <p class="mt-2 text-sm text-gray-900">{{ item.description }}</p>
                <p v-if="item.visit_scheduled_date" class="mt-1 text-xs text-gray-500">
                  Identificada na visita de {{ formatarData(item.visit_scheduled_date) }}
                </p>

                <!--
                  Foto da pendência: `CamposVisiveisAoCliente::ADEQUACAO` (Task 15.3) não
                  expõe nenhum campo de foto hoje, embora `WorkOrderAdequation` tenha fotos
                  internamente (ver `WorkOrderTabContent.vue`, `getPhotosForAdequation()`,
                  e o app do técnico). Os dois blocos abaixo cobrem `item.foto` (uma só) e
                  `item.fotos` (lista), de propósito, para o dia em que o backend passar a
                  expor essa foto ao portal - até lá, nenhum dos dois aparece, porque o
                  campo nunca vem.
                -->
                <div v-if="item.foto" class="mt-3">
                  <img :src="item.foto" alt="Foto da pendência" class="h-32 w-full rounded-md object-cover sm:w-48">
                </div>
                <div v-else-if="Array.isArray(item.fotos) && item.fotos.length" class="mt-3 flex flex-wrap gap-2">
                  <img
                    v-for="(foto, indice) in item.fotos"
                    :key="indice"
                    :src="foto"
                    alt="Foto da pendência"
                    class="h-20 w-20 rounded-md object-cover">
                </div>
              </div>

              <div class="shrink-0 text-left sm:text-right">
                <span
                  v-if="!item.deadline"
                  class="inline-flex items-center rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600">
                  Sem prazo definido
                </span>
                <span
                  v-else-if="estaVencida(item.deadline)"
                  class="inline-flex items-center rounded-full bg-red-100 px-2.5 py-1 text-xs font-medium text-red-800">
                  Prazo encerrado em {{ formatarData(item.deadline) }}
                </span>
                <span
                  v-else-if="venceEmBreve(item.deadline)"
                  class="inline-flex items-center rounded-full bg-yellow-100 px-2.5 py-1 text-xs font-medium text-yellow-800">
                  Prazo até {{ formatarData(item.deadline) }}
                </span>
                <span
                  v-else
                  class="inline-flex items-center rounded-full bg-blue-100 px-2.5 py-1 text-xs font-medium text-blue-800">
                  Prazo até {{ formatarData(item.deadline) }}
                </span>
              </div>
            </div>
          </li>
        </ul>
      </Card>

      <Pagination v-if="adequacoes.data.length > 0" :links="adequacoes.links" />
    </div>
  </PortalLayout>
</template>

<script setup>
import { computed } from 'vue';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatarData, diasAte } from '@/utils/formatDate';

// `adequacoes` é o `LengthAwarePaginator` montado em `PortalController::paginar()`
// (Task 15.4) sobre o array já filtrado por `CamposVisiveisAoCliente::adequacao()`
// (Task 15.3): cada item tem só `id, type, description, priority, status, status_text,
// deadline, visit_order_number, visit_scheduled_date`.
const props = defineProps({
  adequacoes: {
    type: Object,
    required: true,
  },
});

const TIPOS = {
  estrutural: 'Estrutural',
  sanitario: 'Sanitário',
  higienico: 'Higiênico',
  quimico: 'Químico',
  outros: 'Outros',
};

function rotuloDoTipo(tipo) {
  return TIPOS[tipo] || tipo;
}

/**
 * Agrupamento por endereço, como pede a Task 15.8, não é possível hoje:
 * `CamposVisiveisAoCliente::ADEQUACAO` (Task 15.3) não traz `address` nem
 * `visit_address` - só `visit_order_number` e `visit_scheduled_date`, da
 * visita a que a adequação pertence. Como cada visita atende um único
 * endereço, agrupar por `visit_order_number` é o proxy mais próximo do
 * agrupamento por endereço disponível com o dado atual. Lacuna documentada:
 * se `CamposVisiveisAoCliente::adequacao()` passar a expor o endereço da
 * visita, trocar a chave abaixo por ele reagrupa a tela sem tocar no resto.
 */
const grupos = computed(() => {
  const mapa = new Map();

  for (const item of props.adequacoes.data) {
    const chave = item.visit_order_number ?? 'sem-visita';

    if (!mapa.has(chave)) {
      mapa.set(chave, {
        chave,
        visita: item.visit_order_number,
        dataDaVisita: item.visit_scheduled_date,
        itens: [],
      });
    }

    mapa.get(chave).itens.push(item);
  }

  return Array.from(mapa.values());
});

/**
 * Vencido é calculado aqui só para o destaque visual desta tela. O prazo em
 * si (`deadline`) já vem calculado e gravado no servidor - esta comparação
 * client-side nunca decide nada de negócio, só a cor do badge.
 */
function estaVencida(deadline) {
  if (!deadline) return false;

  const dias = diasAte(deadline);

  return dias !== null && dias < 0;
}

function venceEmBreve(deadline) {
  if (!deadline) return false;

  const dias = diasAte(deadline);

  return dias !== null && dias >= 0 && dias <= 3;
}
</script>
