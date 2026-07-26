# Migração multiempresa: aplicar, conferir e voltar atrás

Registro operacional do Plano 4. Descreve o que foi construído e como cada etapa
é aplicada em produção, conferida e revertida.

Este documento é escrito para ser executado por quem não participou da
implementação: cada etapa tem o comando exato, a consulta de conferência com o
resultado esperado e o que fazer quando o resultado não bate. Leia a seção
"Ponto sem retorno" antes de aplicar o Deploy 4, e a seção "O DEFAULT 1" antes
de aplicar o Deploy 3. São as duas que estragam o dia se forem ignoradas.

## Estado em que a migração está hoje

| Onde | Situação |
|---|---|
| Código (branch `main`, ainda sem commit) | As cinco etapas implementadas: 4 migrations, 1 comando de backfill, trait de escopo, tenant explícito em fila e CLI, 85 testes verdes |
| Banco de desenvolvimento | Deploy 4 aplicado. `company_id` NOT NULL com `DEFAULT 1` nas 31 tabelas, uniques já compostas, migration do Deploy 5 pendente |
| Produção | Nada aplicado. Todas as cinco etapas ainda estão por fazer |

Conferir o estado do banco a que você está conectado, sempre, antes de rodar
qualquer coisa:

```
php artisan migrate:status | tail -5
php artisan migrate:status --path=database/migrations/deploy5
```

## Antes de começar, em qualquer etapa

1. **Backup completo, restaurado e conferido em outro banco.** Não é backup até
   ter sido restaurado uma vez. A partir do Deploy 4 com dois tenants, é a única
   saída que sobra.
2. **Uma etapa por deploy.** Nunca duas na mesma leva, com a única exceção
   declarada do Deploy 3, que tem duas migrations pelo motivo explicado lá.
3. **Um dia útil de operação normal entre uma etapa e a seguinte.** O que quebra
   na etapa anterior aparece no uso, não no deploy.
4. **Produção exige `--force`** em todo comando de migration, senão o Laravel
   pergunta e o deploy trava esperando resposta.
5. **Nunca use `-n` / `--no-interaction` no `multiempresa:backfill`.** A
   confirmação de gravação responde "não" por padrão, e o comando termina com
   sucesso sem ter gravado nada. Você acharia que fez o backfill.
6. **Confira a versão do MySQL de produção** antes do Deploy 3, porque é ela que
   decide se a tabela é reconstruída bloqueando escrita:

   ```
   php artisan tinker --execute="echo DB::select('select version() as v')[0]->v;"
   ```

### A armadilha do `php artisan migrate` puro

As quatro migrations do Plano 4 estão **no mesmo branch**. Um `php artisan
migrate --force` no Deploy 1 tentaria aplicar as quatro de uma vez, que é
exatamente o agrupamento que este plano existe para evitar. Na prática: a
`150000` entraria, a `160000` encontraria as 31 tabelas com `company_id` nulo,
porque ninguém rodou o backfill, e abortaria. O deploy terminaria com o schema
pela metade e um erro de migration no meio da madrugada.

Por isso, em toda etapa deste documento, a migration é aplicada **por caminho
explícito de arquivo**, nunca por `migrate` solto:

```
php artisan migrate --path=database/migrations/<arquivo>.php --force
```

O Laravel aceita um `--path` terminado em `.php` e roda só aquele arquivo.

A alternativa é fazer uma release por etapa, com o branch de cada deploy contendo
apenas a migration daquela etapa. Dá o mesmo resultado e exige coordenação de
release; o caminho explícito exige só copiar o comando certo. Recomendo o caminho
explícito.

Depois de todas as etapas concluídas, o `migrate` volta ao uso normal.

### Janela do dia

As rotinas agendadas ocupam 00:10, 00:20, 00:30, 00:40 e 02:00
(`App\Support\RotinasAgendadas::DIARIAS`). A janela do deploy fica fora desses
horários, senão a rotina pega a tabela no meio da reconstrução.

O horário de menor movimento da operação do cliente atual não está medido. Antes
do Deploy 3, levante o dado no próprio banco em vez de chutar:

```sql
SELECT HOUR(created_at) AS hora, COUNT(*) AS registros
FROM work_orders
WHERE created_at >= NOW() - INTERVAL 90 DAY
GROUP BY hora
ORDER BY registros ASC;
```

Repita para `service_orders`, `financial_entries` e `access_logs`. A hora com
menos registros nas quatro é a janela. Anote aqui quando medir:

- Janela escolhida: (preencher)
- Responsável pelo aviso ao cliente: (preencher)
- Telefone de quem decide abortar: (preencher)

## Mapa das cinco etapas

| Deploy | O que faz | Artefato | Reversível? |
|---|---|---|---|
| 1 | `company_id` nullable nas 31 tabelas de domínio | `2026_07_26_150000_add_company_id_to_domain_tables.php` | Sim, sem perda |
| 2 | Preenche `company_id = 1` nas linhas existentes | `php artisan multiempresa:backfill` | Sim, por UPDATE manual, só antes do Deploy 3 |
| 3 | `company_id` NOT NULL com `DEFAULT 1`, índice e FK | `2026_07_26_155000` + `2026_07_26_160000` | Sim, sem perda de dado |
| 4 | As cinco uniques globais viram compostas com `company_id` | `2026_07_26_170000_compose_uniques_with_company_id.php` | Sim, **até existir o segundo tenant** |
| 5 | Código com escopo ativo e remoção do `DEFAULT 1` | Release das Tasks 4.6 a 4.9 + `database/migrations/deploy5/2026_07_26_160001` | Sim, na ordem certa (migration antes do código) |

