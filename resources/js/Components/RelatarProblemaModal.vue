<!--
  Formulário curto do botão "Relatar problema", usado no menu do usuário do
  painel (`Sidebar`) e no portal do cliente (`PortalLayout`).

  Envia por `fetch()` (não `useForm`/Inertia) porque o modal abre por cima de
  qualquer tela, e o redirect do Inertia tiraria a pessoa do que ela estava
  fazendo. A URL da tela aberta vai junto sem ninguém digitar.

  `Teleport` para o `body`: o menu lateral do painel usa `transform` para abrir
  e fechar no celular, e elemento `fixed` dentro de um ancestral com
  `transform` fica preso à caixa dele em vez de cobrir a tela.

  `corPrimaria` existe para o portal: lá o botão principal usa a cor da
  empresa, e a variável CSS `--portal-cor-primaria` não alcança o modal
  teleportado, porque ela só existe na raiz do `PortalLayout`.
-->
<template>
  <Teleport to="body">
    <Modal :show="show" @close="fechar">
      <template #icon>
        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            v-if="enviado"
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M5 13l4 4L19 7"></path>
          <path
            v-else
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
        </svg>
      </template>

      <template #title>{{ enviado ? 'Relato enviado' : 'Relatar problema' }}</template>

      <template #content>
        <div v-if="enviado" class="space-y-2">
          <p class="text-sm font-medium text-gray-900">{{ mensagemDeSucesso }}</p>
          <p class="text-sm text-gray-600">
            O suporte técnico vai analisar o que aconteceu nesta tela.
          </p>
        </div>

        <form v-else :id="idDoFormulario" class="space-y-4" @submit.prevent="enviar">
          <p class="text-sm text-gray-600">
            Conte o que aconteceu. O endereço desta tela vai junto com o relato.
          </p>

          <div>
            <label :for="`${idDoFormulario}-titulo`" class="block text-sm font-medium text-gray-700 mb-1">
              Título (opcional)
            </label>
            <input
              :id="`${idDoFormulario}-titulo`"
              v-model="titulo"
              type="text"
              maxlength="200"
              placeholder="Ex.: Botão de salvar não responde"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm"
              :class="{ 'border-red-500': erros.titulo }" />
            <p v-if="erros.titulo" class="mt-1 text-sm text-red-600">{{ erros.titulo }}</p>
          </div>

          <div>
            <label :for="`${idDoFormulario}-descricao`" class="block text-sm font-medium text-gray-700 mb-1">
              O que aconteceu? *
            </label>
            <textarea
              :id="`${idDoFormulario}-descricao`"
              v-model="descricao"
              rows="5"
              maxlength="10000"
              required
              placeholder="Descreva o que você tentou fazer e o que deu errado."
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm"
              :class="{ 'border-red-500': erros.descricao }"></textarea>
            <p v-if="erros.descricao" class="mt-1 text-sm text-red-600">{{ erros.descricao }}</p>
          </div>

          <div v-if="erro" class="bg-red-50 border border-red-200 rounded-md p-3">
            <p class="text-sm text-red-800">{{ erro }}</p>
          </div>
        </form>
      </template>

      <template #actions>
        <div class="flex w-full justify-end gap-3">
          <template v-if="enviado">
            <button
              type="button"
              class="btn-primary"
              :style="estiloDoBotaoPrincipal"
              @click="fechar">
              Fechar
            </button>
          </template>
          <template v-else>
            <button type="button" class="btn-secondary" :disabled="enviando" @click="fechar">
              Cancelar
            </button>
            <button
              type="submit"
              :form="idDoFormulario"
              class="btn-primary disabled:opacity-50 disabled:cursor-not-allowed"
              :style="estiloDoBotaoPrincipal"
              :disabled="enviando || !descricao.trim()">
              {{ enviando ? 'Enviando...' : 'Enviar relato' }}
            </button>
          </template>
        </div>
      </template>
    </Modal>
  </Teleport>
</template>

<script setup>
import { computed, getCurrentInstance, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  // Endereço do POST: `route('relatos.store')` no painel e
  // `route('portal.relatos.store')` no portal.
  rota: {
    type: String,
    required: true,
  },
  // Cor do botão principal no portal (hexadecimal já validado pelo
  // `resolverCoresDoPortal()`). Vazia, vale o verde do sistema.
  corPrimaria: {
    type: String,
    default: '',
  },
});

const emit = defineEmits(['close']);

const idDoFormulario = `relatar-problema-${getCurrentInstance()?.uid}`;

const titulo = ref('');
const descricao = ref('');
const erros = ref({});
const erro = ref('');
const enviando = ref(false);
const enviado = ref(false);
const mensagemDeSucesso = ref('');

const estiloDoBotaoPrincipal = computed(() => (
  props.corPrimaria ? { backgroundColor: props.corPrimaria } : undefined
));

const limpar = () => {
  titulo.value = '';
  descricao.value = '';
  erros.value = {};
  erro.value = '';
  enviando.value = false;
  enviado.value = false;
  mensagemDeSucesso.value = '';
};

// Cada abertura começa do zero: o relato anterior já foi enviado ou
// descartado.
watch(() => props.show, (aberto) => {
  if (aberto) {
    limpar();
  }
});

const fechar = () => {
  if (enviando.value) return;
  emit('close');
};

const primeiraMensagem = (valor) => (Array.isArray(valor) ? valor[0] : valor);

const enviar = async () => {
  if (enviando.value || !descricao.value.trim()) return;

  enviando.value = true;
  erros.value = {};
  erro.value = '';

  try {
    const resposta = await fetch(props.rota, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        Accept: 'application/json',
      },
      body: JSON.stringify({
        titulo: titulo.value.trim() || null,
        descricao: descricao.value,
        pagina_url: window.location.href,
      }),
    });

    const dados = await resposta.json().catch(() => ({}));

    if (resposta.status === 422) {
      erros.value = Object.fromEntries(
        Object.entries(dados.errors || {}).map(([campo, mensagens]) => [campo, primeiraMensagem(mensagens)])
      );
      if (!erros.value.titulo && !erros.value.descricao) {
        erro.value = dados.message || 'Confira o que foi preenchido e tente de novo.';
      }
      return;
    }

    if (resposta.status === 429) {
      erro.value = 'Você enviou vários relatos em pouco tempo. Aguarde alguns minutos e tente de novo.';
      return;
    }

    if (!resposta.ok) {
      erro.value = 'Não foi possível enviar o relato agora. Tente de novo em instantes.';
      return;
    }

    mensagemDeSucesso.value = dados.message || 'Recebemos seu relato. Obrigado!';
    enviado.value = true;
  } catch (falha) {
    erro.value = 'Não foi possível falar com o servidor. Verifique a conexão e tente de novo.';
  } finally {
    enviando.value = false;
  }
};
</script>
