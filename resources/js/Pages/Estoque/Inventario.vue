<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        :title="modoContagem ? `Contagem - ${inventario.local}` : 'Inventário'"
        :description="modoContagem
          ? `Aberto por ${inventario.aberto_por} em ${formatarData(inventario.aberto_em)}`
          : 'Contagem física do estoque, sempre com ajuste justificado.'"
      >
        <template #actions>
          <Link v-if="modoContagem" :href="route('inventarios.index')" class="btn-secondary">
            Voltar à lista
          </Link>
          <button v-else type="button" class="btn-primary" @click="abrirModalDeAbertura">
            Abrir inventário
          </button>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-3xl mx-auto space-y-4 pb-10">
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!-- ================= LISTA DE INVENTÁRIOS ================= -->
      <template v-if="!modoContagem">
        <Card v-if="inventarios.length === 0" padding="large">
          <p class="text-sm text-gray-500 text-center">
            Nenhum inventário aberto ainda. Escolha um local e comece a contagem.
          </p>
        </Card>

        <Card v-else padding="none">
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Local</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Situação</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Progresso</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Aberto por</th>
                  <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider"></th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr v-for="linha in inventarios" :key="linha.id" class="hover:bg-gray-50">
                  <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900">{{ linha.local }}</td>
                  <td class="px-4 py-4 whitespace-nowrap">
                    <span class="px-2 py-1 text-xs font-medium rounded-full" :class="classeSituacao(linha.situacao)">
                      {{ rotuloSituacao(linha.situacao) }}
                    </span>
                  </td>
                  <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">
                    {{ linha.itens_contados }} de {{ linha.itens_total }} itens
                  </td>
                  <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-600">
                    {{ linha.aberto_por }}<br>
                    <span class="text-xs text-gray-400">{{ formatarData(linha.aberto_em) }}</span>
                  </td>
                  <td class="px-4 py-4 whitespace-nowrap text-right text-sm">
                    <Link :href="route('inventarios.show', linha.id)" class="text-green-600 hover:text-green-800 font-medium">
                      {{ linha.situacao === 'aberto' ? 'Continuar contagem' : 'Ver detalhe' }}
                    </Link>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </Card>
      </template>

      <!-- ================= TELA DE CONTAGEM ================= -->
      <template v-else>
        <!-- Contador de progresso -->
        <Card padding="small">
          <div class="flex items-center justify-between mb-2">
            <p class="text-sm font-medium text-gray-700">
              {{ itensContados }} de {{ itensTotal }} itens contados
            </p>
            <span class="text-sm font-semibold text-green-700">{{ progressoPct }}%</span>
          </div>
          <div class="w-full bg-gray-200 rounded-full h-2.5">
            <div class="bg-green-600 h-2.5 rounded-full transition-all" :style="{ width: progressoPct + '%' }"></div>
          </div>
        </Card>

        <div v-if="inventario.situacao !== 'aberto'" class="bg-blue-50 border border-blue-200 rounded-md p-4">
          <p class="text-sm text-blue-800">
            Este inventário está <strong>{{ rotuloSituacao(inventario.situacao) }}</strong> e não aceita mais alteração.
          </p>
        </div>

        <!-- Itens, um por linha (bloco), pensado para toque com uma mão -->
        <div class="space-y-3">
          <Card v-for="linha in linhas" :key="linha.id" padding="normal">
            <div class="flex items-start justify-between gap-2 mb-2">
              <div class="min-w-0">
                <p class="text-base font-semibold text-gray-900 truncate">{{ linha.produto }}</p>
                <p class="text-xs text-gray-500">
                  Lote: {{ linha.lote || 'sem lote' }}
                  <span v-if="linha.validade"> · Validade: {{ formatarData(linha.validade) }}</span>
                </p>
              </div>
              <span
                class="flex-shrink-0 px-2 py-1 text-xs font-medium rounded-full"
                :class="linha.contado ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'"
              >
                {{ linha.contado ? 'Contado' : 'Pendente' }}
              </span>
            </div>

            <!--
              Saldo do sistema fica oculto por padrão (regra de negócio explícita):
              quem conta vendo o número esperado tende a confirmar o número
              esperado, e isso não é contagem. O botão abaixo é a única forma de
              revelar o valor, linha a linha.
            -->
            <div class="mb-3">
              <button
                v-if="!linha.revelado"
                type="button"
                class="text-sm text-gray-500 underline decoration-dotted hover:text-gray-700"
                @click="linha.revelado = true"
                :disabled="inventario.situacao !== 'aberto'"
              >
                Revelar saldo do sistema
              </button>
              <p v-else class="text-sm text-gray-600">
                Saldo do sistema:
                <strong>{{ formatoNumero(linha.saldo_sistema) }} {{ linha.unidade }}</strong>
                <button type="button" class="ml-2 text-xs text-gray-400 underline" @click="linha.revelado = false">
                  ocultar
                </button>
              </p>
            </div>

            <label class="block text-sm font-medium text-gray-700 mb-1">Quantidade contada</label>
            <div class="flex items-center gap-2 mb-2">
              <input
                v-model="linha.contadoInput"
                type="number"
                inputmode="decimal"
                step="0.0001"
                min="0"
                :disabled="inventario.situacao !== 'aberto' || linha.salvando"
                class="w-full text-lg font-semibold text-center py-3 px-4 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                @blur="aoPerderFoco(linha)"
              />
              <span class="text-sm text-gray-500 w-12 flex-shrink-0">{{ linha.unidade }}</span>
            </div>

            <!--
              Justificativa aparece na própria linha assim que uma diferença é
              detectada, e é obrigatória antes de salvar aquela linha. A
              comparação usa `saldo_sistema` já carregado nesta tela (congelado
              na abertura do inventário) contra o valor digitado: como os dois
              lados partem do mesmo número que o backend usa em
              `InventoryService::contar()`, o cálculo local antecipa a decisão do
              backend sem round-trip a cada tecla. O PUT só sai quando a linha é
              salva, e o backend continua como fonte final da verdade (uma
              divergência de arredondamento aparece como 422 no lugar do sucesso).
            -->
            <div v-if="linha.mostrarJustificativa" class="mb-2">
              <label class="block text-sm font-medium text-gray-700 mb-1">
                Justificativa da diferença *
              </label>
              <textarea
                v-model="linha.justificativaInput"
                rows="2"
                :disabled="inventario.situacao !== 'aberto' || linha.salvando"
                placeholder="Explique o que foi encontrado na contagem física"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              ></textarea>
            </div>

            <p v-if="linha.erro" class="text-sm text-red-600 mb-2">{{ linha.erro }}</p>

            <button
              type="button"
              class="btn-primary w-full py-3 text-base"
              :disabled="inventario.situacao !== 'aberto' || linha.salvando"
              @click="salvarLinha(linha)"
            >
              {{ linha.salvando ? 'Salvando...' : (linha.contado ? 'Atualizar contagem' : 'Salvar contagem') }}
            </button>
          </Card>
        </div>

        <!-- Ações finais -->
        <div v-if="inventario.situacao === 'aberto'" class="space-y-2 pt-2">
          <button
            type="button"
            class="btn-primary w-full py-3 text-base"
            :disabled="itensContados < itensTotal"
            @click="abrirModalDeFinalizacao"
          >
            Finalizar inventário
          </button>
          <p v-if="itensContados < itensTotal" class="text-xs text-gray-500 text-center">
            Conte todos os itens para poder finalizar.
          </p>

          <button type="button" class="btn-secondary w-full" @click="modalCancelarVisivel = true">
            Cancelar inventário
          </button>
        </div>
      </template>
    </div>

    <!-- ================= MODAL: ABRIR INVENTÁRIO ================= -->
    <Modal :show="modalAberturaVisivel" @close="fecharModalDeAbertura">
      <template #icon>
        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2" />
        </svg>
      </template>
      <template #title>Abrir inventário</template>
      <template #content>
        <p class="text-sm text-gray-600 mb-4">
          O saldo do sistema é fotografado agora, item a item. O local continua
          operando normalmente durante a contagem.
        </p>

        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Local *</label>
            <select
              v-model="novoInventario.stock_location_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="" disabled>Selecione o local</option>
              <option v-for="local in locais" :key="local.id" :value="local.id">{{ local.nome }}</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Observação</label>
            <textarea
              v-model="novoInventario.observacao"
              rows="2"
              placeholder="Opcional"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            ></textarea>
          </div>

          <p v-if="erroAbertura" class="text-sm text-red-600">{{ erroAbertura }}</p>
        </div>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="abrindo" @click="fecharModalDeAbertura">Cancelar</button>
        <button type="button" class="btn-primary" :disabled="abrindo" @click="confirmarAbertura">
          {{ abrindo ? 'Abrindo...' : 'Abrir inventário' }}
        </button>
      </template>
    </Modal>

    <!-- ================= MODAL: FINALIZAR (resumo de divergências) ================= -->
    <Modal :show="modalFinalizarVisivel" @close="fecharModalDeFinalizacao">
      <template #icon>
        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </template>
      <template #title>Finalizar inventário</template>
      <template #content>
        <p class="text-sm text-gray-700 mb-4">
          Esta ação gera o ajuste de saldo para cada item divergente e não pode
          ser desfeita. Contagem errada exige um novo inventário.
        </p>

        <div v-if="divergencias.length === 0" class="rounded-md bg-green-50 border border-green-200 p-3 text-sm text-green-800">
          Nenhuma divergência: a contagem confirmou o saldo do sistema em todos os itens.
        </div>

        <div v-else class="space-y-3">
          <p class="text-sm font-medium text-gray-900">
            {{ divergencias.length }} de {{ itensTotal }} itens com divergência.
          </p>
          <div class="max-h-56 overflow-y-auto border border-gray-200 rounded-md divide-y divide-gray-100">
            <div v-for="linha in divergencias" :key="linha.id" class="px-3 py-2 flex items-center justify-between gap-2 text-sm">
              <div class="min-w-0">
                <p class="font-medium text-gray-900 truncate">{{ linha.produto }}</p>
                <p class="text-xs text-gray-500">{{ linha.lote || 'sem lote' }}</p>
              </div>
              <span class="flex-shrink-0 font-semibold" :class="Number(linha.diferenca) > 0 ? 'text-green-700' : 'text-red-700'">
                {{ Number(linha.diferenca) > 0 ? '+' : '' }}{{ formatoNumero(linha.diferenca) }} {{ linha.unidade }}
              </span>
            </div>
          </div>
          <p class="text-xs text-gray-500">
            Diferença positiva aumenta o saldo do local; negativa reduz.
          </p>
        </div>

        <p v-if="erroFinalizar" class="mt-3 text-sm text-red-600">{{ erroFinalizar }}</p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="finalizando" @click="fecharModalDeFinalizacao">Voltar</button>
        <button type="button" class="btn-primary" :disabled="finalizando" @click="confirmarFinalizacao">
          {{ finalizando ? 'Finalizando...' : 'Confirmar finalização' }}
        </button>
      </template>
    </Modal>

    <!-- ================= MODAL: CANCELAR ================= -->
    <ConfirmDeleteModal
      :show="modalCancelarVisivel"
      variant="warning"
      title="Cancelar inventário"
      message="Esta contagem será descartada e o saldo não será alterado. Deseja continuar?"
      confirm-text="Sim, cancelar"
      cancel-text="Voltar"
      processing-text="Cancelando..."
      :processing="cancelando"
      @cancel="modalCancelarVisivel = false"
      @confirm="confirmarCancelamento"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, reactive, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import { formatarData } from '@/utils/formatDate';