A lista das 31 tabelas, dos 31 models e das 5 uniques vive em um lugar só:
`app/Support/DominioMultiempresa.php`. Migrations, trait e testes leem de lá, e
é lá que se confere qualquer dúvida sobre "esta tabela entra ou não".

## O DEFAULT 1 é temporário e obrigatório

O Deploy 3 deixa a coluna `NOT NULL DEFAULT 1`. O Deploy 5 remove o `DEFAULT` e
mantém o `NOT NULL`. Os dois lados desse par são obrigatórios.

**Por que o DEFAULT precisa existir no Deploy 3.** Quem preenche `company_id` é
a trait `BelongsToCompany`, que só entra no Deploy 5. Entre o Deploy 3 e o
Deploy 5 o código que está no ar não escreve essa coluna em lugar nenhum. Com
`NOT NULL` e sem padrão, todo insert de cliente, ordem de serviço, certificado e
lançamento financeiro falha no banco, e a operação do cliente para. O padrão 1
cobre essa janela gravando o tenant certo, porque 1 é o único que existe.

**Por que o DEFAULT precisa morrer no Deploy 5.** Depois que a trait entra, o
mesmo padrão inverte de proteção para risco: um insert que esquecer o tenant
grava a empresa 1 em silêncio, sem erro, sem log, sem sinal. Com um segundo
tenant real, isso é o vazamento entre empresas que o Plano 4 inteiro existe para
evitar. Sem o padrão, o esquecimento vira erro de banco visível
(`SQLSTATE[HY000] 1364 Field 'company_id' doesn't have a default value`), que é
o comportamento desejado.

Esquecer de rodar a migration do Deploy 5 não quebra nada e não aparece em
nenhum lugar. É por isso que a conferência do Deploy 5 tem que ser feita a olho,
com a consulta que está na seção dele.

A migration que remove o padrão mora fora de `database/migrations/` de
propósito: o `migrate` só varre a raiz daquela pasta, então ela fica invisível
para o `migrate` do Deploy 3 e para a suíte de testes, e só roda com o caminho
explícito. Se ela rodasse junto com o Deploy 3, derrubaria produção.

## Ponto sem retorno

O retorno do Deploy 4 existe enquanto o banco tiver **um tenant só**. Nesse
estado, a unique composta `(company_id, coluna)` aceita exatamente o mesmo
conjunto de linhas que a unique global antiga, e o `down()` devolve o schema ao
estado exato.

O retorno deixa de existir no instante em que o **primeiro valor repetido entre
dois tenants** for gravado. Basta um destes cinco:

| Tabela | Valor que mata o retorno |
|---|---|
| `daily_cash_balances` | O mesmo `balance_date` em duas empresas. Acontece no primeiro dia útil do segundo tenant, sem ninguém fazer nada |
| `work_orders` | O mesmo `order_number` em duas empresas |
| `service_orders` | O mesmo `order_number` em duas empresas |
| `technicians` | O mesmo `email` de técnico em duas empresas |
| `service_types` | O mesmo `slug` de tipo de serviço em duas empresas. Acontece no seed inicial do segundo tenant |

Na prática: **o ponto sem retorno é o momento em que o segundo tenant começa a
operar**, e não o momento em que ele é cadastrado. Um tenant vazio ainda permite
voltar; um tenant que fechou o primeiro caixa do dia, não.

O `down()` da migration `2026_07_26_170000` sabe disso e aborta de propósito
antes de tocar em qualquer tabela, listando os valores em conflito. Ele não
tenta apagar dado de ninguém para caber na unique global. Quando ele abortar, a
única saída é restaurar backup, e não existe segunda opção.

Consulta para saber, em qualquer momento, se o retorno ainda existe:

```sql
SELECT 'daily_cash_balances' AS tabela, COUNT(*) AS colisoes FROM (
    SELECT balance_date FROM daily_cash_balances GROUP BY balance_date HAVING COUNT(*) > 1
) x
UNION ALL SELECT 'work_orders', COUNT(*) FROM (
    SELECT order_number FROM work_orders GROUP BY order_number HAVING COUNT(*) > 1
) x
UNION ALL SELECT 'service_orders', COUNT(*) FROM (
    SELECT order_number FROM service_orders GROUP BY order_number HAVING COUNT(*) > 1
) x
UNION ALL SELECT 'technicians', COUNT(*) FROM (
    SELECT email FROM technicians GROUP BY email HAVING COUNT(*) > 1
) x
UNION ALL SELECT 'service_types', COUNT(*) FROM (
    SELECT slug FROM service_types GROUP BY slug HAVING COUNT(*) > 1
) x;
```

Zero em todas as linhas: o retorno do Deploy 4 ainda funciona. Qualquer número
maior que zero: acabou, e a partir daí só se avança.

