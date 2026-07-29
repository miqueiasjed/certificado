<template>
  <PortalLayout>
    <template #header>
      <PageHeader :title="solicitacao.assunto" description="Detalhe da solicitação.">
        <template #actions>
          <Link :href="route('portal.solicitacoes.index')" class="btn-secondary">Voltar</Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-3xl space-y-6">
      <Card>
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <span
              class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
              :class="corDaSituacao(solicitacao.situacao)">
              {{ rotuloDaSituacao(solicitacao.situacao) }}
            </span>
            <p class="mt-2 text-xs text-gray-500">Aberta em {{ formatarDataHora(solicitacao.criada_em) }}</p>
            <p v-if="solicitacao.endereco" class="mt-1 text-xs text-gray-500">Endereço: {{ solicitacao.endereco }}</p>
          </div>
        </div>

        <div class="mt-4 border-t border-gray-100 pt-4">
          <h3 class="text-sm font-medium text-gray-700">Descrição</h3>
          <p class="mt-1 whitespace-pre-line text-sm text-gray-900">{{ solicitacao.descricao }}</p>
        </div>
      </Card>

      <Card>
        <h3 class="text-sm font-medium text-gray-700">Resposta da empresa</h3>

        <div v-if="solicitacao.resposta" class="mt-3 rounded-md bg-gray-50 p-4">
          <p class="whitespace-pre-line text-sm text-gray-900">{{ solicitacao.resposta }}</p>
          <p class="mt-2 text-xs text-gray-500">
            <span v-if="solicitacao.atendida_por">{{ solicitacao.atendida_por }} · </span>
            {{ formatarDataHora(solicitacao.respondida_em) }}
          </p>
        </div>
        <p v-else class="mt-3 text-sm text-gray-500">
          Ainda não há resposta. A empresa foi avisada e vai responder por aqui assim que possível.
        </p>
      </Card>
    </div>
  </PortalLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import PortalLayout from '@/Layouts/PortalLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import { formatarDataHora } from '@/utils/formatDate';

// `solicitacao` vem de `ClientRequestService::minha()` (Task 15.5), já no formato de
// `paraPortal()`: assunto, descricao, situacao, prioridade, endereco, resposta,
// respondida_em, atendida_por, criada_em. `prioridade` não é exibida aqui de propósito -
// é avaliação interna da empresa, e o cliente nunca a define nem precisa acompanhá-la.
defineProps({
  solicitacao: {
    type: Object,
    required: true,
  },
});

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
</script>
