// Helpers do registro de avistamento por cômodo (Plano 13, Task 13.7).
//
// Este módulo fica FORA dos dois arquivos compartilhados do app do técnico
// (`db/repositorio.js` e `sync/fila.js`): outras tasks do mesmo plano mexem
// nesses dois arquivos em paralelo, e tudo que este módulo precisa já existe
// como export genérico deles - `obterMeta`/`definirMeta` (par chave/valor) e
// `enfileirar`/`removerDaFila` (fila) - sem precisar acrescentar nada novo a
// nenhum dos dois. `db` (Dexie) só é importado para uma leitura pontual
// (`db.fila.get`), nunca para escrever direto na tabela.
//
// Contrato real do servidor (conferido em `app/Models/PestSighting.php`, nas
// migrations de `pest_sightings` e em
// `app/Services/Sync/AplicadorDeAvistamento.php`, Task 13.2): cada operação
// `avistamento` vira UM registro `PestSighting`, com no máximo UM produto
// aplicado (`applied_product` + `applied_product_quantity`, ambos colunas
// simples, sem tabela de itens). `PestSightingItem` existe no banco, mas
// guarda características do avistamento em si (tipo de evidência, tamanho,
// condição) e não é usado por `AplicadorDeAvistamento` - não serve para
// "vários produtos por cômodo", ao contrário do que uma leitura apressada da
// spec sugere.
//
// Por isso "vários produtos no mesmo cômodo" é implementado aqui como várias
// operações `avistamento` na fila, uma por produto (mesma praga, intensidade,
// cômodo e observação em todas), em vez de uma estrutura de itens que o
// servidor não sabe interpretar.
//
// Lacuna documentada: nem o catálogo de produtos (`products`, ver
// `app/Models/Product.php`) nem `pest_sightings` têm coluna de unidade -
// `unit` só existe nos pivots de produto previsto por OS/certificado. A
// unidade escolhida em campo, portanto, não tem onde ser gravada de forma
// estruturada hoje; para não se perder ao sincronizar, ela entra em texto
// livre dentro de `control_measures_applied` (ver `descricaoDoProduto`
// abaixo). Resolver isso de vez exige uma migration nova em
// `pest_sightings` e ajuste em `AplicadorDeAvistamento`, fora do escopo
// desta task de frontend.

import { obterMeta, definirMeta } from '../db/repositorio';
import { enfileirar, removerDaFila } from '../sync/fila';
import { db } from '../db/schema';

const PREFIXO_REGISTRO = 'avistamento_comodo';
const CHAVE_PRODUTOS_RECENTES = 'avistamento_produtos_recentes';
const LIMITE_PRODUTOS_RECENTES_GRAVADOS = 30;

export function chaveDoRegistro(workOrderId, roomId) {
    return `${PREFIXO_REGISTRO}:${workOrderId}:${roomId}`;
}

/**
 * Registro local do cômodo nesta visita (rascunho da última gravação), ou
 * `null` se o cômodo ainda não foi registrado. É este registro que faz
 * `ListaDeComodos.vue` mostrar o sinal de "já registrado" e
 * `RegistroDeComodo.vue` reabrir para edição em vez de começar em branco -
 * sobrevive à sincronização (não depende de a operação ainda estar na fila),
 * porque mora em `meta`, não em `fila`.
 */
export async function obterRegistroDoComodo(workOrderId, roomId) {
    return obterMeta(chaveDoRegistro(workOrderId, roomId));
}

