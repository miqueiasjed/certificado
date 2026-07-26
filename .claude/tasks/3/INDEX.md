# Tasks do Plano 3 - Auditoria e histórico de alterações

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 3.1 | Migrations e models de auditoria | backend-estrutura | ✅ | baixa |
| 3.2 | Trait Auditavel com captura de valores antes e depois | backend-logica | ✅ | média |
| 3.3 | Aplicar Auditavel nos models sensíveis | backend-logica | ✅ | baixa |
| 3.4 | Registro de acesso: login, falha de login e logout | backend-logica | ✅ | baixa |
| 3.5 | Service e endpoint de consulta do histórico | backend-endpoint | ✅ | média |
| 3.6 | Componente de linha do tempo do histórico | frontend-componente | ✅ | média |
| 3.7 | Histórico embutido nas telas dos registros sensíveis | frontend-pagina | ✅ | baixa |
| 3.8 | Expurgo de auditoria agendado | backend-logica | ✅ | baixa |
| 3.9 | Testes de auditoria e de registro de acesso | teste | ✅ | média |

## Ordem de execução

```
Lote 1:             3.1
Lote 2 (paralelo):  3.2  |  3.4
Lote 3 (paralelo):  3.3  |  3.5  |  3.8
Lote 4:             3.6
Lote 5:             3.7
Lote 6:             3.9
```

## Dependências internas

- 3.2 depende de 3.1 (model `AuditLog`)
- 3.3 depende de 3.2 (trait)
- 3.4 depende de 3.1 (model `AccessLog`)
- 3.5 depende de 3.1 e do catálogo de permissões do Plano 2 (Tasks 2.2 e 2.3)
- 3.6 depende de 3.5 (endpoint) e da Task 2.9 do Plano 2 (permissões no frontend)
- 3.7 depende de 3.6
- 3.8 depende de 3.1 e da config criada em 3.2
- 3.9 depende de 3.3, 3.4, 3.5 e 3.8

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/web.php` | 3.5 | Única do plano; sincronizar com as tasks 2.6, 2.7 e 2.13 do Plano 2 |
| `app/Models/User.php` | 3.3 | Depois das tasks 2.1 e 2.4 do Plano 2 |
| `app/Support/RotinasAgendadas.php` | 3.8 | Única do plano |

## Ordem de aplicação em produção

1. Deploy com as migrations da 3.1 (tabelas novas, sem dado existente: um único passo).
2. Deploy do restante do código. A auditoria passa a gravar a partir daí, sem histórico retroativo, o que é esperado.
3. `php artisan permissions:sync` para criar `auditoria-ver` e `acesso-log-ver`, e reatribuir ao papel administrador.
4. Conferir o crescimento de `audit_logs` na primeira semana antes de decidir se 730 dias de retenção é adequado.

## Observações

- O plano estimava ~5 tasks. A decomposição chegou a 9 porque o entregável "registro automático" se divide em estrutura, trait e aplicação, e o frontend não pode ser agrupado com backend.
- Sem `company_id` em nenhuma tabela deste plano. O Plano 4 varre todas as tabelas de domínio e inclui estas duas na varredura.
- O evento `tenant_assumido` fica declarado na tabela desde a 3.1, mas quem o dispara é o Plano 5.
