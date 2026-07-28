// Fila de sincronização do aplicativo do técnico (Plano 12, Task 12.8).
//
// Este arquivo é a única porta de entrada para a tabela `fila` (schema.js,
// versão 2 do Dexie). Toda leitura e toda mudança de situação de uma operação
// passam por aqui - `sincronizador.js` nunca acessa `db.fila` diretamente,
// para a lógica de estado da fila ficar concentrada num só lugar.
//
// Regra de ouro: nada sai da fila sem confirmação explícita do servidor
// (`aplicada` ou `duplicada`). Conflito e falha permanecem na fila, visíveis,
// até alguém decidir (Task 12.10) - nunca são descartados silenciosamente.

import { db } from '../db/schema';

// -----------------------------------------------------------------------
// Notificação de mudança (evento, não polling)
// -----------------------------------------------------------------------

/**
 * Quem precisa saber que a fila mudou (hoje, só `useSincronizacao.js`) se
 * inscreve aqui. É o que permite ao indicador do cabeçalho refletir um
 * `enfileirar()` feito em qualquer tela, na hora, sem esperar um intervalo de
 * polling.
 */
const escutadores = new Set();

export function aoMudar(callback) {
    escutadores.add(callback);

    return () => escutadores.delete(callback);
}

function notificar() {
    escutadores.forEach((callback) => callback());
}

// -----------------------------------------------------------------------
// Gravação (enfileirar)
// -----------------------------------------------------------------------

/**
 * Grava uma operação na fila local e devolve o registro gravado.
 *
 * O uuid nasce aqui, uma única vez, com `crypto.randomUUID()`, e nunca muda
 * nas tentativas seguintes: é o que torna o reenvio seguro no servidor (o
 * mesmo uuid enviado duas vezes volta `duplicada`, nunca aplica duas vezes).
 *
 * Como só usa `db.fila.add()`, esta função participa automaticamente de uma
 * transação Dexie ambiente sempre que `db.fila` estiver entre as tabelas
 * dela. Por isso, uma tela que registra um evento e enfileira a operação ao
 * mesmo tempo faz as duas coisas na MESMA transação, bastando envolver as
 * duas chamadas assim:
 *
 *   await db.transaction('rw', [db.algumaCoisa, db.fila], async () => {
 *       await db.algumaCoisa.put(registro);
 *       await enfileirar({ tipo: 'evento_dispositivo', work_order_id, payload });
 *   });
 *
 * Isso é o que garante que o técnico nunca vê o registro local sem o
 * correspondente na fila (nem vice-versa): ou as duas gravações entram
 * juntas, ou nenhuma entra.
 */
export async function enfileirar({ tipo, work_order_id = null, payload, updated_at_conhecido = null }) {
    const registro = {
        uuid: crypto.randomUUID(),
        tipo,
        work_order_id,
        payload,
        updated_at_conhecido,
        registrada_em: new Date().toISOString(),
        situacao: 'pendente',
        tentativas: 0,
        ultimo_erro: null,
        proxima_tentativa_em: null,
    };

    await db.fila.add(registro);
    notificar();

    return registro;
}

// -----------------------------------------------------------------------
// Leitura (para a interface e para o sincronizador)
// -----------------------------------------------------------------------

/**
 * Todas as operações pendentes, na ordem em que foram registradas. O volume
 * por aparelho é pequeno (algumas centenas no pior caso de um dia inteiro
 * sem rede), então ordenar em memória depois de ler é o mesmo padrão já usado
 * em `repositorio.js` (`listarOrdens`), e mais simples do que um índice
 * composto do Dexie só para isso.
 */
export async function listarPendentes() {
    const pendentes = await db.fila.where('situacao').equals('pendente').toArray();

    return ordenarPorRegistradaEm(pendentes);
}

export async function listarEmConflito() {
    return db.fila.where('situacao').equals('conflito').toArray();
}

export async function listarComFalha() {
    return db.fila.where('situacao').equals('falha').toArray();
}

export async function contarPendentes() {
    return db.fila.where('situacao').equals('pendente').count();
}

