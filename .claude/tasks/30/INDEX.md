# Tasks do Plano 30 - Compromissos avulsos: cadastro e agenda

> Gerado em: 10/08/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 30.1 | Model Compromisso, factory e registro multiempresa | backend-estrutura | ⏳ | baixa |
| 30.2 | CompromissoService: ciclo de vida e promoção para OS | backend-logica | ⏳ | média |
| 30.3 | Endpoints, permissões e rotas do compromisso | backend-endpoint | ⏳ | alta |
| 30.4 | Agenda mescla ordens de serviço e compromissos | backend-logica | ⏳ | média |
| 30.5 | Frontend: compromisso na Agenda | frontend-pagina | ⏳ | alta |
| 30.6 | Testes de endpoint e da mescla na Agenda | teste | ⏳ | média |

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
