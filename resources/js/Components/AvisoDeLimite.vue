<template>
  <div v-if="avisosVisiveis.length" class="space-y-px">
    <div
      v-for="aviso in avisosVisiveis"
      :key="aviso.texto"
      class="flex items-start justify-between gap-3 border-b px-4 py-2 sm:px-6 lg:px-8"
      :class="aviso.estourado
        ? 'bg-red-50 border-red-200 text-red-800'
        : 'bg-yellow-50 border-yellow-200 text-yellow-800'">
      <div class="flex items-start gap-2">
        <svg class="h-5 w-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" />
        </svg>
        <p class="text-sm font-medium">{{ aviso.texto }}</p>
      </div>

      <button
        type="button"
        class="shrink-0 rounded p-1 opacity-70 hover:opacity-100 focus:outline-none"
        @click="fechar(aviso.texto)">
        <span class="sr-only">Fechar aviso</span>
        <svg class="h-4 w-4" fill="currentColor" viewBox="0 0 20 20">
          <path
            fill-rule="evenodd"
            d="M4.293 4.293a1 1 0 011.414 0L10 8.586l4.293-4.293a1 1 0 111.414 1.414L11.414 10l4.293 4.293a1 1 0 01-1.414 1.414L10 11.414l-4.293 4.293a1 1 0 01-1.414-1.414L8.586 10 4.293 5.707a1 1 0 010-1.414z"
            clip-rule="evenodd" />
        </svg>
      </button>
    </div>
  </div>
</template>

<script setup>
/**
 * Faixa discreta de aviso de limite quantitativo do plano (Task 6.5), lida
 * direto de `page.props.avisos` (array de strings prontas, compartilhado por
 * `HandleInertiaRequests`). Sem prop: entra uma vez em `AuthenticatedLayout`
 * e aparece em qualquer página, sem cada página precisar declará-la.
 *
 * O backend não manda um estado estruturado, só a frase pronta
 * (`LimitesDoPlanoService::mensagemDeAviso()`), e as duas frases possíveis se
 * diferenciam por uma palavra fixa: só a mensagem de limite ultrapassado tem
 * "ultrapassado" no texto; a de "perto do teto" nunca tem. Checar essa
 * palavra é suficiente para colorir a faixa e mais simples do que mudar o
 * formato do prop compartilhado só para isso.
 *
 * Fechar esconde a mensagem pelo resto da sessão do navegador (sessionStorage,
 * pela chave da própria mensagem): ela volta a aparecer no próximo login,
 * porque um novo login normalmente também é uma nova aba/sessão do navegador.
 * Se o texto mudar (o percentual subiu, por exemplo), a chave muda junto e o
 * aviso aparece de novo, o que é o comportamento certo: é uma situação nova.
 */
import { computed, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';

const CHAVE_SESSAO = 'avisosDeLimiteFechados';

const $page = usePage();

function lerFechados() {
  try {
    const bruto = sessionStorage.getItem(CHAVE_SESSAO);
    const lista = bruto ? JSON.parse(bruto) : [];

    return Array.isArray(lista) ? lista : [];
  } catch {
    // Modo privado ou sessionStorage indisponível: segue sem lembrar o
    // fechamento entre navegações, mas a faixa continua funcionando.
    return [];
  }
}

const fechados = ref(lerFechados());

const avisosVisiveis = computed(() =>
  ($page.props.avisos || [])
    .filter((texto) => !fechados.value.includes(texto))
    .map((texto) => ({ texto, estourado: texto.includes('ultrapassado') }))
);

const fechar = (texto) => {
  if (fechados.value.includes(texto)) {
    return;
  }

  fechados.value = [...fechados.value, texto];

  try {
    sessionStorage.setItem(CHAVE_SESSAO, JSON.stringify(fechados.value));
  } catch {
    // Sem persistência: o aviso some agora e pode voltar numa navegação
    // seguinte, o que é aceitável frente a travar a tela.
  }
};
</script>
