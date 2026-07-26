<!--
  BotaoWhatsapp: abre a conversa do WhatsApp com uma mensagem pronta, em nova aba.
  Não envia nada sozinho — o clique manual é a única ação que este componente faz.

  Contrato de uso, para quem for reutilizar (ex.: Task 14.9, em OS, orçamento e
  título a receber):
  - Este componente NÃO conhece nem decide sobre a preferência de canal do
    cliente (`aceita_whatsapp`). Quem usa o componente é responsável por essa
    decisão: se o cliente recusou o WhatsApp, passe `telefone` vazio ou nulo.
    "Sem telefone" e "canal recusado" recebem o mesmo tratamento visual aqui,
    com um texto que faz sentido nos dois casos.
  - `telefone` chega como está salvo no cadastro (com ou sem máscara). A
    normalização para o formato internacional acontece só na hora de montar o
    link do wa.me — nunca altera o valor exibido ou salvo em outro lugar.
-->
<template>
  <div class="inline-flex flex-col items-start gap-1">
    <button
      type="button"
      :disabled="!disponivel"
      @click="abrirConversa"
      :title="disponivel ? 'Abrir conversa no WhatsApp' : motivo"
      class="btn-primary disabled:opacity-50 disabled:cursor-not-allowed disabled:shadow-sm disabled:transform-none disabled:hover:bg-green-600 disabled:hover:shadow-sm"
    >
      <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path>
      </svg>
      WhatsApp
    </button>
    <span v-if="!disponivel" class="text-xs text-gray-500">{{ motivo }}</span>
  </div>
</template>

<script setup>
import { computed } from 'vue';

const props = defineProps({
  telefone: {
    type: String,
    default: null,
  },
  mensagem: {
    type: String,
    default: '',
  },
});

/**
 * Normaliza o telefone para o formato internacional exigido pelo link do
 * wa.me: remove tudo que não é dígito e, quando o número tem 10 ou 11
 * dígitos e ainda não começa com 55, acrescenta o código do país na frente.
 *
 * Números com menos de 10 dígitos não são um telefone utilizável (o wa.me
 * abriria uma conversa quebrada), então voltam como null, mesmo tratamento
 * de "sem telefone". Números com 12+ dígitos já chegam prontos (com DDI) e
 * saem como vieram, sem receber outro 55 na frente.
 *
 * Só existe para montar o link: nunca altera o que a tela exibe ou salva.
 */
function normalizarTelefone(valor) {
  if (!valor) {
    return null;
  }

  const digitos = valor.replace(/\D/g, '');

  if (digitos.length < 10) {
    return null;
  }

  if ((digitos.length === 10 || digitos.length === 11) && !digitos.startsWith('55')) {
    return `55${digitos}`;
  }

  return digitos;
}

const telefoneNormalizado = computed(() => normalizarTelefone(props.telefone));

const disponivel = computed(() => !!telefoneNormalizado.value);

const motivo = computed(() => (
  disponivel.value ? '' : 'WhatsApp indisponível para este cliente'
));

const link = computed(() => {
  if (!disponivel.value) {
    return null;
  }

  return `https://wa.me/${telefoneNormalizado.value}?text=${encodeURIComponent(props.mensagem || '')}`;
});

function abrirConversa() {
  if (!disponivel.value || !link.value) {
    return;
  }

  window.open(link.value, '_blank', 'noopener');
}
</script>
