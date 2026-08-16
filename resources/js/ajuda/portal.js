/**
 * Manual de uso do Portal do Cliente.
 *
 * Quem lê estes textos é o cliente da empresa de controle de pragas, não um
 * funcionário: linguagem simples, sem termos internos e sem citar nada que a
 * tela não mostre de fato.
 */
export default {
  'Portal/Dashboard': {
    area: 'Portal do cliente',
    titulo: 'Início',
    paraQueServe: 'É a primeira tela depois que você entra no portal. Mostra, em três blocos, o que precisa da sua atenção agora: a próxima visita, as pendências em aberto e as faturas vencendo.',
    comoUsar: [
      {
        titulo: 'Ver a próxima visita',
        passos: [
          'Olhe o bloco "Próxima visita", no alto da tela.',
          'A data aparece em destaque e, quando há horário definido, aparece logo abaixo como "A partir de".',
          'Quando o serviço e o endereço já estão definidos, eles aparecem embaixo da data.',
          'Clique em "Ver todas as visitas" para abrir a lista completa.',
        ],
      },
      {
        titulo: 'Conferir o que está pendente',
        passos: [
          'No bloco "Pendências em aberto", veja o total e as três primeiras da lista.',
          'Repare no prazo de cada uma: quando o prazo já passou, ele aparece em vermelho.',
          'Clique em "Ver todas as pendências" para abrir a lista inteira, com o detalhe de cada uma.',
        ],
      },
      {
        titulo: 'Conferir faturas a vencer',
        passos: [
          'No bloco "Faturas vencendo", veja quantas são, com a data de vencimento e o valor de cada uma.',
          'Clique em "Ver todas as faturas" para abrir a lista completa.',
        ],
      },
    ],
    dicas: [
      'Quando um bloco aparece vazio, é porque não há nada pendente naquele assunto — não é erro.',
      'Cada bloco mostra no máximo três itens; use o link do rodapé do bloco para ver o restante.',
    ],
    relacionados: [
      { titulo: 'Visitas', href: '/portal/visitas' },
      { titulo: 'Pendências', href: '/portal/adequacoes' },
      { titulo: 'Faturas', href: '/portal/faturas' },
    ],
  },

  'Portal/Auth/Login': {
    area: 'Portal do cliente',
    titulo: 'Entrar no portal',
    paraQueServe: 'Tela de entrada do portal. Use o e-mail e a senha do seu acesso para ver visitas, documentos, pendências e faturas.',
    comoUsar: [
      {
        titulo: 'Entrar',
        passos: [
          'Digite seu e-mail no campo "E-mail".',
          'Digite sua senha no campo "Senha".',
          'Clique em "Entrar".',
          'Se os dados estiverem certos, você cai direto na tela de início do portal.',
        ],
      },
      {
        titulo: 'Quando não lembra a senha',
        passos: [
          'Clique em "Esqueci minha senha", embaixo do botão de entrar.',
          'Informe o e-mail cadastrado e peça o link de recuperação.',
          'Abra o e-mail que chegar e clique no link para escolher uma senha nova.',
        ],
      },
    ],
    atencao: [
      'Se aparecer uma mensagem em vermelho embaixo dos campos, confira o e-mail e digite a senha de novo — por segurança, a senha é apagada a cada tentativa que não dá certo.',
      'O acesso é criado pela empresa. Se você ainda não recebeu o convite por e-mail, fale com ela.',
    ],
  },

  'Portal/Auth/EsqueciSenha': {
    area: 'Portal do cliente',
    titulo: 'Esqueci minha senha',
    paraQueServe: 'Pede por e-mail um link para você escolher uma senha nova, quando não consegue mais entrar no portal.',
    comoUsar: [
      {
        titulo: 'Pedir o link de recuperação',
        passos: [
          'Digite no campo "E-mail" o mesmo endereço que você usa para entrar no portal.',
          'Clique em "Enviar link de recuperação".',
          'Uma mensagem verde confirma o envio na própria tela.',
          'Abra sua caixa de entrada e clique no link recebido para definir a nova senha.',
        ],
      },
      {
        titulo: 'Voltar sem pedir nada',
        passos: [
          'Clique em "Voltar para o login", no fim da tela.',
          'Tente entrar de novo com a senha que você já tem.',
        ],
      },
    ],
    dicas: [
      'A mensagem de confirmação aparece mesmo quando o e-mail digitado não tem acesso ao portal — é uma proteção. Se o e-mail não chegar, confira se digitou o endereço certo.',
      'Se o e-mail demorar, olhe também a caixa de spam ou lixo eletrônico.',
    ],
  },

  'Portal/Auth/DefinirSenha': {
    area: 'Portal do cliente',
    titulo: 'Definir senha',
    paraQueServe: 'Tela onde você escolhe sua senha. Vale tanto para o primeiro acesso, pelo convite que a empresa enviou, quanto para trocar a senha depois de pedir a recuperação.',
    comoUsar: [
      {
        titulo: 'Escolher a senha',
        passos: [
          'Abra o link que chegou no seu e-mail — ele já leva direto a esta tela.',
          'Digite a senha nova em "Nova senha".',
          'Repita exatamente a mesma senha em "Confirme a nova senha".',
          'Confira a lista de regras acima dos campos: os itens ficam verdes quando a senha está de acordo.',
          'Clique em "Salvar senha".',
        ],
      },
      {
        titulo: 'Quando o link não funciona mais',
        passos: [
          'Se aparecer o aviso amarelo "Link expirado ou inválido", o link já foi usado ou passou do prazo.',
          'Clique em "Pedir novo link".',
          'Informe seu e-mail para receber um link novo e repita o passo a passo.',
        ],
      },
    ],
    campos: [
      { nome: 'Nova senha', descricao: 'Precisa ter pelo menos 8 caracteres.' },
      { nome: 'Confirme a nova senha', descricao: 'Repetição da senha, só para garantir que você não digitou errado. As duas precisam ser iguais.' },
    ],
    atencao: [
      'O aviso de link vencido só aparece depois que você tenta salvar. Se aparecer, nenhuma senha foi trocada.',
      'O link do e-mail vale uma vez só. Depois de salvar a senha, use a tela de entrada normal.',
    ],
  },

  'Portal/Visitas/Index': {
    area: 'Portal do cliente',
    titulo: 'Visitas',
    paraQueServe: 'Lista o histórico das visitas agendadas e realizadas no seu endereço, da mais recente para a mais antiga.',
    comoUsar: [
      {
        titulo: 'Encontrar uma visita',
        passos: [
          'Percorra a lista: cada cartão mostra a data, o endereço, a situação, o serviço e o técnico.',
          'Quando você tem mais de um endereço, escolha um deles no campo "Endereço" para ver só as visitas dele.',
          'Para limitar por período, preencha "De" e "Até" com as datas desejadas.',
          'Clique em "Limpar filtros" para voltar a ver tudo.',
        ],
      },
      {
        titulo: 'Abrir o detalhe de uma visita',
        passos: [
          'Clique no cartão da visita que quer consultar.',
          'Você vai para a tela com o resumo, o horário, o técnico e o que foi feito.',
          'De lá, use "Voltar para visitas" para retornar à lista.',
        ],
      },
      {
        titulo: 'Ver visitas mais antigas',
        passos: [
          'Role até o fim da lista.',
          'Use os números de página para avançar no histórico.',
          'Repita o filtro na nova página, se precisar — o filtro vale só para a página que está aberta.',
        ],
      },
    ],
    campos: [
      { nome: 'Situação', descricao: 'Em que ponto está a visita: aguardando, agendada, em andamento, concluída, cancelada ou em espera.' },
      { nome: 'Técnico', descricao: 'Quem vai atender ou atendeu. Aparece como "Não atribuído" enquanto a empresa não definir.' },
    ],
    dicas: [
      'O filtro de endereço só aparece quando existe mais de um endereço entre as visitas da página.',
      'Os filtros funcionam sobre as visitas da página aberta. Se não achar o período que procura, avance a página antes de filtrar de novo.',
    ],
    relacionados: [
      { titulo: 'Documentos', href: '/portal/certificados' },
      { titulo: 'Pendências', href: '/portal/adequacoes' },
    ],
  },

  'Portal/Visitas/Show': {
    area: 'Portal do cliente',
    titulo: 'Detalhe da visita',
    paraQueServe: 'Mostra tudo o que o portal tem sobre uma visita: data, horário, endereço, serviço, técnico e a descrição do que foi feito. É também onde você baixa a ordem de serviço.',
    comoUsar: [
      {
        titulo: 'Conferir os dados da visita',
        passos: [
          'Veja o selo colorido ao lado de "Resumo da visita" para saber a situação atual.',
          'No quadro abaixo, confira data, horário, endereço, serviço e técnico.',
          'Leia o bloco "O que foi feito nesta visita" para o relato da empresa.',
        ],
      },
      {
        titulo: 'Baixar a ordem de serviço',
        passos: [
          'Clique em "Baixar OS", no topo da tela.',
          'O arquivo em PDF é baixado direto pelo navegador.',
          'Guarde o arquivo: ele é o mesmo documento que a empresa emite internamente.',
        ],
      },
      {
        titulo: 'Voltar para a lista',
        passos: [
          'Clique em "Voltar para visitas", logo abaixo do título.',
          'Você volta para o histórico completo, com os filtros disponíveis.',
        ],
      },
    ],
    dicas: [
      'O horário só aparece quando a empresa registrou hora de início. Quando há hora de término, o portal mostra o intervalo completo.',
      'Se o bloco de descrição estiver vazio, a empresa ainda não registrou o relato dessa visita.',
    ],
    relacionados: [
      { titulo: 'Visitas', href: '/portal/visitas' },
      { titulo: 'Solicitações', href: '/portal/solicitacoes' },
    ],
  },

  'Portal/Certificados/Index': {
    area: 'Portal do cliente',
    titulo: 'Certificados',
    paraQueServe: 'Reúne os certificados emitidos para o seu endereço e permite baixar cada um em PDF. Fica no menu como "Documentos".',
    comoUsar: [
      {
        titulo: 'Encontrar o certificado que você precisa',
        passos: [
          'Comece pela seção "Vigentes", com os certificados que ainda estão valendo.',
          'Se procura um documento antigo, veja a seção "Vencidos ou cancelados", logo abaixo.',
          'Confira em cada cartão o endereço, a data de emissão e até quando ele vale.',
          'Se não encontrar, avance nos números de página no fim da tela.',
        ],
      },
      {
        titulo: 'Baixar um certificado',
        passos: [
          'Localize o cartão do certificado desejado.',
          'Clique em "Baixar certificado".',
          'O PDF é baixado pelo navegador, com nome e data no arquivo.',
        ],
      },
    ],
    campos: [
      { nome: 'Emitido em', descricao: 'Data em que o serviço foi executado e o certificado passou a valer.' },
      { nome: 'Válido até', descricao: 'Prazo de garantia do serviço. Mostra "Sem prazo de validade" quando não há data definida.' },
    ],
    dicas: [
      'Certificado vencido ou cancelado continua disponível para baixar — pode ser exigido em fiscalização de um período passado.',
      'As duas seções contam apenas os certificados da página aberta; um documento mais antigo pode estar na página seguinte.',
    ],
    atencao: [
      'Se a tela estiver vazia, nenhuma visita gerou certificado para o seu endereço ainda. Assim que gerar, ele aparece aqui sozinho.',
    ],
    relacionados: [
      { titulo: 'Visitas', href: '/portal/visitas' },
      { titulo: 'Contratos', href: '/portal/contratos' },
    ],
  },

  'Portal/Contratos/Index': {
    area: 'Portal do cliente',
    titulo: 'Contratos',
    paraQueServe: 'Mostra os contratos de serviço firmados para o seu endereço, com período, periodicidade e valor, separados entre vigentes e encerrados.',
    comoUsar: [
      {
        titulo: 'Consultar as condições de um contrato',
        passos: [
          'Escolha a seção certa: "Vigentes" para o que está valendo, "Encerrados" para os antigos.',
          'No cartão, confira o período, o tipo de serviço, a periodicidade e o valor.',
          'Veja o bloco "Próximas visitas previstas", quando existir, para saber quando é o próximo atendimento daquele contrato.',
        ],
      },
      {
        titulo: 'Baixar o contrato',
        passos: [
          'Localize o cartão do contrato desejado.',
          'Clique em "Baixar contrato".',
          'O PDF é baixado pelo navegador para você guardar ou imprimir.',
        ],
      },
    ],
    campos: [
      { nome: 'Período', descricao: 'Início e fim do contrato. Contrato sem data de término aparece como "sem data de término" e é tratado como vigente.' },
      { nome: 'Periodicidade', descricao: 'De quanto em quanto tempo a visita se repete, por exemplo "A cada 1 mês".' },
      { nome: 'Praga-alvo', descricao: 'Quais pragas o serviço contratado atende.' },
      { nome: 'Cláusula adicional', descricao: 'Texto combinado com a empresa fora das condições padrão. Só aparece quando existe.' },
    ],
    dicas: [
      'As próximas visitas previstas só aparecem quando já há visita agendada para o endereço daquele contrato.',
    ],
    relacionados: [
      { titulo: 'Visitas', href: '/portal/visitas' },
      { titulo: 'Faturas', href: '/portal/faturas' },
    ],
  },

  'Portal/Adequacoes/Index': {
    area: 'Portal do cliente',
    titulo: 'Pendências',
    paraQueServe: 'Lista o que precisa ser resolvido no seu espaço para manter o controle de pragas em dia, agrupado pela visita em que a pendência foi identificada.',
    comoUsar: [
      {
        titulo: 'Entender o que está pendente',
        passos: [
          'Veja o título de cada bloco: ele indica a visita e a data em que a pendência foi identificada.',
          'Em cada item, leia a etiqueta cinza com o tipo e, abaixo, a descrição do que precisa ser feito.',
          'Olhe o prazo, à direita do item, para saber até quando resolver.',
        ],
      },
      {
        titulo: 'Priorizar pelo prazo',
        passos: [
          'Comece pelos itens com fundo vermelho: o prazo deles já encerrou.',
          'Depois, resolva os de etiqueta amarela, que vencem nos próximos dias.',
          'Deixe por último os de etiqueta azul e os marcados como "Sem prazo definido".',
          'Avance pelos números de página no fim da tela para ver as demais pendências.',
        ],
      },
    ],
    campos: [
      { nome: 'Tipo', descricao: 'Natureza do ajuste: Estrutural, Sanitário, Higiênico, Químico ou Outros.' },
      { nome: 'Prazo', descricao: 'Data limite combinada para o ajuste. Aparece como "Sem prazo definido" quando a empresa não estabeleceu data.' },
    ],
    dicas: [
      'Resolver as pendências é responsabilidade sua ou do seu espaço; a empresa apenas aponta o que encontrou na visita.',
      'Se tiver dúvida sobre como resolver algum item, abra uma solicitação e pergunte à empresa.',
    ],
    atencao: [
      'As pendências não são marcadas como resolvidas por você nesta tela. A empresa confere e baixa o item na visita seguinte.',
    ],
    relacionados: [
      { titulo: 'Visitas', href: '/portal/visitas' },
      { titulo: 'Solicitações', href: '/portal/solicitacoes' },
    ],
  },

  'Portal/Faturas/Index': {
    area: 'Portal do cliente',
    titulo: 'Faturas',
    paraQueServe: 'Mostra o vencimento, o valor, a situação e a forma de pagamento de cada fatura lançada para o seu cadastro.',
    comoUsar: [
      {
        titulo: 'Conferir uma fatura',
        passos: [
          'Localize a linha pela data na coluna "Vencimento".',
          'Confira o valor na coluna "Valor".',
          'Veja a coluna "Situação" para saber se está em aberto, paga, com pagamento parcial ou vencida.',
          'Confira em "Forma de pagamento" como o pagamento foi combinado.',
        ],
      },
      {
        titulo: 'Ver faturas de outros períodos',
        passos: [
          'Role até o fim da tabela.',
          'Use os números de página para ver as faturas anteriores.',
        ],
      },
    ],
    campos: [
      { nome: 'Situação', descricao: 'Como está o pagamento da fatura. Quando houve pagamento parcial, o valor já pago aparece embaixo do valor total.' },
      { nome: 'Forma de pagamento', descricao: 'Como o pagamento foi combinado com a empresa. Mostra "Não informada" quando ainda não há definição.' },
    ],
    atencao: [
      'O pagamento pelo portal ainda não está disponível: esta tela é só de consulta. Para pagar ou pedir a segunda via, fale com a empresa.',
    ],
    relacionados: [
      { titulo: 'Contratos', href: '/portal/contratos' },
      { titulo: 'Solicitações', href: '/portal/solicitacoes' },
    ],
  },

  'Portal/Solicitacoes/Index': {
    area: 'Portal do cliente',
    titulo: 'Solicitações',
    paraQueServe: 'É por aqui que você pede atendimento à empresa e acompanha a resposta. Serve para relatar um problema, tirar dúvida ou pedir uma visita fora do previsto.',
    comoUsar: [
      {
        titulo: 'Abrir uma solicitação',
        passos: [
          'No bloco "Abrir nova solicitação", escreva um resumo curto no campo "Assunto".',
          'Descreva em "Descrição" o que está acontecendo, com o máximo de detalhe possível.',
          'Clique em "Enviar solicitação".',
          'A solicitação aparece na lista abaixo do formulário, já com a data de abertura.',
        ],
      },
      {
        titulo: 'Acompanhar as respostas',
        passos: [
          'Percorra a lista e veja o selo de situação ao lado de cada solicitação.',
          'Quando a empresa responde, o começo da resposta já aparece na própria lista.',
          'Clique na solicitação para ler a resposta completa e o texto que você enviou.',
        ],
      },
    ],
    campos: [
      { nome: 'Assunto', descricao: 'Resumo em poucas palavras, até 255 caracteres. Obrigatório.' },
      { nome: 'Descrição', descricao: 'Explicação detalhada do pedido, até 5.000 caracteres. Obrigatória.' },
      { nome: 'Situação', descricao: 'Aberta (a empresa ainda não começou), Em atendimento, Resolvida ou Cancelada.' },
    ],
    dicas: [
      'Quanto mais detalhe na descrição (local, desde quando, o que você observou), mais rápida costuma ser a resposta.',
      'Não é preciso reenviar o mesmo pedido: acompanhe a solicitação já aberta até a empresa responder.',
    ],
    atencao: [
      'Com 5 solicitações em aberto ao mesmo tempo, o formulário fica bloqueado com um aviso amarelo. Ele libera assim que uma delas for resolvida ou cancelada.',
      'A resposta depende da empresa. O portal registra o pedido e avisa a equipe, mas não define prazo de atendimento.',
    ],
    relacionados: [
      { titulo: 'Visitas', href: '/portal/visitas' },
      { titulo: 'Pendências', href: '/portal/adequacoes' },
    ],
  },

  'Portal/Solicitacoes/Show': {
    area: 'Portal do cliente',
    titulo: 'Detalhe da solicitação',
    paraQueServe: 'Mostra tudo sobre uma solicitação que você abriu: a situação atual, o texto que você enviou e a resposta da empresa.',
    comoUsar: [
      {
        titulo: 'Acompanhar o andamento',
        passos: [
          'Veja o selo colorido no alto para saber a situação atual.',
          'Logo abaixo, confira a data e a hora de abertura e, quando houver, o endereço relacionado.',
          'Leia o bloco "Descrição" para relembrar exatamente o que você pediu.',
        ],
      },
      {
        titulo: 'Ler a resposta da empresa',
        passos: [
          'Vá ao bloco "Resposta da empresa", no fim da tela.',
          'Se já houver resposta, ela aparece em destaque, com quem respondeu e quando.',
          'Se ainda não houver, o portal avisa que a empresa foi comunicada e vai responder por aqui.',
          'Clique em "Voltar" para retornar à lista de solicitações.',
        ],
      },
    ],
    atencao: [
      'Não é possível editar nem cancelar uma solicitação por esta tela. Se precisar corrigir algo, abra uma nova solicitação explicando a mudança.',
    ],
    relacionados: [
      { titulo: 'Solicitações', href: '/portal/solicitacoes' },
    ],
  },

  'Portal/Relatorios/Index': {
    area: 'Portal do cliente',
    titulo: 'Relatórios de monitoramento',
    paraQueServe: 'Lista os relatórios de monitoramento de pragas do seu endereço que a empresa já publicou, com o histórico de cada período.',
    comoUsar: [
      {
        titulo: 'Encontrar um relatório',
        passos: [
          'Percorra a lista: cada cartão mostra o período coberto e o endereço.',
          'À direita, veja a data em que o relatório foi gerado.',
          'Use os números de página no fim da tela para ver os relatórios anteriores.',
        ],
      },
      {
        titulo: 'Abrir ou baixar',
        passos: [
          'Clique no cartão para abrir o resumo do relatório na tela.',
          'Ou clique em "Baixar PDF", dentro do próprio cartão, para salvar o arquivo direto.',
        ],
      },
    ],
    atencao: [
      'Só aparecem aqui os relatórios que a empresa já publicou. Um período recente pode ainda não estar disponível.',
    ],
    relacionados: [
      { titulo: 'Visitas', href: '/portal/visitas' },
      { titulo: 'Pendências', href: '/portal/adequacoes' },
    ],
  },

  'Portal/Relatorios/Show': {
    area: 'Portal do cliente',
    titulo: 'Relatório de monitoramento',
    paraQueServe: 'Resumo do monitoramento de um período: quantas visitas houve, como as ocorrências evoluíram mês a mês, quais pontos concentram mais ocorrência e o que ficou pendente.',
    comoUsar: [
      {
        titulo: 'Ler a evolução do período',
        passos: [
          'No alto, confira o endereço, a data em que o relatório foi gerado e o total de "Visitas no período".',
          'Vá ao bloco "Evolução no período" e olhe o gráfico de barras, uma barra por mês.',
          'Use a legenda acima do gráfico: uma cor indica as capturas registradas no mês e a outra indica mês sem visita.',
          'Passe o cursor sobre uma barra para ver o número exato daquele mês.',
        ],
      },
      {
        titulo: 'Ver os pontos de atenção',
        passos: [
          'Desça até "Pontos com mais ocorrência" para ver até cinco locais do seu espaço, do mais crítico para o menos.',
          'Ao lado de cada ponto, veja a etiqueta de tendência para saber se a situação está melhorando ou piorando.',
          'Confira no fim da tela as adequações em aberto ligadas a esse relatório.',
        ],
      },
      {
        titulo: 'Guardar ou voltar',
        passos: [
          'Clique em "Baixar PDF", no topo, para salvar o relatório completo.',
          'Clique em "Voltar para relatórios" para retornar à lista.',
        ],
      },
    ],
    dicas: [
      'Quando o relatório cobre mais de um endereço, cada endereço ganha o próprio título, com gráfico e pontos separados.',
      'Meses sem visita aparecem com barra pequena de cor diferente — não confunda com mês sem nenhuma ocorrência.',
    ],
    relacionados: [
      { titulo: 'Relatórios', href: '/portal/relatorios' },
      { titulo: 'Pendências', href: '/portal/adequacoes' },
    ],
  },

  'Portal/ModuloIndisponivel': {
    area: 'Portal do cliente',
    titulo: 'Portal indisponível',
    paraQueServe: 'Aparece quando o portal não está liberado para a empresa no momento. Seu acesso continua existindo, mas as telas de visitas, documentos, pendências e faturas ficam fora do ar.',
    comoUsar: [
      {
        titulo: 'O que fazer',
        passos: [
          'Leia a mensagem no centro da tela: ela explica a situação.',
          'Entre em contato com a empresa que presta o serviço para saber quando o portal volta.',
          'Clique em "Sair" para encerrar a sessão no computador ou celular que estiver usando.',
        ],
      },
    ],
    atencao: [
      'Nada do seu histórico é apagado nesta situação: visitas, documentos e faturas voltam a aparecer assim que a empresa liberar o portal de novo.',
    ],
  },
};