/**
 * Uma única página Inertia para as duas visões do inventário (lista e
 * contagem), no mesmo padrão do backend: `inventarios.index` e
 * `inventarios.show` (Task 17.7) renderizam os dois o componente
 * `Estoque/Inventario`, diferenciados pelas props que mandam. Isso evita duas
 * rotas Inertia para o mesmo componente e mantém consistência com o que o
 * controller já decidiu.
 */
const props = defineProps({
  inventarios: { type: Array, default: () => [] },
  locais: { type: Array, default: () => [] },
  inventario: { type: Object, default: null },
  itens: { type: Array, default: () => [] },
});

const modoContagem = computed(() => props.inventario !== null);

const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

// -----------------------------------------------------------------
// Apoio: rótulos e formatação
// -----------------------------------------------------------------

function classeSituacao(situacao) {
  return {
    aberto: 'bg-blue-100 text-blue-800',
    finalizado: 'bg-green-100 text-green-800',
    cancelado: 'bg-gray-100 text-gray-800',
  }[situacao] || 'bg-gray-100 text-gray-800';
}

function rotuloSituacao(situacao) {
  return {
    aberto: 'Aberto',
    finalizado: 'Finalizado',
    cancelado: 'Cancelado',
  }[situacao] || situacao;
}

function normalizar(valor) {
  return Math.round(valor * 10000) / 10000;
}

