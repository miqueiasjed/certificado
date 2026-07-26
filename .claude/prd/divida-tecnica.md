# Dívida técnica mapeada na auditoria

> Levantado em 25/07/2026 lendo o código em produção.

## 1. Comandos artisan nunca executam

`app/Console/Commands/` tem quatro comandos e **nenhum está agendado**:

- `UpdateCertificateStatus` - certificado vencido nunca muda de status.
- `UpdatePaymentStatuses` - pagamento em atraso nunca vira `overdue`.
- `SyncDailyCashBalances` - saldo diário só existe se alguém rodar na mão.
- `CreateMissingDailyBalances` - idem.

`routes/console.php` só tem o `inspire` padrão do Laravel e `bootstrap/app.php`
não chama `withSchedule()`. Em Laravel 11 o agendamento vai em um desses dois
lugares.

Efeito em produção: status de certificado e de pagamento exibidos ao usuário
estão errados hoje.

## 2. Tabelas órfãs

Criadas por migration e nunca referenciadas pelo código:

- `work_order_products` (o código usa `work_order_product`, singular, em
  `app/Models/WorkOrder.php:106`)
- `service_order_products` (o código usa `service_order_product`, em
  `app/Models/ServiceOrder.php:58`)

Confundem quem lê o schema e viram armadilha na migração multi-tenant, porque
parecem precisar de `company_id`.

## 3. Uniques globais que quebram com o segundo tenant

| Tabela | Coluna | Efeito com 2 tenants |
|---|---|---|
| `daily_cash_balances` | `balance_date` | Segundo tenant não consegue criar o saldo do dia. Falha silenciosa no financeiro |
| `work_orders` | `order_number` | Colisão de numeração entre empresas |
| `service_orders` | `order_number` | Idem |
| `technicians` | `email` | Mesmo técnico não pode existir em duas empresas |
| `service_types` | `slug` | Cada empresa precisa dos próprios tipos |

Todos precisam virar unique composto com `company_id`.

Exceção deliberada: `users.email` continua unique global, porque o login é por
e-mail e um e-mail pertencendo a duas empresas exigiria seletor de tenant no
login sem ganho real.

## 4. Ausência de controle de acesso

Nenhuma occorrência de `role` ou `permission` em `app/`. Qualquer usuário
autenticado acessa `/financial-dashboard`, `/cash-flow` e
`/financial-withdrawals`. Um técnico vê o faturamento da empresa.

## 5. Ausência de trilha de auditoria

Nenhum registro de quem alterou o quê. Em OS, certificado e lançamento
financeiro isso é exigência prática de conformidade e de resolução de disputa
com cliente.

## 6. Endereços sem geolocalização

`addresses` não tem `latitude` nem `longitude`, o que bloqueia mapa,
roteirização e cálculo de deslocamento.

## 7. Produto sem dimensão de estoque

`products` só tem ficha técnica (princípio ativo, grupo químico, antídoto,
registro). Sem saldo, lote, validade ou custo, o custo real de uma OS é
incalculável e a rastreabilidade exigida pela RDC fica incompleta.
