# Handoff

Estado: **os 31 planos do roteiro estão concluídos e mergeados em `main`.**
Nada foi publicado — push segue pendente de autorização explícita.

Não há plano pendente. O que resta são decisões de rollout e a dívida técnica
registrada abaixo.

## O que esta sessão fez

Nenhum plano novo a executar. O `run-plan` encontrou o roteiro fechado e fez só
o acerto de estado que faltava:

- **`.claude/tasks/31/INDEX.md` ficou para trás.** O commit `6cacca2` fechou o
  Plano 31 no índice de planos, mas as cinco tasks continuavam ⏳ no índice de
  tasks, embora `15a9caf`..`71e5203` tenham entregue todas. Marcadas ✅ depois de
  conferir entrega e testes, não pela mensagem de commit.
- **Validação do Plano 31 refeita nesta sessão**, já que a anterior não deixou
  evidência: `php artisan test --filter=Route` → 32 testes, 136 asserções,
  exit 0 (71s). `npm run build` limpo.
- O rótulo `DEPR` em toda a saída continua sendo ruído pré-existente do PHP 8.5
  (`PDO::MYSQL_ATTR_SSL_CA`, `config/database.php:81`), não é falha.

Os arquivos do Plano 31 conferem com o que as tasks previam: `RouteStop`,
`RouteService`, `OtimizadorDeRota`, `RouteController`, `ReordenarRotaRequest`,
`Roteiros/Index.vue`, `MapaDeVisitas.vue` e os dois arquivos de teste.

## Planos 30 e 31 — decisões que a próxima sessão precisa conhecer

1. **O contrato de reordenação de roteiro mudou de forma incompatível:**
   `work_order_ids: [int]` virou `paradas: ["os:N", "compromisso:N"]`. Não houve
   período de transição — backend e frontend mudaram juntos dentro do Plano 31.
   Qualquer cliente antigo do endpoint quebra.
2. **`tipo_item` é o discriminador único** entre OS e compromisso, o mesmo nome
   usado pelo `AgendaService::doPeriodo()` do Plano 30, de propósito.
3. **Compromisso sem `address_id` recebe o mesmo tratamento de OS sem
   geocodificação:** fica fora do cálculo de distância e aparece na lista de
   "paradas sem coordenada" do mapa.
4. **Nenhuma task do Plano 31 toca schema.** Deploy único, sem etapas.

## Planos 28 e 29 — decisões ainda válidas

1. **Uma só regra de troca vencida.** O predicado público
   `AlertaDeEpiService::trocaVencida()` é a autoridade; `recorteDeTrocaVencida()`
   é só recorte SQL. `SituacaoDeEpiService` e o checklist consomem o predicado —
   nenhum compara data de EPI por conta própria.
   `SituacaoDeEpiServiceTest::test_a_situacao_e_o_alerta_nunca_discordam_sobre_a_mesma_entrega`
   quebra se alguém escrever uma segunda comparação.
2. **`sem_assinatura` é situação própria**, nunca colapsada em `em_dia`.
3. **A idempotência da sincronização tem três camadas** no
   `ConfirmacaoDeEpiService`: deduplicação no lote, `lockForUpdate` na transação,
   e captura da violação de unique convertida em atualização. O reenvio preserva
   o `confirmado_em` original.
4. **EPI não exigido pela OS é ignorado em silêncio, não recusado** — recusar
   faria a fila falhar em campo, sem tela onde corrigir.
5. **`ChecklistService::verificar()` apaga a linha do item que saiu do
   checklist**, senão desligar o módulo deixaria um irregular eterno no painel.
6. **A carga do dia leva só `id`, `nome`, `tipo` e `obrigatorio`** (~78 bytes por
   EPI por OS). Há teste que falha se CA, validade ou fabricante vazarem.
7. **Carga incremental (`desde`) não reflete mudança de exigência** até a OS
   mudar ou vir carga completa. Mesmo comportamento de `servicos` e
   `produtos_previstos`.