function formatoNumero(valor) {
  return Number(valor).toLocaleString('pt-BR', { minimumFractionDigits: 0, maximumFractionDigits: 4 });
}

// -----------------------------------------------------------------
// Tela de contagem
// -----------------------------------------------------------------

// Cópia local dos itens: o valor digitado, a justificativa, o estado de
// "revelado" e o de salvamento são estado de tela, não dado do servidor, e por
// isso vivem clonados aqui em vez de mutar a prop diretamente.
const linhas = ref(props.itens.map((item) => ({
  ...item,
  contadoInput: item.saldo_contado !== null ? String(item.saldo_contado) : '',
  justificativaInput: item.justificativa || '',
  revelado: false,
  salvando: false,
  erro: null,
  mostrarJustificativa: !!(item.contado && Number(item.diferenca) !== 0),
})));

const itensTotal = computed(() => linhas.value.length);
const itensContados = computed(() => linhas.value.filter((linha) => linha.contado).length);
const progressoPct = computed(() => (
  itensTotal.value === 0 ? 0 : Math.round((itensContados.value / itensTotal.value) * 100)
));

const divergencias = computed(() => linhas.value.filter((linha) => linha.contado && Number(linha.diferenca) !== 0));

/**
 * Ao perder o foco com valor preenchido, decide se a linha diverge e revela o
 * campo de justificativa. Compara contra `saldo_sistema` já carregado na tela
 * (o mesmo valor congelado que `InventoryService::contar()` usa no backend),
 * o que evita uma requisição a cada tecla digitada. O backend confirma (ou
 * recusa) a divergência de verdade só quando a linha é salva.
 */
