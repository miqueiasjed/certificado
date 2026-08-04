<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Cobranças"
        :description="`${cobrancas.length} cobrança(s) no filtro atual`"
      >
        <template #actions>
          <Link href="/contas-a-receber" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Contas a receber
          </Link>
          <Link v-if="pode('cobranca-ver')" href="/cobrancas/conciliacao" class="btn-secondary">
            Conciliação
          </Link>
          <Link v-if="pode('cobranca-configurar')" href="/cobrancas/configuracao" class="btn-secondary">
            Configuração
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <!-- Aviso permanente de sandbox: nunca dispensável enquanto o ambiente ativo for de teste. -->
      <div v-if="ambiente_sandbox" class="rounded-md border border-red-300 bg-red-50 p-4">
        <div class="flex items-start gap-3">
          <svg class="h-5 w-5 shrink-0 text-red-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" />
          </svg>
          <p class="text-sm font-medium text-red-800 min-w-0">
            Gateway de pagamento em ambiente de teste (sandbox). Os links, boletos e códigos Pix desta lista não são
            reais. Enviar uma cobrança de teste para um cliente de verdade é o erro mais constrangedor deste
            módulo, então confira o ambiente em Configuração antes de mandar qualquer link.
          </p>
        </div>
      </div>

      <Alert
        v-if="mensagemResultado"
        :key="alertaChave"
        :type="mensagemResultado.tipo"
        :title="mensagemResultado.titulo"
        :message="mensagemResultado.detalhe"
      />

      <!-- Filtros -->
      <Card>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Situação</label>
            <select v-model="filtros.situacao" class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm" @change="aplicarFiltros">
              <option value="">Todas</option>
              <option v-for="situacao in situacoes" :key="situacao" :value="situacao">{{ SITUACAO_LABEL[situacao] || situacao }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
            <select v-model="filtros.tipo" class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm" @change="aplicarFiltros">
              <option value="">Todos</option>
              <option v-for="tipo in tipos" :key="tipo" :value="tipo">{{ TIPO_LABEL[tipo] || tipo }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Vencimento de</label>
            <input v-model="filtros.de" type="date" class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm" @change="aplicarFiltros" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Vencimento até</label>
            <input v-model="filtros.ate" type="date" class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm" @change="aplicarFiltros" />
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Cliente</label>
            <select v-model="filtros.client_id" class="w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm" @change="aplicarFiltros">
              <option value="">Todos</option>
              <option v-for="cliente in clientes" :key="cliente.id" :value="cliente.id">{{ cliente.nome }}</option>
            </select>
          </div>
        </div>
      </Card>

      <!-- Lista -->
      <Card padding="none">
        <div v-if="cobrancas.length === 0" class="p-12 text-center">
          <p class="text-sm text-gray-500">Nenhuma cobrança encontrada com os filtros atuais.</p>
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cliente</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Tipo</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Valor</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vencimento</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Situação</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="cobranca in cobrancas" :key="cobranca.id" class="hover:bg-gray-50">
                <td class="px-4 py-4 text-sm text-gray-900">
                  {{ cobranca.cliente || 'Cliente não identificado' }}
                  <div v-if="cobranca.receivable_installment_id" class="text-xs text-gray-500">
                    Parcela #{{ cobranca.receivable_installment_id }}
                  </div>
                </td>
                <td class="px-4 py-4">
                  <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                    {{ TIPO_LABEL[cobranca.tipo] || cobranca.tipo }}
                  </span>
                </td>
                <td class="px-4 py-4 text-sm text-gray-700 text-right">
                  {{ formatarMoeda(cobranca.valor) }}
                  <div v-if="cobranca.valor_pago" class="text-xs text-green-700">
                    Pago {{ formatarMoeda(cobranca.valor_pago) }}
                  </div>
                </td>
                <td class="px-4 py-4 text-sm text-gray-700">
                  {{ formatarData(cobranca.vencimento) }}
                  <div v-if="cobranca.pago_em" class="text-xs text-gray-500">Pago em {{ formatarData(cobranca.pago_em) }}</div>
                </td>
                <td class="px-4 py-4 max-w-xs">
                  <span :class="['inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium', SITUACAO_BADGE[cobranca.situacao] || 'bg-gray-100 text-gray-800']">
                    {{ SITUACAO_LABEL[cobranca.situacao] || cobranca.situacao }}
                  </span>
                  <p v-if="cobranca.situacao === 'erro' && cobranca.erro_mensagem" class="mt-1 text-xs text-red-700">
                    {{ cobranca.erro_mensagem }}
                  </p>
                  <p v-else-if="cobranca.erro_mensagem" class="mt-1 text-xs text-gray-500">
                    {{ cobranca.erro_mensagem }}
                  </p>
                </td>
                <td class="px-4 py-4">
                  <div class="flex flex-wrap items-center justify-end gap-x-3 gap-y-2">
                    <button
                      v-if="cobranca.linha_digitavel || cobranca.qr_code_pix"
                      type="button"
                      class="text-sm font-medium text-blue-600 hover:text-blue-900"
                      @click="copiarCodigo(cobranca)"
                    >
                      {{ codigoCopiadoId === cobranca.id ? 'Copiado!' : (cobranca.qr_code_pix ? 'Copiar Pix' : 'Copiar linha digitável') }}
                    </button>

                    <a
                      v-if="cobranca.link_pagamento"
                      :href="cobranca.link_pagamento"
                      target="_blank"
                      rel="noopener"
                      class="text-sm font-medium text-blue-600 hover:text-blue-900"
                    >
                      Abrir link
                    </a>

                    <BotaoWhatsapp
                      :telefone="cobranca.cliente_aceita_whatsapp ? cobranca.cliente_telefone : null"
                      :mensagem="mensagemCobranca(cobranca)"
                    />

                    <a
                      v-if="hrefEmail(cobranca)"
                      :href="hrefEmail(cobranca)"
                      class="text-sm font-medium text-blue-600 hover:text-blue-900"
                    >
                      E-mail
                    </a>
                    <span v-else class="text-xs text-gray-400">E-mail indisponível</span>

                    <button
                      v-if="podeReemitir(cobranca) && pode('cobranca-emitir')"
                      type="button"
                      :disabled="processandoId === cobranca.id"
                      class="text-sm font-medium text-amber-700 hover:text-amber-900 disabled:opacity-50"
                      @click="reemitir(cobranca)"
                    >
                      {{ processandoId === cobranca.id ? 'Reemitindo...' : 'Tentar novamente' }}
                    </button>

                    <button
                      v-if="podeCancelar(cobranca) && pode('cobranca-emitir')"
                      type="button"
                      class="text-sm font-medium text-red-600 hover:text-red-900"
                      @click="abrirCancelamento(cobranca)"
                    >
                      Cancelar
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div v-if="cobrancas.length >= 300" class="px-4 py-3 bg-yellow-50 border-t border-yellow-200 text-sm text-yellow-800">
          Mostrando as 300 cobranças mais próximas do vencimento. Refine o período para ver as demais.
        </div>
      </Card>
    </div>

    <!-- Cancelamento: sempre com motivo e o efeito descrito, nunca confirm() nativo. -->
    <Modal :show="mostrarModalCancelamento" @close="fecharCancelamento">
      <template #icon>
        <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
        </svg>
      </template>
      <template #title>Cancelar cobrança</template>
      <template #content>
        <p class="text-sm text-gray-700 mb-4">
          {{ cobrancaEmCancelamento?.cliente }} · {{ formatarMoeda(cobrancaEmCancelamento?.valor) }} · vencimento
          {{ formatarData(cobrancaEmCancelamento?.vencimento) }}. O cliente não conseguirá mais pagar por este link
          ou código depois de cancelada. Explique o motivo: é o que responde por este cancelamento mais tarde.
        </p>
        <label class="block text-sm font-medium text-gray-700 mb-1">Motivo *</label>
        <textarea
          v-model="motivoCancelamento"
          rows="3"
          maxlength="1000"
          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          :class="{ 'border-red-500': erroCancelamento }"
          placeholder="Ex.: cliente pediu para renegociar a data, boleto emitido com valor errado."
        ></textarea>
        <p v-if="erroCancelamento" class="mt-1 text-sm text-red-600">{{ erroCancelamento }}</p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="cancelando" @click="fecharCancelamento">Voltar</button>
        <button type="button" class="btn-danger ml-3" :disabled="cancelando" @click="confirmarCancelamento">
          {{ cancelando ? 'Cancelando...' : 'Confirmar cancelamento' }}
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Alert from '@/Components/Alert.vue';
import Modal from '@/Components/Modal.vue';
import BotaoWhatsapp from '@/Components/BotaoWhatsapp.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { formatarData } from '@/utils/formatDate';

// Formato de `cobrancas`: um item por `ChargeController::linhaDaCobranca()`
// (Task 19.6/19.7). `situacoes` e `tipos` são `Charge::SITUACOES`/`Charge::TIPOS`,
// na ordem do enum do banco.
const props = defineProps({
  filtros: { type: Object, required: true },
  cobrancas: { type: Array, default: () => [] },
  clientes: { type: Array, default: () => [] },
  situacoes: { type: Array, default: () => [] },
  tipos: { type: Array, default: () => [] },
  ambiente_sandbox: { type: Boolean, default: false },
});

const { pode } = usePermissoes();

const SITUACAO_LABEL = {
  pendente: 'Pendente',
  emitida: 'Emitida',
  paga: 'Paga',
  vencida: 'Vencida',
  cancelada: 'Cancelada',
  erro: 'Erro',
  estornada: 'Estornada',
};

const SITUACAO_BADGE = {
  pendente: 'bg-blue-100 text-blue-800',
  emitida: 'bg-blue-100 text-blue-800',
  paga: 'bg-green-100 text-green-800',
  vencida: 'bg-yellow-100 text-yellow-800',
  cancelada: 'bg-gray-100 text-gray-800',
  erro: 'bg-red-100 text-red-800',
  estornada: 'bg-red-100 text-red-800',
};

const TIPO_LABEL = { boleto: 'Boleto', pix: 'Pix' };

// Situações em que reemitir/cancelar fazem sentido de verdade: o backend
// (`ChargeService::garantirNaoPaga()`) só recusa cobrança `paga`, mas mostrar
// os dois botões numa cobrança `pendente`/`emitida` sem motivo confundiria
// mais do que ajudaria - `estornada` fica de fora dos dois por ser caso raro
// que pede olhar o histórico, não um clique na lista.
function podeReemitir(cobranca) {
  return ['erro', 'vencida', 'cancelada'].includes(cobranca.situacao);
}

function podeCancelar(cobranca) {
  return ['pendente', 'emitida', 'vencida'].includes(cobranca.situacao);
}

function formatarMoeda(valor) {
  const numero = typeof valor === 'number' ? valor : parseFloat(valor || 0);
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number.isFinite(numero) ? numero : 0);
}

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
}

