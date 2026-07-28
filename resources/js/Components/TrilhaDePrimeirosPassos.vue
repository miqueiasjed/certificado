<template>
  <Card v-if="onboarding" padding="none" class="overflow-hidden">
    <div class="px-6 py-4 flex items-center justify-between gap-4">
      <div class="min-w-0">
        <h3 class="text-lg font-medium text-gray-900">Primeiros passos</h3>
        <p class="text-sm text-gray-500">{{ concluidos }} de {{ total }} concluídos</p>
      </div>

      <button
        type="button"
        class="shrink-0 rounded-full p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-600 focus:outline-none focus:ring-2 focus:ring-green-500"
        @click="alternarRecolhido">
        <span class="sr-only">{{ recolhido ? 'Expandir primeiros passos' : 'Recolher primeiros passos' }}</span>
        <svg
          class="h-5 w-5 transition-transform duration-200"
          :class="{ 'rotate-180': !recolhido }"
          fill="none"
          stroke="currentColor"
          viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
        </svg>
      </button>
    </div>

    <div class="px-6 pb-4">
      <div class="flex items-center gap-3">
        <div class="h-2 flex-1 rounded-full bg-gray-100">
          <div
            class="h-2 rounded-full bg-green-600 transition-all duration-300"
            :style="{ width: onboarding.percentual + '%' }">
          </div>
        </div>
        <span class="shrink-0 text-sm font-medium text-gray-700">{{ onboarding.percentual }}%</span>
      </div>
    </div>

    <div v-if="!recolhido" class="border-t border-gray-200 divide-y divide-gray-100">
      <div
        v-for="passo in onboarding.passos"
        :key="passo.chave"
        class="px-6 py-4 flex items-start gap-3">
        <!-- Concluído -->
        <template v-if="passo.estado === 'concluido'">
          <div class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-green-100 text-green-600">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
            </svg>
          </div>
          <p class="text-sm font-medium text-gray-400 line-through">{{ passo.titulo }}</p>
        </template>

        <!-- Ignorado -->
        <template v-else-if="passo.estado === 'ignorado'">
          <div class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-gray-100 text-gray-400">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"></path>
            </svg>
          </div>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-gray-400">{{ passo.titulo }}</p>
          </div>
          <button
            type="button"
            class="shrink-0 text-xs font-medium text-green-700 hover:text-green-800 hover:underline disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="emAndamento.has(passo.chave)"
            @click="trazerDeVolta(passo)">
            Trazer de volta
          </button>
        </template>

        <!-- Pendente -->
        <template v-else>
          <div class="mt-0.5 flex h-6 w-6 shrink-0 items-center justify-center rounded-full border-2 border-gray-300"></div>
          <div class="min-w-0 flex-1">
            <p class="text-sm font-medium text-gray-900">{{ passo.titulo }}</p>
            <p class="text-sm text-gray-500">{{ passo.descricao }}</p>
            <div class="mt-2 flex flex-wrap items-center gap-3">
              <Link :href="route(passo.rota)" class="btn-secondary-sm">
                Ir para lá
              </Link>
              <button
                type="button"
                class="text-xs font-medium text-gray-500 hover:text-gray-700 hover:underline disabled:opacity-50 disabled:cursor-not-allowed"
                :disabled="emAndamento.has(passo.chave)"
                @click="dispensar(passo)">
                Dispensar
              </button>
            </div>
          </div>
        </template>
      </div>
    </div>
  </Card>
</template>

<script setup>
/**
 * Trilha de primeiros passos do tenant novo (Plano 8, Task 8.9), lida direto
 * de `page.props.onboarding` (compartilhado por `HandleInertiaRequests`, no
 * mesmo padrão de `AvisoDeLimite` e `FaixaDeSuporte`). Sem prop declarada:
 * o bloco entra no topo do `Dashboard.vue` e não renderiza nada quando a
 * prop vem nula, o que acontece assim que a trilha fica concluída.
 *
 * "Dispensar" e "Trazer de volta" chamam o backend (`OnboardingController`)
 * e mudam o estado real do passo; "Recolher" é só visual, guardado no
 * `localStorage` do navegador, e não muda nada no percentual.
 */
import { computed, ref } from 'vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';

const CHAVE_LOCALSTORAGE = 'trilhaPrimeirosPassos.recolhida';

const $page = usePage();

const onboarding = computed(() => $page.props.onboarding);

const concluidos = computed(
  () => (onboarding.value?.passos || []).filter((passo) => passo.estado === 'concluido').length
);
const total = computed(() => (onboarding.value?.passos || []).length);

function lerRecolhido() {
  try {
    return localStorage.getItem(CHAVE_LOCALSTORAGE) === '1';
  } catch {
    // localStorage indisponível (modo privado, por exemplo): a trilha começa
    // sempre expandida, sem travar a exibição.
    return false;
  }
}

const recolhido = ref(lerRecolhido());

function alternarRecolhido() {
  recolhido.value = !recolhido.value;

  try {
    localStorage.setItem(CHAVE_LOCALSTORAGE, recolhido.value ? '1' : '0');
  } catch {
    // Sem persistência: o recolher funciona nesta navegação e pode voltar a
    // pedir na próxima, o que é aceitável frente a travar a tela.
  }
}

const emAndamento = ref(new Set());

function marcarEmAndamento(chave, ativo) {
  const novo = new Set(emAndamento.value);

  if (ativo) {
    novo.add(chave);
  } else {
    novo.delete(chave);
  }

  emAndamento.value = novo;
}

function dispensar(passo) {
  if (emAndamento.value.has(passo.chave)) {
    return;
  }

  marcarEmAndamento(passo.chave, true);

  router.post(
    route('onboarding.ignorar', passo.chave),
    {},
    {
      preserveScroll: true,
      onFinish: () => marcarEmAndamento(passo.chave, false),
    }
  );
}

function trazerDeVolta(passo) {
  if (emAndamento.value.has(passo.chave)) {
    return;
  }

  marcarEmAndamento(passo.chave, true);

  router.post(
    route('onboarding.retomar', passo.chave),
    {},
    {
      preserveScroll: true,
      onFinish: () => marcarEmAndamento(passo.chave, false),
    }
  );
}
</script>
