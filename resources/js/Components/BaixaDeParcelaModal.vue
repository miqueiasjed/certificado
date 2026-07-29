<!--
  BaixaDeParcelaModal: registra o recebimento de uma parcela (baixa total ou
  parcial) ou de várias parcelas do mesmo cliente de uma vez (baixa em lote,
  para o depósito único que cobre mais de uma parcela).

  Modo único (uma parcela em `parcelas`): o valor nasce preenchido com o saldo
  devedor e pode ser reduzido para uma baixa parcial. Antes de confirmar, a
  tela mostra o saldo que continua em aberto, para ninguém descobrir depois
  que a parcela não foi quitada.

  Modo lote (mais de uma parcela): cada parcela é baixada pelo próprio saldo
  integral, todas com a mesma data e forma de pagamento, o que corresponde ao
  caso real de um depósito só cobrindo várias parcelas. Não existe baixa
  parcial em lote: dividir um valor menor entre parcelas diferentes é uma
  decisão que este modal não toma sozinho.

  Estorno não vive aqui: é ação separada, com motivo obrigatório, tratada por
  quem usa este componente.
-->
<template>
  <Modal :show="show" @close="fechar">
    <template #icon>
      <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1" />
      </svg>
    </template>

    <template #title>{{ ehLote ? 'Baixar parcelas selecionadas' : 'Baixar parcela' }}</template>

    <template #content>
      <div class="space-y-4 text-left">
        <!-- Modo único: identificação da parcela -->
        <div v-if="!ehLote && parcelaUnica" class="bg-gray-50 border border-gray-200 rounded-md p-3">
          <p class="text-sm font-medium text-gray-900">{{ parcelaUnica.cliente }}</p>
          <p class="text-sm text-gray-600">{{ parcelaUnica.descricao }}</p>
          <p class="text-xs text-gray-500 mt-1">
            Vencimento {{ formatarData(parcelaUnica.vencimento) }} · Saldo devedor {{ formatarMoeda(parcelaUnica.saldo) }}
          </p>
        </div>

        <!-- Modo lote: lista das parcelas selecionadas -->
        <div v-else class="border border-gray-200 rounded-md divide-y divide-gray-200 max-h-48 overflow-y-auto">
          <div v-for="parcela in parcelas" :key="parcela.id" class="px-3 py-2 flex items-center justify-between text-sm">
            <div>
              <p class="font-medium text-gray-900">{{ parcela.cliente }}</p>
              <p class="text-gray-600">{{ parcela.descricao }} · vence {{ formatarData(parcela.vencimento) }}</p>
            </div>
            <span class="text-gray-900 font-medium flex-shrink-0 ml-3">{{ formatarMoeda(parcela.saldo) }}</span>
          </div>
        </div>

        <p v-if="ehLote" class="text-sm text-gray-700">
          {{ parcelas.length }} parcela(s) do mesmo cliente, baixadas integralmente com a mesma data e forma de
          pagamento. Valor total do depósito: <strong>{{ formatarMoeda(saldoTotal) }}</strong>.
        </p>

        <!-- Valor: editável só no modo único, para permitir baixa parcial -->
        <div v-if="!ehLote">
          <label class="block text-sm font-medium text-gray-700 mb-1">Valor recebido *</label>
          <input
            :value="valorExibido"
            @input="alterarValor"
            type="text"
            inputmode="decimal"
            placeholder="0,00"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          />

          <!-- Saldo remanescente ANTES de confirmar: é a regra que não pode faltar -->
          <p v-if="mensagemSaldo" :class="['mt-2 text-sm rounded-md px-3 py-2', mensagemSaldo.tom]">
            {{ mensagemSaldo.texto }}
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Data do recebimento *</label>
          <input
            v-model="data"
            type="date"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          />
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Forma de pagamento</label>
          <select
            v-model="formaPagamento"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          >
            <option value="">Selecione</option>
            <option value="pix">PIX</option>
            <option value="dinheiro">Dinheiro</option>
            <option value="cartao_credito">Cartão de crédito</option>
            <option value="cartao_debito">Cartão de débito</option>
            <option value="boleto">Boleto bancário</option>
            <option value="transferencia">Transferência bancária</option>
          </select>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Observação</label>
          <textarea
            v-model="observacao"
            rows="2"
            maxlength="1000"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            placeholder="Opcional"
          ></textarea>
        </div>

        <p v-if="erro" class="text-sm text-red-600">{{ erro }}</p>

        <ul v-if="falhasDoLote.length" class="text-sm text-red-600 list-disc pl-5">
          <li v-for="falha in falhasDoLote" :key="falha.id">{{ falha.cliente }}: {{ falha.mensagem }}</li>
        </ul>
      </div>
    </template>

    <template #actions>
      <button type="button" class="btn-secondary" :disabled="enviando" @click="fechar">Cancelar</button>
      <button type="button" class="btn-primary ml-3" :disabled="!podeConfirmar || enviando" @click="confirmar">
        {{ enviando ? 'Baixando...' : (ehLote ? `Baixar ${parcelas.length} parcela(s)` : 'Confirmar baixa') }}
      </button>
    </template>
  </Modal>
</template>

<script setup>
import { computed, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import { formatarData, hojeISO } from '@/utils/formatDate';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  // Uma parcela (baixa única) ou várias do mesmo cliente (baixa em lote).
  // Cada item precisa de: id, cliente, descricao, vencimento, saldo.
  parcelas: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['close', 'sucesso']);

