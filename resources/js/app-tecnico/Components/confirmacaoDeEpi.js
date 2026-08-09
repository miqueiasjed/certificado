// Estado local da confirmação de EPI de uma visita (Plano 29, Task 29.5).
//
// Existe pelo mesmo motivo de `comodoAvistamento.js` (Task 13.7), e segue o
// mesmo desenho: o registro do que o técnico respondeu mora em `meta`, e não em
// `fila`. Guardar só na fila faria a etapa "esquecer" as respostas assim que a
// operação sincronizasse (a fila descarta o que o servidor confirmou, ver a
// regra de ouro em `sync/fila.js`), e o técnico voltaria à aba de EPI achando
// que não confirmou nada — e responderia de novo. A carga do dia não traz de
// volta a confirmação já gravada, então `meta` é a única memória possível aqui.
//
// Fica FORA de `db/repositorio.js` e de `sync/fila.js` (arquivos disputados por
// outras tasks) porque tudo de que precisa já é export genérico deles:
// `obterMeta`/`definirMeta` e `enfileirar`/`atualizarPendente`.
//
// Um módulo, e não código dentro do componente, porque `ResumoDaVisita.vue`
// precisa ler exatamente o mesmo estado para mostrar a pendência antes da
// assinatura. Duas leituras da mesma chave, escritas em dois lugares, divergem.
//
// Contrato do payload (conferido em `app/Services/Sync/AplicadorDeConfirmacaoDeEpi.php`
// e em `app/Services/Ppe/ConfirmacaoDeEpiService.php`, Tasks 29.2 e 29.3):
//
//   { tipo: 'confirmacao_epi', work_order_id: 12, payload: { confirmacoes: [
//       { personal_protective_equipment_id: 3, confirmado: true,
//         justificativa: null, confirmado_em: '2026-08-08T10:12:00.000Z' },
//   ] } }
//
// `uuid` e `registrada_em` são preenchidos por `enfileirar()`, e o
// `AppSyncService` deriva `work_order_id`/`registrada_em` dos campos de nível
// superior da operação — nada disso entra no payload.
//
// `confirmado_em` vai por item, em UTC (`toISOString()`), que é o mesmo que
// `fila.js` já faz com `registrada_em`: instante de aparelho é a exceção
// legítima da skill `datas-timezone`, e o aplicador converte para UTC de
// qualquer forma. Sem ele, o servidor usaria o instante da operação — o que
// dataria a resposta pela hora em que o técnico apertou "Salvar", e não pela
// hora em que ele de fato respondeu sobre cada EPI.

import { definirMeta, obterMeta, obterOrdem } from '../db/repositorio';
import { atualizarPendente, enfileirar } from '../sync/fila';

export const TIPO_DA_OPERACAO = 'confirmacao_epi';

const PREFIXO_REGISTRO = 'confirmacao_epi';

export function chaveDoRegistro(workOrderId) {
  return `${PREFIXO_REGISTRO}:${Number(workOrderId)}`;
}

/**
 * O que o técnico já respondeu sobre o EPI desta visita neste aparelho, ou
 * `null` se ele ainda não respondeu nada.
 *
 * Formato: `{ respostas: { [epiId]: { confirmado, justificativa, confirmado_em } },
 * uuid, atualizado_em }`. `uuid` é o da operação enfileirada, para uma correção
 * feita antes do envio reaproveitar a mesma operação em vez de criar a segunda.
 */
export async function obterRegistroDeEpi(workOrderId) {
  return obterMeta(chaveDoRegistro(workOrderId));
}

/**
 * A resposta está completa o bastante para ser enviada?
 *
 * Falta de EPI sem motivo declarado é o único conteúdo que o servidor recusa
 * (`ConfirmacaoDeEpiService::normalizar()`), e recusa como conflito de regra de
 * negócio — que o técnico só descobriria horas depois, na tela de Pendências,
 * longe do cliente e sem lembrar do motivo. Por isso a cobrança acontece aqui,
 * antes de a operação entrar na fila.
 */
export function respostaCompleta(resposta) {
  if (!resposta || resposta.confirmado === null || resposta.confirmado === undefined) {
    return false;
  }

  return resposta.confirmado === true || String(resposta.justificativa || '').trim() !== '';
}

/**
 * Ids dos EPIs marcados como não usados e ainda sem motivo escrito.
 */
export function faltamJustificativas(respostas) {
  return Object.entries(respostas || {})
    .filter(([, resposta]) => resposta?.confirmado === false && !respostaCompleta(resposta))
    .map(([epiId]) => Number(epiId));
}

