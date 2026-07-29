<template>
  <Head title="Contratos" />

  <PortalLayout>
    <template #header>
      <PageHeader
        title="Contratos"
        description="Período, periodicidade e valor dos seus contratos de serviço." />
    </template>

    <div class="space-y-6">
      <!-- Estado vazio: nenhum contrato -->
      <div v-if="contratos.data.length === 0" class="rounded-lg border border-gray-200 bg-white p-8 text-center">
        <svg class="mx-auto h-10 w-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
        </svg>
        <h3 class="mt-3 text-sm font-medium text-gray-900">Nenhum contrato por aqui ainda</h3>
        <p class="mt-1 text-sm text-gray-500">
          Assim que um contrato for firmado para o seu endereço, ele aparece nesta tela.
        </p>
      </div>

      <template v-else>
        <!--
          Vigente/encerrado: fallback documentado. `CamposVisiveisAoCliente::CONTRATO`
          não expõe nenhum campo de status calculado para contrato (diferente
          de `Certificate.calculated_status`), e `Contract` não tem accessor
          equivalente (conferido: nenhum `get*Attribute` no model). Idealmente
          o backend calcularia isso no fuso do negócio, como faz para
          certificado. Na ausência desse campo, o fallback aqui é uma
          comparação simples de data (`diasAte`, que usa o fuso
          America/Sao_Paulo fixo do `formatDate.js`, não o fuso do navegador),
          contra `end_date`. Contrato sem `end_date` é tratado como vigente
          (contrato por prazo indeterminado).
        -->
        <section>
          <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">
            Vigentes ({{ vigentes.length }})
          </h2>
          <p v-if="vigentes.length === 0" class="mt-2 text-sm text-gray-500">
            Nenhum contrato vigente nesta página.
          </p>
          <div v-else class="mt-3 space-y-3">
            <Card v-for="contrato in vigentes" :key="contrato.id" variant="bordered" padding="normal">
              <CorpoDoContrato :contrato="contrato" />
            </Card>
          </div>
        </section>

        <section>
          <h2 class="text-sm font-semibold uppercase tracking-wide text-gray-500">
            Encerrados ({{ encerrados.length }})
          </h2>
          <p v-if="encerrados.length === 0" class="mt-2 text-sm text-gray-500">
            Nenhum contrato encerrado nesta página.
          </p>
          <div v-else class="mt-3 space-y-3">
            <Card v-for="contrato in encerrados" :key="contrato.id" variant="bordered" padding="normal" class="bg-gray-50">
              <CorpoDoContrato :contrato="contrato" encerrado />
            </Card>
          </div>
        </section>

        <Pagination :links="contratos.links" />
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
import { formatarData, diasAte } from '@/utils/formatDate.js';
import CorpoDoContrato from './CorpoDoContrato.vue';

/**
 * `contratos` é o `LengthAwarePaginator` de `PortalController::contratos()`
 * (20 por página), ordenado por `start_date` decrescente pelo
 * `PortalService::contratos()`.
 */
const props = defineProps({
  contratos: Object,
});

/**
 * Fallback de "encerrado" (ver comentário no template): `end_date` no
 * passado, comparado com hoje no fuso do negócio via `diasAte`.
 */
const estaEncerrado = (contrato) => {
  if (!contrato.end_date) {
    return false;
  }
  const dias = diasAte(contrato.end_date);
  return dias !== null && dias < 0;
};

const vigentes = computed(() => props.contratos.data.filter((contrato) => !estaEncerrado(contrato)));
const encerrados = computed(() => props.contratos.data.filter((contrato) => estaEncerrado(contrato)));
</script>
