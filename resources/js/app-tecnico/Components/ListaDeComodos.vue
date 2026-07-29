<!--
    Lista de cômodos da OS, com sinal de já registrado nesta visita e
    contador de progresso (Plano 13, Task 13.7).

    Componente autossuficiente: cuida da lista e, ao tocar um cômodo, mostra
    `RegistroDeComodo.vue` no lugar dela, sem depender de nenhuma tela externa
    para alternar entre as duas - a Task 13.6 (tela de execução da OS, em
    paralelo) só precisa montar `<ListaDeComodos :ordem="ordem" />` numa aba
    "Cômodos" quando estiver pronta.

    Tudo aqui sai do IndexedDB (`listarComodosDaOrdem`) e do registro local
    (`comodoAvistamento.js`, guardado em `meta`) - nenhuma leitura de rede.
-->
<template>
    <div class="space-y-4">
        <div v-if="comodos.length" class="space-y-1">
            <div class="flex items-center justify-between text-sm">
                <span class="font-medium text-gray-700">Progresso</span>
                <span class="text-gray-600">{{ contagemRegistrados }} de {{ comodos.length }} cômodos registrados</span>
            </div>
            <div class="h-2 w-full overflow-hidden rounded-full bg-gray-200">
                <div class="h-full rounded-full bg-green-600 transition-all" :style="{ width: percentualRegistrado + '%' }"></div>
            </div>
        </div>

        <!-- Detalhe do cômodo selecionado -->
        <div v-if="comodoSelecionado" class="rounded-lg border border-gray-200 bg-white p-4">
            <RegistroDeComodo
                :ordem="ordem"
                :comodo="comodoSelecionado"
                @salvo="aoSalvar"
                @cancelar="comodoSelecionado = null"
            />
        </div>

        <!-- Lista de cômodos -->
        <div v-else>
            <div v-if="carregando" class="py-8 text-center text-sm text-gray-500">Carregando cômodos...</div>

            <div v-else-if="!comodos.length" class="py-8 text-center text-sm text-gray-500">
                Nenhum cômodo cadastrado para este endereço.
            </div>

            <ul v-else class="space-y-2">
                <li v-for="comodo in comodos" :key="comodo.id">
                    <button
                        type="button"
                        class="flex w-full min-h-[44px] items-center justify-between gap-3 rounded-lg border border-gray-200 bg-white px-4 py-3 text-left shadow-sm hover:bg-gray-50"
                        @click="abrirComodo(comodo)"
                    >
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium text-gray-900">{{ comodo.nome }}</p>
                            <p v-if="registroDoComodo(comodo)" class="truncate text-xs text-gray-500">
                                {{ resumoDoRegistro(comodo) }}
                            </p>
                        </div>

                        <span
                            v-if="registroDoComodo(comodo)"
                            class="shrink-0 rounded-full bg-green-100 px-2 py-1 text-xs font-medium text-green-800"
                        >
                            Registrado
                        </span>
                        <span v-else class="shrink-0 rounded-full bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600">
                            Pendente
                        </span>
                    </button>
                </li>
            </ul>
        </div>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import RegistroDeComodo from './RegistroDeComodo.vue';
import { listarComodosDaOrdem } from '../db/repositorio';
import { obterRegistroDoComodo } from './comodoAvistamento';

const props = defineProps({
    ordem: {
        type: Object,
        required: true,
    },
});

const comodos = ref([]);
const registros = ref(new Map());
const comodoSelecionado = ref(null);
const carregando = ref(true);

onMounted(carregar);

async function carregar() {
    carregando.value = true;

    comodos.value = await listarComodosDaOrdem(props.ordem.id);

    const pares = await Promise.all(
        comodos.value.map(async (comodo) => [comodo.id, await obterRegistroDoComodo(props.ordem.id, comodo.id)]),
    );

    registros.value = new Map(pares.filter(([, registro]) => registro !== undefined && registro !== null));
    carregando.value = false;
}

const contagemRegistrados = computed(() => registros.value.size);

const percentualRegistrado = computed(() => {
    if (!comodos.value.length) {
        return 0;
    }

    return Math.round((contagemRegistrados.value / comodos.value.length) * 100);
});

function registroDoComodo(comodo) {
    return registros.value.get(comodo.id) ?? null;
}

function resumoDoRegistro(comodo) {
    const registro = registroDoComodo(comodo);

    if (!registro) {
        return '';
    }

    const quantidadeDeProdutos = registro.produtos?.length ?? 0;

    if (quantidadeDeProdutos === 0) {
        return 'Avistamento registrado, sem produto aplicado';
    }

    if (quantidadeDeProdutos === 1) {
        return `1 produto aplicado: ${registro.produtos[0].nome}`;
    }

    return `${quantidadeDeProdutos} produtos aplicados`;
}

function abrirComodo(comodo) {
    comodoSelecionado.value = comodo;
}

async function aoSalvar() {
    comodoSelecionado.value = null;
    await carregar();
}
</script>
