/**
 * Manual de uso da área de cadastro de clientes e locais.
 *
 * A chave de cada entrada é o nome do componente Inertia da tela
 * (ver formato em `modelo.js`).
 */
export default {
  'Clients/Index': {
    area: 'Clientes e locais',
    titulo: 'Clientes',
    paraQueServe: 'Lista todos os clientes cadastrados e é o ponto de partida para criar, consultar, editar ou excluir um cliente. Todo o resto do sistema (endereços, cômodos, dispositivos, ordens de serviço) nasce de um cliente.',
    comoUsar: [
      {
        titulo: 'Encontrar um cliente',
        passos: [
          'Digite pelo menos 2 caracteres no campo de busca do topo da tela.',
          'Busque por nome, e-mail ou CPF/CNPJ — a lista se atualiza sozinha enquanto você digita.',
          'Clique no "x" dentro do campo para limpar a busca e ver todos de novo.',
          'Use a paginação no rodapé quando a lista tiver mais de uma página.',
        ],
      },
      {
        titulo: 'Cadastrar um cliente',
        passos: [
          'Clique em "Novo Cliente", no canto superior direito.',
          'Preencha nome, e-mail, telefone e CPF/CNPJ.',
          'Clique em "Salvar Cliente".',
          'De volta à lista, abra o cliente para cadastrar o primeiro endereço dele.',
        ],
      },
      {
        titulo: 'Consultar, editar ou excluir',
        passos: [
          'Na coluna "Ações", clique no ícone de olho para ver os detalhes do cliente.',
          'Clique no ícone de lápis para abrir a tela de edição.',
          'Clique no ícone de lixeira para excluir e confirme na janela que aparece.',
        ],
      },
    ],
    campos: [
      { nome: 'Documento', descricao: 'CPF ou CNPJ do cliente. Não pode se repetir entre clientes.' },
      { nome: 'Contato', descricao: 'Mostra o e-mail e o telefone principais cadastrados.' },
    ],
    dicas: [
      'Os ícones de ação só aparecem se o seu perfil de acesso tiver a permissão correspondente. Se não vê o botão de excluir, peça a permissão a quem administra o sistema.',
    ],
    atencao: [
      'A exclusão do cliente é definitiva e leva junto o histórico ligado a ele. Só exclua cadastros criados por engano.',
    ],
    relacionados: [
      { titulo: 'Endereços', href: '/addresses' },
      { titulo: 'Dispositivos', href: '/devices' },
    ],
  },

  'Clients/Create': {
    area: 'Clientes e locais',
    titulo: 'Novo cliente',
    paraQueServe: 'Formulário de cadastro de um novo cliente. É o primeiro passo antes de cadastrar endereços, cômodos, dispositivos e ordens de serviço para ele.',
    comoUsar: [
      {
        titulo: 'Preencher o cadastro',
        passos: [
          'Preencha "Nome / Razão Social" com o nome completo da pessoa ou a razão social da empresa.',
          'Informe o "Email" — ele é usado para os avisos automáticos do sistema.',
          'Digite o "Telefone"; o campo formata sozinho no padrão (11) 99999-9999.',
          'Digite o "CPF/CNPJ"; o campo também se formata sozinho conforme você digita.',
          'Use "Observações" para qualquer informação livre sobre o cliente.',
        ],
      },
      {
        titulo: 'Salvar e continuar',
        passos: [
          'Clique em "Salvar Cliente".',
          'Se aparecer um aviso vermelho, corrija os campos destacados e salve de novo.',
          'Depois de salvo, cadastre os endereços do cliente na área de Endereços.',
        ],
      },
    ],
    campos: [
      { nome: 'Nome / Razão Social', descricao: 'Obrigatório. Nome da pessoa física ou razão social da empresa.' },
      { nome: 'Email', descricao: 'Obrigatório e único: dois clientes não podem ter o mesmo e-mail.' },
      { nome: 'CPF/CNPJ', descricao: 'Obrigatório e único. Se o sistema recusar, já existe um cliente com esse documento.' },
    ],
    atencao: [
      'Os endereços não são cadastrados aqui. Salve o cliente primeiro e depois use a área de Endereços, como o próprio aviso azul do formulário indica.',
    ],
    relacionados: [
      { titulo: 'Clientes', href: '/clients' },
      { titulo: 'Endereços', href: '/addresses' },
    ],
  },

  'Clients/Edit': {
    area: 'Clientes e locais',
    titulo: 'Editar cliente',
    paraQueServe: 'Atualiza os dados de um cliente já cadastrado e define por quais canais ele aceita receber os avisos automáticos do sistema.',
    comoUsar: [
      {
        titulo: 'Corrigir os dados do cliente',
        passos: [
          'Ajuste "Nome / Razão Social", "Email", "Telefone" ou "CPF/CNPJ" conforme necessário.',
          'Atualize as "Observações" se houver alguma informação nova.',
          'Clique em "Atualizar Cliente" para gravar.',
        ],
      },
      {
        titulo: 'Definir como o cliente recebe avisos',
        passos: [
          'No bloco "Comunicação", marque ou desmarque "Aceita receber avisos por e-mail".',
          'Marque ou desmarque "Aceita receber avisos por WhatsApp".',
          'Em "Canal preferido", escolha "E-mail", "WhatsApp" ou deixe em "Sem preferência".',
          'Preencha "E-mail de notificação" só se os avisos precisarem ir para um endereço diferente do e-mail principal.',
          'Clique em "Atualizar Cliente".',
        ],
      },
    ],
    campos: [
      { nome: 'Canal preferido', descricao: 'Por onde o sistema tenta avisar primeiro. "Sem preferência" deixa o sistema decidir.' },
      { nome: 'E-mail de notificação', descricao: 'Endereço alternativo só para avisos. Em branco, o sistema usa o e-mail principal.' },
    ],
    atencao: [
      'Desmarcar um canal interrompe todos os avisos automáticos daquele tipo para este cliente, inclusive lembretes de visita e avisos de vencimento.',
    ],
    relacionados: [
      { titulo: 'Clientes', href: '/clients' },
      { titulo: 'Endereços', href: '/addresses' },
    ],
  },

  'Clients/Show': {
    area: 'Clientes e locais',
    titulo: 'Detalhes do cliente',
    paraQueServe: 'Mostra a ficha completa do cliente: contato, documento, endereços cadastrados e os últimos avisos automáticos enviados a ele. Também permite cadastrar um endereço novo sem sair da tela.',
    comoUsar: [
      {
        titulo: 'Cadastrar um endereço direto pela ficha',
        passos: [
          'No bloco "Endereços", clique em "Novo Endereço" (ou em "Adicionar Endereço", se ainda não houver nenhum).',
          'Preencha o "Apelido" do local, como "Matriz", "Casa" ou "Filial".',
          'Digite o CEP e clique em "Buscar CEP" para preencher rua, bairro, cidade e estado automaticamente.',
          'Complete o número e, se quiser, um ponto de referência.',
          'Clique em "Salvar Endereço" — ele passa a aparecer na lista logo abaixo.',
        ],
      },
      {
        titulo: 'Conferir os avisos enviados ao cliente',
        passos: [
          'Role até o bloco "Notificações".',
          'Veja o tipo de aviso, a data e a situação (Pendente, Enviada, Falha, entre outras).',
          'Clique em "Ver histórico completo" para abrir todos os avisos deste cliente.',
        ],
      },
      {
        titulo: 'Falar com o cliente e editar a ficha',
        passos: [
          'No bloco "Telefone", use o botão de WhatsApp para abrir uma conversa já com o telefone do cliente.',
          'Clique em "Editar Cliente" para alterar dados de cadastro ou preferências de contato.',
          'Nos endereços listados, use os ícones ao lado de cada um para ver ou editar aquele local.',
        ],
      },
    ],
    dicas: [
      'O botão de WhatsApp só fica disponível quando o cliente está marcado como quem aceita avisos por WhatsApp na tela de edição.',
      'Um endereço marcado como "Inativo" continua na ficha, mas sinaliza que aquele local não está mais em atendimento.',
    ],
    relacionados: [
      { titulo: 'Clientes', href: '/clients' },
      { titulo: 'Endereços', href: '/addresses' },
    ],
  },

  'Addresses/Index': {
    area: 'Clientes e locais',
    titulo: 'Endereços',
    paraQueServe: 'Lista todos os locais atendidos, de todos os clientes. É aqui que você encontra um endereço para ver seus cômodos e dispositivos, editá-lo ou gerar um contrato para ele.',
    comoUsar: [
      {
        titulo: 'Localizar um endereço',
        passos: [
          'Digite no campo "Buscar Endereços" o apelido, a rua, a cidade ou o nome do cliente.',
          'Aguarde a lista se atualizar sozinha.',
          'Clique em "Limpar" para voltar à lista completa.',
        ],
      },
      {
        titulo: 'Cadastrar um endereço',
        passos: [
          'Clique em "Novo Endereço", no canto superior direito.',
          'Escolha o cliente dono do local e preencha os dados.',
          'Salve — o endereço passa a aparecer nesta lista.',
        ],
      },
      {
        titulo: 'Agir sobre um endereço da lista',
        passos: [
          'Clique em "Ver" para abrir o endereço com seus cômodos e dispositivos.',
          'Clique em "Editar" para corrigir os dados do local.',
          'Clique em "Contrato" para iniciar um contrato para aquele endereço.',
          'Clique em "Excluir" e confirme na janela para remover o endereço.',
        ],
      },
    ],
    campos: [
      { nome: 'Ativo / Inativo', descricao: 'Indica se o local segue em atendimento. Endereço inativo continua no sistema com todo o histórico.' },
      { nome: 'Cômodos', descricao: 'Quantidade de cômodos já cadastrados naquele endereço.' },
    ],
    dicas: [
      'Os quadros do topo contam o total de endereços e, dentro da página aberta no momento, quantos estão ativos, de quantos clientes e em quantas cidades.',
    ],
    atencao: [
      'Excluir um endereço é definitivo e afeta tudo que depende dele, como cômodos e dispositivos. Se o local só saiu de atendimento, prefira editá-lo e desmarcar "Endereço ativo".',
    ],
    relacionados: [
      { titulo: 'Clientes', href: '/clients' },
      { titulo: 'Dispositivos', href: '/devices' },
    ],
  },

  'Addresses/Create': {
    area: 'Clientes e locais',
    titulo: 'Novo endereço',
    paraQueServe: 'Cadastra um local de atendimento e o vincula a um cliente. O endereço precisa existir antes de você cadastrar cômodos, dispositivos ou abrir ordens de serviço para aquele local.',
    comoUsar: [
      {
        titulo: 'Preencher o endereço',
        passos: [
          'Em "Cliente", escolha o dono do local — só aparecem clientes já cadastrados.',
          'Preencha o "Apelido" com um nome curto que identifique o local, como "Matriz" ou "Depósito".',
          'Digite o CEP e clique em "Buscar CEP": rua, bairro, cidade e estado são preenchidos automaticamente.',
          'Complete o "Número" (use "S/N" quando não houver) e confira os demais campos.',
          'Se ajudar o técnico a chegar, escreva um ponto em "Referência".',
        ],
      },
      {
        titulo: 'Salvar',
        passos: [
          'Deixe "Endereço ativo" marcado se o local está em atendimento.',
          'Clique em "Salvar Endereço".',
          'Abra o endereço recém-criado para cadastrar os cômodos e os dispositivos dele.',
        ],
      },
    ],
    campos: [
      { nome: 'Apelido', descricao: 'Como o local é chamado no dia a dia. Aparece nas listas, nas ordens de serviço e nos relatórios.' },
      { nome: 'Referência', descricao: 'Campo livre, opcional, com uma dica de localização para quem vai até o local.' },
    ],
    dicas: [
      'Se a busca por CEP não trouxer nada, preencha rua, bairro, cidade e estado à mão — nenhum deles fica bloqueado.',
    ],
    atencao: [
      'O cliente precisa estar cadastrado antes: não é possível criar um endereço solto, sem dono.',
    ],
    relacionados: [
      { titulo: 'Endereços', href: '/addresses' },
      { titulo: 'Clientes', href: '/clients' },
    ],
  },

  'Addresses/Edit': {
    area: 'Clientes e locais',
    titulo: 'Editar endereço',
    paraQueServe: 'Corrige os dados de um local já cadastrado, inclusive o cliente ao qual ele pertence, e permite inativá-lo quando o local sai de atendimento.',
    comoUsar: [
      {
        titulo: 'Atualizar os dados',
        passos: [
          'Ajuste os campos que precisam de correção.',
          'Se o CEP mudou, digite o novo e clique em "Buscar CEP" para atualizar rua, bairro, cidade e estado de uma vez.',
          'Clique em "Atualizar Endereço" para gravar.',
        ],
      },
      {
        titulo: 'Tirar o local de atendimento',
        passos: [
          'Desmarque "Endereço ativo".',
          'Clique em "Atualizar Endereço".',
          'O endereço passa a aparecer como "Inativo" nas listas, sem perder o histórico.',
        ],
      },
    ],
    atencao: [
      'Trocar o campo "Cliente" transfere o local, e tudo que está nele, para outro cliente. Só faça isso quando o cadastro original estiver realmente errado.',
    ],
    relacionados: [
      { titulo: 'Endereços', href: '/addresses' },
    ],
  },

  'Addresses/Show': {
    area: 'Clientes e locais',
    titulo: 'Detalhes do endereço',
    paraQueServe: 'Central do local: mostra os dados do endereço e do cliente e reúne, em abas, os cômodos e os dispositivos instalados ali, além das ordens de serviço já feitas no local.',
    comoUsar: [
      {
        titulo: 'Cadastrar um cômodo',
        passos: [
          'Abra a aba "Cômodos".',
          'Clique em "Novo Cômodo" (ou "Adicionar Cômodo", se ainda não houver nenhum).',
          'Preencha o "Nome do Cômodo", como Sala, Cozinha ou Depósito.',
          'Use "Observações" para detalhes do ambiente, se precisar.',
          'Confirme para salvar — o cômodo aparece na lista da aba.',
        ],
      },
      {
        titulo: 'Cadastrar um dispositivo',
        passos: [
          'Abra a aba "Dispositivos" e clique em "Novo Dispositivo".',
          'Preencha "Nome/Etiqueta" e "Número/Identificação".',
          'Escolha o "Tipo de Isca" ou clique no botão "+" ao lado para cadastrar um tipo novo na hora.',
          'Descreva onde ele fica em "Localização Padrão".',
          'Clique em "Salvar Dispositivo".',
        ],
      },
      {
        titulo: 'Consultar cômodos, dispositivos e histórico',
        passos: [
          'Use "Ver" ou "Editar" ao lado de cada cômodo ou dispositivo para abrir seus detalhes.',
          'Role até "Ordens de Serviço" para ver os atendimentos já feitos neste local.',
          'Clique em "Ver" em uma ordem de serviço para abrir o atendimento completo.',
        ],
      },
    ],
    dicas: [
      'O número ao lado do nome de cada aba mostra quantos cômodos e quantos dispositivos o local tem.',
      'O tipo de isca criado pelo botão "+" fica disponível para os próximos dispositivos, sem precisar cadastrá-lo de novo.',
    ],
    atencao: [
      'Cômodo ou dispositivo já usado em alguma ordem de serviço não pode ser excluído: o botão "Excluir" fica desabilitado ou o sistema recusa a exclusão, para preservar o histórico do atendimento.',
    ],
    relacionados: [
      { titulo: 'Endereços', href: '/addresses' },
      { titulo: 'Dispositivos', href: '/devices' },
      { titulo: 'Avistamentos de pragas', href: '/pest-sightings' },
    ],
  },

  'Rooms/Create': {
    area: 'Clientes e locais',
    titulo: 'Novo cômodo',
    paraQueServe: 'Cadastra um ambiente dentro de um endereço — sala, cozinha, depósito, área externa. Os cômodos são o que o técnico percorre e registra durante a ordem de serviço.',
    comoUsar: [
      {
        titulo: 'Cadastrar o cômodo',
        passos: [
          'Em "Endereço", escolha o local onde fica o cômodo.',
          'Preencha o "Nome do Cômodo".',
          'Use "Observações" para detalhes que ajudem o técnico, como acesso restrito ou horário.',
          'Deixe "Cômodo ativo" marcado se o ambiente faz parte do atendimento.',
          'Clique em "Salvar Cômodo".',
        ],
      },
      {
        titulo: 'Se o endereço não estiver na lista',
        passos: [
          'Clique em "Voltar" para sair sem salvar.',
          'Cadastre o endereço na área de Endereços.',
          'Volte a esta tela: o novo endereço já aparecerá na lista de escolha.',
        ],
      },
    ],
    dicas: [
      'Quando você chega aqui a partir de um endereço, ele já vem escolhido no campo "Endereço".',
      'Use nomes que o técnico reconheça no local; eles aparecem no roteiro do atendimento.',
    ],
    relacionados: [
      { titulo: 'Endereços', href: '/addresses' },
    ],
  },

  'Rooms/Edit': {
    area: 'Clientes e locais',
    titulo: 'Editar cômodo',
    paraQueServe: 'Corrige o nome e as observações de um cômodo já cadastrado, ou o inativa quando aquele ambiente deixa de ser atendido.',
    comoUsar: [
      {
        titulo: 'Atualizar o cômodo',
        passos: [
          'Ajuste o "Nome do Cômodo" e as "Observações".',
          'Marque ou desmarque "Cômodo ativo" conforme o ambiente siga ou não em atendimento.',
          'Clique em "Atualizar Cômodo".',
        ],
      },
    ],
    campos: [
      { nome: 'Endereço', descricao: 'Aparece apenas para conferência e não pode ser alterado aqui. Um cômodo pertence sempre ao endereço onde foi criado.' },
    ],
    relacionados: [
      { titulo: 'Endereços', href: '/addresses' },
    ],
  },

  'Rooms/Show': {
    area: 'Clientes e locais',
    titulo: 'Detalhes do cômodo',
    paraQueServe: 'Mostra a ficha de um cômodo: nome, situação, observações e datas de criação e de última alteração.',
    comoUsar: [
      {
        titulo: 'Navegar a partir do cômodo',
        passos: [
          'Use a trilha no topo para voltar ao cliente ou ao endereço em um clique.',
          'Clique em "Editar Cômodo" para corrigir o nome, as observações ou a situação.',
          'Clique em "Voltar" para retornar à tela anterior.',
        ],
      },
    ],
    campos: [
      { nome: 'Status', descricao: '"Ativo" significa que o ambiente faz parte do atendimento; "Inativo", que ele foi retirado da rotina, mas continua registrado.' },
    ],
    relacionados: [
      { titulo: 'Endereços', href: '/addresses' },
    ],
  },

  'Devices/Index': {
    area: 'Clientes e locais',
    titulo: 'Dispositivos',
    paraQueServe: 'Lista todos os pontos de monitoramento instalados nos endereços (armadilhas, iscas, sensores). Serve para localizar um dispositivo, cadastrar novos em lote e imprimir as etiquetas de identificação.',
    comoUsar: [
      {
        titulo: 'Filtrar a lista',
        passos: [
          'Escolha um "Cliente" para restringir a lista.',
          'Escolha o "Endereço" — a lista de endereços só libera depois que um cliente é selecionado.',
          'Se quiser, escolha também o "Tipo de Isca" e a "Situação".',
          'Clique em "Aplicar Filtros"; use "Limpar Filtros" para recomeçar.',
        ],
      },
      {
        titulo: 'Cadastrar vários dispositivos de uma vez',
        passos: [
          'Filtre por um único endereço — sem isso o botão fica desabilitado, porque o lote precisa saber onde os dispositivos ficam.',
          'Clique em "Cadastrar em lote".',
          'Informe a quantidade, o número inicial e o prefixo do rótulo; confira a pré-visualização dos nomes.',
          'Escolha o tipo de isca e o local previsto, se souber.',
          'Clique em "Criar lote" e, na sequência, use "Imprimir etiquetas destes" se já for etiquetar.',
        ],
      },
      {
        titulo: 'Imprimir etiquetas de dispositivos existentes',
        passos: [
          'Marque a caixa de seleção dos dispositivos desejados.',
          'Selecione somente dispositivos de um mesmo endereço — a folha de etiquetas é sempre por endereço.',
          'Clique em "Imprimir etiquetas": a folha abre em uma nova aba, pronta para impressão.',
        ],
      },
    ],
    campos: [
      { nome: 'Situação', descricao: 'Ativo, Substituído ou Removido. Este filtro age só sobre os dispositivos já carregados na página aberta.' },
      { nome: 'Código', descricao: 'Código público do dispositivo, em destaque ao lado do nome. É o mesmo que sai impresso na etiqueta.' },
    ],
    dicas: [
      'Dispositivos substituídos ou removidos não têm mais os botões de editar e excluir: eles ficam só como histórico.',
    ],
    atencao: [
      'Dispositivo já usado em ordem de serviço ou com eventos registrados não pode ser excluído; o sistema avisa ao tentar.',
    ],
    relacionados: [
      { titulo: 'Endereços', href: '/addresses' },
      { titulo: 'Eventos de dispositivos', href: '/device-events' },
    ],
  },

  'Devices/Create': {
    area: 'Clientes e locais',
    titulo: 'Novo dispositivo',
    paraQueServe: 'Cadastra um ponto de monitoramento em um endereço. O endereço precisa existir antes, porque todo dispositivo pertence a um local.',
    comoUsar: [
      {
        titulo: 'Cadastrar o dispositivo',
        passos: [
          'Escolha o "Endereço" onde o dispositivo está instalado.',
          'Preencha o "Rótulo" com o nome de uso, por exemplo "Armadilha para Ratos".',
          'Informe o "Número", que identifica o ponto dentro do endereço.',
          'Selecione o "Tipo de Isca" ou clique em "+ Novo Tipo" para cadastrar um na hora.',
          'Descreva onde ele fica em "Localização no Endereço" e clique em "Salvar Dispositivo".',
        ],
      },
      {
        titulo: 'Se precisar de vários dispositivos iguais',
        passos: [
          'Clique em "Cancelar" para sair sem salvar.',
          'Abra a lista de Dispositivos e filtre pelo endereço desejado.',
          'Use "Cadastrar em lote" para criar a faixa numerada de uma vez só.',
        ],
      },
    ],
    campos: [
      { nome: 'Rótulo', descricao: 'Nome pelo qual o técnico reconhece o dispositivo no local.' },
      { nome: 'Número', descricao: 'Identificação do ponto dentro do endereço. Não pode se repetir no mesmo local.' },
    ],
    relacionados: [
      { titulo: 'Dispositivos', href: '/devices' },
      { titulo: 'Endereços', href: '/addresses' },
    ],
  },

  'Devices/Edit': {
    area: 'Clientes e locais',
    titulo: 'Editar dispositivo',
    paraQueServe: 'Corrige os dados de um dispositivo já instalado: nome, número, tipo de isca, observação de localização e se ele segue ativo.',
    comoUsar: [
      {
        titulo: 'Atualizar o dispositivo',
        passos: [
          'Confira o "Endereço" e ajuste-o apenas se o dispositivo foi realmente movido de local.',
          'Corrija o "Rótulo" e o "Número" conforme a etiqueta em campo.',
          'Troque o "Tipo de Isca" se a isca usada no ponto mudou.',
          'Atualize a observação de localização com o lugar exato onde ele está.',
          'Clique em "Atualizar Dispositivo".',
        ],
      },
    ],
    atencao: [
      'Se o dispositivo físico foi trocado por outro, não edite este cadastro: abra os detalhes dele e use "Substituir Dispositivo", para que o histórico do ponto fique preservado.',
    ],
    relacionados: [
      { titulo: 'Dispositivos', href: '/devices' },
    ],
  },

  'Devices/Show': {
    area: 'Clientes e locais',
    titulo: 'Detalhes do dispositivo',
    paraQueServe: 'Mostra a ficha completa de um ponto de monitoramento: código público, situação, tipo de isca, localização e a linha do tempo dos dispositivos que já ocuparam aquele ponto. Daqui também se imprime a etiqueta e se registra a substituição.',
    comoUsar: [
      {
        titulo: 'Imprimir a etiqueta',
        passos: [
          'Clique em "Imprimir Etiqueta", no topo da tela.',
          'A folha de etiqueta abre em uma nova aba do navegador.',
          'Imprima e cole a etiqueta no dispositivo, conferindo o código com o que aparece nesta tela.',
        ],
      },
      {
        titulo: 'Substituir o dispositivo do ponto',
        passos: [
          'Clique em "Substituir Dispositivo" — disponível apenas enquanto ele estiver ativo.',
          'Escolha o "Motivo" e confirme a "Data da substituição".',
          'Escreva a "Observação" explicando o que houve; com o motivo "Outro" ela é obrigatória.',
          'Confira o nome e o número do dispositivo novo, já pré-preenchidos.',
          'Confirme: o antigo sai de circulação com o histórico preservado e o novo assume o ponto com código próprio.',
        ],
      },
      {
        titulo: 'Acompanhar o ponto',
        passos: [
          'Use a trilha do topo para voltar ao cliente ou ao endereço.',
          'Role até "Histórico do Ponto" para ver a sequência de dispositivos daquele local.',
          'Clique em "Editar Dispositivo" para corrigir dados de cadastro.',
        ],
      },
    ],
    campos: [
      { nome: 'Código público', descricao: 'Código em destaque, o mesmo impresso na etiqueta. Serve para conferência rápida em campo.' },
      { nome: 'Situação', descricao: 'Ativo, Substituído ou Removido. Só um dispositivo ativo pode ser editado ou substituído.' },
    ],
    atencao: [
      'A substituição não pode ser desfeita pela tela: o dispositivo anterior sai de circulação em definitivo.',
      'O tipo de isca do dispositivo novo é herdado do anterior. Para trocá-lo, edite o dispositivo novo logo depois da substituição.',
      'A linha do tempo completa nem sempre aparece ao abrir a tela; nesse caso o sistema avisa que está mostrando só a situação atual deste dispositivo.',
    ],
    relacionados: [
      { titulo: 'Dispositivos', href: '/devices' },
      { titulo: 'Eventos de dispositivos', href: '/device-events' },
    ],
  },

  'DeviceEvents/Index': {
    area: 'Clientes e locais',
    titulo: 'Eventos de dispositivos',
    paraQueServe: 'Reúne tudo o que foi registrado nos dispositivos durante os atendimentos: consumo de isca, limpeza, troca de isca e observações do técnico. Serve para acompanhar a evolução de um ponto ao longo do tempo.',
    comoUsar: [
      {
        titulo: 'Filtrar os eventos',
        passos: [
          'Escolha o "Dispositivo" para ver o histórico de um ponto específico.',
          'Escolha a "Ordem de Serviço" para ver o que foi registrado em um atendimento.',
          'Selecione o "Tipo de Evento" e/ou uma data em "Data (De)".',
          'Clique em "Aplicar Filtros"; use "Limpar Filtros" para voltar à lista completa.',
        ],
      },
      {
        titulo: 'Consultar um evento',
        passos: [
          'Localize o evento na lista — cada item mostra o dispositivo, o cliente, a data e a ordem de serviço.',
          'Clique em "Ver Detalhes" para abrir o registro completo.',
          'Clique em "Editar" para corrigir alguma informação lançada.',
        ],
      },
    ],
    campos: [
      { nome: 'Tipo de Evento', descricao: 'Consumo de Isca, Limpeza, Troca de Isca ou Observações do Técnico. Cada tipo mostra informações próprias na lista.' },
    ],
    relacionados: [
      { titulo: 'Dispositivos', href: '/devices' },
      { titulo: 'Avistamentos de pragas', href: '/pest-sightings' },
    ],
  },

  'DeviceEvents/Create': {
    area: 'Clientes e locais',
    titulo: 'Novo evento de dispositivo',
    paraQueServe: 'Registra o que foi observado ou feito em um dispositivo durante um atendimento. O dispositivo e a ordem de serviço já precisam existir, porque todo evento pertence aos dois.',
    comoUsar: [
      {
        titulo: 'Registrar o evento',
        passos: [
          'Escolha o "Dispositivo" e a "Ordem de Serviço" a que o registro se refere.',
          'Selecione o "Tipo de Evento".',
          'Confira a "Data do Evento", que já vem preenchida com a data e a hora atuais.',
          'Preencha os campos que aparecem conforme o tipo escolhido.',
          'Clique em "Salvar Evento".',
        ],
      },
      {
        titulo: 'Preencher conforme o tipo',
        passos: [
          'Em "Consumo de Isca", informe o status (Parcial, Total, Não houve, Estragada ou Reposição) e, se souber, a quantidade.',
          'Em "Limpeza", responda se ela foi realizada e descreva o que foi feito.',
          'Em "Troca de Isca", informe o tipo, o lote e a quantidade da isca nova.',
          'Em "Observações do Técnico", escreva o relato — o texto é obrigatório nesse tipo.',
        ],
      },
    ],
    dicas: [
      'A data é registrada no horário de Brasília, o mesmo que aparece no campo.',
      'Trocar o tipo de evento limpa os campos específicos preenchidos antes; escolha o tipo certo no começo.',
    ],
    relacionados: [
      { titulo: 'Eventos de dispositivos', href: '/device-events' },
      { titulo: 'Dispositivos', href: '/devices' },
    ],
  },

  'DeviceEvents/Edit': {
    area: 'Clientes e locais',
    titulo: 'Editar evento de dispositivo',
    paraQueServe: 'Corrige um registro já lançado em um dispositivo, quando algo foi anotado errado durante o atendimento.',
    comoUsar: [
      {
        titulo: 'Corrigir o registro',
        passos: [
          'Confira o "Dispositivo" e a "Ordem de Serviço" e ajuste-os apenas se o evento foi lançado no lugar errado.',
          'Corrija o "Tipo de Evento" e a "Data do Evento" se necessário.',
          'Atualize os campos específicos do tipo — status de consumo, limpeza, troca de isca ou observações.',
          'Salve para gravar a correção.',
        ],
      },
    ],
    atencao: [
      'Ao trocar o tipo de evento, os campos específicos do tipo anterior são limpos e precisam ser preenchidos de novo.',
    ],
    relacionados: [
      { titulo: 'Eventos de dispositivos', href: '/device-events' },
    ],
  },

  'DeviceEvents/Show': {
    area: 'Clientes e locais',
    titulo: 'Detalhes do evento',
    paraQueServe: 'Mostra o registro completo de um evento de dispositivo: tipo, data, dispositivo, ordem de serviço, cliente, endereço e as informações específicas daquele tipo de evento.',
    comoUsar: [
      {
        titulo: 'Consultar e corrigir',
        passos: [
          'Confira os dados no bloco "Detalhes do Evento".',
          'Role a tela para ver o bloco específico do tipo — consumo de isca, limpeza, troca de isca ou observações.',
          'Clique em "Editar Evento" se algo precisar de correção.',
          'Clique em "Voltar à Lista" para retornar aos eventos.',
        ],
      },
    ],
    relacionados: [
      { titulo: 'Eventos de dispositivos', href: '/device-events' },
      { titulo: 'Dispositivos', href: '/devices' },
    ],
  },

  'PestSightings/Index': {
    area: 'Clientes e locais',
    titulo: 'Avistamentos de pragas',
    paraQueServe: 'Reúne os avistamentos de pragas registrados nos atendimentos, com tipo, severidade e local. Serve para acompanhar a infestação de um endereço ao longo do tempo e priorizar ações.',
    comoUsar: [
      {
        titulo: 'Filtrar os avistamentos',
        passos: [
          'Escolha o "Endereço" e/ou a "Ordem de Serviço" que quer analisar.',
          'Selecione o "Tipo de Praga" e a "Severidade".',
          'Delimite o período em "Data (De)" e "Data (Até)".',
          'Clique em "Aplicar Filtros"; use "Limpar Filtros" para recomeçar.',
        ],
      },
      {
        titulo: 'Analisar um registro',
        passos: [
          'Observe a etiqueta colorida de severidade ao lado do tipo de praga.',
          'Leia o resumo com localização, condições ambientais e medidas aplicadas.',
          'Clique em "Ver Detalhes" para abrir o registro completo ou em "Editar" para corrigi-lo.',
        ],
      },
    ],
    campos: [
      { nome: 'Severidade', descricao: 'Baixa, Média, Alta ou Crítica. A cor da etiqueta acompanha a gravidade e ajuda a priorizar os casos mais graves.' },
    ],
    relacionados: [
      { titulo: 'Endereços', href: '/addresses' },
      { titulo: 'Eventos de dispositivos', href: '/device-events' },
    ],
  },

  'PestSightings/Create': {
    area: 'Clientes e locais',
    titulo: 'Novo avistamento de praga',
    paraQueServe: 'Registra uma praga encontrada durante um atendimento, com o local exato, a gravidade e o que foi feito. O endereço e a ordem de serviço precisam existir antes do registro.',
    comoUsar: [
      {
        titulo: 'Registrar o avistamento',
        passos: [
          'Escolha o "Endereço" e a "Ordem de Serviço" correspondentes.',
          'Confirme a "Data do Avistamento", com data e hora.',
          'Selecione o "Tipo de Praga" e o nível de severidade.',
          'Descreva onde a praga foi vista no campo de localização — esse campo é obrigatório.',
          'Clique em "Salvar Avistamento".',
        ],
      },
      {
        titulo: 'Completar o contexto (opcional, mas recomendado)',
        passos: [
          'Anote as condições ambientais, como umidade, temperatura ou iluminação.',
          'Descreva as medidas de controle aplicadas na hora.',
          'Acrescente observações do técnico com qualquer detalhe relevante para a próxima visita.',
        ],
      },
    ],
    campos: [
      { nome: 'Nível de severidade', descricao: 'Baixa, Média, Alta ou Crítica. Use o mesmo critério em todos os registros para que a comparação entre visitas faça sentido.' },
    ],
    dicas: [
      'Quanto mais específica a descrição da localização, mais fácil o próximo técnico conferir o mesmo ponto.',
    ],
    relacionados: [
      { titulo: 'Avistamentos de pragas', href: '/pest-sightings' },
      { titulo: 'Endereços', href: '/addresses' },
    ],
  },

  'PestSightings/Edit': {
    area: 'Clientes e locais',
    titulo: 'Editar avistamento de praga',
    paraQueServe: 'Corrige um avistamento já registrado ou completa informações que faltaram no momento do atendimento.',
    comoUsar: [
      {
        titulo: 'Corrigir o registro',
        passos: [
          'Ajuste o endereço, a ordem de serviço ou a data se o registro foi lançado no lugar errado.',
          'Revise o tipo de praga e o nível de severidade.',
          'Complete a descrição da localização, as condições ambientais e as medidas aplicadas.',
          'Salve para gravar a correção.',
        ],
      },
    ],
    atencao: [
      'O avistamento faz parte do histórico do local e pode embasar o laudo do cliente. Corrija apenas para deixar o registro fiel ao que aconteceu.',
    ],
    relacionados: [
      { titulo: 'Avistamentos de pragas', href: '/pest-sightings' },
    ],
  },

  'PestSightings/Show': {
    area: 'Clientes e locais',
    titulo: 'Detalhes do avistamento',
    paraQueServe: 'Mostra o registro completo de um avistamento: praga, data, severidade, cliente, endereço e as informações adicionais anotadas pelo técnico.',
    comoUsar: [
      {
        titulo: 'Consultar e corrigir',
        passos: [
          'Leia o bloco "Detalhes do Avistamento" com praga, data, severidade e ordem de serviço.',
          'Role até "Informações Adicionais" para ver localização, condições ambientais, medidas aplicadas e observações.',
          'Clique em "Editar Avistamento" se algo precisar de ajuste.',
          'Clique em "Voltar à Lista" para retornar.',
        ],
      },
    ],
    relacionados: [
      { titulo: 'Avistamentos de pragas', href: '/pest-sightings' },
      { titulo: 'Endereços', href: '/addresses' },
    ],
  },

  'Enderecos/PendenciasGeo': {
    area: 'Clientes e locais',
    titulo: 'Pendências de coordenada',
    paraQueServe: 'Lista os endereços que ficaram sem coordenada no mapa ou cuja localização só foi encontrada no nível da cidade. Corrigir essas pendências é o que faz o endereço entrar corretamente no roteiro dos técnicos.',
    comoUsar: [
      {
        titulo: 'Corrigir arrastando o marcador',
        passos: [
          'Localize o endereço na lista e confira a etiqueta amarela com o motivo da pendência.',
          'No mapa do card, arraste o marcador até o ponto exato do local.',
          'Solte o marcador — a coordenada é salva automaticamente.',
          'O endereço sai da lista assim que a coordenada é aceita.',
        ],
      },
      {
        titulo: 'Informar a coordenada manualmente',
        passos: [
          'Digite os valores nos campos "Latitude" e "Longitude".',
          'Clique em "Salvar coordenada".',
          'Se aparecer uma mensagem vermelha, corrija os valores e tente de novo.',
        ],
      },
      {
        titulo: 'Deixar o sistema tentar de novo',
        passos: [
          'Clique em "Tentar geocodificar de novo" para o sistema buscar a localização a partir do endereço cadastrado.',
          'Se ainda assim não houver coordenada confiável, o card avisa e você ajusta à mão.',
          'Se o endereço estiver escrito errado, corrija-o na tela de edição do endereço antes de tentar de novo.',
        ],
      },
    ],
    campos: [
      { nome: 'Sem coordenada', descricao: 'O sistema não conseguiu localizar o endereço no mapa. O marcador começa no centro do Brasil, só como ponto de partida.' },
      { nome: 'Só no nível da cidade', descricao: 'A localização encontrada aponta a cidade, não a rua. Precisa de ajuste manual para servir ao roteiro.' },
    ],
    dicas: [
      'Use os botões "Anterior" e "Próximo" do rodapé para percorrer as demais páginas de pendências.',
      'Clique em "Voltar ao roteiro" quando terminar, para ver o efeito das correções no planejamento das visitas.',
    ],
    relacionados: [
      { titulo: 'Endereços', href: '/addresses' },
    ],
  },
};
