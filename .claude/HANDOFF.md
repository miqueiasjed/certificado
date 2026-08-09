# Handoff

Estado: **os 29 planos do roteiro estão concluídos e mergeados em `main`.**
Nada foi publicado — push segue pendente de autorização explícita.

Não há plano pendente. O que resta são decisões de rollout e a dívida técnica
registrada abaixo.

## O que esta sessão fez

Fechou o Plano 28 (revisão independente, correções e merge) e executou o Plano
29 inteiro, das 6 tasks ao merge.

**Sobre paralelismo entre planos:** não existe mais a esta altura do roteiro. O
pedido desta sessão era rodar o máximo de planos independentes em paralelo, e só
restavam o 28 e o 29 — com o 29 dependendo do 28. O paralelismo aplicado foi
entre agentes: dois revisores simultâneos, dois agentes de correção em arquivos
disjuntos, e o Lote 3 do Plano 29 (`29.3 ‖ 29.4`). Cada agente com banco próprio
(`testing_28b/c`, `testing_29a/b/c`) e lista explícita de arquivos proibidos.
Nenhum conflito de escrita ocorreu.

## Plano 28 — o que a revisão independente encontrou

Ela não tinha chegado a rodar antes (o agente morreu por limite de sessão da
API). Rodou dividida em dois revisores com contexto limpo. Corrigido em
`a0aad88`:

1. **Crítico — a ficha pedia assinatura de recebimento em entrega estornada.**
   O fecho do PDF (declaração + linha de assinatura do trabalhador) era liberado
   pela contagem bruta de entregas. Técnico cuja **única** entrega tivesse sido
   estornada recebia um documento que imprimia a linha marcada como estornada e,
   abaixo, o convidava a declarar que recebeu. Num documento oponível, isso é
   prova contra quem emitiu. A guarda passou a ser a contagem de válidas, e a
   linha estornada deixou de exibir o badge de pendência, que contradizia o
   resumo do rodapé na mesma folha.
2. **O texto legal afirmava o que o sistema não registra.** A declaração dizia
   que o trabalhador recebeu os equipamentos "em perfeitas condições de uso, bem
   como as orientações sobre a sua utilização". Não há coluna, campo, evento nem
   tela em todo o Plano 28 que registre qualquer das duas coisas, e o rodapé da
   mesma folha diz que treinamento e uso em campo não são verificados. As
   afirmações saíram.
3. **Dois buracos de alerta.** Um substituto já devolvido calava o aviso da
   entrega antiga sem entrar ele próprio na varredura — técnico sem EPI nenhum e
   sem aviso nenhum. E troca vencida de técnico desligado avisava toda semana,
   indefinidamente.
4. **`epi-ver` saiu do papel `leitura`** — decisão do usuário nesta sessão. O
   filtro por sufixo do seeder a entregava a qualquer usuário de leitura, e com
   ela a ficha completa dos técnicos e o CSV de todos eles. O precedente já
   estava no mesmo método: `comissoes-ver` está nas exceções porque dado pessoal
   não entra em papel dado por padrão.

## Plano 29 — decisões que a próxima sessão precisa conhecer

1. **Uma só regra de troca vencida.** Ela não estava extraível: vivia inline na
   consulta de `AlertaDeEpiService::trocasVencidas()`. Virou o predicado público
   `trocaVencida()` com o recorte SQL `recorteDeTrocaVencida()` ao lado. **O
   predicado é a autoridade; o recorte é só recorte.** `SituacaoDeEpiService` e o
   checklist consomem o predicado — nenhum deles compara data de EPI.
   `SituacaoDeEpiServiceTest::test_a_situacao_e_o_alerta_nunca_discordam_sobre_a_mesma_entrega`
   é o teste que quebra se alguém escrever uma segunda comparação.
2. **`sem_assinatura` é situação própria**, nunca colapsada em `em_dia`. É a
   única pendência que a NR-6 realmente cobra.
3. **A idempotência da sincronização tem três camadas** no
   `ConfirmacaoDeEpiService`: deduplicação dentro do lote, leitura com
   `lockForUpdate` na transação, e captura da violação de unique convertida em
   atualização. A unique sozinha transformaria o reenvio em falha permanente da
   fila. O reenvio **preserva o `confirmado_em` original**.
4. **EPI não exigido pela OS é ignorado em silêncio, não recusado.** O aparelho
   trabalha com a carga do dia, que pode ter sido baixada antes de alguém mexer
   na exigência no escritório; recusar faria a fila falhar em campo, sem tela
   onde corrigir.
5. **`verificar()` do `ChecklistService` agora apaga a linha do item que saiu do
   checklist.** O item de EPI é o primeiro item condicional do serviço; sem
   isso, quem ligasse o módulo, gravasse `irregular` e depois desligasse ficaria
   com um irregular eterno no painel, sem tela onde resolvê-lo.
6. **A carga do dia leva `id`, `nome`, `tipo` e `obrigatorio`** — cerca de 78
   bytes por EPI por OS, medido. CA, validade e fabricante ficam no servidor.
   Há teste que falha se algum deles vazar para o aparelho.
7. **Carga incremental (`desde`) não reflete mudança de exigência** até a OS
   mudar ou vir carga completa: mexer na exigência não toca `work_orders`. É o
   comportamento que `servicos` e `produtos_previstos` já têm; a alternativa
   reenviaria o roteiro inteiro por um clique no escritório.
8. **Nada bloqueia a execução da OS.** `podeConcluir` não foi tocado. É a
   decisão do plano mais provável de ser questionada, e é deliberada.

## Validação final

