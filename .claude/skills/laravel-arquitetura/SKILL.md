---
name: laravel-arquitetura
description: Padrões estritos de arquitetura Laravel para o projeto de certificados. Define uso de Controllers, Services, FormRequests e regras de domínio.
---

# Skill: Arquitetura Laravel – Sistema de Certificados

Você está trabalhando em um projeto Laravel com Inertia.js + Vue 3 e regras estritas de arquitetura.

## Regras essenciais

### 1. Controllers

- Devem ser **finos**: orquestrar → chamar Service → retornar resposta Inertia ou JSON.
- Não devem conter lógica de negócio, cálculos, validações complexas ou regras de permissão além de middlewares.
- Para respostas web (páginas): usar `Inertia::render('Pasta/Pagina', [...])`.
- Para respostas de API (fetch/ajax): retornar `response()->json([...])`.

### 2. Services

- Toda regra de negócio fica em Services em `App\Services\`.
- Services recebem Models ou dados primitivos — nunca fazem `Auth::user()` internamente.
- Devem retornar arrays padronizados com `success`, `message`, `data` ou lançar exceções.
- Services devem ser testáveis e independentes do framework.
- Exemplos existentes no projeto: `AddressService`, `RoomService`, `WorkOrderService`, `ClientService`.

### 3. Validação

- Toda validação obrigatoriamente vai para **FormRequests** em `App\Http\Requests`.
- Nunca usar `$request->validate()` diretamente em controllers.
- Exemplos existentes: `AddressRequest`, `RoomRequest`, `WorkOrderRequest`.

### 4. Models (Eloquent)

- Model **nunca** contém regras de negócio.
- Permitido: Accessors, Mutators, Scopes, relacionamentos, `$fillable`, `$casts`.
- Proibido: processos complexos, lógica que mistura domínio com infraestrutura.
- Todo recurso principal (Client, Address, Room, WorkOrder, Device) tem seus relacionamentos definidos via Eloquent.

### 5. Ownership e Segurança

- Todo recurso pertence a um usuário ou cliente — sempre verificar o vínculo no Service antes de operar.
- Cômodos pertencem a Endereços; Endereços pertencem a Clientes.
- Dispositivos e Ordens de Serviço devem ter seus vínculos validados antes de exclusão.

### 6. Idioma

- Variáveis, métodos e mensagens em **português**.
- Nomes de classes, arquivos, tabelas e rotas em inglês (convenção Laravel).
- Mensagens de erro e retorno JSON sempre em português para o usuário.

## Decisão obrigatória (ordem mental)

1. Isso é regra de negócio? → **Service**.
2. É validação de input? → **FormRequest**.
3. É checagem repetitiva de acesso? → **Middleware**.
4. É dado do modelo? → **Accessor/Scope no Model**.

## Estrutura de diretórios relevante

```
app/
├── Http/
│   ├── Controllers/        # Controllers finos (Inertia + JSON)
│   └── Requests/           # FormRequests de validação
├── Models/                 # Eloquent Models
└── Services/               # Toda lógica de negócio
```

---

Use este skill sempre que criar endpoints, services, validações ou organizar estrutura de diretórios no projeto.
