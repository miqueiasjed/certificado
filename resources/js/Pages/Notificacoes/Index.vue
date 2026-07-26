<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Central de notificações" description="Acompanhe o que foi enviado a clientes, empresa e usuários, e o motivo de cada falha.">
        <template #actions>
          <Link :href="route('notificacoes.templates.index')" class="btn-secondary">
            Editar templates
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <Alert v-if="mensagemFlash" :key="flashKey" :type="mensagemFlash.tipo" :title="mensagemFlash.texto" />

      <!-- Filtros rápidos -->
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <button type="button" class="text-left" @click="filtrarPendentes">
          <Card variant="hover" class="cursor-pointer">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-lg bg-yellow-100 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
              </div>
              <div class="min-w-0">
                <p class="text-sm font-medium text-gray-900">Pendentes</p>
                <p class="text-xs text-gray-500">Ver avisos aguardando envio</p>
              </div>
            </div>
          </Card>
        </button>

        <button type="button" class="text-left" @click="filtrarEnviadasHoje">
          <Card variant="hover" class="cursor-pointer">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-lg bg-green-100 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
              </div>
              <div class="min-w-0">
                <p class="text-sm font-medium text-gray-900">Enviadas hoje</p>
                <p class="text-xs text-gray-500">Ver o que já saiu hoje</p>
              </div>
            </div>
          </Card>
        </button>

        <button type="button" class="text-left" @click="filtrarEmFalha">
          <Card variant="hover" class="cursor-pointer">
            <div class="flex items-center gap-3">
              <div class="w-10 h-10 rounded-lg bg-red-100 flex items-center justify-center flex-shrink-0">
                <svg class="w-5 h-5 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                </svg>
              </div>
              <div class="min-w-0">
                <p class="text-sm font-medium text-gray-900">Em falha</p>
                <p class="text-xs text-gray-500">Ver o que precisa de atenção</p>
              </div>
            </div>
          </Card>
        </button>
      </div>

      <!-- Filtros -->
      <Card>
        <form class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-4" @submit.prevent="aplicarFiltros">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Evento</label>
            <select v-model="filtroEvento" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500">
              <option value="">Todos</option>
              <option v-for="evento in eventos" :key="evento.evento" :value="evento.evento">{{ evento.rotulo }}</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Situação</label>
            <select v-model="filtroSituacao" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500">
              <option value="">Todas</option>
              <option v-for="situacao in situacoes" :key="situacao" :value="situacao">{{ rotuloSituacao(situacao) }}</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">De</label>
            <input v-model="filtroDataInicio" type="date" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Até</label>
            <input v-model="filtroDataFim" type="date" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500" />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Cliente (ID)</label>
            <input v-model="filtroClientId" type="number" min="1" placeholder="Ex.: 42" class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500" />
          </div>

          <div class="sm:col-span-2 lg:col-span-5 flex gap-3">
            <button type="submit" class="btn-primary">Filtrar</button>
            <button type="button" class="btn-secondary" @click="limparFiltros">Limpar filtros</button>
          </div>
        </form>
      </Card>

      <!-- Lista -->
      <Card padding="none">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3"></th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Evento</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Destinatário</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Canal</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Situação</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tentativas</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <template v-for="linha in linhas" :key="linha.id">
                <tr class="hover:bg-gray-50">
                  <td class="px-4 py-4">
                    <button type="button" class="text-gray-400 hover:text-gray-600" @click="alternarExpansao(linha.id)">
                      <svg
                        class="w-4 h-4 transition-transform"
                        :class="{ 'rotate-90': expandidas.has(linha.id) }"
                        fill="none"
                        stroke="currentColor"
                        viewBox="0 0 24 24"
                      >
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                      </svg>
                    </button>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ formatarDataHora(linha.agendada_para) }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ rotuloEvento(linha.evento) }}</td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <div class="text-sm text-gray-900">{{ rotuloDestinatario(linha.destinatario_tipo) }}</div>
                    <div class="text-xs text-gray-500">{{ linha.destino }}</div>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ rotuloCanal(linha.canal) }}</td>
                  <td class="px-6 py-4 whitespace-nowrap">
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" :class="classesSituacao(linha.situacao)">
                      {{ rotuloSituacao(linha.situacao) }}
                    </span>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ linha.tentativas }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-right text-sm font-medium">
                    <div class="flex items-center justify-end gap-3">
                      <button
                        v-if="linha.situacao === 'falha' && pode('notificacao-gerenciar')"
                        type="button"
                        :disabled="processandoId === linha.id"
                        class="text-green-600 hover:text-green-900 disabled:opacity-50 disabled:cursor-not-allowed"
                        @click="reenviar(linha)"
                      >
                        Reenviar
                      </button>
                      <button
                        v-if="linha.situacao === 'pendente' && pode('notificacao-gerenciar')"
                        type="button"
                        :disabled="processandoId === linha.id"
                        class="text-red-600 hover:text-red-900 disabled:opacity-50 disabled:cursor-not-allowed"
                        @click="pedirCancelamento(linha)"
                      >
                        Cancelar
                      </button>
                      <button
                        type="button"
                        :disabled="processandoWhatsappId === linha.id"
                        class="text-gray-600 hover:text-gray-900 disabled:opacity-50 disabled:cursor-not-allowed"
                        @click="abrirWhatsapp(linha)"
                      >
                        WhatsApp
                      </button>
                    </div>
                  </td>
                </tr>
                <tr v-if="expandidas.has(linha.id)" class="bg-gray-50">
                  <td></td>
                  <td colspan="7" class="px-6 py-4">
                    <p class="text-xs font-medium text-gray-500 uppercase tracking-wider mb-2">Histórico de tentativas</p>
                    <div v-if="!linha.logs?.length" class="text-sm text-gray-500">Nenhuma tentativa registrada ainda.</div>
                    <table v-else class="min-w-full text-sm">
                      <thead>
                        <tr class="text-xs text-gray-500 uppercase tracking-wider text-left">
                          <th class="pr-4 py-1">Tentativa</th>
                          <th class="pr-4 py-1">Resultado</th>
                          <th class="pr-4 py-1">Mensagem</th>
                          <th class="pr-4 py-1">Data</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="log in linha.logs" :key="log.id" class="border-t border-gray-200">
                          <td class="pr-4 py-2 text-gray-900">{{ log.tentativa }}</td>
                          <td class="pr-4 py-2">
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="classesResultado(log.resultado)">
                              {{ rotuloResultado(log.resultado) }}
                            </span>
                          </td>
                          <td class="pr-4 py-2 text-gray-700">{{ log.mensagem || '-' }}</td>
                          <td class="pr-4 py-2 text-gray-500">{{ formatarDataHora(log.ocorrida_em) }}</td>
                        </tr>
                      </tbody>
                    </table>
                  </td>
                </tr>
              </template>

              <tr v-if="!linhas.length">
                <td colspan="8" class="px-6 py-10 text-center text-sm text-gray-500">
                  Nenhuma notificação encontrada com os filtros atuais.
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="px-6 py-4 border-t border-gray-200">
          <Pagination :links="itens.links" />
        </div>
      </Card>
    </div>

    <ConfirmDeleteModal
      :show="!!itemParaCancelar"
      title="Cancelar aviso"
      subtitle="O aviso deixa de ser enviado. Essa ação não pode ser desfeita."
      message="Tem certeza que deseja cancelar o aviso de"
      :item-name="itemParaCancelar ? `${rotuloEvento(itemParaCancelar.evento)} para ${itemParaCancelar.destino}` : ''"
      confirm-text="Sim, cancelar"
      processing-text="Cancelando..."
      :processing="cancelando"
      @confirm="confirmarCancelamento"
      @cancel="itemParaCancelar = null"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, watch } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Alert from '@/Components/Alert.vue';