/**
 * Grava as respostas localmente e enfileira UMA operação `confirmacao_epi` com
 * todas elas.
 *
 * Uma operação para a visita inteira, e não uma por EPI: o aplicador do
 * servidor recebe a lista e grava uma linha por item, com idempotência pelo par
 * (OS, EPI). Enfileirar uma por EPI só multiplicaria requisições sem mudar o
 * resultado.
 *
 * Corrigir uma resposta antes de a operação sair do aparelho ATUALIZA a
 * operação pendente no lugar (`atualizarPendente`, mesmo padrão de
 * `RegistroDeEvento.vue`), sem trocar o uuid. Se ela já saiu (`enviando`,
 * `conflito`, `falha`, ou confirmada e removida da fila), enfileira uma nova —
 * o que é seguro justamente porque o Service do lado de lá faz upsert do par
 * (OS, EPI) em vez de inserir sempre.
 *
 * EPI sem resposta não vira item do payload. É deliberado, e é a regra do
 * `ConfirmacaoDeEpiService`: quem não respondeu fica como "não informado", que
 * o checklist da RDC 622/2022 (Task 29.4) lê como pendência — nunca como
 * irregularidade. Mandar `confirmado: false` por um campo em branco acusaria o
 * técnico de não ter usado o equipamento.
 *
 * Sem transação Dexie envolvendo `meta` e `fila` de propósito: `enfileirar()`
 * avisa os escutadores da fila de dentro da própria chamada, e esses
 * escutadores leem tabelas (`comodos`, `fotos`) que não estariam no escopo da
 * transação — o que faria o Dexie recusar a leitura. O mesmo motivo pelo qual
 * `comodoAvistamento.js` também não envolve as duas escritas. A ordem escolhida
 * é a segura: a operação entra na fila antes de o registro local existir, então
 * a única falha possível deixa o trabalho do técnico no lado de lá.
 *
 * @param {object} dados
 * @param {number|string} dados.workOrderId
 * @param {Object<string, {confirmado: boolean|null, justificativa: string|null}>} dados.respostas
 * @returns {Promise<object|null>} O registro gravado, ou `null` quando não há
 *          nenhuma resposta para enviar.
 * @throws {Error} Quando alguma falta ficou sem motivo escrito.
 */
export async function salvarConfirmacoesDeEpi({ workOrderId, respostas }) {
  const ordemId = Number(workOrderId);
  const pendentes = faltamJustificativas(respostas);

  if (pendentes.length) {
    throw new Error(
      'Informe o motivo de o EPI não ter sido usado: sem o motivo, a falta não é enviada.',
    );
  }

  const confirmacoes = [];
  const guardadas = {};

  for (const [epiId, resposta] of Object.entries(respostas || {})) {
    if (!respostaCompleta(resposta)) {
      continue;
    }

    const confirmado = resposta.confirmado === true;
    const justificativa = confirmado ? null : String(resposta.justificativa).trim();
    const confirmadoEm = resposta.confirmado_em || new Date().toISOString();

    confirmacoes.push({
      personal_protective_equipment_id: Number(epiId),
      confirmado,
      justificativa,
      confirmado_em: confirmadoEm,
    });

    guardadas[epiId] = { confirmado, justificativa, confirmado_em: confirmadoEm };
  }

  if (confirmacoes.length === 0) {
    return null;
  }

  const registroAnterior = await obterRegistroDeEpi(ordemId);
  const payload = { confirmacoes };

  let uuid = registroAnterior?.uuid ?? null;
  const atualizou = uuid ? await atualizarPendente(uuid, payload) : false;

  if (!atualizou) {
    const operacao = await enfileirar({ tipo: TIPO_DA_OPERACAO, work_order_id: ordemId, payload });
    uuid = operacao.uuid;
  }

  const registro = {
    respostas: guardadas,
    uuid,
    atualizado_em: new Date().toISOString(),
  };

  await definirMeta(chaveDoRegistro(ordemId), registro);

  return registro;
}

/**
 * Situação da confirmação de EPI desta visita, para quem só precisa do número:
 * a aba da execução (sinal de pendência) e o resumo mostrado antes da
 * assinatura.
 *
 * Devolve `{ exigidos, respondidos, confirmados, naoUsados, semResposta }`, tudo
 * zerado quando a OS não exige EPI nenhum — que é o caso em que a etapa
 * simplesmente não existe.
 */
export async function resumoDeEpiDaOrdem(workOrderId) {
  const ordemId = Number(workOrderId);
  const ordem = await obterOrdem(ordemId);
  const exigidos = Array.isArray(ordem?.epis_exigidos) ? ordem.epis_exigidos : [];

  const vazio = { exigidos: 0, respondidos: 0, confirmados: 0, naoUsados: 0, semResposta: 0 };

  if (exigidos.length === 0) {
    return vazio;
  }

  const registro = await obterRegistroDeEpi(ordemId);
  const respostas = registro?.respostas || {};

  let confirmados = 0;
  let naoUsados = 0;

  for (const epi of exigidos) {
    const resposta = respostas[String(epi.id)];

    if (!respostaCompleta(resposta)) {
      continue;
    }

    if (resposta.confirmado) {
      confirmados += 1;
    } else {
      naoUsados += 1;
    }
  }

  const respondidos = confirmados + naoUsados;

  return {
    exigidos: exigidos.length,
    respondidos,
    confirmados,
    naoUsados,
    semResposta: exigidos.length - respondidos,
  };
}
