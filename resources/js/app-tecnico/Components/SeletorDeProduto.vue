<!--
    Seletor de produto para o registro de avistamento por cômodo (Plano 13,
    Task 13.7).

    Busca sempre no catálogo local (`listarProdutos()`, carga do dia) - nunca
    no servidor durante a execução. Mostra o princípio ativo abaixo do nome
    (vem pronto na carga, `AppDayLoadService::carregarProdutos()`) e nenhum
    dado de preço ou custo (o catálogo nem carrega isso).

    Os últimos 5 produtos usados pelo técnico neste aparelho aparecem no topo
    enquanto a busca está vazia: em serviço de rotina o técnico usa os mesmos
    dois ou três produtos o dia inteiro, e rolar a lista inteira a cada
    cômodo é o tipo de atrito que faz o aplicativo ser abandonado.
-->
<template>
    <div>
        <!-- Produto já selecionado: mostra compacto, com opção de trocar -->
        <div
            v-if="produtoSelecionado && !buscaAberta"
            class="flex items-start justify-between gap-3 rounded-md border border-gray-300 bg-white px-3 py-2"
        >
            <div class="min-w-0">
                <p class="truncate text-sm font-medium text-gray-900">{{ produtoSelecionado.nome }}</p>
                <p v-if="produtoSelecionado.principio_ativo" class="truncate text-xs text-gray-500">
                    {{ produtoSelecionado.principio_ativo }}
                </p>
            </div>
            <button
                type="button"
                class="shrink-0 text-sm font-medium text-green-700 hover:text-green-800"
                @click="abrirBusca"
            >
                Trocar
            </button>
        </div>

        <!-- Busca / seleção -->
        <div v-else class="space-y-2">
            <input
                v-model="termoDeBusca"
                type="text"
                placeholder="Buscar produto pelo nome..."
                class="w-full min-h-[44px] rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
            />

            <div class="max-h-64 overflow-y-auto rounded-md border border-gray-200">
                <template v-if="!termoDeBusca">
                    <div v-if="ultimosUsados.length" class="border-b border-gray-200 bg-gray-50 px-3 py-1.5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Usados recentemente</p>
                    </div>
                    <button
                        v-for="produto in ultimosUsados"
                        :key="'recente-' + produto.id"
                        type="button"
                        class="flex w-full min-h-[44px] flex-col items-start justify-center border-b border-gray-100 px-3 py-2 text-left hover:bg-green-50"
                        @click="selecionar(produto)"
                    >
                        <span class="text-sm font-medium text-gray-900">{{ produto.nome }}</span>
                        <span v-if="produto.principio_ativo" class="text-xs text-gray-500">
                            {{ produto.principio_ativo }}
                        </span>
                    </button>

                    <div v-if="ultimosUsados.length" class="border-b border-gray-200 bg-gray-50 px-3 py-1.5">
                        <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Todos os produtos</p>
                    </div>
                </template>

                <button
                    v-for="produto in produtosFiltrados"
                    :key="produto.id"
                    type="button"
                    class="flex w-full min-h-[44px] flex-col items-start justify-center border-b border-gray-100 px-3 py-2 text-left last:border-b-0 hover:bg-green-50"
                    @click="selecionar(produto)"
                >
                    <span class="text-sm font-medium text-gray-900">{{ produto.nome }}</span>
                    <span v-if="produto.principio_ativo" class="text-xs text-gray-500">
                        {{ produto.principio_ativo }}
                    </span>
                </button>

                <p v-if="!produtosFiltrados.length" class="px-3 py-3 text-sm text-gray-500">
                    Nenhum produto encontrado.
                </p>
            </div>

            <button
                v-if="produtoSelecionado"
                type="button"
                class="text-sm text-gray-600 hover:text-gray-800"
                @click="buscaAberta = false"
            >
                Cancelar troca
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { listarProdutos } from '../db/repositorio';
import { listarUltimosProdutosUsados } from './comodoAvistamento';

const props = defineProps({
    modelValue: {
        type: Object,
        default: null,
    },
});

const emit = defineEmits(['update:modelValue']);

const produtos = ref([]);
const ultimosUsadosBrutos = ref([]);
const termoDeBusca = ref('');
const buscaAberta = ref(false);

const produtoSelecionado = computed(() => props.modelValue);

onMounted(async () => {
    const [listaDeProdutos, recentes] = await Promise.all([listarProdutos(), listarUltimosProdutosUsados(5)]);

    produtos.value = listaDeProdutos;
    ultimosUsadosBrutos.value = recentes;
});

/**
 * Os últimos produtos usados, resolvidos contra o catálogo atual (o
 * histórico guarda só id e nome; o princípio ativo, se existir, sai sempre
 * do catálogo carregado agora, nunca do que foi salvo no histórico).
 */
const ultimosUsados = computed(() => {
    const porId = new Map(produtos.value.map((produto) => [produto.id, produto]));

    return ultimosUsadosBrutos.value
        .map((item) => porId.get(item.produto_id))
        .filter((produto) => produto !== undefined);
});

const produtosFiltrados = computed(() => {
    const termo = termoDeBusca.value.trim().toLowerCase();

    if (!termo) {
        return produtos.value;
    }

    return produtos.value.filter(
        (produto) =>
            produto.nome?.toLowerCase().includes(termo) || produto.principio_ativo?.toLowerCase().includes(termo),
    );
});

function abrirBusca() {
    termoDeBusca.value = '';
    buscaAberta.value = true;
}

function selecionar(produto) {
    emit('update:modelValue', produto);
    termoDeBusca.value = '';
    buscaAberta.value = false;
}
</script>
