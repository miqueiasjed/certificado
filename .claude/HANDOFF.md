# Handoff

Plano: 28 — Controle de EPI: cadastro, CA e ficha de entrega
Estado: **Todas as 7 tasks concluídas.** Revisão independente feita e as
correções aplicadas. Falta a suíte completa fechar e o merge em `main`.
Base Git: branch `plano-28`, sobre `ff61313`

Os planos 1 a 27 continuam concluídos e mergeados em `main`, sem push.

## Sobre "rodar planos em paralelo"

Não existe paralelismo entre planos a esta altura do roteiro: dos 29, os planos
1 a 27 estão fechados, e o 29 depende do 28. O paralelismo aplicável é **entre
tasks e entre agentes**, e foi o usado aqui — dois revisores independentes ao
mesmo tempo, depois dois agentes de correção em arquivos disjuntos, cada um com
banco de teste próprio (`testing_28b` e `testing_28c`). Nenhum conflito de
escrita ocorreu.

## Próxima ação

1. Conferir o resultado da suíte completa (`DB_DATABASE=testing_merge`).
2. Merge de `plano-28` em `main`, no padrão sequencial dos planos 24 a 27.
3. Plano 29 — EPI em campo e na conformidade. Lotes do
   `.claude/tasks/29/INDEX.md`: `29.1` → `29.2` → `29.3 ‖ 29.4` → `29.5` →
   `29.6`.

## A revisão independente do Plano 28

Não tinha chegado a rodar na sessão anterior (o agente morreu por limite de
sessão da API). Rodou agora, dividida em dois revisores paralelos com contexto
limpo: um de domínio (models, services, alertas, documento) e um de superfície
(endpoints, permissões, multiempresa, frontend).

**Um achado crítico e cinco médios foram corrigidos.** O que segue é o que
importa saber depois.

### O crítico: a ficha pedia assinatura de recebimento em entrega estornada

`ppe-record.blade.php` liberava o fecho — declaração + linha de assinatura do
trabalhador — com `@if(count($entregas) > 0)`. Um técnico cuja **única** entrega
tivesse sido estornada recebia um PDF que imprimia a linha marcada como
estornada e, logo abaixo, convidava o trabalhador a declarar que recebeu.

Num documento oponível, assinatura de recebimento de item que a própria empresa
registrou como inexistente é prova contra quem emitiu. A guarda passou a ser
`$resumo['validas'] > 0`, e a declaração ressalva os estornos no texto.

Pelo mesmo motivo, a linha estornada deixou de exibir o badge "Pendente de
assinatura": o resumo do rodapé já a excluía da contagem, e a célula contradizia
o resumo na mesma folha.

### O texto legal da ficha foi reescrito

A declaração afirmava que o trabalhador recebeu os equipamentos "em perfeitas
condições de uso, bem como as orientações sobre a sua utilização". **Não existe
coluna, campo, evento nem tela em todo o Plano 28 que registre orientação,
treinamento ou estado de conservação** — e o rodapé da mesma folha diz que
treinamento, uso em campo, higienização e guarda não são verificados por este
sistema. O documento afirmava contra si mesmo.

As duas afirmações saíram. A declaração ficou restrita ao que a ficha prova:
recebimento dos itens listados, nas datas e com os CA listados. Os compromissos
que o trabalhador assume ao assinar (usar para a finalidade, guardar, comunicar
alteração, devolver ao fim do contrato) foram mantidos — são obrigações
assumidas, não fatos que o sistema deveria ter registrado.

**Continua valendo o que o handoff anterior pediu: o responsável técnico deve
ler o texto antes do Deploy 1.** Ele está mais conservador, não dispensado de
conferência.

### Dois buracos de alerta

- **Substituto devolvido calava a entrega antiga.** A consulta de "entrega mais
  recente" só excluía estornadas, e as candidatas a aviso excluíam devolvidas.
  Entrega de 200 dias com troca vencida, substituto entregue há 10 e devolvido
  há 5: o técnico estava sem respirador nenhum e o sistema não avisava nada.
  Agora só cala a linha antiga o substituto que **está com o técnico**.
- **Técnico desligado avisava toda semana, para sempre.** `trocasVencidas()` não
  filtrava `is_active`, enquanto a varredura de CA já filtrava EPI inativo.
  Corrigido por simetria. O item não devolvido no desligamento continua sendo
  problema de escritório: não é pendência de troca e não tem aviso próprio.

### Decisão de produto tomada nesta sessão: `epi-ver` saiu do papel `leitura`

O filtro por sufixo de `RolesAndPermissionsSeeder::permissoesLeitura()` dava
`epi-ver` a qualquer usuário de leitura — e com ela a ficha completa dos
técnicos (nome, matrícula, histórico, assinatura) e o CSV de **todos** os
técnicos do período.

O precedente contrário já existia no mesmo método: `comissoes-ver` está na lista
de exceções porque quanto uma pessoa ganha é dado pessoal. Ficha de EPI é a
mesma classe de dado e estava recebendo o tratamento oposto.

`epi-ver` entrou na lista de exceções. **Consequência no rollout:** quem usa o
papel `leitura` não enxerga o módulo EPI; a permissão passa a ser concedida
explicitamente a quem responde pela segurança do trabalho. `EpiEndpointTest`
guarda a decisão nos dois sentidos.

### Uma asserção frágil a menos, e o alerta que ela deixa

`test_nao_existe_rota_de_exclusao_de_entrega` varria **todo** o módulo (`epis`)
para provar uma regra que é só de `epis/entregas`, e travava o resultado com
`assertSame`. É a mesma armadilha de escopo que o `7fd654c` corrigiu no catálogo
de permissões. O recorte passou a ser `epis/entregas`, com a exclusão do
cadastro conferida à parte.

