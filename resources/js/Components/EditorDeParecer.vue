<template>
  <Card v-if="temModulo('laudo_ia')" padding="none">
    <div class="px-6 py-4 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <h3 class="text-lg font-medium text-gray-900">Parecer técnico</h3>
        <p class="text-sm text-gray-500">
          Rascunho assistido por inteligência artificial, para revisão do responsável técnico.
        </p>
      </div>

      <button
        v-if="pode('ia-gerar') && !rascunho"
        type="button"
        class="btn-primary whitespace-nowrap"
        :disabled="gerando"
        @click="gerarRascunho"
      >
        <span v-if="gerando">Gerando...</span>
        <span v-else>Gerar rascunho</span>
      </button>
    </div>

    <div class="p-6 space-y-4">
      <!-- Erros: cada motivo tem a sua própria mensagem, em português e sem
           jargão. "Deu erro" não diz a quem lê se é para tentar de novo, se é
           para esperar ou se é para escrever à mão. -->
      <div v-if="erro" class="rounded-md border p-4" :class="classesDoErro">
        <p class="text-sm font-medium">{{ erro }}</p>
      </div>

      <p v-if="!rascunho && !gerando" class="text-sm text-gray-500">
        Nenhum rascunho gerado para esta ordem de serviço. O parecer também pode ser
        escrito manualmente no documento, sem passar por aqui.
      </p>

      <template v-if="rascunho">
        <!-- Faixa de aviso: permanente enquanto não houver revisão aprovada.
             Não é dispensável e não some ao editar o texto. Texto gerado que
             parece pronto é como alguém emite um laudo sem ler. -->
        <div
          v-if="!estaRevisado"
          class="rounded-md border border-yellow-300 bg-yellow-50 p-4 flex items-start gap-3"
        >
          <svg class="h-5 w-5 flex-shrink-0 text-yellow-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z" />
          </svg>
          <div>
            <p class="text-sm font-semibold text-yellow-900">
              Texto gerado automaticamente. Precisa da revisão do responsável técnico antes de emitir o documento.
            </p>
            <p class="mt-1 text-sm text-yellow-800">
              A emissão da ordem de serviço fica bloqueada enquanto este parecer não for revisado.
            </p>
          </div>
        </div>

        <div v-else class="rounded-md border border-green-300 bg-green-50 p-4">
          <p class="text-sm font-semibold text-green-900">
            Parecer revisado e assumido pelo responsável técnico.
          </p>
          <p v-if="revisadoEm" class="mt-1 text-sm text-green-800">
            Revisado em {{ revisadoEm }}.
          </p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">
            Texto do parecer
          </label>
          <textarea
            v-model="texto"
            rows="12"
            :disabled="!pode('ia-revisar')"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 disabled:bg-gray-50 disabled:text-gray-500"
          ></textarea>
          <p class="mt-1 text-xs text-gray-500">
            Modelo utilizado: {{ rascunho.modelo }}
          </p>
        </div>

        <div class="flex flex-col sm:flex-row sm:justify-end gap-3">
          <button
            v-if="pode('ia-revisar')"
            type="button"
            class="btn-secondary"
            :disabled="salvando"
            @click="abrirDescarte"
          >
            Descartar rascunho
          </button>
          <button
            v-if="pode('ia-revisar') && !estaRevisado"
            type="button"
            class="btn-primary"
            :disabled="salvando || !textoPreenchido"
            @click="modalDeRevisao = true"
          >
            Revisar e assumir este texto
          </button>
        </div>

        <!-- Comparação lado a lado: é o que documenta a revisão para quem
             auditar depois. O gerado nunca é sobrescrito justamente para este
             quadro poder existir. -->
        <div v-if="estaRevisado" class="border-t border-gray-200 pt-4">
          <h4 class="text-sm font-medium text-gray-900 mb-3">
            Comparação entre o texto gerado e o texto revisado
          </h4>
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">
                Gerado automaticamente
              </p>
              <div class="rounded-md border border-gray-200 bg-gray-50 p-3 text-sm leading-relaxed max-h-80 overflow-y-auto">
                <p
                  v-for="(linha, indice) in linhasGeradas"
                  :key="`gerado-${indice}`"
                  class="whitespace-pre-wrap"
                  :class="linha.alterada ? 'bg-red-100 text-red-900 rounded px-1' : 'text-gray-700'"
                >{{ linha.texto || ' ' }}</p>
              </div>
            </div>
            <div>
              <p class="text-xs font-semibold uppercase tracking-wide text-gray-500 mb-2">
                Revisado pelo responsável técnico
              </p>
              <div class="rounded-md border border-gray-200 bg-white p-3 text-sm leading-relaxed max-h-80 overflow-y-auto">
                <p
                  v-for="(linha, indice) in linhasRevisadas"
                  :key="`revisado-${indice}`"
                  class="whitespace-pre-wrap"
                  :class="linha.alterada ? 'bg-green-100 text-green-900 rounded px-1' : 'text-gray-700'"
                >{{ linha.texto || ' ' }}</p>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- Confirmação da revisão. A frase de responsabilidade é o ponto do
         modal: assumir o texto é declarar que o responsável técnico passa a
         responder pelo conteúdo. -->
    <div
      v-if="modalDeRevisao"
      class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4"
    >
      <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full mx-4">
        <div class="p-6">
          <h3 class="text-lg font-semibold text-gray-900 mb-2">Assumir este parecer</h3>
          <p class="text-sm text-gray-700 mb-6">
            Ao confirmar, o responsável técnico assume este parecer como próprio e passa a
            responder pelo conteúdo do documento emitido.
          </p>
          <div class="flex justify-end gap-3">
            <button type="button" class="btn-secondary" :disabled="salvando" @click="modalDeRevisao = false">
              Cancelar
            </button>
            <button type="button" class="btn-primary" :disabled="salvando" @click="confirmarRevisao">
              <span v-if="salvando">Salvando...</span>
              <span v-else>Confirmar revisão</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <div
      v-if="modalDeDescarte"
      class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4"
    >
      <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full mx-4">
        <div class="p-6">
          <h3 class="text-lg font-semibold text-gray-900 mb-2">Descartar rascunho</h3>
          <p class="text-sm text-gray-700 mb-6">
            O texto gerado continua registrado no histórico, e um novo rascunho poderá ser
            gerado para esta ordem de serviço.
          </p>
          <div class="flex justify-end gap-3">
            <button type="button" class="btn-secondary" :disabled="salvando" @click="modalDeDescarte = false">
              Cancelar
            </button>
            <button type="button" class="btn-danger" :disabled="salvando" @click="confirmarDescarte">
              <span v-if="salvando">Descartando...</span>
              <span v-else>Sim, descartar</span>
            </button>
          </div>
        </div>
      </div>
    </div>
  </Card>