---

## Deploy 1: `company_id` nullable

**O que faz.** Adiciona a coluna `company_id`, nullable, sem índice e sem chave
estrangeira, nas 31 tabelas de domínio. Nenhum código lê ou escreve essa coluna
ainda.

**Comando.**

```
php artisan migrate --path=database/migrations/2026_07_26_150000_add_company_id_to_domain_tables.php --force
```

Sem o `--path`, as quatro etapas rodariam juntas. Ver "A armadilha do
`php artisan migrate` puro".

**Como conferir.**

```sql
SELECT COUNT(*) AS tabelas_com_coluna
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'company_id';
```

Esperado: `31`.

```sql
SELECT TABLE_NAME, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'company_id'
  AND (IS_NULLABLE = 'NO' OR COLUMN_DEFAULT IS NOT NULL);
```

Esperado: nenhuma linha. Se vier alguma, o banco não está no estado do Deploy 1,
e sim mais adiante. Confira `migrate:status` antes de qualquer outra coisa.

A migration também imprime, na saída do deploy:
`company_id nullable: 31 tabela(s) alterada(s), 0 pulada(s)`.

**Se não bater.** Contagem menor que 31 significa tabela que não existe no banco
conectado. A saída da migration lista as puladas. Compare com
`DominioMultiempresa::TABELAS_DE_DOMINIO` e descubra qual falta antes de seguir.

**Sinais nas primeiras horas.** Nenhum efeito observável é o resultado esperado.
Vigie `storage/logs/laravel.log` por erro de coluna desconhecida em consultas com
`SELECT *` e `INSERT` posicional, que é o único jeito de uma coluna nova
atrapalhar.

**Como voltar atrás.**

```
php artisan migrate:rollback --step=1 --force
```

Remove a coluna das 31 tabelas. **O que se perde:** nada, se o Deploy 2 ainda não
rodou. Se o backfill já rodou, perde-se o preenchimento, e o Deploy 2 precisa ser
refeito depois.

**Tempo medido.** Em desenvolvimento, com o banco inteiro em 78 linhas, a etapa
termina em poucos segundos. **Não conclua daí que esta etapa é barata em
produção.** A migration cria a coluna com `->after('id')`, e a posição importa:

- MySQL 8.0.29 ou superior faz `ADD COLUMN` em qualquer posição com
  `ALGORITHM=INSTANT`, alteração só de metadado, praticamente sem custo.
- MySQL 8.0 anterior a 8.0.29, ou 5.7, só faz `INSTANT` quando a coluna vai para
  o fim da tabela. Com `AFTER id`, a tabela é **reconstruída**, nas 31, inclusive
  em `audit_logs`, `access_logs`, `device_events` e `work_order_photos`.

Confira a versão antes de dimensionar a janela do Deploy 1. Se for anterior a
8.0.29, trate o Deploy 1 com o mesmo cuidado do Deploy 3 e meça na cópia.

---

## Deploy 2: backfill de `company_id = 1`

**O que faz.** Grava `company_id = 1` em toda linha que está com a coluna nula,
tabela por tabela, em lotes de 1.000, conferindo a contagem antes e depois de
cada uma.

**Comandos, nesta ordem.**

```
php artisan multiempresa:backfill --dry-run
```

Leia o relatório linha a linha. Ele traz uma linha por tabela, inclusive as
vazias, com total, quantas já têm `company_id`, quantas estão nulas e a situação.
O arquivo fica em `storage/logs/backfill-company-id-<data>_<hora>.log`. Guarde
esse arquivo: ele é a prova do estado anterior.

```
php artisan multiempresa:backfill
```

O comando pergunta antes de gravar: `Gravar company_id = 1 em N linha(s)
pendente(s) das tabelas de domínio?`. Responda `yes`. Sem confirmação, nada é
gravado.

Para outra empresa que não a 1, existe `--company=N`. Em produção hoje isso não
tem uso: o tenant é o 1.

**Como conferir.**

```
php artisan multiempresa:backfill --dry-run
```

Rodar de novo em simulação é a conferência mais direta. Esperado: coluna "Nulos"
zerada em todas as 31 linhas, e a situação de cada tabela como `vazia` ou
`já preenchida`. Nenhuma linha pode dizer `N linha(s) pendente(s)`.

Compare o total de cada tabela com o do log do `--dry-run` anterior: o comando
nunca cria nem apaga linha, então os totais têm que ser idênticos.

**Se não bater.** O comando para na primeira divergência de contagem, sem tocar
nas tabelas seguintes, e imprime qual tabela divergiu com os números. A causa
quase sempre é escrita concorrente durante o backfill. Rode de novo: só linhas
nulas são tocadas, então repetir é seguro e completa o que faltou.

**Sinais nas primeiras horas.** Nenhuma mudança de comportamento é o esperado.
Linha nova criada depois do backfill nasce com `company_id` nulo, porque o código
ainda não preenche a coluna. Isso é normal nesta etapa e é exatamente o que o
Deploy 3 conserta, com a guarda dele acusando o que sobrou.

**Como voltar atrás.** Não existe migration para reverter, porque isto é um
comando. O retorno é SQL manual, e só faz sentido enquanto o Deploy 3 não rodou:

