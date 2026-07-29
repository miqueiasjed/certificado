# Migração do financeiro para títulos: aplicar, conferir e desfazer

Registro operacional da Task 18.2 do Plano 18. Descreve o que a migração faz,
o que ela deliberadamente não faz, como conferir o resultado e como voltar
atrás.

Escrito para ser executado por quem não participou da implementação. A regra
que manda em tudo aqui é uma só: **nenhum número que o cliente vê hoje pode
mudar**. Quem prova isso é a conferência automática de totais, não a impressão
de quem rodou.

## O que a migração faz

Transforma cada linha de `payment_details` em parcela de um título a receber:

| De (modelo atual) | Para (modelo novo) |
|---|---|
| `payment_details` da mesma OS | um `receivables` por ordem de serviço |
| cada `payment_detail` | um `receivable_installments` |
| `payment_details.final_amount` | `receivable_installments.valor` |
| `payment_details.amount_paid` (com `payment_date`) | `receivable_installments.valor_pago` |
| `payment_details.payment_date` | `receivable_installments.pago_em` |
| `payment_details.payment_due_date` | `receivable_installments.vencimento` |
| `payment_details.id` | `receivable_installments.payment_detail_id` |
| `financial_entries.id` do recebimento | `receivable_installments.financial_entry_id` |

Comando:

```
php artisan financeiro:migrar-titulos --dry-run
php artisan financeiro:migrar-titulos
```

## O que a migração não faz, e por quê

- **Não apaga nada.** `payment_details` e `financial_entries` continuam
  existindo, com a mesma contagem e os mesmos valores, e continuam sendo a
  fonte dos painéis até a Task 18.7 trocar a leitura. A limpeza é assunto de
  outro plano.
- **Não cria lançamento de caixa.** O recebimento antigo já tem o lançamento
  dele em `financial_entries`; a migração **vincula** esse lançamento. Criar um
  segundo é como o mesmo dinheiro entra duas vezes no fluxo de caixa. Por isso
  ela não chama `SettlementService` nem `IntegracaoComCaixa`.
- **Não recalcula valor nem vencimento.** Não chama
  `ReceivableService::gerarDaOs()`: a geração nova divide o valor do título em
  parcelas pela regra dela, e o dado antigo já tem parcela com valor, data e
  recebimento próprios. Migrar é copiar, não recalcular.
- **Não adivinha.** Caso ambíguo vira exceção, não é migrado e vai para o
  relatório, para uma pessoa decidir.

## Regras de tradução, uma a uma

1. **Recebimento só existe com `payment_date` preenchida e `amount_paid` maior
   que zero.** É o mesmo critério de `PaymentDetail::scopePaid()`, do accessor
   de status e de `PaymentDetailService::totalRecebidoDaOrdem()`. Valor sem
   data de pagamento é dinheiro a receber, nunca dinheiro que entrou.
2. **O valor da parcela vive em `final_amount`.** Registro antigo, anterior à
   correção de `PaymentDetailService`, tem `final_amount` nulo e o valor em
   `amount_paid`; nesse caso o valor da parcela é `amount_paid`.
3. **Situação da parcela nova**: `paga` quando o recebido cobre o valor,
   `parcial` quando não cobre, `vencida` quando não há recebimento e o
   vencimento já passou (comparação por dia no fuso `America/Sao_Paulo`),
   `aberta` no resto.
4. **`pago_em` em parcela parcial** recebe a data do recebimento registrado no
   modelo antigo. É a única data que existe no dado antigo, e descartá-la
   mudaria o total recebido do mês. No fluxo novo `pago_em` só é preenchido na
   quitação; parcela migrada é a exceção, e ela está documentada aqui.
5. **Parcela paga sem vencimento** usa a data de pagamento como vencimento.
   Parcela **em aberto** sem vencimento vira exceção: sem vencimento ela não
   entra em aging nem em previsão de caixa, e inventar a data mudaria a régua
   de inadimplência.
6. **`valor_total` do título é a soma das parcelas dele**, não o valor final da
   OS. As duas coisas divergem no dado antigo (OS de 1.000 com uma única
   parcela de 500 lançada), e título cujo total não é a soma das parcelas fica
   inconsistente com a baixa e com o aging. Toda diferença entre a soma e o
   valor da OS aparece marcada no relatório, para conferência.
7. **`emitido_em` é o dia da cobrança mais antiga do grupo**
   (`payment_details.created_at`, convertido de UTC para o dia no fuso do
   negócio). Usar "hoje" faria todo título migrado nascer com a data da
   migração.
8. **Parcela inativa (`active = false`) também é migrada.** Os painéis atuais
   não filtram por `active`, então deixá-la de fora mudaria os totais.

### Critério de vínculo entre parcela e lançamento de caixa

