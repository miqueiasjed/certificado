<!--
    Tela de Pendências do aplicativo do técnico (Plano 12, Task 12.10).

    Três blocos, cada um com a própria contagem: "Aguardando envio" (fila
    ainda não confirmada pelo servidor), "Precisa de decisão" (conflito aberto)
    e "Falhou" (tentativas esgotadas). As contagens do cabeçalho de cada bloco
    vêm de `useSincronizacao.js` - sempre a fila local, nunca o servidor -, e a
    lista de cada bloco é a mesma fonte por baixo (`sync/fila.js`), então as
    duas nunca divergem.

    "Precisa de decisão" é o único bloco cujo detalhe (o que o servidor tem
    hoje, `valor_do_servidor`) não existe na base local: o técnico só guarda,
    localmente, o próprio `conflito_id` e o motivo (`marcarComoConflito()`).
    Por isso, e só para o detalhe (nunca para a contagem), esta tela busca
    `GET /api/app/conflitos` e mescla cada resposta com o registro local
    correspondente (que contém `registrada_em`, a data em que o técnico
    registrou - o servidor não guarda isso em `valor_do_aplicativo`).

    Nada aqui decide nada sozinho pelo técnico: resolver um conflito e tentar
    de novo uma falha são sempre um toque explícito dele.
-->
<template>
    <div class="max-w-lg mx-auto px-4 py-6 space-y-6">
        <h1 class="text-lg font-semibold text-gray-900">Pendências</h1>

        <!-- Aguardando envio -->
        <section class="space-y-2">
            <h2 class="text-sm font-semibold text-gray-900">Aguardando envio ({{ pendentes }})</h2>

            <p v-if="listaPendentes.length === 0" class="text-sm text-gray-600">
                Nada aguardando envio neste aparelho.
            </p>

            <ul v-else class="space-y-2">
                <li
                    v-for="item in listaPendentes"
                    :key="item.uuid"
                    class="bg-white rounded-lg border border-gray-200 shadow-sm p-3"
                >
                    <p class="text-sm font-medium text-gray-900">{{ rotuloDoTipo(item.tipo) }}</p>
                    <p class="text-sm text-gray-700">{{ rotuloDaOrdem(item.work_order_id) }}</p>
                    <p class="text-xs text-gray-500 mt-1">Registrado em {{ formatarDataHora(item.registrada_em) }}</p>
                </li>
            </ul>
        </section>

        <!-- Precisa de decisão -->
        <section class="space-y-2">
            <h2 class="text-sm font-semibold text-gray-900">Precisa de decisão ({{ emConflito }})</h2>

            <p v-if="carregandoConflitos" class="text-sm text-gray-600">Carregando detalhes dos conflitos...</p>
            <p v-else-if="erroConflitos" class="text-sm text-red-600">{{ erroConflitos }}</p>
            <p v-else-if="conflitosDetalhados.length === 0" class="text-sm text-gray-600">
                Nada precisando de decisão agora.
            </p>

            <ul v-else class="space-y-2">
                <li v-for="conflito in conflitosDetalhados" :key="conflito.id">
                    <button
                        type="button"
                        class="w-full text-left bg-white rounded-lg border border-gray-200 shadow-sm p-3 min-h-[44px]"
                        @click="abrirConflito(conflito)"
                    >
                        <p class="text-sm font-medium text-gray-900">{{ rotuloDoTipo(conflito.tipo) }}</p>
                        <p class="text-sm text-gray-700">{{ rotuloDaOrdem(conflito.work_order_id) }}</p>
                        <p class="text-xs text-yellow-800 mt-1">{{ rotuloDoMotivo(conflito.motivo) }}</p>
                    </button>
                </li>
            </ul>
        </section>

        <!-- Falhou -->
        <section class="space-y-2">
            <h2 class="text-sm font-semibold text-gray-900">Falhou ({{ comFalha }})</h2>

            <p v-if="listaFalhas.length === 0" class="text-sm text-gray-600">Nada com falha agora.</p>

            <ul v-else class="space-y-2">
                <li
                    v-for="item in listaFalhas"
                    :key="item.uuid"
                    class="bg-white rounded-lg border border-gray-200 shadow-sm p-3 space-y-2"
                >
                    <div>
                        <p class="text-sm font-medium text-gray-900">{{ rotuloDoTipo(item.tipo) }}</p>
                        <p class="text-sm text-gray-700">{{ rotuloDaOrdem(item.work_order_id) }}</p>
                        <p class="text-xs text-red-700 mt-1">{{ item.ultimo_erro || 'Falha desconhecida.' }}</p>
                    </div>

                    <button type="button" class="btn-secondary-sm min-h-[44px]" @click="tentarDeNovo(item.uuid)">
                        Tentar de novo
                    </button>
                </li>
            </ul>
        </section>

        <ResolucaoDeConflito
            :conflito="conflitoSelecionado"
            :rotulo-da-ordem="conflitoSelecionado ? rotuloDaOrdem(conflitoSelecionado.work_order_id) : ''"
            @fechar="fecharConflito"
            @resolvido="aoConflitoResolvido"
        />
    </div>
</template>

<script setup>
import { onMounted, ref, watch } from 'vue';
import { useSincronizacao } from '../Composables/useSincronizacao';
import { listarPendentes, listarComFalha, listarEmConflito, tentarNovamente, removerDaFila } from '../sync/fila';
import { listarOrdens, obterMeta } from '../db/repositorio';
import { formatarDataHora } from '@/utils/formatDate';
import ResolucaoDeConflito from '../Components/ResolucaoDeConflito.vue';