8. **Nada bloqueia a execução da OS.** `podeConcluir` não foi tocado — é
   deliberado e é a decisão mais provável de ser questionada.

## Pendências que exigem decisão humana

1. **Texto legal da ficha de EPI.** Ficou mais conservador no Plano 28, mas
   continua sendo texto de documento com valor perante fiscalização do trabalho.
   Merece leitura do responsável técnico antes do Deploy 1.
2. **Push.** `main` local está à frente do remoto desde o Plano 24. Nada foi
   publicado.

## Dívida técnica acumulada

- **`RelatorioPdfServiceTest.php:277` falha em qualquer máquina** que não seja a
  do autor original: grava num caminho absoluto de scratchpad
  (`/private/tmp/claude-501/-Users-miqueias-...`). Vem do Plano 21. **É uma linha
  para consertar** e destravaria a suíte completa para sempre.
- **Conflito `registro_alterado` ao sincronizar tudo de uma vez** depois do modo
  avião: o `execucao/iniciar` do mesmo lote muda `work_orders.updated_at`, e o
  `execucao/concluir` leva o `updated_at_conhecido` velho da carga do dia.
  Reproduzido em OS sem EPI nenhum — é dos Planos 12/13. Afeta o técnico em
  campo; é o achado mais relevante em aberto.
- **Instante do aparelho e fuso.** `AplicadorDeConfirmacaoDeEpi` converte para
  UTC antes de gravar; `AplicadorDeExecucao` e `AplicadorDeAdequacao` não. Hoje
  não é bug (o app manda `toISOString()`, já UTC com `Z`). Passa a ser se o app
  um dia enviar ISO com offset.
- `resources/js/app-tecnico/` e `resources/js/utils/epi.js` mantêm cópias em JS
  dos rótulos de tipo de EPI, sem nada que as amarre às constantes PHP.
- `assinatura_path` viaja no payload da ficha e o PNG fica em disco público com
  `storage:link`. Padrão pré-existente (mesmo de `WorkOrderSignatureService`).
- `montarCsvDeEntregas` e `trocasVencidas()` carregam tudo em memória.
- O item de EPI do checklist roda duas consultas por técnico ativo. Custo zero
  com o módulo desligado. Se a equipe crescer, consertar no
  `SituacaoDeEpiService` com um método que avalie a equipe inteira — não
  duplicar a regra no checklist.
- `epi.entregas.index` e `epi.show` não são consumidos por nenhuma tela.
- `assertCount(18, RotinasAgendadas::DIARIAS)` é contagem global sem recorte por
  domínio.
- `routes/web.php` reprovado no `ordered_imports` do Pint por imports herdados
  dos Planos 27 e anteriores. O projeto não usa Pint como gate.
- Relação `devices` ausente em `ServiceOrder`; marcador de conflito no
  `.gitignore`.

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
   vários marcos de uma vez. Medir com `--dry-run` antes de ligar a rotina.
8. **Cadastrar a exigência de EPI por serviço antes de anunciar a etapa aos
   técnicos.** Serviço sem exigência não mostra a etapa.
9. **A carga do dia cresce.** Medir antes e depois: é onde o app fica pesado, e
   quem sofre é quem está no campo com sinal ruim.
10. As migrations dos dois planos são inteiramente aditivas: quatro tabelas
    novas, nenhuma coluna em tabela com dado em produção.

## Limpeza pendente

- Worktrees e branches `.claude/worktrees/plano-2{4,5,6,7}`, já mergeados.
- Branches `plano-28` e `plano-29`, já mergeadas.
- Bancos de teste `testing_2{4,5,6,7}`, `testing_28a/b/c`, `testing_29a/b/c`,
  `testing_base`, `testing_fix_a/b/c`, `testing_merge`,
  `testing_nfse_29450_ca61782f`.
