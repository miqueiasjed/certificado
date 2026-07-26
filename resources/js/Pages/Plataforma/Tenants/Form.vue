<template>
  <Card>
    <form class="space-y-6" @submit.prevent="submit">
      <!-- Dados da empresa -->
      <div>
        <h3 class="text-lg font-medium text-gray-900 mb-4">Dados da empresa</h3>
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
          <div>
            <label for="name" class="block text-sm font-medium text-gray-700 mb-1">Nome / Razão social *</label>
            <input
              id="name"
              v-model="form.name"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.name }"
              placeholder="Nome do tenant"
            />
            <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
          </div>

          <div>
            <label for="cnpj" class="block text-sm font-medium text-gray-700 mb-1">CNPJ</label>
            <input
              id="cnpj"
              v-model="form.cnpj"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.cnpj }"
              placeholder="00.000.000/0000-00"
            />
            <p v-if="form.errors.cnpj" class="mt-1 text-sm text-red-600">{{ form.errors.cnpj }}</p>
          </div>

          <div>
            <label for="email" class="block text-sm font-medium text-gray-700 mb-1">E-mail</label>
            <input
              id="email"
              v-model="form.email"
              type="email"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.email }"
              placeholder="contato@empresa.com"
            />
            <p v-if="form.errors.email" class="mt-1 text-sm text-red-600">{{ form.errors.email }}</p>
          </div>

          <div>
            <label for="phone" class="block text-sm font-medium text-gray-700 mb-1">Telefone</label>
            <input
              id="phone"
              v-model="form.phone"
              type="tel"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.phone }"
              placeholder="(11) 99999-9999"
            />
            <p v-if="form.errors.phone" class="mt-1 text-sm text-red-600">{{ form.errors.phone }}</p>
          </div>
        </div>
      </div>

      <!-- Endereço -->
      <div>
        <h3 class="text-lg font-medium text-gray-900 mb-4">Endereço</h3>
        <div class="grid grid-cols-1 gap-6 md:grid-cols-6">
          <div class="md:col-span-3">
            <label for="street" class="block text-sm font-medium text-gray-700 mb-1">Rua</label>
            <input
              id="street"
              v-model="form.street"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.street }"
            />
            <p v-if="form.errors.street" class="mt-1 text-sm text-red-600">{{ form.errors.street }}</p>
          </div>

          <div class="md:col-span-1">
            <label for="number" class="block text-sm font-medium text-gray-700 mb-1">Número</label>
            <input
              id="number"
              v-model="form.number"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.number }"
            />
            <p v-if="form.errors.number" class="mt-1 text-sm text-red-600">{{ form.errors.number }}</p>
          </div>

          <div class="md:col-span-2">
            <label for="complement" class="block text-sm font-medium text-gray-700 mb-1">Complemento</label>
            <input
              id="complement"
              v-model="form.complement"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.complement }"
            />
            <p v-if="form.errors.complement" class="mt-1 text-sm text-red-600">{{ form.errors.complement }}</p>
          </div>

          <div class="md:col-span-2">
            <label for="district" class="block text-sm font-medium text-gray-700 mb-1">Bairro</label>
            <input
              id="district"
              v-model="form.district"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.district }"
            />
            <p v-if="form.errors.district" class="mt-1 text-sm text-red-600">{{ form.errors.district }}</p>
          </div>

          <div class="md:col-span-2">
            <label for="city" class="block text-sm font-medium text-gray-700 mb-1">Cidade</label>
            <input
              id="city"
              v-model="form.city"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.city }"
            />
            <p v-if="form.errors.city" class="mt-1 text-sm text-red-600">{{ form.errors.city }}</p>
          </div>

          <div class="md:col-span-1">
            <label for="state" class="block text-sm font-medium text-gray-700 mb-1">UF</label>
            <input
              id="state"
              v-model="form.state"
              type="text"
              maxlength="2"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm uppercase focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.state }"
            />
            <p v-if="form.errors.state" class="mt-1 text-sm text-red-600">{{ form.errors.state }}</p>
          </div>

          <div class="md:col-span-1">
            <label for="zip" class="block text-sm font-medium text-gray-700 mb-1">CEP</label>
            <input
              id="zip"
              v-model="form.zip"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.zip }"
            />
            <p v-if="form.errors.zip" class="mt-1 text-sm text-red-600">{{ form.errors.zip }}</p>
          </div>
        </div>
      </div>

      <!-- Plano e avaliação -->
      <div>
        <h3 class="text-lg font-medium text-gray-900 mb-4">Plano e avaliação</h3>
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
          <div>
            <label for="plan_id" class="block text-sm font-medium text-gray-700 mb-1">Plano</label>
            <select
              id="plan_id"
              v-model="form.plan_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.plan_id }"
            >
              <option value="">Sem plano</option>
              <option v-for="plano in planos" :key="plano.id" :value="plano.id">
                {{ plano.nome }}
              </option>
            </select>
            <p v-if="form.errors.plan_id" class="mt-1 text-sm text-red-600">{{ form.errors.plan_id }}</p>
          </div>

          <div>
            <label for="trial_ends_at" class="block text-sm font-medium text-gray-700 mb-1">
              Fim do período de avaliação
            </label>
            <input
              id="trial_ends_at"
              v-model="form.trial_ends_at"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.trial_ends_at }"
            />
            <p class="mt-1 text-xs text-gray-500">
              Deixe em branco para o tenant nascer ativo, sem período de avaliação.
            </p>
            <p v-if="form.errors.trial_ends_at" class="mt-1 text-sm text-red-600">{{ form.errors.trial_ends_at }}</p>
          </div>
        </div>
      </div>

      <!-- Administrador: só existe na criação -->
      <div v-if="criando">
        <h3 class="text-lg font-medium text-gray-900 mb-4">Administrador do tenant</h3>
        <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
          <div>
            <label for="administrador_nome" class="block text-sm font-medium text-gray-700 mb-1">Nome *</label>
            <input
              id="administrador_nome"
              v-model="form.administrador_nome"
              type="text"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.administrador_nome }"
              placeholder="Nome do administrador"
            />
            <p v-if="form.errors.administrador_nome" class="mt-1 text-sm text-red-600">
              {{ form.errors.administrador_nome }}
            </p>
          </div>

          <div>
            <label for="administrador_email" class="block text-sm font-medium text-gray-700 mb-1">E-mail *</label>
            <input
              id="administrador_email"
              v-model="form.administrador_email"
              type="email"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.administrador_email }"
              placeholder="admin@empresa.com"
            />
            <p v-if="form.errors.administrador_email" class="mt-1 text-sm text-red-600">
              {{ form.errors.administrador_email }}
            </p>
          </div>
        </div>
        <p class="mt-2 text-xs text-gray-500">
          O administrador nasce com uma senha aleatória e entra pela recuperação de senha; não há e-mail de boas-vindas nesta etapa.
        </p>
      </div>

      <!-- Ações -->
      <div class="flex justify-end gap-4 pt-4 border-t border-gray-200">
        <Link :href="route('plataforma.tenants.index')" class="btn-secondary">
          Cancelar
        </Link>
        <button type="submit" class="btn-primary" :disabled="form.processing">
          <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          {{ form.processing ? 'Salvando...' : (criando ? 'Criar tenant' : 'Salvar alterações') }}
        </button>
      </div>
    </form>
  </Card>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import Card from '@/Components/Card.vue';

