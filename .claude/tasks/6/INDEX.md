# Tasks do Plano 6 - Liberação de módulos por plano

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 6.1 | Migrations e models de módulos | backend-estrutura | ✅ | média |
| 6.2 | Catálogo dos módulos controláveis | backend-estrutura | ✅ | baixa |
| 6.3 | Service de módulos ativos por tenant | backend-logica | ✅ | média |
| 6.4 | Middleware de módulo e aplicação nas rotas | backend-endpoint | ✅ | média |
| 6.5 | Limites quantitativos do plano | backend-logica | ✅ | média |
| 6.6 | Módulos ativos no frontend e menu filtrado | frontend-componente | ✅ | média |
| 6.7 | Endpoints de gestão de módulos por plano e tenant | backend-endpoint | ✅ | média |
| 6.8 | Telas de módulos, indisponível e aviso de limite | frontend-pagina | ✅ | alta |
| 6.9 | Testes de bloqueio de módulo e de limites | teste | ✅ | alta |

## Ordem de execução

```
Lote 1:             6.1
Lote 2:             6.2
Lote 3 (paralelo):  6.3  |  6.5
Lote 4:             6.4                  (toca routes/web.php)
Lote 5:             6.7                  (toca routes/web.php, depois de 6.4)
Lote 6:             6.6
Lote 7:             6.8
Lote 8:             6.9
```

## Dependências internas

- 6.2 depende de 6.1 (model `Module`)
- 6.3 depende de 6.1 e 6.2
- 6.4 depende de 6.3
- 6.5 depende do Plano 5 (`Plan` e `TenantUsageService`)
- 6.6 depende de 6.3 e da Task 2.10 do Plano 2 (menu já filtrado por permissão)
- 6.7 depende de 6.3
- 6.8 depende de 6.4 (página de indisponível), 6.5 (avisos) e 6.7 (endpoints)
- 6.9 depende de 6.4, 6.5 e 6.7

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/web.php` | 6.4, 6.7 | 6.4 antes de 6.7 |
| `app/Http/Middleware/HandleInertiaRequests.php` | 6.5, 6.6 | Uma por vez; ambas acrescentam chave ao `share()` |
| `resources/js/Layouts/AuthenticatedLayout.vue` | 6.8 | Já alterado no Plano 5 (Task 5.9); conferir o estado antes |
| `app/Support/DominioMultiempresa.php` | 6.1 | Acrescenta 3 tabelas fora do escopo |

## Decisões registradas

- **Módulo indisponível some do menu**, em vez de aparecer desabilitado com cadeado. O cadeado virou padrão de mercado, mas em sistema de uso diário ele adiciona ruído permanente. O convite comercial fica concentrado na página de módulo indisponível e na tela de plano do tenant (Plano 7).
- **Só o limite de usuários bloqueia a criação.** Cliente e OS acima do teto continuam sendo criados, com aviso. Travar a operação de quem já paga custa mais que cobrar depois.
- **Permissão e módulo são verificações independentes e cumulativas.** Permissão diz o que o usuário pode fazer; módulo diz o que a empresa contratou.
- **Três módulos são `sempre_ativo`** (clientes, ordens de serviço, certificados): são o produto, não um extra vendável.

## Ordem de aplicação em produção

1. Deploy da migration 6.1 e do seeder 6.2. Conferir que o tenant 1 ficou no plano interno com os 13 módulos.
2. Deploy do código com o middleware (6.4). **Antes de subir**, confirmar em `select * from company_modules` e no plano interno que o tenant 1 tem financeiro e contratos: um erro aqui tira o financeiro do cliente atual do ar.
3. Deploy do frontend (6.6 e 6.8). O menu do cliente atual precisa continuar idêntico.

## Observações

- O plano estimava ~6 tasks. A decomposição chegou a 9 porque os limites quantitativos são um Service próprio, e o frontend tem componente, telas da plataforma e telas das empresas.
- Sete módulos do catálogo ainda não têm rota (estoque, portal do cliente, app do técnico, notificações, roteirização, NFS-e, laudo por IA). Eles ficam declarados e o middleware entra junto com o plano que os implementa. Isso está anotado no comentário do grupo de rotas da Task 6.4.
