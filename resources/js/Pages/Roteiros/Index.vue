<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Roteiro do dia"
        description="Ordem das visitas por técnico, com distância e horário previsto de cada parada"
      >
        <template #actions>
          <button type="button" class="btn-secondary print:hidden" @click="imprimir">
            Imprimir roteiro
          </button>
          <button
            v-if="podeGerenciar"
            type="button"
            class="btn-primary print:hidden"
            :disabled="!rota || carregando"
            @click="abrirModalOtimizar"
          >
            Otimizar ordem
          </button>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <div v-if="erro" class="rounded-md border border-red-200 bg-red-50 p-4 print:hidden">
        <p class="text-sm text-red-800">{{ erro }}</p>
      </div>

      <!-- Filtros: data e técnico -->
      <Card padding="small" class="print:hidden">
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-3">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Data</label>
            <input
              v-model="data"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>

          <div v-if="!restritoAoProprioTecnico">
            <label class="block text-sm font-medium text-gray-700 mb-1">Técnico</label>
            <select
              v-model="technicianId"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Selecione um técnico</option>
              <option v-for="tecnico in tecnicos" :key="tecnico.id" :value="String(tecnico.id)">
                {{ tecnico.name }}
              </option>
            </select>
          </div>
        </div>
      </Card>

      <!-- Cabeçalho de impressão: some na tela, só aparece no papel -->
      <div class="hidden print:block">
        <h1 class="text-lg font-semibold text-gray-900">Roteiro do dia - {{ formatarData(data) }}</h1>
        <p v-if="nomeTecnicoSelecionado" class="text-sm text-gray-600">Técnico: {{ nomeTecnicoSelecionado }}</p>
      </div>

      <div v-if="carregando" class="py-8 text-center text-sm text-gray-500 print:hidden">
        Carregando roteiro...
      </div>

      <template v-else-if="rota">
        <!-- Totais do dia -->
        <Card padding="small">
          <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:gap-6">
            <p class="text-sm text-gray-700">
              <span class="font-medium text-gray-900">Distância total:</span>
              {{ distanciaTotalTexto ?? 'sem estimativa disponível' }}
            </p>
            <p class="text-sm text-gray-700">
              <span class="font-medium text-gray-900">Tempo estimado:</span>
              {{ duracaoTotalTexto ?? 'sem estimativa disponível' }}
            </p>
            <p v-if="rota.ordenacao_manual" class="text-xs text-gray-500">
              Ordem manual{{ rota.reordenada_por ? ` por ${rota.reordenada_por}` : '' }}{{ reordenadaEmTexto ? ` em ${reordenadaEmTexto}` : '' }}.
            </p>
          </div>
        </Card>

        <!-- Mapa -->
        <MapaDeVisitas :paradas="rota.paradas" />

        <!-- Lista reordenável (tela) -->
        <Card padding="none" class="print:hidden">
          <p v-if="salvandoOrdem" class="border-b border-gray-200 bg-gray-50 px-4 py-2 text-xs text-gray-500">
            Salvando nova ordem...
          </p>
          <div v-if="paradas.length === 0" class="p-6 text-center text-sm text-gray-500">
            Nenhuma parada agendada para este dia.
          </div>
          <ul v-else class="divide-y divide-gray-200">
            <li
              v-for="(parada, indice) in paradas"
              :key="parada.route_stop_id"
              :ref="(elemento) => setItemRef(elemento, indice)"
              class="flex items-start gap-3 p-4"
              :class="indiceArrastando === indice ? 'bg-green-50' : ''"
            >
              <button
                v-if="podeGerenciar"
                type="button"
                class="mt-0.5 flex-shrink-0 cursor-grab touch-none text-gray-400 hover:text-gray-600 active:cursor-grabbing"
                aria-label="Arrastar para reordenar"
                @pointerdown="aoPointerDownHandle($event, indice)"
              >
                <svg class="h-5 w-5" fill="currentColor" viewBox="0 0 20 20">
                  <path d="M7 4a1 1 0 100 2 1 1 0 000-2zm0 5a1 1 0 100 2 1 1 0 000-2zm0 5a1 1 0 100 2 1 1 0 000-2zm6-10a1 1 0 100 2 1 1 0 000-2zm0 5a1 1 0 100 2 1 1 0 000-2zm0 5a1 1 0 100 2 1 1 0 000-2z" />
                </svg>
              </button>

              <span class="mt-0.5 flex h-6 w-6 flex-shrink-0 items-center justify-center rounded-full bg-green-600 text-xs font-bold text-white">
                {{ parada.ordem }}
              </span>

              <div class="min-w-0 flex-1">
                <p class="text-sm font-medium text-gray-900">{{ parada.cliente || 'Cliente não informado' }}</p>
                <p class="text-sm text-gray-500">{{ parada.endereco || 'Endereço não informado' }}</p>
                <div class="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500">
                  <span>Chegada prevista: {{ formatarHora(parada.chegada_estimada) || '-' }}</span>
                  <span>Distância da anterior: {{ formatarKm(parada.distancia_anterior_km) ?? 'sem referência' }}</span>
                </div>
              </div>
            </li>
          </ul>
        </Card>

        <!-- Lista compacta (impressão) -->
        <table class="hidden w-full text-xs print:table">
          <thead>
            <tr class="border-b border-gray-400 text-left">
              <th class="py-1 pr-2">#</th>
              <th class="py-1 pr-2">Cliente</th>
              <th class="py-1 pr-2">Endereço</th>
              <th class="py-1 pr-2">Horário</th>
              <th class="py-1">Distância</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="parada in rota.paradas" :key="parada.route_stop_id" class="border-b border-gray-200">
              <td class="py-1 pr-2">{{ parada.ordem }}</td>
              <td class="py-1 pr-2">{{ parada.cliente || '-' }}</td>
              <td class="py-1 pr-2">{{ parada.endereco || '-' }}</td>
              <td class="py-1 pr-2">{{ formatarHora(parada.chegada_estimada) || '-' }}</td>
              <td class="py-1">{{ formatarKm(parada.distancia_anterior_km) ?? 'sem referência' }}</td>
            </tr>
          </tbody>
        </table>
      </template>

      <div v-else-if="!carregando" class="py-8 text-center text-sm text-gray-500 print:hidden">
        {{ restritoAoProprioTecnico ? 'Selecione uma data para ver o roteiro.' : 'Selecione a data e o técnico para ver o roteiro.' }}
      </div>
    </div>

    <!-- Modal de otimização: mostra o ganho ANTES de aplicar -->
    <Modal :show="mostrarModalOtimizar" @close="fecharModalOtimizar">
      <template #icon>
        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7l5 5m0 0l-5 5m5-5H6" />
        </svg>
      </template>
      <template #title>Otimizar ordem do roteiro</template>
      <template #content>
        <p class="text-sm text-gray-600 mb-4">
          A otimização substitui a ordem manual atual das paradas por uma ordem calculada por proximidade.
          Confira o ganho antes de aplicar.
        </p>

        <div v-if="simulando" class="text-sm text-gray-500">Calculando a otimização...</div>

        <div v-else-if="erroOtimizacao" class="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-800">
          {{ erroOtimizacao }}
        </div>

        <div v-else-if="comparacao" class="space-y-2">
          <p class="text-sm text-gray-900">
            Ordem atual: <strong>{{ formatarKm(comparacao.atual.distancia_total_km) ?? 'sem ordem atual registrada' }}</strong>.
            Ordem otimizada: <strong>{{ formatarKm(comparacao.otimizada.distancia_total_km) }}</strong>.
          </p>
          <p v-if="ganhoKm !== null" class="text-sm" :class="ganhoKm > 0 ? 'text-green-700' : 'text-gray-600'">
            <template v-if="ganhoKm > 0">Redução de {{ formatarKm(ganhoKm) }} em relação à ordem atual.</template>
            <template v-else-if="ganhoKm < 0">Aumento de {{ formatarKm(Math.abs(ganhoKm)) }} em relação à ordem atual.</template>
            <template v-else>Sem alteração na distância total.</template>
          </p>
        </div>
      </template>
      <template #actions>
        <button
          type="button"
          class="btn-primary sm:ml-3"
          :disabled="simulando || aplicandoOtimizacao || !comparacao"
          @click="confirmarOtimizacao"
        >
          {{ aplicandoOtimizacao ? 'Aplicando...' : 'Aplicar otimização' }}
        </button>
        <button type="button" class="btn-secondary mt-3 sm:mt-0" @click="fecharModalOtimizar">
          Cancelar
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
/**
 * Roteiro do dia por técnico: lista reordenável, mapa e otimização com
 * comparação antes de aplicar (Plano 22, Task 22.6).
 *
 * Página "casca" + fetch: `RouteController::painel()` só entrega o
 * necessário para montar os filtros (lista de técnicos, data inicial); o
 * roteiro do dia em si vem de `GET /roteiros` (JSON, contrato da Task 22.5),
 * buscado aqui a cada troca de data/técnico - mesmo padrão de
 * `Agenda/Index.vue` (`AgendaController::index()` renderiza a casca,
 * `AgendaController::dados()` é o JSON que a página busca em seguida).
 *
 * Reordenar arrastando usa Pointer Events (mesmo mecanismo de
 * `ListaDeNaoPosicionados.vue`, Plano 21): funciona em mouse, caneta e
 * toque, sem depender do `dragstart` do HTML5 Drag and Drop, que a
 * `ListaDeNaoPosicionados.vue` já documenta como pouco confiável em tela de
 * toque - relevante aqui porque o coordenador pode montar o roteiro do
 * tablet em campo, não só no computador do escritório.
 */
