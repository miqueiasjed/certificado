// Leitura e escrita da base local do aplicativo do técnico (Plano 12, Task
// 12.7), por cima do esquema de `schema.js`.
//
// Regra de ouro deste arquivo: a carga é aplicada inteira ou não é aplicada.
// `salvarCarga()` faz tudo dentro de uma única transação Dexie
// (`db.transaction('rw', [...], async () => {...})`), porque uma transação
// que fosse embutida em outra promise perdida no meio do caminho é o cenário
// que deixaria uma ordem sem os dispositivos, exatamente o erro que este
// arquivo existe para evitar.

import { db } from './schema';

// -----------------------------------------------------------------------
// Ordens de serviço
// -----------------------------------------------------------------------

/**
 * Todas as ordens carregadas, ordenadas por data agendada e, dentro do mesmo
 * dia, por horário de início. O volume aqui é pequeno (até 200 ordens, limite
 * do próprio backend), então ordenar em memória depois de ler é mais simples
 * e mais claro do que depender de um índice composto do Dexie para isso.
 */
export async function listarOrdens() {
    const ordens = await db.ordens.toArray();

    return ordens.sort((a, b) => {
        const dataA = a.data_agendada || '';
        const dataB = b.data_agendada || '';

        if (dataA !== dataB) {
            return dataA.localeCompare(dataB);
        }

        return (a.inicio || '').localeCompare(b.inicio || '');
    });
}

export async function obterOrdem(id) {
    return db.ordens.get(id);
}

// -----------------------------------------------------------------------
// Endereços
// -----------------------------------------------------------------------

export async function obterEndereco(id) {
    return db.enderecos.get(id);
}

// -----------------------------------------------------------------------
// Cômodos e dispositivos (um registro por ordem, ver comentário em schema.js)
// -----------------------------------------------------------------------

export async function listarComodosDaOrdem(workOrderId) {
    return db.comodos.where('work_order_id').equals(workOrderId).toArray();
}

export async function listarDispositivosDaOrdem(workOrderId) {
    return db.dispositivos.where('work_order_id').equals(workOrderId).toArray();
}

/**
 * Consulta pelo código público impresso na etiqueta do dispositivo, offline.
 * É a busca que o leitor de QR/código de barras do Plano 11 usa em campo, sem
 * saber de antemão a qual ordem o dispositivo pertence.
 *
 * Quando o mesmo dispositivo aparece em mais de uma ordem carregada (visitas
 * de dias diferentes no mesmo período), devolve a ocorrência mais recente por
 * data agendada da ordem, que é a mais relevante para o técnico em campo.
 */
export async function obterDispositivoPorCodigoPublico(codigoPublico) {
    const ocorrencias = await db.dispositivos.where('codigo_publico').equals(codigoPublico).toArray();

    if (ocorrencias.length <= 1) {
        return ocorrencias[0] ?? null;
    }

    const ordens = await Promise.all(ocorrencias.map((ocorrencia) => obterOrdem(ocorrencia.work_order_id)));

    let maisRecente = ocorrencias[0];
    let dataMaisRecente = ordens[0]?.data_agendada || '';

    for (let indice = 1; indice < ocorrencias.length; indice += 1) {
        const data = ordens[indice]?.data_agendada || '';

        if (data > dataMaisRecente) {
            maisRecente = ocorrencias[indice];
            dataMaisRecente = data;
        }
    }

    return maisRecente;
}

// -----------------------------------------------------------------------
// Catálogos de apoio
// -----------------------------------------------------------------------

export async function listarTiposDeEvento() {
    return db.tipos_de_evento.toArray();
}

export async function listarTiposDeIsca() {
    return db.tipos_de_isca.toArray();
}

export async function listarPragas() {
    return db.pragas.toArray();
}

export async function listarProdutos() {
    return db.produtos.toArray();
}

// -----------------------------------------------------------------------
// Meta (chave/valor genérico)
// -----------------------------------------------------------------------

export async function obterMeta(chave) {
    const registro = await db.meta.get(chave);

    return registro ? registro.valor : null;
}

export async function definirMeta(chave, valor) {
    await db.meta.put({ chave, valor });
}

// -----------------------------------------------------------------------
// Carga do dia (Task 12.3): aplicação atômica do payload inteiro
// -----------------------------------------------------------------------

