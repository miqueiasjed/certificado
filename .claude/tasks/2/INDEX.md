# Tasks do Plano 2 - Papéis e permissões

> Gerado em: 26/07/2026

## Legenda
- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 2.1 | Instalar e configurar Spatie Permission | config | ✅ | baixa |
| 2.2 | Comando SyncPermissions com o catálogo de permissões | backend-logica | ✅ | média |
| 2.3 | Seeder de papéis com as permissões de cada papel | backend-estrutura | ✅ | média |
| 2.4 | Migration: usuário ativo e vínculo com técnico | backend-estrutura | ✅ | baixa |
| 2.5 | Comando de backfill: papel inicial e vínculo técnico-usuário | backend-logica | ✅ | média |
| 2.6 | Middleware nas rotas financeiras (crítico) | backend-endpoint | ✅ | média |
| 2.7 | Middleware nas demais rotas de domínio e cadastros | backend-endpoint | ✅ | alta |
| 2.8 | Técnico só enxerga e executa as próprias OS | backend-logica | ✅ | média |
| 2.17 | Verificação de dono nos demais controllers da OS | backend-logica | ✅ | média |
| 2.18 | Dashboard não entrega dado financeiro sem permissão | backend-endpoint | ✅ | baixa |
| 2.19 | Dashboard esconde o bloco financeiro sem permissão | frontend-pagina | ✅ | baixa |
| 2.20 | Remover rotas de resource sem método no controller | backend-endpoint | ✅ | baixa |
| 2.9 | Compartilhar papéis e permissões com o frontend | backend-endpoint | ✅ | baixa |
| 2.10 | Composable de permissões e menu filtrado | frontend-componente | ✅ | média |
| 2.11 | Ações das telas respeitando permissão | frontend-pagina | ✅ | baixa |
| 2.12 | Service de gestão de usuários e bloqueio de login inativo | backend-logica | ✅ | média |
| 2.13 | Endpoints de gestão de usuários | backend-endpoint | ✅ | média |
| 2.14 | Tela de gestão de usuários da empresa | frontend-pagina | ✅ | alta |
| 2.15 | Teste de acesso negado por papel nas rotas financeiras | teste | ✅ | média |
| 2.16 | Teste do escopo do técnico e da regra do último administrador | teste | ✅ | média |

## Ordem de execução

```
Lote 1:             2.1                      (sozinho: toca User.php, base de tudo)
Lote 2 (paralelo):  2.2  |  2.4  |  2.9
Lote 3:             2.3
Lote 4 (paralelo):  2.5  |  2.6  |  2.7  |  2.8  |  2.10  |  2.12
Lote 5 (paralelo):  2.11 |  2.13
Lote 6:             2.14
Lote 7 (paralelo):  2.15 |  2.16
```

## Dependências internas

- 2.2 depende de 2.1
- 2.3 depende de 2.2
- 2.5 depende de 2.3 e 2.4
- 2.6 e 2.7 dependem de 2.3 (nomes das permissões) e alteram o mesmo arquivo,
  `routes/web.php`: **nunca despachar em paralelo**
- 2.8 depende de 2.4
- 2.9 depende de 2.1
- 2.10 depende de 2.9
- 2.11 depende de 2.10
- 2.12 depende de 2.3 e 2.4
- 2.13 depende de 2.12 e toca `routes/web.php`: rodar depois de 2.6 e 2.7
- 2.14 depende de 2.13 e de 2.10 (toca `Sidebar.vue`, alterado em 2.10)
- 2.15 depende de 2.6
- 2.16 depende de 2.8 e 2.12

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/web.php` | 2.6, 2.7, 2.13 | Uma por vez, nesta ordem |
| `resources/js/Components/Sidebar.vue` | 2.10, 2.14 | 2.10 antes de 2.14 |
| `app/Models/User.php` | 2.1, 2.4 | 2.1 antes de 2.4 |

## Ordem de aplicação em produção

1. Deploy do código com a migration da Task 2.4 e as do Spatie (Task 2.1).
2. `php artisan permissions:sync` e `php artisan db:seed --class=RolesAndPermissionsSeeder`.
3. `php artisan acesso:backfill --dry-run`, conferir a saída, depois sem a flag.
4. Só então liberar o acesso. Antes do passo 3 todo usuário fica sem papel e recebe
   403 em tudo, então os passos 2 e 3 rodam no mesmo deploy, não em deploys separados.
