<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Editar Endereço" description="Editar informações do endereço">
        <template #actions>
          <button @click="goBack" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </button>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-4xl mx-auto">
      <Card>
        <form @submit.prevent="submit">
          <div class="p-6 space-y-6">
            <!-- Seleção do Cliente -->
            <div>
              <label for="client_id" class="block text-sm font-medium text-gray-700 mb-2">
                Cliente <span class="text-red-500">*</span>
              </label>
              <select
                id="client_id"
                v-model="form.client_id"
                :class="[
                  'w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent',
                  form.errors.client_id ? 'border-red-500' : 'border-gray-300'
                ]"
                required
              >
                <option value="">Selecione um cliente</option>
                <option v-for="client in clients" :key="client.id" :value="client.id">
                  {{ client.name }} - {{ client.cnpj || client.cpf }}
                </option>
              </select>
              <p v-if="form.errors.client_id" class="mt-1 text-sm text-red-600">
                {{ form.errors.client_id }}
              </p>
            </div>

            <!-- Apelido do Endereço -->
            <div>
              <label for="nickname" class="block text-sm font-medium text-gray-700 mb-2">
                Apelido <span class="text-red-500">*</span>
              </label>
              <input
                type="text"
                id="nickname"
                v-model="form.nickname"
                :class="[
                  'w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent',
                  form.errors.nickname ? 'border-red-500' : 'border-gray-300'
                ]"
                placeholder="Ex: Matriz, Casa, Filial"
                required
              />
              <p v-if="form.errors.nickname" class="mt-1 text-sm text-red-600">
                {{ form.errors.nickname }}
              </p>
            </div>

            <!-- CEP e Busca Automática -->
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div class="md:col-span-1">
                <label for="zip" class="block text-sm font-medium text-gray-700 mb-2">
                  CEP <span class="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  id="zip"
                  v-model="form.zip"
                  @blur="searchCep"
                  :class="[
                    'w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent',
                    form.errors.zip ? 'border-red-500' : 'border-gray-300'
                  ]"
                  placeholder="00000-000"
                  maxlength="9"
                  required
                />
                <p v-if="form.errors.zip" class="mt-1 text-sm text-red-600">
                  {{ form.errors.zip }}
                </p>
              </div>
              <div class="md:col-span-2 flex items-end">
                <button
                  type="button"
                  @click="searchCep"
                  class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors"
                >
                  <svg class="w-4 h-4 mr-2 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path>
                  </svg>
                  Buscar CEP
                </button>
              </div>
            </div>

            <!-- Endereço -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label for="street" class="block text-sm font-medium text-gray-700 mb-2">
                  Rua <span class="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  id="street"
                  v-model="form.street"
                  :class="[
                    'w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent',
                    form.errors.street ? 'border-red-500' : 'border-gray-300'
                  ]"
                  placeholder="Nome da rua"
                  required
                />
                <p v-if="form.errors.street" class="mt-1 text-sm text-red-600">
                  {{ form.errors.street }}
                </p>
              </div>
              <div>
                <label for="number" class="block text-sm font-medium text-gray-700 mb-2">
                  Número <span class="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  id="number"
                  v-model="form.number"
                  :class="[
                    'w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent',
                    form.errors.number ? 'border-red-500' : 'border-gray-300'
                  ]"
                  placeholder="Número ou S/N"
                  required
                />
                <p v-if="form.errors.number" class="mt-1 text-sm text-red-600">
                  {{ form.errors.number }}
                </p>
              </div>
            </div>

            <!-- Bairro e Cidade -->
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label for="district" class="block text-sm font-medium text-gray-700 mb-2">
                  Bairro <span class="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  id="district"
                  v-model="form.district"
                  :class="[
                    'w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent',
                    form.errors.district ? 'border-red-500' : 'border-gray-300'
                  ]"
                  placeholder="Nome do bairro"
                  required
                />
                <p v-if="form.errors.district" class="mt-1 text-sm text-red-600">
                  {{ form.errors.district }}
                </p>
              </div>
              <div>
                <label for="city" class="block text-sm font-medium text-gray-700 mb-2">
                  Cidade <span class="text-red-500">*</span>
                </label>
                <input
                  type="text"
                  id="city"
                  v-model="form.city"
                  :class="[
                    'w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent',
                    form.errors.city ? 'border-red-500' : 'border-gray-300'
                  ]"
                  placeholder="Nome da cidade"
                  required
                />
                <p v-if="form.errors.city" class="mt-1 text-sm text-red-600">
                  {{ form.errors.city }}
                </p>
              </div>
            </div>

            <!-- Estado -->
            <div>
              <label for="state" class="block text-sm font-medium text-gray-700 mb-2">
                Estado <span class="text-red-500">*</span>
              </label>
              <select
                id="state"
                v-model="form.state"
                :class="[
                  'w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent',
                  form.errors.state ? 'border-red-500' : 'border-gray-300'
                ]"
                required
              >
                <option value="">Selecione o estado</option>
                <option value="AC">Acre</option>
                <option value="AL">Alagoas</option>
                <option value="AP">Amapá</option>
                <option value="AM">Amazonas</option>
                <option value="BA">Bahia</option>
                <option value="CE">Ceará</option>
                <option value="DF">Distrito Federal</option>
                <option value="ES">Espírito Santo</option>
                <option value="GO">Goiás</option>
                <option value="MA">Maranhão</option>
                <option value="MT">Mato Grosso</option>
                <option value="MS">Mato Grosso do Sul</option>
                <option value="MG">Minas Gerais</option>
                <option value="PA">Pará</option>
                <option value="PB">Paraíba</option>
                <option value="PR">Paraná</option>
                <option value="PE">Pernambuco</option>
                <option value="PI">Piauí</option>
                <option value="RJ">Rio de Janeiro</option>
                <option value="RN">Rio Grande do Norte</option>
                <option value="RS">Rio Grande do Sul</option>
                <option value="RO">Rondônia</option>
                <option value="RR">Roraima</option>
                <option value="SC">Santa Catarina</option>
                <option value="SP">São Paulo</option>
                <option value="SE">Sergipe</option>
                <option value="TO">Tocantins</option>
              </select>
              <p v-if="form.errors.state" class="mt-1 text-sm text-red-600">
                {{ form.errors.state }}
              </p>
            </div>

            <!-- Referência -->
            <div>
              <label for="reference" class="block text-sm font-medium text-gray-700 mb-2">
                Referência
              </label>
              <textarea
                id="reference"
                v-model="form.reference"
                :class="[
                  'w-full px-3 py-2 border rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent',
                  form.errors.reference ? 'border-red-500' : 'border-gray-300'
                ]"
                rows="3"
                placeholder="Ponto de referência, próximo a..."
              ></textarea>
              <p v-if="form.errors.reference" class="mt-1 text-sm text-red-600">
                {{ form.errors.reference }}
              </p>
            </div>

            <!-- Status -->
            <div>
              <label class="flex items-center">
                <input
                  type="checkbox"
                  v-model="form.active"
                  class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded"
                />
                <span class="ml-2 text-sm text-gray-700">Endereço ativo</span>
              </label>
            </div>
          </div>

          <!-- Botões de Ação -->
          <div class="px-6 py-4 bg-gray-50 border-t border-gray-200 flex justify-end gap-3">
            <button @click="goBack" class="btn-secondary">
              Cancelar
            </button>
            <button
              type="submit"
              :disabled="form.processing"
              class="btn-primary"
            >
              <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ form.processing ? 'Salvando...' : 'Atualizar Endereço' }}
            </button>
          </div>
        </form>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { useForm, Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  address: Object,
  clients: Array,
});

const form = useForm({
  client_id: props.address.client_id,
  nickname: props.address.nickname,
  street: props.address.street,
  number: props.address.number,
  district: props.address.district,
  city: props.address.city,
  state: props.address.state,
  zip: props.address.zip,
  reference: props.address.reference,
  active: props.address.active,
});

const searchCep = async () => {
  if (!form.zip || form.zip.length < 8) return;

  try {
    const cleanCep = form.zip.replace(/\D/g, '');
    const response = await fetch(`https://viacep.com.br/ws/${cleanCep}/json/`);
    const data = await response.json();

    if (!data.erro) {
      form.street = data.logradouro || '';
      form.district = data.bairro || '';
      form.city = data.localidade || '';
      form.state = data.uf || '';
    }
  } catch (error) {
    console.error('Erro ao buscar CEP:', error);
  }
};

const goBack = () => {
  window.history.back();
};

const submit = () => {
  form.put(`/addresses/${props.address.id}`);
};
</script>
