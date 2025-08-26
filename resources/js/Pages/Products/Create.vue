<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Novo Produto"
        description="Cadastre um novo produto no sistema">
        <template #actions>
          <Link href="/products" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </Link>
        </template>
      </PageHeader>
    </template>

    <!-- Mensagens flash -->
    <div v-if="$page.props.flash.success" class="mb-4">
      <Alert type="success" :message="$page.props.flash.success" />
    </div>
    <div v-if="$page.props.flash.error" class="mb-4">
      <Alert type="error" :message="$page.props.flash.error" />
    </div>

    <div class="max-w-2xl mx-auto">
      <form @submit.prevent="submitForm" class="space-y-6">
        <Card>
          <div class="p-6 space-y-4">
            <div>
              <label for="name" class="block text-sm font-medium text-gray-700 mb-1">
                Nome do Produto *
              </label>
              <input
                type="text"
                id="name"
                v-model="form.name"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                :class="{ 'border-red-500': errors.name }"
                placeholder="Digite o nome do produto">
              <p v-if="errors.name" class="mt-1 text-sm text-red-600">{{ errors.name }}</p>
            </div>

            <div>
              <label for="active_ingredient_id" class="block text-sm font-medium text-gray-700 mb-1">
                Princípio Ativo *
              </label>
              <div class="flex gap-2">
                <select
                  id="active_ingredient_id"
                  v-model="form.active_ingredient_id"
                  class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.active_ingredient_id }">
                  <option value="">Selecione um princípio ativo</option>
                  <option v-for="ingredient in activeIngredients" :key="ingredient.id" :value="ingredient.id">
                    {{ ingredient.name }}
                  </option>
                </select>
                <button
                  type="button"
                  @click="showActiveIngredientModal = true"
                  class="px-3 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 transition-colors">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                  </svg>
                </button>
              </div>
              <p v-if="errors.active_ingredient_id" class="mt-1 text-sm text-red-600">{{ errors.active_ingredient_id }}</p>
            </div>

            <div>
              <label for="chemical_group_id" class="block text-sm font-medium text-gray-700 mb-1">
                Grupo Químico *
              </label>
              <div class="flex gap-2">
                <select
                  id="chemical_group_id"
                  v-model="form.chemical_group_id"
                  class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.chemical_group_id }">
                  <option value="">Selecione um grupo químico</option>
                  <option v-for="group in chemicalGroups" :key="group.id" :value="group.id">
                    {{ group.name }}
                  </option>
                </select>
                <button
                  type="button"
                  @click="showChemicalGroupModal = true"
                  class="px-3 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 transition-colors">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                  </svg>
                </button>
              </div>
              <p v-if="errors.chemical_group_id" class="mt-1 text-sm text-red-600">{{ errors.chemical_group_id }}</p>
            </div>

            <div>
              <label for="antidote_id" class="block text-sm font-medium text-gray-700 mb-1">
                Antídoto *
              </label>
              <div class="flex gap-2">
                <select
                  id="antidote_id"
                  v-model="form.antidote_id"
                  class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.antidote_id }">
                  <option value="">Selecione um antídoto</option>
                  <option v-for="antidote in antidotes" :key="antidote.id" :value="antidote.id">
                    {{ antidote.name }}
                  </option>
                </select>
                <button
                  type="button"
                  @click="showAntidoteModal = true"
                  class="px-3 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 transition-colors">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                  </svg>
                </button>
              </div>
              <p v-if="errors.antidote_id" class="mt-1 text-sm text-red-600">{{ errors.antidote_id }}</p>
            </div>

            <div>
              <label for="organ_registration_id" class="block text-sm font-medium text-gray-700 mb-1">
                Reg. Min da Saude
              </label>
              <div class="flex gap-2">
                <select
                  id="organ_registration_id"
                  v-model="form.organ_registration_id"
                  class="flex-1 px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.organ_registration_id }">
                  <option value="">Selecione um registro (opcional)</option>
                  <option v-for="registration in organRegistrations" :key="registration.id" :value="registration.id">
                    {{ registration.record }}
                  </option>
                </select>
                <button
                  type="button"
                  @click="showOrganRegistrationModal = true"
                  class="px-3 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 transition-colors">
                  <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                  </svg>
                </button>
              </div>
              <p v-if="errors.organ_registration_id" class="mt-1 text-sm text-red-600">{{ errors.organ_registration_id }}</p>
            </div>
          </div>
        </Card>

        <!-- Botões de ação -->
        <div class="flex justify-end gap-4">
          <Link href="/products" class="btn-secondary">
            Cancelar
          </Link>
          <button type="submit" class="btn-primary" :disabled="isSubmitting">
            <svg v-if="isSubmitting" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ isSubmitting ? 'Salvando...' : 'Salvar Produto' }}
          </button>
        </div>
      </form>
    </div>

    <!-- Modal - Princípio Ativo -->
    <Modal :show="showActiveIngredientModal" @close="showActiveIngredientModal = false">
      <template #title>Novo Princípio Ativo</template>
      <template #content>
        <div class="space-y-4">
          <div>
            <label for="new_active_ingredient" class="block text-sm font-medium text-gray-700 mb-1">
              Nome do Princípio Ativo *
            </label>
            <input
              type="text"
              id="new_active_ingredient"
              v-model="newActiveIngredient"
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
              placeholder="Digite o nome do princípio ativo">
          </div>
        </div>
      </template>
      <template #actions>
        <button
          type="button"
          @click="createActiveIngredient"
          class="btn-primary mr-2"
          :disabled="!newActiveIngredient.trim()">
          Criar
        </button>
        <button type="button" class="btn-secondary" @click="showActiveIngredientModal = false">
          Cancelar
        </button>
      </template>
    </Modal>

    <!-- Modal - Grupo Químico -->
    <Modal :show="showChemicalGroupModal" @close="showChemicalGroupModal = false">
      <template #title>Novo Grupo Químico</template>
      <template #content>
        <div class="space-y-4">
          <div>
            <label for="new_chemical_group" class="block text-sm font-medium text-gray-700 mb-1">
              Nome do Grupo Químico *
            </label>
            <input
              type="text"
              id="new_chemical_group"
              v-model="newChemicalGroup"
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
              placeholder="Digite o nome do grupo químico">
          </div>
        </div>
      </template>
      <template #actions>
        <button
          type="button"
          @click="createChemicalGroup"
          class="btn-primary mr-2"
          :disabled="!newChemicalGroup.trim()">
          Criar
        </button>
        <button type="button" class="btn-secondary" @click="showChemicalGroupModal = false">
          Cancelar
        </button>
      </template>
    </Modal>

    <!-- Modal - Antídoto -->
    <Modal :show="showAntidoteModal" @close="showAntidoteModal = false">
      <template #title>Novo Antídoto</template>
      <template #content>
        <div class="space-y-4">
          <div>
            <label for="new_antidote" class="block text-sm font-medium text-gray-700 mb-1">
              Nome do Antídoto *
            </label>
            <input
              type="text"
              id="new_antidote"
              v-model="newAntidote"
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
              placeholder="Digite o nome do antídoto">
          </div>
        </div>
      </template>
      <template #actions>
        <button
          type="button"
          @click="createAntidote"
          class="btn-primary mr-2"
          :disabled="!newAntidote.trim()">
          Criar
        </button>
        <button type="button" class="btn-secondary" @click="showAntidoteModal = false">
          Cancelar
        </button>
      </template>
    </Modal>

    <!-- Modal - Registro Ministerial -->
    <Modal :show="showOrganRegistrationModal" @close="showOrganRegistrationModal = false">
      <template #title>Novo Registro Ministerial</template>
      <template #content>
        <div class="space-y-4">
          <div>
            <label for="new_organ_registration" class="block text-sm font-medium text-gray-700 mb-1">
              Registro *
            </label>
            <input
              type="text"
              id="new_organ_registration"
              v-model="newOrganRegistration"
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
              placeholder="Digite o número do registro">
          </div>
        </div>
      </template>
      <template #actions>
        <button
          type="button"
          @click="createOrganRegistration"
          class="btn-primary mr-2"
          :disabled="!newOrganRegistration.trim()">
          Criar
        </button>
        <button type="button" class="btn-secondary" @click="showOrganRegistrationModal = false">
          Cancelar
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, useForm, router, usePage } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import Alert from '@/Components/Alert.vue';