```sql
UPDATE clients SET company_id = NULL WHERE company_id = 1;
```

Uma linha por tabela, para as 31. **O que se perde:** nada, mas não faça isso sem
motivo forte. O estado "coluna nula" é pior que o estado "coluna preenchida" em
todos os aspectos, e o Deploy 3 exige o preenchimento.

**Tempo medido.** Em desenvolvimento, 78 linhas em 31 tabelas, execução
instantânea. Em produção, o tempo acompanha o número de linhas, principalmente em
`audit_logs`, `access_logs`, `device_events` e `work_order_photos`. Meça na cópia
do banco antes, como descrito na seção "Como medir o tempo antes da janela".

---

## Deploy 3: NOT NULL com DEFAULT 1, índice e chave estrangeira

**O que faz.** Duas migrations na mesma leva, e é a única etapa com duas:

- `2026_07_26_155000_seed_founding_company_for_fresh_installs`: cria a empresa
  fundadora **apenas quando `companies` está vazia**. Em produção não faz nada,
  porque a empresa 1 já existe, e imprime
  `Fundação do tenant: nada a fazer, já existe empresa cadastrada.`. Ela existe
  pelo caminho do banco novo (ambiente novo, CI, suíte de testes), onde
  migrations antigas semeiam linhas antes de a coluna existir e não há operador
  para rodar o backfill no meio de um `migrate`.
- `2026_07_26_160000_make_company_id_required_on_domain_tables`: aplica
  `NOT NULL DEFAULT 1`, índice simples e chave estrangeira para `companies` com
  `ON DELETE RESTRICT` e `ON UPDATE RESTRICT`, nas 31 tabelas.

Antes de qualquer DDL, a migration percorre as 31 tabelas e aborta se encontrar
`company_id` nulo ou apontando para empresa inexistente, listando tabela,
contagem e ids órfãos. Se ela abortar, **nenhuma tabela foi alterada**.

`RESTRICT` e não `CASCADE` por decisão: apagar um tenant por engano não pode
levar junto a operação inteira de um cliente.

**Comandos, nesta ordem, na mesma janela.**

```
php artisan migrate --path=database/migrations/2026_07_26_155000_seed_founding_company_for_fresh_installs.php --force
php artisan migrate --path=database/migrations/2026_07_26_160000_make_company_id_required_on_domain_tables.php --force
```

A primeira imprime `nada a fazer, já existe empresa cadastrada` em produção. Se
ela imprimir que criou a empresa 1, você não está conectado ao banco de
produção: pare tudo e confira antes de rodar a segunda.

**Como conferir.**

```sql
SELECT COUNT(*) AS colunas_obrigatorias_com_padrao
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'company_id'
  AND IS_NULLABLE = 'NO' AND COLUMN_DEFAULT = '1';
```

Esperado: `31`.

```sql
SELECT COUNT(*) AS chaves_estrangeiras_restrict
FROM information_schema.REFERENTIAL_CONSTRAINTS rc
JOIN information_schema.KEY_COLUMN_USAGE k
  ON k.CONSTRAINT_NAME = rc.CONSTRAINT_NAME
 AND k.CONSTRAINT_SCHEMA = rc.CONSTRAINT_SCHEMA
WHERE rc.CONSTRAINT_SCHEMA = DATABASE() AND k.COLUMN_NAME = 'company_id'
  AND rc.DELETE_RULE = 'RESTRICT' AND rc.UPDATE_RULE = 'RESTRICT';
```

Esperado: `31`.

```sql
SELECT COUNT(DISTINCT TABLE_NAME) AS tabelas_indexadas
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'company_id'
  AND SEQ_IN_INDEX = 1 AND NON_UNIQUE = 1;
```

Esperado: `31`.

A migration também imprime o tempo por tabela das cinco mais demoradas. Guarde
essa saída: é a medição real que dimensiona qualquer repetição futura.

**Se não bater.** A migration abortou e disse o motivo:

- Linha com `company_id` nulo: o Deploy 2 não cobriu tudo, ou linha nova nasceu
  nula depois dele. Rode `php artisan multiempresa:backfill`, confira e repita o
  deploy.
- Id órfão: `company_id` aponta para empresa que não existe em `companies`.
  Alguém apagou uma empresa. Restaure a empresa ou corrija as linhas citadas.
  **Não crie empresa às cegas só para a chave estrangeira passar**: isso amarra
  dado de um cliente a um tenant errado, e o erro fica invisível para sempre.

**Sinais nas primeiras horas.** Esta é a etapa que quebra escrita se algo der
errado. Vigie:

- `storage/logs/laravel.log` por `SQLSTATE[HY000]: General error: 1364` ou
  `Field 'company_id' doesn't have a default value`. Isso significa que o
  `DEFAULT 1` não ficou aplicado em alguma tabela. Confira a consulta B acima:
  se o resultado for menor que 31, descubra qual tabela ficou de fora e aplique o
  padrão nela à mão.
- Criação de cliente, de ordem de serviço, de certificado e de lançamento
  financeiro pela interface, uma de cada, logo depois do deploy. As quatro têm
  que funcionar e gravar `company_id = 1`.
