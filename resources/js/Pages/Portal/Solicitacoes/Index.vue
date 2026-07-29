<template>
  <PortalLayout>
    <template #header>
      <PageHeader
        title="Solicitações"
        description="Peça atendimento à empresa e acompanhe a resposta por aqui." />
    </template>

    <div class="space-y-6">
      <Card>
        <div class="border-b border-gray-200 pb-4">
          <h3 class="text-lg font-medium text-gray-900">Abrir nova solicitação</h3>
        </div>

        <div v-if="limiteAtingido" class="mt-4 rounded-md border border-yellow-200 bg-yellow-50 p-4">
          <p class="text-sm font-medium text-yellow-800">
            Você já tem {{ abertasCount }} solicitações em aberto.
          </p>
          <p class="mt-1 text-sm text-yellow-700">
            Acompanhe as existentes na lista abaixo antes de abrir uma nova. Assim que uma delas for
            resolvida ou cancelada, o formulário libera de novo.
          </p>
        </div>

        <form v-else class="mt-4 space-y-4" @submit.prevent="enviar">
          <div>
            <label for="assunto" class="mb-1 block text-sm font-medium text-gray-700">Assunto *</label>
            <input
              id="assunto"
              v-model="form.assunto"
              type="text"
              maxlength="255"
              required
              class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
              :class="{ 'border-red-500': form.errors.assunto }">
            <p v-if="form.errors.assunto" class="mt-1 text-sm text-red-600">{{ form.errors.assunto }}</p>
          </div>

          <div>
            <label for="descricao" class="mb-1 block text-sm font-medium text-gray-700">Descrição *</label>
            <textarea
              id="descricao"
              v-model="form.descricao"
              rows="4"
              maxlength="5000"
              required
              class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
              :class="{ 'border-red-500': form.errors.descricao }" />
            <p v-if="form.errors.descricao" class="mt-1 text-sm text-red-600">{{ form.errors.descricao }}</p>
          </div>

          <!--
            Endereço: a Task 15.8 pede o campo "quando houver mais de um", mas
            `ClientRequestController::index()` (Task 15.5) não manda a lista de
            endereços do cliente para o portal - só `solicitacoes`, já com
            `endereco` resolvido como texto para as solicitações existentes,
            sem os ids necessários para popular um `<select>`. Sem essa lista,
            não há dado para montar o campo, então ele fica de fora aqui.
            `AbrirSolicitacaoRequest::address_id` já é opcional, e não
            enviá-lo grava a solicitação sem endereço - o mesmo que aconteceria
            hoje mesmo com o campo presente e vazio. Quando o endpoint passar a
            expor os endereços do cliente, um `<select v-if="enderecos.length
            > 1">` aqui resolve, sem mexer no restante do formulário.
          -->

          <p v-if="form.errors.limite" class="text-sm text-red-600">{{ form.errors.limite }}</p>

          <div class="flex justify-end">
            <button type="submit" :disabled="form.processing" class="btn-primary">
              {{ form.processing ? 'Enviando...' : 'Enviar solicitação' }}
            </button>
          </div>
        </form>
      </Card>

      <div v-if="solicitacoes.length === 0" class="rounded-lg border border-gray-200 bg-white p-8 text-center">
        <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-gray-100">
          <svg class="h-6 w-6 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
          </svg>
        </div>
        <h3 class="mt-3 text-sm font-medium text-gray-900">Nenhuma solicitação registrada</h3>
        <p class="mt-1 text-sm text-gray-500">Use o formulário acima quando precisar de atendimento.</p>
      </div>

      <Card v-else padding="none">
        <ul class="divide-y divide-gray-100">
          <li v-for="solicitacao in solicitacoes" :key="solicitacao.id" class="px-6 py-4">
            <Link :href="route('portal.solicitacoes.show', solicitacao.id)" class="block">
              <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0">
                  <p class="truncate text-sm font-medium text-gray-900">{{ solicitacao.assunto }}</p>
                  <p class="mt-1 text-xs text-gray-500">Aberta em {{ formatarDataHora(solicitacao.criada_em) }}</p>
                  <p v-if="solicitacao.resposta" class="mt-2 text-sm text-gray-700">
                    <span class="font-medium text-gray-900">Resposta da empresa:</span> {{ solicitacao.resposta }}
                  </p>
                </div>
                <span
                  class="inline-flex shrink-0 items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                  :class="corDaSituacao(solicitacao.situacao)">
                  {{ rotuloDaSituacao(solicitacao.situacao) }}
                </span>
              </div>
            </Link>
          </li>
        </ul>
      </Card>
    </div>
  </PortalLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import { formatarDataHora } from '@/utils/formatDate';

// `solicitacoes` vem de `ClientRequestService::minhas()` (Task 15.5), já no formato de
// `paraPortal()`: sem paginação (o Service devolve array simples, e o controller não
// pagina esta lista).
const props = defineProps({
  solicitacoes: {
    type: Array,
    required: true,
  },
});

// Mesmo teto de `ClientRequestService::LIMITE_ABERTAS` (Task 15.5). Não há como ler essa
// constante do backend a partir daqui, então o valor é replicado - checagem client-side é
// só para desabilitar o formulário sem esperar a viagem ao servidor; o servidor recusa de
// qualquer forma (`RuntimeException`), tratado em `form.errors.limite` abaixo.
const LIMITE_ABERTAS = 5;
const SITUACOES_ABERTAS = ['aberta', 'em_atendimento'];

const abertasCount = computed(
  () => props.solicitacoes.filter((solicitacao) => SITUACOES_ABERTAS.includes(solicitacao.situacao)).length,
);
const limiteAtingido = computed(() => abertasCount.value >= LIMITE_ABERTAS);

const SITUACOES = {
  aberta: { rotulo: 'Aberta', cor: 'bg-blue-100 text-blue-800' },
  em_atendimento: { rotulo: 'Em atendimento', cor: 'bg-yellow-100 text-yellow-800' },
  resolvida: { rotulo: 'Resolvida', cor: 'bg-green-100 text-green-800' },
  cancelada: { rotulo: 'Cancelada', cor: 'bg-gray-100 text-gray-800' },
};

function rotuloDaSituacao(situacao) {
  return SITUACOES[situacao]?.rotulo || situacao;
}

function corDaSituacao(situacao) {
  return SITUACOES[situacao]?.cor || 'bg-gray-100 text-gray-800';
}

const form = useForm({
  assunto: '',
  descricao: '',
});

function enviar() {
  form.post(route('portal.solicitacoes.store'), {
    preserveScroll: true,
    onSuccess: () => form.reset(),
  });
}
</script>
