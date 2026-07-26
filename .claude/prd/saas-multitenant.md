# SaaS multiempresa

## Restrição que define tudo

O sistema **já roda em produção para um cliente**. Nenhuma etapa da
transformação pode deixá-lo sem operar, perder dado ou mudar o que ele vê. Toda
migração é incremental, aplicada com o sistema no ar e reversível.

## Ponto a favor

`app/Models/Company.php:41` já resolve a empresa corrente assim:

```php
public static function current()
{
    return self::firstOrCreate(['id' => 1]);
}
```

O cliente atual já é o tenant de id 1, e `current()` é o único ponto de acesso à
empresa no código (7 chamadas: `CompanyController`, `BudgetService`,
`CertificateService`, `WorkOrderService` x2, `ContractService`). A troca para
resolução por usuário autenticado acontece nesse único método.

## Modelo de isolamento

Banco único. `company_id` em toda tabela de domínio, escopo global aplicado por
trait no Eloquent.

```
companies (é a tabela de tenants)
  id 1 = cliente atual, criado antes do SaaS
  id 2+ = novos tenants

Toda query de domínio: where company_id = <tenant do usuário>
```

Regras:

1. Tabela de domínio raiz recebe `company_id`. Tabela pivot herda o escopo pelo
   pai e não recebe coluna.
2. O escopo global é aplicado por trait `BelongsToCompany`, que também preenche
   `company_id` automaticamente ao criar.
3. Um model de domínio sem a trait é considerado bug. Um teste automatizado
   percorre `app/Models/` e falha se algum model de domínio não a usar.
4. Fora de requisição HTTP (comando artisan, seeder, job de fila) não existe
   usuário autenticado. O tenant precisa ser informado explicitamente, e o job
   carrega o `company_id` no payload.
5. O super admin não pertence a nenhum tenant. Para operar dentro de um, ele
   assume o tenant explicitamente, e essa ação é registrada em auditoria.

## Ordem da migração (não destrutiva)

Cada passo é um deploy próprio, validado em produção antes do seguinte:

1. `company_id` nullable em todas as tabelas de domínio, sem FK.
2. Backfill `company_id = 1` em todos os registros. Validar contagem por tabela
   antes e depois.
3. `company_id` NOT NULL, índice e chave estrangeira.
4. Uniques globais viram compostos com `company_id` (ver `divida-tecnica.md`).
5. Trait com escopo global ativada, `Company::current()` passa a resolver pelo
   usuário. Com um único tenant o comportamento observável é idêntico.
6. Teste de vazamento: criar o tenant 2 com dados, confirmar que o usuário do
   tenant 1 não vê nada dele em nenhuma tela ou endpoint.

Só depois de o passo 6 passar é que um segundo tenant real entra.

## Planos comerciais e liberação de módulos

O super admin define planos. Cada plano libera um conjunto de módulos, e cada
tenant fica em um plano, com possibilidade de liberação pontual fora do plano.

Módulos controláveis: financeiro, estoque, contratos, portal do cliente, app do
técnico, notificações, roteirização, NFS-e, laudo por IA, relatórios de
monitoramento.

Regras:

- Módulo bloqueado não aparece no menu e o acesso direto por URL é negado. A
  verificação vive no backend; esconder só no frontend não conta.
- Limite quantitativo por plano (usuários, clientes, OS por mês) com aviso ao
  se aproximar do teto, nunca perda de dado ao estourar.
- Rebaixar plano nunca apaga dado. O módulo fica somente leitura ou oculto, e o
  dado volta ao ser reativado.
- O tenant 1 recebe um plano interno com tudo liberado e sem cobrança, para que
  o cliente atual não perceba mudança.

## Assinaturas dos tenants

Gateway: **PagBank**, atrás de uma interface `GatewayAssinatura` para permitir
troca sem tocar no domínio.

- Assinatura recorrente com PIX, boleto e cartão.
- Webhook de confirmação, com registro de todo evento recebido e reprocessamento
  seguro em caso de duplicata.
- Inadimplência: período de tolerância configurável, avisos antes do bloqueio,
  bloqueio de acesso mantendo os dados intactos, reativação imediata ao pagar.
- Bloqueio nunca se aplica ao tenant 1.
- Histórico de faturas visível ao tenant e ao super admin.

## Onboarding de novo tenant

Criar um tenant precisa ser autônomo, sem intervenção em banco:

- Cadastro da empresa, criação do usuário administrador e envio de convite.
- Seed dos cadastros base que toda dedetizadora usa: tipos de evento, tipos de
  isca, tipos de serviço, unidades. Sem isso o sistema chega inutilizável.
- Cadastros regulatórios (princípio ativo, grupo químico, antídoto, registro em
  órgão) partem de um catálogo compartilhado copiado para o tenant, que passa a
  poder editar o próprio.
- Trilha de primeiros passos indicando o que falta configurar.
