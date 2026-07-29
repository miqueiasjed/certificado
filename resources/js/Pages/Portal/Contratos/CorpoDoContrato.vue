<template>
  <div>
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <p class="text-sm font-semibold text-gray-900">Contrato {{ contrato.contract_number ?? contrato.id }}</p>
        <p class="mt-1 truncate text-sm text-gray-600">{{ contrato.address || 'Endereço não informado' }}</p>
      </div>
      <span
        class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium"
        :class="encerrado ? 'bg-gray-100 text-gray-800' : 'bg-green-100 text-green-800'">
        {{ encerrado ? 'Encerrado' : 'Vigente' }}
      </span>
    </div>

    <dl class="mt-3 grid gap-x-6 gap-y-1.5 text-sm text-gray-600 sm:grid-cols-2">
      <div>
        <span class="font-medium text-gray-700">Período:</span>
        {{ formatarData(contrato.start_date) }} até {{ contrato.end_date ? formatarData(contrato.end_date) : 'sem data de término' }}
      </div>
      <div v-if="contrato.service_type">
        <span class="font-medium text-gray-700">Tipo de serviço:</span> {{ contrato.service_type }}
      </div>
      <div v-if="periodicidade">
        <span class="font-medium text-gray-700">Periodicidade:</span> {{ periodicidade }}
      </div>
      <div v-if="contrato.visit_count">
        <span class="font-medium text-gray-700">Visitas previstas no contrato:</span> {{ contrato.visit_count }}
      </div>
      <div v-if="contrato.pest_target">
        <span class="font-medium text-gray-700">Praga-alvo:</span> {{ contrato.pest_target }}
      </div>
      <div v-if="contrato.service_value !== null && contrato.service_value !== undefined">
        <span class="font-medium text-gray-700">Valor:</span> R$ {{ formatarMoeda(contrato.service_value) }}
      </div>
      <div v-if="contrato.payment_method">
        <span class="font-medium text-gray-700">Forma de pagamento:</span> {{ contrato.payment_method }}
      </div>
      <div v-if="contrato.jurisdiction">
        <span class="font-medium text-gray-700">Foro:</span> {{ contrato.jurisdiction }}
      </div>
    </dl>

    <p v-if="contrato.additional_clause" class="mt-3 text-sm text-gray-600">
      <span class="font-medium text-gray-700">Cláusula adicional:</span> {{ contrato.additional_clause }}
    </p>

    <!--
      Próximas visitas previstas (Task 15.7): relacionadas pelo controller
      (`PortalController::comProximasVisitas()`) comparando o endereço do
      contrato com o das próximas visitas do cliente, já que nenhum dos dois
      arrays expõe `address_id` ao portal.
    -->
    <div v-if="proximasVisitas.length" class="mt-4 border-t border-gray-100 pt-4">
      <p class="text-sm font-medium text-gray-700">Próximas visitas previstas</p>
      <ul class="mt-2 space-y-1.5">
        <li v-for="visita in proximasVisitas" :key="visita.id" class="text-sm text-gray-600">
          {{ formatarData(visita.scheduled_date) }}
          <span v-if="visita.service"> - {{ visita.service }}</span>
        </li>
      </ul>
    </div>

    <!--
      Download do contrato: não citado explicitamente na especificação da Task
      15.7 para esta tela (só Visitas/Certificados citam o botão), mas
      `routes/portal.php` já aceita `tipo=contrato` em
      `portal.documentos.download` (Task 15.4/15.3, `PortalService::documento()`),
      então oferecer o link segue a mesma regra geral do projeto ("documento
      emitido tem valor perante fiscalização") sem exigir nenhuma rota nova.
    -->
    <a
      :href="route('portal.documentos.download', { tipo: 'contrato', id: contrato.id })"
      class="mt-4 inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium text-white shadow-sm transition-opacity hover:opacity-90"
      style="background-color: var(--portal-cor-primaria)">
      <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"></path>
      </svg>
      Baixar contrato
    </a>
  </div>
</template>

<script setup>
import { computed } from 'vue';
import { formatarData } from '@/utils/formatDate.js';

const props = defineProps({
  contrato: {
    type: Object,
    required: true,
  },
  encerrado: {
    type: Boolean,
    default: false,
  },
});

const proximasVisitas = computed(() => props.contrato.proximas_visitas ?? []);

const periodicidade = computed(() => {
  if (!props.contrato.visit_frequency_valor || !props.contrato.visit_frequency_unidade) {
    return '';
  }
  return `A cada ${props.contrato.visit_frequency_valor} ${props.contrato.visit_frequency_unidade}`;
});

// Mesmo padrão de `resources/js/Pages/Contracts/Index.vue` (`formatCurrency`):
// `Number.prototype.toLocaleString` para formatar moeda não é abrangido pela
// proibição da skill de datas e timezone, que trata de `Date.prototype.toLocaleDateString`
// e afins - aqui não há data nenhuma envolvida, só valor monetário.
const formatarMoeda = (valor) => {
  if (valor === null || valor === undefined) {
    return '0,00';
  }
  return Number(valor).toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
};
</script>