// -----------------------------------------------------------------
// Filtros
// -----------------------------------------------------------------

const filtros = reactive({
  situacao: props.filtros.situacao || '',
  tipo: props.filtros.tipo || '',
  de: props.filtros.de || '',
  ate: props.filtros.ate || '',
  client_id: props.filtros.client_id || '',
});

function aplicarFiltros() {
  const params = {};

  Object.entries(filtros).forEach(([chave, valor]) => {
    if (valor !== '' && valor !== null) params[chave] = valor;
  });

  router.get('/cobrancas', params, { preserveState: true, preserveScroll: true, replace: true });
}

// -----------------------------------------------------------------
// Ações na linha: copiar código, WhatsApp e e-mail nunca chamam o backend,
// são só o clique que abre/copia o que a cobrança já tem gravado.
// -----------------------------------------------------------------

const codigoCopiadoId = ref(null);
let timeoutCopia = null;

async function copiarCodigo(cobranca) {
  const codigo = cobranca.qr_code_pix || cobranca.linha_digitavel;
  if (!codigo) return;

  try {
    await navigator.clipboard.writeText(codigo);
    codigoCopiadoId.value = cobranca.id;
    clearTimeout(timeoutCopia);
    timeoutCopia = setTimeout(() => {
      codigoCopiadoId.value = null;
    }, 2000);
  } catch (erro) {
    // Navegador sem permissão de clipboard: nada quebra, só não há feedback visual.
  }
}

