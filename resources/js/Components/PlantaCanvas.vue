<template>
  <div
    ref="viewport"
    class="relative h-full w-full touch-none select-none overflow-hidden rounded-lg border border-gray-200 bg-gray-100"
    @wheel.prevent="aoRodarRoda"
    @pointerdown="aoPointerDownViewport"
    @pointermove="aoPointerMoveViewport"
    @pointerup="aoPointerUpViewport"
    @pointercancel="aoPointerUpViewport"
  >
    <div v-if="!larguraValida" class="flex h-full w-full items-center justify-center p-6 text-center text-sm text-gray-500">
      Planta sem dimensões carregadas.
    </div>

    <template v-else>
      <div ref="palco" class="absolute left-0 top-0 origin-top-left" :style="estiloPalco">
        <img
          :src="imagemUrl"
          :draggable="false"
          alt="Planta do endereço"
          class="pointer-events-none block h-full w-full select-none object-fill"
          @error="imagemComErro = true"
        />
        <div v-if="imagemComErro" class="absolute inset-0 flex items-center justify-center bg-gray-100 p-4 text-center text-sm text-gray-500">
          Não foi possível carregar a imagem da planta.
        </div>
      </div>

      <!-- Pontos: ficam fora do `palco` transformado, para que o alvo de toque
           (ver `TAMANHO_TOQUE_PX`) tenha sempre o mesmo tamanho na tela,
           independente do zoom - só a posição (`left`/`top` em %) segue o
           `palco`. -->
      <button
        v-for="ponto in pontosRenderizados"
        :key="ponto.deviceId"
        type="button"
        class="absolute flex -translate-x-1/2 -translate-y-1/2 items-center justify-center touch-none rounded-full outline-none"
        :style="estiloMarcador(ponto)"
        :aria-label="`Dispositivo ${ponto.rotulo}, código ${ponto.codigo}`"
        @pointerdown="aoPointerDownMarcador($event, ponto)"
        @pointermove="aoPointerMoveMarcador"
        @pointerup="aoPointerUpMarcador($event, ponto)"
        @pointercancel="aoCancelarArrastoMarcador"
      >
        <span
          class="rounded-full border-2 border-white bg-green-600 shadow"
          :class="{ 'ring-2 ring-green-300': arrastandoPonto?.deviceId === ponto.deviceId }"
          :style="estiloPontoVisual"
        />
      </button>

      <!-- Popover do dispositivo clicado -->
      <div
        v-if="popover"
        class="absolute z-30 w-56 -translate-x-1/2 -translate-y-full rounded-lg border border-gray-200 bg-white p-3 shadow-lg"
        :style="{ left: `${popover.left}px`, top: `${popover.top - 12}px` }"
      >
        <div class="flex items-start justify-between gap-2">
          <div class="min-w-0">
            <p class="truncate text-sm font-semibold text-gray-900">{{ popover.ponto.rotulo }}</p>
            <p class="text-xs text-gray-500">Código {{ popover.ponto.codigo }}</p>
            <p v-if="popover.ponto.numero" class="text-xs text-gray-500">Número {{ popover.ponto.numero }}</p>
          </div>
          <button type="button" class="shrink-0 text-gray-400 hover:text-gray-600" aria-label="Fechar" @click="fecharPopover">
            <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
            </svg>
          </button>
        </div>
        <button type="button" class="btn-secondary-sm mt-3 w-full text-red-700" @click="confirmandoRemocao = true">
          Remover posição
        </button>
      </div>

      <!-- Controles de zoom -->
      <div class="absolute bottom-3 right-3 z-20 flex flex-col gap-1 rounded-lg border border-gray-200 bg-white p-1 shadow-sm">
        <button type="button" class="btn-secondary-sm" aria-label="Aumentar zoom" @click="zoomComBotao(1.3)">+</button>
        <button type="button" class="btn-secondary-sm" aria-label="Diminuir zoom" @click="zoomComBotao(1 / 1.3)">-</button>
        <button type="button" class="btn-secondary-sm text-xs" aria-label="Ajustar planta à tela" @click="ajustarEnquadramento(true)">Ajustar</button>
      </div>
    </template>

    <ConfirmDeleteModal
      :show="confirmandoRemocao"
      title="Remover posição"
      subtitle="O dispositivo volta para a lista de não posicionados. A posição pode ser arrastada de volta a qualquer momento."
      message="Remover a posição de"
      :item-name="popover?.ponto?.rotulo || ''"
      confirm-text="Remover posição"
      processing-text="Removendo..."
      @confirm="confirmarRemocao"
      @cancel="confirmandoRemocao = false"
    />
  </div>
</template>