- Listagens principais continuam mostrando a mesma quantidade de registros de
  antes.

**Como voltar atrás.**

```
php artisan migrate:rollback --step=1 --force
```

`--step=1` reverte **uma migration**, não um lote inteiro, e a que volta é a
`160000`, que é a última pelo nome. A `155000` não precisa voltar: o `down()`
dela é vazio de propósito, porque apagar a empresa fundadora deixaria órfão todo
o dado que aponta para ela.

O `down()` remove a chave estrangeira, remove o índice e devolve a coluna para
nullable sem padrão. **O que se perde:** nenhum dado. O `company_id` preenchido
continua lá.

Uma consequência que morde: depois do rollback, a coluna volta a ser nullable
**sem `DEFAULT`**, e o código que está no ar antes do Deploy 5 não preenche a
coluna. Toda linha criada a partir daí nasce com `company_id` nulo. Antes de
reaplicar o Deploy 3, rode o backfill de novo, senão a guarda da migration aborta
apontando exatamente essas linhas.

**Tempo medido.** Em desenvolvimento, com 31 linhas na maior tabela, a etapa
inteira termina em poucos segundos, e essa medição não diz nada sobre produção.
Esta é a etapa cara, e a razão está no MySQL:

- `MODIFY COLUMN` de NULL para NOT NULL reconstrói a tabela. No MySQL 8 e
  superior costuma rodar `INPLACE`, permitindo escrita concorrente, com trava de
  metadado curta no começo e no fim.
- **Adicionar chave estrangeira com `foreign_key_checks` ligado, que é o padrão,
  só existe em `ALGORITHM=COPY`.** Cópia da tabela bloqueia escrita durante toda
  a operação. É este passo, e não o `NOT NULL`, que dita o tamanho da janela.

Então trate a etapa como bloqueante para escrita nas quatro maiores tabelas:
`audit_logs`, `access_logs`, `device_events` e `work_order_photos`. Meça antes.

---

## Deploy 4: uniques globais viram compostas

**O que faz.** Converte cinco uniques globais em compostas com `company_id`:

| Tabela | Antes | Depois |
|---|---|---|
| `daily_cash_balances` | `balance_date` | `company_id, balance_date` |
| `service_orders` | `order_number` | `company_id, order_number` |
| `service_types` | `slug` | `company_id, slug` |
| `technicians` | `email` | `company_id, email` |
| `work_orders` | `order_number` | `company_id, order_number` |

`users.email` **não** é tocada: continua unique global por decisão registrada no
PRD, porque o login é por e-mail. Um e-mail pertence a uma empresa só.
`technicians.user_id` também continua global, porque o vínculo é 1:1 com um
usuário que já pertence a uma empresa só.

A migration cria a composta antes de dropar a global, para não existir instante
sem restrição nenhuma, e usa um `ALTER TABLE` por operação, porque criar e dropar
índice na mesma instrução em tabela com chave estrangeira em `company_id` esbarra
no erro 1553 do MySQL.

Assim como no Deploy 3, a guarda confere as cinco tabelas inteiras antes de
alterar qualquer uma e aborta com a lista dos valores duplicados.

**Comando.**

```
php artisan migrate --path=database/migrations/2026_07_26_170000_compose_uniques_with_company_id.php --force
```

**Como conferir.**

```sql
SELECT TABLE_NAME, INDEX_NAME,
       GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS colunas
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0 AND INDEX_NAME <> 'PRIMARY'
  AND TABLE_NAME IN ('daily_cash_balances','service_orders','service_types','technicians','work_orders','users')
GROUP BY TABLE_NAME, INDEX_NAME
ORDER BY TABLE_NAME, INDEX_NAME;
```

Esperado, exatamente estas sete linhas:

```
daily_cash_balances  daily_cash_balances_company_id_balance_date_unique  company_id,balance_date
service_orders       service_orders_company_id_order_number_unique       company_id,order_number
service_types        service_types_company_id_slug_unique                company_id,slug
technicians          technicians_company_id_email_unique                 company_id,email
technicians          technicians_user_id_unique                          user_id
users                users_email_unique                                  email
work_orders          work_orders_company_id_order_number_unique          company_id,order_number
```

Nenhuma unique global sobre `balance_date`, `order_number`, `slug` ou `email` de
técnico pode aparecer. `users_email_unique` sobre `email` **tem** que aparecer.

**Se não bater.** A migration abortou porque encontrou duplicata em
`(company_id, coluna)`. Com um tenant só isso significa que a unique global já
estava sendo burlada, o que não deveria ser possível: investigue as linhas
citadas antes de repetir. A causa provável é backfill que atribuiu empresas
diferentes a linhas que colidiam.

**Sinais nas primeiras horas.** Criação de OS, de ordem de serviço agendada e de
saldo diário de caixa continua recusando número repetido dentro da mesma empresa.
Se passar a aceitar duplicata, a composta não ficou aplicada. Confira a consulta
acima.

**Como voltar atrás.**

```
php artisan migrate:rollback --step=1 --force
```

Recria as uniques globais e remove as compostas. **O que se perde:** nada,
enquanto existir um tenant só.

