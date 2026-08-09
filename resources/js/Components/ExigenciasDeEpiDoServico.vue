<!--
  EPI exigido por um serviço (Plano 29, Task 29.5), dentro do cadastro do
  serviço.

  É a declaração de escritório — "executar dedetização exige respirador e luva" —
  e a origem de tudo que o Plano 29 faz depois: é dela que a carga do dia monta
  a etapa de confirmação no aplicativo do técnico, que o
  `ConfirmacaoDeEpiService` decide o que aceitar da fila offline e que o
  checklist da RDC 622/2022 sabe o que cobrar.

  **Serviço sem exigência cadastrada é o estado normal**, nunca irregularidade:
  a etapa simplesmente não aparece no aplicativo para aquele serviço. É por isso
  que a lista vazia aqui aparece em cinza, com a explicação, e não em vermelho.

  Por que `fetch` e não `useForm` do Inertia: as quatro rotas de
  `ServicePpeRequirementController` respondem JSON quando o pedido aceita JSON, e
  `ServiceController::edit()` não passa exigência nenhuma como prop — recarregar
  a página inteira a cada clique de "obrigatório/recomendado" perderia o
  formulário do serviço que o usuário talvez já esteja editando ao lado. É o
  caminho que o próprio controller documenta para esta tela.

  Obrigatório x recomendado não é detalhe visual: só o EPI **obrigatório**
  marcado como não usado gera o aviso `EXECUCAO_SEM_EPI_EXIGIDO` ao gestor. O
  recomendado fica no registro e não vira alarme.
-->
<template>
  <Card>
    <div class="px-6 py-4 border-b border-gray-200">
      <h3 class="text-lg font-medium text-gray-900">EPI exigido neste serviço</h3>
      <p class="mt-1 text-sm text-gray-500">
        O técnico confirma em campo, na execução da ordem de serviço, o que vestiu.
      </p>
    </div>

    <div class="p-6 space-y-4">
      <div v-if="erro" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ erro }}</p>
      </div>

      <p v-if="carregando" class="text-sm text-gray-500">Carregando exigências...</p>

      <template v-else>
        <div
          v-if="exigencias.length === 0"
          class="rounded-md border border-gray-200 bg-gray-50 p-4"
        >
          <p class="text-sm text-gray-700">Nenhum EPI exigido por este serviço.</p>
          <p class="mt-1 text-xs text-gray-500">
            Sem exigência cadastrada, a etapa de confirmação não aparece no aplicativo do técnico para as
            ordens de serviço deste serviço.
          </p>
        </div>

        <ul v-else class="divide-y divide-gray-200 border border-gray-200 rounded-md">
          <li
            v-for="exigencia in exigencias"
            :key="exigencia.id"
            class="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
          >
            <div class="min-w-0">
              <p class="text-sm font-medium text-gray-900">
                {{ exigencia.personal_protective_equipment?.nome || 'EPI não identificado' }}
              </p>
              <p class="text-xs text-gray-500">
                {{ rotuloDeTipoDeEpi(exigencia.personal_protective_equipment?.tipo) }}
              </p>
            </div>

            <div class="flex items-center gap-3">
              <select
                :value="exigencia.obrigatorio ? '1' : '0'"
                :disabled="salvandoId === exigencia.id"
                class="px-3 py-2 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                @change="alternarForca(exigencia, $event.target.value === '1')"
              >
                <option value="1">Obrigatório</option>
                <option value="0">Recomendado</option>
              </select>

              <button
                type="button"
                class="text-sm text-red-600 hover:text-red-800"
                :disabled="salvandoId === exigencia.id"
                @click="exigenciaParaRemover = exigencia"
              >
                Remover
              </button>
            </div>
          </li>
        </ul>

        <div class="flex flex-col sm:flex-row sm:items-end gap-3 border-t border-gray-200 pt-4">
          <div class="flex-1">
            <label class="block text-sm font-medium text-gray-700 mb-1">Acrescentar EPI</label>
            <select
              v-model="epiSelecionado"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="">Selecione um EPI</option>
              <option v-for="epi in episParaEscolher" :key="epi.id" :value="epi.id">
                {{ epi.nome }} - {{ rotuloDeTipoDeEpi(epi.tipo) }}
              </option>
            </select>
            <p v-if="episDisponiveis.length === 0" class="mt-1 text-xs text-gray-500">
              Nenhum modelo de EPI ativo no cadastro. Cadastre em Controle de EPI antes de exigir aqui.
            </p>
          </div>

          <div class="sm:pb-2">
            <label class="flex items-center">
              <input
                v-model="novoObrigatorio"
                type="checkbox"
                class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded"
              />
              <span class="ml-2 text-sm text-gray-700">Obrigatório</span>
            </label>
          </div>

          <button
            type="button"
            class="btn-primary"
            :disabled="!epiSelecionado || acrescentando"
            @click="acrescentar"
          >
            {{ acrescentando ? 'Salvando...' : 'Acrescentar' }}
          </button>
        </div>

        <p class="text-xs text-gray-500">
          Obrigatório gera aviso ao gestor quando o técnico registra que não usou. Recomendado fica só no
          registro da visita.
        </p>
      </template>
    </div>

    <ConfirmDeleteModal
      :show="!!exigenciaParaRemover"
      title="Remover exigência de EPI"
      subtitle="As confirmações já registradas continuam no histórico."
      message="A etapa do aplicativo deixa de pedir a confirmação deste EPI nas próximas ordens de serviço. Remover"
      :item-name="exigenciaParaRemover?.personal_protective_equipment?.nome || ''"
      confirm-text="Remover"
      processing-text="Removendo..."
      :processing="removendo"
      @cancel="exigenciaParaRemover = null"
      @confirm="remover"
    />
  </Card>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import Card from '@/Components/Card.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import { rotuloDeTipoDeEpi } from '@/utils/epi';

