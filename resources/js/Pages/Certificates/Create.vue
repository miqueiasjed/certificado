<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Novo Certificado" description="Crie um novo certificado para o sistema">
        <template #actions>
          <Link href="/certificates" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-6xl mx-auto">
      <form @submit.prevent="submitForm" class="space-y-6">
        <!-- Informações Principais -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Informações Principais</h3>
          </div>
          <div class="p-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
              <div>
                <label for="client_id" class="block text-sm font-medium text-gray-700 mb-1">
                  Cliente *
                </label>
                <select
                  id="client_id"
                  v-model="form.client_id"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.client_id }"
                >
                  <option value="">Selecione um cliente</option>
                  <option v-for="client in clients" :key="client.id" :value="client.id">
                    {{ client.name }} - {{ client.cnpj }}
                  </option>
                </select>
                <p v-if="errors.client_id" class="mt-1 text-sm text-red-600">{{ errors.client_id }}</p>
              </div>

              <div>
                <label for="execution_date" class="block text-sm font-medium text-gray-700 mb-1">
                  Data da Execução *
                </label>
                <input
                  type="date"
                  id="execution_date"
                  v-model="form.execution_date"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.execution_date }"
                  required
                />
                <p v-if="errors.execution_date" class="mt-1 text-sm text-red-600">{{ errors.execution_date }}</p>
              </div>

              <div>
                <label for="warranty" class="block text-sm font-medium text-gray-700 mb-1">
                  Garantia
                </label>
                <input
                  type="date"
                  id="warranty"
                  v-model="form.warranty"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                  :class="{ 'border-red-500': errors.warranty }"
                />
                <p v-if="errors.warranty" class="mt-1 text-sm text-red-600">{{ errors.warranty }}</p>
              </div>
              <div>
                <label for="work_order_id" class="block text-sm font-medium text-gray-700 mb-1">
                  Ordem de Serviço
                </label>
                <select
                  id="work_order_id"
                  v-model="form.work_order_id"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                >
                  <option value="">Selecione uma OS</option>
                  <option v-for="wo in workOrders" :key="wo.id" :value="wo.id">
                    {{ wo.order_number }} - {{ new Date(wo.scheduled_date).toLocaleDateString('pt-BR') }}
                  </option>
                </select>
              </div>
            </div>
          </div>
        </Card>

        <!-- Produtos -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex justify-between items-center">
              <h3 class="text-lg font-medium text-gray-900">Produtos</h3>
              <button
                type="button"
                @click="addProduct"
                class="px-3 py-1 text-sm bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-colors"
              >
                <svg class="w-4 h-4 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Adicionar Produto
              </button>
            </div>
          </div>
          <div class="p-6">
            <div v-if="form.products.length === 0" class="text-center py-8 text-gray-500">
              <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4" />
              </svg>
              <p class="mt-2">Nenhum produto adicionado</p>
              <p class="text-sm">Clique em "Adicionar Produto" ou cadastre um novo produto</p>
              <div class="mt-4 flex justify-center space-x-2">
                <button
                  type="button"
                  @click="addProduct"
                  class="px-3 py-1 text-sm bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-colors"
                >
                  Adicionar Produto
                </button>
                <button
                  type="button"
                  @click="showProductModal = true"
                  class="px-3 py-1 text-sm bg-indigo-600 text-white rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 transition-colors"
                >
                  Novo Produto
                </button>
              </div>
            </div>

            <div v-else class="space-y-4">
              <div
                v-for="(product, index) in form.products"
                :key="index"
                class="grid grid-cols-1 md:grid-cols-4 gap-4 p-4 border border-gray-200 rounded-lg bg-gray-50"
              >
                <div class="md:col-span-3">
                  <label :for="`product_${index}`" class="block text-sm font-medium text-gray-700 mb-1">
                    Produto {{ index + 1 }}
                  </label>
                  <select
                    :id="`product_${index}`"
                    v-model="product.product_id"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                    :class="{ 'border-red-500': errors[`products.${index}.product_id`] }"
                  >
                    <option value="">Selecione um produto</option>
                    <option v-for="prod in products" :key="prod.id" :value="prod.id">
                      {{ prod.name }}
                    </option>
                  </select>
                  <p v-if="errors[`products.${index}.product_id`]" class="mt-1 text-sm text-red-600">
                    {{ errors[`products.${index}.product_id`] }}
                  </p>
                </div>

                <div class="flex items-end">
                  <button
                    type="button"
                    @click="removeProduct(index)"
                    class="w-full px-3 py-2 bg-red-500 text-white rounded-md hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-colors flex items-center justify-center"
                    title="Remover produto"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                    <span class="ml-2 text-sm">Remover</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </Card>

        <!-- Serviços -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <div class="flex justify-between items-center">
              <h3 class="text-lg font-medium text-gray-900">Serviços</h3>
              <div class="flex space-x-2">
                <button
                  type="button"
                  @click="addService"
                  class="px-3 py-1 text-sm bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 focus:ring-offset-2 transition-colors"
                >
                  <svg class="w-4 h-4 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                  </svg>
                  Adicionar Serviço
                </button>
                <button
                  type="button"
                  @click="showServiceModal = true"
                  class="px-3 py-1 text-sm bg-purple-600 text-white rounded-md hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-purple-500 focus:ring-offset-2 transition-colors"
                >
                  <svg class="w-4 h-4 mr-1 inline" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                  </svg>
                  Novo Serviço
                </button>
              </div>
            </div>
          </div>
          <div class="p-6">
            <div v-if="form.services.length === 0" class="text-center py-8 text-gray-500">
              <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
              </svg>
              <p class="mt-2">Nenhum serviço adicionado</p>
              <p class="text-sm">Clique em "Adicionar Serviço" para começar</p>
            </div>

            <div v-else class="space-y-4">
              <div
                v-for="(service, index) in form.services"
                :key="index"
                class="grid grid-cols-1 md:grid-cols-4 gap-4 p-4 border border-gray-200 rounded-lg bg-gray-50"
              >
                <div class="md:col-span-3">
                  <label :for="`service_${index}`" class="block text-sm font-medium text-gray-700 mb-1">
                    Serviço {{ index + 1 }}
                  </label>
                  <select
                    :id="`service_${index}`"
                    v-model="service.service_id"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                    :class="{ 'border-red-500': errors[`services.${index}.service_id`] }"
                  >
                    <option value="">Selecione um serviço</option>
                    <option v-for="serv in services" :key="serv.id" :value="serv.id">
                      {{ serv.name }} - {{ serv.category }}
                    </option>
                  </select>
                  <p v-if="errors[`services.${index}.service_id`]" class="mt-1 text-sm text-red-600">
                    {{ errors[`services.${index}.service_id`] }}
                  </p>
                </div>

                <div class="flex items-end">
                  <button
                    type="button"
                    @click="removeService(index)"
                    class="w-full px-3 py-2 bg-red-500 text-white rounded-md hover:bg-red-600 focus:outline-none focus:ring-2 focus:ring-red-500 focus:ring-offset-2 transition-colors flex items-center justify-center"
                    title="Remover serviço"
                  >
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                    </svg>
                    <span class="ml-2 text-sm">Remover</span>
                  </button>
                </div>
              </div>
            </div>
          </div>
        </Card>

        <!-- Observações -->
        <Card>
          <div class="px-6 py-4 border-b border-gray-200">
            <h3 class="text-lg font-medium text-gray-900">Observações</h3>
          </div>
          <div class="p-6">
            <div>
              <label for="notes" class="block text-sm font-medium text-gray-700 mb-1">
                Observações
              </label>
              <textarea
                id="notes"
                v-model="form.notes"
                rows="3"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
                :class="{ 'border-red-500': errors.notes }"
                placeholder="Observações adicionais sobre o certificado..."
              ></textarea>
              <p v-if="errors.notes" class="mt-1 text-sm text-red-600">{{ errors.notes }}</p>
            </div>
          </div>
        </Card>



        <!-- Botões de Ação -->
        <div class="flex justify-end space-x-3">
          <Link href="/certificates" class="btn-secondary">
            Cancelar
          </Link>
          <button type="submit" class="btn-primary" :disabled="form.processing">
            <svg v-if="form.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
              <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
              <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
            </svg>
            {{ form.processing ? 'Criando...' : 'Criar Certificado' }}
          </button>
        </div>
      </form>
    </div>



    <!-- Modal para Cadastro Rápido de Serviço -->
    <Modal :show="showServiceModal" @close="showServiceModal = false">
      <template #icon>
        <svg class="h-6 w-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
        </svg>
      </template>

      <template #title>
        Cadastrar Novo Serviço
      </template>

      <template #content>
        <p class="text-sm text-gray-500 mb-4">Preencha os dados do novo serviço</p>

        <form @submit.prevent="submitService" class="space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
              <label for="service_name" class="block text-sm font-medium text-gray-700 mb-1">
                Nome do Serviço *
              </label>
              <input
                type="text"
                id="service_name"
                v-model="serviceForm.name"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                :class="{ 'border-red-500': serviceErrors.name }"
                placeholder="Digite o nome do serviço"
                required
              />
              <p v-if="serviceErrors.name" class="mt-1 text-sm text-red-600">{{ serviceErrors.name }}</p>
            </div>

            <div>
              <label for="service_category" class="block text-sm font-medium text-gray-700 mb-1">
                Categoria
              </label>
              <input
                type="text"
                id="service_category"
                v-model="serviceForm.category"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                :class="{ 'border-red-500': serviceErrors.category }"
                placeholder="Ex: Análise, Consultoria, Teste..."
              />
              <p v-if="serviceErrors.category" class="mt-1 text-sm text-red-600">{{ serviceErrors.category }}</p>
            </div>

            <div>
              <label for="service_price" class="block text-sm font-medium text-gray-700 mb-1">
                Preço
              </label>
              <input
                type="number"
                id="service_price"
                v-model="serviceForm.price"
                step="0.01"
                min="0"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                :class="{ 'border-red-500': serviceErrors.price }"
                placeholder="0.00"
              />
              <p v-if="serviceErrors.price" class="mt-1 text-sm text-red-600">{{ serviceErrors.price }}</p>
            </div>

            <div>
              <label for="service_active" class="block text-sm font-medium text-gray-700 mb-1">
                Status
              </label>
              <select
                id="service_active"
                v-model="serviceForm.is_active"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
                :class="{ 'border-red-500': serviceErrors.is_active }"
              >
                <option :value="true">Ativo</option>
                <option :value="false">Inativo</option>
              </select>
              <p v-if="serviceErrors.is_active" class="mt-1 text-sm text-red-600">{{ serviceErrors.is_active }}</p>
            </div>
          </div>

          <div>
            <label for="service_description" class="block text-sm font-medium text-gray-700 mb-1">
              Descrição
            </label>
            <textarea
              id="service_description"
              v-model="serviceForm.description"
              rows="3"
              class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-purple-500 focus:border-transparent"
              :class="{ 'border-red-500': serviceErrors.description }"
              placeholder="Descreva o serviço..."
            ></textarea>
            <p v-if="serviceErrors.description" class="mt-1 text-sm text-red-600">{{ serviceErrors.description }}</p>
          </div>
        </form>
      </template>

      <template #actions>
        <button
          type="button"
          @click="showServiceModal = false"
          class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 transition-colors"
        >
          Cancelar
        </button>
        <button
          type="button"
          @click="submitService"
          :disabled="serviceForm.processing"
          class="px-4 py-2 text-sm font-medium text-white bg-purple-600 border border-transparent rounded-md hover:bg-purple-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-purple-500 disabled:opacity-50 transition-colors"
        >
          <svg v-if="serviceForm.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          {{ serviceForm.processing ? 'Criando...' : 'Criar Serviço' }}
        </button>
      </template>
    </Modal>

    <!-- Modal para Cadastro Rápido de Produto -->
    <Modal :show="showProductModal" @close="showProductModal = false">
      <template #icon>
        <svg class="h-6 w-6 text-indigo-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
        </svg>
      </template>

      <template #title>
        Cadastrar Novo Produto
      </template>

      <template #content>
        <p class="text-sm text-gray-500 mb-4">Preencha os dados do novo produto</p>

        <form @submit.prevent="submitProduct" class="space-y-4">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div class="md:col-span-2">
              <label for="product_name" class="block text-sm font-medium text-gray-700 mb-1">
                Nome do Produto *
              </label>
              <input
                type="text"
                id="product_name"
                v-model="productForm.name"
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent"
                :class="{ 'border-red-500': productErrors.name }"
                placeholder="Digite o nome do produto"
                required
              />
              <p v-if="productErrors.name" class="mt-1 text-sm text-red-600">{{ productErrors.name }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Princípio Ativo *</label>
              <select v-model="productForm.active_ingredient_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" :class="{ 'border-red-500': productErrors.active_ingredient_id }">
                <option value="">Selecione</option>
                <option v-for="ai in props.activeIngredients" :key="ai.id" :value="ai.id">{{ ai.name }}</option>
              </select>
              <p v-if="productErrors.active_ingredient_id" class="mt-1 text-sm text-red-600">{{ productErrors.active_ingredient_id }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Grupo Químico *</label>
              <select v-model="productForm.chemical_group_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" :class="{ 'border-red-500': productErrors.chemical_group_id }">
                <option value="">Selecione</option>
                <option v-for="cg in props.chemicalGroups" :key="cg.id" :value="cg.id">{{ cg.name }}</option>
              </select>
              <p v-if="productErrors.chemical_group_id" class="mt-1 text-sm text-red-600">{{ productErrors.chemical_group_id }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Antídoto *</label>
              <select v-model="productForm.antidote_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" :class="{ 'border-red-500': productErrors.antidote_id }">
                <option value="">Selecione</option>
                <option v-for="an in props.antidotes" :key="an.id" :value="an.id">{{ an.name }}</option>
              </select>
              <p v-if="productErrors.antidote_id" class="mt-1 text-sm text-red-600">{{ productErrors.antidote_id }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Registro (MS)</label>
              <select v-model="productForm.organ_registration_id" class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent" :class="{ 'border-red-500': productErrors.organ_registration_id }">
                <option value="">Selecione</option>
                <option v-for="or in props.organRegistrations" :key="or.id" :value="or.id">{{ or.record }}</option>
              </select>
              <p v-if="productErrors.organ_registration_id" class="mt-1 text-sm text-red-600">{{ productErrors.organ_registration_id }}</p>
            </div>
          </div>
        </form>
      </template>

      <template #actions>
        <button
          type="button"
          @click="showProductModal = false"
          class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 transition-colors"
        >
          Cancelar
        </button>
        <button
          type="button"
          @click="submitProduct"
          :disabled="productForm.processing"
          class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 border border-transparent rounded-md hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 disabled:opacity-50 transition-colors"
        >
          <svg v-if="productForm.processing" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white inline" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          {{ productForm.processing ? 'Criando...' : 'Criar Produto' }}
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, onMounted, watch } from 'vue';
import { useForm } from '@inertiajs/vue3';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';

const props = defineProps({
  clients: Array,
  products: Array,
  services: Array,
  activeIngredients: Array,
  chemicalGroups: Array,
  antidotes: Array,
  organRegistrations: Array,
  errors: Object,
});

const showServiceModal = ref(false);
const showProductModal = ref(false);
const workOrders = ref([]);

const form = useForm({
  client_id: '',
  work_order_id: '',
  products: [],
  services: [],
  execution_date: '',
  warranty: '',
  notes: '',
});



const serviceForm = useForm({
  name: '',
  category: '',
  price: 0,
  is_active: true,
  description: '',
});

const serviceErrors = ref({});
const productForm = useForm({
  name: '',
  active_ingredient_id: '',
  chemical_group_id: '',
  antidote_id: '',
  organ_registration_id: '',
});
const productErrors = ref({});



const loadWorkOrders = async (clientId) => {
  workOrders.value = [];
  if (!clientId) return;
  try {
    const resp = await fetch(`/work-orders/client/${clientId}`);
    workOrders.value = await resp.json();
  } catch (e) {}
};

onMounted(() => {
  if (form.client_id) loadWorkOrders(form.client_id);
});

watch(() => form.client_id, (val) => {
  form.work_order_id = '';
  loadWorkOrders(val);
});

const addProduct = () => {
  form.products.push({
    product_id: '',
  });
};

const removeProduct = (index) => {
  form.products.splice(index, 1);
};

const addService = () => {
  form.services.push({
    service_id: '',
  });
};

const removeService = (index) => {
  form.services.splice(index, 1);
};



const submitForm = () => {
  form.post('/certificates', {
    onSuccess: () => {
      // Sucesso
    },
  });
};



const submitService = () => {
  serviceForm.post('/services', {
    onSuccess: (response) => {
      // Fechar modal e limpar formulário
      showServiceModal.value = false;
      serviceForm.reset();
      serviceErrors.value = {};

      // Recarregar a página para atualizar a lista de serviços
      router.reload();
    },
    onError: (errors) => {
      serviceErrors.value = errors;
    },
  });
};

const submitProduct = () => {
  productForm.post('/products', {
    onSuccess: () => {
      showProductModal.value = false;
      productForm.reset();
      productErrors.value = {};
      // Atualizar lista de produtos
      router.reload();
    },
    onError: (errors) => {
      productErrors.value = errors;
    },
  });
};
</script>
