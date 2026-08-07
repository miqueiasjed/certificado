<template>
  <div class="flex h-full flex-col">
    <div class="border-b border-gray-200 px-4 py-3">
      <h3 class="text-sm font-semibold text-gray-900">Dispositivos não posicionados</h3>
      <p class="mt-1 text-xs text-gray-500">Arraste um item da lista para o lugar dele na planta.</p>

      <p class="mt-3 text-sm font-medium text-gray-700">{{ posicionados }} de {{ totalDispositivos }} posicionados</p>
      <div class="mt-1.5 h-1.5 w-full overflow-hidden rounded-full bg-gray-100">
        <div class="h-full rounded-full bg-green-600 transition-all" :style="{ width: `${progresso}%` }" />
      </div>
    </div>

    <div class="flex-1 overflow-y-auto p-2">
      <p v-if="dispositivos.length === 0" class="px-2 py-6 text-center text-sm text-gray-500">
        Todos os dispositivos ativos já estão posicionados nesta planta.
      </p>

      <ul v-else class="space-y-1">
        <li
          v-for="dispositivo in dispositivos"
          :key="dispositivo.id"
          class="touch-none select-none rounded-md border border-gray-200 bg-white px-3 py-2 text-sm shadow-sm active:cursor-grabbing"
          :class="arrastoAtivo?.dispositivo?.id === dispositivo.id ? 'opacity-40' : 'cursor-grab'"
          @pointerdown="aoPointerDownItem($event, dispositivo)"
        >
          <p class="truncate font-medium text-gray-900">{{ dispositivo.label }}</p>
          <p class="text-xs text-gray-500">Nº {{ dispositivo.number }} · {{ dispositivo.codigo_publico }}</p>
        </li>
      </ul>
    </div>
  </div>

  <!-- "Fantasma" do item sendo arrastado: `Teleport` para `body` para não
       ficar preso ao `overflow-y-auto` da lista e sempre ficar por cima da
       planta, inclusive quando o dedo/cursor já passou do painel lateral
       para o canvas. -->
  <Teleport to="body">
    <div
      v-if="arrastoAtivo"
      class="pointer-events-none fixed z-50 -translate-x-1/2 -translate-y-1/2 rounded-md border border-green-400 bg-white px-3 py-2 text-sm shadow-lg"
      :style="{ left: `${arrastoAtivo.x}px`, top: `${arrastoAtivo.y}px` }"
    >
      <p class="whitespace-nowrap font-medium text-gray-900">{{ arrastoAtivo.dispositivo.label }}</p>
    </div>
  </Teleport>
</template>

<script setup>
/**
 * Painel lateral com os dispositivos do endereço sem posição na planta ativa
 * (Plano 21, Task 21.7). Cada item é arrastável para dentro de
 * `PlantaCanvas.vue`.
 *
 * Arrasto por Pointer Events, não HTML5 `draggable`
 * -----------------------------------------------------------------------
 * O projeto já tem um mecanismo de arrastar-e-soltar nativo do HTML5
 * (`useArrastarVisita.js`, calendário da agenda), mas o próprio comentário
 * daquele composable documenta a limitação: "isto NÃO funciona bem em tela
 * de toque (o HTML5 Drag and Drop é feito para mouse; em celular/tablet o
 * `dragstart` de um elemento `draggable` raramente dispara de forma
 * confiável)". Como a Task 21.7 exige explicitamente funcionar em tablet
 * (o aparelho usado em campo para isto), este componente usa Pointer Events
 * (`pointerdown`/`pointermove`/`pointerup`) em vez de `draggable`: é a mesma
 * API para mouse, caneta e toque, e não depende do gesto de Drag and Drop
 * que o HTML5 não garante em touch.
 */
import { computed, ref } from 'vue';

const props = defineProps({
  // Dispositivos ativos do endereço sem posição na planta ativa - vem de
  // `FloorPlanService::dispositivosNaoPosicionados()` (Task 21.4): cada item
  // tem `id`, `label`, `number`, `codigo_publico`.
  dispositivos: {
    type: Array,
    default: () => [],
  },
  // Total de dispositivos ATIVOS do endereço (posicionados + não
  // posicionados), para o contador "12 de 40 posicionados".
  totalDispositivos: {
    type: Number,
    default: 0,
  },
});

// `soltar`: { dispositivo, clientX, clientY }. Quem escuta (Editor.vue)
// decide se o ponto de soltura caiu dentro da planta - este componente não
// conhece o canvas, só onde o ponteiro foi solto na tela.
const emit = defineEmits(['soltar']);

const posicionados = computed(() => Math.max(props.totalDispositivos - props.dispositivos.length, 0));
const progresso = computed(() => (props.totalDispositivos > 0 ? (posicionados.value / props.totalDispositivos) * 100 : 0));

const arrastoAtivo = ref(null); // { dispositivo, x, y }
let pointerIdAtivo = null;

function aoPointerDownItem(evento, dispositivo) {
  pointerIdAtivo = evento.pointerId;
  arrastoAtivo.value = { dispositivo, x: evento.clientX, y: evento.clientY };

  // Ouvintes no `window`, não no item: o dedo/cursor sai da lista e entra no
  // canvas durante o arrasto, e o item de origem não teria mais o ponteiro
  // em cima dele para continuar recebendo `pointermove`.
  window.addEventListener('pointermove', aoPointerMoveGlobal);
  window.addEventListener('pointerup', aoPointerUpGlobal);
  window.addEventListener('pointercancel', aoCancelarArrasto);
}

function aoPointerMoveGlobal(evento) {
  if (!arrastoAtivo.value || evento.pointerId !== pointerIdAtivo) return;
  arrastoAtivo.value.x = evento.clientX;
  arrastoAtivo.value.y = evento.clientY;
}

function aoPointerUpGlobal(evento) {
  if (!arrastoAtivo.value || evento.pointerId !== pointerIdAtivo) return;

  emit('soltar', {
    dispositivo: arrastoAtivo.value.dispositivo,
    clientX: evento.clientX,
    clientY: evento.clientY,
  });

  limparOuvintes();
}

function aoCancelarArrasto() {
  limparOuvintes();
}

function limparOuvintes() {
  arrastoAtivo.value = null;
  pointerIdAtivo = null;
  window.removeEventListener('pointermove', aoPointerMoveGlobal);
  window.removeEventListener('pointerup', aoPointerUpGlobal);
  window.removeEventListener('pointercancel', aoCancelarArrasto);
}
</script>
