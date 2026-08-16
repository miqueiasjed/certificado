/**
 * Manual de uso das telas de Financeiro e faturamento.
 *
 * A chave é o nome do componente Inertia da tela (ex.: 'Financeiro/ContasAReceber').
 * O formato de cada entrada está descrito em `modelo.js`.
 */
export default {
  'FinancialDashboard/Index': {
    area: 'Financeiro',
    titulo: 'Dashboard financeiro',
    paraQueServe: 'Mostra, em um período escolhido, quanto a empresa recebeu, de onde veio esse dinheiro e como ele evoluiu mês a mês. É a visão de abertura do financeiro, antes de entrar na lista detalhada.',
    comoUsar: [
      {
        titulo: 'Escolher o período analisado',
        passos: [
          'Abra o seletor "Período" e escolha entre "Este Mês", "Mês Anterior", "Este Ano" ou "Ano Anterior".',
          'Para um recorte diferente, escolha "Personalizado" — os campos "Data Inicial" e "Data Final" aparecem ao lado.',
          'Preencha as duas datas: os cartões, os gráficos e a lista de entradas recentes são recalculados na hora.',
        ],
      },
      {
        titulo: 'Ler os números do topo',
        passos: [
          'Confira "Total Recebido": é a soma de tudo que entrou no período.',
          'Compare "De Pagamentos" (entradas geradas por ordens de serviço) com "Manuais" (entradas lançadas à mão).',
          'Use "Total de Entradas" para saber quantos lançamentos formam esse valor, não o valor em si.',
          'Desça até "Entradas Recentes" para ver as 10 últimas entradas com data, forma de pagamento e OS de origem.',
        ],
      },
      {
        titulo: 'Lançar uma entrada sem sair da tela',
        passos: [
          'Clique em "Nova Entrada" no topo direito.',
          'Escolha a "Origem" (Ordem de Serviço ou Manual) e informe o "Valor".',
          'Preencha a "Descrição" e a "Data", e escolha a "Forma de Pagamento" quando souber como o dinheiro entrou.',
          'Clique em "Salvar" — a entrada passa a contar nos cartões e nos gráficos.',
        ],
      },
    ],
    campos: [
      { nome: 'De Pagamentos', descricao: 'Parte do total que veio de pagamentos vinculados a ordens de serviço, e não de lançamentos digitados manualmente.' },
      { nome: 'Evolução Mensal', descricao: 'Gráfico que compara os meses do período escolhido, útil para enxergar queda ou crescimento de receita.' },
    ],
    dicas: [
      'Para conferir um número que pareceu estranho, clique em "Ver Entradas" e investigue lançamento a lançamento.',
    ],
    relacionados: [
      { titulo: 'Entradas financeiras', href: '/financial-entries' },
      { titulo: 'Fluxo de caixa', href: '/cash-flow' },
    ],
  },

  'FinancialEntries/Index': {
    area: 'Financeiro',
    titulo: 'Entradas financeiras',
    paraQueServe: 'Lista todo dinheiro que entrou no caixa e permite lançar, corrigir ou excluir entradas feitas manualmente. As entradas geradas por ordem de serviço aparecem aqui, mas são somente leitura.',
    comoUsar: [
      {
        titulo: 'Encontrar uma entrada',
        passos: [
          'Use "Status" para separar o que está "Confirmado", "Pendente" ou "Cancelado".',
          'Preencha "Data Inicial" e "Data Final" para limitar o período.',
          'A lista e os cartões de resumo se atualizam a cada filtro alterado.',
          'Se houver muitos resultados, use a paginação no rodapé da lista.',
        ],
      },
      {
        titulo: 'Lançar uma entrada manual',
        passos: [
          'Clique em "Nova Entrada".',
          'Escolha a "Origem" e digite o "Valor" e a "Descrição".',
          'Confirme a "Data" e escolha a "Forma de Pagamento".',
          'Se houver comprovante ou transação, preencha "Número de Referência" e use "Observações" para o contexto.',
          'Deixe o "Status" em "Confirmado" quando o dinheiro já entrou de fato e clique em "Salvar".',
        ],
      },
      {
        titulo: 'Corrigir ou excluir uma entrada',
        passos: [
          'Localize a linha da entrada e clique em "Editar".',
          'Ajuste o que estiver errado — abaixo do formulário fica o histórico de alterações do registro.',
          'Clique em "Salvar" para gravar a correção.',
          'Para apagar, clique em "Excluir" e confirme na janela que aparece.',
        ],
      },
    ],
    campos: [
      { nome: 'Status', descricao: '"Confirmado" é dinheiro que já entrou; "Pendente" é entrada esperada mas ainda não confirmada; "Cancelado" deixa o lançamento registrado sem valer como receita.' },
      { nome: 'Número de Referência', descricao: 'Número do comprovante, do depósito ou da transação. É o que permite casar o lançamento com o extrato depois.' },
      { nome: 'Gerada por OS', descricao: 'Marca as entradas criadas automaticamente pelo pagamento de uma ordem de serviço. Elas não têm botão de editar nem de excluir.' },
    ],
    atencao: [
      'Excluir uma entrada apaga o lançamento do caixa e não tem como desfazer. Se a intenção é anular sem perder o rastro, edite a entrada e mude o "Status" para "Cancelado".',
      'Entrada marcada como "Gerada por OS" só muda pela ordem de serviço de origem — alterar por aqui deixaria a OS e o caixa contando histórias diferentes.',
    ],
    relacionados: [
      { titulo: 'Saídas financeiras', href: '/financial-withdrawals' },
      { titulo: 'Fluxo de caixa', href: '/cash-flow' },
    ],
  },

  'FinancialWithdrawals/Index': {
    area: 'Financeiro',
    titulo: 'Saídas financeiras',
    paraQueServe: 'Registra e lista todo dinheiro que saiu do caixa, seja uma retirada lançada à mão, seja a devolução gerada pela reabertura de um pagamento de ordem de serviço.',
    comoUsar: [
      {
        titulo: 'Consultar as saídas do período',
        passos: [
          'Escolha o "Status" desejado para ver só o que está confirmado, pendente ou cancelado.',
          'Informe "Data Inicial" e "Data Final" para recortar o período.',
          'Compare os cartões "Total de Saídas", "De Reaberturas" e "Manuais" para saber a origem do dinheiro que saiu.',
        ],
      },
      {
        titulo: 'Lançar uma saída manual',
        passos: [
          'Clique em "Nova Saída".',
          'Informe o "Valor", a "Descrição" e a "Data" em que o dinheiro saiu.',
          'Escolha a "Forma de Pagamento" e, se houver comprovante, preencha "Número de Referência".',
          'Confirme o "Status" e clique em "Salvar" — o valor aparece na lista com sinal negativo.',
        ],
      },
      {
        titulo: 'Corrigir ou remover uma saída',
        passos: [
          'Clique em "Editar" na linha da saída e ajuste os campos.',
          'Clique em "Salvar" para gravar.',
          'Para apagar, clique em "Excluir" e confirme na janela de confirmação.',
        ],
      },
    ],
    campos: [
      { nome: 'De Reaberturas', descricao: 'Saídas criadas automaticamente quando um pagamento de ordem de serviço é reaberto: o valor recebido volta a sair do caixa.' },
      { nome: 'Gerada por OS', descricao: 'Aparece nas saídas de reabertura de pagamento. Essas linhas não podem ser editadas nem excluídas por aqui.' },
    ],
    atencao: [
      'Excluir uma saída remove o lançamento do caixa em definitivo. Prefira mudar o "Status" para "Cancelado" quando quiser preservar o histórico.',
    ],
    relacionados: [
      { titulo: 'Entradas financeiras', href: '/financial-entries' },
      { titulo: 'Fluxo de caixa', href: '/cash-flow' },
    ],
  },

  'CashFlow/Index': {
    area: 'Financeiro',
    titulo: 'Fluxo de caixa',
    paraQueServe: 'Reúne entradas e saídas na mesma linha do tempo, com o que já aconteceu de verdade no caixa. É a tela para conferir o movimento do período e exportar o histórico.',
    comoUsar: [
      {
        titulo: 'Filtrar o movimento',
        passos: [
          'Escolha o "Método" para ver só dinheiro, PIX, cartão ou transferência.',
          'Defina "Data Inicial" e "Data Final" para o período que quer conferir.',
          'Use "Buscar" para procurar por descrição ou número de referência — a busca roda sozinha depois de você parar de digitar.',
        ],
      },
      {
        titulo: 'Ler o resumo e o histórico',
        passos: [
          'Confira "Total Recebido" e "Total de Saídas" para ter o movimento bruto do período.',
          'Use "Pagamentos Efetivos" para ver quanto do recebido veio de pagamentos, já descontado o que foi reaberto.',
          'Na lista "Histórico Completo", entradas aparecem com "+" em verde e saídas com "-" em vermelho.',
          'Cada linha mostra a data, a forma de pagamento, a referência e a OS de origem quando existir.',
        ],
      },
      {
        titulo: 'Exportar o período',
        passos: [
          'Deixe os filtros do jeito que você quer o relatório.',
          'Clique em "Exportar" no topo da tela.',
          'O arquivo é gerado em uma nova aba com exatamente o mesmo recorte da tela.',
        ],
      },
    ],
    campos: [
      { nome: 'Pagamentos Efetivos', descricao: 'Total dos pagamentos recebidos descontando o que voltou por reabertura, ou seja, o dinheiro que de fato ficou no caixa.' },
    ],
    dicas: [
      'O fluxo de caixa mostra o que já foi realizado. Para o que ainda vai entrar e sair, use a previsão de caixa.',
    ],
    relacionados: [
      { titulo: 'Previsão de caixa', href: '/financeiro/previsao' },
      { titulo: 'Dashboard financeiro', href: '/financial-dashboard' },
    ],
  },

  'Financeiro/ContasAReceber': {
    area: 'Financeiro',
    titulo: 'Contas a receber',
    paraQueServe: 'Lista as parcelas que os clientes devem à empresa e é onde você registra o recebimento (a baixa), estorna um recebimento errado, emite cobrança de boleto ou Pix e pede a nota fiscal do título.',
    comoUsar: [
      {
        titulo: 'Encontrar as parcelas certas',
        passos: [
          'Use "De" e "Até" para limitar o período de vencimento.',
          'Escolha a "Situação" (Aberta, Parcial, Paga, Vencida ou Cancelada) e, se precisar, o "Cliente".',
          'Filtre por "Origem" para separar o que veio de avulso, de ordem de serviço ou de contrato.',
          'Use "Categoria" para ver apenas as parcelas classificadas em uma conta do plano de contas.',
          'Confira os indicadores do topo: "Vence hoje", "Vencido", "A vencer no mês" e "Recebido no mês".',
        ],
      },
      {
        titulo: 'Dar baixa em uma parcela',
        passos: [
          'Na linha da parcela, clique em "Baixar".',
          'O campo "Valor recebido" já vem preenchido com o saldo devedor — reduza o valor se o cliente pagou só uma parte.',
          'Confira o aviso logo abaixo do valor: ele diz se a baixa quita a parcela ou quanto continua em aberto.',
          'Informe a "Data do recebimento" (a data real do pagamento, não a de hoje) e a "Forma de pagamento".',
          'Clique em "Confirmar baixa" — o valor entra no caixa com a data informada.',
        ],
      },
      {
        titulo: 'Baixar várias parcelas de um depósito único',
        passos: [
          'Marque a caixa de seleção das parcelas na primeira coluna — só é possível selecionar parcelas com saldo e do mesmo cliente.',
          'Confira na barra verde quantas parcelas e qual total você selecionou.',
          'Clique em "Baixar selecionadas".',
          'Informe a "Data do recebimento" e a "Forma de pagamento": elas valem para todas as parcelas do lote.',
          'Clique em "Baixar N parcela(s)" — cada parcela é quitada pelo próprio saldo integral.',
        ],
      },
      {
        titulo: 'Estornar um recebimento',
        passos: [
          'Na linha da parcela já paga, clique em "Estornar".',
          'Escreva o "Motivo" com pelo menos 10 caracteres, explicando o que aconteceu.',
          'Clique em "Confirmar estorno" — o valor volta a ficar em aberto na parcela.',
        ],
      },
    ],
    campos: [
      { nome: 'Saldo', descricao: 'Quanto ainda falta receber da parcela: é o valor original menos o que já foi pago. Só parcelas com saldo podem ser baixadas.' },
      { nome: 'Situação', descricao: '"Parcial" é a parcela que recebeu uma baixa menor que o saldo e continua em aberto; "Vencida" passou da data e ainda tem saldo.' },
      { nome: 'Origem', descricao: 'Indica se a parcela nasceu de um lançamento avulso, de uma ordem de serviço ou de um contrato.' },
    ],
    dicas: [
      'Não existe baixa parcial em lote: dividir um valor menor entre parcelas diferentes precisa ser feito parcela por parcela.',
      'Quando a lista chega a 300 parcelas, o sistema avisa que está mostrando as mais próximas do vencimento — refine o período para ver as demais.',
    ],
    atencao: [
      'O estorno não apaga a baixa: o recebimento original continua registrado e o estorno entra como uma saída no dia em que foi feito. O motivo é o que explica a reversão em uma auditoria.',
      'A emissão de NFS-e tem valor fiscal. Ao selecionar títulos e clicar em "Emitir NFS-e", uma nota é solicitada para cada título, e a prefeitura pode continuar processando depois que a tela responde.',
      'Ao clicar em "Emitir cobrança", é gerado um boleto ou Pix para cada parcela selecionada. Confira o resumo por parcela antes de fechar a janela: ele mostra quais foram emitidas e quais deram erro.',
    ],
    relacionados: [
      { titulo: 'Inadimplência', href: '/financeiro/inadimplencia' },
      { titulo: 'Cobranças', href: '/cobrancas' },
      { titulo: 'Notas fiscais', href: '/notas' },
    ],
  },

  'Financeiro/ContasAPagar': {
    area: 'Financeiro',
    titulo: 'Contas a pagar',
    paraQueServe: 'Controla as parcelas que a empresa deve aos fornecedores: cadastro do título (avulso ou recorrente), registro do pagamento, estorno e cancelamento.',
    comoUsar: [
      {
        titulo: 'Cadastrar um título a pagar',
        passos: [
          'Clique em "Novo título".',
          'Escolha o "Fornecedor" e escreva a "Descrição" da despesa.',
          'Selecione a "Categoria" do plano de contas para a despesa entrar classificada nos relatórios.',
          'Informe o "Valor" e o "Vencimento" — e, se fizer sentido, a data em "Emitido em".',
          'Para uma despesa que se repete, escolha a "Recorrência" (Mensal, Trimestral ou Anual) e preencha "Recorrente até".',
          'Clique em "Cadastrar título".',
        ],
      },
      {
        titulo: 'Registrar o pagamento de uma parcela',
        passos: [
          'Localize a parcela e clique no ícone de visto ("Registrar pagamento").',
          'Confira o "Valor pago" — ele vem preenchido com o saldo em aberto e pode ser reduzido para um pagamento parcial.',
          'Informe a "Data do pagamento" e a "Forma de pagamento".',
          'Use "Observação" para o contexto do pagamento e clique em "Registrar pagamento".',
        ],
      },
      {
        titulo: 'Mudar o valor de uma despesa recorrente',
        passos: [
          'Na parcela marcada como "Recorrente", clique no ícone de lápis ("Alterar valor da série").',
          'Digite o "Novo valor".',
          'Escolha o "Alcance da alteração": "Apenas este título" muda só esta competência, "Este e os títulos futuros da série" muda também as próximas.',
          'Leia o aviso azul, que descreve exatamente o efeito da escolha, e clique em "Confirmar alteração".',
        ],
      },
      {
        titulo: 'Estornar ou cancelar',
        passos: [
          'Para desfazer um pagamento já lançado, clique no ícone de seta ("Estornar pagamento").',
          'Escreva o "Motivo do estorno" com pelo menos 10 caracteres e clique em "Confirmar estorno".',
          'Para encerrar um título que não será mais pago, clique no ícone de X ("Cancelar título") e confirme em "Sim, cancelar".',
        ],
      },
    ],
    campos: [
      { nome: 'Saldo', descricao: 'Quanto falta pagar da parcela. Só parcelas abertas, parciais ou vencidas podem receber pagamento.' },
      { nome: 'Recorrência', descricao: 'Ao salvar um título recorrente, as próximas 3 competências já são geradas, e o sistema mantém sempre 3 à frente até a data de "Recorrente até".' },
    ],
    dicas: [
      'Use "Limpar filtros" para voltar rapidamente à lista completa depois de uma busca específica.',
      'Os indicadores do topo ("Vence hoje", "Vencido", "A vencer no mês" e "Pago no mês") consideram todas as parcelas, não só as que estão na tela.',
    ],
    atencao: [
      'O estorno desfaz o pagamento já lançado no caixa e exige motivo — é ele que responde pela reversão depois.',
      'Cancelar o título cancela as parcelas ainda em aberto. As parcelas já pagas continuam exatamente como estão.',
    ],
    relacionados: [
      { titulo: 'Plano de contas', href: '/contas' },
      { titulo: 'Previsão de caixa', href: '/financeiro/previsao' },
    ],
  },

  'Financeiro/Inadimplencia': {
    area: 'Financeiro',
    titulo: 'Inadimplência',
    paraQueServe: 'Mostra o saldo em aberto de cada cliente separado por faixa de atraso, para você saber quem cobrar primeiro e há quanto tempo a dívida existe.',
    comoUsar: [
      {
        titulo: 'Ler o quadro de atrasos',
        passos: [
          'Comece pelos cartões do topo: quantos clientes estão inadimplentes, qual o saldo total em aberto e quantas parcelas ele representa.',
          'Na tabela, leia da esquerda para a direita: "A vencer", "1 a 30 dias", "31 a 60 dias", "61 a 90 dias" e "Acima de 90 dias".',
          'Quanto mais à direita estiver o valor, mais antiga é a dívida daquele cliente.',
          'Confira a linha "Total geral" no rodapé para o retrato da empresa inteira.',
        ],
      },
      {
        titulo: 'Ver as parcelas de uma faixa',
        passos: [
          'Clique no valor da faixa que quer investigar.',
          'A tela de contas a receber abre já filtrada pelo cliente e pelo período de vencimento daquela faixa.',
          'A partir dali você pode baixar, estornar ou emitir cobrança das parcelas.',
        ],
      },
      {
        titulo: 'Cobrar o cliente',
        passos: [
          'Na coluna "Cobrança", clique no botão de WhatsApp da linha do cliente.',
          'A mensagem já vai preenchida com o saldo total em aberto daquele cliente.',
          'Revise o texto antes de enviar.',
        ],
      },
    ],
    campos: [
      { nome: 'A vencer', descricao: 'Saldo que ainda não passou da data de vencimento. Entra no quadro para você ver o total devido, mas não é atraso.' },
    ],
    dicas: [
      'O botão de WhatsApp só fica disponível para clientes com telefone cadastrado e que aceitam contato por esse canal.',
    ],
    relacionados: [
      { titulo: 'Contas a receber', href: '/contas-a-receber' },
      { titulo: 'Cobranças', href: '/cobrancas' },
    ],
  },

  'Financeiro/PlanoDeContas': {
    area: 'Financeiro',
    titulo: 'Plano de contas',
    paraQueServe: 'Cadastra as categorias de receita e de despesa usadas para classificar os títulos a receber e a pagar. É o que permite depois olhar o financeiro por tipo de gasto e de entrada.',
    comoUsar: [
      {
        titulo: 'Criar uma categoria',
        passos: [
          'Clique em "Nova categoria de receita" ou em "Nova categoria de despesa", conforme o caso.',
          'Informe o "Código" (o número que ordena a categoria na lista) e o "Nome".',
          'Deixe "Categoria-mãe" em "Nenhuma (categoria raiz)" para uma categoria de primeiro nível.',
          'Confira o "Tipo" e clique em "Cadastrar categoria".',
        ],
      },
      {
        titulo: 'Criar uma subcategoria',
        passos: [
          'Na linha da categoria que será a mãe, clique no ícone de "+" ("Nova subcategoria").',
          'Preencha "Código" e "Nome" da subcategoria.',
          'O "Tipo" fica travado: a subcategoria sempre herda o tipo da categoria-mãe.',
          'Clique em "Cadastrar categoria" — ela aparece recuada abaixo da mãe.',
        ],
      },
      {
        titulo: 'Tirar uma categoria de uso',
        passos: [
          'Clique no ícone de desligar para desativar a categoria: ela some dos seletores de novos títulos e o histórico continua classificado como está.',
          'Para apagar de vez, clique no ícone de lixeira e confirme.',
          'Se a categoria já classificar títulos ou tiver subcategorias, o sistema explica o motivo e oferece "Desativar agora" no lugar da exclusão.',
        ],
      },
    ],
    campos: [
      { nome: 'Código', descricao: 'Identificador curto da categoria, usado para ordenar e para achar a conta nos seletores de título.' },
      { nome: 'Títulos vinculados', descricao: 'Quantos títulos já usam a categoria. Enquanto houver algum, a categoria não pode ser excluída.' },
    ],
    atencao: [
      'A exclusão de uma categoria é definitiva. Desativar é quase sempre a escolha certa: preserva a classificação do histórico e só tira a conta das telas de cadastro.',
    ],
    relacionados: [
      { titulo: 'Contas a pagar', href: '/contas-a-pagar' },
      { titulo: 'Contas a receber', href: '/contas-a-receber' },
    ],
  },

  'Financeiro/Previsao': {
    area: 'Financeiro',
    titulo: 'Previsão de caixa',
    paraQueServe: 'Projeta quanto entra e quanto sai nos próximos meses a partir das parcelas a receber e a pagar que ainda estão em aberto, e avisa em que mês o caixa ficaria negativo.',
    comoUsar: [
      {
        titulo: 'Escolher o horizonte da projeção',
        passos: [
          'No topo da tela, abra o seletor "Horizonte".',
          'Escolha 3, 6 ou 12 meses.',
          'A tabela, o gráfico e os cartões passam a considerar o novo período.',
        ],
      },
      {
        titulo: 'Ler a projeção mês a mês',
        passos: [
          'Compare "Saldo de caixa atual (realizado)", que é o dinheiro já em caixa hoje, com "Saldo previsto ao final do horizonte".',
          'Na tabela, olhe a coluna "Resultado" de cada competência: é o que entra menos o que sai naquele mês.',
          'Acompanhe "Saldo acumulado previsto" para saber como o caixa evolui de mês a mês.',
          'Linhas em vermelho, com a etiqueta "Negativo", são os meses em que o caixa previsto fica no vermelho.',
        ],
      },
      {
        titulo: 'Agir sobre um mês negativo',
        passos: [
          'Se aparecer o aviso vermelho no topo, anote o mês indicado.',
          'Vá a contas a receber e antecipe a cobrança do que vence naquele mês.',
          'Vá a contas a pagar e reveja o que pode ser renegociado ou remarcado para outra competência.',
        ],
      },
    ],
    campos: [
      { nome: 'Competência', descricao: 'O mês ao qual a projeção se refere.' },
      { nome: 'Realizado x Previsto', descricao: 'Realizado é o saldo já fechado até hoje, com dinheiro que entrou e saiu de verdade. Previsto é expectativa, montada em cima de parcelas ainda em aberto, e pode não se confirmar.' },
    ],
    dicas: [
      'No gráfico, a linha sólida verde é o realizado e a linha tracejada azul é a previsão — elas nunca se misturam no mesmo traço.',
    ],
    relacionados: [
      { titulo: 'Fluxo de caixa', href: '/cash-flow' },
      { titulo: 'Contas a receber', href: '/contas-a-receber' },
    ],
  },

  'Cobrancas/Index': {
    area: 'Financeiro',
    titulo: 'Cobranças',
    paraQueServe: 'Lista os boletos e Pix emitidos para os clientes, com o link e o código de pagamento de cada um. É daqui que você envia a cobrança, tenta emitir de novo o que falhou e cancela o que não deve mais ser pago.',
    comoUsar: [
      {
        titulo: 'Localizar uma cobrança',
        passos: [
          'Escolha a "Situação" para separar as pendentes, emitidas, pagas, vencidas, canceladas ou com erro.',
          'Use "Tipo" para ver só boleto ou só Pix.',
          'Informe "Vencimento de" e "Vencimento até" e, se precisar, escolha o "Cliente".',
        ],
      },
      {
        titulo: 'Enviar a cobrança ao cliente',
        passos: [
          'Clique em "Copiar Pix" ou "Copiar linha digitável" para levar o código para onde você quiser colar.',
          'Use "Abrir link" para ver a página de pagamento gerada pelo gateway.',
          'Clique no botão de WhatsApp para abrir a conversa com a mensagem de cobrança já montada.',
          'Clique em "E-mail" para abrir o e-mail preenchido — ele só aparece quando o cliente tem e-mail cadastrado e aceita esse canal.',
        ],
      },
      {
        titulo: 'Reemitir ou cancelar',
        passos: [
          'Em uma cobrança com erro, vencida ou cancelada, clique em "Tentar novamente" para emitir de novo.',
          'Para encerrar uma cobrança pendente, emitida ou vencida, clique em "Cancelar".',
          'Escreva o "Motivo" com pelo menos 5 caracteres e clique em "Confirmar cancelamento".',
        ],
      },
    ],
    campos: [
      { nome: 'Situação "Erro"', descricao: 'A emissão foi tentada mas o gateway recusou. A mensagem do erro aparece embaixo da etiqueta, e a cobrança pode ser reemitida.' },
    ],
    atencao: [
      'Quando o aviso vermelho de ambiente de teste (sandbox) estiver na tela, os links, boletos e códigos Pix não são reais. Não envie nada disso para um cliente de verdade — confira o ambiente em Configuração antes.',
      'Depois de cancelada, o cliente não consegue mais pagar por aquele link ou código. O motivo informado é o que explica o cancelamento depois.',
    ],
    relacionados: [
      { titulo: 'Contas a receber', href: '/contas-a-receber' },
      { titulo: 'Conciliação de cobrança', href: '/cobrancas/conciliacao' },
      { titulo: 'Configuração de cobrança', href: '/cobrancas/configuracao' },
    ],
  },

  'Cobrancas/Conciliacao': {
    area: 'Financeiro',
    titulo: 'Conciliação de cobrança',
    paraQueServe: 'Compara o que o gateway de pagamento confirmou com o que foi baixado no sistema, mostrando as diferenças de um período. É a tela para descobrir pagamento que o cliente fez e que não chegou a virar baixa.',
    comoUsar: [
      {
        titulo: 'Escolher o período conferido',
        passos: [
          'Preencha "De" e "Até".',
          'Clique em "Filtrar".',
          'Os três blocos abaixo passam a mostrar apenas o que aconteceu nesse intervalo.',
        ],
      },
      {
        titulo: 'Resolver eventos sem baixa',
        passos: [
          'Comece pelo bloco "Eventos sem baixa": são os casos em que o gateway confirmou o pagamento mas a cobrança não foi baixada.',
          'Leia a coluna "Motivo" e, quando houver, o "Último erro" da linha.',
          'Clique em "Reprocessar" para o sistema tentar aplicar o pagamento de novo.',
          'Confira a mensagem do resultado: se ainda ficar pendente, a linha permanece na lista.',
        ],
      },
      {
        titulo: 'Interpretar os outros dois blocos',
        passos: [
          'Em "Baixas sem evento" estão as parcelas quitadas sem passar por boleto ou Pix — recebimento em dinheiro ou baixa manual. Isso não é falha, é só informação.',
          'Em "Valores divergentes", o gateway e o sistema concordam que foi pago, mas por valores diferentes; a coluna "Diferença" mostra o tamanho do problema.',
          'Investigue cada divergência com o número da cobrança antes de ajustar qualquer coisa no financeiro.',
        ],
      },
    ],
    campos: [
      { nome: 'Valor no gateway', descricao: 'Quanto o provedor de pagamento diz que o cliente pagou.' },
      { nome: 'Valor baixado no sistema', descricao: 'Quanto foi registrado como recebido na parcela correspondente.' },
    ],
    atencao: [
      'Reprocessar um evento aplica o pagamento no financeiro de verdade: a parcela é baixada e o valor entra no caixa. Confira o valor do evento antes de clicar.',
      'Valor divergente não se resolve nesta tela. Corrija pela parcela em contas a receber, com baixa complementar ou estorno, para o histórico ficar coerente.',
    ],
    relacionados: [
      { titulo: 'Cobranças', href: '/cobrancas' },
      { titulo: 'Contas a receber', href: '/contas-a-receber' },
    ],
  },

  'Cobrancas/Configuracao': {
    area: 'Financeiro',
    titulo: 'Configuração de cobrança',
    paraQueServe: 'Guarda a credencial do gateway de pagamento usado para emitir boletos e Pix, e define a régua de cobrança: quando os avisos automáticos saem e com quanta antecedência a cobrança da próxima parcela do contrato é emitida sozinha.',
    comoUsar: [
      {
        titulo: 'Cadastrar a credencial do gateway',
        passos: [
          'Escolha o "Ambiente": "Sandbox (teste)" para experimentar, "Produção" para cobranças reais.',
          'Cole o "Token de acesso" da conta do provedor.',
          'Marque "Gateway ativo (permite emitir cobranças)" quando quiser liberar a emissão.',
          'Clique em "Salvar credencial".',
          'Clique em "Validar credencial" para confirmar na hora que o provedor aceitou o token.',
        ],
      },
      {
        titulo: 'Ajustar a régua de cobrança',
        passos: [
          'Marque "Régua ativa (avisos automáticos de vencimento)" para ligar os avisos.',
          'Em "Marcos ativos", escolha em quais momentos o aviso sai (antes, no dia ou depois do vencimento).',
          'Preencha "Antecedência da emissão automática (dias)": quantos dias antes do vencimento a cobrança da próxima parcela do contrato é emitida sozinha.',
          'Marque "Cliente pode gerar a própria cobrança pelo portal" se quiser liberar essa opção.',
          'Clique em "Salvar régua".',
        ],
      },
    ],
    campos: [
      { nome: 'Token de acesso', descricao: 'Por segurança, o token salvo nunca é mostrado de volta. Deixe o campo em branco para manter o atual e mudar só o ambiente ou a ativação.' },
      { nome: 'Verificada em', descricao: 'Data e hora da última validação bem-sucedida da credencial com o provedor.' },
    ],
    atencao: [
      'Enquanto o ambiente salvo for "Sandbox (teste)", nenhuma cobrança emitida é real. Só troque para "Produção" depois de validar a credencial de produção.',
      'A régua dispara avisos e emite cobranças automaticamente para clientes reais. Confira os marcos escolhidos antes de ativá-la.',
    ],
    relacionados: [
      { titulo: 'Cobranças', href: '/cobrancas' },
      { titulo: 'Conciliação de cobrança', href: '/cobrancas/conciliacao' },
    ],
  },

  'Fiscal/Notas': {
    area: 'Financeiro',
    titulo: 'Notas fiscais',
    paraQueServe: 'Acompanha as NFS-e da empresa: consulta a situação de cada nota, baixa o PDF e o XML, cancela, substitui e reprocessa as que deram erro.',
    comoUsar: [
      {
        titulo: 'Encontrar uma nota',
        passos: [
          'Escolha a "Situação" para separar as emitidas, as canceladas ou as que estão com erro.',
          'Selecione o "Cliente" e informe "Competência de" e "Competência até".',
          'Clique em "Filtrar" — ou em "Limpar" para voltar à lista completa.',
          'Enquanto houver nota aguardando a prefeitura, a lista se atualiza sozinha a cada 30 segundos.',
        ],
      },
      {
        titulo: 'Baixar os arquivos da nota',
        passos: [
          'Localize a nota na lista.',
          'Clique em "PDF" para abrir o documento da nota.',
          'Clique em "XML" para baixar o arquivo que o contador costuma pedir.',
        ],
      },
      {
        titulo: 'Cancelar uma nota emitida',
        passos: [
          'Na linha da nota, clique em "Cancelar".',
          'Escreva o "Motivo" com pelo menos 15 caracteres.',
          'Clique em "Solicitar cancelamento" — o pedido vai para a prefeitura e fica registrado no histórico fiscal.',
        ],
      },
      {
        titulo: 'Substituir uma nota com dado errado',
        passos: [
          'Clique em "Substituir" na linha da nota emitida.',
          'Escreva o "Motivo" com pelo menos 15 caracteres.',
          'Ajuste o "Valor do serviço", a "Competência" e a "Descrição do serviço" conforme o correto.',
          'Clique em "Emitir substituta".',
        ],
      },
    ],
    campos: [
      { nome: 'Processando', descricao: 'A nota foi aceita e a prefeitura ainda está respondendo. Nada precisa ser feito: a tela se atualiza quando o retorno chega.' },
      { nome: 'Erro', descricao: 'A nota não foi aceita. A mensagem do motivo aparece na linha, e o botão "Reprocessar" tenta de novo depois de corrigir o dado.' },
      { nome: 'Origem', descricao: 'Mostra de onde a nota nasceu: uma ordem de serviço ou um título a receber.' },
    ],
    atencao: [
      'Nota fiscal emitida é documento com valor perante a fiscalização. Cancelamento e substituição são pedidos enviados à prefeitura e ficam registrados no histórico — não é possível apagar uma nota.',
      'Quando o aviso de ambiente de homologação estiver na tela, as notas são testes e não devem ser enviadas ao cliente como documento válido.',
    ],
    relacionados: [
      { titulo: 'Pendências fiscais', href: '/notas/pendencias' },
      { titulo: 'Configuração fiscal', href: '/fiscal/configuracao' },
      { titulo: 'Contas a receber', href: '/contas-a-receber' },
    ],
  },

  'Fiscal/Pendencias': {
    area: 'Financeiro',
    titulo: 'Pendências fiscais',
    paraQueServe: 'Agrupa as notas que deram erro pelo motivo da recusa, para você corrigir o cadastro que está causando o problema e reprocessar todas de uma vez.',
    comoUsar: [
      {
        titulo: 'Entender o que está travando',
        passos: [
          'Cada bloco vermelho é um motivo de recusa, com a quantidade de notas afetadas.',
          'Leia o texto do motivo: ele diz qual dado a prefeitura recusou.',
          'Veja em "Clientes afetados" quais cadastros precisam de ajuste.',
        ],
      },
      {
        titulo: 'Corrigir o cadastro do cliente',
        passos: [
          'Na linha do cliente, clique em "Corrigir cadastro".',
          'Ajuste os dados fiscais: nome ou razão social, CPF ou CNPJ, e-mails, telefone e inscrições.',
          'Em "Endereço usado na nota", escolha um endereço já cadastrado ou "Cadastrar novo endereço" e preencha os campos, inclusive o "Código do município (IBGE)".',
          'Clique em "Salvar correção".',
        ],
      },
      {
        titulo: 'Reprocessar o grupo',
        passos: [
          'Depois de corrigir os cadastros, volte ao bloco do motivo.',
          'Clique em "Reprocessar todas deste grupo".',
          'Leia o aviso do resultado: ele informa quantas notas continuam com erro e quantas foram aceitas e aguardam a prefeitura.',
        ],
      },
    ],
    campos: [
      { nome: 'Substituições do grupo', descricao: 'Quando o bloco avisa que algumas substituições precisam ser refeitas pela nota original, essas notas não entram no reprocessamento em lote — abra a nota original e refaça a substituição.' },
    ],
    atencao: [
      'O reprocessamento emite notas fiscais de verdade. Corrija o cadastro antes de reprocessar, senão a recusa se repete.',
      'Com o ambiente de homologação ativo, o reprocessamento continua gerando notas de teste.',
    ],
    relacionados: [
      { titulo: 'Notas fiscais', href: '/notas' },
      { titulo: 'Configuração fiscal', href: '/fiscal/configuracao' },
    ],
  },

  'Fiscal/Configuracao': {
    area: 'Financeiro',
    titulo: 'Configuração fiscal',
    paraQueServe: 'Guarda os dados usados em toda emissão de NFS-e da empresa: credencial do provedor, tributação do serviço, numeração e a decisão de emitir notas automaticamente.',
    comoUsar: [
      {
        titulo: 'Informar provedor e credencial',
        passos: [
          'Confira o "Provedor" e escolha o "Ambiente": "Homologação" para testes, "Produção" para notas válidas.',
          'Preencha "Client ID" e "Client secret" com as credenciais do provedor.',
          'Se a credencial já estiver salva, deixe os dois campos vazios para mantê-la.',
          'Clique em "Validar e salvar" — o sistema confere a credencial antes de gravar.',
        ],
      },
      {
        titulo: 'Preencher a tributação do serviço',
        passos: [
          'Escolha o "Regime tributário" da empresa.',
          'Informe o "Código nacional do serviço", o "CNAE" e a "Alíquota do ISS (%)".',
          'Escolha a "Natureza da operação".',
          'Preencha a "Série" e o "Próximo número" da numeração das notas.',
          'Marque "ISS retido pelo tomador" e "Exigir inscrição municipal" conforme a orientação do contador.',
          'Clique em "Validar e salvar".',
        ],
      },
      {
        titulo: 'Ligar a emissão automática',
        passos: [
          'Marque "Emitir notas automaticamente".',
          'Leia a confirmação e clique em "Confirmar ativação".',
          'Escolha em "Quando emitir" o gatilho: "Ao concluir a ordem de serviço" ou "Ao quitar o título a receber".',
          'Clique em "Validar e salvar".',
        ],
      },
    ],
    campos: [
      { nome: 'Client ID e Client secret', descricao: 'Credenciais do provedor. Os campos sempre aparecem vazios porque a credencial salva nunca volta do servidor.' },
      { nome: 'Próximo número', descricao: 'Número que a próxima nota emitida vai receber. Mexer nele desalinha a numeração fiscal da empresa.' },
    ],
    atencao: [
      'A emissão automática gera consequência fiscal sem confirmação manual: cada operação elegível vira nota. Ative somente após a decisão do contador da empresa.',
      'Trocar o ambiente para "Produção" faz toda emissão seguinte valer como documento fiscal real.',
    ],
    relacionados: [
      { titulo: 'Notas fiscais', href: '/notas' },
      { titulo: 'Pendências fiscais', href: '/notas/pendencias' },
    ],
  },

  'Assinatura/Show': {
    area: 'Financeiro',
    titulo: 'Assinatura do sistema',
    paraQueServe: 'Mostra o plano contratado pela sua empresa no sistema, a próxima cobrança, a fatura em aberto e o histórico de faturas dos últimos 12 meses.',
    comoUsar: [
      {
        titulo: 'Conferir o plano e a próxima cobrança',
        passos: [
          'No cartão do topo, veja o nome do plano, o valor e a periodicidade.',
          'Confira a etiqueta de situação: "Ativa", "Em atraso", "Suspensa" ou "Cancelada".',
          'Leia "Próxima cobrança" e "Forma de pagamento" para saber quando e como será cobrado.',
        ],
      },
      {
        titulo: 'Pagar a fatura em aberto',
        passos: [
          'Localize o bloco "Fatura em aberto", com a referência, o valor e o vencimento.',
          'Clique em "Pagar agora" para abrir a página de pagamento.',
          'Ou clique em "Copiar" ao lado do "Pix copia e cola" ou da "Linha digitável do boleto" e pague pelo aplicativo do banco.',
        ],
      },
      {
        titulo: 'Trocar a forma de pagamento e ver faturas antigas',
        passos: [
          'Clique em "Trocar forma de pagamento".',
          'Escolha entre "Pix" e "Boleto" e clique em "Salvar".',
          'Na tabela "Faturas dos últimos 12 meses", clique em "Segunda via" na linha da fatura que quiser reabrir.',
        ],
      },
    ],
    campos: [
      { nome: 'Referência', descricao: 'O período a que a fatura se refere.' },
      { nome: 'Plano interno', descricao: 'Empresas operadas internamente não são cobradas: nesse caso não há fatura nem forma de pagamento a gerenciar.' },
    ],
    dicas: [
      'Quando a forma de pagamento é cartão, a cobrança é automática e nenhuma ação é necessária na fatura em aberto.',
    ],
    atencao: [
      'Esta tela trata da assinatura da sua empresa no sistema, não das cobranças enviadas aos seus clientes. Para essas, use a tela de cobranças.',
    ],
    relacionados: [
      { titulo: 'Cobranças', href: '/cobrancas' },
    ],
  },

  'Assinaturas/Configuracao': {
    area: 'Financeiro',
    titulo: 'Assinatura eletrônica',
    paraQueServe: 'Guarda a credencial do provedor que assina os contratos da empresa eletronicamente e mostra o endereço de webhook a cadastrar no painel desse provedor.',
    comoUsar: [
      {
        titulo: 'Cadastrar a credencial',
        passos: [
          'Escolha o "Provedor" e o "Ambiente": "Sandbox (teste)" ou "Produção".',
          'Cole o "Token da API" fornecido pelo provedor.',
          'Preencha o "Segredo do webhook" apenas se o provedor permitir cabeçalho próprio de autenticidade.',
          'Marque "Integração ativa" e clique em "Salvar".',
        ],
      },
      {
        titulo: 'Conferir se a credencial funciona',
        passos: [
          'Clique em "Validar credencial".',
          'Leia o "Resultado" no quadro cinza: ele diz se o provedor aceitou ou recusou a credencial.',
          'Confira também a "Última verificação" para saber quando isso foi testado pela última vez.',
        ],
      },
      {
        titulo: 'Cadastrar o webhook no provedor',
        passos: [
          'Clique em "Mostrar endereço do webhook".',
          'Copie o endereço exibido no quadro azul.',
          'Cadastre-o no painel do provedor, para o sistema receber o aviso quando um contrato for assinado.',
        ],
      },
    ],
    campos: [
      { nome: 'Token da API', descricao: 'O token nunca volta do servidor depois de salvo: o campo fica vazio de propósito. Deixe em branco para manter o que já está cadastrado.' },
    ],
    atencao: [
      'Nada assinado no ambiente de teste (sandbox) tem validade jurídica. Percorra o ciclo inteiro nele e só depois troque para produção.',
      'O endereço do webhook contém um segredo desta empresa: não o compartilhe fora da configuração do provedor.',
    ],
  },
};
