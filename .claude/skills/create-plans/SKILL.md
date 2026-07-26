---
name: create-plans
description: Lê o PRD/briefing do Sistema de Certificados e gera planos de desenvolvimento numerados em .claude/plans/, atualizando o INDEX.md. Use quando o usuário pedir para planejar o projeto, criar planos ou transformar requisitos em roteiro de execução.
---
# Skill: Criar Planos a partir do PRD

Transforma um documento de requisitos (PRD/SRS/briefing) em planos de desenvolvimento autocontidos, prontos para virarem tasks (`create-tasks`) e serem executados (`run-plan`).

## Contexto do projeto

<!-- PROJETO-ESPECIFICO: esta é a única seção que varia entre projetos. Ao sincronizar esta skill, preserve este bloco. -->
- **Projeto:** Sistema de Certificados (gestão para empresas de controle de pragas)
- **Fonte de requisitos:** fragmentos por domínio em `.claude/prd/`, com índice em `.claude/prd/README.md`. Dívida técnica mapeada em `.claude/prd/divida-tecnica.md`
- **Stack:** Laravel 11 + MySQL + Inertia; Vue 3 `<script setup>` em JavaScript puro (sem TypeScript) + TailwindCSS; PDF via dompdf
- **Módulos típicos do domínio:** clientes e endereços, cômodos e dispositivos, ordens de serviço, certificados, contratos, orçamentos, financeiro, cadastros técnicos regulatórios
- **Regras específicas:**
  - Idioma do código em português (variáveis, métodos, mensagens); nomes de classes, tabelas e rotas em inglês
  - Toda regra de negócio em `app/Services/`; toda validação em `app/Http/Requests/`
  - Todo recurso pertence a um cliente: verificar o vínculo no Service antes de operar
  - Nunca `confirm()` nativo para ação destrutiva, sempre modal de confirmação
  - **O sistema roda em produção para um cliente real.** Todo plano que toque schema ou dado precisa declarar como é aplicado sem interromper a operação, e como se volta atrás
  - Depois do Plano 4, o sistema é multiempresa: todo model de domínio carrega `company_id` e escopo global

## Regra de ouro: economia de contexto

- Cada plano é **autocontido**: contém apenas o contexto mínimo para ser executado, sem depender da leitura do PRD inteiro.
- **Nunca copie trechos longos do PRD.** Resuma e referencie (arquivo + seção/regra).
- PRD grande (2.000+ linhas ou 5+ módulos): fragmente primeiro (Passo 0).

## Passo 0 - Fragmentar o PRD (se necessário)

1. Crie `.claude/prd/` (se não existir) e extraia cada módulo/domínio para um arquivo próprio, com nomes reais do domínio (ex.: `.claude/prd/[modulo].md`).
2. Cada fragmento contém: regras de negócio do módulo, entidades/models envolvidos, fluxos de usuário e critérios de aceite.
3. A partir daí, consulte apenas os fragmentos; o PRD original vira referência.

## Passo 1 - Ler o contexto

1. Os fragmentos em `.claude/prd/` relevantes ao que foi pedido.
2. `.claude/plans/INDEX.md`: planos já existentes e próximo número livre.
3. `CLAUDE.md`: convenções do projeto.
4. `.claude/rules.md` (se existir): restrições que todo plano deve respeitar.

## Passo 2 - Identificar módulos e dependências

Identifique módulos funcionais, dependências entre eles e a prioridade natural (módulos base antes de dependentes). Organize na ordem lógica:

1. Infraestrutura e setup (migrations, seeders base, configs).
2. Módulos independentes.
3. Módulos dependentes, na ordem das dependências.
4. Integrações e refinamentos.
5. Testes end-to-end e polimento.

## Passo 3 - Gerar os planos

Para cada módulo, crie `.claude/plans/[N].md`:

```markdown
# Plano [N] - [Nome descritivo do módulo]

## Objetivo
[1-2 frases: o que este plano entrega ao final]

## Contexto de Negócio
[Resumo das regras de negócio relevantes - máximo 10-15 linhas]
[Referencie o fragmento: "Detalhes completos em: `.claude/prd/[modulo].md`"]

## Escopo

### Inclui
- [Funcionalidade A]

### Não inclui (fica para outro plano)
- [Funcionalidade X -> ver Plano M]

## Dependências
- **Requer concluído:** Plano [X] - [nome]
- **Bloqueia:** Plano [Y] - [nome]

## Entidades/Models envolvidos
- [Model A] - [breve descrição]

## Entregáveis esperados
- [ ] [Entregável 1]

## Estimativa de tasks
~[N] tasks (backend: ~X, frontend: ~Y, testes: ~Z)

## Skills necessárias
- [skills do projeto relevantes para este plano]
```

### Regras ao gerar planos

- **Granularidade:** um plano = um módulo coerente, completável em **3 a 8 tasks**.
- **Módulo grande** (geraria 15+ tasks): divida em sub-planos por camada, ex.: `Backend (CRUD + regras)`, `Frontend (páginas + componentes)`, `Integrações e testes E2E`.
- **Nunca gere planos vagos** como "Melhorias gerais" ou "Ajustes diversos".
- **Cada plano deve ser executável de forma independente**, respeitadas as dependências declaradas.
- **Plano que altera schema ou dado em produção** declara nos entregáveis: como é aplicado com o sistema no ar, como se confere que nada foi perdido e como se volta atrás.

## Passo 4 - Atualizar o INDEX.md

Após gerar os planos, atualize (ou crie) `.claude/plans/INDEX.md`:

```markdown
# INDEX - Planos do Sistema de Certificados

> Última atualização: [DATA]

## Legenda
- ✅ Concluído
- 🔄 Em andamento
- ⏳ Pendente
- 🔒 Bloqueado (dependência não concluída)

## Planos

| # | Nome | Status | Depende de | Tasks |
|---|------|--------|------------|-------|
| 1 | [Nome do plano] | ⏳ | - | ~4 |

## Ordem de execução recomendada
1 -> 2 -> 3 -> 4/5 (paralelos) -> ...
```

## Passo 5 - Validação final

- [ ] Todo requisito do PRD está coberto por pelo menos um plano? (Órfão: crie um plano ou incorpore em um existente.)
- [ ] O grafo de dependências não tem ciclos?
- [ ] Nenhum plano passa de 8 tasks estimadas?
- [ ] Todo plano que toca dado em produção declara a estratégia de aplicação e de retorno?
- [ ] O INDEX.md reflete todos os planos e a ordem recomendada?

Ao final, informe ao usuário o que foi criado e a ordem recomendada de execução.
