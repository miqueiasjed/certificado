<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Pendências de contratos"
        description="Contratos periódicos vigentes com pendência de conformidade (RDC 622/2022)"
      >
        <template #actions>
          <Link href="/contracts" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar para contratos
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-6xl mx-auto space-y-6">
      <Alert
        v-if="mensagemResultado"
        :key="alertaChave"
        :type="mensagemResultado.tipo"
        :title="mensagemResultado.titulo"
        :message="mensagemResultado.detalhe"
      />

      <!-- Estado vazio: nada de "sem pendências" sem explicar o critério -->
      <Card v-if="pendencias.length === 0">
        <div class="text-center py-12">
          <svg class="mx-auto h-12 w-12 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
          </svg>
          <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum contrato periódico com pendência</h3>
          <p class="mt-1 text-sm text-gray-500 max-w-md mx-auto">
            Todo contrato periódico vigente está com periodicidade preenchida, com as visitas do período geradas
            e sem nenhuma visita vencida em aberto.
          </p>
        </div>
      </Card>

      <template v-else>
        <!-- Resumo -->
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <StatCard title="Sem visita gerada" :value="grupos.semVisita.length" color="red" />
          <StatCard title="Visita vencida não executada" :value="grupos.vencida.length" color="red" />
          <StatCard title="Periodicidade não preenchida" :value="grupos.periodicidade.length" color="yellow" />
        </div>

        <!-- Sem visita gerada no período: pendência de conformidade RDC 622/2022, sempre visível -->
        <Card padding="none">
          <div class="px-6 py-4 border-b border-gray-200 bg-red-50">
            <div class="flex items-start gap-3">
              <svg class="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
              </svg>
              <div>
                <h3 class="text-base font-semibold text-red-900">Sem visita gerada no período</h3>
                <p class="text-sm text-red-800 mt-1">
                  Existe data prevista de visita sem Ordem de Serviço correspondente. É pendência de conformidade
                  com a RDC 622/2022 e não pode passar em silêncio.
                </p>
                <p class="text-sm text-red-800 mt-1">
                  "Gerar visitas" só resolve data futura: nenhuma Ordem de Serviço é criada com data no passado.
                  Para a data que já venceu, a saída é justificar por que a visita não aconteceu. O motivo fica
                  gravado no contrato e é ele que responde à fiscalização.
                </p>
              </div>
            </div>
          </div>

          <div v-if="grupos.semVisita.length === 0" class="p-6 text-sm text-gray-500">
            Nenhum contrato com esta pendência.
          </div>

          <template v-else>
            <div
              v-if="pode('contrato-editar')"
              class="px-6 py-3 border-b border-gray-200 bg-gray-50 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between"
            >
              <label class="flex items-center gap-2 text-sm text-gray-700">
                <input
                  type="checkbox"
                  :checked="todosElegiveisSelecionados"
                  :disabled="elegiveisParaLote.length === 0"
                  class="h-4 w-4 text-green-600 border-gray-300 rounded"
                  @change="alternarTodos"
                />
                Selecionar contratos que só precisam de geração ({{ elegiveisParaLote.length }})
              </label>
              <button
                type="button"
                class="btn-primary"
                :disabled="selecionados.size === 0"
                @click="abrirConfirmacaoLote"
              >
                Gerar visitas selecionadas ({{ selecionados.size }})
              </button>
            </div>

            <ul class="divide-y divide-gray-200">
              <li
                v-for="item in grupos.semVisita"
                :key="item.pendencia.contrato_id"
                class="px-6 py-4 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"
              >
                <div class="flex items-start gap-3">
                  <input
                    v-if="pode('contrato-editar') && ehElegivel(item.pendencia)"
                    type="checkbox"
                    :checked="selecionados.has(item.pendencia.contrato_id)"
                    class="mt-1 h-4 w-4 text-green-600 border-gray-300 rounded"
                    @change="alternarSelecao(item.pendencia.contrato_id)"
                  />
                  <span v-else class="mt-1 w-4 h-4 flex-shrink-0"></span>
                  <div>
                    <p class="text-sm font-medium text-gray-900">
                      {{ item.pendencia.contract_number || `Contrato #${item.pendencia.contrato_id}` }}
                      <span class="text-gray-500 font-normal">· {{ item.pendencia.cliente }}</span>
                    </p>
                    <p class="text-sm text-gray-600 mt-0.5">{{ item.motivo }}</p>
                    <p v-if="item.pendencia.motivos.length > 1" class="text-xs text-amber-700 mt-1">
                      Este contrato também aparece em outra seção abaixo, com outra pendência.
                    </p>
                  </div>
                </div>

                <div class="flex items-center gap-3 flex-shrink-0 pl-7 sm:pl-0">
                  <Link :href="`/contracts/${item.pendencia.contrato_id}`" class="text-sm text-blue-600 hover:text-blue-800 font-medium">
                    Ver contrato
                  </Link>
                  <button
                    v-if="pode('contrato-editar')"
                    type="button"
                    class="btn-secondary-sm"
                    @click="abrirConfirmacaoUnica(item.pendencia)"
                  >
                    Gerar visitas
                  </button>
                  <button
                    v-if="pode('contrato-editar') && item.pendencia.datas_sem_visita?.length"
                    type="button"
                    class="btn-secondary-sm"
                    @click="abrirJustificativa(item.pendencia)"
                  >
                    Justificar {{ item.pendencia.datas_sem_visita.length }} data(s)
                  </button>
                </div>
              </li>
            </ul>
          </template>
        </Card>

        <!-- Visita vencida não executada: falha de operação, não de geração -->
        <Card padding="none">
          <div class="px-6 py-4 border-b border-gray-200 bg-red-50">
            <div class="flex items-start gap-3">
              <svg class="w-5 h-5 text-red-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
              <div>
                <h3 class="text-base font-semibold text-red-900">Visita vencida não executada</h3>
                <p class="text-sm text-red-800 mt-1">
                  A Ordem de Serviço já foi gerada, mas a data passou e ela continua em aberto. A ação aqui é
                  executar ou reagendar a OS, não gerar visita nova.
                </p>
              </div>
            </div>
          </div>

          <div v-if="grupos.vencida.length === 0" class="p-6 text-sm text-gray-500">
            Nenhum contrato com esta pendência.
          </div>

          <ul v-else class="divide-y divide-gray-200">
            <li
              v-for="item in grupos.vencida"
              :key="`vencida-${item.pendencia.contrato_id}`"
              class="px-6 py-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
            >
              <div>
                <p class="text-sm font-medium text-gray-900">
                  {{ item.pendencia.contract_number || `Contrato #${item.pendencia.contrato_id}` }}
                  <span class="text-gray-500 font-normal">· {{ item.pendencia.cliente }}</span>
                </p>
                <p class="text-sm text-gray-600 mt-0.5">{{ item.motivo }}</p>
              </div>
              <Link :href="`/contracts/${item.pendencia.contrato_id}`" class="btn-secondary-sm flex-shrink-0">
                Ver visitas do contrato
              </Link>
            </li>
          </ul>
        </Card>

        <!-- Periodicidade não preenchida: resolução manual na edição do contrato -->
        <Card padding="none">
          <div class="px-6 py-4 border-b border-gray-200 bg-yellow-50">
            <div class="flex items-start gap-3">
              <svg class="w-5 h-5 text-yellow-600 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
              </svg>
              <div>
                <h3 class="text-base font-semibold text-yellow-900">Periodicidade não preenchida</h3>
                <p class="text-sm text-yellow-800 mt-1">
                  O valor gravado em "frequência de visita" não foi reconhecido pelo backfill. Sem isso, o sistema
                  não calcula nenhuma data prevista: é preciso corrigir na edição do contrato.
                </p>
              </div>
            </div>
          </div>

          <div v-if="grupos.periodicidade.length === 0" class="p-6 text-sm text-gray-500">
            Nenhum contrato com esta pendência.
          </div>

          <ul v-else class="divide-y divide-gray-200">
            <li
              v-for="item in grupos.periodicidade"
              :key="`periodicidade-${item.pendencia.contrato_id}`"
              class="px-6 py-4 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between"
            >
              <div>
                <p class="text-sm font-medium text-gray-900">
                  {{ item.pendencia.contract_number || `Contrato #${item.pendencia.contrato_id}` }}
                  <span class="text-gray-500 font-normal">· {{ item.pendencia.cliente }}</span>
                </p>
                <p class="text-sm text-gray-600 mt-0.5">{{ item.motivo }}</p>
              </div>
              <Link
                v-if="pode('contrato-editar')"
                :href="`/contracts/${item.pendencia.contrato_id}/edit#visit_frequency`"
                class="btn-secondary-sm flex-shrink-0"
              >
                Editar periodicidade
              </Link>
            </li>
          </ul>
        </Card>
      </template>
    </div>

    <ConfirmDeleteModal
      :show="mostrarModalGerar"
      variant="warning"
      title="Gerar visitas"
      subtitle="A ação cria Ordens de Serviço agendadas na agenda do cliente."
      :message="mensagemConfirmacao"
      confirm-text="Gerar visitas"
      processing-text="Gerando..."
      :processing="processando"
      @confirm="confirmarGeracao"
      @cancel="mostrarModalGerar = false"
    />

    <!-- Justificar as datas vencidas de um contrato. Uma linha é gravada por
         data escolhida: o mesmo motivo cobre várias datas de uma vez, mas
         nunca "o contrato inteiro para sempre". -->
    <Modal :show="mostrarModalJustificar" @close="fecharJustificativa">
      <template #icon>
        <svg class="h-6 w-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
        </svg>
      </template>
      <template #title>Justificar visitas não realizadas</template>
      <template #content>
        <p class="text-sm text-gray-700 mb-4">
          Contrato {{ contratoEmJustificativa?.contract_number || `#${contratoEmJustificativa?.contrato_id}` }}.
          Nenhuma Ordem de Serviço será criada com data no passado. Marque as datas que este motivo explica: cada
          data escolhida recebe o próprio registro, com seu nome e a data de hoje.
        </p>

        <div class="border border-gray-200 rounded-md divide-y divide-gray-200 max-h-48 overflow-y-auto mb-4">
          <label
            v-for="prevista in contratoEmJustificativa?.datas_sem_visita || []"
            :key="prevista.data"
            class="flex items-center gap-2 px-3 py-2 text-sm text-gray-700"
          >
            <input
              type="checkbox"
              :checked="datasEscolhidas.has(prevista.data)"
              class="h-4 w-4 text-green-600 border-gray-300 rounded"
              @change="alternarData(prevista.data)"
            />
            Visita {{ prevista.numero }} · {{ prevista.data_br }}
          </label>
        </div>

        <label class="block text-sm font-medium text-gray-700 mb-1">Motivo *</label>
        <textarea
          v-model="motivoJustificativa"
          rows="4"
          maxlength="500"
          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          :class="{ 'border-red-500': erroJustificativa }"
          placeholder="Ex.: contrato suspenso no período a pedido do cliente; acesso não autorizado nas datas; visitas realizadas e registradas fora do sistema."
        ></textarea>
        <p v-if="erroJustificativa" class="mt-1 text-sm text-red-600">{{ erroJustificativa }}</p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="justificando" @click="fecharJustificativa">
          Cancelar
        </button>
        <button type="button" class="btn-primary ml-3" :disabled="justificando" @click="confirmarJustificativa">
          {{ justificando ? 'Salvando...' : `Justificar ${datasEscolhidas.size} data(s)` }}
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import Alert from '@/Components/Alert.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import Modal from '@/Components/Modal.vue';
import { usePermissoes } from '@/Composables/usePermissoes';

