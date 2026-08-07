<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        :title="`Planta - ${endereco.nickname || endereco.street}`"
        :description="planta ? `Versão ${planta.versao}${planta.ativa ? ' (ativa)' : ' (substituída)'}` : 'Nenhuma planta enviada ainda para este endereço'"
      >
        <template #actions>
          <button v-if="planta" type="button" class="btn-secondary" @click="abrirModalSubstituir">
            Enviar nova versão
          </button>
          <button type="button" class="btn-primary" @click="abrirModalNovaPlanta">
            {{ planta ? 'Enviar outra planta' : 'Enviar planta' }}
          </button>
        </template>
      </PageHeader>
    </template>

    <div class="mx-auto max-w-[1600px] space-y-4">
      <div v-if="$page.props.flash?.success" class="rounded-md border border-green-200 bg-green-50 p-4 text-sm font-medium text-green-800">
        {{ $page.props.flash.success }}
      </div>
      <div v-if="$page.props.flash?.error" class="rounded-md border border-red-200 bg-red-50 p-4 text-sm font-medium text-red-800">
        {{ $page.props.flash.error }}
      </div>

      <!-- Seletor de planta: só aparece quando o endereço tem mais de uma
           (ex.: "Térreo" e "Depósito"), como pede a Task 21.7. -->
      <div v-if="plantasDoEndereco.length > 1" class="flex items-center gap-2">
        <label for="seletor-planta" class="text-sm font-medium text-gray-700">Planta:</label>
        <select
          id="seletor-planta"
          class="rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
          :value="planta?.id ?? ''"
          @change="trocarPlanta($event.target.value)"
        >
          <option v-for="item in plantasDoEndereco" :key="item.floorPlanId" :value="item.floorPlanId">
            {{ item.nome }}
          </option>
        </select>
      </div>

      <div v-if="!planta" class="rounded-lg border border-dashed border-gray-300 bg-white p-10 text-center text-sm text-gray-500">
        Este endereço ainda não tem planta cadastrada. Envie a primeira para começar a posicionar os dispositivos.
      </div>

      <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_320px]">
        <div class="space-y-2">
          <!-- Barra de ações do editor: desfazer, indicador de salvamento e o
               botão explícito de salvar (Task 21.7: "salvamento 100%
               silencioso deixa o usuário inseguro"). -->
          <div class="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-3 py-2">
            <div class="flex items-center gap-2">
              <button
                type="button"
                class="btn-secondary-sm"
                :disabled="pilhaDeDesfazer.length === 0"
                @click="desfazer"
              >
                Desfazer
              </button>
              <span class="hidden text-xs text-gray-400 sm:inline">Ctrl+Z</span>
            </div>

            <div class="flex items-center gap-3">
              <button
                type="button"
                class="flex items-center gap-1.5 text-xs"
                :class="corTextoIndicador"
                :disabled="estadoSalvamento !== 'erro'"
                @click="estadoSalvamento === 'erro' ? salvarAgora() : null"
              >
                <span class="h-1.5 w-1.5 rounded-full" :class="corPontoIndicador" />
                {{ textoIndicador }}
              </button>
              <button type="button" class="btn-primary" :disabled="estadoSalvamento === 'salvando'" @click="salvarAgora">
                Salvar
              </button>
            </div>
          </div>

          <div class="h-[65vh] min-h-[420px]">
            <PlantaCanvas
              ref="canvasRef"
              :imagem-url="planta.arquivo_url"
              :largura-natural="planta.largura_px"
              :altura-natural="planta.altura_px"
              :pontos="pontosParaCanvas"
              @mover-ponto="aoMoverPonto"
              @remover-posicao="aoRemoverPosicao"
            />
          </div>
        </div>

        <div class="space-y-4">
          <Card padding="none" class="h-[65vh] min-h-[420px] overflow-hidden">
            <ListaDeNaoPosicionados
              :dispositivos="naoPosicionadosLocal"
              :total-dispositivos="totalDispositivosAtivos"
              @soltar="aoSoltarDispositivo"
            />
          </Card>

          <Card v-if="versoes.length" padding="none">
            <div class="border-b border-gray-200 px-4 py-3">
              <h3 class="text-sm font-semibold text-gray-900">Histórico de versões</h3>
            </div>
            <ul class="max-h-64 divide-y divide-gray-100 overflow-y-auto">
              <li v-for="versaoItem in versoes" :key="versaoItem.id" class="flex items-center justify-between gap-2 px-4 py-2 text-sm">
                <div class="min-w-0">
                  <p class="font-medium text-gray-900">Versão {{ versaoItem.versao }}</p>
                  <p class="text-xs text-gray-500">{{ formatarDataHora(versaoItem.created_at) }}</p>
                </div>
                <span
                  v-if="versaoItem.ativa"
                  class="shrink-0 rounded-full bg-green-100 px-2 py-0.5 text-xs font-medium text-green-800"
                >
                  Ativa
                </span>
                <span v-else class="shrink-0 rounded-full bg-gray-100 px-2 py-0.5 text-xs font-medium text-gray-600">
                  Substituída
                </span>
              </li>
            </ul>
          </Card>
        </div>
      </div>
    </div>

    <!-- Envio de planta (nova ou substituição). A Task 21.7 pede o aviso
         explícito ANTES de confirmar, sempre por `Modal.vue` - nunca
         `confirm()` nativo. -->
    <Modal :show="modalEnvio.aberto" @close="fecharModalEnvio">
      <template #title>
        {{ modalEnvio.modo === 'substituir' ? `Enviar nova versão de "${planta?.nome}"` : 'Enviar planta' }}
      </template>
      <template #content>
        <div v-if="modalEnvio.modo === 'substituir'" class="mb-4 rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
          A versão atual (versão {{ planta?.versao }}) fica preservada no histórico, sem nenhuma alteração.
          As posições já marcadas nela são copiadas para a versão nova, e você só ajusta o que mudou.
        </div>

        <form class="space-y-4" @submit.prevent="enviarPlanta">
          <div v-if="modalEnvio.modo === 'nova'">
            <label for="nome-planta" class="mb-1 block text-sm font-medium text-gray-700">Nome da planta *</label>
            <input
              id="nome-planta"
              v-model="formEnvio.nome"
              type="text"
              placeholder="Ex: Térreo, Depósito"
              class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
              :class="{ 'border-red-500': formEnvio.errors.nome }"
            />
            <p v-if="formEnvio.errors.nome" class="mt-1 text-sm text-red-600">{{ formEnvio.errors.nome }}</p>
          </div>
          <p v-else class="text-sm text-gray-600">
            Planta: <strong class="text-gray-900">{{ planta?.nome }}</strong>
          </p>

          <div v-if="modalEnvio.modo === 'nova'">
            <label for="observacao-planta" class="mb-1 block text-sm font-medium text-gray-700">Observação</label>
            <textarea
              id="observacao-planta"
              v-model="formEnvio.observacao"
              rows="2"
              class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
            />
          </div>

          <div>
            <label for="arquivo-planta" class="mb-1 block text-sm font-medium text-gray-700">
              Arquivo (PNG, JPEG ou PDF de uma página, até 10 MB) *
            </label>
            <input
              id="arquivo-planta"
              type="file"
              accept=".png,.jpg,.jpeg,.pdf,image/png,image/jpeg,application/pdf"
              class="block w-full text-sm text-gray-500 file:mr-4 file:rounded-md file:border-0 file:bg-green-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-green-700 hover:file:bg-green-100"
              @change="aoEscolherArquivo"
            />
            <p v-if="formEnvio.errors.arquivo" class="mt-1 text-sm text-red-600">{{ formEnvio.errors.arquivo }}</p>
          </div>
        </form>
      </template>
      <template #actions>
        <button type="button" class="btn-primary" :disabled="formEnvio.processing" @click="enviarPlanta">
          {{ formEnvio.processing ? 'Enviando...' : (modalEnvio.modo === 'substituir' ? 'Confirmar e enviar nova versão' : 'Enviar planta') }}
        </button>
        <button type="button" class="btn-secondary" :disabled="formEnvio.processing" @click="fecharModalEnvio">
          Cancelar
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
/**
 * Editor de planta com posicionamento por arrastar (Plano 21, Task 21.7).
 *
 * CONTRATO DE PROPS -------------------------------------------------------
 * `FloorPlanController::editorPorEndereco()`/`editor()` (rotas
 * `enderecos.plantas.editor` e `plantas.editor`, adicionadas depois da Task
 * 21.7 para fechar o buraco de integração que esta página deixou - ver
 * `FloorPlanController.php`) renderizam esta página com as props abaixo:
 *
 * - `endereco`      { id, nickname, street, number }
 * - `planta`        A versão ATIVA sendo editada agora, ou `null` se o
 *                    endereço ainda não tem nenhuma planta com este nome.
 *                    { id, nome, versao, arquivo_url, largura_px, altura_px,
 *                      ativa, created_at, observacao }
 *                    `arquivo_url` é o accessor `FloorPlan::getArquivoUrlAttribute()`.
 * - `versoes`       Todas as versões do MESMO `nome` de `planta`, mais
 *                    recente primeiro (o "histórico de versões" da task).
 *                    [{ id, versao, ativa, created_at, substituida_em,
 *                       device_positions_count }]
 * - `plantasDoEndereco` Uma entrada por NOME distinto de planta do endereço
 *                    (a versão ativa de cada), para o seletor "Térreo /
 *                    Depósito" quando há mais de uma.
 *                    [{ nome, floorPlanId, versao }]
 * - `posicoes`       Posições da planta ativa selecionada, com o dispositivo
 *                    já embutido (evita N+1 de lookup no frontend).
 *                    [{ deviceId, x, y, rotuloVisivel, device: { id, label,
 *                       number, codigo_publico } }]
 * - `naoPosicionados` Saída de `FloorPlanService::dispositivosNaoPosicionados()`.
 *                    [{ id, label, number, codigo_publico }]
 * - `totalDispositivosAtivos` Total de dispositivos ATIVOS do endereço
 *                    (posicionados + não posicionados), para o contador
 *                    "12 de 40 posicionados".
 *
 * `trocarPlanta()` (seletor) visita `route('plantas.editor', floorPlanId)`.
 *
 * `PUT /plantas/{floorPlan}/posicoes` responde `JsonResponse` (não mais
 * redirect - ajustado junto com a criação das rotas do editor, mesmo
 * critério de `AgendaController::reagendar()`), então `salvarPendentes()`
 * abaixo lê `resposta.ok` direto, sem truque de `redirect: 'manual'`.
 *
 * `StoreFloorPlanRequest` (usado tanto por `store()` quanto por
 * `substituir()`) exige `nome` como campo obrigatório, mas
 * `FloorPlanService::substituir()` ignora esse valor por completo (sempre
 * reaproveita o nome da versão anterior). O formulário de "enviar nova
 * versão" abaixo manda `nome` preenchido automaticamente com o nome atual
 * (sem campo editável, para não sugerir que trocar o nome ali faz alguma
 * diferença) só para passar na validação.
 *
 * Remoção de posição persiste via `DELETE /plantas/{floorPlan}/posicoes/{device}`
 * (`FloorPlanService::removerPosicao()`), disparado em paralelo ao remover
 * do estado local em `aoRemoverPosicao()`, sem debounce (ação explícita e
 * única, diferente do arraste contínuo).
 */
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import { useDebounceFn } from '@vueuse/core';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import PlantaCanvas from '@/Components/PlantaCanvas.vue';
import ListaDeNaoPosicionados from '@/Components/ListaDeNaoPosicionados.vue';
import { formatarDataHora } from '@/utils/formatDate';

const props = defineProps({
  endereco: { type: Object, required: true },
  planta: { type: Object, default: null },
  versoes: { type: Array, default: () => [] },
  plantasDoEndereco: { type: Array, default: () => [] },
  posicoes: { type: Array, default: () => [] },
  naoPosicionados: { type: Array, default: () => [] },
  totalDispositivosAtivos: { type: Number, default: 0 },
});

const SALVAMENTO_DEBOUNCE_MS = 1000;
const LIMITE_HISTORICO_DESFAZER = 50;

const canvasRef = ref(null);

// --- Estado local das posições (fonte da verdade da sessão de edição) ----

const posicionadosMap = reactive(new Map()); // deviceId -> { x, y, rotuloVisivel, device }
const naoPosicionadosLocal = ref([]);
const pilhaDeDesfazer = reactive([]); // [{ deviceId, anterior: {x,y}|null, depois: {x,y} }]
const pendentes = reactive(new Set()); // deviceIds com posição ainda não confirmada pelo backend
const estadoSalvamento = ref('salvo'); // 'pendente' | 'salvando' | 'salvo' | 'erro'
const mensagemErro = ref('');

function ordenarPorRotulo(lista) {
  return [...lista].sort((a, b) => (a.label || '').localeCompare(b.label || '', 'pt-BR'));
}

function inicializarEstado() {
  posicionadosMap.clear();
  (props.posicoes || []).forEach((posicao) => {
    posicionadosMap.set(posicao.deviceId, {
      x: Number(posicao.x),
      y: Number(posicao.y),
      rotuloVisivel: posicao.rotuloVisivel !== false,
      device: posicao.device,
    });
  });

  naoPosicionadosLocal.value = ordenarPorRotulo(props.naoPosicionados || []);
  pilhaDeDesfazer.length = 0;
  pendentes.clear();
  estadoSalvamento.value = 'salvo';
  mensagemErro.value = '';
}

watch(() => props.planta?.id, inicializarEstado, { immediate: true });

const pontosParaCanvas = computed(() =>
  Array.from(posicionadosMap.entries()).map(([deviceId, registro]) => ({
    deviceId,
    x: registro.x,
    y: registro.y,
    rotulo: registro.device?.label || `Dispositivo #${deviceId}`,
    codigo: registro.device?.codigo_publico || '-',
    numero: registro.device?.number || '',
  }))
);

// --- Aplicar mudanças de posição -------------------------------------------

function moverPontoExistente(deviceId, x, y) {
  const registro = posicionadosMap.get(deviceId);
  if (!registro) return;
  registro.x = x;
  registro.y = y;
  marcarComoPendente(deviceId);
}

function posicionarDispositivoNovo(dispositivo, x, y) {
  const indice = naoPosicionadosLocal.value.findIndex((item) => item.id === dispositivo.id);
  if (indice >= 0) naoPosicionadosLocal.value.splice(indice, 1);

  posicionadosMap.set(dispositivo.id, { x, y, rotuloVisivel: true, device: dispositivo });
  marcarComoPendente(dispositivo.id);
}

function limitarHistorico() {
  if (pilhaDeDesfazer.length > LIMITE_HISTORICO_DESFAZER) {
    pilhaDeDesfazer.shift();
  }
}

/** Emitido por `PlantaCanvas` ao soltar um ponto já existente arrastado. */
function aoMoverPonto(deviceId, x, y) {
  const atual = posicionadosMap.get(deviceId);
  if (!atual) return;

  pilhaDeDesfazer.push({ deviceId, anterior: { x: atual.x, y: atual.y }, depois: { x, y } });
  limitarHistorico();
  moverPontoExistente(deviceId, x, y);
}

/**
 * Emitido por `PlantaCanvas` ao confirmar a remoção pelo popover. Some da
 * tela imediatamente (a espera do fetch deixaria o ponto visível por mais
 * um instante depois do usuário já ter confirmado a remoção) e persiste em
 * paralelo via `DELETE /plantas/{floorPlan}/posicoes/{device}` - sem
 * debounce, é uma ação explícita e única, não um movimento contínuo como o
 * arraste.
 */
function aoRemoverPosicao(deviceId) {
  const registro = posicionadosMap.get(deviceId);
  if (!registro) return;

  posicionadosMap.delete(deviceId);
  pendentes.delete(deviceId);
  naoPosicionadosLocal.value = ordenarPorRotulo([...naoPosicionadosLocal.value, registro.device]);
  atualizarEstadoSemPendencias();

  if (!props.planta) return;

  fetch(route('plantas.posicoes.remover', [props.planta.id, deviceId]), {
    method: 'DELETE',
    headers: {
      Accept: 'application/json',
      'X-CSRF-TOKEN': tokenCsrf(),
    },
  }).catch(() => {
    mensagemErro.value = 'Não foi possível confirmar a remoção com o servidor. Atualize a página para conferir.';
  });
}

/** Emitido por `ListaDeNaoPosicionados` ao soltar um item sobre a página. */
function aoSoltarDispositivo({ dispositivo, clientX, clientY }) {
  const fracao = canvasRef.value?.pontoDeTelaParaFracao(clientX, clientY);
  if (!fracao) return; // soltou fora da planta - nada muda

  pilhaDeDesfazer.push({ deviceId: dispositivo.id, anterior: null, depois: { x: fracao.x, y: fracao.y } });
  limitarHistorico();
  posicionarDispositivoNovo(dispositivo, fracao.x, fracao.y);
}

function atualizarEstadoSemPendencias() {
  if (pendentes.size === 0 && estadoSalvamento.value !== 'salvando') {
    estadoSalvamento.value = 'salvo';
  }
}

// --- Desfazer (Ctrl+Z / Cmd+Z + botão) -------------------------------------

function desfazer() {
  const ultimo = pilhaDeDesfazer.pop();
  if (!ultimo) return;

  if (ultimo.anterior === null) {
    // Era um posicionamento novo (arrastado da lista): desfazer tira do
    // mapa e devolve para "não posicionados". Mesma ressalva do popover: se
    // o autosave já tiver confirmado essa posição no backend antes do
    // desfazer, a linha em `device_positions` continua lá (sem endpoint de
    // remoção - ver o comentário no topo do arquivo).
    const registro = posicionadosMap.get(ultimo.deviceId);
    posicionadosMap.delete(ultimo.deviceId);
    pendentes.delete(ultimo.deviceId);
    if (registro) {
      naoPosicionadosLocal.value = ordenarPorRotulo([...naoPosicionadosLocal.value, registro.device]);
    }
    atualizarEstadoSemPendencias();
    return;
  }

  // Volta para a posição anterior, e isso precisa ir para o backend também:
  // "desfazer local e depois o autosave manda a posição revertida" (Task 21.7).
  moverPontoExistente(ultimo.deviceId, ultimo.anterior.x, ultimo.anterior.y);
}

function aoTeclarAtalho(evento) {
  const combinacao = (evento.ctrlKey || evento.metaKey) && !evento.shiftKey && evento.key.toLowerCase() === 'z';
  if (!combinacao) return;

  // Dentro de um campo de texto (ex.: observação do formulário de envio) o
  // desfazer nativo do navegador continua valendo - não interceptar ali.
  const alvo = evento.target;
  if (alvo instanceof HTMLElement && ['INPUT', 'TEXTAREA'].includes(alvo.tagName)) return;

  evento.preventDefault();
  desfazer();
}

onMounted(() => window.addEventListener('keydown', aoTeclarAtalho));
onUnmounted(() => window.removeEventListener('keydown', aoTeclarAtalho));

// --- Salvamento automático (debounce de 1s) + botão explícito -------------

function marcarComoPendente(deviceId) {
  pendentes.add(deviceId);
  if (estadoSalvamento.value !== 'salvando') {
    estadoSalvamento.value = 'pendente';
  }
  agendarSalvamento();
}

function tokenCsrf() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
}