import { computed, onMounted, ref, watch } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import MapaDeVisitas from '@/Components/MapaDeVisitas.vue';
import { formatarData, formatarDataHora, formatarHora } from '@/utils/formatDate';
import { usePermissoes } from '@/Composables/usePermissoes';

const props = defineProps({
  tecnicos: { type: Array, default: () => [] },
  restritoAoProprioTecnico: { type: Boolean, default: false },
  technicianIdProprio: { type: [Number, String], default: null },
  dataInicial: { type: String, required: true },
});

const { pode } = usePermissoes();
const podeGerenciar = computed(() => pode('roteiro-gerenciar'));

// --- Filtros e carga do roteiro ---

const data = ref(props.dataInicial);
const technicianId = ref(props.restritoAoProprioTecnico ? '' : '');

const technicianIdEfetivo = computed(() => {
  if (props.restritoAoProprioTecnico) {
    return props.technicianIdProprio ?? null;
  }

  return technicianId.value || null;
});

const nomeTecnicoSelecionado = computed(() => {
  if (!technicianIdEfetivo.value) return '';
  const tecnico = props.tecnicos.find((item) => String(item.id) === String(technicianIdEfetivo.value));
  return tecnico?.name || '';
});

const rota = ref(null);
const carregando = ref(false);
const erro = ref('');