const props = defineProps({
  pendencias: {
    type: Array,
    default: () => [],
  },
});

const { pode } = usePermissoes();

// Os três motivos vêm do backend como frases completas (PendenciasDeContratoService),
// não como código fixo. O prefixo de cada frase é estável e é o que permite agrupar
// no frontend sem duplicar a regra de negócio nem pedir uma chave nova ao backend.
const PREFIXOS_MOTIVO = {
  semVisita: 'Sem visita gerada no período configurado',
  vencida: 'Visita vencida não executada',
  periodicidade: 'Periodicidade não preenchida',
};

function categoriaDoMotivo(motivo) {
  return Object.keys(PREFIXOS_MOTIVO).find((chave) => motivo.startsWith(PREFIXOS_MOTIVO[chave])) ?? null;
}

// Um mesmo contrato pode acumular mais de um motivo: cada motivo aparece na
// sua própria seção, com o texto exato devolvido pelo backend e a ação certa
// para aquele motivo específico.
const grupos = computed(() => {
  const resultado = { semVisita: [], vencida: [], periodicidade: [] };

  for (const pendencia of props.pendencias) {
    for (const motivo of pendencia.motivos) {
      const categoria = categoriaDoMotivo(motivo);

      if (categoria) {
        resultado[categoria].push({ pendencia, motivo });
      }
    }
  }

  return resultado;
});