/**
 * Total de registros na fila, em qualquer situação (`pendente`, `enviando`,
 * `conflito` ou `falha`). É esta função, e não `contarPendentes()`, que deve
 * decidir se a fila está "vazia" para liberar o logout: um conflito ou uma
 * falha ainda não confirmados pelo servidor são, do mesmo jeito,
 * trabalho do técnico ainda não seguro no lado de lá (ver o aviso em
 * `limparBaseLocal()`, em `repositorio.js`).
 */
export async function contarTodos() {
    return db.fila.count();
}

/**
 * Ids de ordem que têm ao menos uma operação na fila, em qualquer situação
 * (`pendente`, `enviando`, `conflito` ou `falha`). Usado pela lista do dia
 * (Task 12.9) para mostrar "Pendente de envio" no lugar do status vindo da
 * carga quando existe algo local ainda não confirmado pelo servidor para
 * aquela ordem - mesmo que a carga já diga "concluída".
 */
export async function listarOrdensComPendencia() {
    const registros = await db.fila.toArray();

    return new Set(
        registros.map((registro) => registro.work_order_id).filter((id) => id !== null && id !== undefined),
    );
}

/**
 * Até `limite` operações pendentes, prontas para envio agora, respeitando a
 * ordem de `registrada_em`. "Prontas para envio" exclui quem ainda está
 * esperando o próprio prazo de repetição (`proxima_tentativa_em` no futuro).
 *
 * O laço para no primeiro item que ainda não está pronto, em vez de pular
 * para um item mais novo que já esteja: pular a ordem é exatamente o que não
 * pode acontecer aqui, porque a conclusão de uma OS não pode chegar ao
 * servidor antes do evento que ela conclui.
 */
export async function obterProximoLote(limite) {
    const pendentes = await listarPendentes();
    const agora = Date.now();
    const lote = [];

    for (const operacao of pendentes) {
        const aindaEsperandoRepeticao =
            operacao.proxima_tentativa_em && new Date(operacao.proxima_tentativa_em).getTime() > agora;

        if (aindaEsperandoRepeticao) {
            break;
        }

        lote.push(operacao);

        if (lote.length >= limite) {
            break;
        }
    }

    return lote;
}

function ordenarPorRegistradaEm(operacoes) {
    return [...operacoes].sort((a, b) => {
        if (a.registrada_em < b.registrada_em) return -1;
        if (a.registrada_em > b.registrada_em) return 1;

        return 0;
    });
}

// -----------------------------------------------------------------------
// Transições de situação (usadas por `sincronizador.js`)
// -----------------------------------------------------------------------

/**
 * Marca as operações como `enviando`, sempre imediatamente antes da
 * requisição ao servidor. É esta marca que sobrevive se o aplicativo for
 * fechado no meio do envio: ao reabrir, `reverterEnviandoParaPendente()`
 * (chamada uma vez, na varredura inicial do sincronizador) devolve tudo para
 * `pendente`, para ser reenviado com segurança pelo mesmo uuid.
 */
export async function marcarComoEnviando(uuids) {
    if (!uuids.length) return;

    await db.fila.where('uuid').anyOf(uuids).modify({ situacao: 'enviando' });
    notificar();
}

/**
 * Devolve para `pendente` toda operação presa em `enviando`.
 *
 * Sem argumento: varredura completa da tabela (uso: início da sessão do
 * aplicativo, antes de qualquer envio novo - cobre o aplicativo fechado no
 * meio de um envio anterior).
 *
 * Com uma lista de uuids: reverte só esse lote (uso: resposta 401 no meio de
 * um envio - a sessão caiu, não a operação, então tentativas e mensagem de
 * erro não são tocadas).
 */
export async function reverterEnviandoParaPendente(uuids = null) {
    const colecao =
        uuids && uuids.length ? db.fila.where('uuid').anyOf(uuids) : db.fila.where('situacao').equals('enviando');

    let alterou = false;

    await colecao.modify((registro) => {
        if (registro.situacao === 'enviando') {
            registro.situacao = 'pendente';
            alterou = true;
        }
    });

    if (alterou) {
        notificar();
    }
}

