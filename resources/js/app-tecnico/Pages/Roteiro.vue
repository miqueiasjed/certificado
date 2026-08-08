<!--
    Roteiro do dia do técnico (Plano 22, Task 22.7).

    Mesma regra de ouro de `Dia.vue` (Task 12.9): a tela pinta primeiro com o
    que já está salvo localmente (`meta.roteiro`, IndexedDB), nunca esperando
    rede. Sem rede não é erro, é o estado normal do técnico em campo.

    ## De onde vem `meta.roteiro`

    `AppDayLoadService::carregar()` já manda a chave `roteiro` na carga do dia
    desde a Task 22.5 (formato mínimo: id, data, situacao, ordenacao_manual,
    distancia_total_km, duracao_estimada_min e uma lista de paradas com
    work_order_id/ordem/chegada_estimada/latitude/longitude - SEM cliente,
    endereço nem distância por trecho, que ficam nas ordens já carregadas
    separadamente). `repositorio.js::salvarCarga()` grava essa chave em
    `meta.roteiro` (buraco fechado nesta mesma task - antes desta task, nada
    persistia essa chave, e o roteiro nunca sobreviveria a um reload
    offline).

    Quando `meta.roteiro` está vazio (nenhuma carga trouxe um roteiro ainda -
    ou porque ninguém montou o roteiro do dia no servidor, ou porque a base
    local é anterior à Task 22.5) e o aparelho está online, esta tela busca
    sob demanda em `GET /api/app/roteiro?data=hoje` (mesmo endpoint que
    `RouteController::appRoteiro()` expõe, Task 22.5) e salva o resultado, no
    mesmo formato mínimo, para a próxima abertura já vir da base local.

    ## Cliente, endereço e "distância desde a anterior"

    O formato mínimo de `meta.roteiro` não traz cliente/endereço (isso já
    existe em `db.ordens`, carregado pela carga do dia) nem a distância por
    trecho já calculada pelo backend (`RouteStop::distancia_anterior_km`, que
    só existe na apresentação mais rica de `RouteController::apresentarRota()`
    - painel web e resposta "ao vivo" do endpoint, não no formato salvo
    localmente). Por isso esta tela junta cada parada com a ordem
    correspondente (`obterOrdem()`) e recalcula a distância desde a anterior
    localmente, com a mesma fórmula do backend (Haversine + fator de correção
    viária 1,3, `EstimadorDeDeslocamento`, Task 22.3) - é uma reimplementação
    pequena e deliberada, não um capricho: duplicar aqui é mais simples do que
    inflar o formato salvo offline só para carregar um número que dá para
    recalcular a partir de dois pontos já disponíveis.

    ## Parada sem coordenada

    O backend já ordena as paradas sem coordenada útil para o FIM do roteiro
    (`OtimizadorDeRota`, Task 22.3) - confirmado lendo aquela classe antes de
    escrever este arquivo. Esta tela nunca reordena a lista recebida: só
    identifica a parada sem `latitude`/`longitude` para mostrar o aviso e não
    contar distância a partir dela (mesmo critério do backend: uma parada sem
    coordenada não serve de referência para a distância da parada seguinte).

    Sem `precisao_geocodificacao` no formato mínimo salvo offline (só existe
    na apresentação rica do endpoint), esta tela não distingue "sem
    coordenada" de "coordenada de baixíssima precisão (nível cidade)" como o
    painel web faz - trata só a ausência de lat/lng. Ver Task 22.6 (painel)
    para a versão completa dessa distinção.

    ## "Abrir no mapa"

    Delega ao aplicativo de navegação do celular via link universal do Google
    Maps (`google.com/maps/dir/?api=1&destination=...`), que também abre no
    Apple Maps em iOS na prática - nenhum SDK de mapa entra neste aplicativo.
    Com coordenada, o destino é `lat,lng`; sem coordenada, cai para o
    endereço em texto (o link aceita os dois formatos) - a parada continua
    precisando ser visitada mesmo sem coordenada cadastrada.