Com dois tenants e qualquer valor repetido entre eles, o `down()` aborta antes de
tocar em qualquer tabela, com a lista dos conflitos. Ver a seção "Ponto sem
retorno". A partir daí a única saída é restaurar backup.

**Tempo medido.** Em desenvolvimento, instantâneo. Em produção, criar e dropar
índice unique reconstrói o índice das cinco tabelas. Elas são pequenas comparadas
às de auditoria, então esta etapa é bem mais barata que o Deploy 3, mas ainda
assim entra na mesma janela de baixo movimento.

---

## Deploy 5: código com escopo ativo e remoção do DEFAULT

**O que faz.** Duas coisas, nesta ordem, no mesmo deploy:

1. Sobe o release com o escopo ativo: trait `BelongsToCompany` nos 31 models,
   `Company::current()` resolvendo pelo usuário autenticado, tenant explícito em
   fila (`CarregaTenant` + `AplicaTenantDoJob`) e em CLI (`OperaPorTenant`).
2. **Depois de o código estar no ar**, remove o `DEFAULT 1` das 31 colunas,
   mantendo `NOT NULL`, índice e chave estrangeira.

A ordem inversa quebra produção: sem a trait e sem o padrão, nada preenche a
coluna e todo insert falha.

**Antes de liberar este deploy, os testes precisam estar verdes:**

```
php artisan test tests/Feature/EscopoDeEmpresaTest.php tests/Feature/VazamentoEntreEmpresasTest.php tests/Feature/BelongsToCompanyTest.php tests/Feature/TenantForaDaRequisicaoTest.php
```

Esperado: `Tests: 85 passed`. Qualquer falha aqui bloqueia o deploy, sem exceção.
`VazamentoEntreEmpresasTest` é o que cria um segundo tenant com dados e confirma
que o usuário do tenant 1 não o enxerga em tela, endpoint, busca, contador de
dashboard nem histórico de auditoria.

**Comandos, nesta ordem.**

```
# 1. release do código (deploy normal da aplicação)
php artisan migrate --force
php artisan config:cache
php artisan route:cache

# 2. só depois de o código estar servindo requisição:
php artisan migrate --path=database/migrations/deploy5 --force
```

Aqui o `migrate` volta a ser usado solto, e sem risco: as quatro migrations das
etapas anteriores já constam como aplicadas, e a do Deploy 5 mora em subpasta,
onde o `migrate` não varre. É esperado que o passo 1 diga "Nothing to migrate".

A migration do passo 2 confere sozinha se a trait está aplicada aos 31 models e
aborta, sem alterar nada, quando não está. É a rede contra inverter a ordem.

**Como conferir.**

```sql
SELECT COUNT(*) AS colunas_com_padrao
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'company_id'
  AND COLUMN_DEFAULT IS NOT NULL;
```

Esperado: `0`. Qualquer outro número significa que o passo 2 não rodou ou rodou
pela metade, e o vazamento silencioso continua possível.

```sql
SELECT COUNT(*) AS colunas_obrigatorias
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME = 'company_id'
  AND IS_NULLABLE = 'NO';
```

Esperado: `31`. O `NOT NULL` continua de pé.

```
php artisan migrate:status --path=database/migrations/deploy5
```

Esperado: `Ran`.

**Se não bater.** `Pending` no `migrate:status` com o código já no ar é o cenário
mais provável e o mais perigoso, porque nada quebra: rode o comando do passo 2.
Se ele abortar dizendo que faltam models sem a trait, o release do passo 1 não
subiu inteiro. Confira qual versão está servindo antes de insistir.

**Sinais nas primeiras horas.** Esta etapa muda comportamento de leitura em todo
o sistema, então a vigilância é maior:

- **Listagem vazia** em qualquer tela é o sintoma clássico de tenant não
  resolvido. Confira `SELECT id, email, company_id FROM users;`: usuário com
  `company_id` diferente de 1 ou nulo não enxerga nada.
- `storage/logs/laravel.log` por `Nenhuma empresa resolvida para esta operação`,
  que é a mensagem de `TenantAtual::exigirId()`. Ela aparece quando algo tenta
  emitir documento ou gerar numeração fora de um tenant.
- `storage/logs/laravel.log` por `Falha ao registrar auditoria.` e
  `Falha ao registrar o evento de acesso`. As duas indicam gravação de auditoria
  perdida por falta de tenant. Ver a seção de riscos abertos.
- Erro `1364 Field 'company_id' doesn't have a default value` agora é sinal
  legítimo e útil: apontou um caminho de código que não resolve o tenant.
  Registre onde aconteceu e corrija o caminho, não o schema.
- `php artisan routines:status` no dia seguinte, para confirmar que as rotinas
  agendadas rodaram dentro dos tenants.

**Como voltar atrás.** Na ordem inversa da aplicação, e a ordem importa:

```
# 1. devolver o DEFAULT 1 primeiro
php artisan migrate:rollback --path=database/migrations/deploy5 --step=1 --force

# 2. só depois, voltar o release do código
```

Se o código voltar primeiro, o sistema fica sem trait e sem padrão, e toda
criação de registro falha até o passo 1 rodar.

