<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Solicitações"
        description="Pedidos de atendimento abertos pelo cliente no portal."
      />
    </template>

    <div class="max-w-6xl mx-auto space-y-6">
      <div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!-- Filtro por situação -->
      <Card padding="small">
        <div class="flex flex-col sm:flex-row sm:items-center gap-3">
          <label class="text-sm font-medium text-gray-700 shrink-0">Situação</label>
          <select
            v-model="filtroSituacao"
            @change="aplicarFiltro"
            class="w-full sm:w-64 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          >
            <option :value="null">Todas</option>
            <option v-for="situacao in situacoes" :key="situacao" :value="situacao">
              {{ ROTULO_DA_SITUACAO[situacao] || situacao }}
            </option>
          </select>
        </div>
      </Card>

      <!-- Fila de solicitações -->
      <Card padding="none">
        <div v-if="solicitacoes.data.length === 0" class="p-8 text-center text-sm text-gray-500">
          Nenhuma solicitação encontrada{{ filtroSituacao ? ' com esta situação' : '' }}.
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cliente</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assunto</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aberta em</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Prioridade</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Situação</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="solicitacao in solicitacoes.data" :key="solicitacao.id" class="hover:bg-gray-50">
                <td class="px-4 py-4 text-sm">
                  <p class="font-medium text-gray-900">{{ solicitacao.client?.name || 'Cliente removido' }}</p>
                  <p class="text-gray-500">#{{ solicitacao.id }}</p>
                </td>
                <td class="px-4 py-4 text-sm max-w-sm">
                  <p class="font-medium text-gray-900">{{ solicitacao.assunto }}</p>
                  <p class="text-gray-500 line-clamp-2">{{ solicitacao.descricao }}</p>
                </td>
                <td class="px-4 py-4 text-sm text-gray-700 whitespace-nowrap">
                  {{ formatarDataHora(solicitacao.created_at) }}
                </td>
                <td class="px-4 py-4 text-sm text-gray-700 whitespace-nowrap">
                  {{ ROTULO_DA_PRIORIDADE[solicitacao.prioridade] || solicitacao.prioridade }}
                </td>
                <td class="px-4 py-4 whitespace-nowrap">
                  <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full" :class="CLASSE_DA_SITUACAO[solicitacao.situacao]">
                    {{ ROTULO_DA_SITUACAO[solicitacao.situacao] || solicitacao.situacao }}
                  </span>
                </td>
                <td class="px-4 py-4 text-right whitespace-nowrap">
                  <button type="button" class="btn-secondary-sm" @click="abrirAtendimento(solicitacao)">
                    {{ podeResponder ? 'Atender' : 'Ver' }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="p-4 border-t border-gray-200" v-if="solicitacoes.data.length > 0">
          <Pagination :links="solicitacoes.links" />
        </div>
      </Card>
    </div>

    <!-- Modal: Atender -->
    <Modal :show="!!solicitacaoAtiva" @close="fecharAtendimento">
      <template #icon>
        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
        </svg>
      </template>
      <template #title>{{ solicitacaoAtiva?.assunto }}</template>
      <template #content>
        <div v-if="solicitacaoAtiva" class="space-y-4 text-left">
          <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-sm">
            <div>
              <dt class="text-gray-500">Cliente</dt>
              <dd class="font-medium text-gray-900">{{ solicitacaoAtiva.client?.name || 'Cliente removido' }}</dd>
            </div>
            <div>
              <dt class="text-gray-500">Aberta em</dt>
              <dd class="font-medium text-gray-900">{{ formatarDataHora(solicitacaoAtiva.created_at) }}</dd>
            </div>
            <div class="sm:col-span-2">
              <dt class="text-gray-500">Endereço</dt>
              <dd class="font-medium text-gray-900">{{ enderecoDaSolicitacao(solicitacaoAtiva) }}</dd>
            </div>
          </dl>

          <div>
            <p class="text-sm text-gray-500 mb-1">Pedido do cliente</p>
            <p class="text-sm text-gray-900 whitespace-pre-line bg-gray-50 border border-gray-200 rounded-md p-3">{{ solicitacaoAtiva.descricao }}</p>
          </div>

          <div v-if="solicitacaoAtiva.resposta">
            <p class="text-sm text-gray-500 mb-1">{{ legendaDaResposta(solicitacaoAtiva) }}</p>
            <p class="text-sm text-gray-900 whitespace-pre-line bg-green-50 border border-green-200 rounded-md p-3">{{ solicitacaoAtiva.resposta }}</p>
          </div>

          <template v-if="podeResponder">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Situação</label>
              <select
                v-model="situacaoEscolhida"
                :disabled="processando"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                @change="mudarSituacao"
              >
                <option v-for="situacao in situacoes" :key="situacao" :value="situacao">
                  {{ ROTULO_DA_SITUACAO[situacao] || situacao }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">
                {{ solicitacaoAtiva.resposta ? 'Nova resposta' : 'Resposta ao cliente' }} *
              </label>
              <textarea
                v-model="formResposta.resposta"
                rows="4"
                maxlength="5000"
                placeholder="O cliente vê esta resposta no portal e recebe um aviso."
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': formResposta.errors.resposta }"
              ></textarea>
              <p v-if="formResposta.errors.resposta" class="mt-1 text-sm text-red-600">
                {{ formResposta.errors.resposta }}
              </p>
              <p v-else-if="solicitacaoAtiva.resposta" class="mt-1 text-xs text-gray-500">
                A nova resposta substitui a anterior no portal.
              </p>
            </div>
          </template>
        </div>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="processando" @click="fecharAtendimento">
          Fechar
        </button>
        <button
          v-if="podeResponder"
          type="button"
          class="btn-primary"
          :disabled="processando || !formResposta.resposta.trim()"
          @click="enviarResposta"
        >
          {{ formResposta.processing ? 'Enviando...' : 'Enviar resposta' }}
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { formatarDataHora } from '@/utils/formatDate';

const props = defineProps({
  solicitacoes: Object,
  situacaoFiltro: String,
  situacoes: Array,
});

const { pode } = usePermissoes();

// A rota de responder e a de mudar situação exigem a mesma permissão.
const podeResponder = computed(() => pode('solicitacao-responder'));

const ROTULO_DA_SITUACAO = {
  aberta: 'Aberta',
  em_atendimento: 'Em atendimento',
  resolvida: 'Resolvida',
  cancelada: 'Cancelada',
};

const CLASSE_DA_SITUACAO = {
  aberta: 'bg-yellow-100 text-yellow-800',
  em_atendimento: 'bg-blue-100 text-blue-800',
  resolvida: 'bg-green-100 text-green-800',
  cancelada: 'bg-gray-100 text-gray-800',
};

const ROTULO_DA_PRIORIDADE = {
  baixa: 'Baixa',
  normal: 'Normal',
  alta: 'Alta',
};

// Mesmo formato de `Address::getFullAddressAttribute()`: o accessor não é
// serializado, então o endereço chega com as colunas cruas.
const enderecoDaSolicitacao = (solicitacao) => {
  const endereco = solicitacao.address;

  if (!endereco) {
    return 'Não informado';
  }

  return `${endereco.street}, ${endereco.number} - ${endereco.district}, ${endereco.city}/${endereco.state} - CEP: ${endereco.zip}`;
};

// `atendida_por` chega como o objeto da relação `atendidaPor`, que na
// serialização sobrescreve a coluna de mesmo nome.
const legendaDaResposta = (solicitacao) => [
  'Resposta enviada',
  solicitacao.atendida_por?.name ? `por ${solicitacao.atendida_por.name}` : null,
  solicitacao.respondida_em ? `em ${formatarDataHora(solicitacao.respondida_em)}` : null,
].filter(Boolean).join(' ');

// -----------------------------------------------------------------
// Filtro por situação
// -----------------------------------------------------------------

const filtroSituacao = ref(props.situacaoFiltro || null);

const aplicarFiltro = () => {
  router.get(
    route('solicitacoes.index'),
    filtroSituacao.value ? { situacao: filtroSituacao.value } : {},
    { preserveState: true, replace: true }
  );
};

// -----------------------------------------------------------------
// Atender: responder e mudar a situação
// -----------------------------------------------------------------

const solicitacaoAtiva = ref(null);
const situacaoEscolhida = ref(null);
const mudandoSituacao = ref(false);
const formResposta = useForm({ resposta: '' });

const processando = computed(() => formResposta.processing || mudandoSituacao.value);

const abrirAtendimento = (solicitacao) => {
  solicitacaoAtiva.value = solicitacao;
  situacaoEscolhida.value = solicitacao.situacao;
  formResposta.reset();
  formResposta.clearErrors();
};

const fecharAtendimento = () => {
  if (processando.value) return;
  solicitacaoAtiva.value = null;
};

// Depois de cada ação a lista volta do servidor; o modal passa a mostrar a
// versão nova da mesma solicitação (situação e resposta atualizadas).
const atualizarSolicitacaoAtiva = () => {
  const id = solicitacaoAtiva.value?.id;
  solicitacaoAtiva.value = props.solicitacoes.data.find((item) => item.id === id) || null;
  situacaoEscolhida.value = solicitacaoAtiva.value?.situacao ?? null;
};

const mudarSituacao = () => {
  mudandoSituacao.value = true;

  router.post(
    route('solicitacoes.situacao', solicitacaoAtiva.value.id),
    { situacao: situacaoEscolhida.value },
    {
      preserveScroll: true,
      preserveState: true,
      onSuccess: atualizarSolicitacaoAtiva,
      onError: () => {
        situacaoEscolhida.value = solicitacaoAtiva.value.situacao;
      },
      onFinish: () => {
        mudandoSituacao.value = false;
      },
    }
  );
};

const enviarResposta = () => {
  formResposta.post(route('solicitacoes.responder', solicitacaoAtiva.value.id), {
    preserveScroll: true,
    preserveState: true,
    onSuccess: () => {
      formResposta.reset();
      atualizarSolicitacaoAtiva();
    },
  });
};
</script>
