<template>
  <!-- Overlay próprio, e não `Modal.vue`: aquele componente é fixo em
       `sm:max-w-lg`, largura que espreme a grade de signatários e a
       pré-visualização do PDF a ponto de o usuário não conseguir conferir o
       documento antes de enviar — que é justamente o ponto desta tela. Mesmo
       padrão de sobreposição do modal de confirmação do design system. -->
  <div
    v-if="show"
    class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-gray-600 bg-opacity-50 p-4"
  >
    <div class="relative my-8 w-full max-w-3xl rounded-xl bg-white shadow-xl">
      <div class="flex items-center gap-4 border-b border-gray-200 px-6 py-4">
        <div class="flex h-12 w-12 flex-shrink-0 items-center justify-center rounded-full bg-green-100">
          <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
          </svg>
        </div>
        <div>
          <h3 class="text-lg font-semibold text-gray-900">Enviar contrato para assinatura</h3>
          <p class="text-sm text-gray-500">O documento vai por e-mail e não pode ser recolhido.</p>
        </div>
      </div>

      <div class="px-6 py-5">
      <!-- Ambiente de teste: documento assinado em sandbox NÃO tem validade
           jurídica, e quem envia precisa saber disso antes de clicar. -->
      <div v-if="ambiente === 'sandbox'" class="mb-4 rounded-md border border-amber-200 bg-amber-50 p-4">
        <p class="text-sm font-medium text-amber-800">Ambiente de teste (sandbox)</p>
        <p class="mt-1 text-sm text-amber-700">
          O documento assinado aqui não tem validade jurídica. Troque para produção na tela de
          configuração antes de enviar contrato de cliente de verdade.
        </p>
      </div>

      <div class="rounded-md border border-blue-200 bg-blue-50 p-4">
        <p class="text-sm text-blue-800">
          O PDF abaixo é exatamente o que vai chegar ao cliente por e-mail. Confira antes de
          confirmar: depois de enviado, não há como recolher.
        </p>
      </div>

      <!-- Pré-visualização do PDF que será enviado. É o mesmo endereço que o
           botão "PDF" da tela usa, e o mesmo template que o backend renderiza
           em `ContractService::renderizarPdf()`: o que está na tela é o que
           sai. -->
      <div class="mt-4">
        <div class="flex items-center justify-between mb-2">
          <h4 class="text-sm font-medium text-gray-700">Pré-visualização do contrato</h4>
          <a :href="urlDoPdf" target="_blank" rel="noopener" class="text-sm text-green-700 hover:text-green-800">
            Abrir em nova aba
          </a>
        </div>
        <iframe
          :src="urlDoPdf"
          title="Pré-visualização do contrato"
          class="w-full h-64 sm:h-80 rounded-md border border-gray-200 bg-gray-50"
        ></iframe>
      </div>

      <!-- Signatários -->
      <div class="mt-6">
        <div class="flex items-center justify-between mb-2">
          <h4 class="text-sm font-medium text-gray-700">Quem assina</h4>
          <button type="button" class="btn-secondary-sm" @click="adicionarSignatario">
            Adicionar signatário
          </button>
        </div>

        <p class="text-xs text-gray-500 mb-3">
          A ordem define quem assina primeiro. Signatários com a mesma ordem assinam ao mesmo tempo.
        </p>

        <div class="space-y-3">
          <div
            v-for="(signatario, indice) in signatarios"
            :key="indice"
            class="rounded-md border border-gray-200 p-3"
          >
            <div class="grid grid-cols-1 sm:grid-cols-12 gap-3">
              <div class="sm:col-span-4">
                <label class="block text-xs font-medium text-gray-500 mb-1">Nome *</label>
                <input
                  v-model="signatario.nome"
                  type="text"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                />
              </div>
              <div class="sm:col-span-4">
                <label class="block text-xs font-medium text-gray-500 mb-1">E-mail *</label>
                <input
                  v-model="signatario.email"
                  type="email"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                />
              </div>
              <div class="sm:col-span-2">
                <label class="block text-xs font-medium text-gray-500 mb-1">Papel *</label>
                <select
                  v-model="signatario.papel"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                >
                  <option value="contratada">Contratada</option>
                  <option value="contratante">Contratante</option>
                  <option value="testemunha">Testemunha</option>
                </select>
              </div>
              <div class="sm:col-span-1">
                <label class="block text-xs font-medium text-gray-500 mb-1">Ordem</label>
                <input
                  v-model.number="signatario.ordem"
                  type="number"
                  min="1"
                  max="10"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                />
              </div>
              <div class="sm:col-span-1 flex items-end">
                <button
                  type="button"
                  class="text-sm text-red-600 hover:text-red-700 disabled:text-gray-300"
                  :disabled="signatarios.length <= 2"
                  title="Remover signatário"
                  @click="removerSignatario(indice)"
                >
                  Remover
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Prazo e mensagem -->
      <div class="mt-6 grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Prazo para assinar (dias)</label>
          <input
            v-model.number="diasParaExpirar"
            type="number"
            min="1"
            max="90"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          />
          <p class="mt-1 text-xs text-gray-500">
            Passado o prazo, o documento expira e o contrato volta a aceitar alteração.
          </p>
        </div>
        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Mensagem no convite</label>
          <textarea
            v-model="mensagem"
            rows="3"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            placeholder="Texto opcional que acompanha o convite de assinatura."
          ></textarea>
        </div>
      </div>

        <div v-if="erro" class="mt-4 rounded-md border border-red-200 bg-red-50 p-4">
          <p class="text-sm font-medium text-red-800">{{ erro }}</p>
        </div>
      </div>

      <div class="flex flex-col-reverse gap-3 border-t border-gray-200 bg-gray-50 px-6 py-4 sm:flex-row sm:justify-end">
        <button type="button" class="btn-secondary" :disabled="enviando" @click="fechar">
          Cancelar
        </button>
        <button type="button" class="btn-primary" :disabled="enviando" @click="enviar">
          {{ enviando ? 'Enviando...' : 'Confirmar e enviar' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, watch } from 'vue';

/**
 * Envio do contrato para assinatura eletrônica (Plano 26, Task 26.5).
 *
 * A pré-visualização do PDF não é enfeite: o documento vai direto ao e-mail do
 * cliente e não há como recolher. Ela mostra o mesmo endereço que o botão
 * "PDF" da tela do contrato usa, que renderiza o mesmo template Blade que
 * `ContractService::renderizarPdf()` manda ao provedor — o que está na tela é o
 * que sai.
 *
 * Os signatários vêm pré-preenchidos com o usuário logado (contratada) e o
 * contato do cliente (contratante), e continuam editáveis: quem assina pelo
 * cliente muitas vezes não é quem está no cadastro, e o e-mail é justamente o
 * que precisa estar certo.
 */
const props = defineProps({
  show: { type: Boolean, default: false },
  contrato: { type: Object, required: true },
  address: { type: Object, default: null },
  usuario: { type: Object, default: null },
  ambiente: { type: String, default: null },
});

const emit = defineEmits(['close', 'enviado']);

const PRAZO_PADRAO_EM_DIAS = 15;

const signatarios = ref([]);
const diasParaExpirar = ref(PRAZO_PADRAO_EM_DIAS);
const mensagem = ref('');
const enviando = ref(false);
const erro = ref('');

const urlDoPdf = ref('');

const preencher = () => {
  signatarios.value = [
    {
      nome: props.usuario?.name || '',
      email: props.usuario?.email || '',
      papel: 'contratada',
      ordem: 1,
    },
    {
      nome: props.address?.client?.name || '',
      email: props.address?.client?.email || '',
      papel: 'contratante',
      ordem: 2,
    },
  ];

  diasParaExpirar.value = PRAZO_PADRAO_EM_DIAS;
  mensagem.value = '';
  erro.value = '';
  urlDoPdf.value = props.address?.id ? `/addresses/${props.address.id}/contract/pdf` : '';
};

watch(
  () => props.show,
  (aberto) => {
    if (aberto) {
      preencher();
    }
  },
  { immediate: true }
);

const adicionarSignatario = () => {
  signatarios.value.push({
    nome: '',
    email: '',
    papel: 'testemunha',
    ordem: signatarios.value.length + 1,
  });
};

const removerSignatario = (indice) => {
  // Nunca abaixo de dois: o backend recusa pedido sem contratante e
  // contratada, e é melhor bloquear aqui do que gastar uma ida ao servidor
  // para receber a mesma recusa.
  if (signatarios.value.length <= 2) return;
  signatarios.value.splice(indice, 1);
};

const fechar = () => {
  if (enviando.value) return;
  emit('close');
};

const enviar = async () => {
  enviando.value = true;
  erro.value = '';

  try {
    const resposta = await fetch(`/contratos/${props.contrato.id}/assinatura`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        Accept: 'application/json',
      },
      body: JSON.stringify({
        signatarios: signatarios.value,
        dias_para_expirar: diasParaExpirar.value,
        mensagem: mensagem.value || null,
      }),
    });

    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) {
      erro.value = dados.message || 'Não foi possível enviar o contrato para assinatura.';
      return;
    }

    emit('enviado', dados.pedido || null);
  } catch (falha) {
    erro.value = 'Não foi possível falar com o servidor. Verifique a conexão e tente de novo.';
  } finally {
    enviando.value = false;
  }
};
</script>
