# Tasks do Plano 9 - Geração automática de visitas do contrato

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 9.1 | Migration: periodicidade com unidade e vínculo da OS | backend-estrutura | ✅ | baixa |
| 9.2 | Backfill da periodicidade dos contratos existentes | backend-logica | ✅ | média |
| 9.3 | Cálculo das datas de visita do contrato | backend-logica | ✅ | média |
| 9.4 | Geração idempotente das visitas como OS agendada | backend-logica | ✅ | média |
| 9.5 | Reprogramação e cancelamento em cascata | backend-logica | ✅ | alta |
| 9.6 | Rotina que mantém a janela de visitas preenchida | backend-logica | ✅ | média |
| 9.7 | Endpoints das visitas e do painel de pendências | backend-endpoint | ✅ | média |
| 9.8 | Telas de visitas do contrato e painel de pendências | frontend-pagina | ✅ | alta |
| 9.9 | Testes de geração, idempotência e reprogramação | teste | ✅ | alta |

## Ordem de execução

```
Lote 1:             9.1                  (deploy 1: colunas novas, sem efeito)
Lote 2:             9.2                  (deploy 2: backfill conferido)
Lote 3:             9.3
Lote 4:             9.4
Lote 5 (paralelo):  9.5  |  9.6
Lote 6:             9.7
Lote 7:             9.8
Lote 8:             9.9
```

## Dependências internas

