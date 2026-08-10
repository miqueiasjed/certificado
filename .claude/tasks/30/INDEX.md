# Tasks do Plano 30 - Compromissos avulsos: cadastro e agenda

> Gerado em: 10/08/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 30.1 | Model Compromisso, factory e registro multiempresa | backend-estrutura | ✅ | baixa |
| 30.2 | CompromissoService: ciclo de vida e promoção para OS | backend-logica | ✅ | média |
| 30.3 | Endpoints, permissões e rotas do compromisso | backend-endpoint | ✅ | alta |
| 30.4 | Agenda mescla ordens de serviço e compromissos | backend-logica | ✅ | média |
| 30.5 | Frontend: compromisso na Agenda | frontend-pagina | ✅ | alta |
| 30.6 | Testes de endpoint e da mescla na Agenda | teste | ✅ | média |

## Ordem de execução

```
Lote 1:             30.1
Lote 2 (paralelo):  30.2  |  30.4
Lote 3:             30.3
Lote 4 (paralelo):  30.5  |  30.6
```

## Dependências internas

- 30.2 depende de 30.1 (precisa do model `Compromisso`)
- 30.4 depende só de 30.1 (lê `Compromisso`, não usa `CompromissoService`
  nem o controller): pode rodar em paralelo com 30.2
- 30.3 depende de 30.2 (chama `CompromissoService` e captura
  `CompromissoNaoPromovelException`)
- 30.5 depende de 30.3 (consome os endpoints) e de 30.4 (a Agenda já precisa
  devolver compromisso mesclado antes de o frontend saber renderizar)
- 30.6 depende de 30.3 e 30.4 (testa os dois)

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `app/Support/DominioMultiempresa.php` | 30.1 | Task única; acrescenta `Compromisso::class` a `MODELS_DE_DOMINIO`. `TABELAS_DE_DOMINIO` e a unique de `route_stops` já foram registradas fora deste plano, na sessão que criou as migrations |
| `routes/web.php` | 30.3 | Task única |
| `app/Console/Commands/SyncPermissions.php` | 30.3 | Task única; acrescenta 2 permissões |
| `app/Services/AgendaService.php`, `app/Http/Controllers/AgendaController.php` | 30.4 | Task única, nenhuma outra task toca estes dois arquivos |

Nenhum arquivo é tocado por duas tasks ao mesmo tempo: 30.2 e 30.4 podem
rodar em paralelo sem risco de colisão.

## Decisões registradas

- **Sem middleware de módulo.** Compromisso é extensão da Agenda (Plano 10),
  que já não é `module:`-gated no projeto: reaproveita o mesmo critério, só
  permissão. Diferente de EPI (28) e Roteirização (22), que são módulos
  licenciáveis.
- **`compromisso-ver` não entra na lista de exceções do papel `leitura`.**
  Diferente de `epi-ver`/`comissoes-ver`/`usuario-ver`, compromisso não
  carrega o mesmo tipo de dado sensível.
- **Compromisso se exclui de verdade (`destroy`).** Diferente da entrega de
  EPI (documento oponível, só estorno): compromisso é mais parecido com um
  evento de agenda comum.
- **A migration e o registro de `compromissos`/`route_stops` em
  `DominioMultiempresa::TABELAS_DE_DOMINIO` e `UNIQUES_GLOBAIS_MANTIDOS` já
  existem**, criados numa sessão anterior a pedido do usuário, antes deste
  `create-tasks` rodar. As tasks partem desse estado; nenhuma recria
  migration.

## Ordem de aplicação em produção

Migration já aplicada em desenvolvimento (Tasks 30.1 a 30.6 não tocam
schema). Como é tabela nova sem dado existente, não há etapas de deploy: um
deploy só, com o módulo... **não há módulo a ligar/desligar** (ver decisão
acima). O que existe é permissão: nenhum papel além de `administrador` e
`leitura` (via sufixo `-ver`) enxerga compromisso até alguém conceder
`compromisso-gerenciar` explicitamente aos papéis operacionais que devem
lançar compromisso no dia a dia.

## Observações

- O plano estimava ~7 tasks; a decomposição fechou em 6, porque o padrão do
  Plano 28 (Task 28.4) mostrou que permissão + `FormRequest` + controller +
  rotas cabem numa task só quando o recurso não tem a mesma segregação de
  permissões que a entrega de EPI tem.
- Plano 31 (compromissos na roteirização) só começa depois deste plano
  concluído: `RouteService` precisa do model `Compromisso` (30.1) e do
  `CompromissoService` (30.2) prontos para montar e promover paradas.

## Execução (10/08/2026)

Todas as 6 tasks concluídas na worktree `certificado-plano30`, branch
`plano-30-compromissos-agenda`, exatamente na ordem de lotes prevista acima.
Suíte completa: 1755 testes, 12922 asserções, 0 falhas. `npm run build` limpo.

Duas correções nasceram da revisão do orquestrador entre tasks, fora do
escopo literal de qualquer uma:

1. **Task 30.1 tinha um bug de fuso**: `hora_inicio`/`hora_fim` são coluna
   `TIME` pura, mas o model saiu com cast `'datetime'`, reintroduzindo o
   exato bug já corrigido no Plano 1 para `ServiceOrder`. Corrigido antes de
   aceitar a task (removido o cast, mesmo critério de `ServiceOrder`).
2. **Lacuna entre Task 30.4 e Task 30.5**: `formatarCompromisso()` não
   expunha `work_order_id` nem `observacoes`, mas o critério de aceitação da
   própria 30.5 pedia o botão "Promover para OS" desabilitado quando já
   promovido. Corrigido expondo os dois campos em `AgendaService` e
   ajustando a lista de chaves travada em `AgendaTest`; o frontend passou a
   ler `work_order_id` direto da lista em vez do `Set` de sessão que a Task
   30.5 usou como contorno.
3. **`VazamentoEntreEmpresasTest` acusou `Compromisso` sem dado no cenário**
   de vazamento entre dois tenants, só ao rodar a suíte completa (nenhum
   filtro por task pega isso). Corrigido acrescentando um compromisso por
   tenant ao cenário, mesmo critério já usado para `appointmentRequest`.

Nenhuma das três exigiu nova decisão de produto: são correções de
consistência com padrões já estabelecidos no projeto.
