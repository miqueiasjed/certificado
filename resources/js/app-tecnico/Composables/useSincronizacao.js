// Composable de sincronização do aplicativo do técnico (Plano 12, Task 12.8).
//
// O indicador do cabeçalho (Task 12.10) e a tela de Pendências mostram o
// mesmo número ao mesmo tempo, então o estado reativo vive no escopo do
// módulo (um só por aba), não dentro da função exportada: toda chamada a
// `useSincronizacao()` devolve os mesmos refs, já compartilhados.
//
// Atualização por evento, não por polling puro: `fila.js` avisa em todo
// enfileirar/transição de situação, e `sincronizador.js` avisa a cada lote
// processado (sucesso ou fim de ciclo). Um intervalo de reforço cobre a
// janela entre o carregamento deste módulo e o primeiro evento.

import { ref, onMounted } from 'vue';
import { contarPendentes, listarEmConflito, listarComFalha, aoMudar } from '../sync/fila';
import { iniciar, sincronizarAgora, aoSincronizar } from '../sync/sincronizador';

const INTERVALO_DE_REFORCO_MS = 15 * 1000;

const pendentes = ref(0);
const emConflito = ref(0);
const comFalha = ref(0);
const sincronizando = ref(false);
const ultimaSincronizacao = ref(null);

let observadoresConectados = false;

/**
 * Relê as três contagens da fila local. Sempre da base local, nunca do
 * servidor: é o que garante que "o contador de pendentes bate com o conteúdo
 * da fila" mesmo sem rede.
 */
async function atualizarContagens() {
    const [totalPendentes, conflitos, falhas] = await Promise.all([
        contarPendentes(),
        listarEmConflito(),
        listarComFalha(),
    ]);

    pendentes.value = totalPendentes;
    emConflito.value = conflitos.length;
    comFalha.value = falhas.length;
}

function conectarObservadores() {
    if (observadoresConectados) return;
    observadoresConectados = true;

    aoMudar(atualizarContagens);

    aoSincronizar((estado) => {
        if (typeof estado.sincronizando === 'boolean') {
            sincronizando.value = estado.sincronizando;
        }

        if (estado.ultimaSincronizacao) {
            ultimaSincronizacao.value = estado.ultimaSincronizacao;
        }

        atualizarContagens();
    });

    setInterval(atualizarContagens, INTERVALO_DE_REFORCO_MS);
}

export function useSincronizacao() {
    conectarObservadores();

    onMounted(() => {
        atualizarContagens();
        iniciar();
    });

    return {
        pendentes,
        emConflito,
        comFalha,
        sincronizando,
        ultimaSincronizacao,
        forcarEnvio: () => sincronizarAgora({ forcado: true }),
    };
}
