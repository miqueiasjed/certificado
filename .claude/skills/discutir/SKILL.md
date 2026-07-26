---
name: discutir
description: Lê o PRD, a documentação e partes relevantes do código do Sistema de Certificados para discutir funcionalidades, comparar o que está documentado com o que está implementado e recomendar o que vale desenvolver ou corrigir. Análise e conversa, nunca escreve código.
---
# Skill: Discutir

Use esta skill quando o usuário quiser entender como algo funciona no sistema,
avaliar se uma funcionalidade está correta, decidir o que desenvolver a seguir,
ou comparar o que o PRD descreve com o que o código realmente faz.

**Esta skill é de análise e discussão. Nunca escreva código aqui.**

## Passo 1 - Entender o tema

1. Leia o argumento passado (ex.: `discutir contratos`, `discutir estoque`).
2. Sem tema definido, pergunte sobre qual parte do sistema é a conversa.
3. Identifique o domínio:
   - Clientes, endereços e cômodos
   - Dispositivos, eventos e avistamento de pragas
   - Ordens de serviço e execução em campo
   - Certificados e documentos emitidos
   - Contratos e recorrência
   - Orçamentos e comercial
   - Financeiro e fluxo de caixa
   - Cadastros técnicos regulatórios
   - Multiempresa, planos e cobrança da plataforma
   - Portal do cliente e notificações

## Passo 2 - Ler a fonte de negócio antes do código

1. `.claude/prd/README.md` para o mapa dos fragmentos e o estado atual.
2. O fragmento do domínio em questão:
   - `saas-multitenant.md` - isolamento, planos, assinaturas
   - `operacao-campo.md` - contrato recorrente, agenda, app do técnico, QR code
   - `relacionamento-cliente.md` - notificações, portal, agendamento, NPS
   - `financeiro-fiscal.md` - estoque, contas a receber e pagar, cobrança, NFS-e
   - `monitoramento-cip.md` - tendência, mapa de pontos, RDC 622/2022
   - `gestao-comercial.md` - comissões, metas, renovação, frota, IA
   - `divida-tecnica.md` - o que está quebrado hoje
3. `.claude/plans/INDEX.md` para saber o que está concluído, em andamento e
   pendente. Se o tema tiver plano dedicado, abra `.claude/plans/[N].md`.
4. `CLAUDE.md` para as convenções.

## Passo 3 - Conferir contra o código

Leia só o necessário para responder, e prefira ler o Service ao controller,
porque a regra de negócio vive em `app/Services/`.

Pontos de entrada úteis por domínio:

- Regra de negócio: `app/Services/`
- Schema e histórico de decisões: `database/migrations/`
- Rotas e o que está exposto: `routes/web.php`
- Telas: `resources/js/Pages/`
- Documentos gerados: `app/Services/` com `Company::current()` e as views de PDF

Quando o PRD e o código divergirem, diga qual dos dois está desatualizado, em
vez de assumir que o código está certo.

## Passo 4 - Responder

- Comece pela conclusão, não pelo caminho percorrido.
- Cite arquivo e linha quando afirmar algo sobre o código.
- Separe o que **existe hoje**, o que **está previsto em plano** e o que **não
  existe em lugar nenhum**. Essa distinção é o valor da conversa.
- Dê recomendação com o motivo em uma linha. Se depender de algo, diga de que
  depende e qual opção você escolheria no cenário mais provável.
- Ao listar alternativas, marque a favorita e explique por que descartou as
  outras.
- Discorde quando for o caso, inclusive do usuário.
- Lembre que o sistema roda em produção para um cliente real: ao recomendar
  mudança em schema, dado ou documento emitido, diga qual o risco para ele.

## Passo 5 - Fechar com o próximo passo

Termine indicando o que fazer a seguir de forma concreta: qual plano executar,
qual plano criar, ou qual decisão de negócio precisa ser tomada antes.
