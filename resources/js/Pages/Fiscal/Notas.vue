<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Notas fiscais" description="Emita, acompanhe e consulte as NFS-e da empresa.">
        <template #actions>
          <Link v-if="pode('fiscal-ver')" href="/notas/pendencias" class="btn-secondary">Pendências</Link>
          <Link v-if="pode('fiscal-configurar')" href="/fiscal/configuracao" class="btn-secondary">Configuração</Link>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <div v-if="ambiente === 'homologacao'" class="rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-900" role="alert">
        Ambiente de homologação ativo. As notas desta tela são testes e não devem ser enviadas ao cliente como documentos válidos.
      </div>

      <Alert
        v-if="alerta"
        :key="alertaChave"
        :type="alerta.tipo"
        :title="alerta.titulo"
        :message="alerta.mensagem"
      />

      <Card>
        <form class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5" @submit.prevent="aplicarFiltros">
          <div>
            <label for="situacao" class="mb-1 block text-sm font-medium text-gray-700">Situação</label>
            <select id="situacao" v-model="filtro.situacao" class="campo">
              <option value="">Todas</option>
              <option v-for="(rotulo, chave) in SITUACOES" :key="chave" :value="chave">{{ rotulo }}</option>
            </select>
          </div>
          <div>
            <label for="cliente" class="mb-1 block text-sm font-medium text-gray-700">Cliente</label>
            <select id="cliente" v-model="filtro.client_id" class="campo">
              <option value="">Todos</option>
              <option v-for="cliente in clientes" :key="cliente.id" :value="cliente.id">{{ cliente.nome }}</option>
            </select>
          </div>
          <div>
            <label for="de" class="mb-1 block text-sm font-medium text-gray-700">Competência de</label>
            <input id="de" v-model="filtro.de" type="date" class="campo" />
          </div>
          <div>
            <label for="ate" class="mb-1 block text-sm font-medium text-gray-700">Competência até</label>
            <input id="ate" v-model="filtro.ate" type="date" class="campo" />
          </div>
          <div class="flex items-end gap-2">
            <button type="submit" class="btn-primary flex-1">Filtrar</button>
            <button type="button" class="btn-secondary" @click="limparFiltros">Limpar</button>
          </div>
        </form>
      </Card>

      <div v-if="temProcessamento" class="rounded-lg border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
        A prefeitura ainda está respondendo. A lista será atualizada a cada 30 segundos.
      </div>

      <Card v-if="notas.data.length === 0">
        <p class="py-8 text-center text-sm text-gray-500">Nenhuma nota encontrada com os filtros atuais.</p>
      </Card>

      <div v-else class="space-y-3 md:hidden">
        <Card v-for="nota in notas.data" :key="nota.id" padding="small">
          <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
              <p class="truncate font-semibold text-gray-900">{{ nota.numero ? `NFS-e ${nota.numero}` : `Nota #${nota.id}` }}</p>
              <p class="truncate text-sm text-gray-600">{{ nota.cliente }}</p>
            </div>
            <span :class="['badge', classeSituacao(nota.situacao)]" :title="motivoDaSituacao(nota)">
              {{ SITUACOES[nota.situacao] || nota.situacao }}
            </span>
          </div>
          <dl class="mt-4 grid grid-cols-2 gap-3 text-sm">
            <div><dt class="text-gray-500">Competência</dt><dd class="font-medium text-gray-900">{{ formatarData(nota.competencia) }}</dd></div>
            <div><dt class="text-gray-500">Valor</dt><dd class="font-medium text-gray-900">{{ formatarMoeda(nota.valor_servico) }}</dd></div>
            <div><dt class="text-gray-500">ISS</dt><dd class="font-medium text-gray-900">{{ formatarMoeda(nota.valor_iss) }}</dd></div>
            <div><dt class="text-gray-500">Origem</dt><dd class="font-medium text-gray-900">{{ origem(nota) }}</dd></div>
          </dl>
          <p v-if="nota.situacao === 'erro'" class="mt-3 text-sm text-red-700">{{ nota.erro_mensagem }}</p>
          <div class="mt-4 flex flex-wrap gap-3 border-t border-gray-100 pt-3">
            <AcoesDaNota :nota="nota" @cancelar="abrirCancelamento" @substituir="abrirSubstituicao" @reprocessar="reprocessar" />
          </div>
        </Card>
      </div>

      <Card v-if="notas.data.length > 0" padding="none" class="hidden md:block">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="cabecalho">Número</th>
                <th class="cabecalho">Cliente</th>
                <th class="cabecalho">Competência</th>
                <th class="cabecalho text-right">Valor</th>
                <th class="cabecalho text-right">ISS</th>
                <th class="cabecalho">Situação</th>
                <th class="cabecalho text-right">Ações</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 bg-white">
              <tr v-for="nota in notas.data" :key="nota.id" :class="{ 'bg-gray-50 opacity-75': nota.situacao === 'cancelada' }">
                <td class="celula font-medium text-gray-900">{{ nota.numero || `#${nota.id}` }}</td>
                <td class="celula">
                  <span class="block max-w-56 truncate text-gray-900">{{ nota.cliente }}</span>
                  <span class="text-xs text-gray-500">{{ origem(nota) }}</span>
                </td>
                <td class="celula text-gray-700">{{ formatarData(nota.competencia) }}</td>
                <td class="celula text-right text-gray-700">{{ formatarMoeda(nota.valor_servico) }}</td>
                <td class="celula text-right text-gray-700">{{ formatarMoeda(nota.valor_iss) }}</td>
                <td class="celula">
                  <span :class="['badge', classeSituacao(nota.situacao)]" :title="motivoDaSituacao(nota)">
                    {{ SITUACOES[nota.situacao] || nota.situacao }}
                  </span>
                  <p v-if="nota.situacao === 'processando'" class="mt-1 max-w-48 text-xs text-blue-700">Aguardando a prefeitura</p>
                  <p v-if="nota.situacao === 'cancelada' && nota.motivo_cancelamento" class="mt-1 max-w-48 truncate text-xs text-gray-500" :title="nota.motivo_cancelamento">
                    {{ nota.motivo_cancelamento }}
                  </p>
                </td>
                <td class="celula text-right">
                  <div class="flex min-w-max justify-end gap-3">
                    <AcoesDaNota :nota="nota" @cancelar="abrirCancelamento" @substituir="abrirSubstituicao" @reprocessar="reprocessar" />
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>

      <Pagination v-if="notas.links?.length" :links="notas.links" />
    </div>

    <Modal :show="mostrarCancelamento" @close="fecharCancelamento">
      <template #icon><span class="text-xl font-bold text-red-600">!</span></template>
      <template #title>Cancelar nota fiscal</template>
      <template #content>
        <p class="mb-4 text-sm text-gray-700">O pedido será enviado à prefeitura e ficará registrado no histórico fiscal.</p>
        <label for="motivo-cancelamento" class="mb-1 block text-sm font-medium text-gray-700">Motivo *</label>
        <textarea id="motivo-cancelamento" v-model="motivoCancelamento" rows="4" maxlength="2000" class="campo" placeholder="Descreva o motivo com pelo menos 15 caracteres."></textarea>
        <p v-if="erroModal" class="mt-2 text-sm text-red-600">{{ erroModal }}</p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="processandoAcao" @click="fecharCancelamento">Voltar</button>
        <button type="button" class="btn-danger sm:ml-3" :disabled="processandoAcao" @click="confirmarCancelamento">
          {{ processandoAcao ? 'Enviando...' : 'Solicitar cancelamento' }}
        </button>
      </template>
    </Modal>

    <Modal :show="mostrarSubstituicao" @close="fecharSubstituicao">
      <template #title>Substituir nota fiscal</template>
      <template #content>
        <div class="space-y-4">
          <div>
            <label for="motivo-substituicao" class="mb-1 block text-sm font-medium text-gray-700">Motivo *</label>
            <textarea id="motivo-substituicao" v-model="substituicao.motivo" rows="3" maxlength="2000" class="campo" placeholder="Descreva o motivo com pelo menos 15 caracteres."></textarea>
          </div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <div>
              <label for="valor-substituicao" class="mb-1 block text-sm font-medium text-gray-700">Valor do serviço</label>
              <input id="valor-substituicao" v-model="substituicao.valor_servico" type="number" min="0.01" step="0.01" class="campo" />
            </div>
            <div>
              <label for="competencia-substituicao" class="mb-1 block text-sm font-medium text-gray-700">Competência</label>
              <input id="competencia-substituicao" v-model="substituicao.competencia" type="date" class="campo" />
            </div>
          </div>
          <div>
            <label for="descricao-substituicao" class="mb-1 block text-sm font-medium text-gray-700">Descrição do serviço</label>
            <textarea id="descricao-substituicao" v-model="substituicao.descricao_servico" rows="3" maxlength="4000" class="campo"></textarea>
          </div>
          <p v-if="erroModal" class="text-sm text-red-600">{{ erroModal }}</p>
        </div>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="processandoAcao" @click="fecharSubstituicao">Voltar</button>
        <button type="button" class="btn-primary sm:ml-3" :disabled="processandoAcao" @click="confirmarSubstituicao">
          {{ processandoAcao ? 'Enviando...' : 'Emitir substituta' }}
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, defineComponent, h, onMounted, onUnmounted, reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Alert from '@/Components/Alert.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Pagination from '@/Components/Pagination.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { formatarData } from '@/utils/formatDate';