import Pagination from '@/Components/Pagination.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { formatarDataHora, hojeISO } from '@/utils/formatDate';

const props = defineProps({
  itens: {
    type: Object,
    required: true,
  },
  filtros: {
    type: Object,
    default: () => ({}),
  },
  eventos: {
    type: Array,
    default: () => [],
  },
  situacoes: {
    type: Array,
    default: () => [],
  },
});

const { pode } = usePermissoes();

const DESTINATARIO_ROTULOS = {
  cliente: 'Cliente',
  empresa: 'Empresa (uso interno)',
  usuario: 'Usuário do sistema',
};

const CANAL_ROTULOS = {
  email: 'E-mail',
  whatsapp: 'WhatsApp',
};

const SITUACAO_ROTULOS = {
  pendente: 'Pendente',
  enviando: 'Enviando',
  enviada: 'Enviada',
  falha: 'Falha',
  cancelada: 'Cancelada',
  recusada: 'Recusada',
};

const SITUACAO_CLASSES = {
  pendente: 'bg-yellow-100 text-yellow-800',
  enviando: 'bg-yellow-100 text-yellow-800',
  enviada: 'bg-green-100 text-green-800',
  falha: 'bg-red-100 text-red-800',
  recusada: 'bg-red-100 text-red-800',
  cancelada: 'bg-gray-100 text-gray-800',
};

