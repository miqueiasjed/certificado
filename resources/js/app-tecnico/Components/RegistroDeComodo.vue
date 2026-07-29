<!--
    Registro de avistamento por cômodo (Plano 13, Task 13.7).

    Praga avistada, intensidade, produto(s) aplicado(s) com quantidade e
    observação, tudo sobre o cômodo passado por prop. Salvar grava o rascunho
    local e enfileira uma operação `avistamento` por produto (ver
    `comodoAvistamento.js` para o porquê: `pest_sightings` só guarda um
    produto por linha) e volta para a lista, sem esperar rede.

    Se o cômodo já tinha um registro nesta visita, o formulário abre
    preenchido com ele (edição), em vez de começar em branco.

    Nenhum dado de custo ou preço de produto aparece aqui - nem o catálogo
    local carrega isso (ver `SeletorDeProduto.vue`).
-->
<template>
    <div class="space-y-4">
        <div class="flex items-center justify-between gap-2">
            <h2 class="text-base font-semibold text-gray-900">{{ comodo.nome }}</h2>
            <span
                v-if="editando"
                class="shrink-0 rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800"
            >
                Editando registro
            </span>
        </div>

        <!-- Praga avistada -->
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">Praga avistada *</label>

            <div v-if="pragaSelecionada" class="flex items-center justify-between gap-3 rounded-md border border-gray-300 bg-white px-3 py-2">
                <span class="text-sm font-medium text-gray-900">{{ nomeDaPraga(pragaSelecionada) }}</span>
                <button type="button" class="text-sm font-medium text-green-700 hover:text-green-800" @click="pragaSelecionada = null">
                    Trocar
                </button>
            </div>

            <div v-else class="space-y-2">
                <input
                    v-model="buscaDePraga"
                    type="text"
                    placeholder="Buscar praga pelo nome..."
                    class="w-full min-h-[44px] rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
                />
                <div class="max-h-48 overflow-y-auto rounded-md border border-gray-200">
                    <button
                        v-for="praga in pragasFiltradas"
                        :key="praga.id"
                        type="button"
                        class="flex w-full min-h-[44px] items-center border-b border-gray-100 px-3 py-2 text-left text-sm text-gray-900 last:border-b-0 hover:bg-green-50"
                        @click="pragaSelecionada = praga.id"
                    >
                        {{ praga.nome }}
                    </button>
                    <p v-if="!pragasFiltradas.length" class="px-3 py-3 text-sm text-gray-500">Nenhuma praga encontrada.</p>
                </div>
            </div>
        </div>

        <!-- Intensidade -->
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">Intensidade</label>
            <div class="grid grid-cols-4 gap-2">
                <button
                    v-for="opcao in OPCOES_DE_INTENSIDADE"
                    :key="opcao.valor"
                    type="button"
                    class="min-h-[44px] rounded-md border px-2 py-2 text-sm font-medium"
                    :class="
                        intensidade === opcao.valor
                            ? 'border-green-600 bg-green-600 text-white'
                            : 'border-gray-300 bg-white text-gray-700'
                    "
                    @click="intensidade = opcao.valor"
                >
                    {{ opcao.rotulo }}
                </button>
            </div>
        </div>

        <!-- Produtos aplicados -->
        <div>
            <div class="mb-1 flex items-center justify-between">
                <label class="block text-sm font-medium text-gray-700">Produtos aplicados</label>
                <button type="button" class="text-sm font-medium text-green-700 hover:text-green-800" @click="adicionarLinhaDeProduto">
                    + Adicionar produto
                </button>
            </div>

            <p v-if="!linhasDeProduto.length" class="text-sm text-gray-500">
                Nenhum produto aplicado neste cômodo (apenas o avistamento é registrado).
            </p>

            <div v-for="(linha, indice) in linhasDeProduto" :key="linha.chave" class="mb-3 space-y-2 rounded-md border border-gray-200 p-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0 flex-1">
                        <SeletorDeProduto v-model="linha.produto" />
                    </div>
                    <button
                        type="button"
                        class="shrink-0 px-2 py-2 text-sm font-medium text-red-600 hover:text-red-800"
                        @click="removerLinhaDeProduto(indice)"
                    >
                        Remover
                    </button>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-700">Quantidade aplicada *</label>
                        <input
                            v-model="linha.quantidade"
                            type="text"
                            inputmode="decimal"
                            placeholder="0"
                            class="w-full min-h-[44px] rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
                        />
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-700">Unidade</label>
                        <select
                            v-model="linha.unidade"
                            class="w-full min-h-[44px] rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
                        >
                            <option v-for="unidade in UNIDADES_DISPONIVEIS" :key="unidade" :value="unidade">
                                {{ unidade }}
                            </option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Observação -->
        <div>
            <label class="mb-1 block text-sm font-medium text-gray-700">Observação</label>
            <textarea
                v-model="observacaoGeral"
                rows="3"
                placeholder="Ex.: praga concentrada perto da tubulação"
                class="w-full rounded-md border border-gray-300 px-3 py-2 shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500"
            ></textarea>
        </div>

        <p v-if="erro" class="text-sm text-red-600">{{ erro }}</p>

        <div class="flex justify-end gap-3 pt-2">
            <button type="button" class="btn-secondary min-h-[44px]" @click="$emit('cancelar')">Cancelar</button>
            <button type="button" class="btn-primary min-h-[44px]" :disabled="salvando" @click="salvar">
                {{ salvando ? 'Salvando...' : 'Salvar' }}
            </button>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import SeletorDeProduto from './SeletorDeProduto.vue';