const props = defineProps({
  filtros: { type: Object, default: () => ({}) },
  notas: { type: Object, required: true },
  clientes: { type: Array, default: () => [] },
  ambiente: { type: String, default: null },
});

const { pode } = usePermissoes();

const SITUACOES = {
  pendente: 'Pendente',
  processando: 'Processando',
  emitida: 'Emitida',
  cancelamento_pendente: 'Cancelamento pendente',
  cancelada: 'Cancelada',
  substituida: 'Substituída',
  erro: 'Erro',
};

const AcoesDaNota = defineComponent({
  props: { nota: { type: Object, required: true } },
  emits: ['cancelar', 'substituir', 'reprocessar'],
  setup(propriedades, { emit }) {
    return () => {
      const botoes = [];
      if (propriedades.nota.arquivos?.pdf_disponivel) botoes.push(h('a', { href: `/notas/${propriedades.nota.id}/pdf`, class: 'acao' }, 'PDF'));
      if (propriedades.nota.arquivos?.xml_disponivel) botoes.push(h('a', { href: `/notas/${propriedades.nota.id}/xml`, class: 'acao' }, 'XML'));
      if (propriedades.nota.situacao === 'emitida' && pode('fiscal-cancelar')) {
        botoes.push(h('button', { type: 'button', class: 'acao text-red-600 hover:text-red-900', onClick: () => emit('cancelar', propriedades.nota) }, 'Cancelar'));
        botoes.push(h('button', { type: 'button', class: 'acao', onClick: () => emit('substituir', propriedades.nota) }, 'Substituir'));
      }
      if (propriedades.nota.situacao === 'erro' && !propriedades.nota.cadeia?.substitui && !propriedades.nota.cadeia?.reprocessada_por && pode('fiscal-emitir')) {
        botoes.push(h('button', { type: 'button', class: 'acao', onClick: () => emit('reprocessar', propriedades.nota) }, 'Reprocessar'));
      }
      if (propriedades.nota.situacao === 'erro' && propriedades.nota.cadeia?.substitui) {
        botoes.push(h('span', { class: 'text-xs text-gray-500', title: 'Abra a nota original e refaça a substituição.' }, `Refazer pela nota #${propriedades.nota.cadeia.substitui}`));
      }
      return botoes;
    };
  },
});