const valor = ref('');
const data = ref(hojeISO());
const formaPagamento = ref('');
const observacao = ref('');
const enviando = ref(false);
const erro = ref('');
const falhasDoLote = ref([]);

const ehLote = computed(() => props.parcelas.length > 1);
const parcelaUnica = computed(() => props.parcelas[0] ?? null);

const saldoTotal = computed(() => props.parcelas.reduce((soma, p) => soma + numero(p.saldo), 0));

function numero(valorMonetario) {
  const n = typeof valorMonetario === 'number' ? valorMonetario : parseFloat(valorMonetario || 0);
  return Number.isFinite(n) ? n : 0;
}

function formatarMoeda(valorMonetario) {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(numero(valorMonetario));
}

const valorExibido = computed(() => valor.value);

function alterarValor(evento) {
  let bruto = evento.target.value.replace(/\D/g, '');

  if (bruto === '') {
    valor.value = '';
    return;
  }

  const centavos = parseInt(bruto, 10);
  valor.value = (centavos / 100).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function valorNumerico() {
  if (!valor.value) return 0;
  return parseFloat(valor.value.replace(/\./g, '').replace(',', '.')) || 0;
}

// Reseta o formulário sempre que o modal é reaberto para um conjunto novo de
// parcelas, para não herdar valor/observação de uma baixa anterior.
watch(
  () => props.show,
  (aberto) => {
    if (!aberto) return;

    data.value = hojeISO();
    formaPagamento.value = '';
    observacao.value = '';
    erro.value = '';
    falhasDoLote.value = [];
    valor.value = ehLote.value || !parcelaUnica.value
      ? ''
      : numero(parcelaUnica.value.saldo).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  },
);

const mensagemSaldo = computed(() => {
  if (ehLote.value || !parcelaUnica.value) return null;

  const digitado = valorNumerico();
  const saldo = numero(parcelaUnica.value.saldo);

  if (digitado <= 0) {
    return { tom: 'bg-gray-50 text-gray-600', texto: 'Informe o valor recebido.' };
  }

  if (digitado > saldo + 0.001) {
    return {
      tom: 'bg-red-50 text-red-700',
      texto: `O valor não pode ser maior que o saldo devedor (${formatarMoeda(saldo)}).`,
    };
  }

  const restante = saldo - digitado;

  if (restante > 0.005) {
    return {
      tom: 'bg-amber-50 text-amber-800',
      texto: `Baixa parcial: depois de confirmar, a parcela continua em aberto com saldo de ${formatarMoeda(restante)}.`,
    };
  }

  return { tom: 'bg-green-50 text-green-700', texto: 'Esta baixa quita a parcela integralmente.' };
});

const podeConfirmar = computed(() => {
  if (!data.value) return false;

  if (ehLote.value) {
    return props.parcelas.length > 0 && saldoTotal.value > 0;
  }

  if (!parcelaUnica.value) return false;

  const digitado = valorNumerico();

  return digitado > 0 && digitado <= numero(parcelaUnica.value.saldo) + 0.001;
});

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
}

async function baixarUma(parcelaId, valorDaBaixa) {
  const resposta = await fetch(`/contas-a-receber/parcelas/${parcelaId}/baixar`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': csrfToken(),
      Accept: 'application/json',
    },
    body: JSON.stringify({
      valor: valorDaBaixa,
      data: data.value,
      forma_pagamento: formaPagamento.value || null,
      observacao: observacao.value || null,
    }),
  });

  const dados = await resposta.json().catch(() => ({}));

  return { ok: resposta.ok, mensagem: dados.message || Object.values(dados.errors ?? {}).flat()[0] || 'Erro ao baixar a parcela.' };
}

async function confirmar() {
  if (!podeConfirmar.value || enviando.value) return;

  enviando.value = true;
  erro.value = '';
  falhasDoLote.value = [];

  if (!ehLote.value) {
    const resultado = await baixarUma(parcelaUnica.value.id, valorNumerico());

    enviando.value = false;

    if (!resultado.ok) {
      erro.value = resultado.mensagem;
      return;
    }

    emit('sucesso', { sucesso: 1, falhas: 0 });
    return;
  }

  // Lote: sequencial, não em paralelo, para o resultado ficar fácil de somar
  // parcela a parcela e não sobrecarregar o servidor com baixas simultâneas.
  const falhas = [];
  let sucesso = 0;

  for (const parcela of props.parcelas) {
    const resultado = await baixarUma(parcela.id, numero(parcela.saldo));

    if (resultado.ok) {
      sucesso++;
    } else {
      falhas.push({ id: parcela.id, cliente: parcela.cliente, mensagem: resultado.mensagem });
    }
  }

  enviando.value = false;
  falhasDoLote.value = falhas;

  if (falhas.length === 0) {
    emit('sucesso', { sucesso, falhas: 0 });
    return;
  }

  erro.value = sucesso > 0
    ? `${sucesso} parcela(s) baixada(s). ${falhas.length} falharam, veja abaixo.`
    : 'Nenhuma parcela pôde ser baixada.';

  if (sucesso > 0) {
    emit('sucesso', { sucesso, falhas: falhas.length, mantemAberto: true });
  }
}

function fechar() {
  if (enviando.value) return;
  emit('close');
}
</script>