- **Suíte completa: 1744 testes, 12816 asserções, 1 falha** (536s).
  - `RelatorioPdfServiceTest.php:277` — **pré-existente**, do Plano 21: grava num
    caminho absoluto de scratchpad de outra máquina
    (`/private/tmp/claude-501/-Users-miqueias-...`). Falha em qualquer
    computador que não seja o do autor original. **É uma linha para consertar** e
    destravaria a suíte para sempre.
- `npm run build`: limpo.
- Plano 29 conferido no navegador de ponta a ponta, em modo offline: etapa
  aparecendo só quando o serviço exige, justificativa cobrada antes de
  enfileirar, OS concluindo com pendência, e a linha gravada no banco quando o
  sinal volta.
- O rótulo `DEPR` em toda a saída é ruído pré-existente do PHP 8.5
  (`PDO::MYSQL_ATTR_SSL_CA`, `config/database.php:81`), não é falha.

## Pendências que exigem decisão humana

1. **Texto legal da ficha de EPI.** Ficou mais conservador, mas continua sendo
   texto de documento com valor perante fiscalização do trabalho. Merece leitura
   do responsável técnico antes do Deploy 1.
2. **Push.** `main` local está à frente do remoto desde o Plano 24. Nada foi
   publicado.

## Dívida técnica levantada nesta sessão

- **Conflito `registro_alterado` ao sincronizar tudo de uma vez** depois do modo
  avião: o `execucao/iniciar` do mesmo lote muda `work_orders.updated_at`, e o
  `execucao/concluir` leva o `updated_at_conhecido` velho da carga do dia.
  **Reproduzido em OS sem EPI nenhum** — é comportamento dos Planos 12/13, não
  do 29. É o achado mais relevante desta sessão fora do escopo, porque afeta o
  técnico em campo.
- **Instante do aparelho e fuso.** `AplicadorDeConfirmacaoDeEpi` converte para
  UTC explicitamente antes de gravar. Os aplicadores mais antigos
  (`AplicadorDeExecucao`, `AplicadorDeAdequacao`) não convertem. Hoje **não é
  bug**: o app usa `new Date().toISOString()`, que já é UTC com `Z`. Passa a ser
  se algum dia o app enviar ISO com offset.
- `resources/js/app-tecnico/` e `resources/js/utils/epi.js` mantêm cópias em JS
  dos rótulos de tipo de EPI, sem nada que as amarre às constantes PHP.
- `assinatura_path` viaja no payload da ficha e o PNG fica em disco público com
  `storage:link`. Padrão pré-existente do projeto (mesmo de
  `WorkOrderSignatureService`), não regressão.
- `montarCsvDeEntregas` e `trocasVencidas()` carregam tudo em memória.
- O item de EPI do checklist roda duas consultas por técnico ativo. Custo zero
  com o módulo desligado. Se a equipe crescer, o lugar de consertar é o
  `SituacaoDeEpiService`, com um método que avalie a equipe inteira — não
  duplicar a regra no checklist.
- `epi.entregas.index` e `epi.show` não são consumidos por nenhuma tela.
- `assertCount(18, RotinasAgendadas::DIARIAS)` é contagem global sem recorte por
  domínio.
- `routes/web.php` reprovado no `ordered_imports` do Pint por imports herdados
  dos Planos 27 e anteriores. O projeto não usa Pint como gate.

## Ordem de aplicação em produção (28 e 29)

1. **Deploy 1** — Plano 28, tasks 28.1 a 28.5: estrutura, regras, endpoints e
   documentos, com o módulo `epi` **desligado** e a rotina `epi:verificar`
   parada.
2. **Deploy 2** — Plano 28, task 28.6: telas do escritório.
3. **Deploy 3** — Plano 29, tasks 29.1 a 29.4: estrutura, regras, endpoints e
   checklist, módulo ainda desligado.
4. **Deploy 4** — Plano 29, task 29.5: app do técnico e cadastro de serviço.
5. `php artisan permissions:sync` **e** o seeder de papéis precisam rodar nos
   deploys — só foram executados contra bancos de teste, e `epi-ver` mudou de
   papel.
6. Ligar o módulo por tenant. Cadastrar os modelos de EPI **com CA e validade**,
   lançar as entregas em aberto, e só então ligar a rotina.
7. **A primeira execução de `epi:verificar` gera uma leva grande de avisos**,
   porque entrega retroativa nasce com a troca vencida e um CA próximo dispara
   vários marcos de uma vez (as janelas de 60/30/7 dias são cumulativas). Medir
   com `--dry-run` antes de ligar a rotina.
8. **Cadastrar a exigência de EPI por serviço antes de anunciar a etapa aos
   técnicos.** Serviço sem exigência não mostra a etapa, e ligar sem cadastrar
   entrega uma funcionalidade invisível.
9. **A carga do dia cresce.** Medir antes e depois: é o ponto onde o app fica
   pesado, e quem sofre é quem está no campo com sinal ruim.
10. As migrations dos dois planos são inteiramente aditivas: quatro tabelas
    novas, nenhuma coluna em tabela com dado em produção. `down()` derruba só
    elas.

## Limpeza pendente

- Worktrees e branches `.claude/worktrees/plano-2{4,5,6,7}`, já mergeados.
- Branches `plano-28` e `plano-29`, já mergeadas.
- Bancos de teste `testing_28a/b/c`, `testing_29a/b/c` e `testing_merge`.
- Defeitos pré-existentes em `main`: `RelatorioPdfServiceTest:277`, a relação
  `devices` ausente em `ServiceOrder`, o marcador de conflito no `.gitignore`.
