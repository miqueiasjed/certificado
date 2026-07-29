<!--
  Quadro de assinatura por toque, do aplicativo do técnico (Plano 13, Task
  13.9): canvas de largura total da tela, traço em preto, botão de limpar.

  Captura tanto toque (`touchstart`/`touchmove`/`touchend`) quanto mouse
  (`mousedown`/`mousemove`/`mouseup`/`mouseleave`), a segunda combinação só
  para permitir testar em desktop - em campo, o técnico entrega o aparelho
  para o cliente assinar com o dedo.

  Fundo TRANSPARENTE de propósito: nada de `fillRect`/`fillStyle` antes de
  desenhar o traço. O retângulo branco com borda que aparece na tela é CSS do
  contêiner, nunca pixel do canvas - por isso não vai para o PNG exportado.

  Exportação (`exportarPng()`, chamado pela tela que usa este componente):
  redesenha o traço num canvas OFFSCREEN de no máximo 600px de largura
  (mantendo a proporção original, nunca ampliando), sem preencher fundo, e
  gera um PNG (`canvas.toBlob(..., 'image/png')`). PNG é sem perda por
  natureza - não existe "qualidade" para ajustar como em JPEG
  (`sync/foto.js`, Task 12.11). Por isso, se o PNG a 600px ainda passar de
  300 KB (o limite validado pelo backend, Task 13.4), a única forma de
  reduzir o arquivo sem comprimir o traço com perda é reduzir a RESOLUÇÃO do
  canvas de exportação, em passos, nunca a qualidade - é o que o laço abaixo
  faz, com um piso de largura para nunca produzir uma assinatura ilegível.
-->
<template>
  <div class="space-y-2">
    <div
      class="relative w-full overflow-hidden rounded-lg border border-gray-300 bg-white"
      style="touch-action: none"
    >
      <canvas
        ref="canvasRef"
        class="block w-full"
        @mousedown="aoMouseDown"
        @mousemove="aoMouseMove"
        @mouseup="finalizarTraco"
        @mouseleave="finalizarTraco"
        @touchstart.prevent="aoTouchStart"
        @touchmove.prevent="aoTouchMove"
        @touchend.prevent="finalizarTraco"
        @touchcancel.prevent="finalizarTraco"
      ></canvas>

      <p
        v-if="vazio"
        class="pointer-events-none absolute inset-0 flex items-center justify-center text-sm text-gray-400"
      >
        Assine aqui com o dedo
      </p>
    </div>

    <button type="button" class="btn-secondary-sm" :disabled="vazio" @click="limpar">Limpar assinatura</button>
  </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref } from 'vue';

// Altura fixa, em pixels CSS: confortável em modo retrato, sem depender da
// altura da viewport (que varia demais entre aparelhos e com o teclado
// aberto, já que os campos de nome/documento ficam acima deste quadro).
const ALTURA_CSS_PX = 220;

const LARGURA_MAXIMA_EXPORTACAO_PX = 600;
const LARGURA_MINIMA_EXPORTACAO_PX = 250;
const PASSO_DE_REDUCAO = 0.85;
const TAMANHO_MAXIMO_BYTES = 300 * 1024;

const emit = defineEmits(['mudou']);

const canvasRef = ref(null);
const vazio = ref(true);

let contexto = null;
let desenhando = false;
let ultimoPonto = null;

function dimensionarCanvas() {
  const canvas = canvasRef.value;

  if (!canvas) {
    return;
  }

  // Resolução interna do canvas em pixels de dispositivo, para o traço sair
  // nítido em telas de alta densidade; o CSS (`class="block w-full"` +
  // altura abaixo) continua controlando o tamanho exibido.
  const dpr = window.devicePixelRatio || 1;
  const larguraCss = canvas.clientWidth || canvas.parentElement?.clientWidth || 320;

  canvas.width = Math.round(larguraCss * dpr);
  canvas.height = Math.round(ALTURA_CSS_PX * dpr);
  canvas.style.height = `${ALTURA_CSS_PX}px`;

  contexto = canvas.getContext('2d');
  contexto.scale(dpr, dpr);
  contexto.lineCap = 'round';
  contexto.lineJoin = 'round';
  contexto.lineWidth = 2.5;
  contexto.strokeStyle = '#000000';
}

function pontoRelativoAoCanvas(clienteX, clienteY) {
  const retangulo = canvasRef.value.getBoundingClientRect();

  return { x: clienteX - retangulo.left, y: clienteY - retangulo.top };
}

