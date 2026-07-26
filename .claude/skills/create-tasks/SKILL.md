---
name: create-tasks
description: Lê um plano em .claude/plans/[N].md e o decompõe em tasks granulares em .claude/tasks/[N]/, dimensionadas para execução isolada por subagente. Use quando o usuário pedir "create-tasks N" ou para criar as tasks de um plano.
---
# Skill: Criar Tasks a partir de um Plano

Transforma um plano de desenvolvimento em tasks granulares, prontas para execução individual pela skill `run-plan`.

## Contexto do projeto

<!-- PROJETO-ESPECIFICO: esta é a única seção que varia entre projetos. Ao sincronizar esta skill, preserve este bloco. -->
- **Stack:** Laravel 11 + MySQL + Inertia + Vue 3 + TailwindCSS
- **Extensões de frontend:** `.vue` com `<script setup>` em **JavaScript puro, sem TypeScript**
- **Autorização:** Spatie Permission a partir do Plano 2 (ver `.claude/skills/permissoes-e-multitenancy/SKILL.md`)
- **Comando de teste:** `php artisan test --filter=NomeDoTeste`
- **Particularidades:**
  - Idioma do código em português; nomes de classes, tabelas e rotas em inglês
  - Controller fino, regra de negócio no Service, validação no FormRequest
  - **Multiempresa (a partir do Plano 4):** toda task que cria model de domínio inclui `company_id` e a trait de escopo global; toda task de endpoint confirma que o recurso pertence à empresa do usuário
  - **Produção ativa:** task de migration em tabela com dado existente declara o passo de backfill e a conferência de contagem
  - Data e fuso: usar os utilitários do projeto, nunca `toLocaleDateString` (ver `.claude/skills/datas-timezone/SKILL.md`)
  - Ação destrutiva na UI usa modal de confirmação, nunca `confirm()` nativo

## Regra crítica: dimensionamento da task

Cada task deve caber confortavelmente em uma execução isolada de subagente, sem estourar contexto. Quebre em tasks menores se qualquer um destes for verdadeiro:

- Cria/modifica **mais de 3 arquivos**.
- Envolve **mais de ~150 linhas de código novo**.
- Mistura **backend + frontend** na mesma task.
- Exige ler **mais de 5 arquivos existentes** para contexto.

## Passo 1 - Ler o plano

1. Receba o número do plano (ex.: `create-tasks 5`).
2. Leia `.claude/plans/[N].md`.
3. Leia `CLAUDE.md` e `.claude/rules.md` (se existir) para relembrar convenções.
4. Leia o fragmento do PRD referenciado pelo plano (ex.: `.claude/prd/[modulo].md`).

## Passo 2 - Decompor em tasks

Decomponha os entregáveis seguindo esta hierarquia de granularidade:

1. **Backend - Estrutura:** migrations e models (1 task por entidade ou grupo pequeno de entidades relacionadas); seeders se necessário.
2. **Backend - Lógica:** Service + regras de negócio (1 task por Service ou grupo de métodos coesos).
3. **Backend - Endpoints:** controller fino + FormRequest + rotas + autorização (1 task por recurso/CRUD ou ação complexa).
4. **Frontend:** 1 task por página; 1 task por componente complexo; integração com o backend pode agrupar com a página se simples.
5. **Testes:** testes do Service (1 task), testes de endpoint (1 task), teste de isolamento entre empresas quando o plano tocar dado de domínio.

### Regras de agrupamento

- **Pode agrupar:** FormRequest + Controller + rota, se o endpoint for simples.
- **Nunca agrupar:** backend + frontend; lógica de domínio (Service) + criação de UI.
- **Sempre separar:** migrations/models em task própria (são a base de tudo).
- **Sempre separar:** migração de dado em produção (backfill) da migration de estrutura, porque são deploys diferentes.

## Passo 3 - Gerar os arquivos de task

Crie `.claude/tasks/[N]/` com um arquivo por task: `[N].1.md`, `[N].2.md`, ...

```markdown
# Task [N].[X] - [Título curto e descritivo]

## Objetivo
[1-2 frases: o que esta task entrega]

## Tipo
[config | backend-estrutura | backend-logica | backend-endpoint | frontend-pagina | frontend-componente | teste]

## Arquivos a criar/modificar
- `caminho/arquivo1` -> [criar | modificar]

## Contexto necessário (ler antes)
- `caminho/arquivo_existente` -> [motivo]

## Skills necessárias
- [skills do projeto relevantes para esta task]

## Especificação

### O que fazer
[Instruções claras e diretas]

### Regras de negócio aplicáveis
- [Regra 1]

### Critérios de aceitação
- [ ] [Critério verificável 1]

## Teste esperado
- Comando: [comando de teste específico]
- Verificação manual: [se aplicável]

## Estimativa
[baixa | média | alta] - ~[N] linhas | ~[N] arquivos
```

## Passo 4 - Criar o INDEX das tasks

Crie `.claude/tasks/[N]/INDEX.md`:

```markdown
# Tasks do Plano [N] - [Nome do Plano]

> Gerado em: [DATA]

## Legenda
- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| [N].1 | [Título] | backend-estrutura | ⏳ | baixa |

## Ordem de execução
[N].1 -> [N].2 -> [N].3 ...

## Dependências internas
- [N].3 depende de [N].1 e [N].2
```

## Passo 5 - Atualizar o INDEX dos planos

Atualize `.claude/plans/INDEX.md` preenchendo a coluna "Tasks" com o número real de tasks geradas.

## Passo 6 - Validação final

- [ ] Nenhuma task viola os limites de dimensionamento?
- [ ] Nenhuma task mistura backend com frontend?
- [ ] Toda task tem "Contexto necessário", "Critérios de aceitação" e comando de teste?
- [ ] A ordem de execução respeita as dependências internas?
- [ ] Task de migration em tabela com dado existente traz backfill e conferência?
- [ ] Todos os entregáveis do plano estão cobertos?

Ao final, informe quantas tasks foram criadas e a ordem de execução.

## Execução em lote

Para gerar tasks de todos os planos pendentes: leia `.claude/plans/INDEX.md` e execute esta skill para cada plano sem pasta em `.claude/tasks/`. Limite-se a **3 planos por conversa** para não degradar a qualidade.
