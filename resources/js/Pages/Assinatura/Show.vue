<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Assinatura" description="Plano, faturas e forma de pagamento da sua empresa" />
    </template>

    <div class="max-w-5xl mx-auto space-y-6">
      <!-- Mensagens flash -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!-- Tenant interno: sem cobrança, sem fatura, sem ação -->
      <Card v-if="situacao.imune">
        <div class="text-center py-8">
          <h2 class="text-lg font-medium text-gray-900">Plano interno</h2>
          <p class="mt-2 text-sm text-gray-600 max-w-md mx-auto">
            Esta empresa é operada internamente e não é cobrada. Não há fatura nem forma de pagamento a gerenciar.
          </p>
          <p v-if="situacao.plano" class="mt-4 text-sm text-gray-500">
            Plano vinculado: <span class="font-medium text-gray-900">{{ situacao.plano.nome }}</span>
          </p>
        </div>
      </Card>

      <template v-else>
        <!-- Bloco do plano atual -->
        <Card>
          <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <h2 class="text-lg font-medium text-gray-900">{{ situacao.plano?.nome || 'Sem plano definido' }}</h2>
              <p v-if="situacao.plano" class="mt-1 text-2xl font-semibold text-gray-900">
                {{ valorDoPlanoFormatado }}
                <span v-if="situacao.plano.periodicidade" class="text-sm font-normal text-gray-500">
                  / {{ ROTULOS_PERIODICIDADE[situacao.plano.periodicidade] || situacao.plano.periodicidade }}
                </span>
              </p>
            </div>

            <span
              v-if="situacao.situacao"
              class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
              :class="CLASSES_SITUACAO_ASSINATURA[situacao.situacao] || CLASSES_SITUACAO_ASSINATURA.cancelada"
            >
              {{ ROTULOS_SITUACAO_ASSINATURA[situacao.situacao] || situacao.situacao }}
            </span>
          </div>

          <dl class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-3">
            <div>
              <dt class="text-sm font-medium text-gray-500">Periodicidade</dt>
              <dd class="mt-1 text-sm text-gray-900">
                {{ ROTULOS_PERIODICIDADE[situacao.plano?.periodicidade] || '-' }}
              </dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Próxima cobrança</dt>
              <dd class="mt-1 text-sm text-gray-900">
                {{ situacao.proxima_cobranca_em ? formatarData(situacao.proxima_cobranca_em) : '-' }}
              </dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Forma de pagamento</dt>
              <dd class="mt-1 text-sm text-gray-900">
                {{ ROTULOS_FORMA_PAGAMENTO[situacao.forma_pagamento] || '-' }}
              </dd>
            </div>
          </dl>

          <p v-if="!situacao.tem_assinatura" class="mt-4 text-sm text-gray-500">
            Esta empresa ainda não tem assinatura contratada.
          </p>

          <p v-if="situacao.situacao === 'cancelada'" class="mt-4 text-sm text-gray-500">
            Cancelada em {{ situacao.cancelada_em ? formatarDataHora(situacao.cancelada_em) : '-' }}.
            <template v-if="situacao.motivo_cancelamento">Motivo: {{ situacao.motivo_cancelamento }}</template>
          </p>

          <div class="mt-6">
            <button type="button" class="btn-secondary" @click="abrirModalFormaPagamento">
              Trocar forma de pagamento
            </button>
          </div>
        </Card>

        <!-- Fatura em aberto em destaque -->
        <Card v-if="faturaEmAberto">
          <div class="flex items-baseline justify-between">
            <h3 class="text-lg font-medium text-gray-900">Fatura em aberto</h3>
            <span
              class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
              :class="CLASSES_SITUACAO_FATURA[faturaEmAberto.situacao] || CLASSES_SITUACAO_FATURA.cancelada"
            >
              {{ ROTULOS_SITUACAO_FATURA[faturaEmAberto.situacao] || faturaEmAberto.situacao }}
            </span>
          </div>

          <div class="mt-4 flex items-baseline justify-between">
            <span class="text-sm text-gray-500">Referência {{ faturaEmAberto.referencia }}</span>
            <span class="text-xl font-semibold text-gray-900">{{ formatarValor(faturaEmAberto.valor) }}</span>
          </div>
          <p class="mt-1 text-sm text-gray-500">
            Vencimento: {{ faturaEmAberto.vencimento ? formatarData(faturaEmAberto.vencimento) : '-' }}
          </p>

          <div class="mt-4 space-y-3 max-w-md">
            <a
              v-if="faturaEmAberto.link_pagamento"
              :href="faturaEmAberto.link_pagamento"
              target="_blank"
              rel="noopener"
              class="btn-primary inline-flex w-full justify-center"
            >
              Pagar agora
            </a>

            <div v-if="faturaEmAberto.qr_code_pix">
              <label class="block text-sm font-medium text-gray-700 mb-1">Pix copia e cola</label>
              <div class="flex items-stretch gap-2">
                <p class="flex-1 break-all rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                  {{ faturaEmAberto.qr_code_pix }}
                </p>
                <button
                  type="button"
                  class="btn-secondary-sm shrink-0"
                  @click="copiar(faturaEmAberto.qr_code_pix, pixCopiado)"
                >
                  {{ pixCopiado ? 'Copiado!' : 'Copiar' }}
                </button>
              </div>
            </div>

            <div v-else-if="faturaEmAberto.linha_digitavel">
              <label class="block text-sm font-medium text-gray-700 mb-1">Linha digitável do boleto</label>
              <div class="flex items-stretch gap-2">
                <p class="flex-1 break-all rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                  {{ faturaEmAberto.linha_digitavel }}
                </p>
                <button
                  type="button"
                  class="btn-secondary-sm shrink-0"
                  @click="copiar(faturaEmAberto.linha_digitavel, linhaCopiada)"
                >
                  {{ linhaCopiada ? 'Copiado!' : 'Copiar' }}
                </button>
              </div>
            </div>

            <p v-else-if="situacao.forma_pagamento === 'cartao'" class="text-sm text-gray-500">
              A cobrança é automática no cartão cadastrado. Nenhuma ação é necessária.
            </p>

            <p v-else class="text-sm text-gray-500">
              Esta fatura ainda não tem código de pagamento emitido. Fale com o suporte para receber a instrução
              de pagamento.
            </p>
          </div>
        </Card>

        <!-- Tabela de faturas dos últimos 12 meses -->
        <Card padding="none">
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Faturas dos últimos 12 meses</h3>
          </div>

          <div v-if="faturas.length > 0" class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Referência</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Valor</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vencimento</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Situação</th>
                  <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Segunda via</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr v-for="fatura in faturas" :key="fatura.id" class="hover:bg-gray-50">
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ fatura.referencia }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ formatarValor(fatura.valor) }}</td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">
                    {{ fatura.vencimento ? formatarData(fatura.vencimento) : '-' }}
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm">
                    <span
                      class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                      :class="CLASSES_SITUACAO_FATURA[fatura.situacao] || CLASSES_SITUACAO_FATURA.cancelada"
                    >
                      {{ ROTULOS_SITUACAO_FATURA[fatura.situacao] || fatura.situacao }}
                    </span>
                  </td>
                  <td class="px-6 py-4 whitespace-nowrap text-sm">
                    <a
                      v-if="fatura.link_pagamento"
                      :href="fatura.link_pagamento"
                      target="_blank"
                      rel="noopener"
                      class="text-green-600 hover:text-green-700 font-medium"
                    >
                      Segunda via
                    </a>
                    <span v-else class="text-gray-400">-</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>

          <p v-else class="px-6 py-8 text-center text-sm text-gray-500">
            Nenhuma fatura nos últimos 12 meses.
          </p>
        </Card>
      </template>
    </div>

    <!-- Modal de troca de forma de pagamento -->
    <Modal :show="mostrarModalFormaPagamento" @close="fecharModalFormaPagamento">
      <template #title>Trocar forma de pagamento</template>
      <template #content>
        <form class="space-y-4" @submit.prevent="trocarFormaDePagamento">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Forma de pagamento *</label>
            <select
              v-model="formPagamento.forma"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': formPagamento.errors.forma }"
            >
              <option value="pix">Pix</option>
              <option value="boleto">Boleto</option>
              <!--
                Cartão fica fora do select por ora: a tokenização real de cartão
                depende do SDK do provedor rodando no navegador (o número, a
                validade e o código de segurança nunca podem chegar ao nosso
                servidor em texto puro). Essa integração de frontend é trabalho
                à parte, ainda não feito, e "cartao_cifrado" como campo de texto
                simples encorajaria o usuário a colar dado sensível de cartão
                num campo qualquer. Quando o SDK estiver integrado, a opção
                entra aqui chamando a tokenização dele antes do post.
              -->
            </select>
            <p v-if="formPagamento.errors.forma" class="mt-1 text-sm text-red-600">
              {{ formPagamento.errors.forma }}
            </p>
          </div>
        </form>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" @click="fecharModalFormaPagamento">Cancelar</button>
        <button type="button" class="btn-primary" :disabled="formPagamento.processing" @click="trocarFormaDePagamento">
          {{ formPagamento.processing ? 'Salvando...' : 'Salvar' }}
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useForm, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import { formatarData, formatarDataHora } from '@/utils/formatDate';