import { listarPragas } from '../db/repositorio';
import { obterRegistroDoComodo, salvarRegistroDoComodo } from './comodoAvistamento';

const props = defineProps({
    ordem: {
        type: Object,
        required: true,
    },
    comodo: {
        type: Object,
        required: true,
    },
});

const emit = defineEmits(['salvo', 'cancelar']);

const OPCOES_DE_INTENSIDADE = [
    { valor: 'low', rotulo: 'Baixa' },
    { valor: 'medium', rotulo: 'Média' },
    { valor: 'high', rotulo: 'Alta' },
    { valor: 'critical', rotulo: 'Crítica' },
];

const UNIDADES_DISPONIVEIS = ['mL', 'L', 'g', 'kg', 'unidade', 'sachê'];

const pragas = ref([]);
const buscaDePraga = ref('');
const pragaSelecionada = ref(null);
const intensidade = ref('medium');
const observacaoGeral = ref('');
const linhasDeProduto = ref([]);
const erro = ref(null);
const salvando = ref(false);
const editando = ref(false);

let proximaChaveDeLinha = 0;

onMounted(async () => {
    pragas.value = await listarPragas();

    const registroAnterior = await obterRegistroDoComodo(props.ordem.id, props.comodo.id);

    if (registroAnterior) {
        editando.value = true;
        pragaSelecionada.value = registroAnterior.pest_type ?? null;
        intensidade.value = registroAnterior.severity_level ?? 'medium';
        observacaoGeral.value = registroAnterior.observacao_geral ?? '';
        linhasDeProduto.value = (registroAnterior.produtos ?? []).map((produto) => novaLinhaDeProduto(produto));
    }
});

const pragasFiltradas = computed(() => {
    const termo = buscaDePraga.value.trim().toLowerCase();

    if (!termo) {
        return pragas.value;
    }

    return pragas.value.filter((praga) => praga.nome?.toLowerCase().includes(termo));
});

function nomeDaPraga(id) {
    return pragas.value.find((praga) => praga.id === id)?.nome ?? id;
}

function novaLinhaDeProduto(produtoSalvo = null) {
    proximaChaveDeLinha += 1;

    return {
        chave: proximaChaveDeLinha,
        produto: produtoSalvo ? { id: produtoSalvo.produto_id, nome: produtoSalvo.nome } : null,
        quantidade: produtoSalvo ? String(produtoSalvo.quantidade) : '',
        unidade: produtoSalvo?.unidade ?? UNIDADES_DISPONIVEIS[0],
    };
}

function adicionarLinhaDeProduto() {
    linhasDeProduto.value.push(novaLinhaDeProduto());
}

function removerLinhaDeProduto(indice) {
    linhasDeProduto.value.splice(indice, 1);
}

/**
 * Valida e converte as linhas de produto para o formato salvo. Bloqueia o
 * salvamento (devolvendo `null`) quando alguma linha tem produto selecionado
 * sem quantidade válida - regra que não pode falhar: quantidade aplicada é
 * exigência de documentação da execução.
 */
function validarProdutos() {
    const produtosValidos = [];

    for (const linha of linhasDeProduto.value) {
        const temProduto = !!linha.produto;
        const quantidadeTexto = String(linha.quantidade ?? '').trim().replace(',', '.');
        const quantidadeNumero = Number(quantidadeTexto);
        const temQuantidadeValida = quantidadeTexto !== '' && !Number.isNaN(quantidadeNumero) && quantidadeNumero > 0;

        if (!temProduto && quantidadeTexto === '') {
            // Linha em branco (produto ainda não escolhido, sem quantidade):
            // ignorada silenciosamente, não é um registro incompleto - é uma
            // linha que o técnico começou e desistiu.
            continue;
        }

        if (!temProduto) {
            erro.value = 'Selecione o produto da linha antes de salvar, ou remova a linha.';
            return null;
        }

        if (!temQuantidadeValida) {
            erro.value = `Informe a quantidade aplicada de "${linha.produto.nome}" antes de salvar.`;
            return null;
        }

        produtosValidos.push({
            produto_id: linha.produto.id,
            nome: linha.produto.nome,
            quantidade: quantidadeNumero,
            unidade: linha.unidade || null,
        });
    }

    return produtosValidos;
}

async function salvar() {
    erro.value = null;

    if (!pragaSelecionada.value) {
        erro.value = 'Selecione a praga avistada antes de salvar.';
        return;
    }

    const produtos = validarProdutos();

    if (produtos === null) {
        return;
    }

    salvando.value = true;

    try {
        await salvarRegistroDoComodo({
            workOrderId: props.ordem.id,
            roomId: props.comodo.id,
            pestType: pragaSelecionada.value,
            severityLevel: intensidade.value,
            observacaoGeral: observacaoGeral.value.trim(),
            produtos,
        });

        emit('salvo');
    } catch (excecao) {
        erro.value = 'Não foi possível salvar o registro. Tente novamente.';
        console.error('Falha ao salvar registro de cômodo', excecao);
    } finally {
        salvando.value = false;
    }
}
</script>