<script setup>
/**
 * Planta como imagem de fundo, com zoom (roda do mouse / pinça) e
 * deslocamento (arrastar o fundo), e os pontos dos dispositivos posicionados
 * sobre ela (Plano 21, Task 21.7).
 *
 * Conversão pixel -> fração (independente do zoom)
 * -----------------------------------------------------------------------
 * A posição de cada dispositivo é sempre fração (0 a 1) da largura/altura
 * NATURAL da planta (`DevicePosition.x/y`, ver Task 21.4). Em vez de fazer
 * essa conta manualmente a partir de `zoom`/deslocamento, a implementação usa
 * `getBoundingClientRect()` do próprio `palco` (o container que recebe o
 * `transform: translate(...) scale(...)`): o navegador já devolve o
 * retângulo REAL renderizado, depois do zoom e do deslocamento aplicados, e
 * um dispositivo largado num ponto de tela vira fração só dividindo a
 * distância até a borda do retângulo pela largura/altura dele
 * (`fracaoDoPonto()`). É por isso que o resultado é o mesmo em qualquer nível
 * de zoom: o retângulo muda de tamanho junto.
 *
 * Alvo de toque maior que o desenho
 * -----------------------------------------------------------------------
 * O ponto (o círculo verde) é filho do `viewport`, não do `palco` - só a
 * posição dele (`left`/`top` em %) segue o zoom/deslocamento do `palco`,
 * porque a % é calculada sobre o tamanho do `palco`. O TAMANHO do botão em si
 * (a área clicável) é sempre `TAMANHO_TOQUE_PX` em pixel de TELA, igual em
 * qualquer zoom - bem maior que o círculo visual (`TAMANHO_VISUAL_PX`),
 * como a Task 21.7 pede para funcionar em tablet.
 */
import { computed, nextTick, onMounted, reactive, ref, watch } from 'vue';
import { useResizeObserver } from '@vueuse/core';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';

const props = defineProps({
  imagemUrl: { type: String, required: true },
  larguraNatural: { type: Number, required: true },
  alturaNatural: { type: Number, required: true },
  // Cada item: { deviceId, x, y, rotulo, codigo, numero }
  pontos: { type: Array, default: () => [] },
});

const emit = defineEmits(['mover-ponto', 'remover-posicao']);

// Tamanho do círculo desenhado e da área de toque, em pixel de TELA (não
// pixel do `palco`) - por isso os dois são divididos por `zoom` no estilo
// inline: o `palco` já escala tudo dentro dele, e dividir por `zoom`
// cancela essa escala para o tamanho ficar constante na tela.
const TAMANHO_VISUAL_PX = 16;
const TAMANHO_TOQUE_PX = 44;
const ZOOM_MINIMO = 1;
const ZOOM_MAXIMO = 6;
const LIMITE_CLIQUE_PX = 6;
// Margem da fração enviada ao backend: `UpdateDevicePositionsRequest` recusa
// exatamente 0 ou 1 (`gt:0`, `lt:1`), então o valor nunca chega exato na
// borda.
const EPSILON_FRACAO = 0.001;

const viewport = ref(null);
const palco = ref(null);
const imagemComErro = ref(false);

const larguraValida = computed(() => Number(props.larguraNatural) > 0 && Number(props.alturaNatural) > 0);

const zoom = ref(1);
const pan = reactive({ x: 0, y: 0 });
const baseScale = ref(1);
const interagiu = ref(false);

const larguraPalco = computed(() => props.larguraNatural * baseScale.value);
const alturaPalco = computed(() => props.alturaNatural * baseScale.value);

const estiloPalco = computed(() => ({
  width: `${larguraPalco.value}px`,
  height: `${alturaPalco.value}px`,
  transform: `translate(${pan.x}px, ${pan.y}px) scale(${zoom.value})`,
}));

const estiloPontoVisual = computed(() => {
  const tamanho = TAMANHO_VISUAL_PX / zoom.value;
  return { width: `${tamanho}px`, height: `${tamanho}px` };
});

function clamp(valor, minimo, maximo) {
  return Math.min(maximo, Math.max(minimo, valor));
}

/** Ajusta `baseScale` para a planta caber inteira no viewport disponível. */
function ajustarEnquadramento(forcarReset = false) {
  const elemento = viewport.value;
  if (!elemento || !larguraValida.value) return;

  const larguraDisponivel = elemento.clientWidth;
  const alturaDisponivel = elemento.clientHeight;
  if (!larguraDisponivel || !alturaDisponivel) return;

  baseScale.value = Math.min(larguraDisponivel / props.larguraNatural, alturaDisponivel / props.alturaNatural);

  if (forcarReset || !interagiu.value) {
    zoom.value = 1;
    pan.x = 0;
    pan.y = 0;
    interagiu.value = false;
  }
}

