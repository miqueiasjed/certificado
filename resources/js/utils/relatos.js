/**
 * Rótulos e cores dos relatos de problema (`problem_reports`), usados na lista
 * e no detalhe da área da plataforma. As chaves batem com
 * `ProblemReport::SITUACOES`.
 */
export const ROTULOS_SITUACAO_RELATO = {
  novo: 'Novo',
  em_analise: 'Em análise',
  resolvido: 'Resolvido',
};

export const CLASSES_SITUACAO_RELATO = {
  novo: 'bg-yellow-100 text-yellow-800',
  em_analise: 'bg-blue-100 text-blue-800',
  resolvido: 'bg-green-100 text-green-800',
};

/**
 * Nome de quem relatou: funcionário do painel ou usuário do portal do cliente.
 */
export function quemRelatou(relato) {
  if (relato.user) {
    return relato.user.name;
  }

  if (relato.client_user) {
    return `${relato.client_user.nome} (portal do cliente)`;
  }

  return 'Conta removida';
}