</template>

<script setup>
import { computed, ref } from 'vue';
import Card from '@/Components/Card.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { useModulos } from '@/Composables/useModulos';
import { formatarDataHora } from '@/utils/formatDate';

/**
 * Editor do rascunho de parecer técnico (Plano 25, Task 25.6).
 *
 * Três regras moram nesta tela:
 *
 * 1. A faixa de "texto gerado automaticamente" é permanente enquanto não
 *    houver revisão aprovada. Não é dispensável e não some ao editar.
 * 2. Assumir o texto é ação explícita, com modal e com a consequência escrita.
 * 3. Depois de revisado, o gerado e o revisado aparecem lado a lado, com as
 *    linhas diferentes destacadas.
 *
 * Esconder botão por permissão aqui é conforto visual. Quem barra de verdade
 * é o middleware da rota, e a emissão do documento é barrada no Service
 * (`ParecerService::garantirParecerRevisado`), não aqui.
 */
const props = defineProps({
  // 'parecer_os' ou 'resumo_monitoramento'.
  tipo: { type: String, default: 'parecer_os' },
  origemId: { type: [Number, String], required: true },
  // Rascunho já existente, quando a página o carrega junto.
  rascunhoInicial: { type: Object, default: null },
});

const emit = defineEmits(['atualizado']);

const { pode } = usePermissoes();
const { temModulo } = useModulos();

const rascunho = ref(props.rascunhoInicial);
const texto = ref(props.rascunhoInicial?.conteudo_revisado ?? props.rascunhoInicial?.conteudo_gerado ?? '');
const gerando = ref(false);
const salvando = ref(false);
const erro = ref('');
const erroEhAviso = ref(false);
const modalDeRevisao = ref(false);
const modalDeDescarte = ref(false);

const estaRevisado = computed(() => rascunho.value?.situacao === 'revisado');
const textoPreenchido = computed(() => (texto.value || '').trim().length >= 20);
const revisadoEm = computed(() =>
  rascunho.value?.revisado_em ? formatarDataHora(rascunho.value.revisado_em) : ''
);