const TABELAS_DA_CARGA = [
    db.ordens,
    db.enderecos,
    db.comodos,
    db.dispositivos,
    db.tipos_de_evento,
    db.tipos_de_isca,
    db.pragas,
    db.produtos,
    db.meta,
];

/**
 * Aplica a carga do dia (formato exato de `AppDayLoadService::carregar()`,
 * Task 12.3) na base local, tudo em uma única transação: ou a carga inteira
 * entra, ou nada muda.
 *
 * Cada ordem da carga sempre traz a lista completa e atual dos próprios
 * cômodos e dispositivos (o backend nunca manda um diff só desses dois
 * blocos isolado do resto da ordem). Por isso, para toda ordem presente no
 * payload, os cômodos e dispositivos antigos dela são apagados antes de
 * inserir os novos: sem isso, um cômodo ou dispositivo que saiu da ordem sem
 * a ordem inteira sair da carga ficaria "fantasma" na base local.
 *
 * `payload.removidos.ordens` e `payload.removidos.produtos` (presentes só
 * quando a chamada usou `desde`) apagam o que o servidor confirmou que saiu
 * do alcance do técnico.
 */
export async function salvarCarga(payload) {
    if (!payload || typeof payload !== 'object') {
        throw new Error('Carga do dia inválida: resposta vazia do servidor.');
    }

    const primeiraCarga = (await obterMeta('ultima_carga')) === null;

    const ordens = Array.isArray(payload.ordens) ? payload.ordens : [];
    const tiposDeEvento = Array.isArray(payload.tipos_de_evento) ? payload.tipos_de_evento : [];
    const tiposDeIsca = Array.isArray(payload.tipos_de_isca) ? payload.tipos_de_isca : [];
    const pragas = Array.isArray(payload.pragas) ? payload.pragas : [];
    const produtos = Array.isArray(payload.produtos) ? payload.produtos : [];
    const ordensRemovidas = Array.isArray(payload.removidos?.ordens) ? payload.removidos.ordens : [];
    const produtosRemovidos = Array.isArray(payload.removidos?.produtos) ? payload.removidos.produtos : [];

    const linhasDeOrdens = [];
    const enderecosPorId = new Map();
    const linhasDeComodos = [];
    const linhasDeDispositivos = [];

    for (const ordem of ordens) {
        const { comodos, dispositivos, ...restoDaOrdem } = ordem;

        linhasDeOrdens.push(restoDaOrdem);

        const enderecoId = ordem.endereco?.id ?? null;

        if (ordem.endereco && enderecoId !== null) {
            enderecosPorId.set(enderecoId, ordem.endereco);
        }

        for (const comodo of comodos ?? []) {
            linhasDeComodos.push({ ...comodo, work_order_id: ordem.id, address_id: enderecoId });
        }

        for (const dispositivo of dispositivos ?? []) {
            linhasDeDispositivos.push({ ...dispositivo, work_order_id: ordem.id, address_id: enderecoId });
        }
    }

    await db.transaction('rw', TABELAS_DA_CARGA, async () => {
        for (const ordem of ordens) {
            await db.comodos.where('work_order_id').equals(ordem.id).delete();
            await db.dispositivos.where('work_order_id').equals(ordem.id).delete();
        }

        if (linhasDeOrdens.length) await db.ordens.bulkPut(linhasDeOrdens);
        if (enderecosPorId.size) await db.enderecos.bulkPut([...enderecosPorId.values()]);
        if (linhasDeComodos.length) await db.comodos.bulkPut(linhasDeComodos);
        if (linhasDeDispositivos.length) await db.dispositivos.bulkPut(linhasDeDispositivos);
        if (tiposDeEvento.length) await db.tipos_de_evento.bulkPut(tiposDeEvento);
        if (tiposDeIsca.length) await db.tipos_de_isca.bulkPut(tiposDeIsca);
        if (pragas.length) await db.pragas.bulkPut(pragas);
        if (produtos.length) await db.produtos.bulkPut(produtos);

        if (ordensRemovidas.length) {
            await db.ordens.bulkDelete(ordensRemovidas);
            await db.comodos.where('work_order_id').anyOf(ordensRemovidas).delete();
            await db.dispositivos.where('work_order_id').anyOf(ordensRemovidas).delete();
        }

        if (produtosRemovidos.length) {
            await db.produtos.bulkDelete(produtosRemovidos);
        }

        await db.meta.put({ chave: 'ultima_carga', valor: payload.gerado_em ?? new Date().toISOString() });

        if (payload.empresa) {
            await db.meta.put({ chave: 'empresa', valor: payload.empresa });
        }

        if (payload.periodo) {
            await db.meta.put({ chave: 'periodo_carregado', valor: payload.periodo });
        }

        if (typeof payload.ordens_tem_mais === 'boolean') {
            await db.meta.put({ chave: 'ordens_tem_mais', valor: payload.ordens_tem_mais });
        }

        // Roteiro do dia (Plano 22, Task 22.5/22.7): `AppDayLoadService::carregar()`
        // já manda esta chave desde a Task 22.5, mas nada aqui a salvava até
        // agora - buraco fechado nesta Task 22.7, porque sem isso o roteiro
        // nunca sobreviveria a um reload offline, mesmo chegando certinho na
        // carga. `undefined` (carga antiga em cache, de antes da Task 22.5)
        // não sobrescreve o que já estava salvo; `null` explícito (carga
        // atual, sem roteiro montado para o dia ainda) sobrescreve, porque é
        // um valor válido e atual vindo do servidor.
        if (payload.roteiro !== undefined) {
            await db.meta.put({ chave: 'roteiro', valor: payload.roteiro });
        }

        // Rastreamento contínuo (Plano 22, Task 22.4/22.7): mesma razão da
        // chave `roteiro` acima - sem salvar isso localmente, a faixa
        // permanente da Task 22.7 não sobreviveria a um reload offline.
        if (typeof payload.rastreamento_continuo_ligado === 'boolean') {
            await db.meta.put({
                chave: 'rastreamento_continuo_ligado',
                valor: payload.rastreamento_continuo_ligado,
            });
        }
    });

    // Sem armazenamento persistente, o Android é livre para limpar o
    // IndexedDB quando o aparelho fica com pouco espaço, e o técnico perde o
    // dia de trabalho sem aviso. Só pede na primeira carga: é o momento em
    // que passa a existir algo de valor para proteger, e pedir a cada carga
    // não muda a decisão do navegador nem do usuário.
    if (primeiraCarga) {
        await solicitarPersistenciaDeArmazenamento();
    }
}

