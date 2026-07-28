<!--
    Lista das OS do dia (Plano 12, Task 12.9).

    Regra de ouro: tudo aqui sai do IndexedDB via `useCargaDoDia()`
    (Task 12.7), nunca da rede - a tela pinta com o que já está no aparelho,
    e "recarregar" é a única ação que toca o servidor, sempre opcional e
    nunca bloqueante. Sem rede não é erro, é o estado normal do técnico em
    campo.
-->
<template>
    <div class="max-w-lg mx-auto px-4 py-4 space-y-4">
        <div class="flex items-center gap-2">
            <label for="dia-selecionado" class="sr-only">Dia</label>
            <select
                id="dia-selecionado"
                v-model="diaSelecionado"
                class="flex-1 min-h-[44px] px-3 py-2 border border-gray-300 rounded-md shadow-sm text-base font-medium text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
                <option v-for="dia in diasParaSelecionar" :key="dia" :value="dia">
                    {{ rotuloDoDia(dia) }}
                </option>
            </select>

            <button
                type="button"
                class="btn-secondary-sm min-h-[44px] px-4 shrink-0 text-sm"
                :disabled="!online || carregando"
                @click="recarregar"
            >
                {{ carregando ? 'Atualizando...' : 'Atualizar' }}
            </button>
        </div>

        <p v-if="!online" class="text-xs text-gray-600">
            Sem conexão agora. A lista mostrada é a última carregada neste aparelho.
        </p>

        <div v-if="desatualizada" class="rounded-md bg-yellow-50 border border-yellow-200 p-3">
            <p class="text-sm text-yellow-800">
                Estes dados têm {{ idadeArredondada }} {{ idadeArredondada === 1 ? 'hora' : 'horas' }}.
                Atualize quando tiver conexão.
            </p>
        </div>

        <p v-if="erro" class="text-xs text-gray-600">{{ erro }}</p>

        <h1 class="text-lg font-semibold text-gray-900">{{ formatarDataExtensa(diaSelecionado) }}</h1>

        <div v-if="ordensDoDia.length === 0" class="text-center py-12">
            <p class="text-sm text-gray-600">Nenhuma visita para este dia.</p>
        </div>

        <div v-else class="space-y-6">
            <section v-for="secao in secoesComOrdens" :key="secao.chave">
                <h2 class="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
                    {{ secao.titulo }}
                </h2>

                <div class="space-y-3">
                    <article
                        v-for="ordem in secao.ordens"
                        :key="ordem.id"
                        class="bg-white rounded-lg border border-gray-200 shadow-sm p-4"
                    >
                        <div class="flex items-start justify-between gap-2 mb-2">
                            <h3 class="text-sm font-semibold text-gray-900 truncate">
                                {{ ordem.cliente?.nome || 'Cliente não identificado' }}
                            </h3>
                            <span
                                class="shrink-0 text-xs font-medium px-2 py-1 rounded-full"
                                :class="situacaoDaOrdem(ordem).classe"
                            >
                                {{ situacaoDaOrdem(ordem).rotulo }}
                            </span>
                        </div>

                        <p class="text-sm text-gray-700 mb-2">{{ enderecoResumido(ordem) }}</p>

                        <div class="flex items-center justify-between text-sm text-gray-600 gap-2">
                            <span class="shrink-0">{{ horarioDaOrdem(ordem) }}</span>
                            <span class="truncate text-right">{{ tipoDeServico(ordem) }}</span>
                        </div>
                    </article>
                </div>
            </section>
        </div>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useCargaDoDia } from '../Composables/useCargaDoDia';
import { obterMeta } from '../db/repositorio';
import { aoMudar, listarOrdensComPendencia } from '../sync/fila';
import { formatarData, formatarDataExtensa, formatarHora, hojeISO } from '@/utils/formatDate';

const {
    todasAsOrdens,
    ordensDoDia,
    diaSelecionado,
    carregando,
    ultimaCarga,
    idadeEmHoras,
    desatualizada,
    erro,
    recarregar,
} = useCargaDoDia();

// -----------------------------------------------------------------------
// Conectividade: só decide o estado do botão "Atualizar" e o aviso; nunca
// impede a leitura local, que funciona sempre, com ou sem rede.
// -----------------------------------------------------------------------

const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);

function aoFicarOnline() {
    online.value = true;
}

function aoFicarOffline() {
    online.value = false;
}

// -----------------------------------------------------------------------
// Situações locais ainda não confirmadas pelo servidor (Task 12.8): sobrepõe
// o status vindo da carga com "Pendente de envio" quando existe algo na fila
// para aquela ordem.
// -----------------------------------------------------------------------

const ordensComPendencia = ref(new Set());
let pararDeEscutarFila = null;

async function atualizarOrdensComPendencia() {
    ordensComPendencia.value = await listarOrdensComPendencia();
}

onMounted(() => {
    window.addEventListener('online', aoFicarOnline);
    window.addEventListener('offline', aoFicarOffline);

    atualizarOrdensComPendencia();
    pararDeEscutarFila = aoMudar(atualizarOrdensComPendencia);
});