const filtro = reactive({
  situacao: props.filtros.situacao || '',
  client_id: props.filtros.client_id || '',
  de: props.filtros.de || '',
  ate: props.filtros.ate || '',
});

function aplicarFiltros() {
  const parametros = Object.fromEntries(Object.entries(filtro).filter(([, valor]) => valor !== '' && valor !== null));
  router.get('/notas', parametros, { preserveState: true, preserveScroll: true, replace: true });
}

function limparFiltros() {
  Object.assign(filtro, { situacao: '', client_id: '', de: '', ate: '' });
  router.get('/notas', {}, { preserveState: true, replace: true });
}

const temProcessamento = computed(() => props.notas.data.some((nota) => ['pendente', 'processando', 'cancelamento_pendente'].includes(nota.situacao)));
let temporizador = null;

onMounted(() => {
  temporizador = window.setInterval(() => {
    if (temProcessamento.value) router.reload({ only: ['notas'], preserveScroll: true });
  }, 30000);
});

onUnmounted(() => window.clearInterval(temporizador));

function formatarMoeda(valor) {
  const numero = Number(valor || 0);
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number.isFinite(numero) ? numero : 0);
}

function origem(nota) {
  if (nota.work_order_id) return `OS #${nota.work_order_id}`;
  if (nota.receivable_id) return `Título #${nota.receivable_id}`;
  return 'Origem indisponível';
}

function classeSituacao(situacao) {
  return {
    emitida: 'bg-green-100 text-green-800',
    erro: 'bg-red-100 text-red-800',
    pendente: 'bg-yellow-100 text-yellow-800',
    processando: 'bg-blue-100 text-blue-800',
    cancelamento_pendente: 'bg-yellow-100 text-yellow-800',
    cancelada: 'bg-gray-200 text-gray-800',
    substituida: 'bg-purple-100 text-purple-800',
  }[situacao] || 'bg-gray-100 text-gray-800';
}

function motivoDaSituacao(nota) {
  return nota.motivo_cancelamento || nota.motivo_substituicao || nota.erro_mensagem || '';
}

const alerta = ref(null);
const alertaChave = ref(0);
function avisar(tipo, titulo, mensagem) {
  alerta.value = { tipo, titulo, mensagem };
  alertaChave.value += 1;
}