async function salvarPendentes() {
  if (!props.planta || pendentes.size === 0) return;

  const idsParaSalvar = [...pendentes];
  const payload = idsParaSalvar
    .filter((deviceId) => posicionadosMap.has(deviceId))
    .map((deviceId) => {
      const registro = posicionadosMap.get(deviceId);
      return { device_id: deviceId, x: registro.x, y: registro.y, rotulo_visivel: registro.rotuloVisivel };
    });

  if (payload.length === 0) {
    idsParaSalvar.forEach((deviceId) => pendentes.delete(deviceId));
    atualizarEstadoSemPendencias();
    return;
  }

  estadoSalvamento.value = 'salvando';

  try {
    const resposta = await fetch(route('plantas.posicoes', props.planta.id), {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': tokenCsrf(),
      },
      body: JSON.stringify({ posicoes: payload }),
    });

    if (!resposta.ok) {
      const dados = await resposta.json().catch(() => null);
      throw new Error(dados?.message || dados?.errors?.posicoes?.[0] || 'Não foi possível salvar as posições.');
    }

    idsParaSalvar.forEach((deviceId) => pendentes.delete(deviceId));
    mensagemErro.value = '';

    if (pendentes.size > 0) {
      estadoSalvamento.value = 'pendente';
      agendarSalvamento();
    } else {
      estadoSalvamento.value = 'salvo';
    }
  } catch (erro) {
    estadoSalvamento.value = 'erro';
    mensagemErro.value = erro?.message || 'Falha de conexão ao salvar as posições.';
  }
}

