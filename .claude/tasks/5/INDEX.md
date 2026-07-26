# Tasks do Plano 5 - Painel do super admin

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 5.1 | Migration e model do plano comercial e campos de plataforma | backend-estrutura | ✅ | média |
| 5.2 | Migration e model de uso por tenant | backend-estrutura | ✅ | baixa |
| 5.3 | Área do super admin com acesso separado | backend-endpoint | ✅ | média |
| 5.4 | Service de gestão de tenants | backend-logica | ✅ | média |
| 5.5 | Assumir tenant para suporte, com registro em auditoria | backend-endpoint | ✅ | média |
| 5.6 | Apuração de uso por tenant | backend-logica | ✅ | média |
| 5.7 | Endpoints de tenants e de planos comerciais | backend-endpoint | ✅ | alta |
| 5.8 | Endpoints do painel geral e do uso por tenant | backend-endpoint | ✅ | média |
| 5.9 | Layout do painel da plataforma e faixa de suporte | frontend-componente | ✅ | média |
| 5.10 | Telas de lista e cadastro de tenants | frontend-pagina | ✅ | alta |
| 5.11 | Telas de visão geral, uso do tenant e planos | frontend-pagina | ✅ | alta |
| 5.12 | Testes do painel da plataforma | teste | ✅ | alta |

## Ordem de execução

```
Lote 1 (paralelo):  5.1  |  5.2
Lote 2 (paralelo):  5.3  |  5.4
Lote 3 (paralelo):  5.5  |  5.6
Lote 4:             5.7                  (toca routes/web.php)
Lote 5:             5.8                  (toca routes/web.php, depois de 5.7)
Lote 6:             5.9
Lote 7 (paralelo):  5.10 |  5.11
Lote 8:             5.12
```

## Dependências internas

- 5.3 depende de 5.1 (`is_platform_admin`)
- 5.4 depende de 5.1 (campos de situação e plano)
- 5.5 depende de 5.3 (grupo de rotas) e do Plano 3 (model `AccessLog`)
- 5.6 depende de 5.2
- 5.7 depende de 5.3 e 5.4
- 5.8 depende de 5.4 e 5.6
- 5.9 depende de 5.5 (`suporte` compartilhado com o frontend)
- 5.10 depende de 5.7 e 5.9
- 5.11 depende de 5.7, 5.8 e 5.9
- 5.12 depende de 5.4, 5.5, 5.6 e 5.7

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/web.php` | 5.3, 5.5, 5.7, 5.8 | Uma por vez, nesta ordem |
| `app/Support/TenantAtual.php` | 5.5 | Altera a resolução criada no Plano 4; nenhuma outra task toca |
| `app/Http/Middleware/HandleInertiaRequests.php` | 5.5 | Única do plano |
| `app/Support/DominioMultiempresa.php` | 5.1, 5.2 | Ambas acrescentam tabela à lista fora do escopo; sequenciar |

## Decisões registradas

- **Login único, não guard separado.** Mesma tabela `users`, mesmo formulário, redirecionamento e middleware diferentes. Um guard próprio dobraria manutenção de sessão, recuperação de senha e auditoria sem ganho de isolamento real, que vem do middleware.
- **Super admin fica com `company_id` da empresa 1** e `is_platform_admin = true`, em vez de `company_id` nulo. Isso evita afrouxar a restrição NOT NULL criada no Plano 4.
- **Apuração de uso é diária, não mensal**, sobrescrevendo o mês corrente, para o painel mostrar número fresco sem cálculo pesado sob demanda.
- **Exclusão de tenant não existe neste plano.** A FK `restrictOnDelete` do Plano 4 força que ela tenha um fluxo próprio quando for necessária.

## Ordem de aplicação em produção

1. Deploy das migrations 5.1 e 5.2. A 5.1 marca a empresa 1 como interna: conferir com `select id, is_internal, situacao from companies`.
2. Criar o primeiro super admin manualmente (`update users set is_platform_admin = 1 where id = ?`), porque não há tela para isso ainda.
3. Deploy do restante. Conferir imediatamente que o cliente atual continua entrando normalmente e caindo no dashboard das empresas.
4. Rodar `php artisan plataforma:apurar-uso` uma vez à mão antes de confiar no painel.

## Observações

- O plano estimava ~7 tasks. A decomposição chegou a 12: a área da plataforma tem backend próprio (acesso, tenants, planos, uso) e três telas, e nenhuma delas cabe agrupada.
- `plans` e `tenant_usages` são tabelas de plataforma e ficam fora do escopo por empresa. As duas precisam ser classificadas em `DominioMultiempresa`, senão o teste da Task 4.10 falha.
- A faixa de suporte é o item que mais evita erro operacional: sem ela, alguém registra dado no tenant errado sem perceber.