function mensagemDeErro(dados, padrao) {
  return Object.values(dados.errors || {}).flat()[0] || dados.message || padrao;
}

async function enviar(url, corpo) {
  const resposta = await fetch(url, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
      Accept: 'application/json',
    },
    body: JSON.stringify(corpo),
  });
  const dados = await resposta.json().catch(() => ({}));
  if (!resposta.ok) throw new Error(mensagemDeErro(dados, 'A ação fiscal não pôde ser concluída.'));
  return dados;
}

function retornoFiscal(dados) {
  if (dados.resultado_fiscal === 'erro' || dados.nota?.situacao === 'erro') {
    return { tipo: 'error', titulo: 'Pendência fiscal' };
  }

  if (dados.resultado_fiscal === 'pendente' || ['pendente', 'processando', 'cancelamento_pendente'].includes(dados.nota?.situacao)) {
    return { tipo: 'warning', titulo: 'Aguardando a prefeitura' };
  }

  return { tipo: 'success', titulo: 'Operação fiscal concluída' };
}

const mostrarCancelamento = ref(false);
const notaSelecionada = ref(null);
const motivoCancelamento = ref('');
const erroModal = ref('');
const processandoAcao = ref(false);

function abrirCancelamento(nota) {
  notaSelecionada.value = nota;
  motivoCancelamento.value = '';
  erroModal.value = '';
  mostrarCancelamento.value = true;
}

function fecharCancelamento() {
  if (!processandoAcao.value) mostrarCancelamento.value = false;
}

async function confirmarCancelamento() {
  if (motivoCancelamento.value.trim().length < 15) {
    erroModal.value = 'Informe um motivo com pelo menos 15 caracteres.';
    return;
  }
  processandoAcao.value = true;
  erroModal.value = '';
  try {
    const dados = await enviar(`/notas/${notaSelecionada.value.id}/cancelar`, { motivo: motivoCancelamento.value.trim() });
    mostrarCancelamento.value = false;
    const retorno = retornoFiscal(dados);
    avisar(retorno.tipo, retorno.titulo, dados.message);
    router.reload({ only: ['notas'], preserveScroll: true });
  } catch (erro) {
    erroModal.value = erro.message;
  } finally {
    processandoAcao.value = false;
  }
}

const mostrarSubstituicao = ref(false);
const substituicao = reactive({ motivo: '', valor_servico: '', competencia: '', descricao_servico: '' });

function abrirSubstituicao(nota) {
  notaSelecionada.value = nota;
  Object.assign(substituicao, {
    motivo: '',
    valor_servico: nota.valor_servico || '',
    competencia: nota.competencia || '',
    descricao_servico: nota.descricao_servico || '',
  });
  erroModal.value = '';
  mostrarSubstituicao.value = true;
}

function fecharSubstituicao() {
  if (!processandoAcao.value) mostrarSubstituicao.value = false;
}

async function confirmarSubstituicao() {
  if (substituicao.motivo.trim().length < 15) {
    erroModal.value = 'Informe um motivo com pelo menos 15 caracteres.';
    return;
  }
  processandoAcao.value = true;
  erroModal.value = '';
  try {
    const dados = await enviar(`/notas/${notaSelecionada.value.id}/substituir`, {
      ...substituicao,
      motivo: substituicao.motivo.trim(),
    });
    mostrarSubstituicao.value = false;
    const retorno = retornoFiscal(dados);
    avisar(retorno.tipo, retorno.titulo, dados.message);
    router.reload({ only: ['notas'], preserveScroll: true });
  } catch (erro) {
    erroModal.value = erro.message;
  } finally {
    processandoAcao.value = false;
  }
}

async function reprocessar(nota) {
  if (processandoAcao.value) return;
  processandoAcao.value = true;
  try {
    const dados = await enviar(`/notas/${nota.id}/reprocessar`, {});
    const retorno = retornoFiscal(dados);
    avisar(retorno.tipo, retorno.titulo, dados.message);
    router.reload({ only: ['notas'], preserveScroll: true });
  } catch (erro) {
    avisar('error', 'Falha no reprocessamento', erro.message);
    router.reload({ only: ['notas'], preserveScroll: true });
  } finally {
    processandoAcao.value = false;
  }
}
</script>

<style scoped>
.campo { @apply w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm; }
.cabecalho { @apply px-4 py-3 text-left text-xs font-medium uppercase tracking-wider text-gray-500; }
.celula { @apply whitespace-nowrap px-4 py-4 text-sm; }
.badge { @apply inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium; }
.acao { @apply text-sm font-medium text-green-700 hover:text-green-900; }
</style>