function mensagemCobranca(cobranca) {
  const valor = formatarMoeda(cobranca.valor);
  const vencimento = formatarData(cobranca.vencimento);
  const codigo = cobranca.link_pagamento || cobranca.qr_code_pix || cobranca.linha_digitavel || '';

  return `Olá! Segue sua cobrança de ${valor}, com vencimento em ${vencimento}.`
    + (codigo ? ` Link/código de pagamento: ${codigo}` : '');
}

function hrefEmail(cobranca) {
  if (!cobranca.cliente_email || !cobranca.cliente_aceita_email) return null;

  const assunto = encodeURIComponent(`Cobrança - vencimento ${formatarData(cobranca.vencimento)}`);
  const corpo = encodeURIComponent(mensagemCobranca(cobranca));

  return `mailto:${cobranca.cliente_email}?subject=${assunto}&body=${corpo}`;
}

// -----------------------------------------------------------------
// Reemitir e cancelar: chamam o backend e recarregam só a lista.
// -----------------------------------------------------------------

const mensagemResultado = ref(null);
const alertaChave = ref(0);

function mostrarResultado(tipo, titulo, detalhe) {
  mensagemResultado.value = { tipo, titulo, detalhe };
  alertaChave.value += 1;
}

const processandoId = ref(null);

async function reemitir(cobranca) {
  if (processandoId.value !== null) return;

  processandoId.value = cobranca.id;

  try {
    const resposta = await fetch(`/cobrancas/${cobranca.id}/reemitir`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
        Accept: 'application/json',
      },
    });

    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) {
      mostrarResultado('error', 'Não foi possível reemitir', dados.message || 'Tente novamente em instantes.');
      return;
    }

    mostrarResultado('success', 'Cobrança reemitida', dados.message);
    router.reload({ only: ['cobrancas'] });
  } finally {
    processandoId.value = null;
  }
}

