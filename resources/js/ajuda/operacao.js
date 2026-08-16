/**
 * Manual de uso da área de Operação: ordens de serviço, certificados,
 * agenda, roteiros, monitoramento e conformidade.
 *
 * A chave de cada entrada é o nome do componente Inertia da tela.
 */
export default {
  'WorkOrders/Index': {
    area: 'Operação',
    titulo: 'Ordens de serviço',
    paraQueServe: 'Lista todas as ordens de serviço da empresa e é o ponto de partida da operação: aqui você abre uma OS nova, encontra uma OS já criada e chega na tela de execução dela.',
    comoUsar: [
      {
        titulo: 'Abrir uma ordem de serviço',
        passos: [
          'Clique em "Nova Ordem" no topo da tela.',
          'Preencha cliente, endereço, técnicos, serviço e data agendada.',
          'Clique em "Salvar Ordem de Serviço".',
          'A OS volta para esta lista com o número gerado e a situação escolhida.',
        ],
      },
      {
        titulo: 'Encontrar uma ordem específica',
        passos: [
          'Use os filtros de cliente, endereço, técnico, status, prioridade e serviço.',
          'Informe "Data (De)" e "Data (Até)" para limitar o período.',
          'Clique em "Aplicar Filtros".',
          'Clique em "Limpar Filtros" para voltar à lista completa.',
        ],
      },
      {
        titulo: 'Continuar o atendimento de uma OS',
        passos: [
          'Clique em "Ver Detalhes" no cartão da ordem.',
          'Na tela de detalhes, registre produtos, técnicos, cômodos, dispositivos e adequações.',
          'Use "Editar" quando precisar só corrigir data, horário, serviço ou situação.',
        ],
      },
      {
        titulo: 'Emitir nota fiscal de serviço',
        passos: [
          'Marque a caixa de seleção das ordens que vão para a nota.',
          'Clique em "Emitir NFS-e" na barra verde que aparece acima da lista.',
          'Confira a quantidade de ordens e clique em "Emitir NFS-e" no aviso.',
          'Leia o resultado item a item e clique em "Fechar".',
        ],
      },
    ],
    campos: [
      { nome: 'Status', descricao: 'Situação da OS: Pendente, Agendada, Em Andamento, Concluída, Cancelada ou Em Espera.' },
      { nome: 'Prioridade', descricao: 'Baixa, Média, Alta, Urgente ou Emergência. Serve para ordenar o esforço da equipe, não altera o fluxo.' },
    ],
    dicas: [
      'A seleção para NFS-e e o botão "Emitir NFS-e" só aparecem para quem tem permissão de emissão fiscal e com o módulo de nota fiscal ligado.',
      'O cartão de cada ordem já mostra cliente, endereço, data, técnico, descrição e observações, sem precisar abrir.',
    ],
    atencao: [
      'Excluir uma ordem de serviço apaga o registro da execução, e a exclusão não pode ser desfeita. Se o serviço não vai mais acontecer, prefira mudar a situação para Cancelada.',
      'A nota fiscal é um documento com efeito legal: confira cliente e valores antes de confirmar a emissão.',
    ],
    relacionados: [
      { titulo: 'Certificados', href: '/certificates' },
      { titulo: 'Agenda', href: '/agenda' },
      { titulo: 'Roteiro do dia', href: '/roteiros/painel' },
    ],
  },

  'WorkOrders/Create': {
    area: 'Operação',
    titulo: 'Nova ordem de serviço',
    paraQueServe: 'Cria a ordem de serviço que abre o atendimento: define quem é o cliente, onde é o serviço, quem executa, quando e o que será feito. É o primeiro passo do fluxo ordem de serviço, execução e certificado.',
    comoUsar: [
      {
        titulo: 'Preencher os dados básicos',
        passos: [
          'Busque e selecione o cliente no campo "Cliente".',
          'Escolha o "Endereço" entre os endereços cadastrados desse cliente.',
          'Selecione o técnico e, se for preciso, clique em "+ Adicionar Técnico" para incluir outros.',
          'Escolha "Nível de Prioridade", "Data Agendada", "Serviço" e "Status".',
          'Preencha "Horário de Início" e "Horário de Término" quando já houver hora marcada.',
        ],
      },
      {
        titulo: 'Registrar produtos que serão usados',
        passos: [
          'Na seção "Produtos Utilizados", selecione o produto.',
          'Informe a quantidade e a unidade de medida.',
          'Escreva a observação de aplicação, quando houver.',
          'Clique em "+ Adicionar Produto" para incluir outro produto.',
        ],
      },
      {
        titulo: 'Informar cômodos e dispositivos',
        passos: [
          'Na aba "Cômodos", clique em "+ Adicionar Cômodo" e escolha o cômodo.',
          'Clique em "Adicionar Evento", escolha o tipo e a data do evento e clique em "Salvar Evento".',
          'Se houve praga avistada, clique em "Adicionar Avistamento de Praga" e clique em "Salvar Avistamento".',
          'Na aba "Dispositivos", clique em "+ Adicionar Dispositivo" e depois em "Adicionar Evento ao Dispositivo".',
        ],
      },
      {
        titulo: 'Salvar a ordem',
        passos: [
          'Confira se cada cômodo selecionado tem evento com tipo e data preenchidos.',
          'Clique em "Salvar Ordem de Serviço".',
          'Se aparecer o aviso "Campos Obrigatórios Não Preenchidos", complete os eventos apontados e salve de novo.',
        ],
      },
    ],
    campos: [
      { nome: 'Endereço', descricao: 'Fica bloqueado até você escolher o cliente, porque a lista mostra apenas os endereços dele.' },
      { nome: 'Ordem de serviço ativa', descricao: 'Marcado por padrão. Desmarque para deixar a OS registrada sem entrar na rotina do dia a dia.' },
    ],
    dicas: [
      'A aba "Dispositivos" só libera a inclusão depois que o endereço estiver escolhido.',
      'Cômodo, dispositivo, técnico e produto já escolhidos somem das listas seguintes, então não há risco de repetir o mesmo item.',
    ],
    atencao: [
      'Cliente e endereço não podem ser trocados depois que a ordem for salva. Se errar, será preciso criar outra ordem.',
      'Cômodo selecionado sem evento trava o salvamento: o evento é o registro do que foi feito no local.',
    ],
    relacionados: [
      { titulo: 'Ordens de serviço', href: '/work-orders' },
      { titulo: 'Clientes', href: '/clients' },
    ],
  },

  'WorkOrders/Edit': {
    area: 'Operação',
    titulo: 'Editar ordem de serviço',
    paraQueServe: 'Corrige os dados de agendamento e de situação de uma ordem já criada: prioridade, data, horários, serviço, status, descrição e observações.',
    comoUsar: [
      {
        titulo: 'Ajustar o agendamento',
        passos: [
          'Altere "Nível de Prioridade" e "Data Agendada" conforme a nova combinação com o cliente.',
          'Preencha "Horário de Início" quando a equipe começar o atendimento.',
          'Clique em "Salvar Alterações".',
        ],
      },
      {
        titulo: 'Fechar a execução',
        passos: [
          'Preencha "Horário de Término" com o horário real de encerramento.',
          'Confira que o campo "Status" passou sozinho para "Concluída".',
          'Complete a "Descrição" e as "Observações" com o que foi feito.',
          'Clique em "Salvar Alterações".',
        ],
      },
    ],
    campos: [
      { nome: 'Cliente e Endereço', descricao: 'Aparecem apenas para leitura: não podem ser alterados depois que a ordem foi criada.' },
      { nome: 'Status', descricao: 'Muda sozinho para "Concluída" quando você informa o horário de término, e volta para "Em Andamento" ou "Agendada" se você apagar esse horário.' },
    ],
    dicas: [
      'Para registrar produtos, técnicos, cômodos, dispositivos, fotos e adequações, use a tela de detalhes da ordem, não esta.',
    ],
    atencao: [
      'Concluir a ordem é o que autoriza a emissão do certificado do serviço: confira os dados antes de fechar.',
    ],
    relacionados: [
      { titulo: 'Ordens de serviço', href: '/work-orders' },
    ],
  },

  'WorkOrders/Show': {
    area: 'Operação',
    titulo: 'Detalhes da ordem de serviço',
    paraQueServe: 'Reúne tudo o que aconteceu no atendimento, organizado em abas, e é de onde saem os documentos da OS: o PDF da ordem, o recibo e o certificado do serviço.',
    comoUsar: [
      {
        titulo: 'Registrar o que foi executado',
        passos: [
          'Abra a aba "Produtos" e clique em "Adicionar Produto" para lançar o que foi aplicado, com quantidade e unidade.',
          'Na aba "Técnicos", clique em "Adicionar Técnico" para incluir quem participou.',
          'Na aba "Cômodos Atendidos", clique em "Adicionar Cômodo" e registre o evento realizado em cada um.',
          'Na aba "Dispositivos", clique em "Adicionar Evento" e anexe fotos com "Adicionar Foto".',
          'Na aba "Adequações", clique em "Adicionar Adequação" para registrar o que o cliente precisa corrigir e o prazo.',
        ],
      },
      {
        titulo: 'Emitir o certificado do serviço',
        passos: [
          'Clique em "Certificado" na barra de ações do rodapé.',
          'Confira os dados da OS e a lista de produtos e serviços mostrada à esquerda.',
          'Preencha "Data da Execução" e "Procedimento Utilizado", que são obrigatórios.',
          'Informe a "Data da Garantia" e as "Observações", se houver.',
          'Clique em "Emitir Certificado" — o sistema leva você para a lista de certificados.',
        ],
      },
      {
        titulo: 'Gerar os documentos da ordem',
        passos: [
          'Clique em "PDF" para abrir a ordem de serviço em uma nova aba, pronta para imprimir.',
          'Clique em "Recibo" quando o botão aparecer, para abrir o recibo do pagamento.',
          'Use a aba "Informações Financeiras" e "Editar Informações" para acertar valores e pagamentos.',
        ],
      },
      {
        titulo: 'Conferir a conformidade antes de uma fiscalização',
        passos: [
          'Leia o bloco "Conformidade da execução" no topo, quando ele aparecer.',
          'Trate cada item marcado como "Falta documentar" nas abas correspondentes.',
          'Confira também os itens de "Aviso de registro", que apontam produto com registro vencido na data.',
        ],
      },
    ],
    campos: [
      { nome: 'Abas', descricao: 'Informações Financeiras, Detalhes da Ordem, Produtos, Técnicos, Cômodos Atendidos, Dispositivos e Adequações. O número ao lado do nome mostra quantos itens já foram registrados.' },
      { nome: 'Conformidade da execução', descricao: 'Bloco informativo: lista o que falta na documentação da execução. Ele nunca impede concluir a ordem nem emitir documento.' },
    ],
    dicas: [
      'O certificado emitido a partir da OS já herda os produtos e o serviço registrados na ordem, sem precisar digitar de novo.',
      'O botão "Recibo" só aparece quando a ordem está marcada como paga.',
      'O histórico de alterações no fim da página mostra quem mudou o quê e quando.',
    ],
    atencao: [
      'Certificado é documento com valor perante fiscalização. Confira data da execução, procedimento e garantia antes de clicar em "Emitir Certificado".',
      'Ordem cancelada não aceita novas fotos, adequações nem alterações de execução.',
    ],
    relacionados: [
      { titulo: 'Certificados', href: '/certificates' },
      { titulo: 'Ordens de serviço', href: '/work-orders' },
      { titulo: 'Pendências de execução', href: '/conformidade/pendencias-de-execucao' },
    ],
  },

  'ServiceOrders/Index': {
    area: 'Operação',
    titulo: 'Ordens de serviço agendadas',
    paraQueServe: 'Lista o registro simplificado de serviço contratado por cliente, com serviço, situação e cômodos atendidos. É um cadastro mais leve que a ordem de serviço completa, usado para acompanhar o combinado com o cliente.',
    comoUsar: [
      {
        titulo: 'Criar um registro',
        passos: [
          'Clique em "Nova Ordem" no topo da tela.',
          'Escolha o cliente e o serviço.',
          'Defina status, prioridade e datas.',
          'Clique em "Criar Ordem de Serviço".',
        ],
      },
      {
        titulo: 'Localizar uma ordem',
        passos: [
          'Digite pelo menos 2 caracteres no campo de busca.',
          'Busque por número, cliente ou serviço.',
          'Use o botão de limpar dentro do campo para voltar à lista completa.',
        ],
      },
      {
        titulo: 'Consultar ou alterar',
        passos: [
          'Clique no ícone de detalhes para ver a ordem completa.',
          'Clique no ícone de edição para corrigir os dados.',
          'Clique no ícone de exclusão e confirme para remover o registro.',
        ],
      },
    ],
    campos: [
      { nome: 'Ordem', descricao: 'Mostra o número interno e o código no formato OS-000000.' },
      { nome: 'Status', descricao: 'Pendente, Em Andamento, Concluída ou Cancelada.' },
    ],
    atencao: [
      'A exclusão remove o registro e não pode ser desfeita.',
    ],
    relacionados: [
      { titulo: 'Ordens de serviço', href: '/work-orders' },
      { titulo: 'Clientes', href: '/clients' },
    ],
  },

  'ServiceOrders/Create': {
    area: 'Operação',
    titulo: 'Nova ordem de serviço agendada',
    paraQueServe: 'Registra o serviço combinado com um cliente: qual serviço, em que situação está, com que prioridade, em que prazo e quais cômodos serão atendidos.',
    comoUsar: [
      {
        titulo: 'Escolher cliente e serviço',
        passos: [
          'Busque o cliente em "Cliente".',
          'Selecione o serviço em "Serviço" — o preço cadastrado aparece ao lado do nome.',
          'Aguarde a seção "Cômodos Atendidos" carregar, quando o cliente tiver cômodos cadastrados.',
        ],
      },
      {
        titulo: 'Definir prazo e situação',
        passos: [
          'Escolha o "Status" da ordem.',
          'Escolha a "Prioridade".',
          'Preencha "Data de Início" e "Data de Conclusão Prevista".',
          'Use "Observações" para o que precisa ficar registrado.',
        ],
      },
      {
        titulo: 'Marcar os cômodos atendidos',
        passos: [
          'Na seção "Cômodos Atendidos", marque a caixa de cada cômodo que entra no serviço.',
          'Escreva a observação do atendimento no campo que abre abaixo do cômodo marcado.',
          'Clique em "Criar Ordem de Serviço".',
        ],
      },
    ],
    dicas: [
      'A lista de cômodos só é carregada depois que o cliente é escolhido. Cliente sem cômodo cadastrado não mostra essa seção.',
    ],
    relacionados: [
      { titulo: 'Ordens de serviço agendadas', href: '/service-orders' },
    ],
  },

  'ServiceOrders/Edit': {
    area: 'Operação',
    titulo: 'Editar ordem de serviço agendada',
    paraQueServe: 'Corrige os dados de um registro de serviço já criado, inclusive o cliente, o serviço, as datas e os cômodos atendidos.',
    comoUsar: [
      {
        titulo: 'Alterar os dados da ordem',
        passos: [
          'Ajuste "Cliente" e "Serviço" nas listas correspondentes.',
          'Revise "Status", "Prioridade", "Data de Início" e "Data de Conclusão Prevista".',
          'Atualize as "Observações".',
          'Clique em "Salvar Alterações".',
        ],
      },
      {
        titulo: 'Revisar os cômodos',
        passos: [
          'Marque ou desmarque cômodos na seção "Cômodos Atendidos".',
          'Atualize a observação de atendimento de cada cômodo marcado.',
          'Clique em "Salvar Alterações".',
        ],
      },
    ],
    atencao: [
      'Trocar o cliente limpa os cômodos já marcados, porque os cômodos pertencem ao cliente anterior.',
    ],
    relacionados: [
      { titulo: 'Ordens de serviço agendadas', href: '/service-orders' },
    ],
  },

  'ServiceOrders/Show': {
    area: 'Operação',
    titulo: 'Detalhes da ordem de serviço agendada',
    paraQueServe: 'Mostra em uma página só os dados do cliente, do serviço, da situação e dos cômodos atendidos do registro, e permite gerar o PDF dele.',
    comoUsar: [
      {
        titulo: 'Conferir a ordem',
        passos: [
          'Leia os blocos "Informações do Cliente", "Informações do Serviço" e "Informações da Ordem".',
          'Confira os cômodos e as observações no bloco "Cômodos Atendidos".',
          'Veja data de criação e última atualização em "Informações do Sistema".',
        ],
      },
      {
        titulo: 'Gerar o documento ou corrigir dados',
        passos: [
          'Clique em "Gerar PDF" para abrir o documento em outra aba.',
          'Clique em "Editar Ordem" para corrigir qualquer informação.',
          'Clique em "Voltar à Lista" para retomar a fila de ordens.',
        ],
      },
    ],
    relacionados: [
      { titulo: 'Ordens de serviço agendadas', href: '/service-orders' },
    ],
  },

  'Certificates/Index': {
    area: 'Operação',
    titulo: 'Certificados',
    paraQueServe: 'Lista todos os certificados emitidos pela empresa e é onde você acompanha a validade da garantia e baixa o PDF que o cliente arquiva.',
    comoUsar: [
      {
        titulo: 'Encontrar um certificado',
        passos: [
          'Digite pelo menos 2 caracteres no campo de busca.',
          'Busque por número, cliente ou data de execução.',
          'Confira a coluna "Data da Garantia" para saber até quando ele vale.',
        ],
      },
      {
        titulo: 'Entregar o certificado ao cliente',
        passos: [
          'Clique no ícone de detalhes para conferir o conteúdo antes de enviar.',
          'Volte à lista e clique no ícone de PDF na linha do certificado.',
          'O documento abre em outra aba, pronto para imprimir ou enviar.',
        ],
      },
      {
        titulo: 'Emitir um certificado avulso',
        passos: [
          'Clique em "Novo Certificado" no topo da tela.',
          'Preencha cliente, endereço, produtos, serviço e procedimento.',
          'Clique em "Criar Certificado".',
        ],
      },
    ],
    campos: [
      { nome: 'Status', descricao: 'Ativo enquanto a garantia não venceu, Vencido depois da data de garantia e Cancelado quando o certificado foi cancelado. Certificado sem data de garantia fica sempre Ativo.' },
    ],
    dicas: [
      'O caminho normal é emitir o certificado pela tela de detalhes da ordem de serviço: assim ele já nasce ligado à OS, com os produtos e o serviço executados.',
    ],
    atencao: [
      'Certificado é documento com valor perante fiscalização. Excluir apaga o registro do que foi entregue ao cliente, e a exclusão não pode ser desfeita.',
    ],
    relacionados: [
      { titulo: 'Ordens de serviço', href: '/work-orders' },
    ],
  },

  'Certificates/Create': {
    area: 'Operação',
    titulo: 'Novo certificado',
    paraQueServe: 'Emite um certificado avulso, sem partir de uma ordem de serviço. Use quando o serviço não tem OS registrada no sistema; nos demais casos, emita o certificado pela tela de detalhes da ordem.',
    comoUsar: [
      {
        titulo: 'Informar cliente e datas',
        passos: [
          'Busque o cliente em "Cliente".',
          'Escolha o "Endereço do Cliente" onde o serviço foi feito.',
          'Preencha a "Data da Execução".',
          'Preencha a "Garantia" com a data até quando o certificado vale.',
        ],
      },
      {
        titulo: 'Registrar produtos e serviço',
        passos: [
          'Clique em "Adicionar Produto" e escolha o produto aplicado.',
          'Informe quantidade e unidade de medida.',
          'Repita para cada produto usado.',
          'Selecione o serviço em "Selecione o Serviço".',
        ],
      },
      {
        titulo: 'Descrever e emitir',
        passos: [
          'Escreva o "Procedimento Utilizado" com detalhe: é o texto que sai impresso.',
          'Use "Observações" para o que mais precisa constar.',
          'Clique em "Criar Certificado".',
        ],
      },
    ],
    campos: [
      { nome: 'Garantia', descricao: 'Data que define até quando o certificado fica com status Ativo. Sem essa data, ele nunca aparece como vencido.' },
      { nome: 'Procedimento Utilizado', descricao: 'Campo obrigatório. É a descrição técnica do que foi feito e aparece no documento entregue ao cliente.' },
    ],
    dicas: [
      'O campo de endereço só libera depois de escolher o cliente.',
      'Se você repetir o mesmo produto, o sistema avisa "Este produto já foi adicionado" e não deixa salvar até remover a repetição.',
    ],
    atencao: [
      'O certificado tem valor perante fiscalização: confira produtos, datas e o texto do procedimento antes de criar.',
    ],
    relacionados: [
      { titulo: 'Certificados', href: '/certificates' },
    ],
  },

  'Certificates/Edit': {
    area: 'Operação',
    titulo: 'Editar certificado',
    paraQueServe: 'Corrige um certificado já emitido: cliente, datas, produtos, serviço, procedimento e o vínculo com a ordem de serviço que originou o documento.',
    comoUsar: [
      {
        titulo: 'Corrigir os dados principais',
        passos: [
          'Revise "Cliente", "Data da Execução" e "Garantia".',
          'Escolha o "Endereço" quando o certificado não estiver ligado a uma ordem de serviço.',
          'Use "Ordem de Serviço" para vincular o certificado à OS correspondente.',
        ],
      },
      {
        titulo: 'Ajustar produtos e texto',
        passos: [
          'Clique em "Adicionar Produto" ou remova os que não foram usados.',
          'Confira quantidade e unidade de cada produto.',
          'Atualize o "Procedimento Utilizado" e as "Observações".',
          'Clique em "Salvar Alterações".',
        ],
      },
    ],
    dicas: [
      'Ao escolher uma ordem de serviço, o campo de endereço deixa de aparecer: o endereço passa a vir da própria OS.',
    ],
    atencao: [
      'Alterar um certificado muda um documento que pode já ter sido entregue ao cliente e apresentado à fiscalização. Corrija apenas o que estiver de fato errado, e reemita a via do cliente depois.',
    ],
    relacionados: [
      { titulo: 'Certificados', href: '/certificates' },
    ],
  },

  'Certificates/Show': {
    area: 'Operação',
    titulo: 'Detalhes do certificado',
    paraQueServe: 'Mostra o conteúdo completo do certificado emitido, inclusive princípio ativo, grupo químico e antídoto de cada produto, e é de onde sai o PDF entregue ao cliente.',
    comoUsar: [
      {
        titulo: 'Conferir antes de entregar',
        passos: [
          'Confira cliente, serviço e os dados de cada produto aplicado.',
          'Verifique "Data da Execução", "Data da Garantia" e o status do certificado.',
          'Leia o "Procedimento Utilizado" como ele vai sair impresso.',
        ],
      },
      {
        titulo: 'Entregar ou corrigir',
        passos: [
          'Clique em "Baixar PDF" para abrir o documento em outra aba.',
          'Clique em "Editar Certificado" se algum dado precisar de correção.',
          'Consulte o histórico de alterações no fim da página para saber o que já foi mudado.',
        ],
      },
    ],
    campos: [
      { nome: 'Status', descricao: 'ATIVO enquanto a garantia estiver em dia e VENCIDO depois da data de garantia.' },
    ],
    atencao: [
      'O PDF é o exemplar que o cliente arquiva e que a fiscalização compara. Confira o conteúdo antes de enviar.',
    ],
    relacionados: [
      { titulo: 'Certificados', href: '/certificates' },
    ],
  },

  'Agenda/Index': {
    area: 'Operação',
    titulo: 'Agenda',
    paraQueServe: 'Calendário das visitas e compromissos da empresa. É onde se distribui o trabalho do dia: atribuir técnico, reagendar uma ordem de serviço e acompanhar a carga de cada profissional.',
    comoUsar: [
      {
        titulo: 'Navegar e filtrar',
        passos: [
          'Escolha a visão "Dia", "Semana" ou "Mês".',
          'Use os filtros de técnico, tipo de serviço, situação e cidade.',
          'Selecione "Sem técnico" no filtro de técnico para achar o que ainda não foi distribuído.',
          'Clique em "Limpar filtros" para ver tudo de novo.',
        ],
      },
      {
        titulo: 'Atribuir técnico e reagendar',
        passos: [
          'Clique na visita no calendário para abrir o painel lateral.',
          'Escolha o profissional em "Atribuir técnico" — a marca "(aviso)" indica quem já tem outra visita no horário.',
          'Em "Reagendar", informe a nova data agendada e os horários de início e término.',
          'Clique em "Reagendar".',
          'Clique em "Abrir ordem de serviço completa" quando precisar do detalhe da OS.',
        ],
      },
      {
        titulo: 'Criar e resolver um compromisso avulso',
        passos: [
          'Clique em "Novo compromisso" e preencha os dados do compromisso.',
          'Clique no compromisso no calendário para abrir o painel lateral.',
          'Clique em "Concluir" quando o compromisso for cumprido.',
          'Clique em "Promover para OS" para transformá-lo em ordem de serviço, escolhendo antes o serviço em "Promover para".',
          'Clique em "Cancelar" e confirme em "Sim, cancelar" quando o compromisso não for mais acontecer.',
        ],
      },
      {
        titulo: 'Equilibrar a carga da equipe',
        passos: [
          'Abra o painel de carga por técnico no rodapé da agenda.',
          'Clique na linha de um técnico para filtrar o calendário só com as visitas dele.',
          'Redistribua as visitas usando o painel lateral de cada uma.',
        ],
      },
    ],
    campos: [
      { nome: 'Situação', descricao: 'Filtro com várias marcações ao mesmo tempo: Pendente, Agendada, Em Andamento, Concluída, Cancelada e Em Espera.' },
      { nome: 'Compromisso', descricao: 'Item da agenda que não é ordem de serviço — reunião, visita comercial, orçamento. Aparece com destaque próprio no calendário.' },
    ],
    dicas: [
      'A visão, a data e os filtros ficam gravados no endereço da página: dá para copiar o link e mandar para outra pessoa já filtrado.',
      'Um compromisso já promovido não pode ser promovido de novo — o botão fica bloqueado.',
    ],
    atencao: [
      'Reagendar e trocar o técnico altera a ordem de serviço na hora, inclusive para o técnico que já a viu no celular. Avise a equipe.',
      'Cancelar um compromisso não apaga o registro: ele continua no histórico, só muda de situação.',
    ],
    relacionados: [
      { titulo: 'Ordens de serviço', href: '/work-orders' },
      { titulo: 'Roteiro do dia', href: '/roteiros/painel' },
      { titulo: 'Agendamentos', href: '/solicitacoes-de-horario' },
    ],
  },

  'Agenda/MeuDia': {
    area: 'Operação',
    titulo: 'Meu dia',
    paraQueServe: 'Lista as ordens de serviço agendadas para você em um dia, em ordem de horário. É a tela de campo do técnico, pensada para uso no celular.',
    comoUsar: [
      {
        titulo: 'Ver a agenda do dia',
        passos: [
          'Confira a data em destaque no topo da tela.',
          'Use as setas laterais para ir ao dia anterior ou ao próximo dia.',
          'Clique em "Voltar para hoje" quando quiser retornar ao dia atual.',
        ],
      },
      {
        titulo: 'Atender uma visita',
        passos: [
          'Confira horário, cliente, serviço e endereço no cartão da visita.',
          'Clique em "Ver no mapa" para abrir a rota até o local.',
          'Clique em "Abrir OS" para registrar produtos, cômodos, dispositivos e fotos do atendimento.',
        ],
      },
    ],
    dicas: [
      'As visitas com horário marcado vêm primeiro, na ordem do relógio; as sem horário ficam agrupadas no fim da lista.',
      'A etiqueta colorida em cada cartão mostra a situação da ordem: Pendente, Agendada, Em andamento, Concluída, Cancelada ou Em espera.',
    ],
    relacionados: [
      { titulo: 'Roteiro do dia', href: '/roteiros/painel' },
    ],
  },

  'Agendamentos/Index': {
    area: 'Operação',
    titulo: 'Agendamentos',
    paraQueServe: 'Fila dos pedidos de horário abertos pelo cliente, pelo portal ou pela página pública. É aqui que a empresa responde cada pedido, e a confirmação já cria a ordem de serviço.',
    comoUsar: [
      {
        titulo: 'Acompanhar a fila',
        passos: [
          'Use o filtro "Situação" para ver só os pendentes, confirmados, recusados ou cancelados.',
          'Leia o solicitante, o endereço, a data e o período pedidos.',
          'Confira a coluna "Origem" para saber se o pedido veio do portal do cliente ou da página pública.',
        ],
      },
      {
        titulo: 'Confirmar um pedido',
        passos: [
          'Clique em "Confirmar" na linha do pedido pendente.',
          'Reveja a data e o período (Manhã ou Tarde).',
          'Escolha o técnico responsável pelo atendimento.',
          'Clique em "Confirmar pedido".',
        ],
      },
      {
        titulo: 'Recusar um pedido',
        passos: [
          'Clique em "Recusar" na linha do pedido.',
          'Escolha um dos motivos prontos ou escreva o motivo no campo de texto.',
          'Clique em "Recusar pedido".',
        ],
      },
    ],
    dicas: [
      'Depois de respondido, a coluna de ações mostra quem respondeu o pedido.',
      'Se o período tiver lotado entre o pedido e a confirmação, o aviso aparece dentro do próprio formulário e o pedido continua aberto.',
    ],
    atencao: [
      'Confirmar cria a ordem de serviço e avisa o solicitante por e-mail — não é um rascunho.',
      'O motivo da recusa é enviado ao cliente exatamente como você escrever.',
    ],
    relacionados: [
      { titulo: 'Agenda', href: '/agenda' },
      { titulo: 'Ordens de serviço', href: '/work-orders' },
    ],
  },

  'Roteiros/Index': {
    area: 'Operação',
    titulo: 'Roteiro do dia',
    paraQueServe: 'Monta a ordem das visitas de um técnico em um dia, com mapa, distância entre paradas e horário previsto de chegada. Serve para planejar o deslocamento e entregar o roteiro impresso à equipe.',
    comoUsar: [
      {
        titulo: 'Carregar o roteiro',
        passos: [
          'Escolha a "Data" do roteiro.',
          'Escolha o "Técnico" na lista.',
          'Confira "Distância total" e "Tempo estimado" no cartão de totais.',
          'Acompanhe as paradas no mapa e na lista abaixo dele.',
        ],
      },
      {
        titulo: 'Reordenar as paradas na mão',
        passos: [
          'Segure a alça de arrastar da parada que precisa mudar de lugar.',
          'Arraste até a posição desejada e solte.',
          'Aguarde o aviso "Salvando nova ordem..." desaparecer — a ordem é gravada sozinha.',
        ],
      },
      {
        titulo: 'Otimizar por proximidade',
        passos: [
          'Clique em "Otimizar ordem".',
          'Leia a comparação entre a ordem atual e a ordem otimizada.',
          'Confira a redução ou o aumento de quilometragem indicado.',
          'Clique em "Aplicar otimização" para trocar a ordem, ou em "Cancelar" para manter como está.',
        ],
      },
      {
        titulo: 'Levar o roteiro para a rua',
        passos: [
          'Confira a data e o técnico selecionados nos filtros.',
          'Clique em "Imprimir roteiro".',
          'A impressão sai em lista compacta, com número, cliente, endereço, horário e distância.',
        ],
      },
    ],
    campos: [
      { nome: 'Chegada prevista', descricao: 'Horário estimado de chegada em cada parada, calculado a partir da ordem atual.' },
      { nome: 'Distância da anterior', descricao: 'Quilometragem desde a parada anterior. Aparece como "sem referência" quando o endereço não tem localização registrada.' },
    ],
    dicas: [
      'O cartão de totais indica quando a ordem foi montada na mão, com o nome de quem reordenou e a data.',
      'Paradas de compromisso avulso aparecem com destaque diferente das ordens de serviço.',
    ],
    atencao: [
      'Aplicar a otimização substitui a ordem manual que já estava montada. Confira o ganho antes de confirmar.',
    ],
    relacionados: [
      { titulo: 'Agenda', href: '/agenda' },
      { titulo: 'Ordens de serviço', href: '/work-orders' },
    ],
  },

  'Monitoring/Index': {
    area: 'Operação',
    titulo: 'Monitoramento',
    paraQueServe: 'Visão ao vivo da evolução da infestação de um cliente em um período: evolução por semana e por mês, ranking de pontos, mapa de calor, espécies encontradas e adequações pendentes.',
    comoUsar: [
      {
        titulo: 'Carregar a visão do período',
        passos: [
          'Escolha o "Cliente".',
          'Escolha o "Endereço" ou deixe em "Todos os endereços do cliente".',
          'Informe as datas em "De" e "Até".',
          'Clique em "Filtrar".',
        ],
      },
      {
        titulo: 'Ler os números',
        passos: [
          'Confira o período apurado e a quantidade de visitas no cartão do topo.',
          'Acompanhe a evolução semanal e mensal na primeira seção de cada endereço.',
          'Veja quais pontos mais registram ocorrência no ranking e no mapa de calor.',
          'Confira as espécies encontradas e as adequações pendentes no fim da página.',
        ],
      },
      {
        titulo: 'Congelar o período em um relatório',
        passos: [
          'Confira que o filtro está no cliente, endereço e período certos.',
          'Clique em "Gerar relatório do período".',
          'O sistema abre o relatório gerado, já com os números daquele momento gravados.',
        ],
      },
    ],
    dicas: [
      'Enquanto cliente e datas não forem preenchidos, a tela mostra apenas o convite para escolher o filtro.',
      'Esta tela recalcula tudo a cada abertura: os números mudam conforme novas visitas são registradas.',
    ],
    atencao: [
      'O relatório gerado congela os números daquele instante e passa a ser o documento do período. Gere só depois de conferir o filtro.',
    ],
    relacionados: [
      { titulo: 'Relatórios de monitoramento', href: '/monitoramento/relatorios' },
    ],
  },

  'Monitoring/Relatorios/Index': {
    area: 'Operação',
    titulo: 'Relatórios de monitoramento',
    paraQueServe: 'Lista os relatórios de monitoramento já gerados e congelados, e controla quais deles ficam visíveis para o cliente no portal.',
    comoUsar: [
      {
        titulo: 'Localizar um relatório',
        passos: [
          'Informe as datas em "De" e "Até".',
          'Clique em "Filtrar".',
          'Clique em "Limpar filtros" para ver todos os relatórios de novo.',
        ],
      },
      {
        titulo: 'Consultar e entregar',
        passos: [
          'Clique em "Ver" para abrir o relatório completo.',
          'Clique em "PDF" para baixar o documento.',
          'Confira em "Gerado em" e "Gerado por" quando e por quem o relatório foi congelado.',
        ],
      },
      {
        titulo: 'Publicar no portal do cliente',
        passos: [
          'Clique em "Publicar" na linha do relatório.',
          'Leia o aviso de que o cliente passará a ver o documento com download liberado.',
          'Clique em "Confirmar".',
          'Use "Despublicar" e confirme para tirar o relatório do portal.',
        ],
      },
    ],
    campos: [
      { nome: 'Portal do cliente', descricao: 'Indica se o relatório está Publicado ou Não publicado para o cliente.' },
    ],
    dicas: [
      'Clique em "Visão ao vivo" no topo para voltar aos números recalculados na hora.',
    ],
    atencao: [
      'Publicar libera o documento para o cliente ver e baixar no portal dele. Confira o conteúdo antes de confirmar.',
    ],
    relacionados: [
      { titulo: 'Monitoramento', href: '/monitoramento' },
    ],
  },

  'Monitoring/Relatorios/Show': {
    area: 'Operação',
    titulo: 'Relatório de monitoramento',
    paraQueServe: 'Mostra o relatório congelado de um período, exatamente como ele foi gerado, com as mesmas seções de evolução, ranking, mapa de calor, espécies e adequações.',
    comoUsar: [
      {
        titulo: 'Conferir o relatório',
        passos: [
          'Confira cliente, endereço, quem gerou e quando, no cartão do topo.',
          'Veja se o relatório está publicado no portal do cliente.',
          'Percorra as seções de cada endereço apurado.',
        ],
      },
      {
        titulo: 'Entregar ao cliente',
        passos: [
          'Confira o aviso do topo, que indica que o relatório está congelado.',
          'Clique em "Baixar PDF" para gerar o documento.',
          'Clique em "Voltar para relatórios" para retomar a lista.',
        ],
      },
    ],
    dicas: [
      'Os números aqui nunca mudam: são os que existiam no momento da geração, mesmo que novas visitas tenham sido registradas depois.',
    ],
    relacionados: [
      { titulo: 'Relatórios de monitoramento', href: '/monitoramento/relatorios' },
      { titulo: 'Monitoramento', href: '/monitoramento' },
    ],
  },

  'Plantas/Editor': {
    area: 'Operação',
    titulo: 'Planta do endereço',
    paraQueServe: 'Marca na planta do local onde fica cada dispositivo instalado. É o croqui que acompanha o monitoramento e que a equipe usa para achar as armadilhas em campo.',
    comoUsar: [
      {
        titulo: 'Enviar a planta',
        passos: [
          'Clique em "Enviar planta".',
          'Preencha o "Nome da planta", como Térreo ou Depósito.',
          'Escolha o arquivo em PNG, JPEG ou PDF de uma página, de até 10 MB.',
          'Clique em "Enviar planta" no rodapé do aviso.',
        ],
      },
      {
        titulo: 'Posicionar os dispositivos',
        passos: [
          'Arraste o dispositivo da lista lateral até o ponto certo da planta.',
          'Arraste um ponto já marcado para corrigir a posição.',
          'Acompanhe o indicador de salvamento: ele passa a "Salvo" quando o servidor confirma.',
          'Clique em "Salvar" para enviar na hora, sem esperar o salvamento automático.',
          'Clique em "Desfazer", ou use Ctrl+Z, para voltar a última mudança.',
        ],
      },
      {
        titulo: 'Trocar a planta por uma versão nova',
        passos: [
          'Clique em "Enviar nova versão".',
          'Leia o aviso: a versão atual fica preservada no histórico e as posições marcadas são copiadas para a nova.',
          'Escolha o arquivo novo.',
          'Clique em "Confirmar e enviar nova versão" e ajuste só o que mudou.',
        ],
      },
    ],
    campos: [
      { nome: 'Planta', descricao: 'Seletor que aparece quando o endereço tem mais de uma planta, como Térreo e Depósito.' },
      { nome: 'Histórico de versões', descricao: 'Lista todas as versões enviadas, marcando qual é a Ativa e quais foram Substituídas.' },
    ],
    dicas: [
      'Se o indicador mostrar erro ao salvar, clique nele para tentar enviar de novo.',
    ],
    atencao: [
      'Enviar nova versão não apaga a anterior: ela continua no histórico como versão substituída.',
    ],
    relacionados: [
      { titulo: 'Dispositivos', href: '/devices' },
      { titulo: 'Endereços', href: '/addresses' },
    ],
  },

  'Conformidade/Index': {
    area: 'Operação',
    titulo: 'Conformidade',
    paraQueServe: 'Checklist do que o sistema consegue verificar sobre a regularidade da empresa perante a RDC 622/2022, agrupado por urgência. Serve para saber o que resolver antes de uma fiscalização.',
    comoUsar: [
      {
        titulo: 'Ler o checklist',
        passos: [
          'Leia primeiro o bloco "Antes de usar este checklist", no topo.',
          'Confira os totais de Irregulares, Atenção, Regulares e Não aplicáveis.',
          'Percorra o grupo "Irregulares", que é o que precisa de providência imediata.',
          'Em cada item, leia o detalhe e a linha "O que a norma pede".',
        ],
      },
      {
        titulo: 'Resolver um item',
        passos: [
          'Clique no botão de ação do item para ir direto à tela onde o dado é corrigido.',
          'Corrija o cadastro ou o documento apontado.',
          'Volte a esta tela e clique em "Recalcular agora".',
          'Confira a data em "Última verificação registrada".',
        ],
      },
      {
        titulo: 'Consultar o resto do módulo',
        passos: [
          'Clique em "Validades dos documentos" para ajustar os prazos que o sistema acompanha.',
          'Clique em "Referência normativa" para ver o texto da resolução citada nos documentos.',
          'Use "Voltar ao checklist" nessas telas para retornar a esta lista.',
        ],
      },
    ],
    campos: [
      { nome: 'Irregulares', descricao: 'Documento vencido, cancelado ou ausente. Precisa de providência.' },
      { nome: 'Atenção', descricao: 'Vence em breve ou depende de conferência antes da próxima fiscalização.' },
      { nome: 'Não aplicáveis ou não informados', descricao: 'Item que não se aplica à empresa, ou cujo dado ainda não foi preenchido. Campo em branco não é irregularidade.' },
    ],
    atencao: [
      'O checklist informa, não certifica: ele não substitui a avaliação do responsável técnico da empresa.',
    ],
    relacionados: [
      { titulo: 'Pendências de execução', href: '/conformidade/pendencias-de-execucao' },
      { titulo: 'Referência normativa', href: '/conformidade/referencias' },
    ],
  },

  'Conformidade/PendenciasDeExecucao': {
    area: 'Operação',
    titulo: 'Pendências de execução',
    paraQueServe: 'Lista as ordens de serviço já concluídas cuja documentação ficou incompleta, ou que usaram produto com aviso de registro. Serve para completar o que falta antes de uma fiscalização.',
    comoUsar: [
      {
        titulo: 'Escolher o período',
        passos: [
          'Informe as datas em "De" e "Até".',
          'Clique em "Filtrar".',
          'Se a lista vier vazia, nenhuma ordem concluída no período está com documentação incompleta.',
        ],
      },
      {
        titulo: 'Completar a documentação de uma ordem',
        passos: [
          'Leia os itens marcados como "Falta documentar" e os de "Aviso de registro".',
          'Confira a linha que explica o que a norma pede em cada item.',
          'Clique em "Abrir ordem de serviço".',
          'Registre o que falta nas abas de execução da OS e volte a esta lista.',
        ],
      },
    ],
    dicas: [
      'Cada bloco traz o número da ordem e a data de execução, o que ajuda a organizar a fila por antiguidade.',
    ],
    atencao: [
      'Nada nesta lista impede concluir ordem de serviço, assinar ou emitir documento. É uma lista de conferência, não um bloqueio.',
    ],
    relacionados: [
      { titulo: 'Conformidade', href: '/conformidade' },
      { titulo: 'Ordens de serviço', href: '/work-orders' },
    ],
  },

  'Conformidade/Referencias': {
    area: 'Operação',
    titulo: 'Referência normativa',
    paraQueServe: 'Define o texto da resolução que sai impresso nos certificados, ordens de serviço, contratos e recibos da empresa.',
    comoUsar: [
      {
        titulo: 'Conferir o texto em uso',
        passos: [
          'Leia o bloco "Texto usado hoje nos documentos".',
          'Confira na tabela quais referências estão cadastradas e a origem de cada uma.',
          'Observe que a referência da empresa tem prioridade sobre a padrão do sistema quando têm a mesma chave.',
        ],
      },
      {
        titulo: 'Cadastrar uma referência',
        passos: [
          'Clique em "Cadastrar referência".',
          'Preencha a "Chave" com o valor indicado abaixo do campo.',
          'Escreva o "Texto da referência" — é ele que sai impresso.',
          'Preencha o "Texto curto" e a data em "Vigente desde".',
          'Deixe "Ativa (usada nos documentos)" marcado e clique em "Salvar".',
        ],
      },
      {
        titulo: 'Alterar ou remover',
        passos: [
          'Clique em "Editar" na linha da referência da empresa.',
          'Ajuste o texto e clique em "Salvar".',
          'Clique em "Remover" e confirme em "Sim, remover" para voltar ao padrão do sistema.',
        ],
      },
    ],
    campos: [
      { nome: 'Origem', descricao: 'Mostra se a referência é "Padrão do sistema", que é somente leitura, ou "Da empresa", que pode ser editada e removida.' },
    ],
    atencao: [
      'A alteração vale só para documentos futuros. Documento já emitido não é reprocessado: ele continua com o texto da época, que é o exemplar que o cliente arquivou e que a fiscalização compara.',
    ],
    relacionados: [
      { titulo: 'Conformidade', href: '/conformidade' },
    ],
  },
};
