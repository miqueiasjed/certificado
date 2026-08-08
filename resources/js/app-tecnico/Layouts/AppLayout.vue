<!--
    Casca visual do aplicativo do técnico (Plano 12, Task 12.9).

    Cabeçalho fino (empresa, data, indicador de sincronização) e navegação
    inferior com três itens (Dia, Pendências, Conta), sem nenhuma dependência
    do Inertia: este aplicativo não usa `<Link>`, `usePage()` nem
    `AuthenticatedLayout` do painel web, porque tem ciclo de vida próprio
    (offline, tela cheia, PWA). A troca de página é decidida por quem monta
    este layout (`App.vue`), via a prop `paginaAtual` e o evento `navegar`.

    O indicador de sincronização (`IndicadorDeSincronizacao.vue`, Task 12.10)
    mostra os quatro estados de envio e leva a Pendências ao ser tocado
    quando há conflito ou falha aguardando decisão do técnico.

    Alvos de toque de no mínimo 44px e contraste alto: a tela é lida no sol,
    em campo, muitas vezes com uma mão só.

    ## Faixa de rastreamento contínuo (Plano 22, Task 22.7)

    Quando `rastreamento_continuo_ligado` (chave nova da carga do dia, ver
    `AppDayLoadService::carregar()`) é `true`, uma faixa permanente aparece
    logo abaixo do cabeçalho, em toda página que passa por este layout (Dia,
    Roteiro, Pendências e Conta) - exatamente a regra inegociável da task:
    "monitorar pessoa sem indicação visível é o que a fronteira do plano
    proíbe". `Execucao.vue` e `Assinatura.vue` rodam FORA desta casca (tela
    cheia, sem a navegação inferior, ver o cabeçalho de `App.vue`), então não
    são alcançadas por esta faixa; `Execucao.vue` replica a mesma faixa no
    próprio arquivo, pelo mesmo motivo (ver o comentário no topo daquele
    arquivo). `Assinatura.vue` fica de fora nesta entrega - lacuna documentada
    aqui, não coberta pelos três arquivos que a Task 22.7 pede.

    "Ver o que é registrado" abre um texto simples (`Modal` comum do projeto),
    não uma tela dedicada: não existe hoje nenhum endpoint de consulta aos
    pontos de rastreamento (a Task 22.4 criou só a configuração de
    consentimento; a captura periódica em si é escopo de uma task futura de
    app, fora desta). O texto do modal documenta essa limitação para o
    técnico, em vez de prometer uma tela que ainda não existe.