const { pendentes, emConflito, comFalha } = useSincronizacao();

const listaPendentes = ref([]);
const listaFalhas = ref([]);
const conflitosDetalhados = ref([]);
const carregandoConflitos = ref(false);
const erroConflitos = ref(null);
const conflitoSelecionado = ref(null);

// Carregado uma vez, para dar nome de cliente ao "OS nº 123" sempre que a
// ordem ainda estiver na carga local (Task 12.7). Uma ordem que já saiu da
// carga (por exemplo, um dia muito antigo) só mostra o número.
const ordensPorId = ref(new Map());

const TIPOS = {
    evento_dispositivo: 'Evento de dispositivo',
    avistamento: 'Avistamento',
    adequacao: 'Adequação',
    conclusao_os: 'Conclusão da OS',
    foto: 'Foto',
};

const MOTIVOS = {
    registro_alterado: 'Alguém alterou esta ordem de serviço enquanto você estava offline',
    registro_removido: 'A ordem de serviço não existe mais no servidor',
    os_travada: 'A ordem de serviço já foi assinada e está travada',
    regra_de_negocio: 'O servidor recusou por uma regra do sistema',
};

function rotuloDoTipo(tipo) {
    return TIPOS[tipo] || (tipo ? tipo.replace(/_/g, ' ') : 'Registro');
}

function rotuloDaOrdem(workOrderId) {
    if (!workOrderId) {
        return 'Sem ordem de serviço vinculada';
    }

    const ordem = ordensPorId.value.get(workOrderId);
    const nomeDoCliente = ordem?.cliente?.nome;

    return nomeDoCliente ? `OS nº ${workOrderId} - ${nomeDoCliente}` : `OS nº ${workOrderId}`;
}

function rotuloDoMotivo(motivo) {
    return MOTIVOS[motivo] || 'Motivo não identificado';
}

async function atualizarListasLocais() {
    const [pendentesDaFila, falhas] = await Promise.all([listarPendentes(), listarComFalha()]);

    listaPendentes.value = pendentesDaFila;
    listaFalhas.value = falhas;
}

/**
 * Busca o detalhe dos conflitos abertos no servidor e mescla com o registro
 * local correspondente (por `conflito_id`), para juntar `valor_do_servidor`
 * (só existe no servidor) com `registrada_em` (só existe localmente).
 *
 * Sem rede, ou com o pedido recusado: os conflitos continuam contados (o
 * número do título vem de `emConflito`, sempre local), só o detalhe de cada
 * um fica indisponível até a próxima tentativa.
 */
async function carregarConflitos() {
    const locais = await listarEmConflito();

    if (locais.length === 0) {
        conflitosDetalhados.value = [];
        erroConflitos.value = null;
        return;
    }

    carregandoConflitos.value = true;
    erroConflitos.value = null;

    try {
        const token = await obterMeta('token');
        const resposta = await fetch('/api/app/conflitos', {
            headers: {
                Accept: 'application/json',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
        });

        if (!resposta.ok) {
            throw new Error(`Não foi possível carregar os detalhes dos conflitos agora (HTTP ${resposta.status}).`);
        }

        const corpo = await resposta.json();
        const doServidor = Array.isArray(corpo?.conflitos) ? corpo.conflitos : [];
        const doServidorPorId = new Map(doServidor.map((conflito) => [conflito.id, conflito]));

        conflitosDetalhados.value = locais
            .map((local) => {
                const doServidorCorrespondente = doServidorPorId.get(local.conflito_id);

                if (!doServidorCorrespondente) {
                    return null;
                }

                return {
                    ...doServidorCorrespondente,
                    uuidLocal: local.uuid,
                    tipo: local.tipo,
                    registrada_em: local.registrada_em,
                };
            })
            .filter(Boolean);
    } catch (falha) {
        conflitosDetalhados.value = [];
        erroConflitos.value = falha?.message || 'Não foi possível carregar os detalhes dos conflitos agora.';
    } finally {
        carregandoConflitos.value = false;
    }
}

async function tentarDeNovo(uuid) {
    await tentarNovamente(uuid);
}

function abrirConflito(conflito) {
    conflitoSelecionado.value = conflito;
}

function fecharConflito() {
    conflitoSelecionado.value = null;
}

/**
 * O servidor já decidiu o conflito quando este evento chega (Modal só emite
 * depois de `POST /api/app/conflitos/{id}/resolver` responder 2xx). Falta só
 * tirar o registro local da fila: ele não sai sozinho porque `aplicarResultados()`
 * (sync/fila.js) só remove pelo resultado de um lote de sincronização, nunca
 * pela resolução de um conflito isolado.
 */
async function aoConflitoResolvido({ conflito }) {
    conflitoSelecionado.value = null;

    if (conflito?.uuidLocal) {
        await removerDaFila([conflito.uuidLocal]);
    }
}

// As contagens (`pendentes`, `comFalha`, `emConflito`) já são atualizadas por
// `useSincronizacao.js` a cada mudança na fila local. Estes observadores só
// religam a lista detalhada de cada bloco sempre que a contagem correspondente
// mudar, para nunca duplicar a assinatura de eventos que o composable já
// mantém.
watch(pendentes, atualizarListasLocais);
watch(comFalha, atualizarListasLocais);
watch(emConflito, carregarConflitos);

onMounted(async () => {
    const ordens = await listarOrdens();
    ordensPorId.value = new Map(ordens.map((ordem) => [ordem.id, ordem]));

    await Promise.all([atualizarListasLocais(), carregarConflitos()]);
});
</script>
