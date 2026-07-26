<!--
  Leitor de QR code do dispositivo, pela câmera do celular ou por digitação.

  Por que não é o `Modal.vue` do projeto
  ---------------------------------------
  `Modal.vue` é uma caixa centralizada de largura fixa (`sm:max-w-lg`), pensada
  para formulário e confirmação. A câmera precisa da tela inteira para caber
  numa mira útil, e os botões de ação (Task: "utilizável com uma mão só")
  precisam ficar na metade de baixo da TELA, não de uma caixa de 400px no meio
  dela. Por isso este componente é uma sobreposição em tela cheia (`fixed
  inset-0`) só sua, e não uma variação de `Modal.vue`. A paleta, os
  arredondamentos e os botões (`btn-primary`/`btn-secondary`) seguem o mesmo
  design system.

  Ciclo de vida da câmera
  ------------------------
  Câmera ligada em segundo plano queima bateria no meio do dia de trabalho do
  técnico. `Html5Qrcode.stop()` (e depois `.clear()`) é chamado em TODO
  caminho de saída: botão fechar, tecla Esc, leitura concluída (`ok`, "abrir
  mesmo assim", "abrir o atual") e desmontagem do componente. Ver `pararCamera`
  e o watch de `show` logo abaixo.
-->
<template>
  <div
    v-if="show"
    class="fixed inset-0 z-50 flex flex-col bg-gray-900"
    role="dialog"
    aria-modal="true"
    aria-label="Leitor de QR code do dispositivo"
  >
    <!-- Cabeçalho -->
    <div class="flex items-center justify-between px-4 py-3 text-white">
      <h2 class="text-base font-medium">Ler dispositivo</h2>
      <button
        type="button"
        class="-m-2 p-2 text-gray-300 transition-colors hover:text-white"
        aria-label="Fechar leitor"
        @click="fechar"
      >
        <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </button>
    </div>

    <!-- Área da câmera -->
    <div class="relative min-h-0 flex-1 overflow-hidden bg-black">
      <div v-show="estadoPermissao === 'concedida'" :id="idViewport" class="h-full w-full"></div>

      <div v-if="estadoPermissao === 'verificando'" class="flex h-full items-center justify-center px-6">
        <p class="text-sm text-gray-300">Abrindo a câmera...</p>
      </div>

      <!-- Negada / indisponível: sem tom de erro, é fluxo normal, não falha. -->
      <div
        v-if="estadoPermissao === 'negada' || estadoPermissao === 'indisponivel'"
        class="flex h-full flex-col items-center justify-center gap-3 px-8 text-center"
      >
        <svg class="h-10 w-10 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="1.5"
            d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"
          />
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z" />
        </svg>
        <p class="text-sm text-gray-300">{{ explicacaoPermissao }}</p>
      </div>
    </div>

    <!-- Metade de baixo: mensagem de situação e digitação manual. Botões de ação sempre aqui. -->
    <div class="max-h-[60vh] overflow-y-auto rounded-t-2xl bg-white px-4 pb-6 pt-4 space-y-4">
      <div v-if="leitura.carregando.value" class="text-center text-sm text-gray-500">
        Consultando dispositivo...
      </div>

      <div v-if="mostrarBlocoSituacao" class="rounded-lg border p-3" :class="classesBloco">
        <p class="text-sm">{{ mensagemBloco }}</p>
        <div class="mt-3 flex flex-col gap-2">
          <button
            v-if="leitura.podeAbrirMesmoAssim.value"
            type="button"
            class="btn-primary w-full py-3 text-base"
            @click="confirmarAbrirMesmoAssim"
          >
            Abrir mesmo assim
          </button>
          <button
            v-else-if="leitura.podeAbrirOAtual.value"
            type="button"
            class="btn-primary w-full py-3 text-base"
            @click="confirmarAbrirOAtual"
          >
            Abrir o atual
          </button>
          <button type="button" class="btn-secondary w-full py-3 text-base" @click="tentarNovamente">
            Tentar novamente
          </button>
        </div>
      </div>

      <div>
        <button
          v-if="!campoManualVisivel"
          type="button"
          class="btn-secondary w-full py-3 text-base"
          @click="mostrarDigitacaoManual = true"
        >
          Digitar o código
        </button>

        <div v-else class="space-y-2">
          <label for="codigo-dispositivo-manual" class="block text-sm font-medium text-gray-700">
            Código do dispositivo
          </label>
          <input
            id="codigo-dispositivo-manual"
            ref="inputCodigoRef"
            :value="codigoDigitado"
            type="text"
            inputmode="text"
            autocomplete="off"
            autocorrect="off"
            autocapitalize="characters"
            spellcheck="false"
            maxlength="10"
            placeholder="Ex.: AB3D9F2K7Q"
            :disabled="leitura.carregando.value"
            class="w-full rounded-md border border-gray-300 px-3 py-3 text-center text-lg uppercase tracking-widest shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
            @keydown="aoTeclaNoCodigo"
            @input="aoDigitarCodigo"
            @paste="aoColarCodigo"
            @keyup.enter="confirmarDigitado"
          />
          <button
            type="button"
            class="btn-primary w-full py-3 text-base"
            :disabled="!podeConfirmarDigitado"
            @click="confirmarDigitado"
          >
            {{ leitura.carregando.value ? 'Consultando...' : 'Confirmar código' }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, watch, onBeforeUnmount, nextTick } from 'vue';
import { Html5Qrcode } from 'html5-qrcode';
import { useLeituraDeDispositivo } from '@/Composables/useLeituraDeDispositivo';
import {
  caractereDoCodigoEhValido,
  normalizarDigitacaoDeCodigo,
  extrairCodigoDoTextoLido,
  TAMANHO_CODIGO_DISPOSITIVO,
} from '@/utils/codigoDispositivo';

const props = defineProps({
  show: {
    type: Boolean,
    default: false,
  },
  // Ordem de serviço em execução, quando a leitura acontece dentro de uma OS.
  // Sem ela a leitura é avulsa e a situação `fora_da_os` nunca aparece (ver
  // `useLeituraDeDispositivo`).
  workOrderId: {
    type: [Number, String],
    default: null,
  },
});

const emit = defineEmits(['lido', 'fechado']);

// Sufixo aleatório: se mais de uma instância deste componente existir ao
// mesmo tempo na página, cada uma precisa do seu próprio elemento de câmera.
const idViewport = `leitor-qrcode-viewport-${Math.random().toString(36).slice(2)}`;

// 'verificando' | 'concedida' | 'negada' | 'indisponivel'
const estadoPermissao = ref('verificando');
const explicacaoPermissao = ref('');

const mostrarDigitacaoManual = ref(false);
const codigoDigitado = ref('');
const inputCodigoRef = ref(null);

const leitura = useLeituraDeDispositivo();

// Instância do leitor de câmera. Fora do reativo de propósito: é um objeto de
// infraestrutura com ciclo de vida próprio (start/stop), não estado de tela.
let html5Qrcode = null;

const campoManualVisivel = computed(
  () => mostrarDigitacaoManual.value
    || estadoPermissao.value === 'negada'
    || estadoPermissao.value === 'indisponivel',
);

const codigoCompleto = computed(() => codigoDigitado.value.length === TAMANHO_CODIGO_DISPOSITIVO);

// Só há algo a decidir quando a consulta terminou e não foi `ok` — essa
// fecha o leitor sozinha. Inclui a falha de rede, tratada com a mesma UI de
// "tentar novamente".
const mostrarBlocoSituacao = computed(
  () => !leitura.carregando.value
    && (!!leitura.erro.value || (!!leitura.situacao.value && leitura.situacao.value !== 'ok')),
);

const podeConfirmarDigitado = computed(
  () => codigoCompleto.value && !leitura.carregando.value && !mostrarBlocoSituacao.value,
);

const mensagemBloco = computed(() => leitura.erro.value || leitura.mensagemDaSituacao.value);

// `fora_da_os` pede decisão (amarelo); `substituido` é rotina, etiqueta velha
// (azul, sem tom de alerta); `nao_encontrado` é neutro; erro de rede usa
// vermelho porque ali sim é falha técnica, e não uma das quatro situações.
const classesBloco = computed(() => {
  if (leitura.erro.value) {
    return 'border-red-200 bg-red-50 text-red-800';
  }

  if (leitura.situacao.value === 'fora_da_os') {
    return 'border-yellow-200 bg-yellow-50 text-yellow-800';
  }

  if (leitura.situacao.value === 'substituido') {
    return 'border-blue-200 bg-blue-50 text-blue-800';
  }

  return 'border-gray-200 bg-gray-50 text-gray-700';
});

watch(
  () => props.show,
  (visivel) => {
    if (visivel) {
      codigoDigitado.value = '';
      mostrarDigitacaoManual.value = false;
      leitura.limpar();
      window.addEventListener('keydown', aoTeclado);
      iniciarCamera();
    } else {
      window.removeEventListener('keydown', aoTeclado);
      pararCamera();
    }
  },
  // flush: 'post' garante que o elemento da câmera já está no DOM (o `v-if`
  // da raiz só o renderiza quando `show` vira true) antes de tentar iniciar.
  { immediate: true, flush: 'post' },
);

// Rede de segurança final: se o componente for desmontado com a câmera
// ligada (navegação, troca de página), ela não pode continuar acesa.
onBeforeUnmount(() => {
  window.removeEventListener('keydown', aoTeclado);
  pararCamera();
});

watch(campoManualVisivel, async (visivel) => {
  if (visivel) {
    await nextTick();
    inputCodigoRef.value?.focus();
  }
});

function aoTeclado(evento) {
  if (evento.key === 'Escape') {
    fechar();
  }
}

async function iniciarCamera() {
  estadoPermissao.value = 'verificando';
  explicacaoPermissao.value = '';

  if (typeof navigator === 'undefined' || !navigator.mediaDevices?.getUserMedia || !window.isSecureContext) {
    definirIndisponivel();
    return;
  }

  try {
    html5Qrcode = new Html5Qrcode(idViewport);

    await html5Qrcode.start(
      { facingMode: 'environment' },
      { fps: 10, qrbox: calcularCaixaDeMira },
      aoDecodificar,
      // Callback de "não achei QR neste quadro": dispara a cada frame sem
      // leitura, é o caminho normal de ainda estar procurando, não um erro.
      () => {},
    );

    estadoPermissao.value = 'concedida';
  } catch (erroCapturado) {
    tratarErroDeCamera(erroCapturado);
  }
}

// Moldura de mira: quadrado central com 70% do menor lado do viewport. O
// próprio html5-qrcode desenha a área sombreada e a borda a partir disto.
function calcularCaixaDeMira(larguraViewport, alturaViewport) {
  const lado = Math.floor(Math.min(larguraViewport, alturaViewport) * 0.7);

  return { width: lado, height: lado };
}

function tratarErroDeCamera(erroCapturado) {
  const nome = erroCapturado?.name || '';
  const mensagem = String(erroCapturado?.message || erroCapturado || '');

  const permissaoNegada = nome === 'NotAllowedError'
    || nome === 'PermissionDeniedError'
    || /permission/i.test(mensagem);

  if (permissaoNegada) {
    definirNegada();
  } else {
    definirIndisponivel();
  }
}

function definirNegada() {
  estadoPermissao.value = 'negada';
  explicacaoPermissao.value = 'Câmera não liberada neste navegador. Digite o código impresso na etiqueta.';
}

function definirIndisponivel() {
  estadoPermissao.value = 'indisponivel';
  explicacaoPermissao.value = 'A leitura por câmera não está disponível neste aparelho ou conexão. '
    + 'Digite o código impresso na etiqueta.';
}

/**
 * Desliga a câmera de verdade. Chamada em todo caminho de saída: fechar,
 * Esc, leitura concluída e desmontagem. `stop()` libera o hardware mesmo que
 * o elemento já tenha saído do DOM (ver comentário do `finally`); `clear()`
 * só limpa o que sobrou visualmente, e uma falha nela nunca deve mascarar que
 * a câmera já foi desligada.
 */
async function pararCamera() {
  const instancia = html5Qrcode;
  html5Qrcode = null;

  if (!instancia) {
    return;
  }

  try {
    if (instancia.isScanning) {
      await instancia.stop();
    }
    instancia.clear();
  } catch {
    // Objetivo aqui é só garantir que a câmera não fique ligada. Se ela já
    // não estava rodando (por exemplo, o start anterior falhou) ou o
    // elemento já saiu do DOM, não há nada a mais a fazer.
  }
}

function pausarCameraSeAtiva() {
  if (html5Qrcode?.isScanning) {
    try {
      html5Qrcode.pause(true);
    } catch {
      // Segue sem pausar; pior caso é reprocessar o mesmo quadro uma vez.
    }
  }
}

function retomarCameraSeAtiva() {
  if (html5Qrcode?.isScanning) {
    try {
      html5Qrcode.resume();
    } catch {
      // Câmera pode ter sido perdida nesse meio tempo; a digitação manual
      // continua disponível de qualquer forma.
    }
  }
}

function aoDecodificar(textoDecodificado) {
  if (leitura.carregando.value || mostrarBlocoSituacao.value) {
    return;
  }

  // Pausa assim que detecta ALGO, antes mesmo de saber o que é: a etiqueta
  // continua no quadro enquanto a consulta roda, e sem pausar o mesmo QR
  // dispararia de novo a cada frame.
  pausarCameraSeAtiva();
  processarCodigo(extrairCodigoDoTextoLido(textoDecodificado));
}

async function processarCodigo(codigo) {
  if (!codigo) {
    retomarCameraSeAtiva();
    return;
  }

  await leitura.ler(codigo, props.workOrderId);

  if (leitura.situacao.value === 'ok') {
    await finalizarLeitura(leitura.dispositivo.value?.codigo_publico ?? codigo);
  }
  // Nas demais situações (e no erro de rede) o bloco de mensagem aparece
  // sozinho, via `mostrarBlocoSituacao`, e a câmera segue pausada até o
  // técnico decidir algo.
}

function tentarNovamente() {
  leitura.limpar();
  codigoDigitado.value = '';
  retomarCameraSeAtiva();
}

async function confirmarAbrirMesmoAssim() {
  await finalizarLeitura(leitura.abrirMesmoAssim());
}

async function confirmarAbrirOAtual() {
  await finalizarLeitura(leitura.abrirOAtual());
}

async function finalizarLeitura(codigo) {
  if (!codigo) {
    tentarNovamente();
    return;
  }

  await pararCamera();
  emit('lido', codigo);
  emit('fechado');
}

async function fechar() {
  await pararCamera();
  emit('fechado');
}

function aoTeclaNoCodigo(evento) {
  // Teclas de mais de um caractere (Backspace, Delete, Tab, setas, Enter...)
  // e atalhos com modificador sempre passam; só a tecla de um caractere entra
  // na checagem do alfabeto. Recusar aqui, e não depois de digitar, é o que a
  // task pede: o caractere fora do alfabeto nunca chega a aparecer no campo.
  if (evento.key.length > 1 || evento.ctrlKey || evento.metaKey || evento.altKey) {
    return;
  }

  if (!caractereDoCodigoEhValido(evento.key)) {
    evento.preventDefault();
  }
}

function aoDigitarCodigo(evento) {
  // Rede de segurança além do `keydown`: normaliza para maiúsculas e recorta
  // qualquer coisa que tenha chegado por um caminho que não passa pelo
  // bloqueio de tecla (autocompletar do navegador, por exemplo).
  codigoDigitado.value = normalizarDigitacaoDeCodigo(evento.target.value);
}

function aoColarCodigo(evento) {
  evento.preventDefault();
  const textoColado = (evento.clipboardData || window.clipboardData)?.getData('text') || '';
  codigoDigitado.value = normalizarDigitacaoDeCodigo(textoColado);
}

async function confirmarDigitado() {
  if (!podeConfirmarDigitado.value) {
    return;
  }

  await processarCodigo(codigoDigitado.value);
}
</script>
