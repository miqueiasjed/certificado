---
name: permissoes-e-multitenancy
description: Diretrizes de autorização com Spatie Permission e de isolamento de dados entre empresas (multiempresa) no Sistema de Certificados. Use ao criar permissão, papel, endpoint que recebe Model por rota, ou qualquer model de domínio.
---
# Skill: Permissões e isolamento entre empresas

Duas camadas de acesso convivem neste sistema, e confundi-las causa falha grave:

1. **Permissão**: o que este usuário pode fazer dentro da empresa dele.
2. **Isolamento por empresa**: a qual empresa este dado pertence.

Permissão correta com isolamento furado significa vazamento de dado de uma
empresa para outra. É a falha mais grave possível em um SaaS, e aqui teria
consequência real, porque os tenants são concorrentes entre si.

## 1. Estado atual

Antes do Plano 2 não existe nenhum controle de acesso: qualquer usuário
autenticado abre `/financial-dashboard`, `/cash-flow` e
`/financial-withdrawals`. Antes do Plano 4 não existe `company_id`.

Ao implementar esses planos, esta skill é a convenção. Depois deles, é a regra
para todo código novo.

## 2. Autorização com Spatie Permission

Padrão adotado: **Spatie Permission**.

- **Papéis** são perfis amplos: `administrador`, `financeiro`, `comercial`,
  `tecnico`, `leitura`.
- **Permissões** são ações específicas, no formato `recurso-acao`:
  `ordem-servico-criar`, `certificado-emitir`, `financeiro-ver`,
  `contrato-excluir`.

### 2.1. Middlewares

- Proteja rotas sempre com os middlewares do Spatie: `role:administrador`,
  `permission:financeiro-ver`, `role:administrador|financeiro`.
- Aplique em **grupos de rotas** em `routes/web.php`, ou no `__construct()` do
  controller.
- Rota financeira sem middleware é bug, não descuido.

### 2.2. Controller, Service e Policy

- **Controller**: sem lógica de autorização complexa. Confia no middleware e
  orquestra.
- **Service**: revalida quando a autorização está ligada à regra de negócio (por
  exemplo, quem pode reabrir um pagamento já baixado).
- **Policy**: apenas quando o acesso depende da instância. Neste projeto,
  Spatie é a regra e policy é exceção.

### 2.3. Comando de sincronização

Crie e mantenha `app/Console/Commands/SyncPermissions.php`, com um método que
retorna **todas** as permissões do sistema, organizadas por módulo.

Sempre que criar permissão nova, em seeder ou em código:

1. Adicione ao array desse método, na categoria correta.
2. Rode `php artisan permissions:sync`.

Permissão que existe no código e não está lá desaparece no próximo sync do
servidor.

## 3. Isolamento entre empresas

### 3.1. Regra base

Todo model de domínio:

- tem `company_id` na tabela;
- usa a trait `BelongsToCompany`, que aplica escopo global e preenche
  `company_id` na criação.

Tabela pivot não recebe a coluna: herda o escopo pelo pai.

Model de domínio sem a trait é bug. Existe teste que percorre `app/Models/` e
falha nesse caso.

### 3.2. Fora da requisição HTTP

Comando artisan, seeder e job de fila não têm usuário autenticado, e portanto
não têm empresa. Nesses contextos:

- O tenant é informado explicitamente, nunca inferido.
- Job carrega `company_id` no payload e o aplica ao iniciar.
- Rotina que roda para todos os tenants itera empresa por empresa, aplicando o
  contexto em cada volta. Nunca consulte sem escopo "para pegar tudo de uma vez"
  e depois filtre em memória.

### 3.3. Super admin

O super admin não pertence a empresa nenhuma. Para operar dentro de uma, assume
o tenant explicitamente, com aviso visível na interface e registro em auditoria.
Consulta sem escopo é privilégio exclusivo da área de plataforma, e nunca
aparece em código de domínio.

### 3.4. Uniques

Todo unique de domínio é composto com `company_id`. Unique global em campo de
domínio impede a existência do segundo tenant. Casos já mapeados em
`.claude/prd/divida-tecnica.md`, item 3.

Exceção deliberada: `users.email` é global, porque o login é por e-mail.

## 4. Convenção contra acesso indevido a registro

**Regra de ouro:** todo endpoint que recebe um Model por route-model binding
(`{workOrder}`, `{client}`, `{certificate}`, `{device}`, `{contract}`) precisa
garantir que o registro pertence a quem está pedindo.

Com o escopo global ativo, a maior parte disso é automática: o binding não
encontra registro de outra empresa e responde 404. Isso cobre o vazamento entre
tenants, e **não** cobre o acesso indevido dentro da mesma empresa.

Continua sendo necessário verificar explicitamente:

- **Técnico** só acessa OS em que está atribuído. Escopo por empresa não separa
  um técnico do outro.
- **Cliente no portal** só acessa os próprios dados. O portal é acesso externo,
  e ali toda consulta é escopada ao cliente autenticado, além da empresa.
- **ID vindo do corpo da requisição**, e não da rota, precisa do mesmo cuidado:
  `client_id`, `address_id`, `device_id` enviados pelo formulário são escopados
  antes de usar. O escopo global cobre a consulta pelo model; concatenar id sem
  consultar não passa por ele.

Ordem de preferência do vetor:

1. **Escopo global por empresa** para separar tenants, sempre ativo.
2. **`authorize()` real no FormRequest** para ação pontual.
3. **Policy** para model com várias ações e regra de dono.
4. **Checagem no Service** quando a autorização está acoplada à regra de negócio.

`authorize() => true` só é aceitável quando a rota é inteiramente protegida por
papel administrativo. Nunca em rota de autoatendimento nem no portal do cliente.

Resposta padrão: **403** para quem não é dono, **404** quando revelar a
existência do registro já vaza informação, que é o caso entre empresas
diferentes.

## 5. Checklist de revisão

Antes de aprovar código que toca dado de domínio:

- [ ] Model novo tem `company_id` e a trait de escopo?
- [ ] Unique novo é composto com `company_id`?
- [ ] Rota tem middleware de papel ou permissão coerente?
- [ ] Permissão nova entrou no `SyncPermissions`?
- [ ] Endpoint que recebe Model por binding tem a verificação de dono, quando o
      escopo por empresa não basta?
- [ ] ID vindo do corpo é escopado antes de ser usado?
- [ ] Job novo carrega o `company_id` no payload?
- [ ] Existe teste de regressão: usuário do tenant A não alcança dado do tenant B?
