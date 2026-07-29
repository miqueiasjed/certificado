<!--
  Formulário de registro de adequação do aplicativo do técnico (Plano 13,
  Task 13.8): o que o cliente precisa corrigir (fresta, ralo sem proteção...),
  com prioridade, prazo sugerido em dias e até 3 fotos.

  Divergências entre a especificação (13.8.md) e o contrato real do backend
  (lidas em `app/Services/Sync/AplicadorDeAdequacao.php` e
  `app/Models/WorkOrderAdequation.php` antes de implementar este arquivo):

  1. Prazo em dias -> data: a especificação diz que "o backend converte para
     data a partir da data da visita". `AplicadorDeAdequacao::aplicar()` não
     faz essa conversão: ele grava `$payload['deadline']` como veio, direto na
     coluna `date`. Por isso o CÁLCULO acontece aqui (`calcularPrazoAPartirDaVisita`),
     a partir de `ordem.data_agendada` (a data da visita, já carregada
     localmente), nunca a partir do instante de sincronização - o enfileiramento
     já manda a data pronta (`yyyy-MM-dd`) no lugar dos dias.

  2. Campo `type`: a tabela `work_order_adequations.type` é um enum
     obrigatório (`estrutural`, `sanitario`, `higienico`, `quimico`, `outros`,
     mesmo catálogo de `WorkOrderAdequationRequest`, usado no painel web) e
     `AplicadorDeAdequacao` recusa a operação sem ele. A especificação da Task
     13.8 não pede este campo; ele foi acrescentado aqui (seletor simples,
     pré-preenchido pela sugestão tocada) porque sem ele a gravação falharia
     sempre.

  3. Campo "local": a especificação pede um campo de local (cômodo ou texto
     livre), mas `work_order_adequations` não tem nenhuma coluna para isso
     (nem `location_description` nem `room_id` - conferido no model e na
     migration). Por isso o local escolhido entra como prefixo da própria
     `description` (“Cozinha: fresta no rodapé”), a única forma de o dado não
     se perder até uma migration própria acrescentar a coluna.

  4. Foto ligada por uuid: o uuid da adequação é gerado aqui, antes de
     qualquer foto, e usado como `entityId` de `CapturaDeFoto` (regra que não
     pode falhar, conforme pedido). Ressalva importante, também encontrada na
     leitura do backend: `work_order_photos.entity_id` é validado como
     inteiro (`SyncFotoRequest`) e a tabela `work_order_adequations` não tem
     coluna de uuid nem qualquer tradução de `entity_id` por uuid em
     `AplicadorDeFoto`/`AplicadorDeAdequacao`. Ou seja, hoje o servidor
     rejeitaria este `entity_id` (não é um inteiro) quando a foto sincronizar de
     verdade - gap de backend fora do alcance desta task (só leitura), documentado
     aqui e no relatório desta task para virar correção própria.
