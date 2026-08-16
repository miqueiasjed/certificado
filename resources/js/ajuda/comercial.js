/**
 * Manual de uso da área Comercial: orçamentos, contratos, serviços, tipos de
 * serviço, metas, comissões, satisfação e as páginas públicas.
 *
 * A chave de cada entrada é o nome do componente Inertia da tela (o mesmo
 * valor de `usePage().component`). O formato está descrito em `modelo.js`.
 */
export default {
  'Budgets/Index': {
    area: 'Comercial',
    titulo: 'Orçamentos',
    paraQueServe: 'Lista todos os orçamentos e propostas comerciais da empresa, de rascunho a convertido. É o começo do funil comercial: primeiro o orçamento, depois a aprovação do cliente e só então a ordem de serviço ou o contrato.',
    comoUsar: [
      {
        titulo: 'Encontrar um orçamento',
        passos: [
          'Digite pelo menos 2 caracteres no campo de busca, por nome, CPF ou CNPJ.',
          'A lista filtra sozinha enquanto você digita.',
          'Clique no "x" dentro do campo para limpar a busca e voltar à lista inteira.',
          'Confira a coluna "Status" para saber em que ponto do funil cada orçamento está.',
        ],
      },
      {
        titulo: 'Criar um orçamento',
        passos: [
          'Clique em "Novo Orçamento" no topo da tela.',
          'Preencha o formulário e salve.',
          'O orçamento nasce com status "Rascunho" e aparece no topo da lista.',
        ],
      },
      {
        titulo: 'Agir sobre um orçamento da lista',
        passos: [
          'Use o ícone de documento para abrir o PDF do orçamento em outra aba e enviar ao cliente.',
          'Use o ícone de lápis para editar valores, serviços ou o status.',
          'Use o ícone de lixeira para excluir; uma janela pede confirmação antes.',
        ],
      },
    ],
    campos: [
      { nome: 'Cliente/Prospecto', descricao: 'Mostra o nome do cliente cadastrado ou, quando o orçamento foi feito para alguém que ainda não é cliente, o nome do prospecto com a marca "(Prospecto)".' },
      { nome: 'Status', descricao: 'Rascunho, Enviado, Negociando, Aprovado, Recusado, Expirado ou Convertido. "Convertido" significa que o orçamento já virou ordem de serviço.' },
      { nome: 'Prioridade', descricao: 'Normal ou Urgente, do jeito que foi marcado no formulário.' },
    ],
    dicas: [
      'Mantenha o status em dia: o painel comercial e as metas de conversão são calculados a partir das mudanças de status do orçamento.',
    ],
    relacionados: [
      { titulo: 'Painel comercial', href: '/comercial/indicadores' },
      { titulo: 'Contratos', href: '/contracts' },
      { titulo: 'Serviços', href: '/services' },
    ],
  },

  'Budgets/Create': {
    area: 'Comercial',
    titulo: 'Novo orçamento',
    paraQueServe: 'Monta uma proposta comercial para um cliente já cadastrado ou para um prospecto, com serviços, valores, condições de pagamento e prazo de execução.',
    comoUsar: [
      {
        titulo: 'Escolher para quem é o orçamento',
        passos: [
          'Em "Cliente Cadastrado", busque e selecione o cliente, se ele já existir no sistema.',
          'Escolha o "Endereço do Local" onde o serviço será feito; se o cliente tiver um só, ele já vem selecionado.',
          'Se ainda não for cliente, deixe o campo de cliente vazio e preencha "Nome do Prospecto", "Telefone" e "Endereço".',
        ],
      },
      {
        titulo: 'Descrever o serviço',
        passos: [
          'Informe "Data" e, se quiser, a "Validade" da proposta.',
          'Escolha "Prioridade", "Canais" (por onde o contato chegou) e "Tipo de Ambiente".',
          'Marque as "Pragas Alvo" e as "Áreas a Tratar" nas listas de opções.',
          'Use "Observações" para o que o cliente precisa ler junto da proposta.',
        ],
      },
      {
        titulo: 'Lançar valores',
        passos: [
          'No bloco "Serviços e Valores", clique em "+ Adicionar Serviço" para cada item da proposta.',
          'Escolha o serviço, informe a quantidade e o valor unitário; o subtotal é calculado sozinho.',
          'Se houver abatimento, preencha "Desconto" — o total já sai com ele descontado.',
          'Em "Produtos Utilizados (Estimativa)", clique em "+ Adicionar Produto" para registrar o consumo previsto.',
        ],
      },
      {
        titulo: 'Fechar as condições e salvar',
        passos: [
          'Preencha a "Estimativa Técnica" com o número de técnicos e as horas previstas.',
          'Escolha a "Forma de Pagamento" e escreva as "Condições de Pagamento" e o "Prazo de Execução".',
          'Clique em "Salvar Orçamento".',
        ],
      },
    ],
    campos: [
      { nome: 'Validade', descricao: 'Até quando a proposta vale. Serve de referência na negociação e sai no PDF.' },
      { nome: 'Canais', descricao: 'De onde veio o contato: WhatsApp, Telefone, Site, Presencial, Indicação ou Outros.' },
      { nome: 'Custo Total (Interno)', descricao: 'Bloco informativo do formulário, usado para você pensar a margem. Não há campo a preencher ali.' },
    ],
    atencao: [
      'O orçamento é sempre salvo como "Rascunho". Para marcá-lo como enviado, aprovado ou recusado, abra a edição e mude o status.',
      'O vendedor do orçamento é a pessoa que o criou — é por ela que o painel comercial e a comissão de venda são apurados.',
    ],
    relacionados: [
      { titulo: 'Orçamentos', href: '/budgets' },
      { titulo: 'Serviços', href: '/services' },
    ],
  },

  'Budgets/Edit': {
    area: 'Comercial',
    titulo: 'Editar orçamento',
    paraQueServe: 'Altera qualquer dado de um orçamento já criado e, principalmente, move a proposta pelo funil: de rascunho para enviado, negociando, aprovado ou recusado.',
    comoUsar: [
      {
        titulo: 'Atualizar a proposta',
        passos: [
          'Ajuste cliente, endereço, datas, pragas e áreas como no cadastro.',
          'Corrija os itens em "Serviços e Valores" ou remova um item pelo ícone de lixeira ao lado dele.',
          'Revise o desconto e confira o total antes de gravar.',
          'Clique em "Atualizar Orçamento".',
        ],
      },
      {
        titulo: 'Mudar o status da negociação',
        passos: [
          'Role até o bloco "Condições Comerciais e Execução".',
          'No campo "Status", escolha Rascunho, Enviado, Negociando, Aprovado, Recusado ou Convertido.',
          'Clique em "Atualizar Orçamento" para gravar.',
        ],
      },
    ],
    campos: [
      { nome: 'Status', descricao: 'Só aparece na edição. Marque "Enviado" ao mandar a proposta e "Aprovado" quando o cliente aceitar: são esses dois momentos que alimentam a taxa de conversão e o tempo médio até o fechamento.' },
    ],
    dicas: [
      'Não pule o status "Enviado". Sem ele, o painel comercial não consegue medir quanto tempo a proposta levou para fechar.',
    ],
    relacionados: [
      { titulo: 'Orçamentos', href: '/budgets' },
      { titulo: 'Painel comercial', href: '/comercial/indicadores' },
    ],
  },

  'Budgets/Show': {
    area: 'Comercial',
    titulo: 'Detalhes do orçamento',
    paraQueServe: 'Mostra a proposta fechada em tela — cliente, serviços, valores, desconto e condições — e é daqui que o orçamento aprovado vira ordem de serviço.',
    comoUsar: [
      {
        titulo: 'Conferir e enviar ao cliente',
        passos: [
          'Confira os blocos "Dados do Cliente/Prospecto", "Serviços" e "Outras Informações".',
          'Clique em "PDF" para abrir a proposta pronta em outra aba.',
          'Clique em "Editar" se precisar corrigir algum valor antes de mandar.',
        ],
      },
      {
        titulo: 'Converter o orçamento aprovado em OS',
        passos: [
          'Com o cliente de acordo, clique em "Converter em OS".',
          'Leia o aviso da janela e clique em "Converter".',
          'O orçamento passa para o status "Convertido" e o sistema abre a criação da ordem de serviço já com o cliente preenchido.',
          'Complete os dados da OS e salve para o serviço entrar na agenda.',
        ],
      },
    ],
    atencao: [
      'Se o orçamento era de um prospecto, a conversão cria na hora um cadastro provisório de cliente e um endereço provisório. Revise esse cadastro depois, porque documento, e-mail e endereço entram com dados de preenchimento.',
      'A conversão só acontece uma vez: o botão "Converter em OS" some depois que o orçamento fica com status "Convertido".',
      'Converter em OS gera um atendimento avulso. Para serviço recorrente, com visitas repetidas, crie um contrato para o endereço do cliente.',
    ],
    relacionados: [
      { titulo: 'Orçamentos', href: '/budgets' },
      { titulo: 'Contratos', href: '/contracts' },
    ],
  },

  'Contracts/Index': {
    area: 'Comercial',
    titulo: 'Contratos',
    paraQueServe: 'Lista os contratos de dedetização da empresa, pontuais ou periódicos. O contrato é o que sustenta a repetição do serviço: dele saem as visitas que viram ordens de serviço na agenda.',
    comoUsar: [
      {
        titulo: 'Localizar um contrato',
        passos: [
          'Digite o nome do cliente no campo "Buscar por Cliente".',
          'A lista filtra sozinha; clique em "Limpar" para voltar a todos.',
          'Cada cartão mostra a etiqueta "Periódico" ou "Pontual", o endereço, a vigência e o valor.',
        ],
      },
      {
        titulo: 'Trabalhar um contrato da lista',
        passos: [
          'Clique em "Ver" para abrir o contrato com as visitas e o histórico.',
          'Clique em "PDF" para gerar o contrato do endereço em outra aba.',
          'Clique em "Editar" para mudar vigência, periodicidade, valor ou cláusulas.',
        ],
      },
      {
        titulo: 'Encerrar ou excluir',
        passos: [
          'Clique em "Encerrar" para fechar a vigência de um contrato ativo.',
          'Leia quantas visitas futuras serão canceladas, escreva o motivo e confirme em "Encerrar contrato".',
          'Use "Excluir" apenas para contrato lançado por engano; se ele já tiver visita executada, o sistema recusa e explica no aviso vermelho do topo.',
        ],
      },
    ],
    campos: [
      { nome: 'Periódico', descricao: 'Contrato de manutenção programada: o sistema calcula as datas previstas e gera as visitas do período.' },
      { nome: 'Pontual', descricao: 'Contrato de visita única, sem repetição programada.' },
    ],
    atencao: [
      'Encerrar cancela as visitas futuras ainda não executadas e grava a data de término. Visitas já executadas não são alteradas.',
      'O botão "Pendências" no topo mostra os contratos periódicos com problema de conformidade — vale olhar essa fila toda semana.',
    ],
    relacionados: [
      { titulo: 'Pendências de contratos', href: '/contracts/pendencias' },
      { titulo: 'Contratos a vencer', href: '/contracts/a-vencer' },
      { titulo: 'Novo contrato', href: '/contracts/create' },
    ],
  },

  'Contracts/Create': {
    area: 'Comercial',
    titulo: 'Criar contrato',
    paraQueServe: 'Cadastra o contrato de um endereço do cliente, com vigência, periodicidade das visitas, valor e as cláusulas que saem no PDF assinado.',
    comoUsar: [
      {
        titulo: 'Escolher o endereço',
        passos: [
          'No campo "Endereço", selecione a combinação cliente + endereço do contrato.',
          'Confira o resumo cinza que aparece com o endereço e o nome do cliente.',
          'Se você chegou aqui a partir do endereço, ele já vem preenchido e não precisa ser escolhido.',
        ],
      },
      {
        titulo: 'Definir vigência e visitas',
        passos: [
          'Em "Tipo de Serviço", escolha "Pontual (Visita Única)" ou "Periódico (Manutenção Programada)".',
          'Informe a "Data de Início".',
          'Clique em um dos botões de "Duração do Contrato" (6 Meses, 1 Ano, 2 Anos, 3 Anos) para preencher a "Data de Término", ou digite a data à mão.',
          'Escolha a "Frequência de Visitas" (Semanal, Quinzenal ou Mensal) e informe a "Quantidade de Visitas".',
        ],
      },
      {
        titulo: 'Preencher valores e texto do contrato',
        passos: [
          'Preencha o "Valor do Serviço (R$)".',
          'Descreva as "Pragas-Alvo do Tratamento".',
          'Preencha "Forma de Pagamento" e "Dados para Pagamento" com o que deve sair no documento.',
          'Se este cliente tiver alguma condição especial, escreva em "Cláusula Adicional" e informe a "Comarca (Cidade do Foro)".',
          'Clique em "Salvar Contrato".',
        ],
      },
    ],
    campos: [
      { nome: 'Quantidade de Visitas', descricao: 'Quantas visitas acontecem dentro da frequência escolhida (por semana, quinzenalmente ou por mês). É esse número, junto com a frequência, que gera as datas previstas.' },
      { nome: 'Cláusula Adicional', descricao: 'Texto livre que entra no PDF do contrato junto com as cláusulas padrão.' },
    ],
    atencao: [
      'O contrato é um documento com valor perante o cliente e a fiscalização: confira o PDF gerado depois de salvar, principalmente quando usar cláusula adicional.',
      'Sem frequência e quantidade de visitas corretas, o contrato periódico aparece em "Pendências de contratos" como periodicidade não preenchida.',
    ],
    relacionados: [
      { titulo: 'Contratos', href: '/contracts' },
    ],
  },

  'Contracts/Edit': {
    area: 'Comercial',
    titulo: 'Editar contrato',
    paraQueServe: 'Corrige os dados de um contrato existente: vigência, tipo de serviço, periodicidade, valor, formas de pagamento e cláusulas.',
    comoUsar: [
      {
        titulo: 'Ajustar prazo e visitas',
        passos: [
          'Revise "Tipo de Serviço", "Data de Início" e "Data de Término".',
          'Use os botões de duração para recalcular a data de término a partir do início.',
          'Corrija a "Frequência de Visitas" e a "Quantidade de Visitas" quando o combinado com o cliente mudar.',
          'Clique em "Atualizar Contrato".',
        ],
      },
      {
        titulo: 'Revisar valores e texto',
        passos: [
          'Atualize o "Valor do Serviço (R$)" e as "Pragas-Alvo do Tratamento".',
          'Ajuste "Forma de Pagamento", "Dados para Pagamento", "Cláusula Adicional" e "Comarca (Cidade do Foro)".',
          'Salve e confira o histórico de alterações no fim da página, que registra o que mudou e quem mudou.',
        ],
      },
    ],
    atencao: [
      'Contrato com pedido de assinatura eletrônica aberto fica somente leitura. Para editar, cancele o pedido de assinatura na tela do contrato.',
      'Mudar periodicidade não apaga visitas já geradas: confira as visitas do contrato depois de alterar.',
    ],
    relacionados: [
      { titulo: 'Contratos', href: '/contracts' },
      { titulo: 'Pendências de contratos', href: '/contracts/pendencias' },
    ],
  },

  'Contracts/Show': {
    area: 'Comercial',
    titulo: 'Detalhes do contrato',
    paraQueServe: 'Reúne tudo de um contrato: dados e vigência, cliente e endereço, visitas geradas, histórico de alterações e, quando o recurso está contratado, a assinatura eletrônica.',
    comoUsar: [
      {
        titulo: 'Consultar o contrato',
        passos: [
          'Confira "Informações do Contrato" (início, término, periodicidade, visitas previstas e valor).',
          'Veja no bloco de visitas quais já foram executadas e quais estão previstas.',
          'Clique em "PDF" para abrir o contrato do endereço em outra aba.',
          'Clique em "Editar" para corrigir qualquer dado.',
        ],
      },
      {
        titulo: 'Enviar para assinatura eletrônica',
        passos: [
          'No bloco "Assinatura eletrônica", clique em "Enviar para assinatura".',
          'Revise a pré-visualização do PDF e confirme o envio.',
          'Acompanhe pela tabela quem visualizou, quem assinou e quando.',
          'Use "Reenviar aviso" para lembrar quem ainda não assinou e "Baixar contrato assinado" quando terminar.',
        ],
      },
      {
        titulo: 'Encerrar o contrato',
        passos: [
          'Clique em "Encerrar contrato".',
          'Leia quantas visitas futuras serão canceladas.',
          'Escreva o motivo do encerramento — ele fica anexado a cada visita cancelada.',
          'Confirme em "Encerrar contrato".',
        ],
      },
    ],
    campos: [
      { nome: 'Periodicidade', descricao: 'A cada quantos dias, semanas ou meses a visita se repete. Em contrato pontual aparece "Não se aplica".' },
      { nome: 'Ambiente de teste', descricao: 'Etiqueta que avisa que a assinatura está sendo feita no ambiente de testes do provedor — nesse caso o documento assinado não tem validade jurídica.' },
    ],
    atencao: [
      'Enquanto o pedido de assinatura estiver aberto, o contrato fica somente leitura, e os botões de editar e encerrar não aparecem. Cancele o pedido para voltar a editar.',
      'Cancelar o pedido de assinatura tira a validade de quem já assinou: para retomar, é preciso enviar o contrato de novo.',
    ],
    relacionados: [
      { titulo: 'Contratos', href: '/contracts' },
      { titulo: 'Contratos a vencer', href: '/contracts/a-vencer' },
    ],
  },

  'Contracts/AVencer': {
    area: 'Comercial',
    titulo: 'Contratos a vencer',
    paraQueServe: 'Fila de decisão de renovação: mostra o que já venceu sem decisão, o que está em negociação e o que se aproxima do fim da vigência, para nenhum contrato virar o mês sem tratativa.',
    comoUsar: [
      {
        titulo: 'Ler a fila',
        passos: [
          'Comece por "Vencido sem decisão": são contratos cuja vigência já terminou e ninguém decidiu nada.',
          'Depois olhe "Em negociação", a conversa de renovação já iniciada.',
          'Por último, veja "Vence em até X dias", agrupado pelos marcos de aviso configurados.',
          'Cada linha mostra o cliente, o número do contrato, o fim da vigência e quantos dias faltam ou já venceram.',
        ],
      },
      {
        titulo: 'Renovar um contrato',
        passos: [
          'Clique em "Renovar" na linha do contrato.',
          'Informe o "Percentual de reajuste (%)" e, se quiser, o "Índice de referência".',
          'Confira o "Valor novo previsto" e, se precisar, informe outra "Data de término do novo contrato".',
          'Leia o bloco "Efeitos ao confirmar" e clique em "Confirmar renovação".',
        ],
      },
      {
        titulo: 'Registrar que não haverá renovação',
        passos: [
          'Clique em "Não renovar" na linha do contrato.',
          'Escolha o motivo: Preço, Mudou de fornecedor, Encerrou a atividade, Insatisfação com o serviço ou Outro.',
          'Quando escolher "Outro", descreva o motivo no campo de texto.',
          'Clique em "Confirmar não renovação".',
        ],
      },
      {
        titulo: 'Marcar uma conversa em andamento',
        passos: [
          'Clique em "Em negociação" para tirar o contrato da fila de cobrança.',
          'O contrato passa para o bloco "Em negociação".',
          'O aviso semanal de vencimento fica pausado por 30 dias enquanto a conversa durar.',
        ],
      },
    ],
    campos: [
      { nome: 'Índice de referência', descricao: 'Apenas um rótulo para o histórico (IPCA, IGP-M...). Quem reajusta o valor de fato é o percentual informado ao lado.' },
    ],
    atencao: [
      'Renovar cria um contrato novo e cancela as visitas futuras do contrato atual — a quantidade a cancelar aparece antes de confirmar.',
      'Deixar em branco a data de término do novo contrato mantém a mesma duração da vigência anterior.',
    ],
    relacionados: [
      { titulo: 'Contratos', href: '/contracts' },
      { titulo: 'Pendências de contratos', href: '/contracts/pendencias' },
    ],
  },

  'Contracts/Pendencias': {
    area: 'Comercial',
    titulo: 'Pendências de contratos',
    paraQueServe: 'Mostra os contratos periódicos vigentes com pendência de conformidade: visita prevista sem ordem de serviço, visita vencida em aberto ou periodicidade não preenchida. É a tela que protege a empresa numa fiscalização da RDC 622/2022.',
    comoUsar: [
      {
        titulo: 'Gerar as visitas que faltam',
        passos: [
          'No bloco "Sem visita gerada no período", marque "Selecionar contratos que só precisam de geração".',
          'Clique em "Gerar visitas selecionadas" e confirme na janela.',
          'Para um contrato só, clique em "Gerar visitas" na linha dele.',
          'Ao final, o aviso verde informa quantas visitas foram criadas.',
        ],
      },
      {
        titulo: 'Justificar data que já passou',
        passos: [
          'Clique em "Justificar" na linha do contrato, ao lado do número de datas pendentes.',
          'Desmarque as datas que este motivo não explica — por padrão todas vêm marcadas.',
          'Escreva o motivo no campo obrigatório (mínimo de 5 caracteres).',
          'Clique em "Justificar" para gravar; cada data recebe o registro com seu nome e a data de hoje.',
        ],
      },
      {
        titulo: 'Resolver os outros dois tipos de pendência',
        passos: [
          'Em "Visita vencida não executada", clique em "Ver visitas do contrato" e execute ou reagende a ordem de serviço.',
          'Em "Periodicidade não preenchida", clique em "Editar periodicidade" e corrija a frequência na edição do contrato.',
          'Volte à tela para conferir se a pendência saiu da lista.',
        ],
      },
    ],
    atencao: [
      'Gerar visitas só cria ordem de serviço com data futura. Nenhuma OS é criada com data no passado: para a data que já venceu, a única saída é justificar.',
      'A justificativa fica gravada por data e é ela que responde à fiscalização — escreva o motivo real, não uma frase genérica.',
      'A geração em lote só aceita contratos cuja única pendência é a falta de visita gerada; os demais exigem ação individual.',
    ],
    relacionados: [
      { titulo: 'Contratos', href: '/contracts' },
      { titulo: 'Contratos a vencer', href: '/contracts/a-vencer' },
    ],
  },

  'Services/Index': {
    area: 'Comercial',
    titulo: 'Serviços',
    paraQueServe: 'Catálogo dos serviços que a empresa vende, com categoria e preço de referência. É desta lista que saem os itens do orçamento.',
    comoUsar: [
      {
        titulo: 'Consultar o catálogo',
        passos: [
          'Digite pelo menos 2 caracteres na busca por nome, categoria ou descrição.',
          'Confira nas colunas o preço e se o serviço está "Ativo" ou "Inativo".',
          'Clique no ícone de olho para ver os detalhes do serviço.',
        ],
      },
      {
        titulo: 'Manter o catálogo',
        passos: [
          'Clique em "Novo Serviço" para cadastrar mais um item.',
          'Use o ícone de lápis para corrigir nome, categoria ou preço.',
          'Use o ícone de lixeira para excluir e confirme na janela que aparece.',
        ],
      },
    ],
    dicas: [
      'Prefira inativar um serviço que saiu de linha a excluí-lo: assim os orçamentos antigos continuam mostrando o que foi vendido.',
    ],
    relacionados: [
      { titulo: 'Novo serviço', href: '/services/create' },
      { titulo: 'Orçamentos', href: '/budgets' },
      { titulo: 'Tipos de serviço', href: '/service-types' },
    ],
  },

  'Services/Create': {
    area: 'Comercial',
    titulo: 'Novo serviço',
    paraQueServe: 'Cadastra um serviço do catálogo comercial, com descrição, categoria e preço de referência para os orçamentos.',
    comoUsar: [
      {
        titulo: 'Preencher o cadastro',
        passos: [
          'Em "Informações Básicas", informe o "Nome do Serviço" e a "Descrição".',
          'Em "Informações Comerciais", preencha a "Categoria" e o "Preço".',
          'Deixe "Serviço ativo" marcado para que ele apareça na hora de montar um orçamento.',
          'Use "Observações Adicionais" para anotações internas.',
          'Clique em "Criar Serviço".',
        ],
      },
    ],
    campos: [
      { nome: 'Categoria', descricao: 'Texto livre usado para agrupar e buscar (por exemplo Análise, Certificação, Consultoria).' },
      { nome: 'Preço', descricao: 'Valor de referência. No orçamento, o valor unitário de cada item ainda pode ser ajustado.' },
    ],
    relacionados: [
      { titulo: 'Serviços', href: '/services' },
    ],
  },

  'Services/Edit': {
    area: 'Comercial',
    titulo: 'Editar serviço',
    paraQueServe: 'Atualiza os dados de um serviço do catálogo e, quando o controle de EPI está disponível, define os equipamentos de proteção exigidos por ele.',
    comoUsar: [
      {
        titulo: 'Atualizar o serviço',
        passos: [
          'Corrija nome, descrição, categoria e preço.',
          'Marque ou desmarque "Serviço ativo" conforme ele continue à venda ou não.',
          'Clique em "Salvar Alterações".',
        ],
      },
      {
        titulo: 'Exigir EPI neste serviço',
        passos: [
          'Role até o bloco de exigências de EPI, no fim da página.',
          'Marque os equipamentos que a empresa passa a exigir em campo para este serviço.',
          'Cada exigência é gravada na hora, separada do botão "Salvar Alterações".',
        ],
      },
    ],
    atencao: [
      'O bloco de EPI só aparece quando o recurso está disponível para a empresa e você tem permissão para gerenciá-lo.',
    ],
    relacionados: [
      { titulo: 'Serviços', href: '/services' },
    ],
  },

  'Services/Show': {
    area: 'Comercial',
    titulo: 'Detalhes do serviço',
    paraQueServe: 'Mostra em tela a ficha completa de um serviço do catálogo: descrição, categoria, preço, situação e datas de criação e atualização.',
    comoUsar: [
      {
        titulo: 'Consultar e seguir',
        passos: [
          'Confira "Descrição" e "Informações Comerciais".',
          'Veja em "Status" se o serviço está ativo para uso nos orçamentos.',
          'Clique em "Editar Serviço" para alterar qualquer dado.',
          'Clique em "Voltar à Lista" para retornar ao catálogo.',
        ],
      },
    ],
    relacionados: [
      { titulo: 'Serviços', href: '/services' },
    ],
  },

  'ServiceTypes/Index': {
    area: 'Comercial',
    titulo: 'Tipos de serviço',
    paraQueServe: 'Gerencia os tipos de ordem de serviço da empresa (dedetização, desinsetização e afins). O tipo classifica a OS e é usado nos indicadores de satisfação e nas regras de comissão.',
    comoUsar: [
      {
        titulo: 'Filtrar a lista',
        passos: [
          'Use "Buscar" para procurar por nome ou descrição.',
          'Use o filtro "Status" para ver Todos, Ativos ou Inativos.',
          'Clique em "Limpar Filtros" para voltar à lista completa.',
          'Os três cartões do topo mostram o total de tipos e quantos estão ativos e inativos.',
        ],
      },
      {
        titulo: 'Manter os tipos',
        passos: [
          'Clique em "Novo Tipo" para cadastrar mais um.',
          'Clique em "Editar" na linha para alterar nome, descrição ou situação.',
          'Clique em "Excluir" e confirme na janela para remover um tipo sem uso.',
        ],
      },
    ],
    campos: [
      { nome: 'Ordens Vinculadas', descricao: 'Quantas ordens de serviço usam este tipo. Com uma ou mais, o botão "Excluir" fica desabilitado.' },
    ],
    atencao: [
      'Tipo com ordem de serviço vinculada não pode ser excluído — desmarque a situação de ativo para tirá-lo de circulação.',
    ],
    relacionados: [
      { titulo: 'Novo tipo de serviço', href: '/service-types/create' },
      { titulo: 'Serviços', href: '/services' },
    ],
  },

  'ServiceTypes/Create': {
    area: 'Comercial',
    titulo: 'Novo tipo de serviço',
    paraQueServe: 'Cria um tipo de ordem de serviço, que classifica as OS da empresa e aparece nas telas de indicadores e de regras de comissão.',
    comoUsar: [
      {
        titulo: 'Cadastrar o tipo',
        passos: [
          'Informe o "Nome" do tipo, como Dedetização ou Desinsetização.',
          'Escreva a "Descrição" com o que este tipo abrange.',
          'Deixe "Tipo de serviço ativo" marcado para poder usá-lo nas ordens de serviço.',
          'Clique em "Salvar Tipo".',
        ],
      },
    ],
    dicas: [
      'Use nomes curtos e sem repetição: o tipo aparece em listas e filtros de várias telas.',
    ],
    relacionados: [
      { titulo: 'Tipos de serviço', href: '/service-types' },
    ],
  },

  'ServiceTypes/Edit': {
    area: 'Comercial',
    titulo: 'Editar tipo de serviço',
    paraQueServe: 'Altera nome, descrição e situação de um tipo de ordem de serviço já cadastrado.',
    comoUsar: [
      {
        titulo: 'Atualizar o tipo',
        passos: [
          'Corrija o "Nome" e a "Descrição".',
          'Marque ou desmarque "Tipo de serviço ativo".',
          'Confira no rodapé o slug, a ordem e as datas de criação e atualização.',
          'Clique em "Atualizar Tipo".',
        ],
      },
    ],
    campos: [
      { nome: 'Slug', descricao: 'Identificador interno gerado a partir do nome. Serve para o sistema referenciar o tipo e não é editável.' },
    ],
    relacionados: [
      { titulo: 'Tipos de serviço', href: '/service-types' },
    ],
  },

  'ServiceTypes/Show': {
    area: 'Comercial',
    titulo: 'Detalhes do tipo de serviço',
    paraQueServe: 'Mostra a ficha de um tipo de ordem de serviço: descrição, situação, ordem de exibição e quantas ordens de serviço já usam esse tipo.',
    comoUsar: [
      {
        titulo: 'Consultar e agir',
        passos: [
          'Confira a "Descrição" e o quadro com "Ordem de Exibição" e "Ordens de Serviço".',
          'Veja em "Informações do Sistema" o slug, a situação e as datas.',
          'Clique em "Editar Tipo" para alterar o cadastro.',
          'Use o botão de excluir do bloco "Ações" apenas se o tipo não tiver ordens vinculadas.',
        ],
      },
    ],
    campos: [
      { nome: 'Ordem de Exibição', descricao: 'Define a posição do tipo nas listas de seleção do sistema.' },
    ],
    relacionados: [
      { titulo: 'Tipos de serviço', href: '/service-types' },
    ],
  },

  'Goals/Index': {
    area: 'Comercial',
    titulo: 'Metas',
    paraQueServe: 'Define e acompanha as metas da equipe por competência (mês), com o realizado, o percentual atingido e a projeção para o fim do período.',
    comoUsar: [
      {
        titulo: 'Acompanhar o mês',
        passos: [
          'Escolha o mês no campo "Competência".',
          'Cada cartão mostra a pessoa, o tipo de meta, o realizado sobre o alvo e a barra de progresso.',
          'Quem chega a 100% ganha a etiqueta "Meta atingida".',
          'Confira a projeção para o fim do período, que aparece a partir do dia útil informado na tela.',
        ],
      },
      {
        titulo: 'Definir metas em lote',
        passos: [
          'No bloco "Definir metas em lote", preencha "ID da pessoa", "Tipo de meta", "Alvo" e, se quiser, uma "Observação".',
          'Clique em "Adicionar linha" para incluir mais pessoas, ou "Remover" para tirar uma linha.',
          'Clique em "Salvar metas em lote".',
          'Reenviar o lote atualiza a meta de quem já tinha, sem duplicar.',
        ],
      },
      {
        titulo: 'Corrigir ou excluir uma meta',
        passos: [
          'Clique em "Editar meta" no cartão da pessoa.',
          'Ajuste o "Alvo" e a "Observação" e clique em "Salvar".',
          'Para apagar, clique em "Excluir" e confirme na janela.',
        ],
      },
    ],
    campos: [
      { nome: 'Tipo de meta', descricao: 'Valor vendido, Valor recebido, Quantidade de OS ou Taxa de conversão.' },
      { nome: 'ID da pessoa', descricao: 'Número do cadastro da pessoa. O campo sugere quem já apareceu em alguma meta; para alguém novo, digite o número do cadastro.' },
    ],
    atencao: [
      'Meta de taxa de conversão com poucos orçamentos enviados mostra o aviso de amostra insuficiente, em vez de um percentual que não significa nada.',
    ],
    relacionados: [
      { titulo: 'Comissões', href: '/comissoes' },
      { titulo: 'Painel comercial', href: '/comercial/indicadores' },
    ],
  },

  'Comercial/Indicadores': {
    area: 'Comercial',
    titulo: 'Painel comercial',
    paraQueServe: 'Mostra a saúde do funil no período: quantos orçamentos foram enviados, quantos viraram venda, o ticket médio e quanto tempo a proposta leva do envio até o fechamento.',
    comoUsar: [
      {
        titulo: 'Escolher o período',
        passos: [
          'Preencha "De" e "Até" com as datas que quer analisar.',
          'Clique em "Filtrar".',
          'Os quatro cartões e as tabelas passam a considerar apenas esse intervalo.',
        ],
      },
      {
        titulo: 'Ler os números',
        passos: [
          'Compare "Enviados x aprovados por mês" para ver o volume de propostas.',
          'Use "Taxa de conversão por mês" para acompanhar a tendência de fechamento.',
          'Confira o "Detalhamento mensal", que traz os números exatos por trás dos gráficos.',
          'Veja em "Por vendedor" o desempenho individual da equipe.',
        ],
      },
    ],
    campos: [
      { nome: 'Taxa de conversão', descricao: 'Quantos orçamentos enviados viraram venda. Vem sempre com a contagem absoluta ao lado.' },
      { nome: 'Ticket médio', descricao: 'Valor médio dos orçamentos aprovados no período.' },
      { nome: 'Tempo médio até o fechamento', descricao: 'Dias entre o momento em que o orçamento ficou "Enviado" e o momento em que ficou "Aprovado".' },
    ],
    atencao: [
      'Os números saem das mudanças de status do orçamento. Se a equipe não marca "Enviado" e "Aprovado" na hora certa, o painel fica sem sentido.',
      'Mês ou vendedor com poucos orçamentos mostra a contagem no lugar da taxa, com o aviso de amostra insuficiente — não é erro do sistema.',
    ],
    relacionados: [
      { titulo: 'Orçamentos', href: '/budgets' },
      { titulo: 'Metas', href: '/metas' },
    ],
  },

  'Commissions/Index': {
    area: 'Comercial',
    titulo: 'Comissões',
    paraQueServe: 'Apura, confere e fecha a comissão de vendedores e técnicos por competência (mês), com a memória de cálculo de cada item.',
    comoUsar: [
      {
        titulo: 'Apurar a competência',
        passos: [
          'Escolha o mês no campo "Competência".',
          'Clique em "Apurar competência" no topo da tela.',
          'Leia o aviso e clique em "Apurar agora".',
          'O sistema recalcula os itens a partir das vendas aprovadas, das parcelas recebidas e das ordens de serviço concluídas no período.',
        ],
      },
      {
        titulo: 'Conferir a memória de cálculo',
        passos: [
          'Clique em "Ver itens" no cartão da pessoa.',
          'Confira a origem de cada item, a data do fato, a base, o percentual ou valor fixo aplicado e o valor.',
          'Clique em "Ocultar itens" para fechar a tabela.',
        ],
      },
      {
        titulo: 'Fechar e pagar',
        passos: [
          'Com os números conferidos, clique em "Fechar" e confirme em "Fechar comissão".',
          'Depois de fechada, clique em "Marcar como paga".',
          'Se quiser lançar o pagamento no financeiro, marque "Gerar título a pagar no financeiro para esta comissão" e informe o ID do fornecedor e o vencimento.',
          'Clique em "Marcar como paga" para gravar.',
        ],
      },
      {
        titulo: 'Reabrir uma comissão',
        passos: [
          'Clique em "Reabrir" no cartão da comissão fechada ou paga.',
          'Escreva a justificativa (mínimo de 10 caracteres).',
          'Clique em "Reabrir comissão" — a justificativa fica registrada na auditoria.',
        ],
      },
    ],
    campos: [
      { nome: 'Situação', descricao: 'Aberta (ainda recalculada a cada apuração), Fechada (imutável) ou Paga.' },
      { nome: 'Origem', descricao: 'De onde veio o item: Orçamento (venda), Parcela recebida ou Ordem de serviço (execução).' },
    ],
    atencao: [
      'Apurar recalcula do zero apenas as comissões ainda abertas da competência. Comissões fechadas ou pagas não são alteradas.',
      'Quem não tem permissão para ver a comissão de todos enxerga apenas a própria comissão.',
    ],
    relacionados: [
      { titulo: 'Regras de comissão', href: '/comissoes/regras' },
      { titulo: 'Metas', href: '/metas' },
    ],
  },

  'Commissions/Regras': {
    area: 'Comercial',
    titulo: 'Regras de comissão',
    paraQueServe: 'Define quanto cada vendedor ou técnico recebe de comissão: percentual ou valor fixo, sobre o que é calculado e a partir de quando vale. É esta configuração que a apuração usa.',
    comoUsar: [
      {
        titulo: 'Criar uma regra',
        passos: [
          'Clique em "Nova regra".',
          'Escolha o "Tipo" (Vendedor ou Técnico) e a "Base de cálculo" (Recebido, Vendido ou Executado).',
          'Marque "Regra geral da empresa" para valer para todo mundo daquele tipo, ou informe o ID da pessoa.',
          'Escolha a "Forma" (Percentual ou Valor fixo) e informe o "Valor".',
          'Preencha o "Início da vigência" e clique em "Salvar".',
        ],
      },
      {
        titulo: 'Restringir a um tipo de serviço',
        passos: [
          'No campo "ID do tipo de serviço", informe o número do tipo a que a regra se aplica.',
          'Deixe em branco para a regra valer para qualquer serviço.',
          'Salve e confira a coluna "Serviço" da listagem.',
        ],
      },
      {
        titulo: 'Alterar ou encerrar uma regra',
        passos: [
          'Clique em "Editar" na linha da regra para corrigir valor ou vigência.',
          'Preencha o "Fim da vigência" para encerrar a regra em uma data.',
          'Desmarque "Regra ativa" para tirá-la de uso sem apagar o histórico.',
          'Use "Excluir" e confirme apenas para regra lançada por engano.',
        ],
      },
    ],
    campos: [
      { nome: 'Recebido', descricao: 'A comissão nasce quando a parcela é recebida do cliente. É o padrão.' },
      { nome: 'Vendido', descricao: 'A comissão nasce quando a venda é aprovada, independentemente do recebimento.' },
      { nome: 'Executado', descricao: 'A comissão nasce quando a ordem de serviço é concluída.' },
    ],
    atencao: [
      'Comissão já apurada não é recalculada retroativamente: uma regra nova vale para os fatos ocorridos a partir do início da vigência dela.',
      'Excluir uma regra não altera as comissões já apuradas com ela.',
    ],
    relacionados: [
      { titulo: 'Comissões', href: '/comissoes' },
      { titulo: 'Tipos de serviço', href: '/service-types' },
    ],
  },

  'Satisfacao/Index': {
    area: 'Comercial',
    titulo: 'Satisfação',
    paraQueServe: 'Reúne as notas que os clientes deram depois da visita: média geral, evolução por mês, desempenho por técnico e por tipo de serviço, e a fila de notas baixas que ainda precisam de contato.',
    comoUsar: [
      {
        titulo: 'Analisar o período',
        passos: [
          'Preencha "De" e "Até" e clique em "Filtrar".',
          'Confira os cartões "Média geral", "Respostas no período" e "Pendências de contato".',
          'Veja em "Evolução por mês" se a nota está subindo ou caindo.',
          'Compare as tabelas "Por técnico" e "Por tipo de serviço".',
        ],
      },
      {
        titulo: 'Tratar as notas baixas',
        passos: [
          'Role até "Notas baixas com contato pendente", que lista as notas 1 e 2 sem retorno da empresa.',
          'Leia o comentário do cliente e veja o técnico, o serviço e a OS da visita.',
          'Ligue ou escreva para o cliente.',
          'De volta ao sistema, clique em "Marcar contato feito" para tirar o caso da fila.',
        ],
      },
    ],
    campos: [
      { nome: 'Média', descricao: 'Nota média de 1 a 5. Quando o corte tem poucas respostas, o sistema mostra quantas ainda faltam em vez da média — nota isolada de um técnico não é justa.' },
    ],
    atencao: [
      'A pesquisa é enviada ao cliente automaticamente depois que a ordem de serviço é concluída, e apenas uma por visita. O mesmo cliente não recebe duas pesquisas em um intervalo curto.',
      'A visita só gera pesquisa se o cliente tiver e-mail ou telefone cadastrado.',
    ],
    relacionados: [
      { titulo: 'Painel comercial', href: '/comercial/indicadores' },
    ],
  },

  'Publico/Agendar': {
    area: 'Comercial',
    titulo: 'Página pública de pedido de horário',
    paraQueServe: 'É a página que o cliente final vê, sem login, no endereço /agendar/ seguido do apelido público da sua empresa. Nela ele escolhe um dia e um período e envia os dados de contato para pedir uma visita.',
    comoUsar: [
      {
        titulo: 'Entender o que o cliente faz',
        passos: [
          'Ele escolhe um dia no calendário; dias sem disponibilidade ficam desabilitados.',
          'Escolhe o "Período" entre as opções liberadas para aquele dia.',
          'Preenche "Tipo de serviço", "Nome", "E-mail", "Telefone", "Endereço do atendimento" e "Observação".',
          'Clica em "Enviar pedido de horário" e vê na mesma tela a confirmação de "Pedido recebido".',
        ],
      },
      {
        titulo: 'Deixar a página no ar',
        passos: [
          'Abra a configuração de disponibilidade e agendamento online do sistema.',
          'Ligue o aceite de agendamento online da empresa.',
          'Defina o apelido público (slug) que compõe o endereço da página e copie a URL pronta.',
          'Configure a disponibilidade por dia e período — é ela que libera ou bloqueia cada data no calendário do cliente.',
        ],
      },
      {
        titulo: 'Atender os pedidos que chegam',
        passos: [
          'Acompanhe a fila de solicitações de horário na tela de agendamentos.',
          'Ligue ou escreva para o solicitante para confirmar dia e horário.',
          'Crie a ordem de serviço quando a visita estiver acertada.',
        ],
      },
    ],
    atencao: [
      'O pedido não agenda nada: não cria ordem de serviço, não cria cliente e não reserva vaga. Quem confirma é a empresa, por telefone ou e-mail — e a própria página avisa isso ao cliente.',
      'Com o agendamento online desligado, ou com o apelido público em branco, o endereço responde página não encontrada.',
      'Os tipos de serviço oferecidos na página vêm do cadastro de tipos de serviço da empresa.',
      'A página tem limite de pedidos por hora, por IP e por telefone, para conter envio automatizado.',
    ],
    relacionados: [
      { titulo: 'Tipos de serviço', href: '/service-types' },
      { titulo: 'Disponibilidade e agendamento online', href: '/settings/disponibilidade' },
    ],
  },

  'Publico/Pesquisa': {
    area: 'Comercial',
    titulo: 'Página pública da pesquisa de satisfação',
    paraQueServe: 'É a página que o cliente final abre pelo link recebido depois da visita, sem login, para dar uma nota de 1 a 5 e deixar um comentário sobre o serviço.',
    comoUsar: [
      {
        titulo: 'Entender o que o cliente vê',
        passos: [
          'Ele abre o link enviado pela empresa depois da ordem de serviço concluída.',
          'Toca em uma das cinco estrelas para dar a nota.',
          'Escreve, se quiser, um "Comentário (opcional)".',
          'Clica em "Enviar avaliação" e recebe a mensagem de agradecimento na mesma tela.',
        ],
      },
      {
        titulo: 'Garantir que os convites saiam',
        passos: [
          'Conclua as ordens de serviço no sistema: é a conclusão que dispara a pesquisa.',
          'Mantenha e-mail e telefone do cliente atualizados no cadastro, senão a pesquisa não é criada.',
          'Acompanhe as respostas na tela de Satisfação e trate as notas baixas.',
        ],
      },
    ],
    atencao: [
      'Cada visita gera no máximo uma pesquisa, e o mesmo cliente não recebe pesquisas seguidas em intervalo curto.',
      'O link tem prazo de resposta. Link vencido, já respondido ou digitado errado abre uma página explicando a situação, sem tom de erro — não é falha do sistema.',
      'O link é a única credencial da resposta: quem recebe é o cliente, e ele não deve repassá-lo.',
    ],
    relacionados: [
      { titulo: 'Satisfação', href: '/satisfacao' },
    ],
  },
};