const agendarSalvamento = useDebounceFn(salvarPendentes, SALVAMENTO_DEBOUNCE_MS);

/** Botão "Salvar" explícito: força o envio imediato, sem esperar o debounce. */
function salvarAgora() {
  agendarSalvamento.cancel?.();
  salvarPendentes();
}

const textoIndicador = computed(() => ({
  pendente: 'Alterações pendentes...',
  salvando: 'Salvando...',
  salvo: 'Salvo',
  erro: mensagemErro.value || 'Erro ao salvar - toque para tentar de novo',
}[estadoSalvamento.value]));

const corTextoIndicador = computed(() => ({
  pendente: 'text-gray-500',
  salvando: 'text-gray-500',
  salvo: 'text-green-700',
  erro: 'text-red-700 cursor-pointer',
}[estadoSalvamento.value]));

const corPontoIndicador = computed(() => ({
  pendente: 'bg-gray-400',
  salvando: 'bg-gray-400 animate-pulse',
  salvo: 'bg-green-500',
  erro: 'bg-red-500',
}[estadoSalvamento.value]));

// --- Seletor de planta (endereço com mais de um nome de planta) -----------

function trocarPlanta(floorPlanId) {
  if (!floorPlanId || String(floorPlanId) === String(props.planta?.id)) return;
  router.get(route('plantas.editor', floorPlanId), {}, { preserveScroll: true });
}

