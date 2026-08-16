export default {
  _visaoGeral: {
    area: 'Começando',
    titulo: 'Como o sistema funciona',
    paraQueServe: 'Mostra o caminho completo do trabalho, do cadastro do cliente até o certificado emitido e o dinheiro registrado. Use esta página quando estiver começando ou quando precisar lembrar em que ordem cada coisa é feita.',
    comoUsar: [
      {
        titulo: 'Preparar a base de cadastros',
        passos: [
          'Abra "Configurações da Empresa" e preencha razão social, CNPJ, endereço, licenças e as imagens de logomarca e assinaturas — é isso que aparece no cabeçalho e no rodapé dos documentos.',
          'Vá em "Cadastros" e preencha os catálogos que o serviço usa: produtos, técnicos, serviços, princípios ativos, grupos químicos, antídotos, registros do Ministério da Saúde e tipos de evento.',
          'Convide as pessoas da equipe em "Convites", escolhendo o papel de cada uma.',
          'Acompanhe a barra "Primeiros passos" no Dashboard: ela marca sozinha o que já foi feito.',
        ],
      },
      {
        titulo: 'Cadastrar cliente, endereço, cômodos e dispositivos',
        passos: [
          'Cadastre o cliente em "Clientes" — todo o resto do sistema pendura nele.',
          'Cadastre o endereço do cliente: um mesmo cliente pode ter vários locais atendidos.',
          'Dentro do endereço, cadastre os cômodos, que são as áreas em que o serviço acontece.',
          'Cadastre os dispositivos instalados (porta-iscas, armadilhas e equipamentos) e diga em que cômodo cada um fica.',
        ],
      },
      {
        titulo: 'Fechar o serviço e executar',
        passos: [
          'Monte um orçamento em "Orçamentos" quando o cliente ainda estiver decidindo.',
          'Gere o contrato a partir do endereço quando o atendimento for recorrente — é o contrato que organiza as visitas previstas.',
          'Abra a ordem de serviço em "Ordens de Serviço", informando cliente, endereço, serviços, produtos e técnico responsável.',
          'Registre a execução e conclua a ordem de serviço quando o atendimento terminar.',
        ],
      },
      {
        titulo: 'Emitir o certificado e registrar o financeiro',
        passos: [
          'Emita o certificado em "Certificados", vinculado ao cliente e ao serviço executado.',
          'Confira o PDF gerado antes de entregar: ele é o documento que a fiscalização pede.',
          'Registre o recebimento no financeiro, que acompanha o pagamento da ordem de serviço.',
          'Volte ao Dashboard para conferir os totais e as últimas ordens e certificados.',
        ],
      },
    ],
    dicas: [
      'A ordem importa: sem endereço não há cômodo, sem cômodo não há dispositivo, e sem ordem de serviço concluída o certificado fica sem lastro.',
      'Cada pessoa vê apenas as áreas liberadas para o papel dela, então itens do menu podem não aparecer para todo mundo.',
      'Recursos que dependem do plano contratado aparecem como "Módulo indisponível" em vez de sumirem sem explicação.',
    ],
    atencao: [
      'Certificado, ordem de serviço, contrato e recibo têm valor perante fiscalização: confira o PDF sempre que mudar dados da empresa, assinaturas ou textos legais.',
      'Tudo que você cadastra pertence à sua empresa e não é visto por outras empresas do sistema.',
    ],
    relacionados: [
      { titulo: 'Clientes', href: '/clients' },
      { titulo: 'Cadastros', href: '/cadastros' },
      { titulo: 'Dados da empresa', href: '/settings/company' },
    ],
  },

  Dashboard: {
    area: 'Geral',
    titulo: 'Dashboard',
    paraQueServe: 'É a tela inicial do sistema: mostra os totais da empresa, atalhos para as tarefas mais comuns e as últimas ordens de serviço e certificados. Use para saber, em poucos segundos, como está o movimento e o que ficou pendente.',
    comoUsar: [
      {
        titulo: 'Ler o resumo da empresa',
        passos: [
          'Confira os cartões de totais: "Total de Clientes", "Total de Produtos", "Total de Técnicos", "Total de Serviços", "Ordens de Serviço" e "Total de Certificados".',
          'Clique em qualquer cartão para abrir a listagem completa daquele assunto.',
          'Role até "Últimas Ordens de Serviço" e "Últimos Certificados" para ver os registros mais recentes com a situação de cada um.',
          'Clique no ícone de olho ao lado de um registro para abrir os detalhes dele.',
        ],
      },
      {
        titulo: 'Concluir a trilha de primeiros passos',
        passos: [
          'Veja o bloco "Primeiros passos", no topo, com a barra de progresso e a contagem de itens concluídos.',
          'Clique em "Ir para lá" no passo que quiser resolver — o sistema leva você direto à tela certa.',
          'Clique em "Dispensar" se aquele passo não fizer sentido para a sua empresa.',
          'Clique em "Trazer de volta" para reativar um passo dispensado.',
          'Use a seta no canto do bloco para recolher a trilha sem alterar o progresso.',
        ],
      },
      {
        titulo: 'Começar uma tarefa pelas Ações Rápidas',
        passos: [
          'Localize o bloco "Ações Rápidas".',
          'Clique em "Novo Cliente" para cadastrar um cliente do zero.',
          'Clique em "Novo Produto" para incluir um produto no catálogo.',
          'Clique em "Nova Ordem" para abrir uma ordem de serviço.',
          'Clique em "Novo Certificado" para emitir um certificado.',
        ],
      },
      {
        titulo: 'Acompanhar a conformidade',
        passos: [
          'Confira o bloco "Conformidade RDC 622/2022", quando ele aparecer, com a contagem de itens irregulares, em atenção e regulares.',
          'Veja a data em que a verificação foi feita, logo abaixo dos números.',
          'Clique em "Ver checklist" para abrir a lista item a item e descobrir o que está vencido.',
          'Corrija as validades da empresa na tela de validades dos documentos e volte para conferir o resultado.',
        ],
      },
    ],
    campos: [
      { nome: 'Primeiros passos', descricao: 'Trilha de configuração inicial da empresa. Some da tela quando todos os passos estiverem concluídos ou dispensados.' },
      { nome: 'Situação nas listas recentes', descricao: 'Etiqueta colorida com o estado do registro, como Pendente, Em Andamento, Concluída, Cancelada, Rascunho, Emitido ou Expirado.' },
      { nome: 'Conformidade RDC 622/2022', descricao: 'Resumo do checklist de licenças e registros. Só aparece quando o recurso está no seu plano e você tem permissão para vê-lo.' },
    ],
    dicas: [
      'Se um cartão mostrar zero, é porque aquele cadastro ainda não foi feito — clique nele para começar.',
      '"Recolher" a trilha é só visual e vale apenas no seu navegador; "Dispensar" muda o passo para todo mundo da empresa.',
    ],
    atencao: [
      'O checklist informa apenas o que o sistema consegue verificar; ele não atesta conformidade perante a fiscalização.',
      'Quando a verificação de conformidade nunca rodou, o bloco não aparece — a ausência dele não significa que está tudo regular.',
    ],
    relacionados: [
      { titulo: 'Ordens de serviço', href: '/work-orders' },
      { titulo: 'Certificados', href: '/certificates' },
      { titulo: 'Cadastros', href: '/cadastros' },
    ],
  },

  'Cadastros/Index': {
    area: 'Geral',
    titulo: 'Cadastros',
    paraQueServe: 'Reúne em uma única tela os catálogos que alimentam ordens de serviço e certificados. Use como porta de entrada para produtos, técnicos, serviços e os cadastros técnicos exigidos nos documentos.',
    comoUsar: [
      {
        titulo: 'Abrir um cadastro',
        passos: [
          'Localize o cartão do cadastro desejado no bloco "Acesse os Cadastros".',
          'Confira a etiqueta com a quantidade de itens já cadastrados naquele cartão.',
          'Clique no cartão para abrir a listagem completa.',
          'Cadastre, edite ou exclua os itens dentro da tela que abrir e use o voltar do navegador para retornar.',
        ],
      },
      {
        titulo: 'Preparar os cadastros antes do primeiro serviço',
        passos: [
          'Comece por "Serviços", que descreve o que a sua empresa executa.',
          'Cadastre os "Produtos" que serão aplicados em campo.',
          'Cadastre os "Técnicos" que assinam e executam os atendimentos.',
          'Complete "Princípio Ativo", "Grupo Químico", "Antídoto" e "Reg. Min da Saúde", que são os dados técnicos impressos nos documentos.',
          'Cadastre "Tipos de Evento" para padronizar o que os técnicos registram nas visitas.',
        ],
      },
    ],
    campos: [
      { nome: 'Produtos', descricao: 'Produtos e materiais aplicados nos atendimentos.' },
      { nome: 'Técnicos', descricao: 'Técnicos e especialistas da empresa. É a lista usada para vincular um usuário a um técnico.' },
      { nome: 'Serviços', descricao: 'Serviços disponíveis para venda e execução.' },
      { nome: 'Princípio Ativo, Grupo Químico e Antídoto', descricao: 'Informações químicas dos produtos, usadas nos documentos técnicos.' },
      { nome: 'Reg. Min da Saúde', descricao: 'Registros do órgão para os produtos utilizados.' },
      { nome: 'Dispositivos', descricao: 'Dispositivos e equipamentos instalados nos endereços dos clientes.' },
      { nome: 'Tipos de Evento', descricao: 'Tipos de ocorrência que o técnico registra durante a visita.' },
    ],
    dicas: [
      'O contador de cada cartão é a forma mais rápida de descobrir qual catálogo ainda está vazio.',
      'Catálogo incompleto trava o preenchimento da ordem de serviço mais adiante — vale resolver antes de abrir o primeiro atendimento.',
    ],
    relacionados: [
      { titulo: 'Dispositivos', href: '/devices' },
      { titulo: 'Dashboard', href: '/dashboard' },
    ],
  },

  ContaSuspensa: {
    area: 'Geral',
    titulo: 'Conta suspensa',
    paraQueServe: 'Explica por que o acesso ao sistema foi bloqueado por falta de pagamento e mostra a fatura em aberto com as formas de pagar. Aparece automaticamente quando a empresa está suspensa.',
    comoUsar: [
      {
        titulo: 'Pagar a fatura em aberto',
        passos: [
          'Confira a referência, o valor e a data de vencimento no quadro da fatura.',
          'Clique em "Pagar agora" para abrir o link de pagamento em uma nova aba, quando ele existir.',
          'Se a cobrança for boleto, clique em "Copiar" ao lado da linha digitável e cole no aplicativo do banco.',
          'Se a cobrança for Pix, clique em "Copiar" ao lado do código copia e cola e use a opção Pix copia e cola do banco.',
          'Aguarde a confirmação do pagamento: o acesso volta assim que ela for registrada.',
        ],
      },
      {
        titulo: 'Consultar o histórico e sair',
        passos: [
          'Clique em "Ver minhas faturas" para abrir a tela de assinatura com as faturas da empresa.',
          'Clique em "Sair" para encerrar a sessão e entrar com outro usuário.',
          'Quando a conta estiver regular, a tela mostra "Sua conta está ativa" e o botão "Voltar ao início" leva ao Dashboard.',
        ],
      },
    ],
    campos: [
      { nome: 'Linha digitável do boleto', descricao: 'Código numérico do boleto. Aparece apenas em fatura cobrada por boleto.' },
      { nome: 'Pix copia e cola', descricao: 'Código do Pix para colar no aplicativo do banco. Aparece apenas em fatura cobrada por Pix.' },
    ],
    dicas: [
      'O botão de copiar troca o texto para "Copiado!" por alguns segundos, confirmando que o código foi para a área de transferência.',
      'Se o navegador não deixar copiar, selecione o código na tela e copie manualmente.',
    ],
    atencao: [
      'Nenhum dado é apagado durante a suspensão: clientes, ordens de serviço, certificados e financeiro continuam íntegros.',
      'Quando a fatura ainda não tem código de pagamento emitido, ou quando não existe fatura em aberto, é preciso falar com o suporte para regularizar o acesso.',
    ],
    relacionados: [
      { titulo: 'Minhas faturas', href: '/assinatura' },
      { titulo: 'Dashboard', href: '/dashboard' },
    ],
  },

  ModuloIndisponivel: {
    area: 'Geral',
    titulo: 'Módulo indisponível',
    paraQueServe: 'Aparece quando você tenta abrir um recurso que não faz parte do plano contratado pela sua empresa. A tela nomeia o módulo bloqueado e explica o que fazer para liberá-lo.',
    comoUsar: [
      {
        titulo: 'Entender o bloqueio',
        passos: [
          'Leia o nome e a descrição do módulo no topo da tela para saber exatamente qual recurso está fora do plano.',
          'Confirme que não houve perda de dados: o bloqueio é apenas de acesso ao recurso.',
          'Clique em "Voltar ao início" para retornar ao Dashboard e seguir com o trabalho nas áreas liberadas.',
        ],
      },
      {
        titulo: 'Liberar o módulo',
        passos: [
          'Anote o nome do módulo mostrado na tela.',
          'Fale com o seu consultor comercial ou com o suporte para conhecer os planos que incluem esse módulo.',
          'Depois da ampliação do plano, abra o sistema novamente: o recurso volta a aparecer no menu.',
        ],
      },
    ],
    dicas: [
      'Módulos como financeiro, contratos, estoque, portal do cliente, notificações e conformidade dependem do plano; clientes, ordens de serviço e certificados estão sempre disponíveis.',
    ],
    atencao: [
      'Se o módulo já esteve ativo, os dados criados nele continuam guardados e voltam a aparecer quando o plano for ampliado.',
    ],
    relacionados: [
      { titulo: 'Minhas faturas', href: '/assinatura' },
      { titulo: 'Dashboard', href: '/dashboard' },
    ],
  },
};