/**
 * Salva o registro local do cômodo e enfileira uma operação `avistamento`
 * por produto aplicado (ou uma única operação sem produto, se nenhum foi
 * informado - o técnico só quer registrar que viu a praga).
 *
 * Ao editar um registro existente, qualquer operação da versão anterior que
 * ainda esteja pendente na fila (não enviada) é removida antes das novas
 * entrarem, para a edição não duplicar o mesmo cômodo/visita na fila local.
 * Uma operação que já saiu da fila (sincronizada, ou em `enviando` neste
 * instante) fica como está - não há como "desfazer" o que o servidor já
 * confirmou por este caminho, nem é seguro mexer no que está no meio do
 * envio.
 *
 * @param {object} dados
 * @param {number} dados.workOrderId
 * @param {number} dados.roomId
 * @param {string} dados.pestType - chave do catálogo de pragas (`db.pragas`, ex.: "rats").
 * @param {string} dados.severityLevel - "low" | "medium" | "high" | "critical".
 * @param {string|null} dados.observacaoGeral
 * @param {Array<{produto_id:number, nome:string, quantidade:number, unidade:string|null}>} dados.produtos
 */
export async function salvarRegistroDoComodo({
    workOrderId,
    roomId,
    pestType,
    severityLevel,
    observacaoGeral,
    produtos,
}) {
    const registroAnterior = await obterRegistroDoComodo(workOrderId, roomId);

    if (registroAnterior?.uuids?.length) {
        for (const uuid of registroAnterior.uuids) {
            const operacaoAinda = await db.fila.get(uuid);

            if (operacaoAinda && operacaoAinda.situacao !== 'enviando') {
                await removerDaFila([uuid]);
            }
        }
    }

    const linhas = produtos.length ? produtos : [null];
    const novosUuids = [];

    for (const produto of linhas) {
        const payload = {
            room_id: roomId,
            pest_type: pestType,
            severity_level: severityLevel,
            observations: observacaoGeral || null,
            control_measures_applied: produto ? descricaoDoProduto(produto) : null,
            applied_product: produto ? produto.nome : null,
            applied_product_quantity: produto ? Number(produto.quantidade) : null,
        };

        const registro = await enfileirar({ tipo: 'avistamento', work_order_id: workOrderId, payload });
        novosUuids.push(registro.uuid);

        if (produto) {
            await registrarProdutoUsado(produto);
        }
    }

    await definirMeta(chaveDoRegistro(workOrderId, roomId), {
        pest_type: pestType,
        severity_level: severityLevel,
        observacao_geral: observacaoGeral || null,
        produtos,
        uuids: novosUuids,
        atualizado_em: new Date().toISOString(),
    });
}

function descricaoDoProduto(produto) {
    return produto.unidade
        ? `Produto aplicado: ${produto.nome} - ${produto.quantidade} ${produto.unidade}`
        : `Produto aplicado: ${produto.nome} - ${produto.quantidade}`;
}

// -----------------------------------------------------------------------
// Últimos produtos usados (para o topo do SeletorDeProduto.vue)
// -----------------------------------------------------------------------

/**
 * Grava o uso de um produto no histórico local (mais recente primeiro, sem
 * repetição do mesmo produto). Guardado em `meta`, não em `fila`: se
 * dependesse da fila, um técnico com boa conexão o dia inteiro veria a lista
 * de "últimos usados" esvaziar assim que cada operação fosse sincronizada,
 * exatamente quando o atalho mais ajuda.
 */
async function registrarProdutoUsado(produto) {
    const recentes = (await obterMeta(CHAVE_PRODUTOS_RECENTES)) || [];
    const semEsteProduto = recentes.filter((item) => item.produto_id !== produto.produto_id);
    const atualizados = [
        { produto_id: produto.produto_id, nome: produto.nome, usado_em: new Date().toISOString() },
        ...semEsteProduto,
    ].slice(0, LIMITE_PRODUTOS_RECENTES_GRAVADOS);

    await definirMeta(CHAVE_PRODUTOS_RECENTES, atualizados);
}

/**
 * Os últimos produtos usados pelo técnico neste aparelho, mais recente
 * primeiro, sem repetição - para `SeletorDeProduto.vue` mostrar no topo da
 * lista antes de qualquer busca.
 */
export async function listarUltimosProdutosUsados(limite = 5) {
    const recentes = (await obterMeta(CHAVE_PRODUTOS_RECENTES)) || [];

    return recentes.slice(0, limite);
}