Isso importa para o Plano 29: a Task 29.3 cria `ServicePpeRequirementController`
com CRUD. Se o caminho ficasse sob `/epis`, teria esbarrado nessa asserção com
uma mensagem falando de entrega.

O revisor varreu os outros 10 testes que leem `SyncPermissions::catalogo()` —
nenhum outro conta ou nega sobre o catálogo inteiro.

## Estado das validações

- Testes do plano: `PpeDeliveryServiceTest`, `AlertaDeEpiServiceTest` (27),
  `FichaDeEpiServiceTest` (39), `EpiEndpointTest` (22) — **todos passando**.
- `VazamentoEntreEmpresasTest`: 36 testes, 2735 asserções, 0 falhas.
- `RotinasAgendadasTest`: 25 testes, 178 asserções, 0 falhas.
- `npm run build`: limpo. `pint --test` nos arquivos tocados: PASS.
- Suíte completa: rodando em `testing_merge` no momento em que este handoff foi
  escrito. **Conferir antes do merge.**
- O rótulo `DEPR` em toda a saída é ruído pré-existente do PHP 8.5
  (`PDO::MYSQL_ATTR_SSL_CA`, `config/database.php:81`), não é falha.
- `RelatorioPdfServiceTest.php:277` é falha **pré-existente** vinda do Plano 21:
  grava num caminho absoluto de scratchpad de outra máquina. Falha em qualquer
  computador que não seja o do autor original.

## Decisões do Plano 28 que a próxima sessão precisa conhecer

1. **Permissões usam hífen, não ponto**: `epi-ver`, `epi-gerenciar`,
   `epi-entregar`, `epi-estornar`. As tasks pediam ponto; vale o hífen, que é a
   convenção do catálogo inteiro. A Task 29 e qualquer teste novo precisam usar
   o hífen.
2. **CA vencido é medido contra hoje, não contra `entregue_em`.** Consequência
   no rollout: lançar entrega retroativa cujo CA venceu no meio-tempo é recusado
   até o cadastro ser atualizado. Se atrapalhar a carga inicial, é uma linha em
   `PpeDeliveryService::garantirCaValido()` — mas é mudança de regra, não
   conserto.
3. **Os rótulos de tipo de EPI e de motivo agora moram nos models**
   (`PersonalProtectiveEquipment::ROTULOS_DE_TIPO`,
   `PpeDelivery::ROTULOS_DE_MOTIVO`). Antes havia três cópias, e o e-mail de
   aviso já divergia do documento. Um teste trava as chaves contra os enums.
4. **`resources/js/utils/epi.js` ainda mantém uma cópia dos rótulos em JS**, sem
   nada que a amarre às constantes PHP. A divergência que os testes agora
   impedem entre backend e documento pode reaparecer entre tela e documento.

## Pendências levantadas e não resolvidas

- **Texto legal da ficha** — leitura do responsável técnico antes do Deploy 1.
- `assinatura_path` viaja no payload da ficha e o PNG fica em disco público com
  `storage:link`. É o padrão pré-existente do projeto (mesmo de
  `WorkOrderSignatureService`), não regressão deste plano. Corrigir exigiria
  `$hidden` e rota autenticada em todo o projeto.
- `montarCsvDeEntregas` e `trocasVencidas()` carregam tudo em memória.
  Aceitáveis por período; "desde sempre" ou vinte anos de histórico pedem
  `lazy()` + streaming.
- `epi.entregas.index` e `epi.show` não são consumidos por nenhuma tela, apesar
  de os docblocks prometerem consumidor.
- `assertCount(18, RotinasAgendadas::DIARIAS)` é contagem global sem recorte por
  domínio. É tripwire deliberado, mas qualquer plano que acrescente rotina
  precisa editar o número.
- `routes/web.php` segue reprovado no `ordered_imports` do Pint por causa de
  `RefuelingController`, herdado do Plano 27. O projeto não usa Pint como gate.

## Antes de aplicar em produção

1. **Deploy 1** (28.1 a 28.5): estrutura, regras, endpoints e documentos, com o
   módulo `epi` **desligado** e a rotina `epi:verificar` parada.
2. **Deploy 2** (28.6): telas.
3. `php artisan permissions:sync` precisa rodar no deploy — só foi executado
   contra bancos de teste. O seeder de papéis também, por causa da mudança em
   `epi-ver`.
4. Ligar o módulo por tenant. Cadastrar os modelos de EPI **com CA e validade**,
   lançar as entregas em aberto e só então ligar a rotina.
5. **A primeira execução de `epi:verificar` gera uma leva grande de avisos**,
   porque entrega retroativa nasce com a troca vencida e um CA próximo dispara
   vários marcos de uma vez (as janelas de 60/30/7 dias são cumulativas, igual
   às do módulo de frota). Medir com `--dry-run` antes de ligar a rotina.
6. Migration é inteiramente aditiva: duas tabelas novas, `down()` derruba só
   elas. Nada do risco do `down()` do Plano 27.

## Limpeza pendente (herdada, não desta sessão)

- Worktrees e branches `.claude/worktrees/plano-2{4,5,6,7}`, já mergeados.
- Bancos de teste `testing_28a/b/c` e `testing_merge` com fixtures de EPI.
- `main` local segue à frente do remoto: **nada foi publicado**, e push continua
  pendente de autorização explícita.
- Defeitos pré-existentes em `main`: `RelatorioPdfServiceTest:277`, a relação
  `devices` ausente em `ServiceOrder`, o marcador de conflito no `.gitignore`.