-->
<template>
    <div class="max-w-lg mx-auto px-4 py-4 space-y-4">
        <div class="flex items-center justify-between gap-2">
            <h1 class="text-lg font-semibold text-gray-900">Roteiro de hoje</h1>

            <button
                type="button"
                class="btn-secondary-sm min-h-[44px] px-4 shrink-0 text-sm"
                :disabled="!online || carregando"
                @click="atualizar"
            >
                {{ carregando ? 'Atualizando...' : 'Atualizar' }}
            </button>
        </div>

        <p v-if="!online" class="text-xs text-gray-600">
            Sem conexão agora. Mostrando o último roteiro salvo neste aparelho.
        </p>

        <p v-if="erro" class="text-xs text-gray-600">{{ erro }}</p>

        <div v-if="carregandoPrimeiraVez" class="text-center py-12">
            <p class="text-sm text-gray-500">Carregando roteiro...</p>
        </div>

        <div v-else-if="paradas.length === 0" class="text-center py-12">
            <p class="text-sm text-gray-600">Nenhuma parada no roteiro de hoje.</p>
        </div>

        <template v-else>
            <div class="flex items-center justify-between gap-2 rounded-md border border-gray-200 bg-gray-50 p-3 text-sm text-gray-700">
                <span>{{ paradas.length }} {{ paradas.length === 1 ? 'parada' : 'paradas' }}</span>
                <span v-if="distanciaTotalTexto">{{ distanciaTotalTexto }}</span>
            </div>

            <ol class="space-y-3">
                <li
                    v-for="(parada, indice) in paradas"
                    :key="parada.workOrderId"
                    class="rounded-lg border border-gray-200 bg-white p-4 shadow-sm"
                >
                    <div class="flex items-start gap-3">
                        <span
                            class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold"
                            :class="parada.temCoordenada ? 'bg-green-100 text-green-800' : 'bg-yellow-100 text-yellow-800'"
                        >
                            {{ indice + 1 }}
                        </span>

                        <div
                            class="min-w-0 flex-1 cursor-pointer"
                            role="button"
                            tabindex="0"
                            @click="$emit('abrir-ordem', parada.workOrderId)"
                            @keydown.enter="$emit('abrir-ordem', parada.workOrderId)"
                        >
                            <div class="flex items-start justify-between gap-2">
                                <h3 class="truncate text-sm font-semibold text-gray-900">
                                    {{ parada.cliente?.nome || 'Cliente não identificado' }}
                                </h3>
                                <span class="shrink-0 text-xs text-gray-600">{{ parada.horarioPrevisto || '--:--' }}</span>
                            </div>

                            <p class="mt-1 text-sm text-gray-700">{{ parada.enderecoResumido }}</p>

                            <p v-if="!parada.temCoordenada" class="mt-2 text-xs font-medium text-yellow-700">
                                Sem coordenada cadastrada. Confirme o endereço antes de sair.
                            </p>
                            <p v-else-if="parada.distanciaAnteriorTexto" class="mt-2 text-xs text-gray-500">
                                {{ parada.distanciaAnteriorTexto }} desde a parada anterior
                            </p>
                        </div>
                    </div>

                    <a
                        v-if="parada.linkMapa"
                        :href="parada.linkMapa"
                        target="_blank"
                        rel="noopener"
                        class="btn-secondary-sm mt-3 inline-flex min-h-[40px] items-center px-3 text-sm"
                        @click.stop
                    >
                        Abrir no mapa
                    </a>
                </li>
            </ol>
        </template>
    </div>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { obterMeta, obterOrdem, definirMeta } from '../db/repositorio';
import { formatarHora, hojeISO } from '@/utils/formatDate';

// Tocar numa parada abre a execução daquela OS, mesmo padrão de `Dia.vue`
// (Task 12.9) - `App.vue` decide o que fazer com o id emitido aqui.
defineEmits(['abrir-ordem']);

// Mesma fórmula de `EstimadorDeDeslocamento` (backend, Task 22.3): Haversine
// para a distância em linha reta, corrigida pelo fator de área urbana. Ver o
// comentário no topo do arquivo para o porquê de recalcular aqui em vez de
// carregar um número pronto.
const RAIO_DA_TERRA_KM = 6371;
const FATOR_CORRECAO_VIARIA = 1.3;

const CHAVE_META_ROTEIRO = 'roteiro';
const CHAVE_META_TOKEN = 'token';

const roteiroBruto = ref(null);
const ordensPorId = ref({});
const carregando = ref(false);
const carregandoPrimeiraVez = ref(true);
const erro = ref(null);
const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);

function aoFicarOnline() {
    online.value = true;
}

function aoFicarOffline() {
    online.value = false;
}

async function carregarOrdensDasParadas(listaDeParadas) {
    const mapa = {};

    for (const parada of listaDeParadas) {
        mapa[parada.work_order_id] = await obterOrdem(parada.work_order_id);
    }

    ordensPorId.value = mapa;
}

async function carregarDoDispositivo() {
    const salvo = await obterMeta(CHAVE_META_ROTEIRO);
    roteiroBruto.value = salvo;

    if (salvo?.paradas?.length) {
        await carregarOrdensDasParadas(salvo.paradas);
    }
}

/**
 * Busca o roteiro de hoje sob demanda (`GET /api/app/roteiro?data=`) e
 * reduz a resposta ao mesmo formato mínimo que a carga do dia salva
 * localmente - ver o comentário no topo do arquivo. É o único caminho deste
 * arquivo que monta/otimiza algo do lado do servidor (o próprio endpoint
 * decide isso, esta tela só chama e guarda o resultado).
 */