function aoPerderFoco(linha) {
  linha.erro = null;

  const digitado = linha.contadoInput === '' ? null : Number(linha.contadoInput);

  if (digitado === null || Number.isNaN(digitado)) {
    linha.mostrarJustificativa = false;
    return;
  }

  linha.mostrarJustificativa = normalizar(digitado - linha.saldo_sistema) !== 0;
}

async function salvarLinha(linha) {
  const digitado = linha.contadoInput === '' ? null : Number(linha.contadoInput);

  if (digitado === null || Number.isNaN(digitado) || digitado < 0) {
    linha.erro = 'Informe a quantidade contada.';
    return;
  }

  const diferenca = normalizar(digitado - linha.saldo_sistema);

  if (diferenca !== 0 && !linha.justificativaInput.trim()) {
    linha.mostrarJustificativa = true;
    linha.erro = 'Toda diferença encontrada na contagem exige justificativa.';
    return;
  }

  linha.salvando = true;
  linha.erro = null;

  try {
    const resposta = await fetch(route('inventarios.itens.update', [props.inventario.id, linha.id]), {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
        Accept: 'application/json',
      },
      body: JSON.stringify({
        contado: digitado,
        justificativa: diferenca !== 0 ? linha.justificativaInput.trim() : null,
      }),
    });

    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) {
      linha.erro = dados.message || 'Não foi possível salvar a contagem desta linha.';
      return;
    }

    linha.saldo_contado = digitado;
    linha.diferenca = diferenca;
    linha.justificativa = diferenca !== 0 ? linha.justificativaInput.trim() : null;
    linha.contado = true;
  } catch (erro) {
    linha.erro = 'Falha de conexão ao salvar a contagem.';
  } finally {
    linha.salvando = false;
  }
}