// "Só precisam disso": elegível para geração em lote é o contrato cujo único
// motivo é a falta de visita gerada. Contrato com periodicidade não
// preenchida não gera nada (o cálculo depende dela), e visita vencida não é
// resolvida por geração: os dois exigem ação individual, não em lote.
function ehElegivel(pendencia) {
  return pendencia.motivos.length === 1 && categoriaDoMotivo(pendencia.motivos[0]) === 'semVisita';
}

const elegiveisParaLote = computed(() => props.pendencias.filter(ehElegivel));

const selecionados = ref(new Set());

const todosElegiveisSelecionados = computed(
  () => elegiveisParaLote.value.length > 0 && elegiveisParaLote.value.every((p) => selecionados.value.has(p.contrato_id))
);

function alternarTodos() {
  if (todosElegiveisSelecionados.value) {
    selecionados.value = new Set();
    return;
  }

  selecionados.value = new Set(elegiveisParaLote.value.map((p) => p.contrato_id));
}

function alternarSelecao(contratoId) {
  const novo = new Set(selecionados.value);

  if (novo.has(contratoId)) {
    novo.delete(contratoId);
  } else {
    novo.add(contratoId);
  }

  selecionados.value = novo;
}

// Modal de confirmação, compartilhado entre a ação em lote e a ação de uma
// linha só: nunca `confirm()` nativo para ação que mexe na agenda do cliente.
const mostrarModalGerar = ref(false);
const processando = ref(false);
const alvoGeracao = ref(null); // { tipo: 'lote', ids: number[] } | { tipo: 'unico', id, numero }

