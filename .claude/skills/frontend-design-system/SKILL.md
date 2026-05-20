---
name: frontend-design-system
description: Padrões de design e componentes do frontend do Sistema de Certificados. Vue 3 + Inertia + TailwindCSS com tema verde.
---

# Skill: Design System – Sistema de Certificados

Você está trabalhando no frontend do Sistema de Certificados com Vue 3, Inertia.js e TailwindCSS.

**Regra geral:** sempre reuse os componentes e padrões existentes antes de criar algo novo.

---

## 1. Stack e Linguagem

- **Vue 3** com `<script setup>` (sem TypeScript — JavaScript puro).
- **Inertia.js** para navegação: usar `<Link>`, `useForm`, `router` de `@inertiajs/vue3`.
- **TailwindCSS** para estilos — sem CSS inline, sem `<style>` a menos que necessário.
- Variáveis, métodos e textos em **português**.

---

## 2. Layout Base

Toda página usa `AuthenticatedLayout` como wrapper:

```vue
<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Título" description="Subtítulo">
        <template #actions>
          <!-- botões de ação -->
        </template>
      </PageHeader>
    </template>

    <div class="max-w-6xl mx-auto space-y-6">
      <!-- conteúdo -->
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
</script>
```

---

## 3. Componentes disponíveis

Importar sempre de `@/Components/`:

| Componente | Uso |
|---|---|
| `Card` | Container de seção com borda e sombra |
| `PageHeader` | Cabeçalho de página com título, descrição e slot `#actions` |
| `Modal` | Modal base com slots `#icon`, `#title`, `#content`, `#actions` |
| `Pagination` | Paginação padrão (recebe `links` do Laravel) |
| `StatCard` | Card de estatística com valor e label |
| `Alert` | Alertas de sucesso/erro |

### Card

```vue
<Card>
  <div class="px-6 py-4 border-b border-gray-200">
    <h3 class="text-lg font-medium text-gray-900">Título da Seção</h3>
  </div>
  <div class="p-6">
    <!-- conteúdo -->
  </div>
</Card>
```

### Modal

```vue
<Modal :show="showModal" @close="showModal = false">
  <template #icon>
    <svg class="h-6 w-6 text-green-600" ...></svg>
  </template>
  <template #title>Título do Modal</template>
  <template #content>
    <!-- formulário ou conteúdo -->
  </template>
  <template #actions>
    <button @click="showModal = false" class="btn-secondary">Cancelar</button>
    <button @click="submit" class="btn-primary">Salvar</button>
  </template>
</Modal>
```

---

## 4. Paleta de Cores (Tema Verde)

### 4.1. Cores Principais

- **Primary:** `#059669` → `bg-green-600`, `text-green-600`, `border-green-600`
- **Primary Hover:** `#047857` → `hover:bg-green-700`
- **Light:** `#10b981` → `bg-green-500`, `text-green-500`

Tokens customizados no Tailwind (usar quando disponível):
- `bg-primary-green` → `#059669`
- `bg-light-green` → `#10b981`
- `bg-dark-green` → `#047857`

### 4.2. Cores de Status (badges)

| Status | Background | Texto |
|---|---|---|
| Ativo / Sucesso | `bg-green-100` | `text-green-800` |
| Inativo / Erro | `bg-red-100` | `text-red-800` |
| Pendente / Aviso | `bg-yellow-100` | `text-yellow-800` |
| Info / Em progresso | `bg-blue-100` | `text-blue-800` |
| Neutro | `bg-gray-100` | `text-gray-800` |

### 4.3. Regras de cor

- Fundo de página: `bg-gray-50` ou padrão do layout.
- Cards/Superfícies: `bg-white` com `border border-gray-200` e `rounded-lg`.
- Texto primário: `text-gray-900`.
- Texto secundário/label: `text-gray-500` ou `text-gray-600`.
- **Nunca** usar gradientes genéricos ou cores hardcoded sem motivo claro.

---

## 5. Botões

Usar sempre as classes utilitárias definidas no CSS global:

```html
<!-- Ação principal (verde) -->
<button class="btn-primary">Salvar</button>

<!-- Ação secundária (cinza) -->
<button class="btn-secondary">Cancelar</button>

<!-- Ação de perigo (vermelho) -->
<button class="btn-danger">Excluir</button>

<!-- Secundário pequeno -->
<button class="btn-secondary-sm">Ação</button>
```

Para links que parecem botões, usar `<Link>` do Inertia com as mesmas classes.

---

## 6. Formulários

Padrão de campo de formulário:

```html
<div>
  <label class="block text-sm font-medium text-gray-700 mb-1">
    Nome do Campo *
  </label>
  <input
    v-model="form.campo"
    type="text"
    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
    :class="{ 'border-red-500': form.errors.campo }"
  />
  <p v-if="form.errors.campo" class="mt-1 text-sm text-red-600">
    {{ form.errors.campo }}
  </p>
</div>
```

Para `select` e `textarea`, mesmas classes de input.

**Formulários Inertia** (preferido para submit de páginas):
```js
const form = useForm({ campo: '', ... });
form.post('/rota', { preserveScroll: true });
form.put(`/rota/${id}`);
```

**fetch() direto** (para modais/ações sem redirecionamento):
```js
const response = await fetch('/rota', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
    'Accept': 'application/json',
  },
  body: JSON.stringify(dados),
});
```

---

## 7. Mensagens Flash

Sempre renderizar no topo do conteúdo, logo após o container principal:

```vue
<div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4">
  <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
</div>
<div v-if="$page.props.flash.error" class="bg-red-50 border border-red-200 rounded-md p-4">
  <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
</div>
```

---

## 8. Padrão de Listagem

```vue
<!-- Tabela padrão dentro de Card -->
<Card padding="none">
  <div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-200">
      <thead class="bg-gray-50">
        <tr>
          <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
            Coluna
          </th>
        </tr>
      </thead>
      <tbody class="bg-white divide-y divide-gray-200">
        <tr v-for="item in items" :key="item.id" class="hover:bg-gray-50">
          <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
            {{ item.campo }}
          </td>
        </tr>
      </tbody>
    </table>
  </div>
</Card>
```

---

## 9. Modais de Confirmação de Exclusão

Padrão para confirmar exclusão destrutiva (não usar `confirm()` nativo):

```vue
<!-- Modal de confirmação -->
<div v-if="showDeleteModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4">
  <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full mx-4">
    <div class="p-6">
      <div class="flex items-center gap-4 mb-4">
        <div class="flex-shrink-0 w-12 h-12 rounded-full bg-red-100 flex items-center justify-center">
          <svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
          </svg>
        </div>
        <div>
          <h3 class="text-lg font-semibold text-gray-900">Excluir Item</h3>
          <p class="text-sm text-gray-500">Esta ação não pode ser desfeita.</p>
        </div>
      </div>
      <p class="text-sm text-gray-700 mb-6">
        Tem certeza que deseja excluir <strong>"{{ itemToDelete?.name }}"</strong>?
      </p>
      <div class="flex justify-end gap-3">
        <button @click="cancelDelete" :disabled="isDeleting" class="btn-secondary">Cancelar</button>
        <button @click="executeDelete" :disabled="isDeleting" class="btn-danger">
          <span v-if="isDeleting">Excluindo...</span>
          <span v-else>Sim, excluir</span>
        </button>
      </div>
    </div>
  </div>
</div>
```

---

Use este skill sempre que criar ou editar páginas, componentes ou modais no projeto.