- 9.2 depende de 9.1 (colunas novas) e do levantamento feito nela
- 9.3 depende de 9.2 (periodicidade preenchida)
- 9.4 depende de 9.3
- 9.5 e 9.6 dependem de 9.4
- 9.7 depende de 9.4 e 9.5
- 9.8 depende de 9.7
- 9.9 depende de 9.3, 9.4, 9.5 e 9.6

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/web.php` | 9.7 | Única do plano |
| `app/Support/RotinasAgendadas.php` | 9.6 | Única do plano |
| `app/Services/ContractService.php` | 9.5 | Única do plano |
| `app/Models/Contract.php` | 9.1 | Única do plano |

## Achado da análise do schema

`visit_frequency` **não é integer**. A migration `2025_11_03_120800_update_contracts_table_for_new_requirements.php` converteu a coluna de `integer` para `string(50)` em produção. O plano fala em "frequência em meses", o que era verdade no schema original e deixou de ser.

Consequências, já refletidas nas tasks:

- A Task 9.1 exige levantar os valores distintos reais **antes** de escrever a migration.
- A Task 9.2 mapeia texto para valor e unidade, e o que não for reconhecido vira pendência em vez de chute.
- A coluna antiga não é removida neste plano: ela é a rede de segurança do backfill.

## Dívida aberta pela Task 9.5, a fechar antes do Deploy 3

O formulário de contrato ainda grava apenas `visit_frequency` (texto). Nem `Contracts/Edit.vue` nem o `update` do controller enviam `visit_frequency_valor` e `visit_frequency_unidade`, que são as colunas que o cálculo lê. Enquanto isso não mudar, trocar "Mensal" por "Semanal" na tela dispara a reprogramação sem alterar calendário nenhum, e a mensagem de sucesso pode falar em visitas criadas sem que a frequência escolhida tenha valido.

O mapa texto -> valor/unidade existe hoje só dentro de `BackfillPeriodicidade::MAPA_LITERAL`. Fechar isso direito significa extrair esse mapa para uma classe compartilhada e usá-la também na gravação do formulário, em vez de duplicar.

## Pendência de integração com o Plano 4 (fechada)

**Fechada em 26/07/2026.** `contratos:gerar-visitas` (Task 9.6) rodava sobre **todos** os contratos vigentes do banco, sem distinção de empresa, porque a trait de tenant explícito fora de requisição HTTP (Plano 4, Task 4.9) ainda não existia quando ela foi escrita.

Com a trait `OperaPorTenant` disponível, o comando passou a usá-la: `use OperaPorTenant;` no corpo da classe, `handle()` → `gerar()` seguindo o mesmo cabeçalho de sempre (dia de referência, janela, modo) e então delegando o laço por empresa a `$this->paraCadaTenant(fn (): int => $this->gerarDaEmpresa($meses, $simulacao))`. `gerarDaEmpresa()` concentra o que antes rodava sem escopo: a geração (real ou simulada), a contagem de contratos sem periodicidade e a impressão do relatório, tudo dentro do tenant da volta corrente do laço. `GeracaoDeVisitasService::gerarDoPeriodo()` monta e executa o `Contract::query()` dentro dele mesmo, então chamado de dentro do callback ele sai escopado assim que a trait `BelongsToCompany` entrar em vigor nos models.

O `Cache::lock` de `handle()` continua por fora do laço de empresas: é a trava do comando inteiro contra execução concorrente (scheduler x terminal), e uma trava por empresa não a substituiria, porque o risco que ela cobre é duas execuções do mesmo comando ao mesmo tempo, não duas empresas ao mesmo tempo.

O código de saída passou a combinar dois sinais: o de `paraCadaTenant()` (que vira `FAILURE` quando uma empresa inteira estoura exceção) e o de contrato individual com erro dentro do relatório (que não lança, então `paraCadaTenant()` sozinho não o vê). A propriedade `$algumContratoFalhou`, setada dentro de `gerarDaEmpresa()`, carrega o segundo sinal até `gerar()` combinar os dois: `$codigo === Command::SUCCESS && $this->algumContratoFalhou ? Command::FAILURE : $codigo`.

`--company=ID` e `--todas` já aparecem em `php artisan contratos:gerar-visitas --help`. Sem a opção, o comportamento é o mesmo de antes (percorre todas as empresas cadastradas), o que mantém a rotina agendada em `RotinasAgendadas` funcionando sem precisar mexer na linha do cron.

## Decisões registradas

- **Janela de 3 meses à frente.** Gerar o contrato inteiro de uma vez enche a agenda de OS que ainda vão mudar de data; janela curta demais impede a equipe de enxergar o mês seguinte.
- **Idempotência por `[contract_id, scheduled_date]`, não por `visita_numero`.** A reprogramação renumera mantendo datas, e um unique em `visita_numero` travaria isso.
- **Visita gerada nasce sem técnico.** Atribuir automaticamente exigiria conhecer carga e agenda, que é o Plano 10.
- **Visita executada nunca é tocada.** Nem movida, nem cancelada, nem renumerada. É histórico, e é regra explícita do PRD.
- **`addMonthsNoOverflow` é obrigatório.** Contrato iniciado em 31 de janeiro com visita mensal precisa cair em 28 de fevereiro, não em 3 de março.

## Ordem de aplicação em produção

1. **Deploy 1** (Task 9.1): colunas novas, nullable, nenhum código as lê. Sem efeito observável.
2. **Deploy 2** (Task 9.2): rodar `contratos:backfill-periodicidade --dry-run`, conferir cada valor distinto a olho, rodar sem a flag, guardar o log. Resolver as pendências à mão na tela antes de seguir.
3. **Deploy 3** (Tasks 9.3 a 9.7): rodar `contratos:gerar-visitas --dry-run` **antes** de habilitar a rotina agendada, e conferir a contagem de OS que seriam criadas. Este é o momento de maior risco do plano: a rotina cria OS reais na agenda do cliente atual.
4. **Deploy 4** (Task 9.8): frontend.

## Observações

- O plano estimava ~7 tasks. A decomposição chegou a 9 porque o backfill da periodicidade precisa de deploy próprio (a coluna é string livre em produção) e porque cálculo, geração e manutenção são responsabilidades distintas.
- Este é o plano de melhor retorno por esforço do roteiro: o dado já está no banco e não é usado.
- Contrato periódico sem visita gerada no período é pendência de conformidade com a RDC 622/2022. O painel da Task 9.7 é o que impede isso de passar em silêncio.