const mensagemConfirmacao = computed(() => {
  if (!alvoGeracao.value) return '';

  if (alvoGeracao.value.tipo === 'lote') {
    return `Isso vai gerar as visitas pendentes de ${alvoGeracao.value.ids.length} contrato(s) selecionado(s). Deseja continuar?`;
  }

  return `Isso vai gerar as visitas pendentes do contrato ${alvoGeracao.value.numero}. Deseja continuar?`;
});

function abrirConfirmacaoLote() {
  if (selecionados.value.size === 0) return;

  alvoGeracao.value = { tipo: 'lote', ids: Array.from(selecionados.value) };
  mostrarModalGerar.value = true;
}

function abrirConfirmacaoUnica(pendencia) {
  alvoGeracao.value = {
    tipo: 'unico',
    id: pendencia.contrato_id,
    numero: pendencia.contract_number || `#${pendencia.contrato_id}`,
  };
  mostrarModalGerar.value = true;
}

async function gerarVisitasDoContrato(contratoId) {
  try {
    const resposta = await fetch(`/contracts/${contratoId}/visitas/gerar`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        Accept: 'application/json',
      },
    });

    const dados = await resposta.json();

    return { ok: resposta.ok && dados.success, criadas: dados.criadas ?? 0 };
  } catch (erro) {
    return { ok: false, criadas: 0 };
  }
}

const mensagemResultado = ref(null);
const alertaChave = ref(0);