// --- Envio de planta (primeira vez ou nova versão) -------------------------

const modalEnvio = reactive({ aberto: false, modo: 'nova' }); // 'nova' | 'substituir'
const formEnvio = useForm({ nome: '', observacao: '', arquivo: null });

function abrirModalNovaPlanta() {
  modalEnvio.modo = 'nova';
  modalEnvio.aberto = true;
  formEnvio.reset();
  formEnvio.clearErrors();
}

function abrirModalSubstituir() {
  if (!props.planta) return;
  modalEnvio.modo = 'substituir';
  modalEnvio.aberto = true;
  formEnvio.reset();
  formEnvio.clearErrors();
  // `StoreFloorPlanRequest` exige `nome` mesmo na substituição, embora
  // `FloorPlanService::substituir()` ignore o valor (ver aviso no topo do
  // arquivo) - preenchido aqui sem campo editável, só para passar na
  // validação.
  formEnvio.nome = props.planta.nome;
}

function fecharModalEnvio() {
  if (formEnvio.processing) return;
  modalEnvio.aberto = false;
}

function aoEscolherArquivo(evento) {
  formEnvio.arquivo = evento.target.files[0] || null;
}

function enviarPlanta() {
  const rota = modalEnvio.modo === 'substituir'
    ? route('plantas.substituir', props.planta.id)
    : route('enderecos.plantas.store', props.endereco.id);

  formEnvio.post(rota, {
    forceFormData: true,
    preserveScroll: true,
    onSuccess: () => {
      modalEnvio.aberto = false;
    },
  });
}
</script>