onMounted(() => nextTick(() => ajustarEnquadramento()));
useResizeObserver(viewport, () => ajustarEnquadramento());
watch(() => [props.larguraNatural, props.alturaNatural, props.imagemUrl], () => {
  imagemComErro.value = false;
  nextTick(() => ajustarEnquadramento(true));
});

/**
 * Fração (0 a 1, já com a margem de `EPSILON_FRACAO`) de um ponto de tela
 * dentro da imagem, ou `null` quando o ponto cai fora dela (arrasto/solto
 * fora dos limites da planta).
 */
function fracaoDoPonto(clientX, clientY) {
  const rect = palco.value?.getBoundingClientRect();
  if (!rect || !rect.width || !rect.height) return null;

  const x = (clientX - rect.left) / rect.width;
  const y = (clientY - rect.top) / rect.height;

  if (x < 0 || x > 1 || y < 0 || y > 1) return null;

  return { x: clamp(x, EPSILON_FRACAO, 1 - EPSILON_FRACAO), y: clamp(y, EPSILON_FRACAO, 1 - EPSILON_FRACAO) };
}

/** Usado por quem arrasta a partir de fora (lista de não posicionados). */
function pontoDeTelaParaFracao(clientX, clientY) {
  return fracaoDoPonto(clientX, clientY);
}

defineExpose({ pontoDeTelaParaFracao });

// --- Zoom (roda do mouse) e pan/pinça (arrastar o fundo) -----------------

const ponteirosAtivos = reactive(new Map());
const panReferencia = reactive({ x: 0, y: 0 });
const panInicioDoGesto = reactive({ x: 0, y: 0 });
let distanciaAnteriorPinca = null;

/** Aplica um novo zoom mantendo o ponto de tela `(pivoX, pivoY)` fixo. */
function aplicarZoomComPivo(novoZoomBruto, pivoX, pivoY) {
  const rect = viewport.value?.getBoundingClientRect();
  if (!rect) return;

  const novoZoom = clamp(novoZoomBruto, ZOOM_MINIMO, ZOOM_MAXIMO);
  // Coordenada do pivô dentro do `palco`, sem a escala atual - é o que
  // permanece constante enquanto o zoom muda.
  const localX = (pivoX - rect.left - pan.x) / zoom.value;
  const localY = (pivoY - rect.top - pan.y) / zoom.value;

  pan.x = pivoX - rect.left - localX * novoZoom;
  pan.y = pivoY - rect.top - localY * novoZoom;
  zoom.value = novoZoom;
  interagiu.value = true;
}

function aoRodarRoda(evento) {
  if (!larguraValida.value) return;
  fecharPopover();
  const fator = evento.deltaY < 0 ? 1.15 : 1 / 1.15;
  aplicarZoomComPivo(zoom.value * fator, evento.clientX, evento.clientY);
}

function zoomComBotao(fator) {
  const rect = viewport.value?.getBoundingClientRect();
  if (!rect) return;
  aplicarZoomComPivo(zoom.value * fator, rect.left + rect.width / 2, rect.top + rect.height / 2);
}

function aoPointerDownViewport(evento) {
  // Um pointerdown que começou num marcador já chega aqui com
  // `stopPropagation()` (ver `aoPointerDownMarcador`), então só sobra o
  // clique no fundo/imagem.
  if (!larguraValida.value) return;

  viewport.value?.setPointerCapture(evento.pointerId);
  ponteirosAtivos.set(evento.pointerId, { x: evento.clientX, y: evento.clientY });
  fecharPopover();

  if (ponteirosAtivos.size === 1) {
    panReferencia.x = evento.clientX;
    panReferencia.y = evento.clientY;
    panInicioDoGesto.x = pan.x;
    panInicioDoGesto.y = pan.y;
    distanciaAnteriorPinca = null;
  } else if (ponteirosAtivos.size === 2) {
    const pontos = [...ponteirosAtivos.values()];
    distanciaAnteriorPinca = Math.hypot(pontos[0].x - pontos[1].x, pontos[0].y - pontos[1].y);
  }
}

