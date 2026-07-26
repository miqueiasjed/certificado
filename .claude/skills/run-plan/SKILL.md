---
name: run-plan
description: Executa exatamente UM plano pendente do Sistema de Certificados, retomando handoff se existir, despachando tasks a subagentes com as skills obrigatórias e atualizando status e logs após os testes. Use quando o usuário pedir "run-plan" ou para executar o próximo plano.
---
# Skill: Run Plan

Executa exatamente **UM** plano por vez, do início ao commit.

## Contexto do projeto

<!-- PROJETO-ESPECIFICO: esta é a única seção que varia entre projetos. Ao sincronizar esta skill, preserve este bloco. -->

### Skills obrigatórias por tipo de task (ler ANTES de codar)

| Tipo de task | Skill | Arquivo |
|---|---|---|
| Código Laravel (controller, service, model, migration, rota) | laravel-arquitetura | `.claude/skills/laravel-arquitetura/SKILL.md` |
| Componente ou página Vue/frontend | frontend-design-system | `.claude/skills/frontend-design-system/SKILL.md` |
| Manipulação de data, validade ou vencimento | datas-timezone | `.claude/skills/datas-timezone/SKILL.md` |
| Permissão, papel ou escopo por empresa | permissoes-e-multitenancy | `.claude/skills/permissoes-e-multitenancy/SKILL.md` |

### Pontos críticos deste projeto

- **O sistema roda em produção para um cliente real, e ele é a receita atual.** Migration que toca tabela com dado existente é sempre aplicada em etapas: estrutura, backfill conferido, restrição. Nunca as três no mesmo deploy.
- **Isolamento entre empresas** (a partir do Plano 4): vazamento de dado de um tenant para outro é a falha mais grave possível neste sistema. Todo model de domínio carrega `company_id` e escopo global.
- **Financeiro**: `financial_entries`, `daily_cash_balances`, `payment_details` e o fluxo de caixa. Erro aqui aparece como dinheiro errado no painel do cliente.
- **Documento emitido** (certificado, OS, contrato, recibo): tem valor perante fiscalização e cliente. Mudança em layout ou em texto legal precisa de conferência visual no PDF gerado.
- **Data e fuso**: validade de certificado e vencimento de contrato erram por um dia quando o fuso é ignorado.
- Services em `app/Services/`; antes de criar um novo, verifique se já existe um adequado.
- Frontend em Vue 3 `<script setup>` com JavaScript puro. Não introduza TypeScript.

## Passo 1 - Checagem de handoff

1. **Sempre** comece lendo `.claude/handoff.md` (se existir).
2. Se houver um plano em andamento registrado (status diferente de "Concluído"/vazio), **retome exatamente dali**; não escolha plano novo.

## Passo 2 - Escolher o plano

1. Leia `.claude/plans/INDEX.md` (e `.claude/rules.md` se existir) e escolha **apenas um** plano pendente cujas dependências estejam concluídas.
2. Estude `.claude/plans/[N].md` e as tasks em `.claude/tasks/[N]/`. Se as tasks não existirem, gere-as primeiro com a skill `create-tasks`.
3. Consulte `.claude/progress.txt` **somente** se topar com um bug recorrente e precisar ver como problemas parecidos foram resolvidos. Não leia por padrão; poupa contexto.

## Passo 3 - Execução com subagentes

O orquestrador (esta sessão) só coordena: lê o plano, agrupa tasks, despacha subagentes e revisa o retorno. **Não implemente tasks pesadas direto na sessão principal** quando der para delegar.

- Tasks **independentes** entre si podem ser despachadas **em paralelo**; tasks dependentes rodam em sequência.
- O subagente nasce "limpo": ele não conhece a arquitetura nem as regras do projeto. **Sempre inclua no prompt o caminho da(s) skill(s) obrigatória(s)** mapeada(s) no Contexto do projeto e o arquivo da task.

### Política de modelo por tipo de task (custo x qualidade)

| Tipo de task | Modelo do subagente | Por quê |
|---|---|---|
| `config` (scaffolding, .env, configs, comandos artisan) | Haiku | Mecânico, baixo risco |
| `backend-*`, `frontend-*`, `teste` | Sonnet | Padrão claro, melhor relação qualidade/custo |
| Crítico (pontos críticos acima, ou o núcleo do plano) | Opus ou o modelo da sessão | Erro custa caro; vale o gasto |

Migração de dado em produção e escopo por empresa entram sempre na faixa crítica. Na dúvida, use Sonnet.

### Como despachar

- Via ferramenta Agent: `Agent(subagent_type: "general-purpose", model: "haiku"|"sonnet"|"opus", prompt: "...")`.
- Via CLI: `claude -p --model <modelo> --permission-mode bypassPermissions "Leia a skill obrigatória em .claude/skills/[skill]/SKILL.md e a especificação em .claude/tasks/[N]/[task].md. Implemente aplicando estritamente os padrões da skill. Ao final, liste os arquivos alterados e o resultado dos testes."`

## Passo 4 - Revisão do orquestrador (após cada lote)

1. Confira o diff produzido pelo subagente contra as regras da skill correspondente (controller fino, FormRequest, lógica no Service).
2. Se a task tocou model de domínio, confirme que a trait de escopo por empresa está aplicada e que existe teste de isolamento.
3. Rode **somente os testes relacionados** às tasks do lote e garanta que passem.
4. Se a task alterou documento emitido, gere o PDF e confira visualmente antes de seguir.
5. Marque as tasks concluídas no `.claude/tasks/[N]/INDEX.md` antes de despachar o próximo lote.

## Passo 5 - Encerramento do plano

Quando todas as tasks do plano estiverem concluídas e os testes passarem:

1. Registre em `.claude/progress.txt` qualquer gotcha, problema contornado ou aprendizado do plano.
2. Atualize `.claude/plans/INDEX.md` marcando o plano como concluído.
3. Use a skill `commit-push` para enviar o trabalho ao repositório.

## Pausa segura (handoff)

Se a sessão estiver muito longa (contexto pesado, muitos ciclos de teste) ou o usuário pedir para pausar:

1. Sobrescreva `.claude/handoff.md`:
   ```md
   # Handoff - Último estado
   Plano: [N]
   Task: [M]
   Status: Em andamento (XX%)
   O que foi feito: [lista]
   O que falta: [lista]
   Arquivos modificados: [lista]
   Próxima ação: [o que fazer ao retomar]
   ```
2. Commite o que vale salvar com prefixo `wip:` (ex.: `wip: handoff do plano X`).
3. Avise o usuário que pode retomar em uma nova sessão digitando `run-plan`, que engatará o handoff. Ao concluir o plano depois, limpe o handoff (status "Concluído").
