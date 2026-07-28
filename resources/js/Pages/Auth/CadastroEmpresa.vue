<template>
  <div class="min-h-screen flex items-center justify-center bg-gray-50 py-12 px-4 sm:px-6 lg:px-8">
    <div class="max-w-2xl w-full space-y-8">
      <div>
        <div class="mx-auto h-12 w-12 bg-green-600 rounded-full flex items-center justify-center">
          <svg class="h-8 w-8 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2M5 21H3m8-14h2m-2 4h2m-2 4h2M9 7h.01M9 11h.01M9 15h.01"></path>
          </svg>
        </div>
        <h2 class="mt-6 text-center text-3xl font-extrabold text-gray-900">
          Crie a conta da sua empresa
        </h2>
        <p class="mt-2 text-center text-sm text-gray-600">
          {{ diasDeAvaliacao }} dias de avaliação gratuita, sem cartão de crédito.
        </p>
      </div>

      <div class="bg-white shadow rounded-lg p-6 sm:p-8">
        <form class="space-y-6" @submit.prevent="submit">
          <!-- Dados da empresa -->
          <div>
            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">
              Dados da empresa
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                  Nome da empresa *
                </label>
                <input
                  v-model="form.name"
                  type="text"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.name }"
                  placeholder="Razão social ou nome fantasia"
                />
                <p v-if="form.errors.name" class="mt-1 text-sm text-red-600">{{ form.errors.name }}</p>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                  CNPJ *
                </label>
                <input
                  :value="form.cnpj"
                  @input="onCnpjInput"
                  type="text"
                  inputmode="numeric"
                  maxlength="18"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.cnpj }"
                  placeholder="00.000.000/0000-00"
                />
                <p v-if="form.errors.cnpj" class="mt-1 text-sm text-red-600">{{ form.errors.cnpj }}</p>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                  Telefone *
                </label>
                <input
                  :value="form.phone"
                  @input="onPhoneInput"
                  type="text"
                  inputmode="numeric"
                  maxlength="15"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.phone }"
                  placeholder="(00) 00000-0000"
                />
                <p v-if="form.errors.phone" class="mt-1 text-sm text-red-600">{{ form.errors.phone }}</p>
              </div>
            </div>
          </div>

          <!-- Dados do administrador -->
          <div class="border-t border-gray-200 pt-6">
            <h3 class="text-sm font-semibold text-gray-900 uppercase tracking-wide mb-4">
              Seus dados
            </h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
              <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                  Seu nome completo *
                </label>
                <input
                  v-model="form.administrador_nome"
                  type="text"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.administrador_nome }"
                  placeholder="Nome e sobrenome"
                />
                <p v-if="form.errors.administrador_nome" class="mt-1 text-sm text-red-600">{{ form.errors.administrador_nome }}</p>
              </div>

              <div class="sm:col-span-2">
                <label class="block text-sm font-medium text-gray-700 mb-1">
                  Seu e-mail *
                </label>
                <input
                  v-model="form.administrador_email"
                  type="email"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.administrador_email }"
                  placeholder="voce@empresa.com"
                />
                <p v-if="form.errors.administrador_email" class="mt-1 text-sm text-red-600">{{ form.errors.administrador_email }}</p>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                  Senha *
                </label>
                <input
                  v-model="form.administrador_senha"
                  type="password"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.administrador_senha }"
                  placeholder="Mínimo 8 caracteres"
                />
                <p v-if="form.errors.administrador_senha" class="mt-1 text-sm text-red-600">{{ form.errors.administrador_senha }}</p>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                  Confirme a senha *
                </label>
                <input
                  v-model="form.administrador_senha_confirmation"
                  type="password"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.administrador_senha_confirmation }"
                  placeholder="Repita a senha"
                />
                <p v-if="form.errors.administrador_senha_confirmation" class="mt-1 text-sm text-red-600">{{ form.errors.administrador_senha_confirmation }}</p>
              </div>
            </div>
          </div>

          <!-- Aceite de termos -->
          <div class="border-t border-gray-200 pt-6">
            <div class="flex items-start gap-2">
              <input
                id="aceite_termos"
                v-model="form.aceite_termos"
                type="checkbox"
                class="mt-0.5 h-4 w-4 text-green-600 border-gray-300 rounded focus:ring-green-500"
                :class="{ 'border-red-500': form.errors.aceite_termos }"
              />
              <label for="aceite_termos" class="text-sm text-gray-700">
                Li e aceito os termos de uso e a política de privacidade. *
              </label>
            </div>
            <p v-if="form.errors.aceite_termos" class="mt-1 text-sm text-red-600">{{ form.errors.aceite_termos }}</p>
          </div>

          <button type="submit" :disabled="form.processing" class="btn-primary w-full py-2.5 disabled:opacity-50 disabled:cursor-not-allowed">
            <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ form.processing ? 'Criando conta...' : 'Criar conta' }}
          </button>
        </form>
      </div>

      <p class="text-center text-sm text-gray-600">
        Já tem uma conta?
        <Link :href="route('login')" class="font-medium text-green-600 hover:text-green-500">
          Entrar
        </Link>
      </p>
    </div>
  </div>
</template>

<script setup>
import { Link, useForm } from '@inertiajs/vue3';
import { useMasks } from '@/Composables/useMasks';

defineProps({
  diasDeAvaliacao: {
    type: Number,
    default: 14,
  },
});

const { cpfCnpjMask, phoneMask } = useMasks();

const form = useForm({
  name: '',
  cnpj: '',
  phone: '',
  administrador_nome: '',
  administrador_email: '',
  administrador_senha: '',
  administrador_senha_confirmation: '',
  aceite_termos: false,
});

const onCnpjInput = (event) => {
  form.cnpj = cpfCnpjMask(event.target.value);
};

const onPhoneInput = (event) => {
  form.phone = phoneMask(event.target.value);
};

const submit = () => {
  form.post(route('cadastro.store'), {
    onError: () => {
      form.reset('administrador_senha', 'administrador_senha_confirmation');
    },
  });
};
</script>
