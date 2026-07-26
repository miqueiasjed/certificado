# Tasks do Plano 4 - Fundação multiempresa

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 4.1 | Inventário das tabelas e models de domínio | backend-estrutura | ✅ | média |
| 4.2 | Etapa 1: company_id nullable em todas as tabelas | backend-estrutura | ✅ | baixa |
| 4.3 | Etapa 2: comando de backfill com conferência | backend-logica | ✅ | média |
| 4.4 | Etapa 3: company_id NOT NULL, índice e FK | backend-estrutura | ✅ | média |
| 4.5 | Etapa 4: uniques globais viram compostos | backend-estrutura | ✅ | média |
| 4.6 | Trait BelongsToCompany e resolvedor de tenant | backend-logica | ✅ | média |
| 4.7 | Company::current() resolvendo pelo usuário | backend-logica | ✅ | baixa |
| 4.8 | Aplicar BelongsToCompany nos models de domínio | backend-logica | ✅ | média |
| 4.9 | Tenant explícito fora de requisição HTTP | backend-logica | ✅ | média |
| 4.10 | Teste que detecta model de domínio sem a trait | teste | ✅ | média |
| 4.11 | Teste de vazamento entre tenants | teste | ✅ | alta |
| 4.12 | Documento do plano de retorno por etapa | config | ✅ | baixa |

## Ordem de execução

```
Lote 1:             4.1
Lote 2:             4.2                  (deploy 1 em produção)
Lote 3:             4.3                  (deploy 2, com conferência do relatório)
Lote 4:             4.4                  (deploy 3)
Lote 5:             4.5                  (deploy 4)
Lote 6:             4.6
Lote 7 (paralelo):  4.7  |  4.9
Lote 8:             4.8
Lote 9 (paralelo):  4.10 |  4.11         (deploy 5, só depois dos dois verdes)
Lote 10:            4.12
```

## Dependências internas

- 4.2, 4.3, 4.4 e 4.5 dependem de 4.1 (lista de tabelas) e uma da outra, em sequência estrita
- 4.6 depende de 4.4 (a coluna precisa existir e ser obrigatória)
- 4.7 depende de 4.6 (`TenantAtual`)
- 4.8 depende de 4.6 e de 4.7, e do relatório de 4.5 sobre numeração
- 4.9 depende de 4.6
- 4.10 depende de 4.8
- 4.11 depende de 4.8 e 4.9
- 4.12 depende de todas: ele registra o que de fato foi executado

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `app/Models/User.php` | 4.7, 4.8 | 4.7 antes de 4.8 |
| `app/Models/*.php` (domínio) | 4.8 | Task única; não despachar nada que toque model junto |
| `app/Support/DominioMultiempresa.php` | 4.1 | Fonte única; 4.2, 4.4, 4.5, 4.10 apenas leem |

## Ordem de aplicação em produção

Cada etapa é um deploy próprio, validado antes do seguinte. Agrupar etapas é o erro que derruba a operação do cliente atual.

1. **Deploy 1** (Task 4.2): coluna nullable. Sem efeito observável. Conferir que o sistema opera normalmente por pelo menos um dia útil.
2. **Deploy 2** (Task 4.3): rodar `multiempresa:backfill --dry-run`, conferir o relatório linha a linha, rodar sem a flag, guardar o log.
3. **Deploy 3** (Task 4.4): duas migrations na mesma leva. `155000` funda o tenant em banco novo (no-op em produção, onde a empresa 1 já existe) e `160000` aplica NOT NULL **com DEFAULT 1**, índice e FK `ON DELETE RESTRICT`. Medir o tempo em cópia do banco antes: passar de NULL para NOT NULL reconstrói a tabela e bloqueia escrita, e as maiores aqui são `audit_logs`, `access_logs`, `device_events` e `work_order_photos`. Janela de baixo movimento.

   O DEFAULT 1 existe para cobrir a janela entre este deploy e o Deploy 5. Sem ele, `company_id` fica obrigatório antes de a trait passar a preenchê-lo, e toda criação de cliente, OS ou certificado falha em produção.
4. **Deploy 4** (Task 4.5): uniques compostas. Ponto sem retorno depois que existir um segundo tenant.
5. **Deploy 5** (Tasks 4.6 a 4.9): código com escopo ativo. Só sobe com 4.10 e 4.11 verdes. Depois de a trait estar no ar, e só depois, rodar:

   ```
   php artisan migrate --path=database/migrations/deploy5
   ```

   Esse passo remove o DEFAULT 1. Esquecê-lo deixa o default vivo, e aí um insert que não informa o tenant grava a empresa 1 em silêncio, que é exatamente o vazamento entre empresas que este plano existe para evitar. O arquivo mora fora de `database/migrations/` de propósito: na raiz, ele rodaria junto com o Deploy 3 e derrubaria o deploy e a suíte de testes.
6. Só depois disso um segundo tenant real entra.

O passo a passo executável de cada um desses cinco deploys, com comando exato,
consulta de conferência, saída esperada, sinais de erro nas primeiras horas e
retorno, está em `docs/multiempresa-migracao.md` (Task 4.12). Os riscos que
ficaram em aberto estão registrados lá, no fim, e quatro deles precisam de
decisão antes do segundo tenant.

## Observações

- O plano estimava ~8 tasks. A decomposição chegou a 12 porque cada etapa da migração é um deploy próprio e precisa de task própria, e porque o teste de vazamento não cabe junto com o teste de trait.
- `users.email` continua unique global por decisão registrada no PRD. Isso significa que um e-mail pertence a uma empresa só, e a decisão de permitir a mesma pessoa em duas empresas fica para quando houver demanda real.
- `Company::current()` deixa de criar empresa por acidente (`firstOrCreate`). Se alguma rotina dependia disso, ela quebra na Task 4.7, e é melhor que quebre em teste do que em produção com dois tenants.
- As tabelas de auditoria do Plano 3 entram na lista de domínio da Task 4.1.