/**
 * Erro transitório (5xx, 429 ou falha de rede/transporte): a operação volta
 * para `pendente`, com a contagem de tentativas e o próximo horário de
 * repetição atualizados (espera crescente calculada em `sincronizador.js`).
 */
export async function reagendarTentativa(uuid, tentativas, mensagemDeErro, proximaTentativaEm) {
    await db.fila.update(uuid, {
        situacao: 'pendente',
        tentativas,
        ultimo_erro: mensagemDeErro,
        proxima_tentativa_em: proximaTentativaEm,
    });
    notificar();
}

/**
 * Falha definitiva: teto de tentativas atingido, erro 4xx que não é 429/401
 * (repetir requisição malformada é ciclo infinito), ou o servidor recusou por
 * regra de negócio (`situacao: 'recusada'` na resposta). A operação some da
 * fila só com confirmação do servidor - aqui ela fica, visível, em `falha`,
 * até o técnico decidir (tentar de novo, na Task 12.10).
 */
export async function marcarComoFalhaDefinitiva(uuids, mensagemDeErro) {
    if (!uuids.length) return;

    await db.fila.where('uuid').anyOf(uuids).modify((registro) => {
        registro.situacao = 'falha';
        registro.tentativas = (registro.tentativas || 0) + 1;
        registro.ultimo_erro = mensagemDeErro;
        registro.proxima_tentativa_em = null;
    });
    notificar();
}

/**
 * Conflito devolvido pelo servidor: guarda o id do conflito (para a Task
 * 12.10 buscar o detalhe em `GET /api/app/conflitos` e resolver em
 * `POST /api/app/conflitos/{id}/resolver`) e o motivo em português. Não
 * agenda nova tentativa: um conflito não se resolve sozinho por repetição,
 * precisa de decisão humana.
 */
export async function marcarComoConflito(uuid, conflitoId, motivo) {
    await db.fila.update(uuid, {
        situacao: 'conflito',
        conflito_id: conflitoId,
        conflito_motivo: motivo,
        proxima_tentativa_em: null,
    });
    notificar();
}

/**
 * Devolve uma operação `falha` para `pendente`, com a contagem de tentativas
 * zerada e sem espera de repetição - decisão manual e explícita do técnico
 * (botão "Tentar de novo" da tela de Pendências, Task 12.10), mesmo espírito
 * de `tentarNovamente()` em `sync/foto.js`. O próximo ciclo do sincronizador
 * (intervalo, evento `online` ou "forçar envio") reenvia pelo mesmo uuid.
 */
export async function tentarNovamente(uuid) {
    await db.fila.update(uuid, {
        situacao: 'pendente',
        tentativas: 0,
        ultimo_erro: null,
        proxima_tentativa_em: null,
    });
    notificar();
}

export async function removerDaFila(uuids) {
    if (!uuids.length) return;

    await db.fila.bulkDelete(uuids);
    notificar();
}

/**
 * Aplica o array `resultados` de `POST /api/app/sincronizar` (formato exato
 * em `.claude/tasks/12/12.5.md`, implementado em `AppSyncUploadController`):
 * `aplicada` e `duplicada` removem da fila; `conflito` marca e guarda o
 * `conflito_id`; `recusada` marca falha com a mensagem do servidor.
 */
export async function aplicarResultados(resultados) {
    const paraRemover = [];

    for (const resultado of resultados) {
        switch (resultado.situacao) {
            case 'aplicada':
            case 'duplicada':
                paraRemover.push(resultado.uuid);
                break;
            case 'conflito':
                await marcarComoConflito(resultado.uuid, resultado.conflito_id ?? null, resultado.motivo ?? null);
                break;
            case 'recusada':
                await marcarComoFalhaDefinitiva(
                    [resultado.uuid],
                    resultado.mensagem || 'O servidor recusou esta operação.',
                );
                break;
            default:
                // Situação que este aplicativo ainda não conhece: não mexe no
                // registro. É mais seguro deixar pendente e reenviar do que
                // presumir um significado e apagar por engano.
                break;
        }
    }

    if (paraRemover.length) {
        await removerDaFila(paraRemover);
    }
}