async function carregarRoteiro() {
  if (!data.value || !technicianIdEfetivo.value) {
    rota.value = null;
    return;
  }

  carregando.value = true;
  erro.value = '';

  try {
    const parametros = new URLSearchParams({ data: data.value, technician_id: String(technicianIdEfetivo.value) });
    const resposta = await fetch(`${route('roteiros.index')}?${parametros.toString()}`, {
      headers: { Accept: 'application/json' },
    });

    if (!resposta.ok) {
      const corpo = await resposta.json().catch(() => null);
      erro.value = corpo?.message || 'Não foi possível carregar o roteiro.';
      rota.value = null;
      return;
    }

    rota.value = await resposta.json();
  } catch {
    erro.value = 'Não foi possível carregar o roteiro. Verifique sua conexão.';
    rota.value = null;
  } finally {
    carregando.value = false;
  }
}

onMounted(carregarRoteiro);
watch([data, technicianIdEfetivo], carregarRoteiro);

// --- Totais do dia: nunca "0 km" para um valor nulo (regra da Task 22.6) ---

function formatarKm(valor) {
  if (valor === null || valor === undefined) return null;
  return `${valor.toFixed(1).replace('.', ',')} km`;
}

function formatarMinutos(minutos) {
  if (minutos === null || minutos === undefined) return null;
  if (minutos === 0) return '0 min';

  const horas = Math.floor(minutos / 60);
  const resto = minutos % 60;

  if (horas === 0) return `${resto} min`;
  return resto === 0 ? `${horas}h` : `${horas}h${resto}min`;
}

const distanciaTotalTexto = computed(() => formatarKm(rota.value?.distancia_total_km ?? null));
const duracaoTotalTexto = computed(() => formatarMinutos(rota.value?.duracao_estimada_min ?? null));
const reordenadaEmTexto = computed(() => (rota.value?.reordenada_em ? formatarDataHora(rota.value.reordenada_em) : ''));

// --- Lista reordenável (arrastar e soltar por Pointer Events) ---

const paradas = ref([]);
watch(rota, (novaRota) => {
  paradas.value = novaRota?.paradas ? [...novaRota.paradas] : [];
}, { immediate: true });

// Sempre atribui, inclusive `null`: Vue chama a função de ref com `null`
// quando o item some da lista (reordenação aplicada pelo servidor,
// otimização), e sem isso um índice removido ficaria com o nó DOM antigo
// preso em `itemRefs`, quebrando o cálculo de posição do próximo arrasto.
const itemRefs = ref([]);
function setItemRef(elemento, indice) {
  itemRefs.value[indice] = elemento || null;
}

const indiceArrastando = ref(null);
const salvandoOrdem = ref(false);
let pointerIdAtivo = null;

function aoPointerDownHandle(evento, indice) {
  pointerIdAtivo = evento.pointerId;
  indiceArrastando.value = indice;

  window.addEventListener('pointermove', aoPointerMove);
  window.addEventListener('pointerup', aoPointerUp);
  window.addEventListener('pointercancel', aoPointerCancelar);
  evento.preventDefault();
}

