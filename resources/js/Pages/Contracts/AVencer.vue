<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Contratos a vencer"
        description="Fila de decisão de renovação: o que já venceu sem decisão, o que está em negociação e o que se aproxima do vencimento"
      />
    </template>

    <div class="max-w-6xl mx-auto space-y-6">
      <Alert
        v-if="mensagemErro"
        :key="alertaChave"
        type="error"
        title="Não foi possível concluir a ação"
        :message="mensagemErro"
      />

      <!-- Vencido sem decisão: sempre o primeiro bloco da tela, inclusive vazio.
           Enterrar esta lista embaixo anularia o motivo do painel existir. -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200 bg-red-50 flex items-center justify-between flex-wrap gap-2">
          <div>
            <h3 class="text-base font-semibold text-red-900">Vencido sem decisão</h3>
            <p class="text-sm text-red-800 mt-1">
              Vigência já terminou e nenhuma decisão de renovação foi encerrada para este contrato.
            </p>
          </div>
          <span class="text-xs font-medium text-red-700 bg-red-100 rounded-full px-2.5 py-1">
            {{ vencidosSemDecisao.length }}
          </span>
        </div>

        <div v-if="vencidosSemDecisao.length === 0" class="p-6 text-sm text-gray-500">
          Nenhum contrato vencido sem decisão.
        </div>
        <ul v-else class="divide-y divide-gray-200">
          <li
            v-for="item in vencidosSemDecisao"
            :key="item.id"
            class="px-6 py-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
          >
            <div>
              <p class="text-sm font-medium text-gray-900">
                {{ item.cliente || 'Cliente não identificado' }}
                <span class="text-gray-500 font-normal">· {{ item.contract_number || `Contrato #${item.id}` }}</span>
              </p>
              <p v-if="item.endereco" class="text-sm text-gray-600 mt-0.5">{{ item.endereco }}</p>
              <p class="text-sm text-gray-600 mt-0.5">
                Fim da vigência: {{ formatarData(item.end_date) }}
                <span
                  class="ml-2 inline-flex px-2 py-0.5 rounded-full text-xs font-medium"
                  :class="classeDias(diasDe(item))"
                >
                  {{ rotuloDias(diasDe(item)) }}
                </span>
                <span
                  v-if="item.situacao_renovacao === 'pendente'"
                  class="ml-2 inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800"
                >
                  Pendente
                </span>
              </p>
            </div>

            <div v-if="pode('contrato-renovar')" class="flex flex-wrap gap-2 flex-shrink-0">
              <button type="button" class="btn-secondary-sm bg-green-600 hover:bg-green-700" @click="abrirRenovacao(item)">
                Renovar
              </button>
              <button type="button" class="btn-secondary-sm bg-red-500 hover:bg-red-600" @click="abrirNaoRenovar(item)">
                Não renovar
              </button>
              <button
                type="button"
                class="btn-secondary-sm"
                :disabled="marcandoNegociacao === item.id"
                @click="marcarEmNegociacao(item)"
              >
                {{ marcandoNegociacao === item.id ? 'Marcando...' : 'Em negociação' }}
              </button>
            </div>
          </li>
        </ul>
      </Card>

      <!-- Em negociação: recorte por situação, entre os itens vencidos e os
           que ainda estão dentro de um marco de aviso. -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200 bg-blue-50 flex items-center justify-between flex-wrap gap-2">
          <div>
            <h3 class="text-base font-semibold text-blue-900">Em negociação</h3>
            <p class="text-sm text-blue-800 mt-1">
              Conversa de renovação em andamento com o cliente. O aviso semanal de vencimento fica pausado por 30 dias
              enquanto durar.
            </p>
          </div>
          <span class="text-xs font-medium text-blue-700 bg-blue-100 rounded-full px-2.5 py-1">
            {{ emNegociacao.length }}
          </span>
        </div>

        <div v-if="emNegociacao.length === 0" class="p-6 text-sm text-gray-500">Nenhum contrato em negociação.</div>
        <ul v-else class="divide-y divide-gray-200">
          <li
            v-for="item in emNegociacao"
            :key="item.id"
            class="px-6 py-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
          >
            <div>
              <p class="text-sm font-medium text-gray-900">
                {{ item.cliente || 'Cliente não identificado' }}
                <span class="text-gray-500 font-normal">· {{ item.contract_number || `Contrato #${item.id}` }}</span>
              </p>
              <p v-if="item.endereco" class="text-sm text-gray-600 mt-0.5">{{ item.endereco }}</p>
              <p class="text-sm text-gray-600 mt-0.5">
                Fim da vigência: {{ formatarData(item.end_date) }}
                <span
                  class="ml-2 inline-flex px-2 py-0.5 rounded-full text-xs font-medium"
                  :class="classeDias(diasDe(item))"
                >
                  {{ rotuloDias(diasDe(item)) }}
                </span>
              </p>
            </div>

            <div v-if="pode('contrato-renovar')" class="flex flex-wrap gap-2 flex-shrink-0">
              <button type="button" class="btn-secondary-sm bg-green-600 hover:bg-green-700" @click="abrirRenovacao(item)">
                Renovar
              </button>
              <button type="button" class="btn-secondary-sm bg-red-500 hover:bg-red-600" @click="abrirNaoRenovar(item)">
                Não renovar
              </button>
            </div>
          </li>
        </ul>
      </Card>

      <!-- Vence em breve: um marco de aviso configurado por sub-seção. -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200 bg-gray-50">
          <h3 class="text-base font-semibold text-gray-900">Vence em até {{ maiorMarco }} dias</h3>
          <p class="text-sm text-gray-600 mt-1">
            Contratos que cruzam um marco de antecedência configurado, ainda sem decisão de renovação encerrada.
          </p>
        </div>

        <template v-for="grupo in gruposPorMarco" :key="grupo.marco">
          <div class="px-6 py-3 border-b border-gray-200 bg-gray-50 flex items-center justify-between">
            <h4 class="text-sm font-semibold text-gray-700">Faltam {{ grupo.marco }} dia(s)</h4>
            <span class="text-xs text-gray-500">{{ grupo.itens.length }} contrato(s)</span>
          </div>

          <div v-if="grupo.itens.length === 0" class="px-6 py-4 text-sm text-gray-500">
            Nenhum contrato vence em {{ grupo.marco }} dias.
          </div>
          <ul v-else class="divide-y divide-gray-200">
            <li
              v-for="item in grupo.itens"
              :key="item.id"
              class="px-6 py-4 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
              <div>
                <p class="text-sm font-medium text-gray-900">
                  {{ item.cliente || 'Cliente não identificado' }}
                  <span class="text-gray-500 font-normal">· {{ item.contract_number || `Contrato #${item.id}` }}</span>
                </p>
                <p v-if="item.endereco" class="text-sm text-gray-600 mt-0.5">{{ item.endereco }}</p>
                <p class="text-sm text-gray-600 mt-0.5">
                  Fim da vigência: {{ formatarData(item.end_date) }}
                  <span
                    class="ml-2 inline-flex px-2 py-0.5 rounded-full text-xs font-medium"
                    :class="classeDias(diasDe(item))"
                  >
                    {{ rotuloDias(diasDe(item)) }}
                  </span>
                  <span
                    v-if="item.situacao_renovacao === 'pendente'"
                    class="ml-2 inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800"
                  >
                    Pendente
                  </span>
                </p>
              </div>

              <div v-if="pode('contrato-renovar')" class="flex flex-wrap gap-2 flex-shrink-0">
                <button type="button" class="btn-secondary-sm bg-green-600 hover:bg-green-700" @click="abrirRenovacao(item)">
                  Renovar
                </button>
                <button type="button" class="btn-secondary-sm bg-red-500 hover:bg-red-600" @click="abrirNaoRenovar(item)">
                  Não renovar
                </button>
                <button
                  type="button"
                  class="btn-secondary-sm"
                  :disabled="marcandoNegociacao === item.id"
                  @click="marcarEmNegociacao(item)"
                >
                  {{ marcandoNegociacao === item.id ? 'Marcando...' : 'Em negociação' }}
                </button>
              </div>
            </li>
          </ul>
        </template>
      </Card>
    </div>

    <RenovacaoModal
      :show="mostrarRenovacao"
      :contract="contratoEmRenovacao"
      @close="mostrarRenovacao = false"
      @renovado="aoRenovar"
    />

    <!-- Não renovar: lista fechada de motivos, com texto livre obrigatório só
         para "outro". Nunca `confirm()` nativo, mesmo sendo uma decisão
         irreversível na prática. -->
    <Modal :show="mostrarNaoRenovar" @close="fecharNaoRenovar">
      <template #icon>
        <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M6 18L18 6M6 6l12 12"
          />
        </svg>
      </template>
      <template #title>
        Não renovar contrato {{ contratoNaoRenovar?.contract_number || (contratoNaoRenovar ? `#${contratoNaoRenovar.id}` : '') }}
      </template>
      <template #content>
        <p class="text-sm text-gray-700 mb-4">
          Isso encerra a tratativa deste contrato como não renovado. Cliente {{ contratoNaoRenovar?.cliente || '-' }}.
        </p>

        <label class="block text-sm font-medium text-gray-700 mb-2">Motivo *</label>
        <div class="space-y-2 mb-4">
          <label
            v-for="opcao in MOTIVOS_NAO_RENOVACAO"
            :key="opcao.valor"
            class="flex items-center gap-2 text-sm text-gray-700"
          >
            <input
              v-model="motivoNaoRenovar"
              type="radio"
              name="motivo_nao_renovacao"
              :value="opcao.valor"
              class="h-4 w-4 text-green-600 border-gray-300"
            />
            {{ opcao.rotulo }}
          </label>
        </div>

        <div v-if="motivoNaoRenovar === 'outro'" class="mb-2">
          <label class="block text-sm font-medium text-gray-700 mb-1">Descreva o motivo *</label>
          <textarea
            v-model="motivoLivreNaoRenovar"
            rows="3"
            maxlength="1000"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          ></textarea>
        </div>

        <p v-if="erroNaoRenovar" class="mt-2 text-sm text-red-600">{{ erroNaoRenovar }}</p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="enviandoNaoRenovar" @click="fecharNaoRenovar">
          Cancelar
        </button>
        <button type="button" class="btn-danger ml-3" :disabled="enviandoNaoRenovar" @click="confirmarNaoRenovar">
          {{ enviandoNaoRenovar ? 'Salvando...' : 'Confirmar não renovação' }}
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
/**
 * Painel de contratos a vencer (Plano 23, Task 23.8), sobre
 * `ContractRenewalController::aVencer()` (Task 23.6) e `ContractAlertService`
 * (Task 23.5).
 *
 * ## Os três blocos não são as três chaves do payload
 *
 * O backend devolve só duas formas: `a_vencer` (por marco, contratos ainda
 * vigentes) e `vencidos_sem_tratativa` (contratos já vencidos) - as duas
 * incluem `situacao_renovacao` `null`, `pendente` OU `em_negociacao` de
 * propósito (ver o docblock de `ContractAlertService::vencidosSemTratativa()`).
 * O terceiro bloco pedido pela Task ("Em negociação") é um recorte por
 * situação sobre as duas formas, feito aqui: todo item com
 * `situacao_renovacao === 'em_negociacao'` sai de "Vencido sem decisão" e de
 * "Vence em até X dias" e aparece só em "Em negociação", para não duplicar o
 * mesmo contrato em duas seções.
 *
 * ## Valor do contrato não aparece nesta lista
 *
 * O payload de `aVencer()` não traz o valor do contrato (só
 * `RenovacaoModal.vue`, via `previa`, o recebe). Mostrar o valor aqui exigiria
 * uma chamada extra por linha só para preencher uma coluna que a maior parte
 * do fluxo nem chega a olhar; ele aparece no lugar em que decide algo: dentro
 * do modal de renovação, ao lado do valor já reajustado.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Alert from '@/Components/Alert.vue';
import Modal from '@/Components/Modal.vue';
import RenovacaoModal from '@/Components/RenovacaoModal.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { formatarData, diasAte } from '@/utils/formatDate';

const props = defineProps({
  marcos: { type: Array, default: () => [] },
  a_vencer: { type: Object, default: () => ({}) },
  vencidos_sem_tratativa: { type: Array, default: () => [] },
});

const { pode } = usePermissoes();

const MOTIVOS_NAO_RENOVACAO = [
  { valor: 'preco', rotulo: 'Preço' },
  { valor: 'mudou_fornecedor', rotulo: 'Mudou de fornecedor' },
  { valor: 'encerrou_atividade', rotulo: 'Encerrou a atividade' },
  { valor: 'insatisfacao_servico', rotulo: 'Insatisfação com o serviço' },
  { valor: 'outro', rotulo: 'Outro' },
];

function ehEmNegociacao(item) {
  return item.situacao_renovacao === 'em_negociacao';
}

const vencidosSemDecisao = computed(() => props.vencidos_sem_tratativa.filter((item) => !ehEmNegociacao(item)));

const maiorMarco = computed(() => (props.marcos.length ? Math.max(...props.marcos) : 0));

// Do maior para o menor: o marco com mais folga aparece primeiro.
const gruposPorMarco = computed(() =>
  [...props.marcos]
    .sort((a, b) => b - a)
    .map((marco) => ({
      marco,
      itens: (props.a_vencer[marco] ?? []).filter((item) => !ehEmNegociacao(item)),
    }))
);

// Une os itens em negociação vindos das duas formas do payload, sem duplicar
// por id (por construção não deveria colidir - `a_vencer` só traz data exata
// futura e `vencidos_sem_tratativa` só traz data passada - mas o mapa é uma
// proteção barata contra qualquer sobreposição).
const emNegociacao = computed(() => {
  const doVencidos = props.vencidos_sem_tratativa.filter(ehEmNegociacao);
  const doAVencer = props.marcos.flatMap((marco) => (props.a_vencer[marco] ?? []).filter(ehEmNegociacao));

  const mapa = new Map();
  [...doVencidos, ...doAVencer].forEach((item) => mapa.set(item.id, item));

  return Array.from(mapa.values());
});

// -----------------------------------------------------------------
// Dias restantes / dias vencidos, calculado no frontend a partir de
// `end_date`, com o utilitário de fuso do projeto (nunca `toLocaleDateString`
// nem `new Date('yyyy-mm-dd')` direto).
// -----------------------------------------------------------------

function diasDe(item) {
  return diasAte(item.end_date);
}

function rotuloDias(dias) {
  if (dias === null) return 'Sem data de fim';
  if (dias > 0) return `Faltam ${dias} dia(s)`;
  if (dias === 0) return 'Vence hoje';

  return `Vencido há ${Math.abs(dias)} dia(s)`;
}

function classeDias(dias) {
  if (dias === null) return 'bg-gray-100 text-gray-600';
  if (dias <= 0) return 'bg-red-100 text-red-800';
  if (dias <= 15) return 'bg-yellow-100 text-yellow-800';

  return 'bg-gray-100 text-gray-700';
}

// -----------------------------------------------------------------
// Erro de ação disparada fora de modal (marcar em negociação)
// -----------------------------------------------------------------

const mensagemErro = ref('');
const alertaChave = ref(0);

function reportarErro(mensagem) {
  mensagemErro.value = mensagem;
  alertaChave.value += 1;
}

function recarregarLista() {
  router.reload({ only: ['a_vencer', 'vencidos_sem_tratativa'] });
}

// -----------------------------------------------------------------
// Renovar: abre o modal comum, que já busca a prévia antes de deixar
// confirmar. `contracts.renovar` devolve JSON puro (nunca resposta Inertia),
// por isso o modal usa `fetch`, não `router.post`.
// -----------------------------------------------------------------

const mostrarRenovacao = ref(false);
const contratoEmRenovacao = ref(null);

function abrirRenovacao(item) {
  contratoEmRenovacao.value = item;
  mostrarRenovacao.value = true;
}

function aoRenovar() {
  recarregarLista();
}

// -----------------------------------------------------------------
// Em negociação: sem modal (ação não destrutiva, só troca uma situação).
// Mesmo padrão de `fetch` + recarga usado em `Contracts/Pendencias.vue` para
// os endpoints que devolvem JSON puro, não resposta Inertia.
// -----------------------------------------------------------------

const marcandoNegociacao = ref(null);

async function marcarEmNegociacao(item) {
  marcandoNegociacao.value = item.id;

  try {
    const resposta = await fetch(route('contracts.em-negociacao', item.id), {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        Accept: 'application/json',
      },
    });

    const dados = await resposta.json();

    if (resposta.ok && dados.success) {
      recarregarLista();
    } else {
      reportarErro(dados.message || 'Não foi possível marcar como em negociação.');
    }
  } catch (erro) {
    reportarErro('Não foi possível marcar como em negociação.');
  } finally {
    marcandoNegociacao.value = null;
  }
}

// -----------------------------------------------------------------
// Não renovar: motivo da lista fechada, texto livre obrigatório só para
// "outro" (mesma regra de `ContractNaoRenovarRequest`, antecipada aqui para
// não fazer o usuário esperar o round-trip para ver o erro).
// -----------------------------------------------------------------

const mostrarNaoRenovar = ref(false);
const contratoNaoRenovar = ref(null);
const motivoNaoRenovar = ref('');
const motivoLivreNaoRenovar = ref('');
const enviandoNaoRenovar = ref(false);
const erroNaoRenovar = ref('');

function abrirNaoRenovar(item) {
  contratoNaoRenovar.value = item;
  motivoNaoRenovar.value = '';
  motivoLivreNaoRenovar.value = '';
  erroNaoRenovar.value = '';
  mostrarNaoRenovar.value = true;
}

function fecharNaoRenovar() {
  if (enviandoNaoRenovar.value) return;

  mostrarNaoRenovar.value = false;
}

async function confirmarNaoRenovar() {
  if (!motivoNaoRenovar.value) {
    erroNaoRenovar.value = 'Selecione um motivo.';
    return;
  }

  if (motivoNaoRenovar.value === 'outro' && !motivoLivreNaoRenovar.value.trim()) {
    erroNaoRenovar.value = 'Descreva o motivo quando escolher "Outro".';
    return;
  }

  enviandoNaoRenovar.value = true;
  erroNaoRenovar.value = '';

  try {
    const resposta = await fetch(route('contracts.nao-renovar', contratoNaoRenovar.value.id), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        Accept: 'application/json',
      },
      body: JSON.stringify({
        motivo: motivoNaoRenovar.value,
        motivo_livre: motivoNaoRenovar.value === 'outro' ? motivoLivreNaoRenovar.value.trim() : undefined,
      }),
    });

    const dados = await resposta.json();

    if (resposta.ok && dados.success) {
      mostrarNaoRenovar.value = false;
      recarregarLista();
    } else {
      erroNaoRenovar.value = dados.message || 'Não foi possível registrar a não renovação.';
    }
  } catch (erro) {
    erroNaoRenovar.value = 'Não foi possível registrar a não renovação.';
  } finally {
    enviandoNaoRenovar.value = false;
  }
}
</script>
