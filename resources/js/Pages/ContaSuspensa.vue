<template>
  <AuthenticatedLayout>
    <div class="max-w-2xl mx-auto">
      <Card>
        <div class="flex flex-col items-center text-center gap-4 py-6">
          <div class="flex h-14 w-14 items-center justify-center rounded-full" :class="suspensa ? 'bg-yellow-100' : 'bg-green-100'">
            <svg class="h-7 w-7" :class="suspensa ? 'text-yellow-600' : 'text-green-600'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
            </svg>
          </div>

          <div>
            <h1 class="text-xl font-semibold text-gray-900">
              {{ suspensa ? 'Acesso suspenso por falta de pagamento' : 'Sua conta está ativa' }}
            </h1>
            <p class="mt-2 text-sm text-gray-600 max-w-md">{{ empresa }}</p>
          </div>

          <template v-if="suspensa">
            <p class="text-sm text-gray-500 max-w-md">
              Nenhum dado foi apagado nem alterado. Clientes, ordens de serviço, certificados e financeiro
              continuam íntegros, e o acesso volta assim que o pagamento for confirmado.
            </p>

            <div v-if="fatura" class="w-full max-w-md rounded-lg border border-gray-200 bg-gray-50 p-4 text-left">
              <div class="flex items-baseline justify-between">
                <span class="text-sm text-gray-500">Fatura {{ fatura.referencia }}</span>
                <span class="text-lg font-semibold text-gray-900">{{ valorEmReal }}</span>
              </div>
              <p class="mt-1 text-sm text-gray-500">
                Vencimento: {{ formatarData(fatura.vencimento) }}
              </p>

              <div class="mt-4 space-y-3">
                <a
                  v-if="fatura.link_pagamento"
                  :href="fatura.link_pagamento"
                  target="_blank"
                  rel="noopener"
                  class="btn-primary inline-flex w-full justify-center"
                >
                  Pagar agora
                </a>

                <div v-if="fatura.linha_digitavel">
                  <label class="block text-sm font-medium text-gray-700 mb-1">Linha digitável do boleto</label>
                  <div class="flex items-stretch gap-2">
                    <p class="flex-1 break-all rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                      {{ fatura.linha_digitavel }}
                    </p>
                    <button
                      type="button"
                      class="btn-secondary-sm shrink-0"
                      @click="copiar(fatura.linha_digitavel, linhaCopiada)"
                    >
                      {{ linhaCopiada ? 'Copiado!' : 'Copiar' }}
                    </button>
                  </div>
                </div>

                <div v-if="fatura.qr_code_pix">
                  <label class="block text-sm font-medium text-gray-700 mb-1">Pix copia e cola</label>
                  <div class="flex items-stretch gap-2">
                    <p class="flex-1 break-all rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900">
                      {{ fatura.qr_code_pix }}
                    </p>
                    <button
                      type="button"
                      class="btn-secondary-sm shrink-0"
                      @click="copiar(fatura.qr_code_pix, pixCopiado)"
                    >
                      {{ pixCopiado ? 'Copiado!' : 'Copiar' }}
                    </button>
                  </div>
                </div>

                <p v-if="semInstrucaoDePagamento" class="text-sm text-gray-500">
                  Esta fatura ainda não tem código de pagamento emitido. Fale com o suporte para receber a
                  instrução de pagamento.
                </p>
              </div>
            </div>

            <p v-else class="text-sm text-gray-500 max-w-md">
              Não encontramos uma fatura em aberto no seu cadastro. Fale com o suporte para regularizar o acesso.
            </p>

            <div class="mt-2 flex flex-col items-center gap-2 sm:flex-row sm:justify-center">
              <Link :href="route('assinatura.faturas.index')" class="btn-secondary">Ver minhas faturas</Link>
              <button type="button" class="btn-secondary" @click="sair">Sair</button>
            </div>
          </template>

          <template v-else>
            <p class="text-sm text-gray-500 max-w-md">
              Não há bloqueio de acesso na sua empresa. Você pode voltar ao sistema normalmente.
            </p>

            <Link :href="route('dashboard')" class="btn-primary mt-2">Voltar ao início</Link>
          </template>
        </div>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Card from '@/Components/Card.vue';
import { formatarData } from '@/utils/formatDate';

// `fatura` é a não paga mais antiga da empresa, montada por
// `ContaSuspensaController`. Pode ser nula: existe tenant suspenso pelo super
// admin por outro motivo, e existe fatura cuja cobrança não chegou a ser emitida
// no provedor. A tela trata os dois casos em vez de assumir que sempre há um
// código para copiar.
const props = defineProps({
  empresa: {
    type: String,
    required: true,
  },
  suspensa: {
    type: Boolean,
    required: true,
  },
  fatura: {
    type: Object,
    default: null,
  },
});

const valorEmReal = computed(() =>
  new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(props.fatura?.valor ?? 0)
);

const semInstrucaoDePagamento = computed(
  () => !props.fatura?.link_pagamento && !props.fatura?.linha_digitavel && !props.fatura?.qr_code_pix
);

// Confirmação visual de cópia: o botão troca o texto por 2 segundos. Cliente
// que não sabe se o código foi copiado tende a desistir do pagamento, e é
// exatamente esse abandono que a régua de inadimplência não pode gerar.
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

function sair() {
  router.post(route('logout'));
}
</script>