const RESULTADO_ROTULOS = {
  sucesso: 'Sucesso',
  falha_temporaria: 'Falha temporária',
  falha_permanente: 'Falha permanente',
};

const RESULTADO_CLASSES = {
  sucesso: 'bg-green-100 text-green-800',
  falha_temporaria: 'bg-yellow-100 text-yellow-800',
  falha_permanente: 'bg-red-100 text-red-800',
};

function rotuloDestinatario(tipo) {
  return DESTINATARIO_ROTULOS[tipo] || tipo;
}

function rotuloCanal(canal) {
  return CANAL_ROTULOS[canal] || canal;
}

function rotuloSituacao(situacao) {
  return SITUACAO_ROTULOS[situacao] || situacao;
}

function classesSituacao(situacao) {
  return SITUACAO_CLASSES[situacao] || 'bg-gray-100 text-gray-800';
}

function rotuloResultado(resultado) {
  return RESULTADO_ROTULOS[resultado] || resultado;
}

function classesResultado(resultado) {
  return RESULTADO_CLASSES[resultado] || 'bg-gray-100 text-gray-800';
}

function rotuloEvento(chaveEvento) {
  return props.eventos.find((evento) => evento.evento === chaveEvento)?.rotulo || chaveEvento;
}

// Filtros
const filtroEvento = ref(props.filtros?.evento || '');
const filtroSituacao = ref(props.filtros?.situacao || '');
const filtroClientId = ref(props.filtros?.client_id || '');
const filtroDataInicio = ref(props.filtros?.data_inicio || '');
const filtroDataFim = ref(props.filtros?.data_fim || '');

function aplicarFiltros() {
  const parametros = {
    evento: filtroEvento.value || undefined,
    situacao: filtroSituacao.value || undefined,
    client_id: filtroClientId.value || undefined,
    data_inicio: filtroDataInicio.value || undefined,
    data_fim: filtroDataFim.value || undefined,
  };

  router.get(route('notificacoes.index'), parametros, {
    preserveState: true,
    preserveScroll: true,
    replace: true,
  });
}

function limparFiltros() {
  filtroEvento.value = '';
  filtroSituacao.value = '';
  filtroClientId.value = '';
  filtroDataInicio.value = '';
  filtroDataFim.value = '';
  router.get(route('notificacoes.index'), {}, { preserveState: true, preserveScroll: true, replace: true });
}

// Filtros rápidos: em vez de contadores calculados só com a página atual (o
// que subestimaria o total), cada atalho aplica o filtro correspondente e
// recarrega a listagem completa.
function filtrarPendentes() {
  filtroEvento.value = '';
  filtroSituacao.value = 'pendente';
  filtroClientId.value = '';
  filtroDataInicio.value = '';
  filtroDataFim.value = '';
  aplicarFiltros();
}