// Teto do plano é aviso (a empresa pode escrever à mão e seguir), enquanto
// indisponibilidade e recusa são erro de verdade.
const classesDoErro = computed(() =>
  erroEhAviso.value
    ? 'border-yellow-300 bg-yellow-50 text-yellow-900'
    : 'border-red-300 bg-red-50 text-red-800'
);

const linhasGeradas = computed(() => compararLinhas(
  rascunho.value?.conteudo_gerado ?? '',
  rascunho.value?.conteudo_revisado ?? ''
));

const linhasRevisadas = computed(() => compararLinhas(
  rascunho.value?.conteudo_revisado ?? '',
  rascunho.value?.conteudo_gerado ?? ''
));

/**
 * Marca cada parágrafo que não existe igual no outro lado. Comparação por
 * parágrafo, e não por palavra: parecer é prosa, e destacar palavra solta
 * dentro de um texto reescrito produz um borrão ilegível.
 */
function compararLinhas(proprio, outro) {
  const doOutroLado = new Set(
    outro.split('\n').map((linha) => linha.trim()).filter((linha) => linha !== '')
  );

  return proprio.split('\n').map((linha) => ({
    texto: linha,
    alterada: linha.trim() !== '' && !doOutroLado.has(linha.trim()),
  }));
}

function cabecalhos() {
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
  };
}

function tratarFalha(resposta, corpo) {
  // Mensagens distintas por motivo. O backend já devolve texto pronto em
  // português; o que muda aqui é só o tom (aviso ou erro).
  erroEhAviso.value = resposta.status === 429;

  if (corpo?.message) {
    erro.value = corpo.message;

    return;
  }

  if (resposta.status === 503) {
    erro.value = 'O serviço de geração de texto está indisponível no momento. Tente novamente em alguns minutos.';

    return;
  }

  erro.value = 'Não foi possível concluir a operação. Tente novamente.';
}

async function gerarRascunho() {
  gerando.value = true;
  erro.value = '';

  try {
    const resposta = await fetch(route('ia.pareceres.store'), {
      method: 'POST',
      headers: cabecalhos(),
      body: JSON.stringify({ tipo: props.tipo, origem_id: props.origemId }),
    });

    const corpo = await resposta.json();

    if (!resposta.ok) {
      tratarFalha(resposta, corpo);

      // 409 devolve o rascunho que já existe: aproveitar evita o usuário
      // ficar preso num erro cuja solução é justamente abrir o que está lá.
      if (resposta.status === 409 && corpo?.data?.rascunho) {
        aplicarRascunho(corpo.data.rascunho);
      }

      return;
    }

    aplicarRascunho(corpo.data.rascunho);
  } catch {
    erroEhAviso.value = false;
    erro.value = 'Não foi possível falar com o servidor. Verifique a conexão e tente novamente.';
  } finally {
    gerando.value = false;
  }
}

async function confirmarRevisao() {
  salvando.value = true;
  erro.value = '';

  try {
    const resposta = await fetch(route('ia.rascunhos.revisar', rascunho.value.id), {
      method: 'PUT',
      headers: cabecalhos(),
      body: JSON.stringify({ conteudo_revisado: texto.value }),
    });

    const corpo = await resposta.json();

    if (!resposta.ok) {
      tratarFalha(resposta, corpo);

      return;
    }

    aplicarRascunho(corpo.data.rascunho);
    modalDeRevisao.value = false;
  } catch {
    erroEhAviso.value = false;
    erro.value = 'Não foi possível falar com o servidor. Verifique a conexão e tente novamente.';
  } finally {
    salvando.value = false;
  }
}

function abrirDescarte() {
  erro.value = '';
  modalDeDescarte.value = true;
}

async function confirmarDescarte() {
  salvando.value = true;

  try {
    const resposta = await fetch(route('ia.rascunhos.descartar', rascunho.value.id), {
      method: 'POST',
      headers: cabecalhos(),
    });

    const corpo = await resposta.json();

    if (!resposta.ok) {
      tratarFalha(resposta, corpo);

      return;
    }

    rascunho.value = null;
    texto.value = '';
    modalDeDescarte.value = false;
    emit('atualizado', null);
  } catch {
    erroEhAviso.value = false;
    erro.value = 'Não foi possível falar com o servidor. Verifique a conexão e tente novamente.';
  } finally {
    salvando.value = false;
  }
}

function aplicarRascunho(novo) {
  rascunho.value = novo;
  texto.value = novo.conteudo_revisado ?? novo.conteudo_gerado ?? '';
  emit('atualizado', novo);
}
</script>
