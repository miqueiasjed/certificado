// Chamada HTTP compartilhada do endpoint de reagendamento da agenda
// (PUT /agenda/{id}/reagendar). Dois pontos do frontend batem nele: o formulário de
// reagendamento do painel lateral (Agenda/Index.vue, Task 10.5) e o arrastar-e-soltar
// do calendário (useArrastarVisita.js, Task 10.6). Em vez de duplicar a montagem do
// fetch nos dois lugares, ela mora aqui uma vez só.
//
// Nenhuma regra de negócio mora neste arquivo: só a chamada e o contrato de resposta,
// que os dois chamadores já tratam do mesmo jeito (sucesso, conflito 422, erro de
// validação 422, falha de rede).

/**
 * Token CSRF da tag <meta name="csrf-token"> que o Laravel injeta em toda página
 * autenticada.
 */
export function tokenCsrf() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
}

/**
 * PUT /agenda/{workOrderId}/reagendar.
 *
 * O corpo precisa das três chaves (`scheduled_date`, `start_time`, `end_time`),
 * mesmo quando os horários forem `null`. Contrato do `ReagendamentoRequest`
 * (Task 10.3): omitir `start_time`/`end_time` deixaria a ordem com a data nova e o
 * horário apontando para o dia antigo.
 *
 * Nunca lança por causa de uma resposta HTTP de erro (422 de conflito, 422 de
 * validação comum, 403...): devolve `{ ok: false, dados }` e quem chamou decide o que
 * fazer (mostrar a mensagem, abrir o modal de conflito, desfazer um arrasto
 * otimista...). Só lança se a rede falhar ou a resposta não vier em JSON, o que quem
 * chamou também precisa tratar.
 *
 * @param {number|string} workOrderId
 * @param {{ scheduled_date: string, start_time: string|null, end_time: string|null }} payload
 * @returns {Promise<{ ok: boolean, dados: any }>}
 */
export async function reagendarOrdem(workOrderId, payload) {
  const resposta = await fetch(`/agenda/${workOrderId}/reagendar`, {
    method: 'PUT',
    headers: {
      'Content-Type': 'application/json',
      'X-CSRF-TOKEN': tokenCsrf(),
      Accept: 'application/json',
    },
    body: JSON.stringify(payload),
  });

  const dados = await resposta.json();

  return { ok: resposta.ok, dados };
}

export default {
  tokenCsrf,
  reagendarOrdem,
};