async function confirmarGeracao() {
  if (!alvoGeracao.value) return;

  processando.value = true;

  const ids = alvoGeracao.value.tipo === 'lote' ? alvoGeracao.value.ids : [alvoGeracao.value.id];

  // Sequencial, não em paralelo: mantém a carga previsível no servidor e o
  // resultado fácil de somar contrato a contrato.
  const resultados = [];
  for (const id of ids) {
    resultados.push(await gerarVisitasDoContrato(id));
  }

  const totalCriadas = resultados.reduce((soma, r) => soma + r.criadas, 0);
  const falhas = resultados.filter((r) => !r.ok).length;

  mensagemResultado.value = {
    tipo: falhas === 0 ? 'success' : 'warning',
    titulo: falhas === 0 ? 'Geração concluída' : 'Geração concluída com falhas',
    detalhe: `${totalCriadas} visita(s) gerada(s) em ${ids.length} contrato(s).`
      + (falhas > 0 ? ` ${falhas} contrato(s) não puderam ser processados.` : ''),
  };
  alertaChave.value += 1;

  selecionados.value = new Set();
  processando.value = false;
  mostrarModalGerar.value = false;
  alvoGeracao.value = null;

  router.reload({ only: ['pendencias'] });
}

// -----------------------------------------------------------------
// Justificar data vencida sem visita
// -----------------------------------------------------------------
//
// A única saída desta pendência. A geração não resolve, por desenho: OS com
// data no passado é documento datado de um dia em que ninguém foi ao local.
// O que sai do painel é a pendência; o que fica é o motivo, gravado por data
// e visível na tela do contrato.

const mostrarModalJustificar = ref(false);
const contratoEmJustificativa = ref(null);
const datasEscolhidas = ref(new Set());
const motivoJustificativa = ref('');
const erroJustificativa = ref('');
const justificando = ref(false);

function abrirJustificativa(pendencia) {
  contratoEmJustificativa.value = pendencia;
  // Todas marcadas por padrão: o caso comum é o mesmo motivo cobrir o período
  // inteiro. Desmarcar é um clique, e a lista fica à vista antes de gravar.
  datasEscolhidas.value = new Set((pendencia.datas_sem_visita || []).map((d) => d.data));
  motivoJustificativa.value = '';
  erroJustificativa.value = '';
  mostrarModalJustificar.value = true;
}

function fecharJustificativa() {
  if (justificando.value) return;

  mostrarModalJustificar.value = false;
  contratoEmJustificativa.value = null;
  datasEscolhidas.value = new Set();
  motivoJustificativa.value = '';
  erroJustificativa.value = '';
}

function alternarData(data) {
  const novo = new Set(datasEscolhidas.value);

  if (novo.has(data)) {
    novo.delete(data);
  } else {
    novo.add(data);
  }

  datasEscolhidas.value = novo;
}

async function confirmarJustificativa() {
  const pendencia = contratoEmJustificativa.value;

  if (!pendencia) return;

  const motivo = motivoJustificativa.value.trim();

  if (datasEscolhidas.value.size === 0) {
    erroJustificativa.value = 'Marque pelo menos uma data.';
    return;
  }

  if (motivo.length < 5) {
    erroJustificativa.value = 'O motivo é obrigatório e precisa ter pelo menos 5 caracteres.';
    return;
  }

  justificando.value = true;
  erroJustificativa.value = '';

  try {
    const resposta = await fetch(`/contracts/${pendencia.contrato_id}/visitas/justificativas`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        Accept: 'application/json',
      },
      body: JSON.stringify({ datas: Array.from(datasEscolhidas.value), motivo }),
    });

    const dados = await resposta.json();

    if (resposta.ok && dados.success) {
      mensagemResultado.value = {
        tipo: 'success',
        titulo: 'Justificativa registrada',
        detalhe: dados.message,
      };
      alertaChave.value += 1;

      mostrarModalJustificar.value = false;
      contratoEmJustificativa.value = null;
      datasEscolhidas.value = new Set();
      motivoJustificativa.value = '';

      router.reload({ only: ['pendencias'] });
    } else {
      erroJustificativa.value = dados.message
        || Object.values(dados.errors ?? {}).flat()[0]
        || 'Não foi possível registrar a justificativa.';
    }
  } catch (erro) {
    erroJustificativa.value = 'Não foi possível registrar a justificativa.';
  } finally {
    justificando.value = false;
  }
}
</script>
