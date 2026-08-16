/**
 * Manual de uso da área de administração da plataforma.
 *
 * Público: o administrador da plataforma (dono do SaaS), que gerencia as
 * empresas assinantes, os planos comerciais e os módulos do produto.
 */
export default {
  'Plataforma/Dashboard': {
    area: 'Administração da plataforma',
    titulo: 'Visão geral',
    paraQueServe: 'Reúne os números da plataforma inteira em uma tela só: quantas empresas assinantes existem, em que situação estão, quantas entraram por mês e quais merecem atenção agora.',
    comoUsar: [
      {
        titulo: 'Ler a situação da base',
        passos: [
          'Confira os quatro cartões do topo: "Total de tenants", "Ativos", "Suspensos" e "Em avaliação".',
          'No cartão "Total de tenants", veja abaixo do número quantas empresas entraram nos últimos 30 dias.',
          'Use o gráfico "Novos tenants por mês" para comparar o ritmo de entrada mês a mês.',
          'Olhe o cartão "Armazenamento total" no rodapé para saber o consumo somado da base.',
        ],
      },
      {
        titulo: 'Agir sobre as listas de atenção',
        passos: [
          'Abra a lista "Sem acesso recente" para ver quem não entra no sistema há mais de 30 dias.',
          'Abra a lista "Avaliação perto do fim" para ver quem tem o período de avaliação encerrando nos próximos 7 dias.',
          'Clique no nome da empresa em qualquer uma das duas listas — você cai direto na tela de uso dela.',
          'Na tela de uso, decida se é caso de contato comercial, troca de plano ou nada.',
        ],
      },
    ],
    campos: [
      { nome: 'Em avaliação', descricao: 'Empresas que foram cadastradas com uma data de fim de período de avaliação e ainda estão dentro dele.' },
      { nome: 'Sem acesso recente', descricao: 'Empresas cujo último acesso registrado tem mais de 30 dias. Quem nunca entrou aparece como "nunca acessou".' },
      { nome: 'Armazenamento total', descricao: 'Soma da apuração mais recente de cada empresa. Acima de 1024 MB o valor é mostrado em GB.' },
    ],
    dicas: [
      'O último acesso é registrado uma vez por sessão de login, não a cada clique: use-o como sinal de abandono, não como medida fina de atividade.',
      'A lista de avaliação perto do fim é o melhor gatilho para cobrança comercial — depois que a avaliação vence, a conversa fica mais difícil.',
    ],
    relacionados: [
      { titulo: 'Empresas assinantes', href: '/plataforma/tenants' },
      { titulo: 'Receita', href: '/plataforma/receita' },
      { titulo: 'Planos', href: '/plataforma/planos' },
    ],
  },

  'Plataforma/Receita': {
    area: 'Administração da plataforma',
    titulo: 'Receita',
    paraQueServe: 'Mostra o dinheiro da plataforma: quantas assinaturas estão ativas, em atraso ou suspensas, quanto entra por mês de forma recorrente e quais faturas estão em aberto.',
    comoUsar: [
      {
        titulo: 'Acompanhar a saúde financeira',
        passos: [
          'Leia os cartões "Assinaturas ativas", "Em atraso" e "Suspensas" para ver a distribuição da base.',
          'Confira "Receita recorrente mensal" para o valor que entra hoje com as assinaturas ativas.',
          'Confira "Total em aberto" e, logo abaixo do valor, a quantidade de faturas que compõem esse total.',
          'Use o gráfico "Cancelamentos nos últimos 6 meses" para enxergar tendência de saída.',
        ],
      },
      {
        titulo: 'Tratar faturas em aberto',
        passos: [
          'Desça até a tabela "Faturas em aberto" — ela já vem ordenada da mais atrasada para a menos atrasada.',
          'Repare nas linhas em vermelho: são as que passaram do prazo de tolerância informado no cabeçalho da tabela.',
          'Confira a coluna "Atraso" para saber há quantos dias a fatura está vencida.',
          'Clique no nome na coluna "Empresa" para abrir o cadastro dela e tratar o caso.',
        ],
      },
    ],
    campos: [
      { nome: 'Receita recorrente mensal', descricao: 'Valor mensal somado das assinaturas ativas. A empresa interna não entra nessa conta.' },
      { nome: 'Situação (da fatura)', descricao: '"Aberta" é fatura ainda dentro do prazo; "Vencida" é fatura que passou da data de vencimento.' },
      { nome: 'Atraso', descricao: 'Dias corridos desde o vencimento. Fatura ainda no prazo aparece como "em dia".' },
    ],
    dicas: [
      'A tolerância mostrada no cabeçalho da tabela é o mesmo prazo usado pela régua de inadimplência: chegando nele, a empresa é suspensa por falta de pagamento.',
      'A linha vermelha é um aviso de que a suspensão já está no ponto de acontecer — trate antes de o cliente perder o acesso.',
    ],
    atencao: [
      'Empresa suspensa por inadimplência perde o acesso ao sistema inteiro até o pagamento entrar. Nenhum dado é apagado, mas a operação dela para.',
      'O gráfico desta tela mostra apenas cancelamentos; não existe aqui a série histórica da receita recorrente, só o valor de hoje.',
    ],
    relacionados: [
      { titulo: 'Empresas assinantes', href: '/plataforma/tenants' },
      { titulo: 'Visão geral', href: '/plataforma' },
    ],
  },

  'Plataforma/Tenants/Index': {
    area: 'Administração da plataforma',
    titulo: 'Empresas assinantes',
    paraQueServe: 'Lista todas as empresas cadastradas na plataforma e é o ponto de partida para cadastrar, editar, consultar uso, ajustar módulos, suspender, reativar ou entrar em uma empresa como suporte.',
    comoUsar: [
      {
        titulo: 'Encontrar uma empresa',
        passos: [
          'Digite nome ou CNPJ no campo "Buscar" — a lista se atualiza sozinha enquanto você digita.',
          'Restrinja pelo campo "Situação" para ver só ativas, em avaliação, suspensas ou canceladas.',
          'Restrinja pelo campo "Plano" para ver só quem assina determinado plano.',
          'Clique nos títulos "Tenant", "Entrada" ou "Último acesso" para ordenar por essa coluna; clique de novo para inverter a ordem.',
        ],
      },
      {
        titulo: 'Usar as ações de cada linha',
        passos: [
          'No fim da linha, use o ícone de lápis para abrir a edição do cadastro.',
          'Use o ícone de gráfico para abrir o uso da empresa contra os limites do plano.',
          'Use o ícone de blocos para abrir os módulos daquela empresa.',
          'Use o ícone de seta para "Assumir" a empresa e operar dentro dela como suporte.',
        ],
      },
      {
        titulo: 'Suspender e reativar',
        passos: [
          'Clique no ícone vermelho de suspender na linha da empresa.',
          'Escreva o motivo no campo "Motivo" — ele é obrigatório e fica registrado.',
          'Clique em "Suspender" para confirmar, ou em "Cancelar" para desistir.',
          'Para devolver o acesso, clique no ícone verde de reativar, que aparece só nas empresas suspensas — a reativação é imediata e apaga o motivo registrado.',
        ],
      },
    ],
    campos: [
      { nome: 'Interno', descricao: 'Selo da empresa da própria plataforma. Ela não pode ser suspensa por caminho nenhum.' },
      { nome: 'Situação', descricao: '"Ativa", "Em avaliação", "Suspensa" ou "Cancelada". Só as ações de suspender e reativar mudam esse valor.' },
      { nome: 'Último acesso', descricao: 'Data e hora do último login registrado na empresa, ou "Nunca" se ninguém entrou ainda.' },
    ],
    dicas: [
      'Os filtros continuam valendo quando você troca de página na lista — não é preciso refazer a busca.',
      'Reativar não precisa de confirmação porque não é destrutivo; suspender sempre pede motivo.',
      'Ao assumir uma empresa você passa a ver os dados reais de clientes dela, e a entrada fica registrada em auditoria.',
    ],
    atencao: [
      'Suspender tira o acesso da empresa ao sistema inteiro na hora. Nenhum dado é apagado e tudo volta inteiro ao reativar, mas a operação dela para até lá.',
      'O motivo da suspensão aparece para quem for reativar depois — escreva algo que outra pessoa entenda.',
      'A exclusão de empresa não existe nesta área, de propósito. Para tirar uma empresa de circulação, suspenda-a.',
    ],
    relacionados: [
      { titulo: 'Planos', href: '/plataforma/planos' },
      { titulo: 'Módulos', href: '/plataforma/modulos' },
      { titulo: 'Visão geral', href: '/plataforma' },
    ],
  },

  'Plataforma/Tenants/Create': {
    area: 'Administração da plataforma',
    titulo: 'Cadastrar empresa assinante',
    paraQueServe: 'Cria uma nova empresa na plataforma junto com o primeiro usuário administrador dela, já vinculada a um plano e, se for o caso, a um período de avaliação.',
    comoUsar: [
      {
        titulo: 'Preencher o cadastro',
        passos: [
          'Em "Dados da empresa", informe "Nome / Razão social" — é o único campo obrigatório do bloco.',
          'Complete CNPJ, e-mail e telefone quando tiver os dados.',
          'Preencha o bloco "Endereço" se precisar dele para contrato ou cobrança.',
          'Em "Plano e avaliação", escolha o plano no campo "Plano" ou deixe "Sem plano".',
          'Preencha "Fim do período de avaliação" se a empresa vai começar em teste; deixe em branco para ela nascer ativa.',
        ],
      },
      {
        titulo: 'Criar o administrador e salvar',
        passos: [
          'Em "Administrador do tenant", informe "Nome" e "E-mail" da pessoa que vai administrar a empresa.',
          'Confira o e-mail com atenção: é por ele que essa pessoa vai recuperar a senha para entrar.',
          'Clique em "Criar tenant" para salvar, ou em "Cancelar" para voltar sem gravar nada.',
          'Avise o administrador para usar a recuperação de senha na tela de login — o sistema não envia e-mail de boas-vindas nesta etapa.',
        ],
      },
    ],
    campos: [
      { nome: 'Fim do período de avaliação', descricao: 'Com data preenchida a empresa nasce "Em avaliação"; em branco, nasce "Ativa".' },
      { nome: 'Plano', descricao: 'Define os limites de uso e quais módulos a empresa enxerga. Sem plano, ela fica só com os módulos sempre ativos.' },
      { nome: 'Administrador do tenant', descricao: 'Só aparece na criação. O usuário nasce com uma senha aleatória e entra pela recuperação de senha.' },
    ],
    dicas: [
      'Empresa cadastrada sem plano funciona, mas enxerga apenas clientes, ordens de serviço e certificados — vincule um plano assim que possível.',
      'O bloco do administrador não existe na edição: depois de criada, a gestão de usuários é feita dentro da própria empresa.',
    ],
    atencao: [
      'Escolher o plano aqui já define o que a empresa vai ver no menu desde o primeiro acesso.',
      'A empresa criada não pode ser excluída depois. Confira nome e CNPJ antes de salvar.',
    ],
    relacionados: [
      { titulo: 'Empresas assinantes', href: '/plataforma/tenants' },
      { titulo: 'Planos', href: '/plataforma/planos' },
    ],
  },

  'Plataforma/Tenants/Edit': {
    area: 'Administração da plataforma',
    titulo: 'Editar empresa assinante',
    paraQueServe: 'Corrige os dados cadastrais de uma empresa já existente e é também o caminho para trocar o plano contratado por ela.',
    comoUsar: [
      {
        titulo: 'Corrigir o cadastro',
        passos: [
          'Ajuste os campos dos blocos "Dados da empresa" e "Endereço".',
          'Clique em "Salvar alterações" para gravar, ou em "Cancelar" para sair sem gravar.',
          'Use "Ver tenant" no topo para conferir a ficha completa depois de salvar.',
        ],
      },
      {
        titulo: 'Trocar o plano contratado',
        passos: [
          'Vá ao bloco "Plano e avaliação".',
          'Escolha o novo plano no campo "Plano" — ou "Sem plano" para desvincular.',
          'Clique em "Salvar alterações".',
          'Abra os módulos da empresa em seguida para conferir o que passou a ficar ativo ou inativo com o novo plano.',
        ],
      },
    ],
    campos: [
      { nome: 'Fim do período de avaliação', descricao: 'Editar essa data aqui não coloca nem tira a empresa da situação "Em avaliação" — a situação só muda pelas ações de suspender e reativar.' },
    ],
    dicas: [
      'O bloco do administrador não aparece na edição: ele só existe no momento da criação da empresa.',
      'Salvar o cadastro nunca reativa uma empresa suspensa — ela continua suspensa até você usar a ação de reativar na lista.',
    ],
    atencao: [
      'Trocar o plano muda na hora quais módulos a empresa enxerga: um módulo que o plano novo não libera some do menu dela imediatamente.',
      'Rebaixar o plano não apaga nada. Clientes e ordens de serviço acima do novo limite continuam existindo; o teto passa a valer para o que for criado dali em diante.',
      'A troca de plano também muda os limites de uso, e o limite de usuários é o único que chega a bloquear a criação de novos registros.',
    ],
    relacionados: [
      { titulo: 'Empresas assinantes', href: '/plataforma/tenants' },
      { titulo: 'Planos', href: '/plataforma/planos' },
      { titulo: 'Módulos', href: '/plataforma/modulos' },
    ],
  },

  'Plataforma/Tenants/Show': {
    area: 'Administração da plataforma',
    titulo: 'Ficha da empresa assinante',
    paraQueServe: 'Mostra em uma página só o cadastro completo da empresa, a situação atual, o consumo do mês corrente e o histórico de uso apurado.',
    comoUsar: [
      {
        titulo: 'Conferir o cadastro',
        passos: [
          'Leia o selo de situação no topo do primeiro cartão: "Ativa", "Em avaliação", "Suspensa" ou "Cancelada".',
          'Se a empresa estiver suspensa, o motivo registrado aparece ao lado do selo.',
          'Confira CNPJ, e-mail, telefone, plano, data de entrada, último acesso e endereço na lista de dados.',
          'Clique em "Editar" no topo para corrigir qualquer um desses dados.',
        ],
      },
      {
        titulo: 'Avaliar o consumo',
        passos: [
          'Veja o bloco "Uso do mês corrente" para usuários, clientes, ordens de serviço, certificados e armazenamento.',
          'Desça até "Histórico de uso apurado" para comparar os meses anteriores.',
          'Clique em "Ver uso completo" para abrir a tela de uso, com o percentual gasto de cada limite do plano.',
        ],
      },
    ],
    campos: [
      { nome: 'Motivo da suspensão', descricao: 'Texto escrito por quem suspendeu a empresa. Some quando a empresa é reativada.' },
      { nome: 'Interno', descricao: 'Selo da empresa da própria plataforma, que não pode ser suspensa.' },
      { nome: 'Referência', descricao: 'Mês a que se refere aquela linha do histórico de uso.' },
    ],
    dicas: [
      'O bloco de histórico só aparece quando já existe pelo menos uma apuração fechada para essa empresa.',
      'Esta tela mostra os números do consumo; para ver quanto disso já foi do teto do plano, abra o uso completo.',
    ],
    relacionados: [
      { titulo: 'Empresas assinantes', href: '/plataforma/tenants' },
      { titulo: 'Planos', href: '/plataforma/planos' },
    ],
  },

  'Plataforma/Tenants/Uso': {
    area: 'Administração da plataforma',
    titulo: 'Uso da empresa assinante',
    paraQueServe: 'Compara o consumo do mês corrente com os limites do plano contratado, mostrando o percentual gasto de cada teto e o histórico das apurações anteriores.',
    comoUsar: [
      {
        titulo: 'Ler o consumo contra o plano',
        passos: [
          'Confira no topo da tela qual plano a empresa assina e a periodicidade dele.',
          'No bloco "Uso no mês corrente", leia cada barra: usuários ativos, clientes, ordens de serviço do mês e armazenamento.',
          'Barra verde é consumo confortável, amarela é sinal de aproximação do teto e vermelha é limite estourado.',
          'Quando o limite estoura, a linha "Excedente" mostra quanto passou do teto do plano.',
          'Métrica sem teto no plano aparece como "ilimitado", sem barra.',
        ],
      },
      {
        titulo: 'Decidir sobre a troca de plano',
        passos: [
          'Use o histórico no rodapé para ver se o estouro é pontual ou vem se repetindo mês a mês.',
          'Clique em "Trocar plano" no topo para abrir a edição da empresa já no caminho da mudança.',
          'Escolha o novo plano e salve.',
          'Volte a esta tela para conferir os percentuais recalculados contra os novos limites.',
        ],
      },
    ],
    campos: [
      { nome: 'Certificados emitidos no mês', descricao: 'Aparece como número puro, sem barra: o plano não tem limite de certificados.' },
      { nome: 'Ordens de serviço (mês)', descricao: 'Contagem do mês corrente, comparada ao limite de OS por mês do plano.' },
      { nome: 'Armazenamento', descricao: 'Espaço consumido pelos arquivos da empresa. Acima de 1024 MB o valor é mostrado em GB.' },
    ],
    dicas: [
      'Estourar limite de clientes, ordens de serviço ou armazenamento não trava a operação da empresa: gera aviso, não bloqueio.',
      'O limite de usuários é o único que impede de fato a criação de mais registros — é o que mais gera chamado de suporte.',
      'Empresa sem plano vinculado mostra tudo como ilimitado, porque não há teto com o que comparar.',
    ],
    relacionados: [
      { titulo: 'Empresas assinantes', href: '/plataforma/tenants' },
      { titulo: 'Planos', href: '/plataforma/planos' },
    ],
  },

  'Plataforma/Planos/Index': {
    area: 'Administração da plataforma',
    titulo: 'Planos',
    paraQueServe: 'Mantém o catálogo de planos comerciais da plataforma: valor, periodicidade, limites de uso e quais módulos cada plano libera para quem o assina.',
    comoUsar: [
      {
        titulo: 'Criar um plano',
        passos: [
          'Clique em "Novo plano" no topo da tela.',
          'Preencha "Nome", "Slug", "Valor (R$)" e escolha a "Periodicidade" entre mensal e anual.',
          'Informe os limites de usuários, clientes, OS por mês e armazenamento — deixe em branco o que for ilimitado.',
          'Ajuste "Ordem de exibição" e "Descrição" se quiser, e mantenha "Plano ativo" marcado.',
          'Clique em "Salvar".',
        ],
      },
      {
        titulo: 'Ajustar um plano existente',
        passos: [
          'Use o campo "Buscar por nome ou slug" e clique em "Filtrar" para achar o plano.',
          'Clique em "Editar" na linha dele.',
          'Altere o que precisar e clique em "Salvar".',
          'Para tirar um plano de circulação sem apagá-lo, desmarque "Plano ativo" e salve.',
        ],
      },
      {
        titulo: 'Definir os módulos do plano',
        passos: [
          'Clique em "Módulos" na linha do plano.',
          'Marque na lista o que esse plano libera e desmarque o que ele não inclui.',
          'Clique em "Salvar" e confirme na janela, que informa quantas empresas serão afetadas.',
        ],
      },
    ],
    campos: [
      { nome: 'Slug', descricao: 'Identificador curto do plano, sem espaços e sem acento (ex.: plano-profissional). Usado internamente para referenciar o plano.' },
      { nome: 'Limites', descricao: 'Teto de usuários, clientes, OS por mês e armazenamento. Campo em branco significa ilimitado, e a lista mostra "Ilimitado".' },
      { nome: 'Tenants', descricao: 'Quantas empresas assinam esse plano hoje.' },
      { nome: 'Status', descricao: '"Ativo" é plano visível para contratação; "Inativo" continua valendo para quem já assina, mas sai de circulação.' },
    ],
    dicas: [
      'Prefira desativar a excluir: o plano inativo preserva o histórico e continua funcionando para quem já assina.',
      'A "Ordem de exibição" controla a posição do plano nas listagens — use números crescentes do mais barato ao mais caro.',
    ],
    atencao: [
      'Plano com empresas vinculadas não pode ser excluído; o sistema recusa a exclusão e orienta a desativar.',
      'Alterar os limites de um plano vale imediatamente para todas as empresas que o assinam.',
      'Mudar os módulos de um plano tira ou devolve o acesso a áreas inteiras do sistema para todas as empresas dele, na hora. Nenhum dado é apagado, mas o menu delas muda.',
    ],
    relacionados: [
      { titulo: 'Módulos', href: '/plataforma/modulos' },
      { titulo: 'Empresas assinantes', href: '/plataforma/tenants' },
    ],
  },

  'Plataforma/Modulos/Index': {
    area: 'Administração da plataforma',
    titulo: 'Módulos',
    paraQueServe: 'Mostra o catálogo de módulos controláveis do produto e como cada um está distribuído: por quantos planos é liberado e para quantas empresas está ativo hoje.',
    comoUsar: [
      {
        titulo: 'Entender a distribuição',
        passos: [
          'Leia a tabela "Catálogo de módulos": cada linha traz o nome do módulo e o identificador dele logo abaixo.',
          'Confira a coluna "Sempre ativo" para saber quais módulos ninguém pode desligar.',
          'Compare "Planos que liberam" com "Tenants ativos" para achar módulo vendido e pouco distribuído, ou o contrário.',
        ],
      },
      {
        titulo: 'Ir para a edição',
        passos: [
          'Para mudar o que um plano inclui, clique em "Ver planos" e use a ação "Módulos" na linha do plano.',
          'Para abrir uma exceção só para uma empresa, clique em "Ver tenants" e use a ação "Módulos" na linha dela.',
          'Prefira sempre ajustar pelo plano; use a exceção por empresa apenas para casos individuais.',
        ],
      },
    ],
    campos: [
      { nome: 'Sempre ativo', descricao: 'Módulo que vale para toda empresa, inclusive as sem plano, e que não pode ser desmarcado de plano nenhum nem bloqueado individualmente. É o caso de clientes, ordens de serviço e certificados.' },
      { nome: 'Planos que liberam', descricao: 'Quantos planos do catálogo incluem esse módulo.' },
      { nome: 'Tenants ativos', descricao: 'Para quantas empresas o módulo está realmente ativo hoje, já contando os módulos sempre ativos e as exceções individuais.' },
    ],
    dicas: [
      'Esta tela é só de consulta: nenhum módulo é criado, editado ou excluído aqui.',
      'Um módulo pode aparecer com muitos "Tenants ativos" e poucos "Planos que liberam" quando ele é sempre ativo ou quando existem muitas liberações individuais.',
    ],
    relacionados: [
      { titulo: 'Planos', href: '/plataforma/planos' },
      { titulo: 'Empresas assinantes', href: '/plataforma/tenants' },
    ],
  },

  'Plataforma/Modulos/PorPlano': {
    area: 'Administração da plataforma',
    titulo: 'Módulos do plano',
    paraQueServe: 'Define quais módulos um plano libera para todas as empresas que o assinam. É aqui que se monta o pacote comercial de cada plano.',
    comoUsar: [
      {
        titulo: 'Montar o pacote do plano',
        passos: [
          'Confira no topo de qual plano você está editando os módulos.',
          'Marque na lista os módulos que esse plano deve incluir.',
          'Desmarque os que não fazem parte dele — os marcados como "Sempre ativo" ficam travados e não podem ser desmarcados.',
          'Clique em "Salvar".',
          'Leia a janela de confirmação: ela informa quantas empresas serão afetadas a partir de agora. Confirme em "Salvar" ou desista em "Cancelar".',
        ],
      },
      {
        titulo: 'Conferir o resultado',
        passos: [
          'Depois de salvar, leia a mensagem no topo com a quantidade de empresas afetadas.',
          'Abra os módulos de uma dessas empresas para conferir a origem de cada módulo dela.',
          'Se alguma empresa precisar de tratamento diferente do plano, abra uma exceção individual em vez de mudar o plano de novo.',
          'Clique em "Voltar" para retornar à lista de planos.',
        ],
      },
    ],
    campos: [
      { nome: 'Sempre ativo', descricao: 'Clientes, ordens de serviço e certificados. O sistema recusa qualquer tentativa de salvar o plano sem eles.' },
    ],
    dicas: [
      'A hierarquia é: o plano define a base, e a exceção por empresa sobrescreve essa base só naquela empresa.',
      'Mexer no plano é a forma certa de tratar uma regra comercial que vale para todo mundo; a exceção individual é para o caso isolado.',
    ],
    atencao: [
      'A alteração vale imediatamente para todas as empresas do plano: o módulo desmarcado some do menu delas na mesma hora.',
      'Nenhum dado é apagado ao desmarcar um módulo. O que a empresa já cadastrou continua guardado e reaparece inteiro se o módulo for liberado de novo.',
      'Empresas com exceção individual para um módulo não seguem o que você marcar aqui — a exceção continua vencendo o plano.',
    ],
    relacionados: [
      { titulo: 'Planos', href: '/plataforma/planos' },
      { titulo: 'Módulos', href: '/plataforma/modulos' },
    ],
  },

  'Plataforma/Modulos/PorTenant': {
    area: 'Administração da plataforma',
    titulo: 'Módulos da empresa',
    paraQueServe: 'Mostra quais módulos estão ativos para uma empresa específica e de onde vem cada decisão, permitindo liberar ou bloquear um módulo pontualmente, fora do que o plano dela define.',
    comoUsar: [
      {
        titulo: 'Ler a situação atual',
        passos: [
          'Confira no topo qual plano a empresa assina.',
          'Na tabela, veja a coluna "Situação" para saber se o módulo está "Ativo" ou "Inativo".',
          'Leia a coluna "Origem" para entender por quê: "Sempre ativo", "Pelo plano", "Fora do plano", "Liberado pontualmente" ou "Bloqueado pontualmente".',
          'Nas exceções, a origem mostra ainda o motivo do bloqueio ou a data até quando a liberação vale.',
        ],
      },
      {
        titulo: 'Liberar um módulo fora do plano',
        passos: [
          'Clique em "Liberar" na linha do módulo.',
          'Preencha "Liberado até" se a liberação tiver prazo; em branco, ela vale sem prazo.',
          'Escreva o "Motivo" se quiser deixar registrado — aqui ele é opcional.',
          'Clique em "Liberar". O módulo passa a ficar ativo na hora, mesmo que o plano não o inclua.',
        ],
      },
      {
        titulo: 'Bloquear um módulo que o plano libera',
        passos: [
          'Clique em "Bloquear" na linha do módulo.',
          'Escreva o "Motivo" — aqui ele é obrigatório, para quem for reativar depois saber o porquê.',
          'Clique em "Bloquear". O módulo some do sistema da empresa imediatamente.',
        ],
      },
      {
        titulo: 'Desfazer uma exceção',
        passos: [
          'Localize a linha cuja origem é "Liberado pontualmente" ou "Bloqueado pontualmente".',
          'Clique em "Voltar ao plano".',
          'A exceção é removida na hora e o acesso volta a ser decidido só pelo plano da empresa.',
        ],
      },
    ],
    campos: [
      { nome: 'Origem', descricao: 'A regra que está decidindo o acesso. A ordem de prioridade é: sempre ativo vence tudo, depois o bloqueio pontual, depois a liberação pontual e, por último, o plano.' },
      { nome: 'Liberado até', descricao: 'Data em que a liberação pontual deixa de valer. Precisa ser uma data futura. Vencido o prazo, o acesso volta a valer só pelo plano.' },
      { nome: 'Fora do plano', descricao: 'Módulo inativo simplesmente porque o plano da empresa não o inclui e não existe exceção para ele.' },
    ],
    dicas: [
      'Bloqueio pontual vence a liberação do plano; liberação pontual vence a ausência do plano. Quem manda é sempre a exceção, não o plano.',
      'Use "Liberado até" para cortesia comercial com prazo — depois da data o acesso se fecha sozinho, sem ninguém precisar lembrar.',
      'Se a regra vale para todos os clientes daquele plano, ajuste o plano em vez de repetir exceções empresa por empresa.',
    ],
    atencao: [
      'Bloquear um módulo tira imediatamente do menu da empresa uma área inteira do sistema. Nenhum dado é apagado, e tudo reaparece ao liberar de novo.',
      'Módulos sempre ativos não têm ações disponíveis: clientes, ordens de serviço e certificados nunca ficam indisponíveis para uma empresa.',
      'Enquanto existir uma exceção aqui, mudar os módulos do plano não terá efeito nesta empresa. Use "Voltar ao plano" para devolvê-la à regra geral.',
    ],
    relacionados: [
      { titulo: 'Empresas assinantes', href: '/plataforma/tenants' },
      { titulo: 'Módulos', href: '/plataforma/modulos' },
      { titulo: 'Planos', href: '/plataforma/planos' },
    ],
  },
};