O vínculo usado é **`financial_entries.payment_detail_id`**, a coluna que o
próprio fluxo atual preenche (`PaymentDetailController::createFinancialEntryFromPayment()`),
filtrando `source = work_order` e `status = confirmed`, e ficando com o mais
recente quando há mais de um (parcela reaberta e paga de novo tem dois
lançamentos de entrada; o primeiro já foi compensado por uma saída
`payment_reopen`).

Casar por OS + valor + data foi **descartado**: duas parcelas do mesmo valor,
na mesma OS e no mesmo dia existem de verdade em parcelamento, e nesse caso o
casamento por semelhança escolhe no escuro. Onde não existe vínculo direto, a
parcela é migrada com `financial_entry_id` nulo e entra na seção
"Recebimentos migrados sem lançamento de caixa vinculado" do relatório, com os
lançamentos parecidos apenas **sugeridos**, nunca aplicados.

> **Ponto que precisa de decisão humana antes do deploy em produção:** se o
> relatório de ensaio mostrar muitos recebimentos sem lançamento vinculado, é
> sinal de que boa parte do histórico é anterior à coluna `payment_detail_id`.
> Nesse caso, decida com o cliente se esses vínculos serão preenchidos a mão
> antes da migração. Sem vínculo, a parcela migra do mesmo jeito e nenhum total
> muda; o que se perde é conseguir ir da parcela ao lançamento de caixa dela.

## Casos que ficam de fora (relatório de exceções)

Nenhum deles é migrado. Todos aparecem no relatório com o motivo escrito.

| Motivo | Por que é ambíguo |
|---|---|
| `parcela_sem_os` | Parcela sem ordem de serviço válida (OS ausente ou sem cliente). Título existe para cobrar de alguém; escolher o cliente no lugar do sistema é decisão de pessoa. |
| `parcela_sem_empresa` | Parcela sem `company_id`, e a OS também sem. Gravar título sem empresa é vazamento entre empresas esperando acontecer. |
| `parcela_sem_valor` | `final_amount` e `amount_paid` vazios ou zerados. Não dá para dizer quanto a parcela cobra. |
| `parcela_sem_vencimento` | Parcela em aberto sem data de vencimento. Ver a regra 5. |
| `valor_divergente_do_lancamento` | O recebido na parcela é diferente do valor do lançamento vinculado. Um dos dois está errado, e qual deles é conferência de quem conhece o recebimento. |
| `os_com_titulo_do_fluxo_novo` | A OS já tem título gerado pela Task 18.3. Migrar as parcelas antigas por cima cobraria o mesmo serviço duas vezes. |

Também são listados, sem bloquear nada:

- **Lançamentos de recebimento sem parcela correspondente**: dinheiro que entrou
  no caixa e nenhuma cobrança explica. Não há título a criar sem inventar
  cliente e valor devido.
- **Recebimentos migrados sem lançamento vinculado**, com sugestões de
  lançamentos parecidos.
- **Títulos cuja soma das parcelas difere do valor final da OS.**

Parcela que não for migrada e também não estiver no relatório de exceções
**aborta a migração**. Todo registro precisa ter destino explicado.

## A conferência de totais

`App\Services\Financial\ConferenciaDeTotais` captura, antes de qualquer
gravação, um retrato JSON em
`storage/app/financeiro/migracao/retrato-totais-<data>_<hora>.json`, com todos
os totais por empresa, em centavos (inteiro, nunca float).

O retrato tem dois grupos:

**`intocavel`** — sai de `payment_details`, `financial_entries`,
`daily_cash_balances` e `work_orders`, que a migração não escreve. Precisa ser
idêntico depois:

- contagem e somas de `payment_details`, por status e por forma;
- contagem e somas de `financial_entries`, por origem e por situação;
- saldo por dia (abertura, entradas, saídas, fechamento, contagens);
- os painéis de `FinancialDashboardController`: resumo, gráfico por tipo,
  gráfico por forma de pagamento e evolução mensal;
- "a receber" por OS, como `WorkOrder::remaining_amount` mostra hoje.

**`espelhado`** — o que o modelo novo passa a responder. Depois da migração é
recalculado como *parcelas migradas (modelo novo) + parcelas que ficaram de
fora (modelo antigo)*:

- total a receber;
- total recebido, e recebido por mês de competência;
- recebido por forma de pagamento (a forma vem do lançamento vinculado, e
  recebimento sem lançamento entra em `sem_lancamento`, dos dois lados);
- contagem de parcelas, com e sem recebimento.

Parcela do modelo novo **sem** `payment_detail_id` fica fora da conferência:
título nascido pelo caminho novo (OS fechada depois do deploy, mensalidade de
contrato) não existia no retrato e não tem par no modelo antigo.

**Qualquer diferença, de um centavo que seja, desfaz a migração inteira.** Tudo
roda em uma transação, e a conferência acontece dentro dela: divergência faz
`rollback`, o comando termina com erro e o banco fica exatamente como estava.