function aoPointerMoveViewport(evento) {
  if (!ponteirosAtivos.has(evento.pointerId)) return;
  ponteirosAtivos.set(evento.pointerId, { x: evento.clientX, y: evento.clientY });

  const pontos = [...ponteirosAtivos.values()];

  if (pontos.length === 1) {
    pan.x = panInicioDoGesto.x + (evento.clientX - panReferencia.x);
    pan.y = panInicioDoGesto.y + (evento.clientY - panReferencia.y);
    interagiu.value = true;
    return;
  }

  if (pontos.length === 2) {
    const distancia = Math.hypot(pontos[0].x - pontos[1].x, pontos[0].y - pontos[1].y);
    const centro = { x: (pontos[0].x + pontos[1].x) / 2, y: (pontos[0].y + pontos[1].y) / 2 };

    if (distanciaAnteriorPinca) {
      aplicarZoomComPivo(zoom.value * (distancia / distanciaAnteriorPinca), centro.x, centro.y);
    }

    distanciaAnteriorPinca = distancia;
  }
}

function aoPointerUpViewport(evento) {
  ponteirosAtivos.delete(evento.pointerId);
  distanciaAnteriorPinca = null;

  // Se ainda sobrou um dedo (estava em pinça), o pan retoma a partir da
  // posição atual dele, sem pular.
  const restante = [...ponteirosAtivos.values()][0];
  if (restante) {
    panReferencia.x = restante.x;
    panReferencia.y = restante.y;
    panInicioDoGesto.x = pan.x;
    panInicioDoGesto.y = pan.y;
  }

  try {
    viewport.value?.releasePointerCapture(evento.pointerId);
  } catch {
    // já liberado pelo navegador (ex.: pointercancel).
  }
}

// --- Arrastar um ponto existente -----------------------------------------

const arrastandoPonto = ref(null); // { deviceId, x, y, moveu }
let pointerIdDoArrasto = null;
let cliqueInicio = null;

const pontosRenderizados = computed(() =>
  props.pontos.map((ponto) =>
    arrastandoPonto.value?.deviceId === ponto.deviceId
      ? { ...ponto, x: arrastandoPonto.value.x, y: arrastandoPonto.value.y }
      : ponto
  )
);

function estiloMarcador(ponto) {
  const tamanho = TAMANHO_TOQUE_PX / zoom.value;
  return {
    left: `${ponto.x * 100}%`,
    top: `${ponto.y * 100}%`,
    width: `${tamanho}px`,
    height: `${tamanho}px`,
  };
}

function aoPointerDownMarcador(evento, ponto) {
  evento.stopPropagation();
  fecharPopover();

  pointerIdDoArrasto = evento.pointerId;
  cliqueInicio = { x: evento.clientX, y: evento.clientY };
  arrastandoPonto.value = { deviceId: ponto.deviceId, x: ponto.x, y: ponto.y, moveu: false };
  evento.currentTarget.setPointerCapture(evento.pointerId);
}

function aoPointerMoveMarcador(evento) {
  if (!arrastandoPonto.value || evento.pointerId !== pointerIdDoArrasto) return;

  const distancia = Math.hypot(evento.clientX - cliqueInicio.x, evento.clientY - cliqueInicio.y);
  if (distancia > LIMITE_CLIQUE_PX) {
    arrastandoPonto.value.moveu = true;
  }

  const fracao = fracaoDoPonto(evento.clientX, evento.clientY);
  if (fracao) {
    arrastandoPonto.value.x = fracao.x;
    arrastandoPonto.value.y = fracao.y;
  }
}

function aoPointerUpMarcador(evento, ponto) {
  if (!arrastandoPonto.value || evento.pointerId !== pointerIdDoArrasto) return;

  try {
    evento.currentTarget.releasePointerCapture(evento.pointerId);
  } catch {
    // segue o baile
  }

  const resultado = arrastandoPonto.value;
  arrastandoPonto.value = null;
  pointerIdDoArrasto = null;

  if (!resultado.moveu) {
    abrirPopover(ponto, evento.currentTarget);
    return;
  }

  emit('mover-ponto', resultado.deviceId, resultado.x, resultado.y);
}

function aoCancelarArrastoMarcador() {
  arrastandoPonto.value = null;
  pointerIdDoArrasto = null;
}

// --- Popover e remoção -----------------------------------------------------

const popover = ref(null); // { ponto, left, top } em px, relativo ao viewport
const confirmandoRemocao = ref(false);

function abrirPopover(ponto, elementoMarcador) {
  const rectMarcador = elementoMarcador.getBoundingClientRect();
  const rectViewport = viewport.value.getBoundingClientRect();

  popover.value = {
    ponto,
    left: rectMarcador.left - rectViewport.left + rectMarcador.width / 2,
    top: rectMarcador.top - rectViewport.top,
  };
}

function fecharPopover() {
  popover.value = null;
  confirmandoRemocao.value = false;
}

function confirmarRemocao() {
  if (!popover.value) return;
  emit('remover-posicao', popover.value.ponto.deviceId);
  confirmandoRemocao.value = false;
  popover.value = null;
}
</script>