// -----------------------------------------------------------------
// Abrir inventário (tela de lista)
// -----------------------------------------------------------------

const modalAberturaVisivel = ref(false);
const abrindo = ref(false);
const erroAbertura = ref(null);
const novoInventario = reactive({ stock_location_id: '', observacao: '' });

function abrirModalDeAbertura() {
  novoInventario.stock_location_id = props.locais[0]?.id ?? '';
  novoInventario.observacao = '';
  erroAbertura.value = null;
  modalAberturaVisivel.value = true;
}

function fecharModalDeAbertura() {
  if (abrindo.value) return;
  modalAberturaVisivel.value = false;
}

async function confirmarAbertura() {
  if (!novoInventario.stock_location_id) {
    erroAbertura.value = 'Selecione o local do inventário.';
    return;
  }

  abrindo.value = true;
  erroAbertura.value = null;

  try {
    const resposta = await fetch(route('inventarios.store'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
        Accept: 'application/json',
      },
      body: JSON.stringify({
        stock_location_id: novoInventario.stock_location_id,
        observacao: novoInventario.observacao || undefined,
      }),
    });

    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) {
      erroAbertura.value = dados.message || 'Não foi possível abrir o inventário.';
      return;
    }

    // Navega direto para a tela de contagem do inventário recém-aberto, em vez
    // de ficar na lista: quem acabou de abrir veio para contar.
    router.visit(route('inventarios.show', dados.inventario.id));
  } catch (erro) {
    erroAbertura.value = 'Falha de conexão ao abrir o inventário.';
  } finally {
    abrindo.value = false;
  }
}

// -----------------------------------------------------------------
// Finalizar (tela de contagem)
// -----------------------------------------------------------------

const modalFinalizarVisivel = ref(false);
const finalizando = ref(false);
const erroFinalizar = ref(null);

function abrirModalDeFinalizacao() {
  erroFinalizar.value = null;
  modalFinalizarVisivel.value = true;
}

function fecharModalDeFinalizacao() {
  if (finalizando.value) return;
  modalFinalizarVisivel.value = false;
}

async function confirmarFinalizacao() {
  finalizando.value = true;
  erroFinalizar.value = null;

  try {
    const resposta = await fetch(route('inventarios.finalizar', props.inventario.id), {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
    });

    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) {
      erroFinalizar.value = dados.message || 'Não foi possível finalizar o inventário.';
      return;
    }

    modalFinalizarVisivel.value = false;
    router.visit(route('inventarios.index'));
  } catch (erro) {
    erroFinalizar.value = 'Falha de conexão ao finalizar o inventário.';
  } finally {
    finalizando.value = false;
  }
}

// -----------------------------------------------------------------
// Cancelar (tela de contagem)
// -----------------------------------------------------------------

const modalCancelarVisivel = ref(false);
const cancelando = ref(false);

async function confirmarCancelamento() {
  cancelando.value = true;

  try {
    const resposta = await fetch(route('inventarios.cancelar', props.inventario.id), {
      method: 'POST',
      headers: { 'X-CSRF-TOKEN': csrfToken, Accept: 'application/json' },
    });

    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) {
      modalCancelarVisivel.value = false;
      router.visit(route('inventarios.show', props.inventario.id));
      return;
    }

    modalCancelarVisivel.value = false;
    router.visit(route('inventarios.index'));
  } finally {
    cancelando.value = false;
  }
}
</script>