/**
 * `navigator.storage.persist()` é best-effort: pode não existir (navegador
 * antigo) e pode ser negado pelo usuário. Em nenhum dos dois casos isso
 * impede o aplicativo de funcionar, por isso os erros aqui só vão para o
 * console, nunca para a interface do técnico.
 */
async function solicitarPersistenciaDeArmazenamento() {
    if (typeof navigator === 'undefined' || !navigator.storage?.persist) {
        return;
    }

    try {
        await navigator.storage.persist();
    } catch (erro) {
        console.warn('Não foi possível solicitar armazenamento persistente.', erro);
    }
}

// -----------------------------------------------------------------------
// Limpeza da base local (logout explícito)
// -----------------------------------------------------------------------

/**
 * Apaga todos os dados locais. Existe só para o fluxo de logout explícito do
 * técnico, e nunca deve ser chamada com a fila de sincronização (Task 12.8)
 * não vazia: a fila é a única cópia de trabalho ainda não confirmada pelo
 * servidor, e apagá-la junto é perda de trabalho do técnico.
 *
 * ATENÇÃO: esta função ainda NÃO faz a checagem de fila vazia. A tabela da
 * fila existe desde a Task 12.8 (`db.fila`, ver `schema.js` versão 2 e
 * `sync/fila.js`), e quem for ligar `limparBaseLocal()` a um botão real de
 * logout precisa envolver a chamada numa checagem prévia com
 * `contarTodos()` (de `sync/fila.js`) igual a zero - não `contarPendentes()`,
 * porque uma operação em `conflito` ou em `falha` também é trabalho do
 * técnico ainda não confirmado pelo servidor, e apagá-la junto é perda de
 * trabalho do mesmo jeito que apagar uma `pendente`. Até essa checagem
 * existir, ninguém deve ligar esta função a nenhum botão real do aplicativo.
 */
export async function limparBaseLocal() {
    await db.transaction('rw', TABELAS_DA_CARGA, async () => {
        await Promise.all(TABELAS_DA_CARGA.map((tabela) => tabela.clear()));
    });
}