`--step=1` reverte a última migration registrada. Confirme com
`php artisan migrate:status --path=database/migrations/deploy5` que é ela mesma
antes de rodar; se o comando responder "Migration not found", outra migration foi
aplicada depois e você precisa usar `--batch=` com o lote correto.

**O que se perde:** nada de dado. O que volta é o risco: com o `DEFAULT 1`
restaurado e o código antigo no ar, insert sem tenant volta a gravar na empresa 1
em silêncio. Aceitável enquanto existir um tenant só, inaceitável depois disso.

**Tempo medido.** A remoção do padrão é `MODIFY COLUMN` nas 31 tabelas, mesmo
custo do Deploy 3 sem a parte da chave estrangeira. Em desenvolvimento, a
migration continua pendente e nunca foi cronometrada em volume real.

---

## Como medir o tempo antes da janela

A medição em desenvolvimento não vale: o banco inteiro tem 78 linhas, e a maior
tabela tem 31. Antes do Deploy 3 em produção, meça em uma cópia restaurada do
dump de produção.

1. Levante o tamanho real das tabelas em produção:

```sql
SELECT TABLE_NAME, TABLE_ROWS,
       ROUND((DATA_LENGTH + INDEX_LENGTH) / 1024 / 1024) AS mb
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
ORDER BY (DATA_LENGTH + INDEX_LENGTH) DESC
LIMIT 10;
```

2. Restaure o dump em outro banco, aponte o `.env` de um ambiente separado para
   ele e rode as etapas 1 a 4 em sequência, cronometrando. A migration do Deploy
   3 imprime o tempo por tabela sozinha.

3. Antes da janela real, descubra se o MySQL de produção consegue fazer a etapa
   sem bloquear escrita, testando na cópia:

```sql
ALTER TABLE audit_logs
  MODIFY company_id BIGINT UNSIGNED NOT NULL DEFAULT 1,
  ALGORITHM=INPLACE, LOCK=NONE;
```

Se o servidor aceitar, a mudança de coluna roda sem bloquear escrita. Se recusar,
ela vai reconstruir a tabela com trava, e a janela precisa comportar o tempo
inteiro medido no passo 2. A chave estrangeira, essa, bloqueia de qualquer jeito
com `foreign_key_checks` ligado.

4. Anote os tempos medidos aqui, por tabela, quando medir. Documento com tempo
   estimado e nunca corrigido depois vale menos que documento nenhum.

## Por que não agrupar etapas

Agrupar Deploy 2 e Deploy 3 no mesmo release é o erro mais tentador, e ele
quebra a operação do cliente por um motivo simples: **o backfill precisa ser
conferido por uma pessoa antes de a restrição existir.** No mesmo deploy, o
`migrate` roda a migration e o backfill sem ninguém no meio, e a primeira coisa
que aparece é a guarda da `160000` abortando, ou pior, uma tabela restrita antes
de o dado estar completo.

Além disso, cada etapa tem uma pergunta própria que só o uso responde:

- Deploy 1 pergunta: alguma consulta com `SELECT *` ou insert posicional quebrou
  com a coluna nova?
- Deploy 2 pergunta: as contagens por tabela batem com o esperado?
- Deploy 3 pergunta: a escrita continua funcionando com a coluna obrigatória?
- Deploy 4 pergunta: a numeração e o saldo diário continuam recusando duplicata
  dentro da empresa?
- Deploy 5 pergunta: alguma tela ficou vazia?

Juntar duas etapas junta as perguntas, e quando algo quebra você não sabe qual
das duas causou. Com o sistema no ar para um cliente real, isso significa
descobrir a causa com o cliente parado.

## Riscos abertos e pendências

Registrados aqui de propósito, em vez de escondidos. Nenhum deles bloqueia a
migração, e todos precisam de decisão antes do segundo tenant real.

### 1. Tentativa de login recusada deixa de ser registrada

**O que acontece.** Depois do Deploy 5, com o `DEFAULT` removido,
`App\Listeners\RegistraAcesso::aoFalhar()` tenta gravar em `access_logs` sem
usuário autenticado, logo sem tenant. A trait não tem de onde tirar
`company_id`, o insert falha no `NOT NULL` e o `try/catch` que existe para a
auditoria nunca derrubar o login engole o erro. Sobra uma linha de
`Log::error('Falha ao registrar o evento de acesso...')` e some o registro.

**Por que importa.** É perda silenciosa de rastro de segurança: exatamente o
padrão de tentativas repetidas que revela invasão para de ser gravado. Login
bem-sucedido e logout continuam funcionando, porque neles o usuário já está
resolvido.

**Pendência.** Decisão de produto sobre onde arquivar tentativa recusada, com
três caminhos possíveis: resolver a empresa pelo e-mail digitado antes de
gravar, tirar `access_logs` da lista de tabelas de domínio e tratá-la como
tabela de plataforma, ou criar uma tabela separada de eventos sem tenant. A
opção pelo e-mail é a mais simples e cobre o caso comum, mas continua perdendo
tentativa com e-mail inexistente, que é justamente a mais suspeita.

### 2. `User` vive sem escopo global de leitura