// `situacao` é o retorno de `SubscriptionService::situacaoDe()` (Task 7.8):
// `imune` marca o tenant interno, sem cobrança nenhuma. `faturas` já vem dos
// últimos 12 meses, mais recente primeiro (`InvoiceService::historicoDe()`),
// pronta para a tabela e para achar a fatura em aberto sem consulta extra.
const props = defineProps({
  situacao: {
    type: Object,
    required: true,
  },
  faturas: {
    type: Array,
    default: () => [],
  },
});

const $page = usePage();

const ROTULOS_SITUACAO_ASSINATURA = {
  ativa: 'Ativa',
  em_atraso: 'Em atraso',
  suspensa: 'Suspensa',
  cancelada: 'Cancelada',
};

const CLASSES_SITUACAO_ASSINATURA = {
  ativa: 'bg-green-100 text-green-800',
  em_atraso: 'bg-yellow-100 text-yellow-800',
  suspensa: 'bg-red-100 text-red-800',
  cancelada: 'bg-gray-100 text-gray-800',
};

const ROTULOS_SITUACAO_FATURA = {
  aberta: 'Em aberto',
  paga: 'Paga',
  vencida: 'Vencida',
  cancelada: 'Cancelada',
};

const CLASSES_SITUACAO_FATURA = {
  aberta: 'bg-yellow-100 text-yellow-800',
  paga: 'bg-green-100 text-green-800',
  vencida: 'bg-red-100 text-red-800',
  cancelada: 'bg-gray-100 text-gray-800',
};