const mostrarModalCancelamento = ref(false);
const cobrancaEmCancelamento = ref(null);
const motivoCancelamento = ref('');
const erroCancelamento = ref('');
const cancelando = ref(false);

function abrirCancelamento(cobranca) {
  cobrancaEmCancelamento.value = cobranca;
  motivoCancelamento.value = '';
  erroCancelamento.value = '';
  mostrarModalCancelamento.value = true;
}

function fecharCancelamento() {
  if (cancelando.value) return;
  mostrarModalCancelamento.value = false;
  cobrancaEmCancelamento.value = null;
}

async function confirmarCancelamento() {
  const motivo = motivoCancelamento.value.trim();

  if (motivo.length < 5) {
    erroCancelamento.value = 'O motivo é obrigatório e precisa ter pelo menos 5 caracteres.';
    return;
  }

  cancelando.value = true;
  erroCancelamento.value = '';

  try {
    const resposta = await fetch(`/cobrancas/${cobrancaEmCancelamento.value.id}/cancelar`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
        Accept: 'application/json',
      },
      body: JSON.stringify({ motivo }),
    });

    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) {
      erroCancelamento.value = dados.message || Object.values(dados.errors ?? {}).flat()[0] || 'Não foi possível cancelar.';
      return;
    }

    mostrarModalCancelamento.value = false;
    cobrancaEmCancelamento.value = null;
    mostrarResultado('success', 'Cobrança cancelada', dados.message);
    router.reload({ only: ['cobrancas'] });
  } finally {
    cancelando.value = false;
  }
}
</script>
