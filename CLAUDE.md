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

## Estrutura de pastas

```
.claude/
└── skills/
    ├── commit-push/SKILL.md
    ├── laravel-arquitetura/SKILL.md
    └── frontend-design-system/SKILL.md

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