const ROTULOS_PERIODICIDADE = {
  mensal: 'mês',
  anual: 'ano',
};

const ROTULOS_FORMA_PAGAMENTO = {
  pix: 'Pix',
  boleto: 'Boleto',
  cartao: 'Cartão de crédito',
};

const valorDoPlanoFormatado = computed(() => formatarValor(props.situacao.plano?.valor));

function formatarValor(valor) {
  return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(valor ?? 0));
}

// Fatura mais recente ainda não quitada: `faturas` já chega ordenada da mais
// recente para a mais antiga, então o primeiro `aberta`/`vencida` encontrado
// é a fatura em destaque.
const faturaEmAberto = computed(() =>
  props.faturas.find((fatura) => fatura.situacao === 'aberta' || fatura.situacao === 'vencida') ?? null
);

// Copiar com confirmação visual: o botão troca de texto por 2 segundos.
// Mesmo padrão usado em `ContaSuspensa.vue`, para quem precisa pagar
// encontrar sempre o mesmo comportamento.
const pixCopiado = ref(false);
const linhaCopiada = ref(false);

async function copiar(texto, indicador) {
  if (!texto) {
    return;
  }

  try {
    await navigator.clipboard.writeText(texto);
    indicador.value = true;
    setTimeout(() => {
      indicador.value = false;
    }, 2000);
  } catch (erro) {
    // Navegador sem permissão de clipboard: o texto continua selecionável manualmente no box acima.
  }
}

// Troca de forma de pagamento. Cartão fica de fora do formulário por ora (ver
// o comentário no select acima); só pix e boleto são aceitos aqui, então
// `cartao_cifrado` nunca precisa ser enviado.
const mostrarModalFormaPagamento = ref(false);

const formaAtualValida = ['pix', 'boleto'].includes(props.situacao.forma_pagamento)
  ? props.situacao.forma_pagamento
  : 'pix';

const formPagamento = useForm({
  forma: formaAtualValida,
});

function abrirModalFormaPagamento() {
  formPagamento.clearErrors();
  formPagamento.forma = formaAtualValida;
  mostrarModalFormaPagamento.value = true;
}

function fecharModalFormaPagamento() {
  mostrarModalFormaPagamento.value = false;
}

function trocarFormaDePagamento() {
  formPagamento.post(route('assinatura.faturas.forma-pagamento'), {
    preserveScroll: true,
    onSuccess: () => {
      mostrarModalFormaPagamento.value = false;
    },
  });
}
</script>