function filtrarEnviadasHoje() {
  const hoje = hojeISO();
  filtroEvento.value = '';
  filtroSituacao.value = 'enviada';
  filtroClientId.value = '';
  filtroDataInicio.value = hoje;
  filtroDataFim.value = hoje;
  aplicarFiltros();
}

function filtrarEmFalha() {
  filtroEvento.value = '';
  filtroSituacao.value = 'falha';
  filtroClientId.value = '';
  filtroDataInicio.value = '';
  filtroDataFim.value = '';
  aplicarFiltros();
}

// Linha expansível
const expandidas = ref(new Set());

function alternarExpansao(id) {
  const novo = new Set(expandidas.value);

  if (novo.has(id)) {
    novo.delete(id);
  } else {
    novo.add(id);
  }

  expandidas.value = novo;
}

// Cópia local da página atual, para atualizar uma linha sem recarregar a
// listagem inteira depois de reenviar ou cancelar. Resincroniza sempre que
// a navegação (filtro, paginação) troca os dados vindos do Inertia.
const linhas = ref([...props.itens.data]);

watch(
  () => props.itens.data,
  (novosDados) => {
    linhas.value = [...novosDados];
  },
);

function substituirLinha(itemAtualizado) {
  const indice = linhas.value.findIndex((linha) => linha.id === itemAtualizado.id);

  if (indice !== -1) {
    linhas.value.splice(indice, 1, itemAtualizado);
  }
}

const mensagemFlash = ref(null);
const flashKey = ref(0);

function mostrarFlash(tipo, texto) {
  mensagemFlash.value = { tipo, texto: texto || 'Feito.' };
  flashKey.value += 1;
}

function cabecalhosJson() {
  return {
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
    Accept: 'application/json',
  };
}

const processandoId = ref(null);

async function reenviar(linha) {
  processandoId.value = linha.id;

  try {
    const resposta = await fetch(route('notificacoes.reenviar', linha.id), {
      method: 'POST',
      headers: cabecalhosJson(),
    });
    const dados = await resposta.json();

    if (resposta.ok && dados.success) {
      substituirLinha(dados.item);
      mostrarFlash('success', dados.message);
    } else {
      mostrarFlash('error', dados.message || 'Não foi possível reenviar este aviso.');
    }
  } catch (excecao) {
    mostrarFlash('error', 'Não foi possível reenviar este aviso. Tente novamente.');
  } finally {
    processandoId.value = null;
  }
}

const itemParaCancelar = ref(null);
const cancelando = ref(false);

function pedirCancelamento(linha) {
  itemParaCancelar.value = linha;
}

async function confirmarCancelamento() {
  if (!itemParaCancelar.value) return;

  cancelando.value = true;

  try {
    const resposta = await fetch(route('notificacoes.cancelar', itemParaCancelar.value.id), {
      method: 'POST',
      headers: cabecalhosJson(),
    });
    const dados = await resposta.json();

    if (resposta.ok && dados.success) {
      substituirLinha(dados.item);
      mostrarFlash('success', dados.message);
      itemParaCancelar.value = null;
    } else {
      mostrarFlash('error', dados.message || 'Não foi possível cancelar este aviso.');
      itemParaCancelar.value = null;
    }
  } catch (excecao) {
    mostrarFlash('error', 'Não foi possível cancelar este aviso. Tente novamente.');
    itemParaCancelar.value = null;
  } finally {
    cancelando.value = false;
  }
}

const processandoWhatsappId = ref(null);

async function abrirWhatsapp(linha) {
  processandoWhatsappId.value = linha.id;

  try {
    const resposta = await fetch(route('notificacoes.whatsapp', linha.id), {
      headers: { Accept: 'application/json' },
    });
    const dados = await resposta.json();

    if (resposta.ok && dados.success) {
      window.open(dados.link, '_blank');
    } else {
      mostrarFlash('error', dados.message || 'Não foi possível montar o link do WhatsApp.');
    }
  } catch (excecao) {
    mostrarFlash('error', 'Não foi possível montar o link do WhatsApp. Tente novamente.');
  } finally {
    processandoWhatsappId.value = null;
  }
}
</script>