-->
<template>
    <div class="min-h-screen flex flex-col bg-gray-50">
        <header class="bg-white border-b border-gray-200 px-4 py-2.5 flex items-center justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-900 truncate">{{ nomeDaEmpresa }}</p>
                <p class="text-xs text-gray-600">{{ dataDeHoje }}</p>
            </div>

            <div class="shrink-0">
                <IndicadorDeSincronizacao @abrir-pendencias="$emit('navegar', 'pendencias')" />
            </div>
        </header>

        <div v-if="rastreamentoContinuoLigado" class="border-b border-blue-200 bg-blue-50 px-4 py-2">
            <div class="flex items-center justify-between gap-2">
                <p class="text-xs text-blue-800">Localização sendo registrada durante o expediente.</p>
                <button
                    type="button"
                    class="shrink-0 text-xs font-medium text-blue-700 underline"
                    @click="mostrandoDetalheRastreamento = true"
                >
                    O que é registrado?
                </button>
            </div>
        </div>

        <main class="flex-1 pb-24">
            <slot />
        </main>

        <nav
            class="fixed inset-x-0 bottom-0 bg-white border-t border-gray-200 flex"
            role="navigation"
            aria-label="Navegação principal"
        >
            <button
                type="button"
                class="flex-1 min-h-[44px] flex flex-col items-center justify-center gap-1 py-2.5 text-xs font-medium"
                :class="paginaAtual === 'dia' ? 'text-green-700' : 'text-gray-500'"
                :aria-current="paginaAtual === 'dia' ? 'page' : undefined"
                @click="$emit('navegar', 'dia')"
            >
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M6.75 3v2.25M17.25 3v2.25M3.75 18.75h16.5A.75.75 0 0021 18V6.75a.75.75 0 00-.75-.75H3.75A.75.75 0 003 6.75V18c0 .414.336.75.75.75zM3 10.5h18"
                    />
                </svg>
                <span>Dia</span>
            </button>

            <button
                type="button"
                class="flex-1 min-h-[44px] flex flex-col items-center justify-center gap-1 py-2.5 text-xs font-medium"
                :class="paginaAtual === 'roteiro' ? 'text-green-700' : 'text-gray-500'"
                :aria-current="paginaAtual === 'roteiro' ? 'page' : undefined"
                @click="$emit('navegar', 'roteiro')"
            >
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M9 6.75L15 3l6 3.75v11.25L15 21l-6-3.75L3 21V9.75l6-3zM9 6.75v11.25M15 3v14.25"
                    />
                </svg>
                <span>Roteiro</span>
            </button>

            <button
                type="button"
                class="flex-1 min-h-[44px] flex flex-col items-center justify-center gap-1 py-2.5 text-xs font-medium"
                :class="paginaAtual === 'pendencias' ? 'text-green-700' : 'text-gray-500'"
                :aria-current="paginaAtual === 'pendencias' ? 'page' : undefined"
                @click="$emit('navegar', 'pendencias')"
            >
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M9 12h6m-6 3.75h6M5.25 5.25h13.5A1.5 1.5 0 0120.25 6.75v13.5a1.5 1.5 0 01-1.5 1.5H5.25a1.5 1.5 0 01-1.5-1.5V6.75a1.5 1.5 0 011.5-1.5zM9 3.75h6a.75.75 0 01.75.75v1.5a.75.75 0 01-.75.75H9a.75.75 0 01-.75-.75v-1.5A.75.75 0 019 3.75z"
                    />
                </svg>
                <span>Pendências</span>
            </button>

            <button
                type="button"
                class="flex-1 min-h-[44px] flex flex-col items-center justify-center gap-1 py-2.5 text-xs font-medium"
                :class="paginaAtual === 'conta' ? 'text-green-700' : 'text-gray-500'"
                :aria-current="paginaAtual === 'conta' ? 'page' : undefined"
                @click="$emit('navegar', 'conta')"
            >
                <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"
                    />
                </svg>
                <span>Conta</span>
            </button>
        </nav>

        <!-- "O que é registrado?" (Task 22.7): texto simples, sem tela
             dedicada - ver o comentário no topo do arquivo para o porquê. -->
        <Modal :show="mostrandoDetalheRastreamento" @close="mostrandoDetalheRastreamento = false">
            <template #icon>
                <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path
                        stroke-linecap="round"
                        stroke-linejoin="round"
                        stroke-width="2"
                        d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z"
                    />
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
            </template>
            <template #title>Localização durante o expediente</template>
            <template #content>
                <p class="text-sm text-gray-700">
                    Sua empresa ligou o rastreamento contínuo para este cadastro de técnico, com o seu consentimento
                    registrado. Enquanto estiver ativo, o local de início e fim de cada visita continua sendo
                    registrado normalmente (com a sua autorização em cada uma).
                </p>
                <p class="mt-3 text-sm text-gray-700">
                    Este aplicativo ainda não tem uma tela com o histórico detalhado dos pontos registrados durante o
                    expediente - fale com sua empresa se quiser saber mais sobre a política de rastreamento.
                </p>
            </template>
            <template #actions>
                <button type="button" class="btn-primary" @click="mostrandoDetalheRastreamento = false">Entendi</button>
            </template>
        </Modal>
    </div>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { obterMeta } from '../db/repositorio';
import IndicadorDeSincronizacao from '../Components/IndicadorDeSincronizacao.vue';
import Modal from '@/Components/Modal.vue';
import { formatarDataExtensa, hojeISO } from '@/utils/formatDate';

defineProps({
    paginaAtual: {
        type: String,
        required: true,
    },
});

defineEmits(['navegar']);

const nomeDaEmpresa = ref('Aplicativo do técnico');
const dataDeHoje = computed(() => formatarDataExtensa(hojeISO()));

// Rastreamento contínuo (Task 22.7): lido uma vez na montagem, mesmo padrão
// já usado abaixo para `meta.empresa` - este layout não escuta mudanças de
// carga em tempo real (nenhuma tela do aplicativo escuta hoje).
const rastreamentoContinuoLigado = ref(false);
const mostrandoDetalheRastreamento = ref(false);

onMounted(async () => {
    // Só carregado depois da primeira carga do dia (Task 12.3), gravado em
    // `meta.empresa` por `salvarCarga()`. Antes disso, fica no rótulo
    // genérico acima - não há empresa nenhuma para mostrar ainda.
    const empresa = await obterMeta('empresa');

    if (empresa?.nome) {
        nomeDaEmpresa.value = empresa.nome;
    }

    rastreamentoContinuoLigado.value = (await obterMeta('rastreamento_continuo_ligado')) === true;
});
</script>