## Passo a passo em produção

A ordem não é negociável. É o módulo que o cliente mais usa.

### 1. Ensaio em cópia do banco de produção

```
mysql -u root -p certificado_copia < backup-producao.sql
php artisan financeiro:migrar-titulos --dry-run
```

Confira o relatório em `storage/logs/migracao-financeira-<data>_<hora>.log`
**linha a linha**:

- toda exceção precisa estar entendida (não necessariamente resolvida);
- toda diferença entre soma das parcelas e valor da OS precisa fazer sentido;
- os lançamentos sem parcela precisam ter explicação.

Depois rode de verdade na cópia e compare os painéis antes e depois, **tela por
tela**: dashboard financeiro, fluxo de caixa, saldo diário, financeiro da OS.
Repita até o relatório de exceções estar vazio ou inteiramente entendido.

### 2. Migração em produção

Janela de baixo movimento, com backup **restaurado e conferido** imediatamente
antes.

```
# 1. Retrato antes de tudo, guardado fora do servidor
php artisan financeiro:migrar-titulos --dry-run

# 2. Migração, comparando contra o retrato já capturado
php artisan financeiro:migrar-titulos --retrato=storage/app/financeiro/migracao/retrato-totais-<data>_<hora>.json
```

Passar `--retrato=` é o caminho recomendado: compara contra o retrato tirado
antes da janela, e não contra um retrato tirado depois de o dado já ter mexido.

O comando pede confirmação antes de gravar. Em deploy automatizado use
`--force`, **nunca** `-n` / `--no-interaction`: a confirmação responde "não" por
padrão e o comando terminaria com sucesso sem ter gravado nada.

Saídas possíveis:

| Saída | O que aconteceu | O que fazer |
|---|---|---|
| `SUCCESS` com "Migração concluída" | Tudo gravado e conferido | Conferir os painéis do cliente |
| `SUCCESS` com "Nenhuma parcela nova a migrar" | Já estava migrado | Nada |
| `FAILURE` com "MIGRAÇÃO ABORTADA" | Divergência de totais, nada gravado | Ler as divergências no relatório, corrigir a causa, rodar de novo |

### 3. Depois

`payment_details` e `financial_entries` continuam sendo a fonte dos painéis. A
troca da leitura é a Task 18.7, **um painel por deploy**, cada um com a mesma
conferência de totais.

## Como desfazer

A esta altura nada foi apagado: `payment_details` e `financial_entries` estão
intactos, e os painéis do cliente continuam lendo deles. Desfazer é esvaziar as
tabelas novas.

```sql
-- Apaga só o que a migração criou, preservando título nascido pelo fluxo novo
DELETE ri FROM receivable_installments ri
WHERE ri.payment_detail_id IS NOT NULL;

DELETE r FROM receivables r
LEFT JOIN receivable_installments ri ON ri.receivable_id = r.id
WHERE ri.id IS NULL
  AND r.work_order_id IS NOT NULL;
```

Se nada além da migração tiver criado título ainda (o caso do primeiro deploy),
o caminho mais simples e mais seguro é esvaziar as duas tabelas:

```sql
SET FOREIGN_KEY_CHECKS = 0;
TRUNCATE TABLE receivable_installments;
TRUNCATE TABLE receivables;
SET FOREIGN_KEY_CHECKS = 1;
```

Confira depois:

```
php artisan tinker --execute="echo App\Models\Receivable::count().' / '.App\Models\ReceivableInstallment::count();"
```

Nenhuma das duas formas encosta em `payment_details`, em `financial_entries` ou
em `daily_cash_balances`. Depois de desfazer, `financeiro:migrar-titulos` pode
ser rodado de novo do zero.

## Reexecução

O comando é seguro para rodar de novo:

- parcela que já virou parcela de título (já aparece em
  `receivable_installments.payment_detail_id`) é ignorada;
- parcela criada pelo fluxo antigo **depois** da primeira execução é anexada ao
  título que a própria migração criou para aquela OS, com o próximo número, e o
  `valor_total` do título é recalculado;
- OS que ganhou título pelo fluxo novo passa a cair na exceção
  `os_com_titulo_do_fluxo_novo`, em vez de receber parcela migrada por cima.

## Arquivos

| Arquivo | Papel |
|---|---|
| `app/Console/Commands/MigrarFinanceiroParaTitulos.php` | O comando |
| `app/Services/Financial/ConferenciaDeTotais.php` | Retrato e comparação de totais |
| `tests/Feature/MigracaoFinanceiraTest.php` | Base de exemplo, aborto por divergência, exceções, reexecução |
| `storage/app/financeiro/migracao/retrato-totais-*.json` | Retrato capturado |
| `storage/logs/migracao-financeira-*.log` | Relatório de cada execução |