function aoPointerMove(evento) {
  if (indiceArrastando.value === null || evento.pointerId !== pointerIdAtivo) return;

  const indiceAlvo = itemRefs.value.findIndex((elemento, indice) => {
    if (!elemento || indice === indiceArrastando.value) return false;
    const retangulo = elemento.getBoundingClientRect();
    return evento.clientY < retangulo.top + retangulo.height / 2;
  });

  const novoIndice = indiceAlvo === -1 ? paradas.value.length - 1 : indiceAlvo;

  if (novoIndice !== indiceArrastando.value) {
    const lista = [...paradas.value];
    const [item] = lista.splice(indiceArrastando.value, 1);
    lista.splice(novoIndice, 0, item);
    paradas.value = lista;
    indiceArrastando.value = novoIndice;
  }
}

async function aoPointerUp(evento) {
  if (evento.pointerId !== pointerIdAtivo) return;
  limparOuvintesDeArrasto();
  await persistirOrdem();
}

function aoPointerCancelar() {
  limparOuvintesDeArrasto();
  paradas.value = rota.value?.paradas ? [...rota.value.paradas] : [];
}

function limparOuvintesDeArrasto() {
  indiceArrastando.value = null;
  pointerIdAtivo = null;
  window.removeEventListener('pointermove', aoPointerMove);
  window.removeEventListener('pointerup', aoPointerUp);
  window.removeEventListener('pointercancel', aoPointerCancelar);
}

async function persistirOrdem() {
  if (!rota.value) return;

  salvandoOrdem.value = true;
  erro.value = '';

  try {
    const resposta = await fetch(route('roteiros.ordem', rota.value.id), {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ work_order_ids: paradas.value.map((parada) => parada.work_order_id) }),
    });

    if (!resposta.ok) {
      const corpo = await resposta.json().catch(() => null);
      erro.value = corpo?.message || 'Não foi possível salvar a nova ordem.';
      paradas.value = rota.value.paradas ? [...rota.value.paradas] : [];
      return;
    }

    rota.value = await resposta.json();
  } catch {
    erro.value = 'Não foi possível salvar a nova ordem. Verifique sua conexão.';
    paradas.value = rota.value.paradas ? [...rota.value.paradas] : [];
  } finally {
    salvandoOrdem.value = false;
  }
}

// --- Otimização: sempre mostra o ganho antes de aplicar ---

const mostrarModalOtimizar = ref(false);
const simulando = ref(false);
const aplicandoOtimizacao = ref(false);
const erroOtimizacao = ref('');
const comparacao = ref(null);

const ganhoKm = computed(() => {
  if (!comparacao.value || comparacao.value.atual.distancia_total_km === null) return null;
  return Math.round((comparacao.value.atual.distancia_total_km - comparacao.value.otimizada.distancia_total_km) * 100) / 100;
});

async function abrirModalOtimizar() {
  if (!rota.value || !technicianIdEfetivo.value) return;

  mostrarModalOtimizar.value = true;
  comparacao.value = null;
  erroOtimizacao.value = '';
  simulando.value = true;

  try {
    const resposta = await fetch(route('roteiros.simular'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ data: data.value, technician_id: technicianIdEfetivo.value }),
    });

    if (!resposta.ok) {
      const corpo = await resposta.json().catch(() => null);
      erroOtimizacao.value = corpo?.message || 'Não foi possível simular a otimização.';
      return;
    }

    comparacao.value = await resposta.json();
  } catch {
    erroOtimizacao.value = 'Não foi possível simular a otimização. Verifique sua conexão.';
  } finally {
    simulando.value = false;
  }
}

async function confirmarOtimizacao() {
  if (!rota.value) return;

  aplicandoOtimizacao.value = true;
  erroOtimizacao.value = '';

  try {
    const resposta = await fetch(route('roteiros.otimizar'), {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ data: data.value, technician_id: technicianIdEfetivo.value }),
    });

    if (!resposta.ok) {
      const corpo = await resposta.json().catch(() => null);
      erroOtimizacao.value = corpo?.message || 'Não foi possível aplicar a otimização.';
      return;
    }

    rota.value = await resposta.json();
    mostrarModalOtimizar.value = false;
    comparacao.value = null;
  } catch {
    erroOtimizacao.value = 'Não foi possível aplicar a otimização. Verifique sua conexão.';
  } finally {
    aplicandoOtimizacao.value = false;
  }
}

function fecharModalOtimizar() {
  if (aplicandoOtimizacao.value) return;
  mostrarModalOtimizar.value = false;
  comparacao.value = null;
  erroOtimizacao.value = '';
}

// --- Impressão: cabe em uma página (lista compacta, sem mapa nem controles) ---

function imprimir() {
  window.print();
}
</script>
