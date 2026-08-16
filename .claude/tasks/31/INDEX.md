# Tasks do Plano 31 - Compromissos na roteirização

> Gerado em: 10/08/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 31.1 | RouteStop aceita compromisso | backend-estrutura | ✅ | baixa |
| 31.2 | RouteService monta, sincroniza e recalcula com compromisso | backend-logica | ✅ | alta |
| 31.3 | Roteiro apresenta e reordena parada de compromisso | backend-endpoint | ✅ | média |
| 31.4 | Frontend: parada de compromisso no roteiro e no mapa | frontend-pagina | ✅ | média |
| 31.5 | Testes de roteiro misto: OS e compromisso | teste | ✅ | média |

## Ordem de execução

```
Lote 1:  31.1
Lote 2:  31.2
Lote 3:  31.3
Lote 4:  31.4  |  31.5  (paralelo)
```

Nenhum lote paralelo antes do 31.3: diferente do Plano 30, aqui cada task
depende do contrato que a anterior fecha (o formato da "parada" definido na
31.2 é o que a 31.3 consome; o formato de resposta que a 31.3 fecha é o que
31.4 e 31.5 consomem).

## Dependências internas

- 31.2 depende de 31.1 (`RouteStop::compromisso()`/`eDeCompromisso()`) e do
  Plano 30 concluído até a Task 30.1 (model `Compromisso` precisa existir)
- 31.3 depende de 31.2, e usa o contrato de reordenação (`"os:N"` /
  `"compromisso:N"`) que a 31.2 implementa em
  `RouteService::reordenarManualmente()` - **as duas tasks precisam
  concordar neste formato; se 31.2 rodar com um formato diferente, ajustar
  31.3 para o real, não duplicar a decisão**
- 31.4 depende de 31.3 (consome `tipo_item`, `compromisso_titulo`,
  `compromisso_tipo` da resposta)
- 31.5 depende de 31.2 e 31.3 (testa os dois)

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `app/Services/Routing/RouteService.php` | 31.2 | Task única |
| `app/Services/Routing/OtimizadorDeRota.php` | 31.2 | Só docblock, mesma task de `RouteService.php` |
| `app/Http/Controllers/RouteController.php` | 31.3 | Task única |
| `app/Http/Requests/ReordenarRotaRequest.php` | 31.3 | Task única |
| `tests/Feature/RouteServiceTest.php`, `tests/Feature/RouteEndpointTest.php` | 31.5 | Task única; 31.1-31.3 só rodam esses testes para confirmar que nada quebrou, não acrescentam caso novo |

## Decisões registradas

- **O "parada" interno de `RouteService` ganha `compromisso_id` ao lado de
  `work_order_id`, ambos nullable, exclusivos entre si.**
  `OtimizadorDeRota` não muda: já é agnóstico ao conteúdo da parada além de
  coordenada/precisão/âncora.
- **Contrato de reordenação muda de `work_order_ids: [int]` para `paradas:
  ["os:N", "compromisso:N"]`.** Mantém o corpo um array plano, mais simples
  de montar no frontend e de validar com uma regra de formato só. É mudança
  incompatível com o contrato antigo (não há período de transição: as duas
  pontas, backend e frontend, mudam juntas dentro deste plano).
- **Compromisso sem `address_id` é "sem coordenada boa".** Mesmo tratamento
  que OS com endereço não geocodificado já recebe: fica de fora do cálculo
  de distância, aparece na lista separada de "paradas sem coordenada" do
  mapa.
- **`tipo_item` é o mesmo nome de discriminador usado no Plano 30**
  (`AgendaService::doPeriodo()`), de propósito: o frontend não aprende dois
  vocabulários diferentes para a mesma distinção.

## Ordem de aplicação em produção

Nenhuma task deste plano toca schema (a migration já foi aplicada fora
deste `create-tasks`, numa sessão anterior). Aplicação em produção é normal,
um deploy só, sem etapas: o que muda é comportamento de Service e endpoint,
não estrutura de dado.

## Observações

- O plano estimava ~6 tasks; a decomposição fechou em 5. `RouteService`
  concentrou o que poderia ter virado duas tasks (montagem e
  reordenação/recálculo) porque as duas partes compartilham o mesmo formato
  de "parada" e a mesma decisão de design - separar aumentaria o risco de
  as duas tasks inventarem contratos diferentes para a mesma coisa.
- Este é o plano mais arriscado dos dois: `RouteService.php` tem 701 linhas
  e nove métodos que hoje só conhecem `WorkOrder`. A Task 31.2 é
  propositalmente a única a tocar este arquivo, para quem executar ter o
  arquivo inteiro como contexto de uma vez, em vez de reconstruir o
  raciocínio a partir de um diff parcial.