-->
<template>
  <div class="space-y-5">
    <div>
      <h3 class="text-base font-semibold text-gray-900">Nova adequação</h3>
      <p class="mt-1 text-sm text-gray-500">
        O que o cliente precisa corrigir para o controle de pragas continuar funcionando.
      </p>
    </div>

    <!-- Sugestões tocáveis: preenchem a descrição, mas ela continua editável depois. -->
    <div>
      <label class="mb-1 block text-sm font-medium text-gray-700">Sugestões</label>
      <div class="flex flex-wrap gap-2">
        <button
          v-for="sugestao in SUGESTOES"
          :key="sugestao.texto"
          type="button"
          class="rounded-full border border-gray-300 bg-white px-3 py-1.5 text-sm text-gray-700 hover:bg-gray-50"
          @click="aplicarSugestao(sugestao)"
        >
          {{ sugestao.texto }}
        </button>
      </div>
    </div>

    <div>
      <label class="mb-1 block text-sm font-medium text-gray-700">Descrição *</label>
      <textarea
        v-model="descricao"
        rows="3"
        placeholder="Descreva o que precisa ser corrigido"
        class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
      ></textarea>
    </div>

    <div>
      <label class="mb-1 block text-sm font-medium text-gray-700">Tipo</label>
      <select
        v-model="tipo"
        class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
      >
        <option v-for="opcao in TIPOS" :key="opcao.valor" :value="opcao.valor">{{ opcao.rotulo }}</option>
      </select>
    </div>

    <div>
      <label class="mb-1 block text-sm font-medium text-gray-700">Local</label>
      <select
        v-model="comodoSelecionadoId"
        class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
      >
        <option value="">Digitar local (texto livre)</option>
        <option v-for="comodo in comodos" :key="comodo.id" :value="String(comodo.id)">{{ comodo.nome }}</option>
      </select>
      <input
        v-if="!comodoSelecionadoId"
        v-model="localLivre"
        type="text"
        placeholder="Ex.: área externa, fundos"
        class="mt-2 w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
      />
    </div>

    <div>
      <label class="mb-1 block text-sm font-medium text-gray-700">Prioridade</label>
      <div class="flex gap-2">
        <button
          v-for="opcao in PRIORIDADES"
          :key="opcao.valor"
          type="button"
          class="min-h-[44px] flex-1 rounded-md border px-3 py-2 text-sm font-medium"
          :class="prioridade === opcao.valor ? opcao.classesAtiva : 'border-gray-300 bg-white text-gray-700'"
          @click="prioridade = opcao.valor"
        >
          {{ opcao.rotulo }}
        </button>
      </div>
    </div>

    <div>
      <label class="mb-1 block text-sm font-medium text-gray-700">Prazo sugerido (dias) *</label>
      <input
        v-model.number="prazoDias"
        type="number"
        inputmode="numeric"
        min="1"
        step="1"
        class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
      />
      <p class="mt-1 text-xs text-gray-500">
        Contado a partir da data desta visita<span v-if="prazoFormatado">: vence em {{ prazoFormatado }}</span>.
      </p>
    </div>

    <p class="text-sm text-gray-600">
      Adequação com foto é o que o cliente resolve mais rápido. Foto é opcional.
    </p>

    <CapturaDeFoto
      :work-order-id="workOrderId"
      entity-type="adequation"
      :entity-id="uuidDaAdequacao"
      :limite-de-fotos="LIMITE_DE_FOTOS_DA_ADEQUACAO"
    />

    <p v-if="erro" class="text-sm text-red-600">{{ erro }}</p>

    <div class="flex justify-end gap-3">
      <button type="button" class="btn-secondary" :disabled="salvando" @click="cancelar">Cancelar</button>
      <button type="button" class="btn-primary" :disabled="salvando" @click="salvar">
        {{ salvando ? 'Salvando...' : 'Salvar adequação' }}
      </button>
    </div>
  </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { obterOrdem, listarComodosDaOrdem } from '../db/repositorio';
import { enfileirar } from '../sync/fila';
import { listarFotosDoContexto, removerFotoLocal } from '../sync/foto';
import { formatarData, hojeISO } from '@/utils/formatDate';
import CapturaDeFoto from './CapturaDeFoto.vue';

const props = defineProps({
    workOrderId: {
        type: [Number, String],
        required: true,
    },
});

const emit = defineEmits(['salvo', 'cancelar']);

const LIMITE_DE_FOTOS_DA_ADEQUACAO = 3;
const MILISSEGUNDOS_POR_DIA = 24 * 60 * 60 * 1000;

// Gerado uma única vez, antes de qualquer foto: é ele que liga a foto a esta
// adequação (`CapturaDeFoto` recebe como `entityId`), a mesma regra usada
// por `ListaDeAdequacoes.vue` para achar a primeira foto de cada item.
const uuidDaAdequacao = crypto.randomUUID();

const SUGESTOES = [
    { texto: 'Frestas na parede ou no rodapé', tipo: 'estrutural' },
    { texto: 'Ralo sem proteção ou tela', tipo: 'estrutural' },
    { texto: 'Vão livre sob a porta', tipo: 'estrutural' },
    { texto: 'Resto de alimento exposto', tipo: 'higienico' },
];

// Mesmo catálogo de `WorkOrderAdequationRequest` (painel web) e da coluna
// enum de `work_order_adequations.type`.
const TIPOS = [
    { valor: 'estrutural', rotulo: 'Estrutural' },
    { valor: 'sanitario', rotulo: 'Sanitário' },
    { valor: 'higienico', rotulo: 'Higiênico' },
    { valor: 'quimico', rotulo: 'Químico' },
    { valor: 'outros', rotulo: 'Outros' },
];

const PRIORIDADES = [
    { valor: 'baixa', rotulo: 'Baixa', classesAtiva: 'border-blue-600 bg-blue-50 text-blue-800' },
    { valor: 'media', rotulo: 'Média', classesAtiva: 'border-yellow-600 bg-yellow-50 text-yellow-800' },
    { valor: 'alta', rotulo: 'Alta', classesAtiva: 'border-red-600 bg-red-50 text-red-800' },
];

const ordem = ref(null);
const comodos = ref([]);

const descricao = ref('');
const tipo = ref('outros');
const comodoSelecionadoId = ref('');
const localLivre = ref('');
const prioridade = ref('media');
const prazoDias = ref(7);

