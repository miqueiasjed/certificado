<template>
  <Head title="Certificados" />

  <PortalLayout>
    <template #header>
      <PageHeader
        title="Certificados"
        description="Certificados emitidos para o seu endereço, vigentes e vencidos." />
    </template>

    <div class="space-y-6">
      <!-- Estado vazio: nenhum certificado emitido -->
      <div v-if="certificados.data.length === 0" class="rounded-lg border border-gray-200 bg-white p-8 text-center">
        <svg class="mx-auto h-10 w-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
        <h3 class="mt-3 text-sm font-medium text-gray-900">Nenhum certificado por aqui ainda</h3>
        <p class="mt-1 text-sm text-gray-500">
          Assim que uma visita gerar um certificado para o seu endereço, ele aparece nesta tela.
        </p>
      </div>

      <template v-else>
        <!--
          Vigentes e vencidos, sempre separados (Task 15.7). O agrupamento usa
          o campo `status` calculado pelo backend
          (`Certificate::getCalculatedStatusAttribute()`, exposto como `status`
          em `CamposVisiveisAoCliente::certificado()`): "vigente"/"vencido"
          nunca é calculado aqui a partir da data de validade, exatamente pela
          regra de negócio da Task 15.7 ("calcular no navegador daria resultado
          diferente para cliente com o relógio em outro fuso").

          `status` só assume `active`/`expired`/`cancelled` (não existe
          `draft` vivo hoje, ver o cabeçalho de `PortalService`). Certificado
          cancelado entra no grupo "vencidos" (não é vigente), mas continua
          com o próprio selo (`status_text` = "Cancelado"), nunca escondido -
          mesma regra do certificado vencido, e pelo mesmo motivo: o cliente
          pode precisar dele para auditoria de período passado.

          O agrupamento acontece só sobre os itens desta página (20 por vez):
          não existe consulta separada por grupo no backend, então um
          certificado vencido antigo pode aparecer só numa página mais adiante
          da listagem, igual a qualquer outra paginação do sistema.
        -->
        <section>
          <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">
            Vigentes ({{ vigentes.length }})
          </h2>
          <p v-if="vigentes.length === 0" class="mt-2 text-sm text-gray-500">
            Nenhum certificado vigente nesta página.
          </p>
          <div v-else class="mt-3 space-y-3">
            <Card v-for="certificado in vigentes" :key="certificado.id" variant="bordered" padding="normal">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-gray-900">Certificado {{ certificado.certificate_number ?? certificado.id }}</p>
                  <p class="mt-1 truncate text-sm text-gray-600">{{ certificado.address || 'Endereço não informado' }}</p>
                </div>
                <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium" :class="classeDoStatus(certificado.status)">
                  {{ certificado.status_text }}
                </span>
              </div>
              <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm text-gray-600 sm:grid-cols-3">
                <div v-if="certificado.service"><span class="font-medium text-gray-700">Serviço:</span> {{ certificado.service }}</div>
                <div><span class="font-medium text-gray-700">Emitido em:</span> {{ formatarData(certificado.execution_date) }}</div>
                <div><span class="font-medium text-gray-700">Válido até:</span> {{ certificado.warranty ? formatarData(certificado.warranty) : 'Sem prazo de validade' }}</div>
              </dl>
              <a
                :href="route('portal.documentos.download', { tipo: 'certificado', id: certificado.id })"
                class="mt-4 inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium text-white shadow-sm transition-opacity hover:opacity-90"
                style="background-color: var(--portal-cor-primaria)">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"></path>
                </svg>
                Baixar certificado
              </a>
            </Card>
          </div>
        </section>

        <section>
          <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">
            Vencidos ou cancelados ({{ vencidos.length }})
          </h2>
          <p v-if="vencidos.length === 0" class="mt-2 text-sm text-gray-500">
            Nenhum certificado vencido ou cancelado nesta página.
          </p>
          <div v-else class="mt-3 space-y-3">
            <Card v-for="certificado in vencidos" :key="certificado.id" variant="bordered" padding="normal" class="bg-gray-50">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0">
                  <p class="text-sm font-semibold text-gray-900">Certificado {{ certificado.certificate_number ?? certificado.id }}</p>
                  <p class="mt-1 truncate text-sm text-gray-600">{{ certificado.address || 'Endereço não informado' }}</p>
                </div>
                <span class="shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium" :class="classeDoStatus(certificado.status)">
                  {{ certificado.status_text }}
                </span>
              </div>
              <dl class="mt-3 grid gap-x-6 gap-y-1 text-sm text-gray-600 sm:grid-cols-3">
                <div v-if="certificado.service"><span class="font-medium text-gray-700">Serviço:</span> {{ certificado.service }}</div>
                <div><span class="font-medium text-gray-700">Emitido em:</span> {{ formatarData(certificado.execution_date) }}</div>
                <div><span class="font-medium text-gray-700">Válido até:</span> {{ certificado.warranty ? formatarData(certificado.warranty) : 'Sem prazo de validade' }}</div>
              </dl>
              <a
                :href="route('portal.documentos.download', { tipo: 'certificado', id: certificado.id })"
                class="mt-4 inline-flex items-center gap-2 rounded-md px-4 py-2 text-sm font-medium text-white shadow-sm transition-opacity hover:opacity-90"
                style="background-color: var(--portal-cor-primaria)">
                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v2a2 2 0 002 2h12a2 2 0 002-2v-2M7 10l5 5 5-5M12 15V3"></path>
                </svg>
                Baixar certificado
              </a>
            </Card>
          </div>
        </section>

        <Pagination :links="certificados.links" />
      </template>
    </div>
  </PortalLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Head } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatarData } from '@/utils/formatDate.js';

/**
 * `certificados` é o `LengthAwarePaginator` de `PortalController::certificados()`
 * (20 por página), ordenado por `execution_date` decrescente pelo
 * `PortalService::certificados()`.
 */
const props = defineProps({
  certificados: Object,
});

const vigentes = computed(() => props.certificados.data.filter((certificado) => certificado.status === 'active'));
const vencidos = computed(() => props.certificados.data.filter((certificado) => certificado.status !== 'active'));

// Cores por `status` calculado (active/expired/cancelled), tabela de status da skill de design.
const classeDoStatus = (status) => ({
  active: 'bg-green-100 text-green-800',
  expired: 'bg-red-100 text-red-800',
  cancelled: 'bg-gray-100 text-gray-800',
}[status] || 'bg-gray-100 text-gray-800');
</script>
