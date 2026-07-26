# Sistema de Certificados — CLAUDE.md

## Visão do Projeto

Sistema de gestão para empresas de controle de pragas: clientes, endereços, cômodos, dispositivos, ordens de serviço, certificados e financeiro.

## Stack

**Backend:** Laravel, MySQL, Inertia.js  
**Frontend:** Vue 3 (`<script setup>`), TailwindCSS, Inertia.js (sem TypeScript)  
**Auth:** Laravel Breeze / Sanctum

## Skills disponíveis

| Skill | Quando usar |
|---|---|
| `commit-push` | Commitar e fazer push das alterações |
| `laravel-arquitetura` | Criar/editar código Laravel (controllers, services, models, migrations) |
| `frontend-design-system` | Criar/editar componentes Vue, páginas, estilos |
| `permissoes-e-multitenancy` | Permissão, papel, escopo por empresa, endpoint que recebe Model por rota |
| `datas-timezone` | Validade, vencimento, agendamento, qualquer formatação de data |
| `create-plans` | Transformar requisitos em planos numerados em `.claude/plans/` |
| `create-tasks` | Decompor um plano em tasks em `.claude/tasks/[N]/` |
| `run-plan` | Executar um plano pendente, despachando tasks a subagentes |
| `discutir` | Analisar e recomendar sem escrever código |

## Planejamento

O roteiro de desenvolvimento vive em `.claude/`:

- `.claude/prd/` — requisitos por domínio, índice em `.claude/prd/README.md`
- `.claude/prd/divida-tecnica.md` — o que está quebrado hoje em produção
- `.claude/plans/INDEX.md` — 27 planos, dependências e ordem de execução

## Estrutura de pastas

```
.claude/
├── prd/                    # Requisitos fragmentados por domínio
├── plans/                  # Planos numerados + INDEX.md
└── skills/
    ├── commit-push/SKILL.md
    ├── create-plans/SKILL.md
    ├── create-tasks/SKILL.md
    ├── datas-timezone/SKILL.md
    ├── discutir/SKILL.md
    ├── frontend-design-system/SKILL.md
    ├── laravel-arquitetura/SKILL.md
    ├── permissoes-e-multitenancy/SKILL.md
    └── run-plan/SKILL.md

app/
├── Http/Controllers/       # Controllers finos (Inertia + JSON)
├── Http/Requests/          # FormRequests de validação
├── Models/                 # Eloquent Models
└── Services/               # Toda lógica de negócio

resources/js/
├── Components/             # Componentes reutilizáveis (Card, Modal, PageHeader...)
├── Layouts/                # AuthenticatedLayout
└── Pages/                  # Páginas Inertia organizadas por módulo
```

## Convenções de código

- Variáveis, métodos e mensagens em **português**
- Nomes de classes, arquivos, tabelas e rotas em inglês (convenção Laravel)
- Toda regra de negócio vai para Services em `App\Services\`
- Toda validação vai para FormRequests em `App\Http\Requests\`
- Todo recurso pertence a um cliente — verificar vínculo no Service antes de operar
- Frontend: Vue 3 `<script setup>` JavaScript puro, sem TypeScript
- Nunca usar `confirm()` nativo para ações destrutivas — usar modal de confirmação
- Nunca `toLocaleDateString` no frontend — usar os utilitários de data do projeto

## Cuidados neste projeto

- **O sistema roda em produção para um cliente real.** Migration que toca tabela
  com dado existente é aplicada em etapas: estrutura, backfill conferido,
  restrição. Nunca as três no mesmo deploy.
- **Multiempresa (a partir do Plano 4):** todo model de domínio carrega
  `company_id` e escopo global; todo unique de domínio é composto com
  `company_id`. Vazamento entre empresas é a falha mais grave possível aqui.
- **Documento emitido** (certificado, OS, contrato, recibo) tem valor perante
  fiscalização. Mudança de layout ou de texto legal exige conferir o PDF gerado.