const salvando = ref(false);
const erro = ref(null);

const localSelecionadoNome = computed(() => {
    const comodo = comodos.value.find((item) => String(item.id) === String(comodoSelecionadoId.value));

    return comodo ? comodo.nome : '';
});

const localFinal = computed(() =>
    comodoSelecionadoId.value ? localSelecionadoNome.value : localLivre.value.trim(),
);

/**
 * Prazo sugerido (dias) somado à data da visita (`ordem.data_agendada`,
 * texto `yyyy-MM-dd`), nunca ao dia da sincronização. Soma feita em UTC sobre
 * os componentes de calendário, sem instanciar `Date` a partir do texto
 * diretamente (mesma técnica de `diasAte()` em `utils/formatDate.js`), para
 * nenhuma conversão de fuso roubar ou somar um dia.
 */
function calcularPrazoAPartirDaVisita(dataAgendada, dias) {
    const numeroDeDias = Number(dias);

    if (!Number.isFinite(numeroDeDias) || numeroDeDias < 0) {
        return null;
    }

    // `dataAgendada` falta só se a OS nunca teve data agendada (não deveria
    // acontecer em uso normal); nesse caso extremo, cai no dia de hoje no
    // fuso do negócio como base - nunca no instante de sincronização, que
    // pode ser dias depois e não tem relação com a visita.
    const baseIso = dataAgendada || hojeISO();
    const partes = /^(\d{4})-(\d{2})-(\d{2})/.exec(baseIso || '');

    if (!partes) {
        return null;
    }

    const [, ano, mes, dia] = partes;
    const baseUtc = Date.UTC(Number(ano), Number(mes) - 1, Number(dia));
    const resultado = new Date(baseUtc + numeroDeDias * MILISSEGUNDOS_POR_DIA);

    return [
        resultado.getUTCFullYear(),
        String(resultado.getUTCMonth() + 1).padStart(2, '0'),
        String(resultado.getUTCDate()).padStart(2, '0'),
    ].join('-');
}

const prazoCalculado = computed(() => calcularPrazoAPartirDaVisita(ordem.value?.data_agendada, prazoDias.value));
const prazoFormatado = computed(() => (prazoCalculado.value ? formatarData(prazoCalculado.value) : ''));

function aplicarSugestao(sugestao) {
    descricao.value = sugestao.texto;
    tipo.value = sugestao.tipo;
}

/**
 * Remove qualquer foto já capturada para este uuid, mas nunca gravada junto
 * de uma adequação de fato (o técnico cancelou o formulário depois de tirar
 * fotos). Sem isso, a foto ficaria órfã na fila local, presa a um uuid que
 * nunca vira uma adequação de verdade.
 */
async function limparFotosOrfas() {
    const fotos = await listarFotosDoContexto(props.workOrderId, {
        entityType: 'adequation',
        entityId: uuidDaAdequacao,
    });

    await Promise.all(fotos.map((foto) => removerFotoLocal(foto.uuid)));
}

async function cancelar() {
    await limparFotosOrfas();
    emit('cancelar');
}

async function salvar() {
    erro.value = null;

    const descricaoDigitada = descricao.value.trim();

    if (!descricaoDigitada) {
        erro.value = 'Descreva o que precisa ser corrigido.';
        return;
    }

    if (!Number.isFinite(Number(prazoDias.value)) || Number(prazoDias.value) < 1) {
        erro.value = 'Informe um número de dias válido para o prazo.';
        return;
    }

    salvando.value = true;

    try {
        const descricaoFinal = localFinal.value ? `${localFinal.value}: ${descricaoDigitada}` : descricaoDigitada;

        await enfileirar({
            tipo: 'adequacao',
            work_order_id: props.workOrderId,
            payload: {
                // Uuid da adequação: liga a foto capturada acima a este
                // registro (ver a ressalva no cabeçalho deste arquivo sobre o
                // que o backend realmente aceita hoje em `entity_id`).
                uuid: uuidDaAdequacao,
                type: tipo.value,
                description: descricaoFinal,
                priority: prioridade.value,
                deadline: prazoCalculado.value,
            },
        });

        emit('salvo');
    } catch (falha) {
        erro.value = falha?.message || 'Não foi possível registrar esta adequação agora.';
    } finally {
        salvando.value = false;
    }
}

onMounted(async () => {
    const [ordemCarregada, comodosCarregados] = await Promise.all([
        obterOrdem(props.workOrderId),
        listarComodosDaOrdem(props.workOrderId),
    ]);

    ordem.value = ordemCarregada ?? null;
    comodos.value = comodosCarregados ?? [];
});
</script>
