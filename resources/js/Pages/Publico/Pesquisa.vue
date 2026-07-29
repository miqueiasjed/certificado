<template>
  <Head :title="tituloDaPagina" />

  <PublicoLayout :empresa="empresa">
    <!-- Estado disponível: formulário de nota, ainda não respondido nesta
         visita. Qualquer outro estado (inválida, expirada, respondida, ou
         resposta acabada de ser enviada) cai no painel abaixo, sem formulário
         e sem tom de erro de sistema: quem chega aqui só clicou num link. -->
    <div v-if="mostrarFormulario" class="rounded-lg border border-gray-200 bg-white p-6 shadow-sm sm:p-8">
      <div class="text-center">
        <h1 class="text-lg font-semibold text-gray-900">{{ titulo }}</h1>
        <p class="mt-1 text-sm text-gray-600">{{ mensagem }}</p>
        <p v-if="servico_avaliado" class="mt-3 text-sm font-medium text-gray-700">
          Serviço avaliado: {{ servico_avaliado }}
        </p>
      </div>

      <form class="mt-6" @submit.prevent="enviar">
        <div class="flex justify-center gap-2 sm:gap-3">
          <button
            v-for="valorDaEstrela in 5"
            :key="valorDaEstrela"
            type="button"
            class="flex h-14 w-14 items-center justify-center rounded-full transition-transform active:scale-95 sm:h-16 sm:w-16"
            :aria-label="`Nota ${valorDaEstrela} de 5`"
            :aria-pressed="form.nota === valorDaEstrela"
            @click="form.nota = valorDaEstrela">
            <svg
              class="h-10 w-10 sm:h-12 sm:w-12"
              :class="valorDaEstrela <= (form.nota || 0) ? '' : 'text-gray-300'"
              :style="valorDaEstrela <= (form.nota || 0) ? { color: 'var(--publico-cor-primaria)' } : undefined"
              viewBox="0 0 24 24"
              fill="currentColor">
              <path d="M12 2l3.09 6.26L22 9.27l-5 4.87L18.18 21 12 17.77 5.82 21 7 14.14 2 9.27l6.91-1.01L12 2z"></path>
            </svg>
          </button>
        </div>
        <p class="mt-2 text-center text-sm text-gray-500">
          {{ form.nota ? `${form.nota} de 5` : 'Toque em uma estrela para avaliar' }}
        </p>
        <p v-if="form.errors.nota" class="mt-1 text-center text-sm text-red-600">{{ form.errors.nota }}</p>

        <div class="mt-6">
          <label class="mb-1 block text-sm font-medium text-gray-700">Comentário (opcional)</label>
          <textarea
            v-model="form.comentario"
            rows="3"
            :maxlength="limite_do_comentario"
            class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
            :class="{ 'border-red-500': form.errors.comentario }"></textarea>
          <p v-if="form.errors.comentario" class="mt-1 text-sm text-red-600">{{ form.errors.comentario }}</p>
        </div>

        <button
          type="submit"
          class="mt-6 flex w-full justify-center rounded-md px-4 py-2.5 text-sm font-medium text-white shadow-sm transition-opacity hover:opacity-90 disabled:cursor-not-allowed disabled:opacity-60"
          style="background-color: var(--publico-cor-primaria)"
          :disabled="form.processing || !form.nota">
          <span v-if="form.processing">Enviando...</span>
          <span v-else>Enviar avaliação</span>
        </button>
      </form>
    </div>

    <div v-else class="rounded-lg border border-gray-200 bg-white p-6 text-center shadow-sm sm:p-8">
      <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-green-100">
        <svg class="h-7 w-7 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
        </svg>
      </div>
      <h1 class="mt-4 text-lg font-semibold text-gray-900">{{ tituloDoPainel }}</h1>
      <p class="mt-2 text-sm text-gray-600">{{ mensagemDoPainel }}</p>
    </div>
  </PublicoLayout>
</template>

<script setup>
// Página pública de resposta da pesquisa de satisfação (Plano 16, Task 16.6).
// O estado do token (`estado`) decide se o formulário aparece: qualquer coisa
// diferente de "disponivel" mostra o painel de texto, com o título e a
// mensagem que já vêm prontos do backend (SatisfactionSurveyService, Task
// 16.5), sem tom de erro. Depois de responder, o backend redireciona para a
// mesma rota com o token já marcado como respondido - o estado muda para
// "respondida" e a mensagem específica da nota chega em `flash.success`.
import { computed } from 'vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import PublicoLayout from '@/Layouts/PublicoLayout.vue';

const props = defineProps({
  estado: { type: String, required: true },
  titulo: { type: String, required: true },
  mensagem: { type: String, required: true },
  empresa: { type: Object, default: null },
  servico_avaliado: { type: String, default: null },
  token: { type: String, default: null },
  nota_minima: { type: Number, required: true },
  nota_maxima: { type: Number, required: true },
  nota_maxima_de_contato: { type: Number, required: true },
  limite_do_comentario: { type: Number, required: true },
});

const page = usePage();
const mensagemDeSucesso = computed(() => page.props.flash?.success ?? null);

// O formulário só aparece quando o token está disponível e a resposta desta
// visita ainda não acabou de ser enviada (evita o caso raro de a página
// reaparecer com "disponivel" e um flash de sucesso ao mesmo tempo).
const mostrarFormulario = computed(() => props.estado === 'disponivel' && !mensagemDeSucesso.value);

const tituloDoPainel = computed(() => (mensagemDeSucesso.value ? 'Avaliação enviada' : props.titulo));
const mensagemDoPainel = computed(() => mensagemDeSucesso.value ?? props.mensagem);

const tituloDaPagina = computed(() => (props.empresa?.nome ? `Pesquisa de satisfação - ${props.empresa.nome}` : 'Pesquisa de satisfação'));

const form = useForm({
  nota: null,
  comentario: '',
});

function enviar() {
  if (!form.nota) {
    return;
  }

  form.post(route('publico.pesquisa.store', { token: props.token }), {
    preserveScroll: true,
  });
}
</script>