**O que acontece.** `User` usa a trait com `aplicaEscopoDeEmpresaNaLeitura()`
devolvendo `false`, por reentrância: quem resolve o tenant lê
`auth()->user()`, que é resolvido por uma consulta a `users`. Ligar o escopo ali
faria a consulta perguntar o tenant antes de existir tenant.

**O que protege no lugar.** O scope `daEmpresaAtual()`, que precisa ser chamado
à mão em toda consulta que lista ou conta usuários, e o override de
`resolveRouteBindingQuery()`, que escopa todo route-model binding de `{user}` e
devolve 404 para usuário de outra empresa.

**Por que importa.** É mais frágil que o escopo global, porque depende de alguém
lembrar. Rota nova que receba `{user}` nasce protegida pelo binding; consulta
nova que liste usuários, não. Toda revisão de código que toque usuário precisa
conferir isso.

### 3. Comandos que operam sobre todas as empresas de propósito

`PurgeAuditLogs` e `BackfillCompanyId` usam `DB::table` cru e percorrem as
tabelas inteiras, sem filtro de empresa. É intencional: retenção de auditoria e
backfill são políticas de plataforma, não de tenant. Fica registrado para que
ninguém "conserte" isso depois achando que é bug, e para que qualquer comando
novo de plataforma siga o mesmo padrão explícito.

### 4. `BackfillAcesso` casa técnico com usuário por e-mail, sem tenant

`php artisan acesso:backfill` compara `technicians.email` com `users.email` sem
nenhum filtro de empresa, e a checagem final de "existe administrador ativo"
também roda global. Com um tenant só, o resultado é correto. Rodado depois do
segundo tenant, ele pode vincular técnico de uma empresa ao usuário de outra.

**Pendência.** Aplicar `OperaPorTenant` nele antes do segundo tenant entrar, do
mesmo jeito que já foi feito nas cinco rotinas agendadas. O mesmo vale para
`BackfillPeriodicidade`, `CorrectPendingInstallments` e `SyncPermissions`, que
também rodam sem tenant.

### 5. A suíte de testes roda em um schema diferente do de produção

**O que acontece.** A migration que remove o `DEFAULT 1` mora fora de
`database/migrations/`, então o `RefreshDatabase` da suíte nunca a executa. O
banco de teste fica permanentemente no estado do Deploy 4: `company_id NOT NULL
DEFAULT 1`. Nenhuma factory preenche `company_id`, e `Tests\TestCase` não fixa
tenant nenhum.

**Por que importa.** Um caminho de código que esquece o tenant passa no CI,
porque o padrão do banco de teste resolve, e falha em produção depois do Deploy
5. O teste de vazamento cobre o caso em que o tenant está resolvido e errado; ele
não cobre o caso em que não há tenant nenhum.

**Pendência.** Depois do Deploy 5 em produção, mover
`database/migrations/deploy5/2026_07_26_160001_drop_company_id_default_on_domain_tables.php`
para `database/migrations/`, como o próprio arquivo prevê. Isso alinha teste e
produção, e quebra de imediato todo teste que cria registro de domínio sem
tenant. Consertar esses testes é o objetivo, não o efeito colateral: cada um
deles é um caminho que também está sem tenant em produção. Trate como task
própria, porque o volume não é pequeno.

### 6. Auditoria perdida em silêncio fora de requisição HTTP

`App\Models\Concerns\Auditavel` grava em `audit_logs`, que é tabela de domínio.
Todo o caminho da auditoria roda dentro de `try/catch` para nunca derrubar a
operação de negócio. Depois do Deploy 5, um comando artisan que altere model de
domínio sem tenant resolvido (`BackfillPeriodicidade`, por exemplo, que faz
`$contrato->update([...])`) grava a alteração no contrato e **perde o registro de
auditoria dela**, deixando só um `Log::error('Falha ao registrar auditoria.')`.

É o mesmo problema do item 1, com superfície maior. A correção é a mesma do item
4: todo comando que toca dado de domínio roda dentro de um tenant.

## Referência rápida

| Arquivo | Papel |
|---|---|
| `app/Support/DominioMultiempresa.php` | Fonte única: 31 tabelas, 31 models, 5 uniques a compor, uniques que continuam globais, com o motivo de cada exclusão |
| `app/Support/TenantAtual.php` | Resolve o tenant corrente. `definir()`, `comTenant()`, `semEscopo()`, `exigirId()` |
| `app/Models/Concerns/BelongsToCompany.php` | Escopo global de leitura e preenchimento na criação |
| `app/Console/Commands/BackfillCompanyId.php` | Comando do Deploy 2 |
| `app/Console/Commands/Concerns/OperaPorTenant.php` | Tenant explícito em comando artisan |
| `app/Jobs/Concerns/CarregaTenant.php` | Tenant no payload do job |
| `database/migrations/2026_07_26_150000_*` | Deploy 1 |
| `database/migrations/2026_07_26_155000_*` e `160000_*` | Deploy 3 |
| `database/migrations/2026_07_26_170000_*` | Deploy 4 |
| `database/migrations/deploy5/2026_07_26_160001_*` | Deploy 5, remoção do padrão |
| `.claude/tasks/4/INDEX.md` | Ordem de execução das tasks e dependências |
| `.claude/prd/saas-multitenant.md` | Requisito de origem |
