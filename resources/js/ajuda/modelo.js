/**
 * Formato de uma entrada do manual (documentação — este arquivo não é importado).
 *
 * A chave do objeto é o nome do componente Inertia da tela, exatamente como
 * aparece em `usePage().component` (ex.: 'Clients/Index'). Use `'Pasta/*'`
 * para cobrir de uma vez todas as telas de uma pasta.
 */
export const exemplo = {
  'Clients/Index': {
    // Área usada para agrupar no índice geral do painel de ajuda.
    area: 'Clientes',
    // Título curto da tela, do jeito que o usuário a chama no sistema.
    titulo: 'Clientes',
    // Uma ou duas frases: para que serve a tela e quando usá-la.
    paraQueServe: 'Lista todos os clientes da empresa e é o ponto de partida para cadastrar, editar ou consultar um cliente.',
    // Blocos de passo a passo. Cada passo é uma frase de ação, na ordem.
    comoUsar: [
      {
        titulo: 'Cadastrar um cliente',
        passos: [
          'Clique em "Novo Cliente" no topo da tela.',
          'Preencha nome, documento e contato.',
          'Clique em "Salvar" — o cliente já aparece na listagem.',
        ],
      },
    ],
    // Campos da tela que não são autoexplicativos (opcional).
    campos: [
      { nome: 'Documento', descricao: 'CPF ou CNPJ do cliente. É usado para evitar cadastro duplicado.' },
    ],
    // Boas práticas e atalhos (opcional).
    dicas: [
      'Use a busca por nome ou documento para achar um cliente sem rolar a lista.',
    ],
    // Riscos, efeitos irreversíveis e pré-requisitos (opcional).
    atencao: [
      'Cliente com ordens de serviço vinculadas não pode ser excluído — inative-o.',
    ],
    // Telas relacionadas, para o usuário continuar o fluxo (opcional).
    relacionados: [
      { titulo: 'Endereços', href: '/addresses' },
    ],
  },
};