const props = defineProps({
  serviceId: {
    type: [Number, String],
    required: true,
  },
});

const exigencias = ref([]);
const episDisponiveis = ref([]);
const epiSelecionado = ref('');
const novoObrigatorio = ref(true);
const carregando = ref(true);
const acrescentando = ref(false);
const removendo = ref(false);
const salvandoId = ref(null);
const exigenciaParaRemover = ref(null);
const erro = ref('');

// EPI já exigido não volta na lista de escolha: cadastrar o mesmo par de novo
// não é erro (o Service trata como atualização), mas oferecer isso faz o
// usuário achar que cadastrou duas linhas.
const episParaEscolher = computed(() => {
  const jaExigidos = new Set(exigencias.value.map((exigencia) => exigencia.personal_protective_equipment_id));

  return episDisponiveis.value.filter((epi) => !jaExigidos.has(epi.id));
});

function cabecalhos() {
  return {
    'Content-Type': 'application/json',
    Accept: 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
  };
}

async function pedir(url, opcoes = {}) {
  const resposta = await fetch(url, { headers: cabecalhos(), ...opcoes });

  if (!resposta.ok) {
    const corpo = await resposta.json().catch(() => ({}));

    throw new Error(corpo.message || 'Não foi possível concluir a operação. Tente de novo.');
  }

  return resposta.json();
}

async function carregar() {
  carregando.value = true;
  erro.value = '';

  try {
    const dados = await pedir(`/epis/exigencias?service_id=${Number(props.serviceId)}`);

    exigencias.value = dados.exigencias || [];
    episDisponiveis.value = dados.episDisponiveis || [];
  } catch (falha) {
    erro.value = falha.message;
  } finally {
    carregando.value = false;
  }
}

async function acrescentar() {
  if (!epiSelecionado.value || acrescentando.value) {
    return;
  }

  acrescentando.value = true;
  erro.value = '';

  try {
    await pedir('/epis/exigencias', {
      method: 'POST',
      body: JSON.stringify({
        service_id: Number(props.serviceId),
        personal_protective_equipment_id: Number(epiSelecionado.value),
        obrigatorio: novoObrigatorio.value,
      }),
    });

    epiSelecionado.value = '';
    novoObrigatorio.value = true;

    await carregar();
  } catch (falha) {
    erro.value = falha.message;
  } finally {
    acrescentando.value = false;
  }
}

async function alternarForca(exigencia, obrigatorio) {
  salvandoId.value = exigencia.id;
  erro.value = '';

  try {
    await pedir(`/epis/exigencias/${exigencia.id}`, {
      method: 'PUT',
      body: JSON.stringify({ obrigatorio }),
    });

    exigencia.obrigatorio = obrigatorio;
  } catch (falha) {
    erro.value = falha.message;
    // A tela volta ao que o servidor tem: deixar o seletor mostrando a escolha
    // que não foi gravada é o que faria o usuário sair achando que exigiu algo.
    await carregar();
  } finally {
    salvandoId.value = null;
  }
}

async function remover() {
  if (!exigenciaParaRemover.value || removendo.value) {
    return;
  }

  removendo.value = true;
  erro.value = '';

  try {
    await pedir(`/epis/exigencias/${exigenciaParaRemover.value.id}`, { method: 'DELETE' });

    exigenciaParaRemover.value = null;

    await carregar();
  } catch (falha) {
    erro.value = falha.message;
  } finally {
    removendo.value = false;
  }
}

onMounted(carregar);
</script>
