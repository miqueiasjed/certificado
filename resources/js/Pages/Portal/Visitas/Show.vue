<template>
  <Head :title="`Visita ${visita.order_number}`" />

  <PortalLayout>
    <template #header>
      <PageHeader
        :title="`Visita ${visita.order_number}`"
        :description="formatarDataExtensa(visita.scheduled_date)">
        <template #actions>
          <!-- Link direto para o endpoint de download (Task 15.4), nunca fetch + blob:
               o navegador trata a resposta com `Content-Disposition: attachment` como
               download nativo, sem precisar de JavaScript para montar o arquivo. -->
          <a
            :href="route('portal.documentos.download', { tipo: 'visita', id: visita.id })"
            class="inline-flex items-center justify-center gap-2 rounded-md px-4 py-2 text-sm font-medium text-white shadow-sm transition-opacity hover:opacity-90"
            style="background-color: var(--portal-cor-primaria)">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"></path>
            </svg>
            Baixar OS
          </a>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <Link :href="route('portal.visitas')" class="inline-flex items-center gap-1 text-sm font-medium text-gray-500 hover:text-gray-700">
        <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
        </svg>
        Voltar para visitas
      </Link>

      <Card>
        <div class="flex flex-wrap items-start justify-between gap-3">
          <h3 class="text-lg font-medium text-gray-900">Resumo da visita</h3>
          <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium" :class="classeDaSituacao(visita.status)">
            {{ visita.status_text }}
          </span>
        </div>

        <dl class="mt-4 grid gap-4 sm:grid-cols-2">
          <div>
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Data</dt>
            <dd class="mt-0.5 text-sm text-gray-900">{{ formatarData(visita.scheduled_date) }}</dd>
          </div>
          <div v-if="horario">
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Horário</dt>
            <dd class="mt-0.5 text-sm text-gray-900">{{ horario }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Endereço</dt>
            <dd class="mt-0.5 text-sm text-gray-900">{{ visita.address || 'Não informado' }}</dd>
          </div>
          <div v-if="visita.service">
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Serviço</dt>
            <dd class="mt-0.5 text-sm text-gray-900">{{ visita.service }}</dd>
          </div>
          <div>
            <dt class="text-xs font-medium uppercase tracking-wide text-gray-500">Técnico</dt>
            <dd class="mt-0.5 text-sm text-gray-900">{{ visita.technician || 'Não atribuído' }}</dd>
          </div>
        </dl>
      </Card>

      <!--
        O que foi feito nesta visita.

        Limitação conhecida (confirmada lendo `CamposVisiveisAoCliente::VISITA`
        e `CamposVisiveisAoCliente::visita()` no backend, Task 15.3/15.4): os
        únicos campos que o portal recebe sobre a visita são id, order_number,
        scheduled_date, start_time, end_time, status, status_text, description,
        service, technician e address. Não existe hoje `comodos`, `dispositivos`,
        `servicos` nem `produtos_previstos` na resposta - a especificação da
        Task 15.7 previa esses campos, mas eles não foram implementados na Task
        15.3/15.4 (que são a fonte de verdade dos dados disponíveis). Por isso
        esta seção mostra o único texto disponível (`description`), em vez de
        uma lista estruturada de cômodos/pragas/produtos. Para exibir isso de
        fato, uma task futura precisaria estender `CamposVisiveisAoCliente::VISITA`
        com os dados de cômodos tratados, pragas encontradas e produtos
        aplicados (hoje registrados em outras tabelas de domínio, não
        devolvidos ao portal).
      -->
      <Card>
        <h3 class="text-lg font-medium text-gray-900">O que foi feito nesta visita</h3>
        <p v-if="visita.description" class="mt-3 whitespace-pre-line text-sm text-gray-700">
          {{ visita.description }}
        </p>
        <p v-else class="mt-3 text-sm text-gray-500">
          A empresa ainda não registrou uma descrição para esta visita.
        </p>
      </Card>
    </div>
  </PortalLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import { formatarData, formatarDataExtensa, formatarHora } from '@/utils/formatDate.js';

/**
 * `visita` vem de `PortalController::visita()`, já filtrada por
 * `CamposVisiveisAoCliente::visita()`. Visita de outro cliente ou inexistente
 * nunca chega aqui: `PortalService::buscarVisita()` lança 404 antes de montar
 * a página (ver o docblock do controller).
 */
const props = defineProps({
  visita: Object,
});

// `start_time`/`end_time` são instante (Task 15.3 já converte para o fuso do
// negócio antes de virar string) - `formatarHora` lê a hora de parede, sem
// nova conversão de fuso.
const horario = computed(() => {
  if (!props.visita.start_time) {
    return '';
  }
  const inicio = formatarHora(props.visita.start_time);
  const fim = props.visita.end_time ? formatarHora(props.visita.end_time) : '';
  return fim ? `${inicio} às ${fim}` : inicio;
});

const classeDaSituacao = (status) => ({
  pending: 'bg-yellow-100 text-yellow-800',
  scheduled: 'bg-blue-100 text-blue-800',
  in_progress: 'bg-blue-100 text-blue-800',
  completed: 'bg-green-100 text-green-800',
  cancelled: 'bg-red-100 text-red-800',
  on_hold: 'bg-gray-100 text-gray-800',
}[status] || 'bg-gray-100 text-gray-800');
</script>