async function buscarNoServidor() {
    const token = await obterMeta(CHAVE_META_TOKEN);

    const resposta = await fetch(`/api/app/roteiro?data=${encodeURIComponent(hojeISO())}`, {
        headers: {
            Accept: 'application/json',
            ...(token ? { Authorization: `Bearer ${token}` } : {}),
        },
    });

    if (!resposta.ok) {
        throw new Error(`Não foi possível buscar o roteiro agora (HTTP ${resposta.status}).`);
    }

    const dados = await resposta.json();

    const normalizado = {
        id: dados.id,
        data: dados.data,
        situacao: dados.situacao,
        ordenacao_manual: dados.ordenacao_manual,
        distancia_total_km: dados.distancia_total_km ?? null,
        duracao_estimada_min: dados.duracao_estimada_min ?? null,
        paradas: (Array.isArray(dados.paradas) ? dados.paradas : []).map((parada) => ({
            work_order_id: parada.work_order_id,
            ordem: parada.ordem,
            chegada_estimada: parada.chegada_estimada,
            latitude: parada.latitude,
            longitude: parada.longitude,
        })),
    };

    await definirMeta(CHAVE_META_ROTEIRO, normalizado);
    roteiroBruto.value = normalizado;
    await carregarOrdensDasParadas(normalizado.paradas);
}

async function atualizar() {
    if (!online.value || carregando.value) {
        return;
    }

    carregando.value = true;
    erro.value = null;

    try {
        await buscarNoServidor();
    } catch (erroCapturado) {
        erro.value = erroCapturado?.message || 'Não foi possível atualizar o roteiro agora.';
    } finally {
        carregando.value = false;
    }
}

onMounted(async () => {
    window.addEventListener('online', aoFicarOnline);
    window.addEventListener('offline', aoFicarOffline);

    carregandoPrimeiraVez.value = true;

    try {
        await carregarDoDispositivo();

        // Roteiro do dia ainda não montado neste aparelho (carga sem
        // `roteiro`, ou base local anterior à Task 22.5): busca sob demanda,
        // exatamente como `AppDayLoadService::carregarRoteiro()` documenta
        // (a carga nunca monta um roteiro por conta própria, só lê o que já
        // existe).
        if (!roteiroBruto.value && online.value) {
            await atualizar();
        }
    } finally {
        carregandoPrimeiraVez.value = false;
    }
});

onUnmounted(() => {
    window.removeEventListener('online', aoFicarOnline);
    window.removeEventListener('offline', aoFicarOffline);
});

// -----------------------------------------------------------------------
// Apresentação
// -----------------------------------------------------------------------

function temCoordenada(parada) {
    return (
        parada.latitude !== null
        && parada.latitude !== undefined
        && parada.longitude !== null
        && parada.longitude !== undefined
    );
}

function distanciaEmKm(de, para) {
    const latDe = (de.latitude * Math.PI) / 180;
    const latPara = (para.latitude * Math.PI) / 180;
    const deltaLat = ((para.latitude - de.latitude) * Math.PI) / 180;
    const deltaLng = ((para.longitude - de.longitude) * Math.PI) / 180;

    const a = Math.sin(deltaLat / 2) ** 2 + Math.cos(latDe) * Math.cos(latPara) * Math.sin(deltaLng / 2) ** 2;
    const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));

    return RAIO_DA_TERRA_KM * c * FATOR_CORRECAO_VIARIA;
}

function formatarKm(valorEmKm) {
    return `${valorEmKm.toFixed(1).replace('.', ',')} km`;
}

function enderecoResumido(endereco) {
    if (!endereco) {
        return 'Endereço não informado';
    }

    const partes = [
        [endereco.logradouro, endereco.numero].filter(Boolean).join(', '),
        endereco.bairro,
        endereco.cidade,
    ].filter(Boolean);

    return partes.join(' - ') || 'Endereço não informado';
}

function montarLinkMapa(parada, endereco) {
    if (temCoordenada(parada)) {
        return `https://www.google.com/maps/dir/?api=1&destination=${parada.latitude},${parada.longitude}`;
    }

    const texto = endereco ? enderecoResumido(endereco) : '';

    return texto && texto !== 'Endereço não informado'
        ? `https://www.google.com/maps/dir/?api=1&destination=${encodeURIComponent(texto)}`
        : null;
}

// Junta cada parada (roteiro) com a ordem correspondente (carga) e calcula a
// distância desde a anterior - só entre paradas com coordenada, na mesma
// ordem em que o backend já devolveu a lista (paradas sem coordenada útil já
// chegam no fim, ver o comentário no topo do arquivo).
const paradas = computed(() => {
    const listaBruta = roteiroBruto.value?.paradas ?? [];
    let anterior = null;

    return listaBruta.map((parada) => {
        const ordem = ordensPorId.value[parada.work_order_id] ?? null;
        const coordenadaOk = temCoordenada(parada);

        let distanciaAnteriorTexto = null;

        if (coordenadaOk && anterior) {
            distanciaAnteriorTexto = formatarKm(distanciaEmKm(anterior, parada));
        }

        if (coordenadaOk) {
            anterior = parada;
        }

        return {
            workOrderId: parada.work_order_id,
            horarioPrevisto: formatarHora(parada.chegada_estimada),
            temCoordenada: coordenadaOk,
            distanciaAnteriorTexto,
            cliente: ordem?.cliente ?? null,
            enderecoResumido: enderecoResumido(ordem?.endereco),
            linkMapa: montarLinkMapa(parada, ordem?.endereco),
        };
    });
});

const distanciaTotalTexto = computed(() => {
    const valor = roteiroBruto.value?.distancia_total_km;

    return typeof valor === 'number' ? formatarKm(valor) : null;
});
</script>