onUnmounted(() => {
    window.removeEventListener('online', aoFicarOnline);
    window.removeEventListener('offline', aoFicarOffline);

    if (pararDeEscutarFila) {
        pararDeEscutarFila();
    }
});

// Primeira carga da vida do aparelho: ninguém sincronizou ainda. Busca no
// servidor automaticamente (só desta vez - `ultima_carga` deixa de ser nula
// depois), sem esperar o técnico descobrir o botão "Atualizar" sozinho.
// A tela já pintou o estado local (vazio) antes desta chamada, então isso
// nunca atrasa a primeira pintura.
onMounted(async () => {
    const jaTeveCarga = (await obterMeta('ultima_carga')) !== null;

    if (!jaTeveCarga && online.value) {
        recarregar();
    }
});

// -----------------------------------------------------------------------
// Seletor de dia: só os dias presentes na carga local, nunca um calendário
// livre - trocar de dia aqui nunca toca rede.
// -----------------------------------------------------------------------

const diasParaSelecionar = computed(() => {
    const dias = new Set(todasAsOrdens.value.map((ordem) => ordem.data_agendada).filter(Boolean));
    dias.add(diaSelecionado.value);

    return [...dias].sort();
});

function rotuloDoDia(dia) {
    const rotulo = formatarData(dia);

    return dia === hojeISO() ? `${rotulo} (hoje)` : rotulo;
}

const idadeArredondada = computed(() => (idadeEmHoras.value !== null ? Math.round(idadeEmHoras.value) : null));

// -----------------------------------------------------------------------
// Agrupamento por período: manhã e tarde, pelo horário de início da visita
// no fuso do negócio (`formatarHora`, nunca `toLocaleDateString`). Ordens
// sem horário definido ficam numa terceira seção, para nunca serem
// classificadas erradas.
// -----------------------------------------------------------------------

function periodoDaOrdem(ordem) {
    const horario = formatarHora(ordem.inicio);

    if (!horario) {
        return 'semHorario';
    }

    return Number(horario.split(':')[0]) < 12 ? 'manha' : 'tarde';
}

const grupos = computed(() => {
    const resultado = { manha: [], tarde: [], semHorario: [] };

    for (const ordem of ordensDoDia.value) {
        resultado[periodoDaOrdem(ordem)].push(ordem);
    }

    return resultado;
});

const secoesComOrdens = computed(() =>
    [
        { chave: 'manha', titulo: 'Manhã', ordens: grupos.value.manha },
        { chave: 'tarde', titulo: 'Tarde', ordens: grupos.value.tarde },
        { chave: 'semHorario', titulo: 'Sem horário definido', ordens: grupos.value.semHorario },
    ].filter((secao) => secao.ordens.length > 0),
);

// -----------------------------------------------------------------------
// Conteúdo do cartão: cliente, endereço resumido, horário, tipo de serviço
// e situação. Nenhum campo de valor, custo ou margem - a carga do backend já
// não traz isso (AppDayLoadService).
// -----------------------------------------------------------------------

function enderecoResumido(ordem) {
    const endereco = ordem.endereco;

    if (!endereco) {
        return 'Endereço não informado';
    }

    const partes = [[endereco.logradouro, endereco.numero].filter(Boolean).join(', '), endereco.bairro, endereco.cidade].filter(
        Boolean,
    );

    return partes.join(' - ') || 'Endereço não informado';
}

function horarioDaOrdem(ordem) {
    const inicio = formatarHora(ordem.inicio);
    const fim = formatarHora(ordem.fim);

    if (inicio && fim) {
        return `${inicio} - ${fim}`;
    }

    return inicio || 'Horário não definido';
}

function tipoDeServico(ordem) {
    const servicos = Array.isArray(ordem.servicos) ? ordem.servicos : [];
    const nomes = servicos.map((servico) => servico.nome).filter(Boolean);

    if (nomes.length === 0) {
        return 'Serviço não informado';
    }

    if (nomes.length <= 2) {
        return nomes.join(' e ');
    }

    return `${nomes[0]} e mais ${nomes.length - 1}`;
}

const ROTULOS_DE_STATUS = {
    pending: { rotulo: 'A fazer', classe: 'bg-gray-100 text-gray-800' },
    scheduled: { rotulo: 'A fazer', classe: 'bg-gray-100 text-gray-800' },
    in_progress: { rotulo: 'Em execução', classe: 'bg-blue-100 text-blue-800' },
    completed: { rotulo: 'Concluída', classe: 'bg-green-100 text-green-800' },
    on_hold: { rotulo: 'Em espera', classe: 'bg-gray-100 text-gray-800' },
    cancelled: { rotulo: 'Cancelada', classe: 'bg-red-100 text-red-800' },
};
const SITUACAO_PADRAO = { rotulo: 'A fazer', classe: 'bg-gray-100 text-gray-800' };
const SITUACAO_PENDENTE_DE_ENVIO = { rotulo: 'Pendente de envio', classe: 'bg-yellow-100 text-yellow-800' };

function situacaoDaOrdem(ordem) {
    if (ordensComPendencia.value.has(ordem.id)) {
        return SITUACAO_PENDENTE_DE_ENVIO;
    }

    return ROTULOS_DE_STATUS[ordem.status] || SITUACAO_PADRAO;
}
</script>