/**
 * Desenha o segmento de `ultimoPonto` até `pontoAtual`. Chamado também no
 * início do traço, com `pontoAtual === ultimoPonto`: uma linha de
 * comprimento zero com `lineCap: round` desenha um ponto, o que faz um mero
 * toque (sem arrastar) já contar como assinatura em branco, e não como
 * quadro vazio.
 */
function desenharSegmento(pontoAtual) {
  if (!contexto || !ultimoPonto) {
    return;
  }

  contexto.beginPath();
  contexto.moveTo(ultimoPonto.x, ultimoPonto.y);
  contexto.lineTo(pontoAtual.x, pontoAtual.y);
  contexto.stroke();

  ultimoPonto = pontoAtual;

  if (vazio.value) {
    vazio.value = false;
    emit('mudou', false);
  }
}

function iniciarTraco(ponto) {
  desenhando = true;
  ultimoPonto = ponto;
  desenharSegmento(ponto);
}

function aoMouseDown(evento) {
  iniciarTraco(pontoRelativoAoCanvas(evento.clientX, evento.clientY));
}

function aoMouseMove(evento) {
  if (!desenhando) {
    return;
  }

  desenharSegmento(pontoRelativoAoCanvas(evento.clientX, evento.clientY));
}

function aoTouchStart(evento) {
  const toque = evento.touches[0];

  if (!toque) {
    return;
  }

  iniciarTraco(pontoRelativoAoCanvas(toque.clientX, toque.clientY));
}

function aoTouchMove(evento) {
  if (!desenhando) {
    return;
  }

  const toque = evento.touches[0];

  if (!toque) {
    return;
  }

  desenharSegmento(pontoRelativoAoCanvas(toque.clientX, toque.clientY));
}

function finalizarTraco() {
  desenhando = false;
  ultimoPonto = null;
}

function limpar() {
  const canvas = canvasRef.value;

  if (!canvas || !contexto) {
    return;
  }

  contexto.clearRect(0, 0, canvas.width, canvas.height);
  vazio.value = true;
  emit('mudou', true);
}

/**
 * Gera o PNG final: canvas offscreen redimensionado para `larguraAlvo`
 * (mantendo a proporção do canvas de desenho), sem preencher fundo (fica
 * transparente).
 */
function gerarBlobRedimensionado(larguraAlvo) {
  const canvasOriginal = canvasRef.value;
  const proporcao = canvasOriginal.height / canvasOriginal.width;
  const alturaAlvo = Math.max(1, Math.round(larguraAlvo * proporcao));

  const canvasExportacao = document.createElement('canvas');
  canvasExportacao.width = larguraAlvo;
  canvasExportacao.height = alturaAlvo;

  const contextoExportacao = canvasExportacao.getContext('2d');
  contextoExportacao.drawImage(canvasOriginal, 0, 0, larguraAlvo, alturaAlvo);

  return new Promise((resolve) => {
    canvasExportacao.toBlob((blob) => resolve(blob), 'image/png');
  });
}

function blobParaDataUrl(blob) {
  return new Promise((resolve, reject) => {
    if (!blob) {
      reject(new Error('Não foi possível gerar a imagem da assinatura.'));
      return;
    }

    const leitor = new FileReader();
    leitor.onloadend = () => resolve(leitor.result);
    leitor.onerror = () => reject(new Error('Não foi possível ler a imagem da assinatura.'));
    leitor.readAsDataURL(blob);
  });
}

/**
 * PNG final da assinatura, em base64 (`data:image/png;base64,...`), pronto
 * para o payload da operação `assinatura` (campo `imagem`, ver
 * `.claude/tasks/13/13.4.md`). `null` se o quadro estiver vazio: quem chama
 * decide o que fazer (o botão de confirmar já fica desabilitado nesse caso,
 * ver `Assinatura.vue`).
 */
async function exportarPng() {
  if (vazio.value || !canvasRef.value) {
    return null;
  }

  let larguraAlvo = Math.min(LARGURA_MAXIMA_EXPORTACAO_PX, canvasRef.value.width);
  let blob = await gerarBlobRedimensionado(larguraAlvo);

  while (blob && blob.size > TAMANHO_MAXIMO_BYTES && larguraAlvo > LARGURA_MINIMA_EXPORTACAO_PX) {
    larguraAlvo = Math.round(larguraAlvo * PASSO_DE_REDUCAO);
    blob = await gerarBlobRedimensionado(larguraAlvo);
  }

  return blobParaDataUrl(blob);
}

onMounted(dimensionarCanvas);

onBeforeUnmount(() => {
  contexto = null;
});

defineExpose({ exportarPng, limpar });
</script>
