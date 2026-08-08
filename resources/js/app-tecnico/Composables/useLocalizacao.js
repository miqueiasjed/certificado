// Consentimento de localização para início/fim de execução (Plano 22, Task
// 22.7).
//
// Mesmo espírito de `Assinatura.vue` (Task 13.9, `obterCoordenadaComConsentimento()`):
// o navegador não deixa customizar o texto do prompt nativo de permissão, então
// a explicação é uma UI própria do app, mostrada ANTES de chamar
// `getCurrentPosition()` - nunca depois, e nunca no lugar dela. Este arquivo
// generaliza aquele padrão para ser reusado em mais de uma tela (aqui,
// `Execucao.vue`), com uma regra nova que `Assinatura.vue` não precisava:
// nunca pedir duas vezes para a MESMA ação da MESMA ordem de serviço.
//
// Regra de negócio inegociável (22.7): "consentimento com explicação, uma vez
// por ação, sem insistência - pedido repetido é o que faz o usuário negar de
// vez." Por isso este composable guarda, em memória, quais pares
// `work_order_id + acao` já foram perguntados nesta sessão do aplicativo
// (`acoesJaPedidas`, um `Set` no escopo do módulo, fora da função
// `useLocalizacao()` - de propósito, para o estado sobreviver a qualquer
// componente que monte/desmonte o composable, e ser compartilhado entre todas
// as telas que o usarem). Perguntado uma vez, seja qual for o resultado
// (autorizou, recusou, deu timeout, ou o aparelho nem tem `geolocation`), a
// chamada seguinte para o mesmo par devolve `null` na hora, sem exibir
// explicação nem tocar `navigator.geolocation` de novo.
//
// Não precisa persistir no servidor nem no IndexedDB: é um estado local,
// vale para a sessão do aplicativo aberta agora. Reabrir o aplicativo (app
// fechado e reaberto) reseta o `Set` e permite perguntar de novo - aceitável,
// porque o objetivo da regra é não insistir DENTRO da mesma visita, não
// proibir para sempre um pedido que o próprio ciclo de vida do app já
// reiniciou.
//
// Nenhuma captura de localização em segundo plano acontece aqui: a única
// chamada a `getCurrentPosition()` deste arquivo é a de `pedirLocalizacao()`,
// disparada só quando quem chama decide (Execucao.vue, nos dois momentos
// explícitos de iniciar/concluir). Não há timer, não há `watchPosition()`.

import { ref } from 'vue';

/**
 * Tempo limite do pedido de localização. GPS pode demorar para travar,
 * principalmente offline (sem assistência de rede para acelerar a
 * primeira leitura) - por isso o valor fica na faixa alta do razoável
 * (5-8s), mesmo critério já usado em `Assinatura.vue`
 * (`TEMPO_LIMITE_LOCALIZACAO_MS`).
 */
const TEMPO_LIMITE_MS = 7000;

/**
 * Aceita uma posição já lida há até um minuto como resposta imediata, sem
 * esperar uma leitura nova do zero. Mesmo valor de `Assinatura.vue`.
 */
const IDADE_MAXIMA_MS = 60000;

/**
 * Pares `work_order_id:acao` já perguntados nesta sessão do aplicativo. Ver o
 * comentário no topo do arquivo para o porquê de viver fora de
 * `useLocalizacao()`.
 *
 * @type {Set<string>}
 */
const acoesJaPedidas = new Set();

function chaveDaAcao(workOrderId, acao) {
    return `${workOrderId}:${acao}`;
}

/**
 * `getCurrentPosition()` envolvido em promise que NUNCA rejeita: recusa,
 * indisponibilidade (`navigator.geolocation` inexistente, comum em contexto
 * não-seguro) e timeout resolvem para `null`. É isso que garante que pedir
 * localização nunca trava nem impede o fluxo de iniciar/concluir a OS -
 * regra inegociável da Task 22.7 ("recusar nunca impede iniciar nem
 * concluir").
 */
function lerPosicaoDoAparelho() {
    return new Promise((resolve) => {
        if (typeof navigator === 'undefined' || !navigator.geolocation) {
            resolve(null);
            return;
        }

        navigator.geolocation.getCurrentPosition(
            (posicao) => {
                resolve({
                    latitude: posicao.coords.latitude,
                    longitude: posicao.coords.longitude,
                });
            },
            () => resolve(null),
            { enableHighAccuracy: false, timeout: TEMPO_LIMITE_MS, maximumAge: IDADE_MAXIMA_MS },
        );
    });
}

export function useLocalizacao() {
    // Estado reativo para quem chama exibir a explicação na tela enquanto o
    // pedido está em andamento (o tempo entre mostrar a frase e o navegador
    // resolver o próprio prompt nativo de permissão, que este código não
    // controla). `Execucao.vue` liga estes dois refs a uma faixa simples no
    // próprio template.
    const pedindoLocalizacao = ref(false);
    const explicacaoAtual = ref('');

    /**
     * Pede a localização atual, com explicação em uma frase mostrada ANTES
     * de `getCurrentPosition()` ser chamado.
     *
     * `identificador`, quando informado como `{ workOrderId, acao }`, é o que
     * torna o pedido "uma vez por ação, nunca repete na mesma OS": a segunda
     * chamada com o mesmo par devolve `null` na hora, sem mostrar a
     * explicação nem tocar no GPS de novo. Sem `identificador` (uso livre,
     * fora do fluxo de execução), o composable pede sempre, sem essa
     * memória - quem chama decide se precisa dela.
     *
     * Devolve `{ latitude, longitude }` quando autorizado, ou `null` em
     * qualquer outro caso (recusa, timeout, aparelho sem suporte, ou pedido
     * repetido para o mesmo par). Nunca lança exceção.
     *
     * @param {string} explicacao Frase mostrada antes do pedido.
     * @param {{ workOrderId?: number|string, acao?: string }} [identificador]
     * @returns {Promise<{latitude: number, longitude: number}|null>}
     */
    async function pedirLocalizacao(explicacao, identificador = {}) {
        const { workOrderId, acao } = identificador;
        const chave = workOrderId != null && acao ? chaveDaAcao(workOrderId, acao) : null;

        if (chave !== null && acoesJaPedidas.has(chave)) {
            return null;
        }

        explicacaoAtual.value = explicacao;
        pedindoLocalizacao.value = true;

        try {
            return await lerPosicaoDoAparelho();
        } finally {
            pedindoLocalizacao.value = false;

            if (chave !== null) {
                acoesJaPedidas.add(chave);
            }
        }
    }

    return { pedirLocalizacao, pedindoLocalizacao, explicacaoAtual };
}