const props = defineProps({
  planos: {
    type: Array,
    default: () => [],
  },
  tenant: {
    type: Object,
    default: null,
  },
  criando: {
    type: Boolean,
    default: false,
  },
});

// Campo `date` puro: os primeiros 10 caracteres do ISO que o Laravel serializa
// (sempre "yyyy-MM-dd..." nesse cast) já são o valor esperado pelo
// <input type="date">. Sem instanciar `Date`, que interpretaria a meia-noite
// UTC do campo e devolveria o dia anterior em fuso negativo.
const paraInputDate = (valor) => (valor ? valor.slice(0, 10) : '');

const camposDoAdministrador = props.criando
  ? { administrador_nome: '', administrador_email: '' }
  : {};

const form = useForm({
  name: props.tenant?.name || '',
  cnpj: props.tenant?.cnpj || '',
  email: props.tenant?.email || '',
  phone: props.tenant?.phone || '',
  street: props.tenant?.street || '',
  number: props.tenant?.number || '',
  complement: props.tenant?.complement || '',
  district: props.tenant?.district || '',
  city: props.tenant?.city || '',
  state: props.tenant?.state || '',
  zip: props.tenant?.zip || '',
  plan_id: props.tenant?.plan_id || '',
  trial_ends_at: paraInputDate(props.tenant?.trial_ends_at),
  ...camposDoAdministrador,
});

const submit = () => {
  if (props.criando) {
    form.post(route('plataforma.tenants.store'));
  } else {
    form.put(route('plataforma.tenants.update', props.tenant.id));
  }
};
</script>
