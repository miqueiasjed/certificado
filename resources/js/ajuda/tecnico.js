/**
 * Manual das telas de produtos, cadastros técnicos, estoque, equipe, EPI e frota.
 *
 * A chave é o nome do componente Inertia da tela. `Pasta/*` vale para todas as
 * telas da pasta que não tiverem manual próprio.
 */
export default {
  // ---------------------------------------------------------------------
  // Produtos
  // ---------------------------------------------------------------------

  'Products/Index': {
    area: 'Produtos e estoque',
    titulo: 'Produtos',
    paraQueServe: 'Lista os produtos que a empresa aplica e é o ponto de partida para cadastrar, consultar e editar cada um. É deste cadastro que a ordem de serviço e o certificado tiram princípio ativo, grupo químico, antídoto e registro do produto.',
    comoUsar: [
      {
        titulo: 'Encontrar um produto',
        passos: [
          'Digite no campo de busca pelo menos 2 caracteres.',
          'A busca aceita nome do produto, princípio ativo e grupo químico.',
          'Clique no "x" dentro do campo para limpar a busca e ver a lista inteira.',
        ],
      },
      {
        titulo: 'Consultar, editar ou excluir',
        passos: [
          'Use o ícone de olho para abrir os detalhes do produto.',
          'Use o ícone de lápis para editar.',
          'Use o ícone de lixeira para excluir e confirme no modal "Tem certeza que deseja excluir este produto?".',
        ],
      },
      {
        titulo: 'Cadastrar um produto novo',
        passos: [
          'Clique em "Novo Produto" no topo da tela.',
          'Preencha o formulário e salve.',
          'O produto aparece na listagem já com as etiquetas de princípio ativo, grupo químico, antídoto e registro.',
        ],
      },
    ],
    campos: [
      { nome: 'Reg. Ministerial', descricao: 'Coluna que mostra o número do registro do produto no órgão de saúde. Quando aparece "-", o produto está sem registro vinculado.' },
    ],
    dicas: [
      'A lista é paginada: se não achou o produto, confira se há mais páginas no rodapé.',
    ],
    atencao: [
      'Excluir um produto é definitivo e não há tela para desfazer. Antes de excluir, confira se ele já foi usado em ordens de serviço e certificados emitidos.',
    ],
    relacionados: [
      { titulo: 'Estoque', href: '/estoque' },
      { titulo: 'Lotes', href: '/lotes' },
    ],
  },

  'Products/Create': {
    area: 'Produtos e estoque',
    titulo: 'Novo produto',
    paraQueServe: 'Cadastra um produto com a classificação técnica que sai impressa no certificado e define se ele terá controle de estoque.',
    comoUsar: [
      {
        titulo: 'Preencher a ficha técnica',
        passos: [
          'Digite o "Nome do Produto".',
          'Escolha o "Princípio Ativo", o "Grupo Químico" e o "Antídoto" — os três são obrigatórios.',
          'Escolha o "Reg. Min da Saude", que é opcional.',
          'Se algum item não estiver na lista, clique no botão verde com o sinal de mais ao lado do campo, digite o nome e clique em "Criar": o item novo já fica selecionado.',
        ],
      },
      {
        titulo: 'Ligar o controle de estoque',
        passos: [
          'Na seção "Controlar estoque deste produto", clique na chave para ligar o controle.',
          'Preencha a "Unidade de medida" (un, kg, L, mL...).',
          'Com o controle ligado, informe o "Estoque mínimo" ou deixe em branco para não ter mínimo.',
          'Clique em "Salvar Produto".',
        ],
      },
    ],
    campos: [
      { nome: 'Unidade de medida', descricao: 'Como o produto é medido. Vale até 10 caracteres e aparece junto de todo saldo e de toda movimentação.' },
      { nome: 'Estoque mínimo', descricao: 'Abaixo deste valor o produto entra no indicador "Abaixo do mínimo" da tela de Estoque.' },
      { nome: 'Controlar estoque deste produto', descricao: 'Ligar a chave não cria saldo nenhum. O saldo só entra por lote, na tela de Lotes, depois de conferido por contagem física.' },
    ],
    dicas: [
      'Cadastre primeiro o princípio ativo, o grupo químico e o antídoto se já souber que vai usá-los em vários produtos: a tela de cada cadastro permite organizar melhor do que os atalhos deste formulário.',
    ],
    atencao: [
      'Princípio ativo, grupo químico, antídoto e registro do produto são impressos no certificado entregue ao cliente. Errar aqui é errar em todo documento emitido daqui para a frente.',
      'O antídoto é a informação que o cliente procura em caso de acidente. Não preencha com um valor qualquer só para poder salvar.',
      'Produto sem controle de estoque não gera movimento nenhum quando a ordem de serviço é fechada: ele fica só como ficha técnica.',
    ],
    relacionados: [
      { titulo: 'Produtos', href: '/products' },
      { titulo: 'Princípios ativos', href: '/active-ingredients' },
    ],
  },

  'Products/Edit': {
    area: 'Produtos e estoque',
    titulo: 'Editar produto',
    paraQueServe: 'Corrige a ficha técnica de um produto já cadastrado e liga ou desliga o controle de estoque dele.',
    comoUsar: [
      {
        titulo: 'Alterar a classificação técnica',
        passos: [
          'Ajuste o nome, o princípio ativo, o grupo químico, o antídoto ou o registro.',
          'Use o botão com o sinal de mais ao lado do campo para criar um item que ainda não existe e clicar em "Criar".',
          'Clique em "Atualizar Produto".',
        ],
      },
      {
        titulo: 'Mudar o controle de estoque',
        passos: [
          'Ligue ou desligue a chave "Controlar estoque deste produto".',
          'Reveja a "Unidade de medida", porque ela acompanha todos os saldos já registrados.',
          'Ajuste o "Estoque mínimo" quando o consumo do produto mudar.',
          'Clique em "Atualizar Produto".',
        ],
      },
    ],
    dicas: [
      'Use "Ver Produto" no topo para conferir a ficha completa antes de sair da edição.',
    ],
    atencao: [
      'Alterar princípio ativo, grupo químico, antídoto ou registro muda o que sai nos próximos certificados. Certificados já emitidos não são reescritos, mas o produto passa a ser descrito de outra forma daqui para a frente.',
      'Trocar a unidade de medida de um produto que já tem saldo faz o número existente passar a significar outra coisa. Faça a conferência do estoque antes.',
    ],
    relacionados: [
      { titulo: 'Produtos', href: '/products' },
      { titulo: 'Estoque', href: '/estoque' },
    ],
  },

  'Products/Show': {
    area: 'Produtos e estoque',
    titulo: 'Detalhes do produto',
    paraQueServe: 'Mostra a ficha completa do produto: princípio ativo, grupo químico, antídoto e registro no órgão, que é exatamente o bloco que vai impresso no certificado.',
    comoUsar: [
      {
        titulo: 'Conferir a ficha antes de emitir documento',
        passos: [
          'Confira os quatro cartões da classificação técnica.',
          'Repare nas etiquetas "Obrigatório" e "Opcional" de cada cartão.',
          'Onde aparecer "Nenhum ... definido", o campo está vazio e sairá como não informado no certificado.',
        ],
      },
      {
        titulo: 'Corrigir o que estiver faltando',
        passos: [
          'Clique em "Editar Produto", no topo ou no rodapé da tela.',
          'Preencha o campo que estava vazio e salve.',
          'Volte aqui e confira em "Última Atualização" que a alteração foi registrada.',
          'Use "Voltar à Lista" para escolher outro produto.',
        ],
      },
    ],
    campos: [
      { nome: 'Reg. Min da Saude', descricao: 'Registro do produto no órgão de saúde. É marcado como opcional no sistema, mas é o que comprova que o produto pode ser comercializado e aplicado.' },
    ],
    atencao: [
      'Se o antídoto ou o registro aparecerem em branco aqui, o certificado sai com "Não informado" no lugar. Preencha antes de emitir documento para o cliente.',
    ],
    relacionados: [
      { titulo: 'Produtos', href: '/products' },
    ],
  },

  // ---------------------------------------------------------------------
  // Cadastros auxiliares do produto
  // ---------------------------------------------------------------------

  'ActiveIngredients/*': {
    area: 'Produtos e estoque',
    titulo: 'Princípios ativos',
    paraQueServe: 'Cadastro da substância que age no produto. Todo produto exige um princípio ativo, e é este nome que vai impresso no certificado entregue ao cliente.',
    comoUsar: [
      {
        titulo: 'Cadastrar um princípio ativo',
        passos: [
          'Clique em "Novo Princípio Ativo" no topo da lista.',
          'Digite o nome exatamente como consta no rótulo do produto.',
          'Clique em "Salvar".',
          'Ele passa a aparecer na lista do formulário de produto.',
        ],
      },
      {
        titulo: 'Consultar e corrigir',
        passos: [
          'Use a busca por nome, digitando pelo menos 2 caracteres.',
          'Veja na coluna "Produtos Vinculados" quantos produtos usam aquele princípio ativo.',
          'Use o ícone de olho para ver os detalhes e o de lápis para editar o nome.',
          'Use o ícone de lixeira para excluir e confirme no modal.',
        ],
      },
    ],
    dicas: [
      'Evite cadastrar o mesmo princípio ativo com grafias diferentes: cada variação vira uma linha separada e o certificado passa a sair de dois jeitos.',
    ],
    atencao: [
      'O botão de excluir fica desabilitado quando existem produtos vinculados. Para excluir, tire o princípio ativo dos produtos antes.',
      'Renomear um princípio ativo muda o texto de todos os produtos que o usam e, com isso, dos próximos certificados emitidos.',
    ],
    relacionados: [
      { titulo: 'Produtos', href: '/products' },
      { titulo: 'Grupos químicos', href: '/chemical-groups' },
    ],
  },

  'ChemicalGroups/*': {
    area: 'Produtos e estoque',
    titulo: 'Grupos químicos',
    paraQueServe: 'Cadastro da classe química a que o produto pertence. É obrigatório no cadastro de produto e sai impresso no certificado ao lado do princípio ativo.',
    comoUsar: [
      {
        titulo: 'Cadastrar um grupo químico',
        passos: [
          'Clique em "Novo Grupo Químico".',
          'Digite o nome do grupo conforme o rótulo ou a ficha técnica do fabricante.',
          'Clique em "Salvar".',
        ],
      },
      {
        titulo: 'Consultar e corrigir',
        passos: [
          'Busque por nome, com pelo menos 2 caracteres.',
          'Confira a coluna "Produtos Vinculados" antes de mexer em qualquer registro.',
          'Use o ícone de lápis para corrigir o nome e o de lixeira para excluir, confirmando no modal.',
        ],
      },
    ],
    dicas: [
      'O grupo químico ajuda a planejar rodízio de produtos: manter a lista consistente é o que permite enxergar quantos produtos da mesma classe você usa.',
    ],
    atencao: [
      'Grupo químico com produtos vinculados não pode ser excluído — o botão de lixeira fica desabilitado.',
      'O nome cadastrado aqui aparece no certificado. Trate como texto de documento, não como anotação interna.',
    ],
    relacionados: [
      { titulo: 'Produtos', href: '/products' },
      { titulo: 'Princípios ativos', href: '/active-ingredients' },
    ],
  },

  'Antidotes/*': {
    area: 'Produtos e estoque',
    titulo: 'Antídotos',
    paraQueServe: 'Cadastro do antídoto ou tratamento indicado em caso de intoxicação pelo produto. É obrigatório no cadastro de produto e sai impresso no certificado.',
    comoUsar: [
      {
        titulo: 'Cadastrar um antídoto',
        passos: [
          'Clique em "Novo Antídoto".',
          'Digite o texto exatamente como está na bula ou na ficha de segurança do produto.',
          'Clique em "Salvar".',
        ],
      },
      {
        titulo: 'Revisar os antídotos em uso',
        passos: [
          'Busque por nome, com pelo menos 2 caracteres.',
          'Veja em "Produtos Vinculados" quantos produtos dependem daquele texto.',
          'Use o ícone de lápis para corrigir e o de lixeira para excluir, confirmando no modal.',
        ],
      },
    ],
    atencao: [
      'Este é o texto que o cliente e o serviço médico vão ler em caso de acidente. Copie da bula do fabricante, sem resumir e sem improvisar.',
      'Antídoto com produtos vinculados não pode ser excluído.',
      'Quando o fabricante mudar a orientação, corrija aqui: a correção vale para todos os produtos que usam este antídoto.',
    ],
    relacionados: [
      { titulo: 'Produtos', href: '/products' },
    ],
  },

  'OrganRegistrations/*': {
    area: 'Produtos e estoque',
    titulo: 'Registros ministeriais',
    paraQueServe: 'Cadastro do número de registro do produto no órgão de saúde. É o dado que comprova, no certificado, que o produto aplicado é regularizado.',
    comoUsar: [
      {
        titulo: 'Cadastrar um registro',
        passos: [
          'Clique em "Novo Registro Ministerial".',
          'Digite o número do registro exatamente como aparece no rótulo do produto.',
          'Clique em "Salvar".',
          'Volte ao cadastro do produto e selecione o registro no campo "Reg. Min da Saude".',
        ],
      },
      {
        titulo: 'Conferir um registro existente',
        passos: [
          'Use a busca por registro, com pelo menos 2 caracteres.',
          'Confira a coluna "Produtos Vinculados".',
          'Use o ícone de olho para ver os detalhes e o de lápis para corrigir o número.',
        ],
      },
    ],
    atencao: [
      'Número de registro digitado errado é o tipo de erro que só aparece em fiscalização, com o certificado já na mão do cliente. Confira dígito por dígito contra o rótulo.',
      'Registro com produtos vinculados não pode ser excluído.',
    ],
    relacionados: [
      { titulo: 'Produtos', href: '/products' },
    ],
  },

  'EventTypes/*': {
    area: 'Produtos e estoque',
    titulo: 'Tipos de evento',
    paraQueServe: 'Cadastro dos tipos de evento que o técnico registra nos dispositivos durante a visita, como consumo, captura ou troca de isca. Cada tipo tem uma cor, usada para identificar o evento nas telas de dispositivos e de ordem de serviço.',
    comoUsar: [
      {
        titulo: 'Cadastrar um tipo de evento',
        passos: [
          'Clique em "Novo Tipo de Evento".',
          'Preencha o "Nome" e, se ajudar a equipe, a "Descrição".',
          'Escolha a "Cor" no seletor ou digite o código hexadecimal no campo ao lado.',
          'Deixe "Tipo de evento ativo" marcado e clique em "Salvar".',
        ],
      },
      {
        titulo: 'Manter a lista organizada',
        passos: [
          'Use "Ver" para abrir os detalhes de um tipo e "Editar" para alterá-lo.',
          'Para tirar um tipo de circulação sem perder o histórico, edite-o e desmarque "Tipo de evento ativo", depois clique em "Atualizar".',
          'Use "Excluir" apenas para tipos criados por engano e confirme no modal "Confirmar Exclusão".',
        ],
      },
    ],
    campos: [
      { nome: 'Cor', descricao: 'Cor usada para destacar o evento nas telas. Pode ser escolhida no seletor ou digitada como código hexadecimal, no formato #1e40af.' },
      { nome: 'Status', descricao: 'Ativo ou Inativo. O tipo inativo deixa de ser oferecido para novos registros de evento.' },
    ],
    dicas: [
      'Use cores bem diferentes entre si: no dia a dia, a equipe identifica o tipo pela cor antes de ler o nome.',
    ],
    atencao: [
      'A exclusão de um tipo de evento não pode ser desfeita, como o próprio modal avisa. Quando o tipo já foi usado em visitas, prefira inativá-lo.',
    ],
    relacionados: [
      { titulo: 'Tipos de evento', href: '/event-types' },
    ],
  },

  // ---------------------------------------------------------------------
  // Equipe técnica
  // ---------------------------------------------------------------------

  'Technicians/Index': {
    area: 'Produtos e estoque',
    titulo: 'Técnicos',
    paraQueServe: 'Lista a equipe técnica da empresa. É deste cadastro que saem os técnicos atribuídos às ordens de serviço e é por ele que se chega à ficha de EPI de cada um.',
    comoUsar: [
      {
        titulo: 'Encontrar um técnico',
        passos: [
          'Digite no campo de busca pelo menos 2 caracteres.',
          'A busca aceita nome, especialidade e número de registro.',
          'Confira a coluna "Status" para saber quem está ativo.',
        ],
      },
      {
        titulo: 'Consultar, editar ou excluir',
        passos: [
          'Use o ícone de olho para abrir os detalhes do técnico.',
          'Use o ícone de lápis para editar os dados.',
          'Use o ícone de lixeira para excluir e confirme no modal "Tem certeza que deseja excluir este técnico?".',
        ],
      },
      {
        titulo: 'Cadastrar alguém novo',
        passos: [
          'Clique em "Novo Técnico" no topo da tela.',
          'Preencha os dados pessoais e profissionais.',
          'Clique em "Criar Técnico".',
        ],
      },
    ],
    dicas: [
      'Quem saiu da empresa deve ser inativado, não excluído: o histórico de visitas e a ficha de EPI continuam ligados ao cadastro.',
    ],
    atencao: [
      'Excluir um técnico é definitivo e não há tela para desfazer.',
    ],
    relacionados: [
      { titulo: 'Controle de EPI', href: '/epis' },
      { titulo: 'Frota', href: '/veiculos' },
    ],
  },

  'Technicians/Create': {
    area: 'Produtos e estoque',
    titulo: 'Novo técnico',
    paraQueServe: 'Cadastra um profissional da equipe técnica com contato, especialidade e registro profissional.',
    comoUsar: [
      {
        titulo: 'Preencher os dados pessoais',
        passos: [
          'Clique em "Novo Técnico" na lista de técnicos.',
          'Em "Informações Pessoais", digite o nome completo.',
          'Informe o e-mail e o telefone de contato.',
        ],
      },
      {
        titulo: 'Completar os dados profissionais',
        passos: [
          'Em "Informações Profissionais", informe a especialidade e o número de registro.',
          'Deixe "Técnico ativo" marcado para que ele possa ser escalado em ordens de serviço.',
          'Use "Observações" para o que a equipe precisa saber sobre esse profissional.',
          'Clique em "Criar Técnico".',
        ],
      },
    ],
    campos: [
      { nome: 'Especialidade', descricao: 'Formação ou área do profissional, como Química, Biologia ou Engenharia.' },
      { nome: 'Número de Registro', descricao: 'Registro profissional no conselho de classe. É o dado que comprova a habilitação em fiscalização.' },
      { nome: 'Técnico ativo', descricao: 'Só o técnico ativo é oferecido nas telas que escalam a equipe.' },
    ],
    atencao: [
      'O nome e o registro profissional cadastrados aqui identificam quem executou o serviço nos documentos emitidos. Confira contra a carteira do conselho.',
    ],
    relacionados: [
      { titulo: 'Técnicos', href: '/technicians' },
    ],
  },

  'Technicians/Edit': {
    area: 'Produtos e estoque',
    titulo: 'Editar técnico',
    paraQueServe: 'Atualiza contato, especialidade, registro profissional e situação de um técnico já cadastrado.',
    comoUsar: [
      {
        titulo: 'Atualizar os dados',
        passos: [
          'Corrija os campos de contato em "Informações Pessoais".',
          'Ajuste a especialidade e o número de registro em "Informações Profissionais".',
          'Clique em "Salvar Alterações".',
        ],
      },
      {
        titulo: 'Registrar um desligamento',
        passos: [
          'Desmarque "Técnico ativo".',
          'Escreva em "Observações" a data e o motivo da saída.',
          'Clique em "Salvar Alterações".',
        ],
      },
    ],
    dicas: [
      'Desmarcar "Técnico ativo" é o caminho para o desligamento: a ficha de EPI e o histórico de visitas continuam acessíveis.',
    ],
    atencao: [
      'Mudar o número de registro profissional altera a identificação do responsável técnico nas telas do sistema. Só altere com o documento na mão.',
    ],
    relacionados: [
      { titulo: 'Técnicos', href: '/technicians' },
    ],
  },

  'Technicians/Show': {
    area: 'Produtos e estoque',
    titulo: 'Detalhes do técnico',
    paraQueServe: 'Mostra a ficha completa de um técnico e dá acesso à ficha de EPI dele.',
    comoUsar: [
      {
        titulo: 'Consultar o técnico',
        passos: [
          'Confira as informações de contato e as informações profissionais.',
          'Veja em "Status" se o técnico está ativo.',
          'Clique em "Editar Técnico" para corrigir qualquer dado.',
        ],
      },
      {
        titulo: 'Abrir a ficha de EPI',
        passos: [
          'Clique em "Ficha de EPI" no topo da tela.',
          'Na ficha, registre as entregas e colha a assinatura de recebimento.',
          'Baixe o documento em "Ficha em PDF" quando precisar apresentá-lo.',
        ],
      },
    ],
    dicas: [
      'O botão "Ficha de EPI" só aparece quando o módulo de EPI está ligado e você tem permissão para vê-lo.',
    ],
    relacionados: [
      { titulo: 'Técnicos', href: '/technicians' },
      { titulo: 'Controle de EPI', href: '/epis' },
    ],
  },

  // ---------------------------------------------------------------------
  // Estoque
  // ---------------------------------------------------------------------

  'Estoque/Index': {
    area: 'Produtos e estoque',
    titulo: 'Estoque',
    paraQueServe: 'Mostra o saldo de cada produto que controla estoque, com a quebra por local e por lote, e é daqui que se registra entrada, transferência e descarte.',
    comoUsar: [
      {
        titulo: 'Ver o saldo de um produto',
        passos: [
          'Clique na linha do produto para expandir.',
          'Em "Por local" veja onde o saldo está: depósito, técnico ou veículo.',
          'Em "Por lote" veja cada lote com validade, situação, quantidade e custo.',
          'Quando aparecer "Sem lote definido", há saldo registrado sem lote vinculado.',
        ],
      },
      {
        titulo: 'Filtrar a lista',
        passos: [
          'Clique no cartão "Abaixo do mínimo" para ver só os produtos em falta.',
          'Clique no cartão de lotes vencendo ou no de "Lotes vencidos com saldo" para filtrar por validade.',
          'Use os campos "Produto" e "Local" para restringir ainda mais.',
          'Clique em "Limpar filtros" para voltar à lista completa.',
        ],
      },
      {
        titulo: 'Registrar uma movimentação',
        passos: [
          'Clique em "Nova movimentação" no topo, ou use "Entrada", "Transferência" e "Descarte" dentro da linha do produto.',
          'No modal, escolha o tipo, o produto e o local (na transferência, a origem e o destino).',
          'Informe a quantidade e, se for o caso, escolha o lote — na saída, deixar em "Automático (por validade)" faz o sistema usar primeiro o lote que vence antes.',
          'No descarte, escreva o motivo, que é obrigatório.',
          'Ajuste a data e hora se o movimento aconteceu antes e clique em "Confirmar".',
        ],
      },
    ],
    campos: [
      { nome: 'Abaixo do mínimo', descricao: 'Quantidade de produtos cujo saldo está abaixo do estoque mínimo definido no cadastro do produto.' },
      { nome: 'Situação do lote', descricao: 'Normal, Vencendo ou Vencido, calculada pela data de validade do lote.' },
      { nome: 'Data e hora da movimentação', descricao: 'Quando o movimento realmente aconteceu. Deixe como está para registrar agora.' },
    ],
    dicas: [
      'A saída pelo fechamento da ordem de serviço é automática: você não precisa lançar o consumo da visita à mão aqui.',
      'O modal mostra o saldo atual do local escolhido antes de você confirmar. Use isso para conferir a quantidade.',
    ],
    atencao: [
      'Movimentação registrada não é apagada: a correção é um novo movimento. Confira quantidade, local e lote antes de confirmar.',
      'Lote vencido não é escolhido automaticamente na saída, porque aplicar produto fora da validade é infração. O caminho para tirá-lo da prateleira é o descarte com motivo.',
      'Descarte sem motivo não é aceito: o motivo é o que fecha o ciclo do produto perante a fiscalização.',
    ],
    relacionados: [
      { titulo: 'Lotes', href: '/lotes' },
      { titulo: 'Inventário', href: '/inventarios' },
      { titulo: 'Produtos', href: '/products' },
    ],
  },

  'Estoque/Lotes': {
    area: 'Produtos e estoque',
    titulo: 'Lotes',
    paraQueServe: 'Cadastro dos lotes comprados, com validade, custo unitário, fornecedor e nota fiscal. É por aqui que o saldo de um produto entra pela primeira vez.',
    comoUsar: [
      {
        titulo: 'Cadastrar um lote',
        passos: [
          'Clique em "Novo lote".',
          'Escolha o produto e digite o "Número do lote" como está na embalagem.',
          'Informe a "Validade", a data em "Recebido em" e o "Custo unitário".',
          'Preencha "Fornecedor" e "Nota fiscal" quando tiver a nota em mãos.',
          'Em "Entrada inicial", informe a quantidade e o local para o saldo já entrar junto com o cadastro.',
          'Clique em "Cadastrar lote".',
        ],
      },
      {
        titulo: 'Consultar e corrigir',
        passos: [
          'Use o filtro "Produto" para ver só os lotes de um produto.',
          'A lista vem ordenada por validade, do que vence antes para o que vence depois.',
          'Use o ícone de prancheta para abrir a rastreabilidade do lote.',
          'Use o ícone de lápis para corrigir os dados e o de lixeira para excluir, confirmando no modal.',
        ],
      },
    ],
    campos: [
      { nome: 'Custo unitário', descricao: 'Dado interno de custo, usado para calcular o custo do serviço. Nunca aparece para o cliente.' },
      { nome: 'Entrada inicial', descricao: 'Opcional. Preenchendo quantidade e local, o saldo do lote já entra assim que ele é cadastrado. Informar a quantidade obriga a informar o local.' },
      { nome: 'Saldo', descricao: 'Quanto ainda resta daquele lote, somando todos os locais.' },
    ],
    dicas: [
      'O produto de um lote não muda depois de cadastrado: se errou o produto, exclua o lote enquanto ele ainda não tem movimento e cadastre de novo.',
      'Cadastre um lote por compra, mesmo que o número do lote se repita entre notas diferentes, para que validade e custo fiquem corretos.',
    ],
    atencao: [
      'Lote que já tem movimento não pode ser excluído — o sistema recusa e explica o motivo na tela.',
      'A validade digitada aqui é o que decide se o produto pode ser aplicado. Confira contra a embalagem, não contra a nota.',
      'O lote é o que liga o produto aplicado ao cliente atendido. Cadastro incompleto aqui significa rastreabilidade incompleta na fiscalização.',
    ],
    relacionados: [
      { titulo: 'Estoque', href: '/estoque' },
      { titulo: 'Inventário', href: '/inventarios' },
    ],
  },

  'Estoque/Inventario': {
    area: 'Produtos e estoque',
    titulo: 'Inventário',
    paraQueServe: 'Contagem física do estoque de um local. O sistema fotografa o saldo na abertura, você digita o que encontrou na prateleira e a finalização gera o ajuste de cada item divergente.',
    comoUsar: [
      {
        titulo: 'Abrir um inventário',
        passos: [
          'Clique em "Abrir inventário".',
          'Escolha o "Local" que será contado.',
          'Escreva uma "Observação" se quiser registrar o motivo da contagem.',
          'Clique em "Abrir inventário" no modal — o local continua operando normalmente durante a contagem.',
        ],
      },
      {
        titulo: 'Contar os itens',
        passos: [
          'Na lista, clique em "Continuar contagem" no inventário aberto.',
          'Em cada item, digite a "Quantidade contada" a partir do que você viu na prateleira.',
          'Se houver diferença, escreva a "Justificativa da diferença", que é obrigatória.',
          'Clique em "Salvar contagem" na própria linha e siga para o próximo item.',
          'Acompanhe a barra de progresso até que todos os itens estejam contados.',
        ],
      },
      {
        titulo: 'Finalizar ou cancelar',
        passos: [
          'Com todos os itens contados, clique em "Finalizar inventário".',
          'Confira no modal a lista de divergências e o quanto cada item vai subir ou descer.',
          'Clique em "Confirmar finalização" para gerar os ajustes.',
          'Se a contagem precisar ser descartada, use "Cancelar inventário" e confirme em "Sim, cancelar": nada é alterado no saldo.',
        ],
      },
    ],
    campos: [
      { nome: 'Revelar saldo do sistema', descricao: 'O saldo esperado fica escondido de propósito, linha a linha. Quem conta vendo o número esperado tende a confirmar o número esperado, e isso deixa de ser contagem.' },
      { nome: 'Situação', descricao: 'Aberto, Finalizado ou Cancelado. Inventário que não está aberto não aceita mais alteração.' },
    ],
    dicas: [
      'Conte primeiro, digite depois. Só revele o saldo do sistema quando precisar entender uma diferença que já encontrou.',
      'A tela foi feita para ser usada no celular, com uma mão, andando pelo depósito.',
    ],
    atencao: [
      'A finalização gera o ajuste de saldo de cada item divergente e não pode ser desfeita. Contagem errada exige abrir um novo inventário.',
      'Diferença positiva aumenta o saldo do local e negativa reduz. A justificativa é o que sustenta o ajuste em uma auditoria.',
    ],
    relacionados: [
      { titulo: 'Estoque', href: '/estoque' },
      { titulo: 'Lotes', href: '/lotes' },
    ],
  },

  'Estoque/Rastreabilidade': {
    area: 'Produtos e estoque',
    titulo: 'Rastreabilidade de lote',
    paraQueServe: 'Responde qual produto foi aplicado em qual cliente. Mostra os dados de um lote e todas as aplicações registradas com ele, com data, ordem de serviço, cliente, endereço, técnico e quantidade.',
    comoUsar: [
      {
        titulo: 'Consultar um lote',
        passos: [
          'Chegue aqui pelo ícone de prancheta na linha do lote, na tela de Lotes.',
          'Confira no topo a validade, a data de recebimento, o saldo atual, o custo, o fornecedor e a nota fiscal.',
          'Em "Aplicações registradas", veja onde cada parcela do lote foi usada e o total aplicado.',
        ],
      },
      {
        titulo: 'Trocar de lote sem sair da tela',
        passos: [
          'Digite no campo "Buscar outro lote" o nome do produto ou o número do lote.',
          'Escolha entre as sugestões que aparecem abaixo do campo.',
          'A tela recarrega com a rastreabilidade do lote escolhido.',
        ],
      },
      {
        titulo: 'Levar o documento para a fiscalização',
        passos: [
          'Confirme que o lote exibido é o que foi questionado.',
          'Clique em "Exportar em PDF".',
          'O arquivo abre em uma nova aba com o lote e todas as aplicações.',
        ],
      },
    ],
    dicas: [
      'As aplicações aparecem aqui a partir do fechamento da ordem de serviço, que é quando a baixa de estoque acontece.',
    ],
    atencao: [
      'Esta é a primeira pergunta da fiscalização em caso de incidente. Lote cadastrado sem número correto ou ordem de serviço fechada sem os produtos aplicados deixam esta tela incompleta justamente quando ela é necessária.',
    ],
    relacionados: [
      { titulo: 'Lotes', href: '/lotes' },
      { titulo: 'Estoque', href: '/estoque' },
    ],
  },

  // ---------------------------------------------------------------------
  // EPI
  // ---------------------------------------------------------------------

  'Epi/Index': {
    area: 'Produtos e estoque',
    titulo: 'Controle de EPI',
    paraQueServe: 'Cadastro dos modelos de equipamento de proteção que a empresa compra, com o Certificado de Aprovação do fabricante e a vida útil de cada item. A ficha de quem recebeu o quê fica no cadastro de cada técnico.',
    comoUsar: [
      {
        titulo: 'Cadastrar um modelo de EPI',
        passos: [
          'Clique em "Novo EPI".',
          'Preencha o "Nome", escolha o "Tipo" e informe o "Fabricante".',
          'Informe o "Número do CA" e a "Validade do CA" conforme o certificado do fabricante.',
          'Preencha a "Vida útil, em dias" se o item tem troca programada; em branco significa sem troca programada.',
          'Marque "Uso obrigatório em serviço" e "Ativo, disponível para novas entregas" conforme o caso e clique em "Salvar".',
        ],
      },
      {
        titulo: 'Manter o cadastro em dia',
        passos: [
          'Use os campos "Buscar", "Tipo" e "Situação" e clique em "Filtrar".',
          'Acompanhe os cartões "CA a vencer" e "CA vencido" no topo da tela.',
          'Clique em "Editar" na linha do EPI para renovar o número e a validade do CA.',
          'Use "Inativar" para tirar um modelo de circulação e "Reativar" para trazê-lo de volta.',
        ],
      },
      {
        titulo: 'Extrair a relação de entregas',
        passos: [
          'No bloco "Relação de entregas por período", preencha as datas "De" e "Até".',
          'Clique em "Baixar CSV".',
          'O arquivo traz todas as entregas do período, inclusive as estornadas, marcadas como tais.',
        ],
      },
    ],
    campos: [
      { nome: 'Número do CA', descricao: 'Certificado de Aprovação do fabricante. Pode ficar em branco enquanto o certificado não estiver em mãos — campo vazio aparece em cinza, como estado neutro, e não como irregularidade.' },
      { nome: 'Vida útil, em dias', descricao: 'Prazo do item na mão do técnico, contado a partir da entrega. Não se confunde com a validade do CA.' },
      { nome: 'CA vencido', descricao: 'Modelo cujo Certificado de Aprovação passou da validade. Ele não pode ser entregue enquanto o CA não for renovado no cadastro.' },
    ],
    dicas: [
      'Cadastre o modelo com o número do CA e a validade antes de registrar entregas: é o CA do dia da entrega que fica gravado na ficha do técnico.',
      'O botão "Excluir" só aparece nos modelos que nunca foram entregues. Para os demais, use "Inativar".',
    ],
    atencao: [
      'Entregar EPI com o CA vencido é exatamente a infração que este registro deveria evitar. Renove o CA no cadastro antes de registrar a entrega.',
      'A exclusão de um cadastro de EPI não pode ser desfeita.',
    ],
    relacionados: [
      { titulo: 'Técnicos', href: '/technicians' },
    ],
  },

  'Epi/Ficha': {
    area: 'Produtos e estoque',
    titulo: 'Ficha de EPI do técnico',
    paraQueServe: 'Registro de tudo que foi entregue a um técnico, com a assinatura de recebimento. É a ficha exigida pela norma de EPI e a prova da empresa em uma reclamação trabalhista.',
    comoUsar: [
      {
        titulo: 'Registrar uma entrega',
        passos: [
          'Clique em "Registrar entrega".',
          'Escolha o EPI — os itens com o CA vencido aparecem desabilitados, com a razão escrita.',
          'Confira o quadro "O que será gravado na ficha": número do CA, validade e troca programada.',
          'Informe a quantidade, a data da entrega e o motivo.',
          'Clique em "Registrar entrega".',
        ],
      },
      {
        titulo: 'Colher a assinatura',
        passos: [
          'Na linha da entrega, clique em "Assinar".',
          'Peça ao técnico que assine no quadro de assinatura.',
          'Clique em "Confirmar recebimento".',
          'A linha passa de "Pendente de assinatura" para "Assinada".',
        ],
      },
      {
        titulo: 'Devolver ou corrigir uma entrega',
        passos: [
          'Para registrar a devolução de um item, clique em "Devolver", escreva o motivo e a data e confirme em "Registrar devolução".',
          'Para corrigir uma entrega errada, clique em "Estornar", escreva o motivo e confirme em "Estornar entrega".',
          'Depois do estorno, registre a entrega correta como uma nova entrega.',
        ],
      },
      {
        titulo: 'Emitir o documento',
        passos: [
          'Resolva antes as linhas marcadas como pendentes de assinatura.',
          'Clique em "Ficha em PDF" no topo da tela.',
          'O documento abre em uma nova aba, pronto para arquivar ou apresentar.',
        ],
      },
    ],
    campos: [
      { nome: 'CA da entrega', descricao: 'O Certificado de Aprovação copiado no dia da entrega. Renovar o certificado no cadastro do EPI não reescreve a ficha do ano passado.' },
      { nome: 'Trocar até', descricao: 'Data limite do item na mão do técnico, calculada pela vida útil do EPI a partir da entrega.' },
      { nome: 'Devolver', descricao: 'Diz que a entrega aconteceu e terminou. O item deixa de contar como EPI em uso e continua na ficha.' },
      { nome: 'Estornar', descricao: 'Diz que a entrega está errada. A linha permanece na ficha marcada como estornada, com o motivo.' },
    ],
    dicas: [
      'A ficha de um técnico inativo continua acessível e extraível: o desligamento não apaga o que foi entregue enquanto ele trabalhou.',
      'Acompanhe os cartões "Trocas vencidas" e "Pendentes de assinatura" para saber o que resolver primeiro.',
    ],
    atencao: [
      'Sem a assinatura de recebimento, a entrega não vale como ficha. Colha a assinatura no ato da entrega.',
      'Entrega não se edita nem se apaga. A única correção é o estorno com motivo, e a linha errada continua visível — é essa sequência que prova que houve correção e não reescrita.',
      'O estorno não pode ser desfeito.',
    ],
    relacionados: [
      { titulo: 'Controle de EPI', href: '/epis' },
      { titulo: 'Técnicos', href: '/technicians' },
    ],
  },

  // ---------------------------------------------------------------------
  // Frota
  // ---------------------------------------------------------------------

  'Frota/Index': {
    area: 'Produtos e estoque',
    titulo: 'Frota',
    paraQueServe: 'Lista os veículos da empresa com hodômetro, consumo médio, custo por quilômetro e o que está perto de vencer em manutenções e documentos.',
    comoUsar: [
      {
        titulo: 'Cadastrar um veículo',
        passos: [
          'Clique em "Novo veículo".',
          'Preencha a "Placa", a "Marca", o "Modelo" e o "Ano".',
          'Escolha o "Tipo" e a "Situação" do veículo.',
          'Informe o "Custo por quilômetro padrão", usado enquanto o consumo real ainda não pôde ser medido.',
          'Clique em "Salvar".',
        ],
      },
      {
        titulo: 'Acompanhar a frota',
        passos: [
          'Use os cartões do topo para ver veículos ativos, manutenções a vencer e documentos vencendo.',
          'Filtre por "Situação" para separar o que está ativo, em manutenção ou inativo.',
          'Confira a coluna "Alertas" de cada linha.',
          'Clique na placa para abrir a ficha completa do veículo.',
        ],
      },
    ],
    campos: [
      { nome: 'Consumo', descricao: 'Média de quilômetros por litro. Aparece como "Sem histórico" enquanto não houver abastecimentos suficientes.' },
      { nome: 'Custo/km', descricao: 'Custo por quilômetro. Quando aparece a etiqueta "padrão", o número vem do cadastro do veículo e não de uma medição real.' },
    ],
    dicas: [
      'O consumo e o custo por quilômetro só passam a ser medidos depois de quatro abastecimentos de tanque cheio, que é o mínimo para a medição não ser ruído.',
    ],
    relacionados: [
      { titulo: 'Frota', href: '/veiculos' },
      { titulo: 'Técnicos', href: '/technicians' },
    ],
  },

  'Frota/Show': {
    area: 'Produtos e estoque',
    titulo: 'Ficha do veículo',
    paraQueServe: 'Reúne tudo de um veículo: consumo médio, custo por quilômetro, próximas manutenções, documentos, estoque que está dentro dele e o histórico de abastecimentos.',
    comoUsar: [
      {
        titulo: 'Registrar um abastecimento',
        passos: [
          'Clique em "Registrar abastecimento".',
          'Informe a data e a "Quilometragem do hodômetro" — a tela mostra a última registrada logo abaixo do campo.',
          'Preencha "Litros", "Valor total" e o "Combustível", e o "Posto" se quiser.',
          'Marque "Enchi o tanque" apenas quando o tanque realmente foi completado.',
          'Se a despesa for para o financeiro, marque "Lançar como conta a pagar" e escolha o fornecedor.',
        ],
      },
      {
        titulo: 'Acompanhar o consumo',
        passos: [
          'Veja no cartão "Consumo médio" a média do período apurado.',
          'No gráfico, cada ponto é o consumo entre dois abastecimentos de tanque cheio.',
          'Passe o cursor sobre um ponto para ver a data, o km/l e a distância.',
          'Abra "Ver os números" para conferir a tabela com distância, litros e km/l de cada intervalo.',
        ],
      },
      {
        titulo: 'Conferir vencimentos e carga',
        passos: [
          'Em "Próximas manutenções", veja o que vence por data e por quilometragem.',
          'Em "Documentos", confira a validade de cada documento e a coluna "Situação".',
          'Em "Estoque no veículo", veja o produto que já saiu do depósito e está dentro do carro.',
        ],
      },
    ],
    campos: [
      { nome: 'Enchi o tanque', descricao: 'O consumo só é calculado entre dois tanques cheios, o único trecho em que se sabe quanto combustível havia nas duas pontas.' },
      { nome: 'Custo por quilômetro', descricao: 'Quando o cartão aparece em amarelo e o aviso fala em valor padrão, o número vem do cadastro do veículo e ainda não descreve o veículo real.' },
      { nome: 'Alerta da manutenção', descricao: 'Data e quilometragem são critérios independentes: vence o que chegar primeiro.' },
    ],
    dicas: [
      'O gráfico só aparece a partir de dois intervalos completos de tanque cheio.',
      'A apuração do custo por quilômetro começa com três intervalos completos, ou seja, quatro abastecimentos de tanque cheio registrados.',
    ],
    atencao: [
      'Marcar "Enchi o tanque" sem ter enchido produz um consumo errado que ninguém percebe depois.',
      'A quilometragem informada precisa ser coerente com a última registrada, senão o sistema recusa o abastecimento.',
    ],
    relacionados: [
      { titulo: 'Frota', href: '/veiculos' },
      { titulo: 'Estoque', href: '/estoque' },
    ],
  },
};
