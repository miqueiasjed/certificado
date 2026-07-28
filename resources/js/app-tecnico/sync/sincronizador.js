// Sincronizador da fila do aplicativo do técnico (Plano 12, Task 12.8).
//
// Orquestra o envio da fila local (`fila.js`) para `POST /api/app/sincronizar`
// (Task 12.5): dispara ao voltar a conexão, ao abrir o aplicativo e a cada 2
// minutos com rede; envia em lotes de até 50, em ordem de `registrada_em`, um
// lote de cada vez, nunca em paralelo - a ordem entre lotes e dentro do lote
// importa, porque a conclusão de uma OS não pode chegar ao servidor antes do
// evento que ela conclui.
//
// `fila.js` é o único lugar que toca a tabela `db.fila` diretamente; este
// arquivo só chama as transições que ela expõe.

import { obterMeta } from '../db/repositorio';
import {
    obterProximoLote,
    marcarComoEnviando,
    reverterEnviandoParaPendente,
    reagendarTentativa,
    marcarComoFalhaDefinitiva,
    aplicarResultados,
} from './fila';

const URL_SINCRONIZAR = '/api/app/sincronizar';
const CHAVE_DO_TOKEN = 'token';

const TAMANHO_DO_LOTE = 50; // limite do próprio backend (Task 12.5) - nunca subir isso.
const INTERVALO_DE_SINCRONIZACAO_MS = 2 * 60 * 1000;

/**
 * Espera mínima antes de repetir, indexada pela contagem de tentativas já
 * feitas (1ª falha espera o índice 0, e assim por diante). Cinco valores para
 * um teto de seis tentativas: a sexta falha não agenda espera nova, marca
 * `falha` definitiva.
 */
const ESPERAS_MS = [
    5 * 1000, // após a 1ª falha
    30 * 1000, // após a 2ª falha
    2 * 60 * 1000, // após a 3ª falha
    10 * 60 * 1000, // após a 4ª falha
    30 * 60 * 1000, // após a 5ª falha
];
const TETO_DE_TENTATIVAS = 6;

/**
 * Calcula a espera mínima, em milissegundos, antes da próxima tentativa.
 * `tentativas` é a contagem já incrementada por esta falha (1 a 5 - a 6ª
 * nunca chega aqui, vai direto para `atingiuTetoDeTentativas`). Função pura,
 * exportada para poder ser conferida isoladamente (não há Vitest/Jest neste
 * projeto no momento desta task - ver relatório da Task 12.8 sobre o que
 * ficou sem teste automatizado).
 */
export function calcularEsperaMs(tentativas) {
    const indice = Math.min(Math.max(tentativas, 1), ESPERAS_MS.length) - 1;

    return ESPERAS_MS[indice];
}

/**
 * `true` quando a operação já foi tentada o máximo de vezes e deve parar de
 * ser repetida sozinha, ficando visível em `falha` (Task 12.10 dá o botão de
 * tentar de novo, manual).
 */
export function atingiuTetoDeTentativas(tentativas) {
    return tentativas >= TETO_DE_TENTATIVAS;
}

// -----------------------------------------------------------------------
// Estado do ciclo (evento, não polling - ver `useSincronizacao.js`)
// -----------------------------------------------------------------------

const escutadores = new Set();

export function aoSincronizar(callback) {
    escutadores.add(callback);

    return () => escutadores.delete(callback);
}

function notificar(estadoParcial) {
    escutadores.forEach((callback) => callback(estadoParcial));
}

let emAndamento = false; // nunca dois ciclos de envio ao mesmo tempo.
let sessaoExpirada = false; // 401: para de tentar sozinho até o próximo login.
let iniciado = false;

async function obterToken() {
    return obterMeta(CHAVE_DO_TOKEN);
}

function paraPayloadDeEnvio(operacao) {
    return {
        uuid: operacao.uuid,
        tipo: operacao.tipo,
        work_order_id: operacao.work_order_id,
        registrada_em: operacao.registrada_em,
        updated_at_conhecido: operacao.updated_at_conhecido,
        payload: operacao.payload,
    };
}

async function extrairMensagemDeErro(resposta) {
    try {
        const corpo = await resposta.json();

        return corpo?.message || corpo?.mensagem || `Erro HTTP ${resposta.status}.`;
    } catch {
        return `Erro HTTP ${resposta.status}.`;
    }
}

/**
 * Erro transitório (5xx, 429 ou falha de rede/transporte, sem resposta do
 * servidor): agenda a próxima tentativa com espera crescente, ou marca falha
 * definitiva quando o teto é atingido. Cada operação do lote é tratada pela
 * própria contagem de tentativas (não uma contagem só do lote), para ficar
 * correto mesmo se um reenvio manual futuro (Task 12.10) reiniciar a
 * contagem de um item isolado.
 */
async function registrarFalhaTemporaria(operacoesDoLote, mensagemDeErro) {
    await Promise.all(
        operacoesDoLote.map((operacao) => {
            const tentativas = (operacao.tentativas || 0) + 1;

            if (atingiuTetoDeTentativas(tentativas)) {
                return marcarComoFalhaDefinitiva([operacao.uuid], mensagemDeErro);
            }

            const proximaTentativaEm = new Date(Date.now() + calcularEsperaMs(tentativas)).toISOString();

            return reagendarTentativa(operacao.uuid, tentativas, mensagemDeErro, proximaTentativaEm);
        }),
    );
}

