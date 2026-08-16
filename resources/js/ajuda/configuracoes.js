export default {
  'Settings/Company': {
    area: 'Configurações',
    titulo: 'Configurações da empresa',
    paraQueServe: 'Guarda os dados da sua empresa, as licenças, a logomarca e as assinaturas usadas nos documentos. É daqui que o sistema tira o cabeçalho e o rodapé do certificado e do orçamento em PDF.',
    comoUsar: [
      {
        titulo: 'Atualizar os dados gerais e o endereço',
        passos: [
          'Preencha "Razão Social", que é obrigatória, e complete "CNPJ", "Email" e "Telefone".',
          'No bloco "Endereço", digite o CEP e aguarde a busca automática preencher rua, bairro, cidade e estado.',
          'Complete "Número" e "Complemento", que a busca por CEP não traz.',
          'Clique em "Salvar Configurações" no fim da página.',
        ],
      },
      {
        titulo: 'Informar licenças e registros',
        passos: [
          'Abra o bloco "Informações Legais e Técnicas".',
          'Preencha "Licença Ambiental", "Alvará Sanitário" e "Alvará de Funcionamento" com o número de cada documento.',
          'Preencha "Registro VISA" e "Registro no CREA".',
          'Escreva em "Informações (Rodapé)" a mensagem de emergência toxicológica que deve sair no rodapé dos documentos.',
          'Clique em "Salvar Configurações".',
        ],
      },
      {
        titulo: 'Enviar logomarca e assinaturas',
        passos: [
          'No bloco "Imagens e Assinaturas", clique em escolher arquivo abaixo de "Logomarca da Empresa" e selecione a imagem.',
          'Em "Gerente Operacional", preencha "Cargo / Título" e "Nome Completo" e envie a imagem da assinatura.',
          'Em "Responsável Técnico", faça o mesmo: cargo, nome completo e imagem da assinatura.',
          'Envie a imagem de "Assinatura Responsável Orçamento", usada nos orçamentos.',
          'Confira a pré-visualização de cada imagem e clique em "Salvar Configurações".',
        ],
      },
    ],
    campos: [
      { nome: 'Informações (Rodapé)', descricao: 'Texto livre impresso no rodapé dos documentos, normalmente a mensagem padrão de emergência toxicológica.' },
      { nome: 'Cargo / Título', descricao: 'Como o cargo aparece embaixo da assinatura no documento. Vem preenchido com "Gerente Operacional" e "Responsável Técnico", e pode ser alterado.' },
      { nome: 'Logomarca da Empresa', descricao: 'Imagem exibida no cabeçalho dos documentos gerados em PDF.' },
    ],
    dicas: [
      'Use imagens de assinatura com fundo transparente ou branco: elas são impressas em cima do documento, sem tratamento.',
      'Deixar um campo de imagem sem escolher arquivo mantém a imagem que já estava salva.',
    ],
    atencao: [
      'Estes dados saem impressos no certificado e no orçamento em PDF. Depois de qualquer alteração, gere um documento de teste e confira o resultado antes de entregar ao cliente.',
      'Documento já emitido é gerado com os dados atuais da empresa: corrigir um número de licença aqui muda o que aparece na próxima impressão daquele documento.',
      'As validades de licenças e do registro do responsável técnico não ficam nesta tela — são configuradas em "Validades dos documentos".',
    ],
    relacionados: [
      { titulo: 'Validades dos documentos', href: '/settings/validades' },
      { titulo: 'Usuários', href: '/settings/users' },
      { titulo: 'Disponibilidade e agendamento online', href: '/settings/disponibilidade' },
    ],
  },

  'Settings/Users/Index': {
    area: 'Configurações',
    titulo: 'Usuários',
    paraQueServe: 'Lista quem tem acesso ao sistema na sua empresa e permite criar, editar, ativar e desativar usuários. É aqui que você define o papel de cada pessoa e o vínculo com um técnico.',
    comoUsar: [
      {
        titulo: 'Cadastrar um usuário',
        passos: [
          'Clique em "Novo Usuário" no topo da tela.',
          'Preencha "Nome" e "E-mail", que são obrigatórios.',
          'Defina a "Senha" com no mínimo 8 caracteres — ela é obrigatória para um usuário novo.',
          'Escolha o "Papel", que determina o que a pessoa poderá acessar.',
          'Se a pessoa for do campo, selecione o "Técnico vinculado" correspondente.',
          'Clique em "Salvar".',
        ],
      },
      {
        titulo: 'Editar um usuário existente',
        passos: [
          'Clique no ícone de lápis na linha do usuário.',
          'Altere nome, e-mail, papel ou técnico vinculado conforme a necessidade.',
          'Deixe a "Senha" em branco para manter a senha atual, ou digite uma nova para trocá-la.',
          'Clique em "Salvar" — a mudança de papel vale no próximo carregamento de tela do usuário.',
        ],
      },
      {
        titulo: 'Ativar ou desativar o acesso',
        passos: [
          'Confira a coluna "Status" para ver quem está "Ativo" e quem está "Inativo".',
          'Clique no ícone ao lado do lápis na linha do usuário.',
          'Leia a mensagem de confirmação, que diz o efeito imediato da ação.',
          'Clique em "Sim, desativar" para tirar o acesso, ou em "Sim, ativar" para devolvê-lo.',
        ],
      },
    ],
    campos: [
      { nome: 'Papel', descricao: 'Perfil de acesso da pessoa. Administrador enxerga e configura tudo, Financeiro trabalha com cobranças e recebimentos, Técnico atua nas ordens de serviço do campo. Outros perfis aparecem na lista conforme cadastrados.' },
      { nome: 'Técnico vinculado', descricao: 'Liga o usuário a um cadastro de técnico, para que os atendimentos dele apareçam em nome dessa pessoa. Só aparecem na lista os técnicos ainda sem usuário.' },
      { nome: 'Status', descricao: 'Ativo significa que a pessoa consegue entrar no sistema; Inativo bloqueia o acesso sem apagar o histórico dela.' },
    ],
    dicas: [
      'Prefira desativar a manter uma pessoa com acesso após o desligamento: o histórico de quem fez o quê continua preservado.',
      'Convidar por e-mail costuma ser melhor que criar a senha por aqui, porque a própria pessoa escolhe a senha dela.',
    ],
    atencao: [
      'Desativar um usuário tira o acesso na hora, inclusive se ele estiver com o sistema aberto.',
      'O sistema impede deixar a empresa sem nenhum administrador ativo: não é possível desativar nem mudar o papel do último administrador.',
      'A quantidade de usuários ativos é limitada pelo plano contratado. Se o limite estiver atingido, o cadastro é recusado com uma mensagem indicando o plano.',
    ],
    relacionados: [
      { titulo: 'Convites', href: '/settings/convites' },
      { titulo: 'Técnicos', href: '/technicians' },
      { titulo: 'Dados da empresa', href: '/settings/company' },
    ],
  },

  'Settings/Convites': {
    area: 'Configurações',
    titulo: 'Convites',
    paraQueServe: 'Convida pessoas para acessar o sistema da sua empresa por e-mail e acompanha a situação de cada convite. Quem aceita cria a própria senha e entra já com o papel definido por você.',
    comoUsar: [
      {
        titulo: 'Enviar um convite',
        passos: [
          'Clique em "Novo Convite" no topo da tela.',
          'Informe o "E-mail" da pessoa, que é obrigatório.',
          'Preencha o "Nome" se quiser deixar o convite mais pessoal — é opcional.',
          'Escolha o "Papel" que a pessoa terá ao entrar.',
          'Clique em "Enviar convite".',
        ],
      },
      {
        titulo: 'Acompanhar e entregar o link',
        passos: [
          'Confira a coluna "Situação": Pendente, Aceito, Expirado ou Cancelado, com a data logo abaixo.',
          'Veja em "Convidado por" e "Convidado em" quem enviou e quando.',
          'Se o e-mail não chegar, clique no ícone de copiar na linha do convite e envie o link por outro caminho.',
          'Confirme a cópia pelo ícone verde de confirmação que aparece por alguns segundos.',
        ],
      },
      {
        titulo: 'Reenviar ou cancelar',
        passos: [
          'Clique no ícone de setas circulares para reenviar um convite pendente ou expirado.',
          'Avise a pessoa de que o link anterior deixou de funcionar depois do reenvio.',
          'Clique no ícone de X para cancelar um convite que não deve mais ser aceito.',
          'Confirme em "Sim, cancelar" ou volte atrás em "Voltar".',
        ],
      },
    ],
    campos: [
      { nome: 'Papel', descricao: 'Perfil que a pessoa recebe ao aceitar. Ele vem do convite e não pode ser alterado por quem aceita.' },
      { nome: 'Situação', descricao: 'Pendente é o convite ainda válido; Expirado passou do prazo; Aceito já virou usuário; Cancelado foi encerrado por você.' },
      { nome: 'Link do convite', descricao: 'Endereço de aceite, disponível apenas para convite pendente. Convite resolvido não tem link para copiar.' },
    ],
    dicas: [
      'O convite vale por 7 dias contados a partir do envio; passado o prazo, basta reenviar.',
      'Convite expirado continua na lista para você saber quem foi chamado e não entrou.',
      'Trate o link como uma senha: quem tiver o endereço consegue criar o acesso.',
    ],
    atencao: [
      'Reenviar gera um link novo e invalida o anterior na mesma hora.',
      'Cancelar faz o link parar de funcionar imediatamente, mesmo que a pessoa já o tenha em mãos.',
      'Se o plano já estiver no limite de usuários ativos, o envio do convite é recusado com uma mensagem explicando o limite do plano.',
    ],
    relacionados: [
      { titulo: 'Usuários', href: '/settings/users' },
      { titulo: 'Dados da empresa', href: '/settings/company' },
    ],
  },

  'Settings/Validades': {
    area: 'Configurações',
    titulo: 'Validades dos documentos',
    paraQueServe: 'Concentra o registro do responsável técnico no conselho e as licenças da empresa, cada um com número, data de validade e arquivo digitalizado. É o que alimenta o checklist de conformidade e os avisos de vencimento.',
    comoUsar: [
      {
        titulo: 'Atualizar um documento',
        passos: [
          'Localize o bloco do documento: registro do responsável técnico no conselho, licença sanitária, licença ambiental ou alvará de funcionamento.',
          'Confira a etiqueta de situação ao lado do título: "Vencido", "Vence em breve", "Dentro da validade" ou "Validade não informada".',
          'Preencha "Número do documento" com o número que consta no documento oficial.',
          'Informe a "Validade" no seletor de data.',
          'Clique em "Salvar validades".',
        ],
      },
      {
        titulo: 'Anexar o documento digitalizado',
        passos: [
          'No bloco do documento, clique em escolher arquivo abaixo de "Documento digitalizado".',
          'Selecione um arquivo PDF, JPG ou PNG de até 4 MB.',
          'Clique em "Salvar validades" para gravar o anexo.',
          'Use o link "Ver documento anexado" sempre que precisar consultar o arquivo guardado.',
        ],
      },
      {
        titulo: 'Conferir o resultado na conformidade',
        passos: [
          'Clique em "Ver checklist de conformidade", no topo ou no rodapé da tela.',
          'Confira se o item que você acabou de renovar saiu da lista de irregulares.',
          'Volte às validades para corrigir o que ainda estiver vencido ou sem data.',
        ],
      },
    ],
    campos: [
      { nome: 'Validade', descricao: 'Data em que o documento vence. Campo em branco significa apenas que a data ainda não foi informada, e não irregularidade.' },
      { nome: 'Documento digitalizado', descricao: 'Cópia do documento oficial, para consulta rápida em caso de fiscalização.' },
      { nome: 'Situação', descricao: 'Calculada a partir da validade informada e da data de hoje, com a mesma regra usada no checklist e nos avisos.' },
    ],
    dicas: [
      'Assim que você salva uma data nova, os avisos de vencimento daquele documento param de ser enviados na hora.',
      'Documento sem validade informada não gera aviso de vencimento, apenas o lembrete mensal de cadastro incompleto.',
    ],
    atencao: [
      'Enviar um arquivo novo substitui o arquivo anexado anteriormente naquele documento.',
      'O checklist mostra o que o sistema consegue verificar; ele não substitui a conferência dos documentos originais.',
      'Trabalhar com licença vencida é risco perante a fiscalização: trate os itens marcados como "Vencido" antes de emitir novos documentos.',
    ],
    relacionados: [
      { titulo: 'Checklist de conformidade', href: '/conformidade' },
      { titulo: 'Dados da empresa', href: '/settings/company' },
    ],
  },

  'Settings/Disponibilidade': {
    area: 'Configurações',
    titulo: 'Disponibilidade e agendamento online',
    paraQueServe: 'Define em que dias a empresa atende, quantas visitas cabem em cada período e se os clientes podem pedir horário por uma página pública, sem login.',
    comoUsar: [
      {
        titulo: 'Definir os dias de atendimento',
        passos: [
          'No bloco "Dias de atendimento", clique nos dias em que a empresa atende — o dia selecionado fica verde.',
          'Clique novamente em um dia para tirá-lo da lista.',
          'Clique em "Salvar configuração" no fim da página.',
        ],
      },
      {
        titulo: 'Ajustar capacidade e prazos',
        passos: [
          'Preencha "Visitas por período, por técnico" com quantos atendimentos cada técnico faz por manhã e por tarde.',
          'Informe a "Antecedência mínima (dias)": quanto tempo antes o cliente precisa pedir o horário.',
          'Informe a "Janela máxima (dias)": até quantos dias à frente a agenda fica aberta para o cliente.',
          'Clique em "Salvar configuração".',
        ],
      },
      {
        titulo: 'Publicar a página de agendamento',
        passos: [
          'No bloco "Página pública de agendamento", digite o "Endereço da página" usando apenas letras minúsculas, números e hífen.',
          'Ligue a chave ao lado do título para permitir pedidos de horário sem login.',
          'Clique em "Copiar link" e divulgue o endereço aos clientes.',
          'Clique em "Salvar configuração".',
        ],
      },
    ],
    campos: [
      { nome: 'Visitas por período, por técnico', descricao: 'Teto de atendimentos por manhã e por tarde para cada técnico. Quando o teto é atingido, o período deixa de aparecer como disponível para o cliente.' },
      { nome: 'Antecedência mínima (dias)', descricao: 'Impede que o cliente escolha uma data cedo demais, dando tempo para a empresa se organizar.' },
      { nome: 'Janela máxima (dias)', descricao: 'Limite de quanto tempo à frente o cliente pode agendar.' },
      { nome: 'Endereço da página', descricao: 'Parte final do link público de agendamento, logo depois do endereço fixo mostrado à esquerda do campo.' },
    ],
    dicas: [
      'A grade mostrada ao cliente considera os dias de atendimento, a antecedência, a janela e a capacidade juntos: se nenhum período aparecer livre, revise esses quatro ajustes.',
      'Sem nenhum técnico ativo cadastrado, todos os períodos aparecem como indisponíveis para o cliente.',
    ],
    atencao: [
      'Trocar o endereço da página quebra qualquer link já divulgado: quem tiver o endereço antigo salvo deixa de encontrar a página. O sistema avisa em destaque quando você altera um endereço já em uso.',
      'Desligar a chave tira a página pública do ar imediatamente, e novos pedidos de horário deixam de ser aceitos.',
      'Não é possível ligar o agendamento online sem definir o endereço da página.',
    ],
    relacionados: [
      { titulo: 'Agenda', href: '/agenda' },
      { titulo: 'Dados da empresa', href: '/settings/company' },
    ],
  },

  'Notificacoes/Index': {
    area: 'Configurações',
    titulo: 'Central de notificações',
    paraQueServe: 'Mostra o histórico dos avisos automáticos enviados a clientes, à empresa e aos usuários, com a situação de cada envio e o motivo das falhas. Use para conferir se um cliente foi avisado e para reagir ao que não saiu.',
    comoUsar: [
      {
        titulo: 'Encontrar um envio',
        passos: [
          'Use os atalhos do topo: "Pendentes" para o que ainda vai sair, "Enviadas hoje" para o que já saiu e "Em falha" para o que precisa de atenção.',
          'Para uma busca mais específica, preencha os filtros "Evento", "Situação", "De", "Até" e "Cliente (ID)".',
          'Clique em "Filtrar" para aplicar.',
          'Clique em "Limpar filtros" para voltar à lista completa.',
        ],
      },
      {
        titulo: 'Investigar uma falha',
        passos: [
          'Localize a linha com situação "Falha" e observe a coluna "Tentativas".',
          'Clique na seta no início da linha para abrir o "Histórico de tentativas".',
          'Leia a coluna "Mensagem" para saber o motivo, como endereço inválido ou recusa do provedor.',
          'Corrija o dado do cliente, se for o caso, e clique em "Reenviar" na linha.',
        ],
      },
      {
        titulo: 'Cancelar um aviso pendente',
        passos: [
          'Filtre por situação "Pendente".',
          'Clique em "Cancelar" na linha do aviso que não deve mais ser enviado.',
          'Leia a confirmação, que nomeia o evento e o destinatário.',
          'Clique em "Sim, cancelar".',
        ],
      },
      {
        titulo: 'Falar pelo WhatsApp',
        passos: [
          'Clique em "WhatsApp" na linha do aviso.',
          'Aguarde o sistema montar a mensagem com o texto daquele aviso.',
          'Confira o conteúdo na janela do WhatsApp que abre em outra aba e envie manualmente.',
        ],
      },
    ],
    campos: [
      { nome: 'Destinatário', descricao: 'Quem recebe o aviso: Cliente, Empresa (uso interno) ou Usuário do sistema, com o e-mail ou telefone de destino logo abaixo.' },
      { nome: 'Situação', descricao: 'Pendente aguarda envio; Enviando está em processamento; Enviada saiu com sucesso; Falha não saiu; Cancelada foi interrompida por alguém; Recusada foi rejeitada no destino.' },
      { nome: 'Tentativas', descricao: 'Quantas vezes o sistema já tentou entregar aquele aviso.' },
      { nome: 'Cliente (ID)', descricao: 'Filtro pelo número de identificação do cliente, útil para ver tudo que foi enviado a um cliente específico.' },
    ],
    dicas: [
      '"Reenviar" só aparece em aviso com falha, e "Cancelar" só em aviso pendente — as demais situações já estão encerradas.',
      'Falha temporária costuma resolver sozinha na próxima tentativa; falha permanente indica dado errado no cadastro do destinatário.',
    ],
    atencao: [
      'Cancelar um aviso é definitivo: ele deixa de ser enviado e não há como desfazer.',
      'Reenviar e cancelar exigem permissão de gerenciar notificações; sem ela, a tela fica só para consulta.',
      'O texto enviado é o do template do evento no momento do envio: para mudar o conteúdo, edite o template antes de reenviar.',
    ],
    relacionados: [
      { titulo: 'Templates de notificação', href: '/notificacoes/templates' },
      { titulo: 'Clientes', href: '/clients' },
    ],
  },

  'Notificacoes/Templates': {
    area: 'Configurações',
    titulo: 'Templates de notificação',
    paraQueServe: 'Permite editar o texto dos avisos automáticos que o sistema envia por e-mail e WhatsApp, evento por evento. Use para escrever as mensagens com a linguagem da sua empresa.',
    comoUsar: [
      {
        titulo: 'Escolher o texto a editar',
        passos: [
          'Localize o cartão do evento desejado — cada cartão mostra o destinatário padrão daquele aviso.',
          'Dentro do cartão, veja as linhas de canal: "E-mail" e "WhatsApp".',
          'Repare nas etiquetas: "Personalizado" indica texto seu, "Padrão" indica o texto que veio do sistema e "Inativo" indica canal desligado para aquele evento.',
          'Clique em "Editar" na linha do canal, ou clique na própria linha.',
        ],
      },
      {
        titulo: 'Escrever a mensagem',
        passos: [
          'Preencha o "Assunto" quando o canal for e-mail — o WhatsApp não tem assunto.',
          'Escreva o "Corpo da mensagem".',
          'Clique nas etiquetas do painel "Variáveis disponíveis" para inserir dados que o sistema substitui na hora do envio, como nome do cliente ou data.',
          'Confira o resultado no bloco "Pré-visualização", que usa dados de exemplo.',
          'Clique em "Salvar".',
        ],
      },
      {
        titulo: 'Ligar, desligar ou voltar ao padrão',
        passos: [
          'Marque ou desmarque "Canal ativo para este evento" para ligar ou desligar aquele canal.',
          'Clique em "Voltar ao padrão" quando quiser descartar o texto personalizado e usar o texto original do sistema.',
          'Confirme em "Voltar ao padrão" na janela de confirmação.',
          'Use "Ver histórico de envios" para conferir como a mensagem saiu na prática.',
        ],
      },
    ],
    campos: [
      { nome: 'Variáveis disponíveis', descricao: 'Marcadores substituídos pelos dados reais no momento do envio. Cada evento tem a própria lista.' },
      { nome: 'Canal ativo para este evento', descricao: 'Desmarcado, o sistema deixa de enviar aquele aviso por aquele canal.' },
      { nome: 'Pré-visualização', descricao: 'Mostra como o texto fica preenchido com dados de exemplo. Nenhum envio acontece a partir dela.' },
    ],
    dicas: [
      'Digite ou clique no campo que quiser preencher antes de clicar em uma variável: ela é inserida no campo em edição.',
      'Mensagens de WhatsApp funcionam melhor curtas e sem formatação complexa.',
    ],
    atencao: [
      'Desligar um canal interrompe os avisos daquele evento: o cliente deixa de ser informado, e ninguém é avisado disso.',
      '"Voltar ao padrão" apaga o texto personalizado daquele canal e não pode ser desfeito.',
      'Alterações valem para os próximos envios; avisos já enviados mantêm o texto que saiu na época.',
    ],
    relacionados: [
      { titulo: 'Central de notificações', href: '/notificacoes' },
    ],
  },

  'Auth/Login': {
    area: 'Configurações',
    titulo: 'Entrar no sistema',
    paraQueServe: 'Tela de entrada do Sistema de Certificados. Informe o e-mail e a senha do seu usuário para acessar a área da sua empresa.',
    comoUsar: [
      {
        titulo: 'Fazer login',
        passos: [
          'Digite o seu e-mail no primeiro campo.',
          'Digite a sua senha no segundo campo.',
          'Clique em "Entrar".',
          'Aguarde: enquanto o sistema verifica, o botão mostra "Entrando...".',
        ],
      },
      {
        titulo: 'Resolver problemas de acesso',
        passos: [
          'Leia a mensagem em vermelho abaixo dos campos: ela indica o motivo da recusa.',
          'Confira se o e-mail está escrito exatamente como foi cadastrado.',
          'Se o acesso continuar recusado, peça a um administrador da sua empresa para conferir se o seu usuário está ativo e para redefinir a sua senha.',
        ],
      },
    ],
    dicas: [
      'Quem recebeu um convite por e-mail deve usar o link do convite na primeira vez, e não esta tela: é lá que a senha é criada.',
      'Se a empresa estiver com pagamento em atraso, o login funciona, mas o sistema abre a tela de conta suspensa.',
    ],
    atencao: [
      'Usuário desativado por um administrador não consegue entrar, mesmo com a senha correta.',
    ],
    relacionados: [
      { titulo: 'Criar a conta da empresa', href: '/cadastro' },
    ],
  },

  'Auth/CadastroEmpresa': {
    area: 'Configurações',
    titulo: 'Criar a conta da empresa',
    paraQueServe: 'Cria a conta da sua empresa no sistema e o primeiro usuário administrador, com período de avaliação gratuita e sem cartão de crédito. Use apenas na primeira vez, para abrir a empresa no sistema.',
    comoUsar: [
      {
        titulo: 'Informar os dados da empresa',
        passos: [
          'Preencha "Nome da empresa" com a razão social ou o nome fantasia.',
          'Digite o "CNPJ" — a formatação é aplicada automaticamente.',
          'Digite o "Telefone" de contato da empresa.',
        ],
      },
      {
        titulo: 'Criar o seu acesso de administrador',
        passos: [
          'No bloco "Seus dados", preencha "Seu nome completo".',
          'Informe "Seu e-mail": é com ele que você vai entrar no sistema.',
          'Escolha uma "Senha" com no mínimo 8 caracteres e repita em "Confirme a senha".',
          'Marque a caixa de aceite dos termos de uso e da política de privacidade.',
          'Clique em "Criar conta" — você entra no sistema já autenticado.',
        ],
      },
      {
        titulo: 'Começar a usar',
        passos: [
          'Siga a trilha "Primeiros passos" que aparece no Dashboard.',
          'Complete os dados da empresa, a logomarca e as assinaturas nas configurações.',
          'Convide o restante da equipe pela tela de convites.',
        ],
      },
    ],
    campos: [
      { nome: 'Aceite dos termos', descricao: 'Obrigatório. Sem marcar a caixa, a conta não é criada.' },
    ],
    dicas: [
      'A empresa nasce com um catálogo inicial de cadastros já preenchido, para você não começar do zero.',
      'Quem já tem conta deve usar o link "Entrar", no rodapé da página, em vez de criar outra empresa.',
    ],
    atencao: [
      'O e-mail informado vira o usuário administrador da empresa — é ele que poderá criar e desativar os demais usuários.',
      'O período de avaliação tem prazo, mostrado no topo da página. Depois dele, o acesso depende da contratação de um plano.',
      'Cada empresa é isolada das demais: dados cadastrados aqui não são vistos por nenhuma outra empresa do sistema.',
    ],
    relacionados: [
      { titulo: 'Entrar no sistema', href: '/login' },
    ],
  },

  'Auth/AceitarConvite': {
    area: 'Configurações',
    titulo: 'Aceitar convite',
    paraQueServe: 'Tela aberta pelo link do convite. Quem foi convidado cria aqui o próprio acesso, com nome e senha, e entra direto no sistema da empresa que o convidou.',
    comoUsar: [
      {
        titulo: 'Criar o seu acesso',
        passos: [
          'Confira no topo o nome da empresa que convidou você e o papel que receberá.',
          'Veja até quando o convite vale, logo abaixo dessa informação.',
          'Confirme o "E-mail" mostrado — ele vem do convite e não pode ser alterado.',
          'Preencha "Nome completo".',
          'Escolha uma "Senha" com no mínimo 8 caracteres e repita em "Confirme a senha".',
          'Clique em "Criar acesso e entrar".',
        ],
      },
      {
        titulo: 'Quando o convite não funciona',
        passos: [
          'Se a tela mostrar "Convite indisponível", leia a mensagem: o link pode ter expirado, sido cancelado ou já ter sido usado.',
          'Clique em "Ir para o login" se você já criou o acesso antes.',
          'Peça um novo convite ao administrador da empresa quando o prazo tiver vencido.',
        ],
      },
    ],
    campos: [
      { nome: 'E-mail', descricao: 'Vem preenchido e bloqueado, exatamente como foi convidado. Para usar outro endereço, é preciso um novo convite.' },
      { nome: 'Papel', descricao: 'Definido por quem convidou. Ele determina o que você poderá acessar e não é escolhido nesta tela.' },
    ],
    dicas: [
      'Assim que a senha é criada, o sistema já entra com o seu usuário — não é preciso passar pela tela de login.',
      'O link do convite é pessoal: não o repasse a outra pessoa.',
    ],
    atencao: [
      'O convite tem prazo de validade. Depois dele, o link para de funcionar e é preciso pedir um reenvio.',
      'Se o administrador reenviar o convite, o link antigo deixa de valer: use sempre a mensagem mais recente.',
    ],
    relacionados: [
      { titulo: 'Entrar no sistema', href: '/login' },
    ],
  },
};