const $page = usePage();

const props = defineProps({
  activeIngredients: Array,
  chemicalGroups: Array,
  antidotes: Array,
  organRegistrations: Array,
  errors: Object,
});

const form = useForm({
  name: '',
  active_ingredient_id: '',
  chemical_group_id: '',
  antidote_id: '',
  organ_registration_id: '',
});

const isSubmitting = ref(false);

// Estados dos modais
const showActiveIngredientModal = ref(false);
const showChemicalGroupModal = ref(false);
const showAntidoteModal = ref(false);
const showOrganRegistrationModal = ref(false);

// Novos itens
const newActiveIngredient = ref('');
const newChemicalGroup = ref('');
const newAntidote = ref('');
const newOrganRegistration = ref('');

const submitForm = () => {
  console.log('Enviando formulário:', form.data()); // Debug
  isSubmitting.value = true;

  form.post('/products', {
    onSuccess: () => {
      console.log('Sucesso!'); // Debug
      isSubmitting.value = false;
    },
    onError: (errors) => {
      console.log('Erro:', errors); // Debug
      isSubmitting.value = false;
    },
  });
};

// Criar novo princípio ativo
const createActiveIngredient = () => {
  router.post('/active-ingredients', { name: newActiveIngredient.value }, {
    onSuccess: () => {
      newActiveIngredient.value = '';
      showActiveIngredientModal.value = false;
      // Recarregar a página para atualizar as listas
      router.reload();
    },
    onError: (errors) => {
      console.log('Erro ao criar princípio ativo:', errors);
      // Em caso de erro, manter o modal aberto
    },
  });
};

// Criar novo grupo químico
const createChemicalGroup = () => {
  router.post('/chemical-groups', { name: newChemicalGroup.value }, {
    onSuccess: () => {
      newChemicalGroup.value = '';
      showChemicalGroupModal.value = false;
      router.reload();
    },
    onError: (errors) => {
      console.log('Erro ao criar grupo químico:', errors);
      // Em caso de erro, manter o modal aberto
    },
  });
};

// Criar novo antídoto
const createAntidote = () => {
  router.post('/antidotes', { name: newAntidote.value }, {
    onSuccess: () => {
      newAntidote.value = '';
      showAntidoteModal.value = false;
      router.reload();
    },
    onError: (errors) => {
      console.log('Erro ao criar antídoto:', errors);
      // Em caso de erro, manter o modal aberto
    },
  });
};

// Criar novo registro ministerial
const createOrganRegistration = () => {
  router.post('/organ-registrations', { record: newOrganRegistration.value }, {
    onSuccess: () => {
      newOrganRegistration.value = '';
      showOrganRegistrationModal.value = false;
      router.reload();
    },
    onError: (errors) => {
      console.log('Erro ao criar registro ministerial:', errors);
      // Em caso de erro, manter o modal aberto
    },
  });
};
</script>