/**
 * Envia um único lote e traduz a resposta em transição de situação na fila.
 * Devolve `{ parar, sucesso }`: `parar` interrompe o ciclo (preserva a ordem
 * entre lotes - nunca pula para o lote seguinte depois de uma falha ou de uma
 * sessão expirada); `sucesso` diz se a requisição chegou e voltou (200),
 * mesmo que operações individuais tenham vindo em conflito ou recusadas.
 */
async function processarUmLote(operacoesDoLote, token) {
    const uuids = operacoesDoLote.map((operacao) => operacao.uuid);

    await marcarComoEnviando(uuids);

    let resposta;

    try {
        resposta = await fetch(URL_SINCRONIZAR, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
            body: JSON.stringify({ operacoes: operacoesDoLote.map(paraPayloadDeEnvio) }),
        });
    } catch (erroDeRede) {
        // Sem resposta nenhuma do servidor: mesma trilha de um 5xx, porque o
        // motivo mais comum é uma rede que caiu no meio da chamada.
        await registrarFalhaTemporaria(operacoesDoLote, erroDeRede?.message || 'Falha de rede ao sincronizar.');

        return { parar: true, sucesso: false };
    }

    if (resposta.status === 401) {
        // A sessão caiu, não a operação: reverte para pendente sem tocar em
        // tentativas nem em ultimo_erro, e para de tentar sozinho até o
        // próximo login (`retomarAposLogin()`).
        await reverterEnviandoParaPendente(uuids);
        sessaoExpirada = true;

        return { parar: true, sucesso: false };
    }

    if (!resposta.ok) {
        const mensagem = await extrairMensagemDeErro(resposta);

        if (resposta.status === 429 || resposta.status >= 500) {
            await registrarFalhaTemporaria(operacoesDoLote, mensagem);
        } else {
            // 4xx que não é 429: repetir requisição malformada é ciclo
            // infinito, então marca falha direto, sem agendar nova tentativa.
            await marcarComoFalhaDefinitiva(uuids, mensagem);
        }

        return { parar: true, sucesso: false };
    }

    const corpo = await resposta.json().catch(() => null);
    const resultados = Array.isArray(corpo?.resultados) ? corpo.resultados : [];

    await aplicarResultados(resultados);

    // Defesa extra: o contrato do backend devolve um resultado por uuid
    // enviado (Task 12.5), mas se algum uuid não vier na resposta por algum
    // motivo, ele não pode ficar preso em 'enviando' para sempre - volta a
    // pendente e é reincluído no próximo lote.
    const uuidsComResultado = new Set(resultados.map((resultado) => resultado.uuid));
    const uuidsSemResultado = uuids.filter((uuid) => !uuidsComResultado.has(uuid));

    if (uuidsSemResultado.length) {
        await reverterEnviandoParaPendente(uuidsSemResultado);
    }

    return { parar: false, sucesso: true };
}

/**
 * Ciclo completo de envio: consome a fila em lotes de até 50, na ordem de
 * `registrada_em`, um de cada vez, até não sobrar nada pronto para envio ou
 * até um lote pedir parada (falha, sessão expirada).
 *
 * `forcado: true` é a única forma de tentar mesmo com `sessaoExpirada`
 * marcada (ação explícita do técnico na tela de Pendências, Task 12.10) - os
 * gatilhos automáticos (intervalo, evento `online`) nunca forçam, para não
 * martelar um token que já se sabe expirado.
 */
export async function sincronizarAgora({ forcado = false } = {}) {
    if (emAndamento) return;
    if (typeof navigator !== 'undefined' && navigator.onLine === false) return;
    if (sessaoExpirada && !forcado) return;

    emAndamento = true;
    notificar({ sincronizando: true });

    try {
        // eslint-disable-next-line no-constant-condition
        while (true) {
            const lote = await obterProximoLote(TAMANHO_DO_LOTE);

            if (lote.length === 0) {
                break;
            }

            const token = await obterToken();
            const resultado = await processarUmLote(lote, token);

            if (resultado.sucesso) {
                notificar({ ultimaSincronizacao: new Date().toISOString() });
            }

            if (resultado.parar) {
                break;
            }
        }
    } finally {
        emAndamento = false;
        notificar({ sincronizando: false });
    }
}

/**
 * Liga os gatilhos automáticos: evento `online`, intervalo de 2 minutos e uma
 * primeira tentativa imediata ("ao abrir o aplicativo"). Idempotente - só a
 * primeira chamada tem efeito, então `useSincronizacao.js` pode chamar isto a
 * cada montagem de componente sem duplicar listeners nem intervalos.
 */
export async function iniciar() {
    if (iniciado) return;
    iniciado = true;

    // Varredura obrigatória, uma única vez por sessão do aplicativo, antes de
    // qualquer envio novo: cobre o aplicativo fechado no meio de um envio
    // anterior (a operação ficou marcada 'enviando' e precisa voltar a
    // 'pendente' para ser reenviada com segurança, pelo mesmo uuid).
    await reverterEnviandoParaPendente();

    if (typeof window !== 'undefined') {
        window.addEventListener('online', () => {
            sincronizarAgora();
        });
    }

    setInterval(() => {
        sincronizarAgora();
    }, INTERVALO_DE_SINCRONIZACAO_MS);

    // "ao abrir o aplicativo": primeira tentativa imediata, sem esperar o
    // primeiro tick do intervalo ou um evento de rede.
    sincronizarAgora();
}

/**
 * Ponto de integração para o fluxo de login (fora do escopo desta task):
 * depois de uma nova autenticação bem-sucedida, chamar isto para destravar o
 * sincronizador (a fila continua intacta - só o envio automático estava
 * parado) e tentar enviar imediatamente o que ficou pendente.
 */
export function retomarAposLogin() {
    sessaoExpirada = false;
    sincronizarAgora();
}
