<template>
  <div class="p-6">
    <!-- Aba: Detalhes da Ordem -->
    <div v-if="activeTab === 'details'">
      <h3 class="text-lg font-medium text-gray-900 mb-6">Detalhes da Ordem</h3>

      <!-- Primeira linha: Informações básicas -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
          <div>
            <label class="block text-sm font-medium text-gray-500">Número da Ordem</label>
            <p class="mt-1 text-sm text-gray-900">{{ props.workOrder.order_number }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-500">Serviço</label>
            <p class="mt-1 text-sm text-gray-900">{{ props.workOrder.service?.name || '-' }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-500">Status</label>
          <p class="mt-1 text-sm text-gray-900">{{ props.workOrder.status_text }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-500">Prioridade</label>
          <p class="mt-1 text-sm text-gray-900">{{ props.workOrder.priority_level_text }}</p>
          </div>
        </div>

      <!-- Segunda linha: Informações de agendamento -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
          <div>
            <label class="block text-sm font-medium text-gray-500">Data Agendada</label>
          <p class="mt-1 text-sm text-gray-900">{{ formatDate(props.workOrder.scheduled_date) }}</p>
          </div>
        <div v-if="props.workOrder.start_time">
            <label class="block text-sm font-medium text-gray-500">Horário de Início</label>
          <p class="mt-1 text-sm text-gray-900">{{ formatDateTime(props.workOrder.start_time) }}</p>
          </div>
        <div v-if="props.workOrder.end_time">
            <label class="block text-sm font-medium text-gray-500">Horário de Término</label>
          <p class="mt-1 text-sm text-gray-900">{{ formatDateTime(props.workOrder.end_time) }}</p>
          </div>
        <div v-if="props.workOrder.duration_text">
            <label class="block text-sm font-medium text-gray-500">Duração</label>
          <p class="mt-1 text-sm text-gray-900">{{ props.workOrder.duration_text }}</p>
          </div>
        </div>

      <!-- Terceira linha: Descrição e Observações lado a lado -->
      <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
        <div v-if="props.workOrder.description">
          <label class="block text-sm font-medium text-gray-500">Descrição</label>
          <p class="mt-1 text-sm text-gray-900">{{ props.workOrder.description }}</p>
        </div>
        <div v-if="props.workOrder.observations">
          <label class="block text-sm font-medium text-gray-500">Observações</label>
          <p class="mt-1 text-sm text-gray-900">{{ props.workOrder.observations }}</p>
      </div>
    </div>

      <!-- Informações do Cliente e Local -->
      <div class="mt-8">
        <h4 class="text-md font-medium text-gray-900 mb-6">Cliente e Local</h4>

        <!-- Informações do Cliente - 4 colunas -->
        <div class="mb-6">
          <h5 class="text-sm font-medium text-gray-700 mb-3">Cliente</h5>
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
          <div>
              <label class="block text-xs font-medium text-gray-500">Nome</label>
              <p class="mt-1 text-sm text-gray-900">{{ props.workOrder.client?.name }}</p>
          </div>
            <div v-if="props.workOrder.client?.document_number">
              <label class="block text-xs font-medium text-gray-500">CPF/CNPJ</label>
              <p class="mt-1 text-sm text-gray-900">{{ props.workOrder.client?.document_number }}</p>
            </div>
            <div v-if="props.workOrder.client?.phone">
              <label class="block text-xs font-medium text-gray-500">Telefone</label>
              <p class="mt-1 text-sm text-gray-900">{{ props.workOrder.client?.phone }}</p>
            </div>
            <div v-if="props.workOrder.client?.email">
              <label class="block text-xs font-medium text-gray-500">Email</label>
              <p class="mt-1 text-sm text-gray-900">{{ props.workOrder.client?.email }}</p>
            </div>
          </div>
        </div>

        <!-- Informações do Endereço - 4 colunas -->
          <div>
          <h5 class="text-sm font-medium text-gray-700 mb-3">Local</h5>
          <div class="grid grid-cols-1 md:grid-cols-4 gap-4">
            <div>
              <label class="block text-xs font-medium text-gray-500">Apelido</label>
              <p class="mt-1 text-sm text-gray-900">{{ props.workOrder.address?.nickname }}</p>
          </div>
          <div>
              <label class="block text-xs font-medium text-gray-500">Rua e Número</label>
              <p class="mt-1 text-sm text-gray-900">{{ props.workOrder.address?.street }}, {{ props.workOrder.address?.number }}</p>
          </div>
          <div>
              <label class="block text-xs font-medium text-gray-500">Cidade/Estado</label>
              <p class="mt-1 text-sm text-gray-900">{{ props.workOrder.address?.city }}/{{ props.workOrder.address?.state }}</p>
            </div>
            <div v-if="props.workOrder.address?.zip_code">
              <label class="block text-xs font-medium text-gray-500">CEP</label>
              <p class="mt-1 text-sm text-gray-900">{{ props.workOrder.address?.zip_code }}</p>
            </div>
          </div>
        </div>
          </div>
        </div>

    <!-- Aba: Produtos e Serviços -->
    <div v-if="activeTab === 'products-services'">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-medium text-gray-900">Produtos</h3>
        <div class="flex space-x-2">
          <button
            @click="showProductModal = true; console.log('Produto modal:', showProductModal)"
            class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-lg text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 transition-all duration-200"
          >
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            Adicionar Produto
          </button>
        </div>
      </div>

      <!-- Produtos Utilizados -->
      <div class="mb-8">
        <h4 class="text-md font-medium text-gray-900 mb-4">Produtos Utilizados</h4>
        <div v-if="props.workOrder.products && props.workOrder.products.length > 0" class="space-y-4">
          <div v-for="product in props.workOrder.products" :key="product.id" class="bg-gray-50 rounded-lg p-4">
            <div class="flex justify-between items-start mb-3">
              <div class="flex-1">
                <h5 class="font-medium text-gray-900">{{ product.name }}</h5>
                <p v-if="product.manufacturer" class="text-sm text-gray-500">{{ product.manufacturer }}</p>
              </div>
              <div class="flex space-x-2">
                <button
                  @click="editProductInOS(product)"
                  class="inline-flex items-center px-3 py-1 border border-blue-300 text-xs font-medium rounded text-blue-700 bg-blue-50 hover:bg-blue-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                >
                  <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                  </svg>
                  Editar
                </button>
                <button
                  @click="removeProductFromOS(product)"
                  class="inline-flex items-center px-3 py-1 border border-red-300 text-xs font-medium rounded text-red-700 bg-red-50 hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
                >
                  <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                  </svg>
                  Remover
                </button>
              </div>
            </div>
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-500">Quantidade</label>
                <p class="mt-1 text-sm text-gray-900">{{ product.pivot.quantity || '-' }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-500">Unidade</label>
                <p class="mt-1 text-sm text-gray-900">{{ product.pivot.unit || '-' }}</p>
              </div>
              <div v-if="product.pivot.observations">
                <label class="block text-sm font-medium text-gray-500">Observações</label>
                <p class="mt-1 text-sm text-gray-900">{{ product.pivot.observations }}</p>
              </div>
            </div>
          </div>
        </div>
        <div v-else class="text-center py-8 text-gray-500">
          <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
          </svg>
          <p class="mt-2 text-sm">Nenhum produto foi registrado para esta ordem de serviço.</p>
          <p class="mt-1 text-xs text-gray-400">Comece criando um novo produto.</p>
        </div>
      </div>

      <!-- Modal para adicionar Produto à OS -->
      <div v-if="showProductModal" class="fixed inset-0 bg-black bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4" @click="console.log('Modal produto renderizado')">
        <div class="relative bg-white rounded-xl shadow-xl min-w-[400px] max-w-4xl w-fit mx-4 transform transition-all max-h-[85vh] overflow-hidden">
          <div class="p-6 overflow-y-auto max-h-[85vh]">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-xl font-semibold text-gray-900">Adicionar Produto à OS</h3>
              <button
                @click="showProductModal = false"
                class="text-gray-400 hover:text-gray-600 transition-colors"
              >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
              </button>
            </div>

            <form @submit.prevent="addProductToOS">
              <div class="space-y-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Selecionar Produto *</label>
                  <select
                    v-model="productForm.product_id"
                    required
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  >
                    <option value="">Selecione um produto...</option>
                    <option v-for="product in availableProductsForOS" :key="product.id" :value="product.id">
                      {{ product.name }}
                    </option>
                  </select>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Quantidade *</label>
                  <input
                    type="number"
                    v-model="productForm.quantity"
                    required
                    step="0.01"
                    min="0"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="0.00"
                  />
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Unidade</label>
                  <select
                    v-model="productForm.unit"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  >
                    <option value="">Selecione</option>
                    <option value="unidade">Unidade</option>
                    <option value="kg">Quilograma (kg)</option>
                    <option value="g">Grama (g)</option>
                    <option value="mg">Miligrama (mg)</option>
                    <option value="L">Litro (L)</option>
                    <option value="mL">Mililitro (mL)</option>
                    <option value="caixa">Caixa</option>
                    <option value="pacote">Pacote</option>
                  </select>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Observações</label>
                  <textarea
                    v-model="productForm.observations"
                    rows="3"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="Observações sobre o uso do produto..."
                  ></textarea>
                </div>
              </div>

              <div class="flex justify-end space-x-4 pt-6">
                <button
                  type="button"
                  @click="showProductModal = false"
                  class="px-6 py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 font-medium transition-colors"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  :disabled="isSubmittingProduct"
                  class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <span v-if="isSubmittingProduct">Adicionando...</span>
                  <span v-else>Adicionar à OS</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Modal para adicionar Serviço à OS -->
      <div v-if="showServiceModal" class="fixed inset-0 bg-black bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4" @click="console.log('Modal serviço renderizado')">
        <div class="relative bg-white rounded-xl shadow-xl min-w-[400px] max-w-4xl w-fit mx-4 transform transition-all max-h-[85vh] overflow-hidden">
          <div class="p-6 overflow-y-auto max-h-[85vh]">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-xl font-semibold text-gray-900">Adicionar Serviço à OS</h3>
              <button
                @click="showServiceModal = false"
                class="text-gray-400 hover:text-gray-600 transition-colors"
              >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
              </button>
            </div>

            <form @submit.prevent="addServiceToOS">
              <div class="space-y-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Selecionar Serviço *</label>
                  <select
                    v-model="serviceForm.service_id"
                    required
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  >
                    <option value="">Selecione um serviço...</option>
                    <option v-for="service in availableServicesForOS" :key="service.id" :value="service.id">
                      {{ service.name }} - {{ service.description || 'Sem descrição' }}
                    </option>
                  </select>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Observações</label>
                  <textarea
                    v-model="serviceForm.observations"
                    rows="3"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                    placeholder="Observações sobre a realização do serviço..."
                  ></textarea>
                </div>
              </div>

              <div class="flex justify-end space-x-4 pt-6">
                <button
                  type="button"
                  @click="showServiceModal = false"
                  class="px-6 py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 font-medium transition-colors"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  :disabled="isSubmittingService"
                  class="px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <span v-if="isSubmittingService">Adicionando...</span>
                  <span v-else>Adicionar à OS</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Modal para editar Produto na OS -->
      <div v-if="showEditProductModal" class="fixed inset-0 bg-black bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-xl shadow-xl max-w-lg w-full mx-4 transform transition-all">
          <div class="p-6">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-xl font-semibold text-gray-900">Editar Produto</h3>
              <button
                @click="showEditProductModal = false"
                class="text-gray-400 hover:text-gray-600 transition-colors"
              >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
              </button>
            </div>

            <form @submit.prevent="updateProductInOS">
              <div class="space-y-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Produto</label>
                  <input
                    type="text"
                    :value="selectedProduct?.name"
                    disabled
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-600"
                  />
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Quantidade *</label>
                  <input
                    type="number"
                    v-model="editProductForm.quantity"
                    required
                    step="0.01"
                    min="0"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Unidade</label>
                  <select
                    v-model="editProductForm.unit"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                  >
                    <option value="">Selecione</option>
                    <option value="unidade">Unidade</option>
                    <option value="kg">Quilograma (kg)</option>
                    <option value="g">Grama (g)</option>
                    <option value="mg">Miligrama (mg)</option>
                    <option value="L">Litro (L)</option>
                    <option value="mL">Mililitro (mL)</option>
                    <option value="caixa">Caixa</option>
                    <option value="pacote">Pacote</option>
                  </select>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Observações</label>
                  <textarea
                    v-model="editProductForm.observations"
                    rows="3"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    placeholder="Observações sobre o uso do produto..."
                  ></textarea>
                </div>
              </div>

              <div class="flex justify-end space-x-4 pt-6">
                <button
                  type="button"
                  @click="showEditProductModal = false"
                  class="px-6 py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 font-medium transition-colors"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  :disabled="isUpdatingProduct"
                  class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <span v-if="isUpdatingProduct">Salvando...</span>
                  <span v-else>Salvar Alterações</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>

      <!-- Modal para editar Serviço na OS -->
      <div v-if="showEditServiceModal" class="fixed inset-0 bg-black bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-xl shadow-xl max-w-lg w-full mx-4 transform transition-all">
          <div class="p-6">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-xl font-semibold text-gray-900">Editar Serviço</h3>
              <button
                @click="showEditServiceModal = false"
                class="text-gray-400 hover:text-gray-600 transition-colors"
              >
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
              </button>
            </div>

            <form @submit.prevent="updateServiceInOS">
              <div class="space-y-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Serviço</label>
                  <input
                    type="text"
                    :value="selectedService?.name"
                    disabled
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg bg-gray-50 text-gray-600"
                  />
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Observações</label>
                  <textarea
                    v-model="editServiceForm.observations"
                    rows="3"
                    class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                    placeholder="Observações sobre a realização do serviço..."
                  ></textarea>
                </div>
              </div>

              <div class="flex justify-end space-x-4 pt-6">
                <button
                  type="button"
                  @click="showEditServiceModal = false"
                  class="px-6 py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 font-medium transition-colors"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  :disabled="isUpdatingService"
                  class="px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <span v-if="isUpdatingService">Salvando...</span>
                  <span v-else>Salvar Alterações</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Aba: Técnicos -->
    <div v-if="activeTab === 'technician'">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-medium text-gray-900">Técnicos Responsáveis</h3>
        <button
          @click="showTechnicianModal = true"
          class="inline-flex items-center px-3 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 rounded-lg transition-colors"
        >
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
          </svg>
          Adicionar Técnico
        </button>
      </div>

      <div v-if="props.workOrder.technicians && props.workOrder.technicians.length > 0" class="space-y-4">
        <div
          v-for="(technician, index) in props.workOrder.technicians"
          :key="technician.id"
          class="bg-gray-50 rounded-lg p-4"
        >
          <div class="flex justify-between items-start mb-3">
            <div class="flex items-center space-x-2">
              <h4 class="font-medium text-gray-900">{{ technician.name }}</h4>
              <span v-if="technician.pivot?.is_primary" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                Principal
              </span>
            </div>
            <div class="flex space-x-2">
              <button
                @click="removeTechnicianFromOS(technician)"
                class="inline-flex items-center px-3 py-1 border border-red-300 text-xs font-medium rounded text-red-700 bg-red-50 hover:bg-red-100 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500"
              >
                <svg class="w-3 h-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                </svg>
                Remover
              </button>
            </div>
          </div>

          <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div v-if="technician.specialty">
              <label class="block text-sm font-medium text-gray-500">Especialidade</label>
              <p class="mt-1 text-sm text-gray-900">{{ technician.specialty }}</p>
            </div>
            <div v-if="technician.phone">
              <label class="block text-sm font-medium text-gray-500">Telefone</label>
              <p class="mt-1 text-sm text-gray-900">{{ technician.phone }}</p>
            </div>
            <div v-if="technician.email">
              <label class="block text-sm font-medium text-gray-500">Email</label>
              <p class="mt-1 text-sm text-gray-900">{{ technician.email }}</p>
            </div>
          </div>
        </div>
      </div>

      <div v-else class="text-center py-8 text-gray-500">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z"></path>
        </svg>
        <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum técnico atribuído</h3>
        <p class="mt-1 text-sm text-gray-500">Esta ordem de serviço não possui técnicos atribuídos.</p>
        <p class="mt-1 text-xs text-gray-400">Comece adicionando um técnico.</p>
      </div>

      <!-- Modal para adicionar Técnico à OS -->
      <div v-if="showTechnicianModal" class="fixed inset-0 bg-black bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-xl shadow-xl max-w-lg w-full mx-4 transform transition-all">
          <div class="p-6">
            <div class="flex items-center justify-between mb-6">
              <h3 class="text-xl font-semibold text-gray-900">Adicionar Técnico à OS</h3>
              <button @click="showTechnicianModal = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
              </button>
            </div>

            <form @submit.prevent="addTechnicianToOS">
              <div class="space-y-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Selecionar Técnico *</label>
                  <select v-model="technicianForm.technician_id" required class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">Selecione um técnico...</option>
                    <option v-for="technician in availableTechniciansForOS" :key="technician.id" :value="technician.id">
                      {{ technician.name }} - {{ technician.specialty || 'Sem especialidade' }}
                    </option>
                  </select>
                </div>
                <div>
                  <label class="flex items-center">
                    <input type="checkbox" v-model="technicianForm.is_primary" class="rounded border-gray-300 text-blue-600 focus:ring-blue-500">
                    <span class="ml-2 text-sm text-gray-700">Marcar como técnico principal</span>
                  </label>
                </div>
              </div>

              <div class="flex justify-end space-x-4 pt-6">
                <button type="button" @click="showTechnicianModal = false" class="px-4 py-2 text-sm font-medium text-gray-700 bg-gray-100 hover:bg-gray-200 rounded-lg transition-colors">
                  Cancelar
                </button>
                <button type="submit" :disabled="isSubmittingTechnician" class="px-6 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg transition-colors">
                  <span v-if="isSubmittingTechnician">Adicionando...</span>
                  <span v-else>Adicionar à OS</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Aba: Cômodos Atendidos -->
    <div v-if="activeTab === 'rooms'">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-medium text-gray-900">Cômodos Atendidos</h3>
        <button
          @click="showAddRoomModal = true"
          class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
        >
          <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
          </svg>
          Adicionar Cômodo
        </button>
      </div>

      <div v-if="props.workOrder.rooms && props.workOrder.rooms.length > 0" class="space-y-6">
        <div
          v-for="(room, index) in props.workOrder.rooms"
          :key="room.id"
          class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm"
        >
          <!-- Cabeçalho do Cômodo -->
          <div class="flex justify-between items-start mb-6">
            <div class="flex items-center">
              <div class="flex-shrink-0 h-12 w-12 rounded-lg bg-blue-100 flex items-center justify-center">
                <svg class="h-6 w-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                </svg>
            </div>
              <div class="ml-4">
                <h4 class="text-lg font-medium text-gray-900">{{ room.name }}</h4>
                <p class="text-sm text-gray-500">Cômodo #{{ index + 1 }}</p>
                </div>
                    </div>
                <button
              @click="removeRoom(room.id)"
              class="text-red-600 hover:text-red-800 transition-colors duration-200"
              :disabled="isRemovingRoom"
            >
              <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                </svg>
              </button>
            </div>


          <!-- Seção de Evento -->
          <div class="border-t pt-6 mb-6">
            <h5 class="text-sm font-medium text-gray-900 mb-4 flex items-center justify-between">
              <div class="flex items-center">
                <svg class="h-4 w-4 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Evento Realizado
              </div>
              <button
                @click="openRoomEventModal(room.id)"
                class="inline-flex items-center px-2 py-1 text-xs font-medium rounded text-green-600 bg-green-50 hover:bg-green-100 focus:outline-none focus:ring-1 focus:ring-green-500 transition-colors"
              >
                <svg class="h-3 w-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
                {{ room.pivot?.event_type_id ? 'Editar' : 'Adicionar' }}
              </button>
            </h5>
            <div v-if="room.pivot?.event_type_id" class="grid grid-cols-1 md:grid-cols-2 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-500 mb-1">Tipo de Evento</label>
                <p class="text-sm text-gray-900">{{ getEventTypeText(room.pivot.event_type_id) }}</p>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-500 mb-1">Data do Evento</label>
                <p class="text-sm text-gray-900">{{ formatDate(room.pivot.event_date) }}</p>
              </div>
              </div>
            <div v-if="room.pivot?.event_description" class="mt-4">
              <label class="block text-sm font-medium text-gray-500 mb-1">Descrição do Evento</label>
              <p class="text-sm text-gray-900">{{ room.pivot.event_description }}</p>
          </div>
            <div v-if="room.pivot?.event_observations" class="mt-4">
              <label class="block text-sm font-medium text-gray-500 mb-1">Observações do Evento</label>
              <p class="text-sm text-gray-900">{{ room.pivot.event_observations }}</p>
        </div>
            <div v-if="!room.pivot?.event_type_id" class="text-sm text-gray-500 italic">
              Nenhum evento registrado para este cômodo.
      </div>
            </div>

          <!-- Seção de Avistamento de Praga -->
          <div class="border-t pt-6">
            <h5 class="text-sm font-medium text-gray-900 mb-4 flex items-center justify-between">
              <div class="flex items-center">
                <svg class="h-4 w-4 mr-2 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                </svg>
                Avistamento de Praga
              </div>
              <button
                @click="openRoomPestSightingModal(room.id)"
                class="inline-flex items-center px-2 py-1 text-xs font-medium rounded text-orange-600 bg-orange-50 hover:bg-orange-100 focus:outline-none focus:ring-1 focus:ring-orange-500 transition-colors"
              >
                <svg class="h-3 w-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                </svg>
                {{ room.pivot?.pest_type ? 'Editar' : 'Adicionar' }}
              </button>
            </h5>
            <div v-if="room.pivot?.pest_type" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                <label class="block text-sm font-medium text-gray-500 mb-1">Tipo de Praga</label>
                <p class="text-sm text-gray-900">{{ getPestTypeText(room.pivot.pest_type) }}</p>
                </div>
                <div>
                <label class="block text-sm font-medium text-gray-500 mb-1">Data do Avistamento</label>
                <p class="text-sm text-gray-900">{{ formatDate(room.pivot.pest_sighting_date) }}</p>
                </div>
                </div>
            <div v-if="room.pivot?.pest_location || room.pivot?.pest_quantity" class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4">
              <div v-if="room.pivot?.pest_location">
                <label class="block text-sm font-medium text-gray-500 mb-1">Localização no Cômodo</label>
                <p class="text-sm text-gray-900">{{ room.pivot.pest_location }}</p>
                </div>
              <div v-if="room.pivot?.pest_quantity">
                <label class="block text-sm font-medium text-gray-500 mb-1">Quantidade Observada</label>
                <p class="text-sm text-gray-900">{{ room.pivot.pest_quantity }}</p>
                </div>
                </div>
            <div v-if="room.pivot?.pest_observation" class="mt-4">
              <label class="block text-sm font-medium text-gray-500 mb-1">Observação</label>
              <p class="text-sm text-gray-900">{{ room.pivot.pest_observation }}</p>
                </div>
            <div v-if="!room.pivot?.pest_type" class="text-sm text-gray-500 italic">
              Nenhum avistamento de praga registrado para este cômodo.
            </div>
          </div>
        </div>
      </div>

      <div v-else class="text-center py-8 text-gray-500">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6"></path>
                </svg>
        <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum cômodo atendido</h3>
        <p class="mt-1 text-sm text-gray-500">Esta ordem de serviço não possui cômodos registrados.</p>
                <button
          @click="showAddRoomModal = true"
          class="mt-3 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
        >
          <svg class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                  </svg>
          Adicionar Primeiro Cômodo
              </button>
      </div>
    </div>

    <!-- Aba: Dispositivos -->
    <div v-if="activeTab === 'devices'">
      <div class="flex items-center justify-between mb-4">
        <h3 class="text-lg font-medium text-gray-900">Dispositivos Utilizados</h3>
        <button
          @click="showAddDeviceModal = true"
          class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
        >
          <svg class="h-4 w-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
          </svg>
          Adicionar Dispositivo
        </button>
      </div>

      <!-- Dispositivos com Eventos -->
      <div v-if="groupedDeviceEvents && groupedDeviceEvents.length > 0" class="space-y-6">
        <div
          v-for="deviceGroup in groupedDeviceEvents"
          :key="deviceGroup.device.id"
          class="bg-white border border-gray-200 rounded-lg p-6 shadow-sm"
        >
          <!-- Cabeçalho do Dispositivo -->
          <div class="flex justify-between items-start mb-6">
            <div class="flex items-center">
              <div class="flex-shrink-0 h-12 w-12 rounded-lg bg-purple-100 flex items-center justify-center">
                <svg class="h-6 w-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>
                </svg>
              </div>
              <div class="ml-4">
                <h4 class="text-lg font-medium text-gray-900">{{ deviceGroup.device.label }} ({{ deviceGroup.device.number }})</h4>
                <p class="text-sm text-gray-500">
                  {{ (deviceGroup.device.baitType || deviceGroup.device.bait_type)?.name || 'Sem tipo de isca' }} -
                  {{ deviceGroup.device.default_location_note || 'Sem localização' }}
                </p>
                <p class="text-xs text-gray-400 mt-1">
                  Status:
                  <span :class="deviceGroup.device.active ? 'text-green-600' : 'text-red-600'">
                    {{ deviceGroup.device.active ? 'Ativo' : 'Inativo' }}
                  </span>
                </p>
              </div>
            </div>
          </div>

          <!-- Seção de Eventos do Dispositivo -->
          <div class="border-t pt-6">
            <h5 class="text-sm font-medium text-gray-900 mb-4 flex items-center justify-between">
              <div class="flex items-center">
                <svg class="h-4 w-4 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Eventos Realizados
              </div>
              <button
                @click="openDeviceEventModal(deviceGroup.device.id)"
                class="inline-flex items-center px-2 py-1 text-xs font-medium rounded text-green-600 bg-green-50 hover:bg-green-100 focus:outline-none focus:ring-1 focus:ring-green-500 transition-colors"
              >
                <svg class="h-3 w-3 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
                </svg>
                Adicionar Evento
              </button>
            </h5>

            <div v-if="deviceGroup.events.length > 0" class="space-y-4">
              <div
                v-for="event in deviceGroup.events"
                :key="event.id"
                class="bg-gray-50 rounded-lg p-4 border border-gray-200"
              >
                <div class="flex justify-between items-start">
                  <div class="flex-1">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                      <div>
                        <label class="block text-sm font-medium text-gray-500 mb-1">Tipo de Evento</label>
                        <p class="text-sm text-gray-900">{{ (event.eventType || event.event_type)?.name || 'Não informado' }}</p>
                      </div>
                      <div>
                        <label class="block text-sm font-medium text-gray-500 mb-1">Data do Evento</label>
                        <p class="text-sm text-gray-900">{{ formatDate(event.event_date) }}</p>
                      </div>
                    </div>
                    <div v-if="event.event_description" class="mt-4">
                      <label class="block text-sm font-medium text-gray-500 mb-1">Descrição</label>
                      <p class="text-sm text-gray-900">{{ event.event_description }}</p>
                    </div>
                    <div v-if="event.event_observations" class="mt-4">
                      <label class="block text-sm font-medium text-gray-500 mb-1">Observações</label>
                      <p class="text-sm text-gray-900">{{ event.event_observations }}</p>
                    </div>
                  </div>
                  <div class="flex space-x-2 ml-4">
                    <button
                      @click="openEditDeviceEventModal(event.id, deviceGroup.device.id)"
                      class="text-blue-600 hover:text-blue-800 transition-colors"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                      </svg>
                    </button>
                    <button
                      @click="deleteDeviceEvent(event.id, deviceGroup.device.id)"
                      class="text-red-600 hover:text-red-800 transition-colors"
                    >
                      <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path>
                      </svg>
                    </button>
                  </div>
                </div>
              </div>
            </div>
            <div v-else class="text-sm text-gray-500 italic">
              Nenhum evento registrado para este dispositivo.
            </div>
          </div>
        </div>
      </div>

      <!-- Mensagem quando não há dispositivos -->
      <div v-else class="text-center py-8 text-gray-500">
        <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 3v2m6-2v2M9 19v2m6-2v2M5 9H3m2 6H3m18-6h-2m2 6h-2M7 19h10a2 2 0 002-2V7a2 2 0 00-2-2H7a2 2 0 00-2 2v10a2 2 0 002 2zM9 9h6v6H9V9z"></path>
        </svg>
        <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum dispositivo adicionado</h3>
        <p class="mt-1 text-sm text-gray-500">Esta ordem de serviço não possui dispositivos registrados.</p>
        <button
          @click="showAddDeviceModal = true"
          class="mt-3 inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
        >
          <svg class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path>
          </svg>
          Adicionar Primeiro Dispositivo
        </button>
      </div>

      <!-- Modal para adicionar evento de dispositivo -->
      <div v-if="showDeviceEventModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
          <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Adicionar Evento ao Dispositivo</h3>

            <form @submit.prevent="saveDeviceEvent">
              <div class="space-y-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de Evento *</label>
                  <select
                    v-model="deviceEventForm.event_type"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                    required
                  >
                    <option value="">Selecione o tipo de evento...</option>
                    <option
                      v-for="eventType in props.eventTypes"
                      :key="eventType.id"
                      :value="eventType.id"
                    >
                      {{ eventType.name }}
                    </option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Data do Evento *</label>
                  <input
                    v-model="deviceEventForm.event_date"
                    type="date"
                    required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Descrição</label>
                  <textarea
                    v-model="deviceEventForm.event_description"
                    rows="3"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  ></textarea>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-2">Observações</label>
                  <textarea
                    v-model="deviceEventForm.event_observations"
                    rows="2"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  ></textarea>
                </div>
              </div>

              <div class="flex justify-end space-x-3 mt-6">
                <button
                  type="button"
                  @click="closeDeviceEventModal"
                  class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500"
                >
                  Cancelar
                </button>
                <button
                  type="submit"
                  :disabled="isSavingDeviceEvent"
                  class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  <span v-if="isSavingDeviceEvent">Salvando...</span>
                  <span v-else>Salvar Evento</span>
                </button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal para adicionar dispositivo -->
    <div v-if="showAddDeviceModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
      <div class="relative top-10 mx-auto p-5 border w-11/12 max-w-2xl shadow-lg rounded-md bg-white">
        <div class="mt-3">
          <h3 class="text-lg font-medium text-gray-900 mb-6">Adicionar Dispositivo</h3>

          <div class="space-y-6">
            <!-- Seção de Seleção do Dispositivo -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Selecionar Dispositivo *</label>
              <select
                v-model="newDeviceForm.device_id"
                class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500"
                :class="deviceFormErrors.device_id ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : 'border-gray-300 focus:border-green-500'"
                :disabled="isLoadingAvailableDevices"
              >
                <option value="">Selecione um dispositivo...</option>
                <option
                  v-for="device in availableDevicesForWorkOrder"
                  :key="device.id"
                  :value="device.id"
                >
                  {{ device.label }} ({{ device.number }}) - {{ (device.baitType || device.bait_type)?.name || 'Sem tipo de isca' }}
                </option>
              </select>
              <p v-if="isLoadingAvailableDevices" class="text-sm text-gray-500 mt-1">Carregando dispositivos disponíveis...</p>
              <p v-if="deviceFormErrors.device_id" class="text-sm text-red-600 mt-1">{{ deviceFormErrors.device_id }}</p>
              <p v-if="availableDevicesForWorkOrder.length === 0 && !isLoadingAvailableDevices" class="text-sm text-gray-500 mt-1">
                Não há dispositivos disponíveis no endereço ou todos já foram adicionados.
              </p>
            </div>

            <!-- Seção de Evento -->
            <div class="border border-gray-200 rounded-lg p-4">
              <h4 class="text-md font-medium text-gray-900 mb-3 flex items-center">
                Evento Realizado
              </h4>

              <div class="space-y-3">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de Evento *</label>
                  <select
                    v-model="newDeviceForm.event_type"
                    required
                    class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 text-sm"
                    :class="deviceFormErrors.event_type ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : 'border-gray-300 focus:ring-green-500 focus:border-green-500'"
                  >
                    <option value="">Selecione o tipo...</option>
                    <option
                      v-for="eventType in props.eventTypes"
                      :key="eventType.id"
                      :value="eventType.id"
                    >
                      {{ eventType.name }}
                    </option>
                  </select>
                  <p v-if="deviceFormErrors.event_type" class="text-sm text-red-600 mt-1">{{ deviceFormErrors.event_type }}</p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Data do Evento *</label>
                  <input
                    v-model="newDeviceForm.event_date"
                    type="date"
                    required
                    :max="new Date().toISOString().split('T')[0]"
                    class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 text-sm"
                    :class="deviceFormErrors.event_date ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : 'border-gray-300 focus:ring-green-500 focus:border-green-500'"
                  >
                  <p v-if="deviceFormErrors.event_date" class="text-sm text-red-600 mt-1">{{ deviceFormErrors.event_date }}</p>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Descrição do Evento</label>
                  <textarea
                    v-model="newDeviceForm.event_description"
                    placeholder="Descreva o evento realizado..."
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500 text-sm resize-none"
                    rows="2"
                  ></textarea>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
                  <textarea
                    v-model="newDeviceForm.event_observations"
                    placeholder="Observações adicionais..."
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-green-500 focus:border-green-500 text-sm resize-none"
                    rows="2"
                  ></textarea>
                </div>
              </div>
            </div>
          </div>

          <!-- Mensagem de erro geral -->
          <div v-if="deviceFormErrors.general" class="mt-4 p-3 bg-red-50 border border-red-200 rounded-md">
            <div class="flex">
              <svg class="h-5 w-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
              </svg>
              <div class="ml-3">
                <p class="text-sm text-red-800">{{ deviceFormErrors.general }}</p>
              </div>
            </div>
          </div>

          <!-- Botões de Ação -->
          <div class="flex justify-end space-x-3 mt-6 pt-4 border-t border-gray-200">
            <button
              @click="showAddDeviceModal = false"
              class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500"
              :disabled="isAddingDevice"
            >
              Cancelar
            </button>
            <button
              @click="addDeviceWithEvent"
              class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500 disabled:opacity-50 disabled:cursor-not-allowed"
              :disabled="isAddingDevice"
            >
              <span v-if="isAddingDevice">Adicionando...</span>
              <span v-else>Adicionar Dispositivo</span>
            </button>
          </div>
        </div>
      </div>
    </div>

      <!-- Modal para adicionar cômodo -->
      <div v-if="showAddRoomModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-10 mx-auto p-5 border w-11/12 max-w-4xl shadow-lg rounded-md bg-white">
          <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-6">Adicionar Cômodo</h3>

            <div class="space-y-6">
              <!-- Seção de Seleção do Cômodo -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Selecionar Cômodo *</label>
                <select
                  v-model="newRoomForm.room_id"
                  class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500"
                  :class="roomFormErrors.room_id ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : 'border-gray-300 focus:border-blue-500'"
                  :disabled="isLoadingAvailableRooms"
                >
                  <option value="">Selecione um cômodo...</option>
                  <option
                    v-for="room in availableRooms"
                    :key="room.id"
                    :value="room.id"
                  >
                    {{ room.full_name }}
                  </option>
                </select>
                <p v-if="isLoadingAvailableRooms" class="text-sm text-gray-500 mt-1">Carregando cômodos disponíveis...</p>
                <p v-if="roomFormErrors.room_id" class="text-sm text-red-600 mt-1">{{ roomFormErrors.room_id }}</p>
              </div>
                <!-- Seção de Evento -->
                <div class="border border-gray-200 rounded-lg p-4">
                  <h4 class="text-md font-medium text-gray-900 mb-3 flex items-center">
                    Evento Realizado
                  </h4>

                  <div class="space-y-3">
                    <div>
                      <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de Evento *</label>
                      <select
                        v-model="newRoomForm.event_type"
                        required
                        class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 text-sm"
                        :class="roomFormErrors.event_type ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : 'border-gray-300 focus:ring-blue-500 focus:border-blue-500'"
                      >
                        <option value="">Selecione o tipo...</option>
                        <option
                          v-for="eventType in props.eventTypes"
                          :key="eventType.id"
                          :value="eventType.id"
                        >
                          {{ eventType.name }}
                        </option>
                      </select>
                      <p v-if="roomFormErrors.event_type" class="text-sm text-red-600 mt-1">{{ roomFormErrors.event_type }}</p>
                    </div>

                    <div>
                      <label class="block text-sm font-medium text-gray-700 mb-1">Data do Evento *</label>
                      <input
                        v-model="newRoomForm.event_date"
                        type="date"
                        required
                        :max="new Date().toISOString().split('T')[0]"
                        class="w-full px-3 py-2 border rounded-md shadow-sm focus:outline-none focus:ring-2 text-sm"
                        :class="roomFormErrors.event_date ? 'border-red-500 focus:border-red-500 focus:ring-red-500' : 'border-gray-300 focus:ring-blue-500 focus:border-blue-500'"
                      >
                      <p v-if="roomFormErrors.event_date" class="text-sm text-red-600 mt-1">{{ roomFormErrors.event_date }}</p>
                    </div>

                    <div>
                      <p class="text-xs text-gray-500 mt-1">
                        Este cômodo não possui dispositivos cadastrados
                      </p>
                    </div>

                    <div>
                      <label class="block text-sm font-medium text-gray-700 mb-1">Descrição do Evento</label>
                      <textarea
                        v-model="newRoomForm.event_description"
                        placeholder="Descreva o evento realizado..."
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm resize-none"
                        rows="2"
                      ></textarea>
                    </div>

                    <div>
                      <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
                      <textarea
                        v-model="newRoomForm.event_observations"
                        placeholder="Observações adicionais..."
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm resize-none"
                        rows="2"
                      ></textarea>
                    </div>
                  </div>
                </div>

                <!-- Seção de Avistamento de Praga -->
                <div class="border border-gray-200 rounded-lg p-4">
                  <h4 class="text-md font-medium text-gray-900 mb-3 flex items-center">
                    Avistamento de Praga (opcional)
                  </h4>

                  <div class="space-y-3">
                    <div>
                      <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de Praga</label>
                      <select
                        v-model="newRoomForm.pest_type"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                      >
                        <option value="">Selecione o tipo...</option>
                        <option value="Formiga">Formiga</option>
                        <option value="Barata">Barata</option>
                        <option value="Rato">Rato</option>
                        <option value="Mosca">Mosca</option>
                        <option value="Mosquito">Mosquito</option>
                        <option value="Aranha">Aranha</option>
                        <option value="Cupim">Cupim</option>
                        <option value="Outro">Outro</option>
                      </select>
                    </div>

                    <div>
                      <label class="block text-sm font-medium text-gray-700 mb-1">Data do Avistamento</label>
                      <input
                        v-model="newRoomForm.pest_sighting_date"
                        type="date"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                      >
                    </div>

                    <div>
                      <label class="block text-sm font-medium text-gray-700 mb-1">Localização no Cômodo</label>
                      <input
                        v-model="newRoomForm.pest_location"
                        type="text"
                        placeholder="Ex: perto da janela, embaixo da pia..."
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                      >
                    </div>

                    <div>
                      <label class="block text-sm font-medium text-gray-700 mb-1">Quantidade Observada</label>
                      <input
                        v-model="newRoomForm.pest_quantity"
                        type="number"
                        min="1"
                        placeholder="Ex: 3"
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm"
                      >
                    </div>

                    <div>
                      <label class="block text-sm font-medium text-gray-700 mb-1">Observação</label>
                      <textarea
                        v-model="newRoomForm.pest_observation"
                        placeholder="Observações sobre o avistamento..."
                        class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500 text-sm resize-none"
                        rows="2"
                      ></textarea>
                    </div>
                  </div>
                </div>
              </div>
            </div>

            <!-- Mensagem de erro geral -->
            <div v-if="roomFormErrors.general" class="mt-4 p-3 bg-red-50 border border-red-200 rounded-md">
              <div class="flex">
                <svg class="h-5 w-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <div class="ml-3">
                  <p class="text-sm text-red-800">{{ roomFormErrors.general }}</p>
                </div>
              </div>
            </div>

            <!-- Botões de Ação -->
            <div class="flex justify-end space-x-3 mt-6 pt-4 border-t border-gray-200">
              <button
                @click="showAddRoomModal = false"
                class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500"
                :disabled="isAddingRoom"
              >
                Cancelar
              </button>
              <button
                @click="addRoomWithEventAndPest"
                class="px-4 py-2 bg-blue-600 text-white rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-500 disabled:opacity-50 disabled:cursor-not-allowed"
                :disabled="isAddingRoom"
              >
                <span v-if="isAddingRoom">Adicionando...</span>
                <span v-else>Adicionar Cômodo</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal para Evento do Cômodo -->
      <div v-if="showRoomEventModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
          <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">
              {{ selectedRoomForEvent && props.workOrder.rooms?.find(r => r.id === selectedRoomForEvent)?.pivot?.event_type_id ? 'Editar Evento' : 'Adicionar Evento' }}
            </h3>

            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de Evento *</label>
                <select
                  v-model="roomEventForm.event_type"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  required
                >
                  <option value="">Selecione o tipo de evento...</option>
                  <option
                    v-for="eventType in props.eventTypes"
                    :key="eventType.id"
                    :value="eventType.id"
                  >
                    {{ eventType.name }}
                  </option>
                </select>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Data do Evento *</label>
                <input
                  v-model="roomEventForm.event_date"
                  type="date"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  required
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Descrição do Evento</label>
                <textarea
                  v-model="roomEventForm.event_description"
                  rows="3"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  placeholder="Descreva o evento realizado..."
                ></textarea>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Observações</label>
                <textarea
                  v-model="roomEventForm.event_observations"
                  rows="3"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  placeholder="Observações adicionais..."
                ></textarea>
              </div>
            </div>

            <div class="flex justify-end space-x-3 mt-6">
              <button
                @click="showRoomEventModal = false"
                class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500"
              >
                Cancelar
              </button>
              <button
                @click="saveRoomEvent"
                class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500"
                :disabled="!roomEventForm.event_type || !roomEventForm.event_date"
              >
                Salvar Evento
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal para Avistamento de Praga do Cômodo -->
      <div v-if="showRoomPestSightingModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
        <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
          <div class="mt-3">
            <h3 class="text-lg font-medium text-gray-900 mb-4">
              {{ selectedRoomForPestSighting && props.workOrder.rooms?.find(r => r.id === selectedRoomForPestSighting)?.pivot?.pest_type ? 'Editar Avistamento' : 'Adicionar Avistamento' }}
            </h3>

            <div class="space-y-4">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de Praga *</label>
                <select
                  v-model="roomPestSightingForm.pest_type"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  required
                >
                  <option value="">Selecione o tipo de praga...</option>
                  <option value="cockroach">Barata</option>
                  <option value="ant">Formiga</option>
                  <option value="mouse">Rato</option>
                  <option value="fly">Mosca</option>
                  <option value="mosquito">Mosquito</option>
                  <option value="spider">Aranha</option>
                  <option value="termite">Cupim</option>
                  <option value="other">Outros</option>
                </select>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Data do Avistamento *</label>
                <input
                  v-model="roomPestSightingForm.pest_sighting_date"
                  type="date"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  required
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Localização no Cômodo</label>
                <input
                  v-model="roomPestSightingForm.pest_location"
                  type="text"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  placeholder="Ex: Perto da janela, embaixo da mesa..."
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Quantidade Observada</label>
                <input
                  v-model="roomPestSightingForm.pest_quantity"
                  type="number"
                  min="1"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  placeholder="Ex: 3"
                />
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Observação</label>
                <textarea
                  v-model="roomPestSightingForm.pest_observation"
                  rows="3"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                  placeholder="Observações sobre o avistamento..."
                ></textarea>
              </div>
            </div>

            <div class="flex justify-end space-x-3 mt-6">
              <button
                @click="showRoomPestSightingModal = false"
                class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500"
              >
                Cancelar
              </button>
              <button
                @click="saveRoomPestSighting"
                class="px-4 py-2 bg-orange-600 text-white rounded-md hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500"
                :disabled="!roomPestSightingForm.pest_type || !roomPestSightingForm.pest_sighting_date"
              >
                Salvar Avistamento
              </button>
            </div>
          </div>
        </div>
      </div>

    <!-- Modal para evento do dispositivo -->
    <div v-if="showDeviceEventModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
      <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
          <h3 class="text-lg font-medium text-gray-900 mb-4">
            {{ selectedDeviceForEvent && selectedEventId ? 'Editar Evento' : 'Adicionar Evento' }}
          </h3>

          <div class="space-y-4">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de Evento *</label>
              <select
                v-model="deviceEventFormWO.event_type"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                required
              >
                <option value="">Selecione o tipo de evento...</option>
                <option
                  v-for="eventType in props.eventTypes"
                  :key="eventType.id"
                  :value="eventType.id"
                >
                  {{ eventType.name }}
                </option>
              </select>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Data do Evento *</label>
              <input
                v-model="deviceEventFormWO.event_date"
                type="date"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                required
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Descrição (Opcional)</label>
              <textarea
                v-model="deviceEventFormWO.event_description"
                rows="3"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                placeholder="Descreva o evento..."
              ></textarea>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Observações (Opcional)</label>
              <textarea
                v-model="deviceEventFormWO.event_observations"
                rows="3"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-blue-500 focus:border-blue-500"
                placeholder="Adicione observações sobre o evento..."
              ></textarea>
            </div>
          </div>

          <div class="mt-6 flex justify-end space-x-3">
            <button
              @click="showDeviceEventModal = false; deviceEventFormWO.reset(); selectedEventId = null"
              class="px-4 py-2 border border-gray-300 rounded-md text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
            >
              Cancelar
            </button>
            <button
              @click="saveDeviceEvent"
              :disabled="isSavingDeviceEvent || !deviceEventFormWO.event_type || !deviceEventFormWO.event_date"
              class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50 disabled:cursor-not-allowed"
            >
              <span v-if="isSavingDeviceEvent">Salvando...</span>
              <span v-else>{{ selectedEventId ? 'Atualizar' : 'Adicionar' }}</span>
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Aba: Informações Financeiras -->
    <div v-if="activeTab === 'financial'">
      <div class="flex items-center justify-between mb-6">
        <h3 class="text-lg font-medium text-gray-900">Informações Financeiras</h3>
        <button
          v-if="props.workOrder.payment_status !== 'paid'"
          @click="openEditFinancialModal"
          class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors duration-200"
        >
          <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
          </svg>
          Editar Informações
        </button>
      </div>

      <!-- Resumo Financeiro -->
      <div class="bg-white border border-gray-200 rounded-lg p-6 mb-6 shadow-sm">
        <h4 class="text-lg font-semibold text-gray-900 mb-6 flex items-center">
          <svg class="w-5 h-5 mr-2 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1"></path>
          </svg>
          Resumo Financeiro
        </h4>

        <!-- Resumo Financeiro Compacto -->
        <div class="bg-gradient-to-r from-gray-50 to-blue-50 rounded-lg p-4 border border-gray-200">
          <div class="flex flex-wrap items-center justify-between gap-4">
            <!-- Custo Total -->
            <div class="flex items-center space-x-2">
              <div class="w-8 h-8 bg-gray-200 rounded-full flex items-center justify-center">
                <svg class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
                </svg>
              </div>
              <div>
                <p class="text-xs font-medium text-gray-600">Custo Total</p>
                <p class="text-lg font-bold text-gray-900">R$ {{ formatCurrency(props.workOrder?.total_cost || 0) }}</p>
              </div>
            </div>

            <!-- Desconto (se houver) -->
            <div v-if="props.workOrder?.discount_amount" class="flex items-center space-x-2">
              <div class="w-8 h-8 bg-red-200 rounded-full flex items-center justify-center">
                <svg class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 12H4m16 0a2 2 0 012 2v6a2 2 0 01-2 2H4a2 2 0 01-2-2v-6a2 2 0 012-2m16 0V8a2 2 0 00-2-2H6a2 2 0 00-2 2v4"></path>
                </svg>
              </div>
              <div>
                <p class="text-xs font-medium text-red-600">Desconto</p>
                <p class="text-lg font-bold text-red-700">- R$ {{ formatCurrency(props.workOrder.discount_amount) }}</p>
              </div>
            </div>

            <!-- Valor Final -->
            <div class="flex items-center space-x-2">
              <div class="w-8 h-8 bg-green-200 rounded-full flex items-center justify-center">
                <svg class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
              </div>
              <div>
                <p class="text-xs font-medium text-green-600">Valor Final</p>
                <p class="text-lg font-bold text-green-700">R$ {{ formatCurrency(props.workOrder?.final_amount || props.workOrder?.total_cost || 0) }}</p>
              </div>
            </div>

            <!-- Valor Pago -->
            <div class="flex items-center space-x-2">
              <div class="w-8 h-8 bg-blue-200 rounded-full flex items-center justify-center">
                <svg class="w-4 h-4 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"></path>
                </svg>
              </div>
              <div>
                <p class="text-xs font-medium text-blue-600">Valor Pago</p>
                <p class="text-lg font-bold text-blue-700">R$ {{ formatCurrency(getTotalPaid()) }}</p>
              </div>
            </div>

            <!-- Status de Pagamento -->
            <div class="flex items-center space-x-2">
              <div class="w-8 h-8 bg-gray-200 rounded-full flex items-center justify-center">
                <svg v-if="props.workOrder?.payment_status === 'paid'" class="w-4 h-4 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <svg v-else-if="props.workOrder?.payment_status === 'pending'" class="w-4 h-4 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                <svg v-else-if="props.workOrder?.payment_status === 'overdue'" class="w-4 h-4 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                </svg>
                <svg v-else class="w-4 h-4 text-gray-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
              </div>
              <div>
                <p class="text-xs font-medium text-gray-600">Status</p>
                <span :class="getPaymentStatusBadgeClass(props.workOrder?.payment_status)" class="text-sm font-medium">
                  {{ props.workOrder?.payment_status_text || 'Pendente' }}
                </span>
              </div>
            </div>

            <!-- Valor Pendente (se houver) -->
            <div v-if="getRemainingAmount() > 0" class="flex items-center space-x-2">
              <div class="w-8 h-8 bg-orange-200 rounded-full flex items-center justify-center">
                <svg class="w-4 h-4 text-orange-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L4.082 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                </svg>
              </div>
              <div>
                <p class="text-xs font-medium text-orange-600">Pendente</p>
                <p class="text-lg font-bold text-orange-700">R$ {{ formatCurrency(getRemainingAmount()) }}</p>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Detalhes de Pagamento -->
      <div class="bg-white border border-gray-200 rounded-lg">
        <div class="px-6 py-4 border-b border-gray-200">
          <div class="flex justify-between items-center">
            <h4 class="text-md font-medium text-gray-900">Histórico de Pagamentos</h4>
            <button
              v-if="props.workOrder.payment_status !== 'paid'"
              @click="showAddPaymentModal = true"
              class="inline-flex items-center px-3 py-2 border border-transparent text-sm leading-4 font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-colors duration-200"
            >
              <svg class="w-4 h-4 mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
              </svg>
              Adicionar Pagamento
            </button>
          </div>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data de Vencimento</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data do Pagamento</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Forma de Pagamento</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Valor Pago</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Status</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Observações</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-if="!props.workOrder?.payment_details || props.workOrder?.payment_details.length === 0">
                <td colspan="7" class="px-6 py-12 text-center text-sm text-gray-500">
                  <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                  </svg>
                  <p class="mt-2">Nenhum pagamento registrado</p>
                </td>
              </tr>
              <tr v-for="payment in props.workOrder?.payment_details || []" :key="payment.id">
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  {{ getPaymentDueDate(payment) }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  {{ getPaymentDate(payment) }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  {{ getPaymentMethodText(payment.payment_method) }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                  R$ {{ formatCurrency(payment.amount_paid || 0) }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span :class="getPaymentStatusBadgeClass(payment.payment_status)" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium">
                    {{ payment.payment_status_text || 'Pendente' }}
                  </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-900 max-w-xs truncate">
                  {{ payment.payment_notes || '-' }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium">
                  <button
                    v-if="payment.payment_status === 'pending'"
                    @click="receivePayment(payment)"
                    class="inline-flex items-center px-3 py-1 border border-transparent text-xs font-medium rounded-md text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 mr-2"
                  >
                    Receber Pagamento
                  </button>
                  <button
                    v-if="payment.payment_status === 'paid'"
                    @click="reopenPayment(payment)"
                    class="inline-flex items-center px-3 py-1 border border-transparent text-xs font-medium rounded-md text-white bg-orange-600 hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500 mr-2"
                  >
                    Reabrir Pagamento
                  </button>
                  <button
                    v-if="payment.payment_status !== 'paid'"
                    @click="editPayment(payment)"
                    class="text-green-600 hover:text-green-900 mr-3"
                  >
                    Editar
                  </button>
                  <button
                    v-if="payment.payment_status !== 'paid'"
                    @click="deletePayment(payment.id)"
                    class="text-red-600 hover:text-red-900"
                  >
                    Excluir
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Modal: Adicionar Pagamento -->
    <div v-if="showAddPaymentModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
      <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900">Adicionar Pagamento</h3>
            <button
              @click="showAddPaymentModal = false"
              class="text-gray-400 hover:text-gray-600"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>
          </div>

          <form @submit.prevent="submitPayment">
            <div class="space-y-4">
              <!-- Informações de Pagamento -->
              <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <h4 class="text-md font-semibold text-gray-900 mb-3">Informações de Pagamento</h4>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Data de Vencimento *</label>
                    <input
                      v-model="paymentForm.payment_due_date"
                      type="date"
                      required
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                    />
                  </div>

                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Forma de Pagamento</label>
                    <select
                      v-model="paymentForm.payment_method"
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                    >
                      <option value="">Selecione a forma de pagamento</option>
                      <option value="pix">PIX</option>
                      <option value="credit_card">Cartão de Crédito</option>
                      <option value="debit_card">Cartão de Débito</option>
                      <option value="boleto">Boleto</option>
                      <option value="cash">Dinheiro</option>
                      <option value="bank_transfer">Transferência Bancária</option>
                    </select>
                  </div>
                </div>

                <div class="mt-4">
                  <label class="block text-sm font-medium text-gray-700 mb-1">Valor Devido *</label>
                  <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                      <span class="text-gray-500 sm:text-sm">R$</span>
                    </div>
                    <input
                      :value="paymentForm.amount_paid"
                      @input="formatPaymentCurrencyField($event, 'amount_paid')"
                      type="text"
                      required
                      class="mt-1 block w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                      placeholder="0,00"
                    />
                  </div>
                </div>

                <div class="mt-4">
                  <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
                  <textarea
                    v-model="paymentForm.payment_notes"
                    rows="3"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                    placeholder="Observações sobre o pagamento..."
                  ></textarea>
                </div>
              </div>

              <!-- Informações de Pagamento -->

            </div>

            <div class="flex justify-end space-x-3 mt-6">
              <button
                type="button"
                @click="showAddPaymentModal = false"
                class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
              >
                Cancelar
              </button>
              <button
                type="submit"
                :disabled="isSubmittingPayment"
                class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50"
              >
                {{ isSubmittingPayment ? 'Salvando...' : 'Adicionar Pagamento' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal: Receber Pagamento -->
    <div v-if="showReceivePaymentModal && selectedPayment" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
      <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900">Receber Pagamento</h3>
            <button
              @click="showReceivePaymentModal = false"
              class="text-gray-400 hover:text-gray-600"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>
          </div>

          <form @submit.prevent="confirmReceivePayment">
            <div class="space-y-4">
              <!-- Informações do Pagamento -->
              <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <h4 class="text-md font-semibold text-gray-900 mb-3">Dados do Pagamento</h4>

                <div class="space-y-3">
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Data de Vencimento</label>
                    <p class="text-sm text-gray-900">{{ selectedPayment.payment_due_date ? formatDateForDisplay(selectedPayment.payment_due_date) : '-' }}</p>
                  </div>

                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Valor Devido</label>
                    <p class="text-sm text-gray-900 font-semibold">R$ {{ formatCurrency(selectedPayment.amount_paid) }}</p>
                  </div>

                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Forma de Pagamento</label>
                    <p class="text-sm text-gray-900">{{ selectedPayment.payment_method_text || '-' }}</p>
                  </div>
                </div>
              </div>

              <!-- Data do Pagamento -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Data do Pagamento *</label>
                <input
                  v-model="receivePaymentForm.payment_date"
                  type="date"
                  required
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                />
              </div>

              <!-- Valor Recebido -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Valor Recebido *</label>
                <input
                  :value="receivePaymentForm.amount_received"
                  @input="formatReceivePaymentCurrencyField"
                  type="text"
                  required
                  placeholder="0,00"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                />
                <p class="mt-1 text-xs text-gray-500">
                  Valor restante: <span class="font-semibold text-orange-600">{{ formatCurrency(getPaymentRemainingAmount()) }}</span>
                </p>
                <div v-if="getPaymentRemainingAmount() > 0" class="mt-2 p-2 bg-orange-50 border border-orange-200 rounded-md">
                  <p class="text-xs text-orange-700">
                    <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.732-.833-2.5 0L4.268 15.5c-.77.833.192 2.5 1.732 2.5z" />
                    </svg>
                    <strong>Pagamento Parcial:</strong> Será criada uma nova parcela de {{ formatCurrency(getPaymentRemainingAmount()) }} com status "Pendente"
                  </p>
                </div>
              </div>

              <!-- Forma de Pagamento -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Forma de Pagamento *</label>
                <select
                  v-model="receivePaymentForm.payment_method"
                  required
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                >
                  <option value="">Selecione a forma de pagamento</option>
                  <option value="pix">PIX</option>
                  <option value="credit_card">Cartão de Crédito</option>
                  <option value="debit_card">Cartão de Débito</option>
                  <option value="boleto">Boleto Bancário</option>
                  <option value="cash">Dinheiro</option>
                  <option value="bank_transfer">Transferência Bancária</option>
                </select>
              </div>

              <!-- Observações -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
                <textarea
                  v-model="receivePaymentForm.payment_notes"
                  rows="3"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                  placeholder="Observações sobre o recebimento..."
                ></textarea>
              </div>
            </div>

            <div class="flex justify-end space-x-3 mt-6">
              <button
                type="button"
                @click="showReceivePaymentModal = false"
                class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
              >
                Cancelar
              </button>
              <button
                type="submit"
                :disabled="isSubmittingReceivePayment || !isValidReceivePaymentAmount"
                class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50"
              >
                {{ isSubmittingReceivePayment ? 'Recebendo...' : 'Confirmar Recebimento' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal: Editar Pagamento -->
    <div v-if="showEditPaymentModal && selectedPayment" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
      <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900">Editar Pagamento</h3>
            <button
              @click="showEditPaymentModal = false"
              class="text-gray-400 hover:text-gray-600"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>
          </div>

          <form @submit.prevent="updatePayment">
            <div class="space-y-4">
              <!-- Informações de Pagamento -->
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Data de Vencimento</label>
                  <input
                    v-model="editPaymentForm.payment_due_date"
                    type="date"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                  />
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Data do Pagamento</label>
                  <input
                    v-model="editPaymentForm.payment_date"
                    type="date"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                  />
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Forma de Pagamento</label>
                  <select
                    v-model="editPaymentForm.payment_method"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                  >
                    <option value="">Selecione uma forma</option>
                    <option value="pix">PIX</option>
                    <option value="credit_card">Cartão de Crédito</option>
                    <option value="debit_card">Cartão de Débito</option>
                    <option value="boleto">Boleto Bancário</option>
                    <option value="cash">Dinheiro</option>
                    <option value="bank_transfer">Transferência Bancária</option>
                  </select>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Valor Pago *</label>
                  <div class="relative">
                    <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                      <span class="text-gray-500 sm:text-sm">R$</span>
                    </div>
                    <input
                      :value="editPaymentForm.amount_paid"
                      @input="formatEditPaymentCurrencyField($event, 'amount_paid')"
                      type="text"
                      required
                      class="mt-1 block w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                      placeholder="0,00"
                    />
                  </div>
                </div>

                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">Status do Pagamento</label>
                  <select
                    v-model="editPaymentForm.payment_status"
                    class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                  >
                    <option value="pending">Pendente</option>
                    <option value="paid">Pago</option>
                    <option value="partial">Parcial</option>
                    <option value="overdue">Vencido</option>
                  </select>
                </div>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
                <textarea
                  v-model="editPaymentForm.payment_notes"
                  rows="3"
                  class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                  placeholder="Observações sobre o pagamento..."
                ></textarea>
              </div>

              <div class="flex items-center">
                <input
                  v-model="editPaymentForm.is_partial_payment"
                  type="checkbox"
                  class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded"
                />
                <label class="ml-2 block text-sm text-gray-900">
                  Pagamento parcial
                </label>
              </div>
            </div>

            <div class="flex justify-end space-x-3 mt-6">
              <button
                type="button"
                @click="showEditPaymentModal = false"
                class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
              >
                Cancelar
              </button>
              <button
                type="submit"
                :disabled="isSubmittingPayment"
                class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50"
              >
                {{ isSubmittingPayment ? 'Atualizando...' : 'Atualizar Pagamento' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal: Editar Informações Financeiras -->
    <div v-if="showEditFinancialModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
      <div class="relative top-20 mx-auto p-5 border w-96 shadow-lg rounded-md bg-white">
        <div class="mt-3">
          <div class="flex items-center justify-between mb-4">
            <h3 class="text-lg font-medium text-gray-900">Editar Informações Financeiras</h3>
            <button
              @click="showEditFinancialModal = false"
              class="text-gray-400 hover:text-gray-600"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>
          </div>

          <form @submit.prevent="submitFinancialInfo">
            <div class="space-y-4">
              <!-- Informações Financeiras Básicas -->
              <div class="bg-gray-50 rounded-lg p-4 border border-gray-200">
                <h4 class="text-md font-semibold text-gray-900 mb-3">Informações Financeiras</h4>

                <div class="grid grid-cols-1 gap-4">
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Custo Total *</label>
                    <div class="relative">
                      <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <span class="text-gray-500 sm:text-sm">R$</span>
                      </div>
                      <input
                        v-model="editFinancialForm.total_cost"
                        @input="formatCurrencyField($event, 'total_cost')"
                        type="text"
                        required
                        class="mt-1 block w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        placeholder="0,00"
                      />
                    </div>
                  </div>

                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Desconto</label>
                    <div class="relative">
                      <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <span class="text-gray-500 sm:text-sm">R$</span>
                      </div>
                      <input
                        v-model="editFinancialForm.discount_amount"
                        @input="formatCurrencyField($event, 'discount_amount')"
                        type="text"
                        class="mt-1 block w-full pl-10 rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                        placeholder="0,00"
                      />
                    </div>
                  </div>

                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Valor Final</label>
                    <div class="relative">
                      <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                        <span class="text-gray-500 sm:text-sm">R$</span>
                      </div>
                      <input
                        v-model="editFinancialForm.final_amount"
                        type="text"
                        readonly
                        class="mt-1 block w-full pl-10 rounded-md border-gray-300 shadow-sm bg-gray-50 text-gray-600 cursor-not-allowed"
                        placeholder="0,00"
                      />
                    </div>
                    <p class="mt-1 text-xs text-gray-500">
                      Calculado automaticamente (Custo Total - Desconto)
                    </p>
                  </div>

                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Data de Vencimento</label>
                    <input
                      v-model="editFinancialForm.payment_due_date"
                      type="date"
                      class="mt-1 block w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm"
                    />
                  </div>
                </div>
              </div>
            </div>

            <div class="flex justify-end space-x-3 mt-6">
              <button
                type="button"
                @click="showEditFinancialModal = false"
                class="px-4 py-2 border border-gray-300 rounded-md shadow-sm text-sm font-medium text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
              >
                Cancelar
              </button>
              <button
                type="submit"
                :disabled="isSubmittingPayment"
                class="px-4 py-2 border border-transparent rounded-md shadow-sm text-sm font-medium text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 disabled:opacity-50"
              >
                {{ isSubmittingPayment ? 'Salvando...' : 'Salvar Informações' }}
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Toast Notification -->
    <div v-if="showToast" class="fixed top-4 right-4 z-50">
      <div :class="{
        'bg-green-500 text-white': toastType === 'success',
        'bg-red-500 text-white': toastType === 'error',
        'bg-yellow-500 text-white': toastType === 'warning'
      }" class="px-6 py-3 rounded-lg shadow-lg max-w-sm">
        <div class="flex items-center space-x-2">
          <svg v-if="toastType === 'success'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
          </svg>
          <svg v-else-if="toastType === 'error'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
          <svg v-else-if="toastType === 'warning'" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
          </svg>
          <span class="font-medium">{{ toastMessage }}</span>
        </div>
      </div>
    </div>

    <!-- Modal para visualizar Avistamento de Praga -->
    <div v-if="showViewPestSightingModal" class="fixed inset-0 bg-black bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4">
      <div class="relative bg-white rounded-xl shadow-xl max-w-3xl w-full mx-4 transform transition-all max-h-[85vh] overflow-hidden">
        <div class="p-6 overflow-y-auto max-h-[85vh]">
          <div class="flex items-center justify-between mb-6">
            <h3 class="text-xl font-semibold text-gray-900">Visualizar Avistamento de Praga</h3>
            <button
              @click="showViewPestSightingModal = false"
              class="text-gray-400 hover:text-gray-600 transition-colors"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>
          </div>

          <div v-if="selectedPestSighting" class="grid grid-cols-2 gap-6">
            <!-- Linha 1 -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Tipo de Praga</label>
              <p class="text-sm text-gray-900 whitespace-pre-line break-words">{{ getPestSightingTypeText(selectedPestSighting.pest_type) }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Nível de Severidade</label>
              <p class="text-sm text-gray-900 whitespace-pre-line break-words">{{ getSeverityLevelText(selectedPestSighting.severity_level) }}</p>
            </div>

            <!-- Linha 2 -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Data do Avistamento</label>
              <p class="text-sm text-gray-900 whitespace-pre-line break-words">{{ formatDateTime(selectedPestSighting.sighting_date) }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Localização</label>
              <p class="text-sm text-gray-900 whitespace-pre-line break-words">{{ selectedPestSighting.location_description }}</p>
            </div>

            <!-- Linhas seguintes (opcionais, lado a lado quando existirem) -->
            <div v-if="selectedPestSighting.description">
              <label class="block text-sm font-medium text-gray-700 mb-1">Descrição</label>
              <p class="text-sm text-gray-900 whitespace-pre-line break-words">{{ selectedPestSighting.description }}</p>
            </div>
            <div v-if="selectedPestSighting.observations">
              <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
              <p class="text-sm text-gray-900 whitespace-pre-line break-words">{{ selectedPestSighting.observations }}</p>
            </div>

            <div v-if="selectedPestSighting.environmental_conditions">
              <label class="block text-sm font-medium text-gray-700 mb-1">Condições Ambientais</label>
              <p class="text-sm text-gray-900 whitespace-pre-line break-words">{{ selectedPestSighting.environmental_conditions }}</p>
            </div>
            <div v-if="selectedPestSighting.control_measures_applied">
              <label class="block text-sm font-medium text-gray-700 mb-1">Medidas de Controle Aplicadas</label>
              <p class="text-sm text-gray-900 whitespace-pre-line break-words">{{ selectedPestSighting.control_measures_applied }}</p>
            </div>

            <div v-if="selectedPestSighting.technician_notes">
              <label class="block text-sm font-medium text-gray-700 mb-1">Observações do Técnico</label>
              <p class="text-sm text-gray-900 whitespace-pre-line break-words">{{ selectedPestSighting.technician_notes }}</p>
            </div>
          </div>

          <div class="flex justify-end pt-4">
            <button
              @click="showViewPestSightingModal = false"
              class="px-6 py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 font-medium transition-colors"
            >
              Fechar
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal para editar Avistamento de Praga -->
    <div v-if="showEditPestSightingModal" class="fixed inset-0 bg-black bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4">
      <div class="relative bg-white rounded-xl shadow-xl max-w-3xl w-full mx-4 transform transition-all max-h-[85vh] overflow-hidden">
        <div class="p-6 overflow-y-auto max-h-[85vh]">
          <div class="flex items-center justify-between mb-6">
            <h3 class="text-xl font-semibold text-gray-900">Editar Avistamento de Praga</h3>
            <button
              @click="showEditPestSightingModal = false"
              class="text-gray-400 hover:text-gray-600 transition-colors"
            >
              <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
              </svg>
            </button>
          </div>

          <form @submit.prevent="updatePestSighting" class="space-y-6">
            <div class="grid grid-cols-2 gap-4">
              <!-- Tipo de Praga -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Tipo de Praga *</label>
                <select
                  v-model="editPestSightingForm.pest_type"
                  required
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                >
                  <option value="">Selecione o tipo de praga</option>
                  <option value="cockroaches">Baratas</option>
                  <option value="ants">Formigas</option>
                  <option value="rats">Ratos</option>
                  <option value="mice">Camundongos</option>
                  <option value="flies">Moscas</option>
                  <option value="mosquitoes">Mosquitos</option>
                  <option value="spiders">Aranhas</option>
                  <option value="termites">Cupins</option>
                  <option value="beetles">Besouros</option>
                  <option value="moths">Traças</option>
                  <option value="wasps">Vespas</option>
                  <option value="other">Outros</option>
                </select>
              </div>

              <!-- Nível de Severidade -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Nível de Severidade *</label>
                <select
                  v-model="editPestSightingForm.severity_level"
                  required
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                >
                  <option value="">Selecione a severidade</option>
                  <option value="low">Baixa</option>
                  <option value="medium">Média</option>
                  <option value="high">Alta</option>
                  <option value="critical">Crítica</option>
                </select>
              </div>

              <!-- Data do Avistamento -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Data do Avistamento *</label>
                <input
                  type="datetime-local"
                  v-model="editPestSightingForm.sighting_date"
                  required
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                />
              </div>

              <!-- Localização -->
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-2">Localização *</label>
                <input
                  type="text"
                  v-model="editPestSightingForm.location_description"
                  required
                  class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  placeholder="Descrição da localização"
                />
              </div>
            </div>

            <!-- Descrição -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Descrição</label>
              <textarea
                v-model="editPestSightingForm.description"
                rows="2"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                placeholder="Descrição detalhada do avistamento..."
              ></textarea>
            </div>

            <!-- Observações -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Observações</label>
              <textarea
                v-model="editPestSightingForm.observations"
                rows="2"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                placeholder="Observações gerais..."
              ></textarea>
            </div>

            <!-- Condições Ambientais -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Condições Ambientais</label>
              <textarea
                v-model="editPestSightingForm.environmental_conditions"
                rows="2"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                placeholder="Condições do ambiente (umidade, temperatura, etc.)..."
              ></textarea>
            </div>

            <!-- Medidas de Controle Aplicadas -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Medidas de Controle Aplicadas</label>
              <textarea
                v-model="editPestSightingForm.control_measures_applied"
                rows="2"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                placeholder="Medidas aplicadas para controle..."
              ></textarea>
            </div>

            <!-- Observações do Técnico -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">Observações do Técnico</label>
              <textarea
                v-model="editPestSightingForm.technician_notes"
                rows="3"
                class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-500 focus:border-green-500"
                placeholder="Observações técnicas..."
              ></textarea>
            </div>

            <!-- Botões -->
            <div class="flex justify-end space-x-4 pt-4">
              <button
                type="button"
                @click="showEditPestSightingModal = false"
                class="px-6 py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 font-medium transition-colors"
              >
                Cancelar
              </button>
              <button
                type="submit"
                :disabled="isUpdatingPestSighting"
                class="px-6 py-3 bg-blue-600 text-white rounded-lg hover:bg-blue-700 font-medium transition-colors disabled:opacity-50 disabled:cursor-not-allowed"
              >
                <span v-if="isUpdatingPestSighting">Salvando...</span>
                <span v-else>Salvar Alterações</span>
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
</template>

<script setup>
import { ref, computed, nextTick, watch } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';

const props = defineProps({
  workOrder: {
    type: Object,
    required: true
  },
  activeTab: {
    type: String,
    required: true
  },
  availableProducts: {
    type: Array,
    default: () => []
  },
  availableServices: {
    type: Array,
    default: () => []
  },
  availableAddresses: {
    type: Array,
    default: () => []
  },
  availableTechnicians: {
    type: Array,
    default: () => []
  },
  eventTypes: {
    type: Array,
    default: () => []
  }
});


// Estado do modal
const isSubmitting = ref(false);
const showPestSightingModal = ref(false);
const isSubmittingPestSighting = ref(false);

// Estado dos modais de produtos e serviços
const showProductModal = ref(false);
const showServiceModal = ref(false);
const showTechnicianModal = ref(false);
const isSubmittingProduct = ref(false);
const isSubmittingService = ref(false);
const isSubmittingTechnician = ref(false);

// Estado dos modais para editar produtos/serviços na OS
const showEditProductModal = ref(false);
const showEditServiceModal = ref(false);
const isUpdatingProduct = ref(false);
const isUpdatingService = ref(false);
const selectedProduct = ref(null);
const selectedService = ref(null);

// Estado dos modais de pagamento
const showAddPaymentModal = ref(false);
const showEditPaymentModal = ref(false);
const showReceivePaymentModal = ref(false);
const showEditFinancialModal = ref(false);
const selectedPayment = ref(null);
const isSubmittingPayment = ref(false);
const isSubmittingReceivePayment = ref(false);

// Computed para validar se o valor do pagamento é válido
const isValidReceivePaymentAmount = computed(() => {
  if (!receivePaymentForm.amount_received) {
    return false;
  }

  const amount = parseCurrencyValue(receivePaymentForm.amount_received);
  return amount > 0;
});

// Formulário do evento de dispositivo
const deviceEventForm = useForm({
  event_type: '',
  device_id: '',
  event_date: '',
  description: '',
  observations: '',
  work_order_id: props.workOrder.id,
  bait_consumption_status: '',
  bait_consumption_quantity: '',
  cleaning_done: '',
  cleaning_notes: '',
  bait_change_type: '',
  bait_change_lot: '',
  bait_change_quantity: '',
  technician_notes: ''
});

// Formulário do avistamento de praga
const pestSightingForm = useForm({
  pest_type: '',
  severity_level: '',
  sighting_date: '',
  location_description: '',
  description: '',
  observations: '',
  environmental_conditions: '',
  control_measures_applied: '',
  technician_notes: '',
  work_order_id: props.workOrder.id,
  address_id: props.workOrder.address_id // Usar automaticamente o endereço da OS
});

// Formulários para produtos e serviços
const productForm = useForm({
  product_id: '',
  quantity: 1,
  unit: '',
  observations: ''
});

const serviceForm = useForm({
  service_id: '',
  observations: ''
});

const technicianForm = useForm({
  technician_id: '',
  is_primary: false
});

// Formulários para editar produtos/serviços na OS
const editProductForm = useForm({
  quantity: 1,
  unit: '',
  observations: ''
});

const editServiceForm = useForm({
  observations: ''
});

// Formulários para pagamentos
const paymentForm = useForm({
  work_order_id: props.workOrder.id,
  payment_due_date: '',
  payment_method: '',
  amount_paid: '',
  payment_notes: ''
});

const editPaymentForm = useForm({
  work_order_id: props.workOrder.id,
  payment_due_date: '',
  payment_date: '',
  payment_method: '',
  amount_paid: '',
  payment_notes: '',
  is_partial_payment: false,
  payment_status: ''
});

    const receivePaymentForm = useForm({
      payment_date: '',
      amount_received: '',
      payment_method: '',
      payment_notes: ''
    });

// Formulário para editar informações financeiras básicas
const editFinancialForm = useForm({
  work_order_id: props.workOrder.id,
  total_cost: '',
  discount_amount: '',
  final_amount: '',
  payment_due_date: '',
});

// Função para converter valor formatado para número
const parseCurrencyValue = (value) => {
  if (!value || value === '') return 0;

  // Remove tudo exceto números e vírgula
  const cleanValue = value.toString().replace(/[^\d,]/g, '');
  if (cleanValue === '') return 0;

  // Substitui vírgula por ponto e converte para número
  const numericValue = cleanValue.replace(',', '.');
  return parseFloat(numericValue) || 0;
};

// Função para formatar data para exibição
const formatDateForDisplay = (dateString) => {
  if (!dateString) return '-';
  const date = new Date(dateString);
  return date.toLocaleDateString('pt-BR');
};

// Função para formatar o campo de valor recebido
const formatReceivePaymentCurrencyField = (event) => {
  const input = event.target;
  let value = input.value;

  // Remove tudo que não é número
  value = value.replace(/\D/g, '');

  // Se estiver vazio, define como 0
  if (value === '') {
    receivePaymentForm.amount_received = '';
    return;
  }

  // Converte para centavos e depois para formato brasileiro
  const numericValue = parseInt(value) / 100;
  const formattedValue = numericValue.toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });

  receivePaymentForm.amount_received = formattedValue;
};

// Função para calcular o valor restante do pagamento específico
const getPaymentRemainingAmount = () => {
  if (!selectedPayment.value || !receivePaymentForm.amount_received) {
    return selectedPayment.value?.amount_paid || 0;
  }

  const amountPaid = parseCurrencyValue(receivePaymentForm.amount_received);
  const amountDue = selectedPayment.value.amount_paid;

  return Math.max(0, amountDue - amountPaid);
};

// Função para verificar se a data é futura
const isFutureDate = (dateString) => {
  if (!dateString) return false;
  const selectedDate = new Date(dateString);
  const today = new Date();
  today.setHours(0, 0, 0, 0); // Remove horas para comparar apenas a data
  return selectedDate > today;
};

// Função para determinar se é data de vencimento ou pagamento
const getDateLabel = (dateString, hasPayment = false) => {
  if (!dateString) return 'Data do Pagamento';

  if (hasPayment) {
    return 'Data do Pagamento';
  }

  return isFutureDate(dateString) ? 'Data de Vencimento' : 'Data do Pagamento';
};

// Função para exibir data de vencimento na tabela
const getPaymentDueDate = (payment) => {
  // Se há payment_due_date específico, mostra ele
  if (payment.payment_due_date) {
    return formatDate(payment.payment_due_date);
  }

  // Se não há payment_date, não há vencimento
  if (!payment.payment_date) {
    return '-';
  }

  // Se payment_date é futura, é vencimento
  if (isFutureDate(payment.payment_date)) {
    return formatDate(payment.payment_date);
  }

  // Se payment_date é passada/hoje, não há vencimento
  return '-';
};

// Função para exibir data de pagamento na tabela
const getPaymentDate = (payment) => {
  // Se não há payment_date, não há pagamento
  if (!payment.payment_date) {
    return '-';
  }

  // Se payment_date é futura, não é pagamento ainda
  if (isFutureDate(payment.payment_date)) {
    return '-';
  }

  // Se payment_date é passada/hoje e há valor pago, é pagamento
  if (payment.amount_paid && payment.amount_paid > 0) {
    return formatDate(payment.payment_date);
  }

  return '-';
};

// Função para calcular o status do pagamento
const calculatePaymentStatus = (amountPaid, finalAmount, paymentDate) => {
  const paid = parseCurrencyValue(amountPaid);
  const final = parseCurrencyValue(finalAmount);

  // Se não há valor pago
  if (!paid || paid === 0) {
    // Se data é futura, é vencimento futuro
    if (isFutureDate(paymentDate)) {
      return 'pending';
    }
    // Se data é passada/hoje e não há pagamento, está vencido
    return 'overdue';
  }

  // Se há valor pago
  // Se valor pago >= valor final, está pago
  if (paid >= final) {
    return 'paid';
  }

  // Se valor pago < valor final, é pagamento parcial
  return 'partial';
};


// Função para calcular valor final
const calculateFinalAmount = () => {
  const totalCost = parseCurrencyValue(editFinancialForm.total_cost);
  const discount = parseCurrencyValue(editFinancialForm.discount_amount);
  const finalAmount = totalCost - discount;

  // Formatar o valor final usando a mesma lógica da diretiva
  if (finalAmount <= 0) {
    editFinancialForm.final_amount = '0,00';
  } else {
    editFinancialForm.final_amount = finalAmount.toLocaleString('pt-BR', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2
    });
  }
};

// Watchers para cálculo automático do valor final (com debounce para evitar interferência)
let calculationTimeout = null;
watch(() => editFinancialForm.total_cost, () => {
  clearTimeout(calculationTimeout);
  calculationTimeout = setTimeout(() => {
    calculateFinalAmount();
  }, 100);
});

watch(() => editFinancialForm.discount_amount, () => {
  clearTimeout(calculationTimeout);
  calculationTimeout = setTimeout(() => {
    calculateFinalAmount();
  }, 100);
});

// Função para formatar campos de moeda
const formatCurrencyField = (event, fieldName) => {
  const input = event.target;
  let value = input.value;

  // Remove tudo exceto números e vírgula
  let cleanValue = value.replace(/[^\d,]/g, '');

  if (cleanValue === '') {
    editFinancialForm[fieldName] = '';
    return;
  }

  let amount;

  // Se já tem vírgula, trata como valor decimal
  if (cleanValue.includes(',')) {
    // Substitui vírgula por ponto e converte
    amount = parseFloat(cleanValue.replace(',', '.'));
  } else {
    // Se não tem vírgula, trata como centavos se tiver mais de 2 dígitos
    const numbers = cleanValue.replace(/\D/g, '');
    if (numbers.length <= 2) {
      // Menos de 3 dígitos: trata como reais
      amount = parseFloat(numbers);
    } else {
      // 3+ dígitos: trata como centavos
      amount = parseFloat(numbers) / 100;
    }
  }

  // Formata o valor
  let formatted = amount.toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });

  // Atualiza o campo
  editFinancialForm[fieldName] = formatted;

  // Força a atualização do input
  nextTick(() => {
    input.value = formatted;
  });
};

// Função para formatar campos de moeda no modal de adicionar pagamento
const formatPaymentCurrencyField = (event, fieldName) => {
  const input = event.target;
  let value = input.value;

  console.log('formatPaymentCurrencyField - input value:', value);

  // Remove tudo exceto números
  let numbers = value.replace(/\D/g, '');
  console.log('formatPaymentCurrencyField - numbers only:', numbers);

  if (numbers === '') {
    paymentForm[fieldName] = '';
    return;
  }

  // Converte para centavos e depois para reais
  let amount = parseFloat(numbers) / 100;
  console.log('formatPaymentCurrencyField - amount:', amount);

  // Formata o valor
  let formatted = amount.toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });
  console.log('formatPaymentCurrencyField - formatted:', formatted);

  // Atualiza o campo
  paymentForm[fieldName] = formatted;

  // Força a atualização do input
  nextTick(() => {
    input.value = formatted;
  });
};

// Função para formatar campos de moeda no modal de editar pagamento
const formatEditPaymentCurrencyField = (event, fieldName) => {
  const input = event.target;
  let value = input.value;

  // Remove tudo exceto números
  let numbers = value.replace(/\D/g, '');

  if (numbers === '') {
    editPaymentForm[fieldName] = '';
    return;
  }

  // Converte para centavos e depois para reais
  let amount = parseFloat(numbers) / 100;

  // Formata o valor
  let formatted = amount.toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2
  });

  // Atualiza o campo
  editPaymentForm[fieldName] = formatted;

  // Força a atualização do input
  nextTick(() => {
    input.value = formatted;
  });
};

// Estado para visualizar e editar eventos
const showViewEventModal = ref(false);
const showEditEventModal = ref(false);
const selectedEvent = ref(null);

// Estado para visualizar e editar avistamentos de pragas
const showViewPestSightingModal = ref(false);
const showEditPestSightingModal = ref(false);
const selectedPestSighting = ref(null);
const editEventForm = useForm({
  id: '',
  event_type: '',
  device_id: '',
  event_date: '',
  description: '',
  observations: '',
  bait_consumption_status: '',
  bait_consumption_quantity: '',
  cleaning_done: '',
  cleaning_notes: '',
  bait_change_type: '',
  bait_change_lot: '',
  bait_change_quantity: '',
  technician_notes: ''
});

// Formulário de edição para avistamentos de pragas
const editPestSightingForm = useForm({
  id: '',
  pest_type: '',
  severity_level: '',
  sighting_date: '',
  location_description: '',
  description: '',
  observations: '',
  environmental_conditions: '',
  control_measures_applied: '',
  technician_notes: '',
  work_order_id: '',
  address_id: ''
});

const isUpdating = ref(false);
const isUpdatingPestSighting = ref(false);

// Estado para gerenciar cômodos
const showAddRoomModal = ref(false);
const availableRooms = ref([]);
const isLoadingAvailableRooms = ref(false);
const isAddingRoom = ref(false);
const isRemovingRoom = ref(false);
const editingRoomId = ref(null);
const roomObservations = ref({});

// Estados para validação do modal de adicionar cômodo
const roomFormErrors = ref({
  room_id: '',
  event_type: '',
  event_date: '',
  general: ''
});

// Modais para eventos e avistamentos
const showRoomEventModal = ref(false);
const showRoomPestSightingModal = ref(false);
const selectedRoomForEvent = ref(null);
const selectedRoomForPestSighting = ref(null);

// Formulário para adicionar cômodo
const newRoomForm = useForm({
  room_id: '',
  // Campos do evento (obrigatórios)
  event_type: '',
  event_date: '',
  event_description: '',
  event_observations: '',
  device_id: '',
  // Campos do avistamento de praga (opcionais)
  pest_type: '',
  pest_sighting_date: '',
  pest_location: '',
  pest_quantity: '',
  pest_observation: ''
});

// Formulários para eventos e avistamentos de cômodos
const roomEventForm = useForm({
  event_type: '',
  event_date: '',
  event_description: '',
  event_observations: '',
  device_id: ''
});

// Estados para dispositivos
const showAddDeviceModal = ref(false);
const isLoadingAvailableDevices = ref(false);
const isAddingDevice = ref(false);

// Estados para validação do modal de adicionar dispositivo
const deviceFormErrors = ref({
  device_id: '',
  event_type: '',
  event_date: '',
  general: ''
});

// Formulário para adicionar dispositivo
const newDeviceForm = useForm({
  device_id: '',
  event_type: '',
  event_date: '',
  event_description: '',
  event_observations: ''
});

const roomPestSightingForm = useForm({
  pest_type: '',
  pest_sighting_date: '',
  pest_location: '',
  pest_quantity: '',
  pest_observation: ''
});

// Estado para gerenciar eventos de dispositivos
const showDeviceEventModal = ref(false);
const selectedDeviceForEvent = ref(null);
const selectedEventId = ref(null);
const isSavingDeviceEvent = ref(false);

// Formulário para eventos de dispositivos
const deviceEventFormWO = useForm({
  event_type: '',
  event_date: '',
  event_description: '',
  event_observations: ''
});

// Sistema de Toast
const showToast = ref(false);
const toastMessage = ref('');
const toastType = ref('success'); // success, error, warning

const displayToast = (message, type = 'success') => {
  toastMessage.value = message;
  toastType.value = type;
  showToast.value = true;

  // Auto-hide após 6 segundos (aumentado de 3 para 6)
  setTimeout(() => {
    showToast.value = false;
  }, 6000);
};

// Função para editar um evento existente
const editEvent = (event) => {
  console.log('Editando evento:', event);
  selectedEvent.value = event;

  // Formatar a data para o formato datetime-local
  const eventDate = new Date(event.event_date);
  const formattedDate = eventDate.toISOString().slice(0, 16);

  editEventForm.id = event.id;
  editEventForm.event_type = event.event_type;
  editEventForm.device_id = event.device_id;
  editEventForm.event_date = formattedDate;
  editEventForm.description = event.description || '';
  editEventForm.observations = event.observations || '';
  editEventForm.bait_consumption_status = event.bait_consumption_status || '';
  editEventForm.bait_consumption_quantity = event.bait_consumption_quantity || '';
  editEventForm.cleaning_done = event.cleaning_done;
  editEventForm.cleaning_notes = event.cleaning_notes || '';
  editEventForm.bait_change_type = event.bait_change_type || '';
  editEventForm.bait_change_lot = event.bait_change_lot || '';
  editEventForm.bait_change_quantity = event.bait_change_quantity || '';
  editEventForm.technician_notes = event.technician_notes || '';

  console.log('Formulário preenchido:', editEventForm.data());
  console.log('Descrição:', editEventForm.description);
  console.log('Observações:', editEventForm.observations);
  showEditEventModal.value = true;
};

// Função para atualizar um evento existente
const updateEvent = async () => {
  if (!editEventForm.id) {
    displayToast('Evento inválido para edição.', 'error');
    return;
  }

  if (!editEventForm.event_type || !editEventForm.device_id || !editEventForm.event_date) {
    displayToast('Por favor, preencha todos os campos obrigatórios.', 'error');
    return;
  }

  // Validação específica para cada tipo de evento
  if (editEventForm.event_type === 'bait_consumption' && !editEventForm.bait_consumption_status) {
    displayToast('Por favor, selecione o status do consumo de isca.', 'error');
    return;
  }

  if (editEventForm.event_type === 'cleaning' && editEventForm.cleaning_done === '') {
    displayToast('Por favor, selecione se a limpeza foi realizada.', 'error');
    return;
  }

  if (editEventForm.event_type === 'technician_notes' && !editEventForm.technician_notes) {
    displayToast('Por favor, preencha as observações do técnico.', 'error');
    return;
  }

  isUpdating.value = true;

  try {
    // Preparar dados para envio
    const updateData = {
      event_type: editEventForm.event_type,
      device_id: editEventForm.device_id,
      event_date: editEventForm.event_date,
      description: editEventForm.description || '',
      observations: editEventForm.observations || '',
      bait_consumption_status: editEventForm.bait_consumption_status || '',
      bait_consumption_quantity: editEventForm.bait_consumption_quantity || '',
      cleaning_done: editEventForm.cleaning_done,
      cleaning_notes: editEventForm.cleaning_notes || '',
      bait_change_type: editEventForm.bait_change_type || '',
      bait_change_lot: editEventForm.bait_change_lot || '',
      bait_change_quantity: editEventForm.bait_change_quantity || '',
      technician_notes: editEventForm.technician_notes || '',
      active: true
    };

    console.log('Enviando dados para atualização:', updateData);

    // Usar router.put do Inertia
    router.put(`/device-events/${editEventForm.id}`, updateData, {
      preserveScroll: true,
      onSuccess: () => {
        showEditEventModal.value = false;
        editEventForm.reset();
        selectedEvent.value = null;
        // Recarregar apenas os eventos
        router.reload({ only: ['deviceEvents'] });
      },
      onError: (errors) => {
        displayToast('Erro ao atualizar evento: ' + (errors.message || 'Erro desconhecido'), 'error');
      }
    });
  } catch (error) {
    console.error('Erro na atualização:', error);
    displayToast('Erro inesperado. Tente novamente.', 'error');
  } finally {
    isUpdating.value = false;
  }
};


// Submissão do formulário
const submitDeviceEvent = async () => {
  if (!deviceEventForm.event_type || !deviceEventForm.device_id || !deviceEventForm.event_date) {
    displayToast('Por favor, preencha todos os campos obrigatórios.', 'error');
    return;
  }

  // Validação específica para cada tipo de evento
  if (deviceEventForm.event_type === 'bait_consumption' && !deviceEventForm.bait_consumption_status) {
    displayToast('Por favor, selecione o status do consumo de isca.', 'error');
    return;
  }

  if (deviceEventForm.event_type === 'cleaning' && deviceEventForm.cleaning_done === '') {
    displayToast('Por favor, selecione se a limpeza foi realizada.', 'error');
    return;
  }

  if (deviceEventForm.event_type === 'technician_notes' && !deviceEventForm.technician_notes) {
    displayToast('Por favor, preencha as observações do técnico.', 'error');
    return;
  }

  isSubmitting.value = true;

  try {
    console.log('Enviando dados:', deviceEventForm.data());
    // Usar deviceEventForm.post do Inertia
    deviceEventForm.post('/device-events', {
      preserveScroll: true,
      onSuccess: () => {
        showDeviceEventModal.value = false;
        deviceEventForm.reset();
        router.reload({ only: ['deviceEvents'] });
      },
      onError: (errors) => {
        displayToast('Erro ao criar evento: ' + (errors.message || 'Erro desconhecido'), 'error');
      }
    });
  } catch (error) {
    console.error('Erro na submissão:', error);
    displayToast('Erro inesperado. Tente novamente.', 'error');
  } finally {
    isSubmitting.value = false;
  }
};

const submitPestSighting = async () => {
  if (!pestSightingForm.pest_type || !pestSightingForm.severity_level || !pestSightingForm.sighting_date || !pestSightingForm.location_description) {
    displayToast('Por favor, preencha todos os campos obrigatórios.', 'error');
    return;
  }

  isSubmittingPestSighting.value = true;

  try {
    // Usar pestSightingForm.post do Inertia
    pestSightingForm.post('/pest-sightings', {
      preserveScroll: true,
      onSuccess: () => {
        showPestSightingModal.value = false;
        pestSightingForm.reset();
        router.reload({ only: ['pestSightings'] });
      },
      onError: (errors) => {
        displayToast('Erro ao criar avistamento: ' + (errors.message || 'Erro desconhecido'), 'error');
      }
    });
  } catch (error) {
    console.error('Erro na submissão:', error);
    displayToast('Erro inesperado. Tente novamente.', 'error');
  } finally {
    isSubmittingPestSighting.value = false;
  }
};

// Métodos para submeter produtos e serviços
const addProductToOS = async () => {
  if (!productForm.product_id) {
    displayToast('Por favor, selecione um produto.', 'error');
    return;
  }

  isSubmittingProduct.value = true;

  try {
    const formData = new FormData();
    formData.append('quantity', productForm.quantity);
    formData.append('unit', productForm.unit || '');
    formData.append('observations', productForm.observations || '');
    formData.append('_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

    // Usar router.post do Inertia
    router.post(`/work-orders/${props.workOrder.id}/products/${productForm.product_id}`, formData, {
      preserveScroll: true,
      onSuccess: () => {
        showProductModal.value = false;
        productForm.reset();
        router.reload({ only: ['workOrder'] });
      },
      onError: (errors) => {
        displayToast('Erro ao adicionar produto: ' + (errors.message || 'Erro desconhecido'), 'error');
      }
    });
  } catch (error) {
    console.error('Erro na submissão:', error);
    displayToast('Erro inesperado. Tente novamente.', 'error');
  } finally {
    isSubmittingProduct.value = false;
  }
};

const addServiceToOS = async () => {
  if (!serviceForm.service_id) {
    displayToast('Por favor, selecione um serviço.', 'error');
    return;
  }

  isSubmittingService.value = true;

  try {
    const formData = {
      observations: serviceForm.observations || ''
    };

    // Usar router.post do Inertia
    router.post(`/work-orders/${props.workOrder.id}/services/${serviceForm.service_id}`, formData, {
      preserveScroll: true,
      onSuccess: () => {
        showServiceModal.value = false;
        serviceForm.reset();
        router.reload({ only: ["workOrder"] });
      },
      onError: (errors) => {
        displayToast('Erro ao adicionar serviço: ' + (errors.message || 'Erro desconhecido'), 'error');
      }
    });
  } catch (error) {
    console.error('Erro na submissão:', error);
    displayToast('Erro inesperado. Tente novamente.', 'error');
  } finally {
    isSubmittingService.value = false;
  }
};

// Métodos para gerenciar produtos e serviços na OS
const editProductInOS = (product) => {
  console.log('🔍 editProductInOS chamado:', product);
  selectedProduct.value = product;
  editProductForm.quantity = product.pivot.quantity || 1;
  editProductForm.unit = product.pivot.unit || '';
  editProductForm.observations = product.pivot.observations || '';
  showEditProductModal.value = true;
  console.log('🔍 showEditProductModal.value:', showEditProductModal.value);
};

const editServiceInOS = (service) => {
  console.log('🔍 editServiceInOS chamado:', service);
  selectedService.value = service;
  editServiceForm.observations = service.pivot.observations || '';
  showEditServiceModal.value = true;
  console.log('🔍 showEditServiceModal.value:', showEditServiceModal.value);
};

const updateProductInOS = async () => {
  if (!selectedProduct.value) return;

  isUpdatingProduct.value = true;

  try {
    const formData = {
      quantity: editProductForm.quantity,
      unit: editProductForm.unit || '',
      observations: editProductForm.observations || ''
    };

    // Usar router.put do Inertia
    router.put(`/work-orders/${props.workOrder.id}/products/${selectedProduct.value.id}`, formData, {
      preserveScroll: true,
      onSuccess: () => {
        showEditProductModal.value = false;
        selectedProduct.value = null;
        router.reload({ only: ["workOrder"] });
      },
      onError: (errors) => {
        displayToast('Erro ao atualizar produto: ' + (errors.message || 'Erro desconhecido'), 'error');
      }
    });
  } catch (error) {
    console.error('Erro na atualização:', error);
    displayToast('Erro inesperado. Tente novamente.', 'error');
  } finally {
    isUpdatingProduct.value = false;
  }
};

const updateServiceInOS = async () => {
  if (!selectedService.value) return;

  isUpdatingService.value = true;

  try {
    const formData = {
      observations: editServiceForm.observations || ''
    };

    // Usar router.put do Inertia
    router.put(`/work-orders/${props.workOrder.id}/services/${selectedService.value.id}`, formData, {
      preserveScroll: true,
      onSuccess: () => {
        showEditServiceModal.value = false;
        selectedService.value = null;
        router.reload({ only: ["workOrder"] });
      },
      onError: (errors) => {
        displayToast('Erro ao atualizar serviço: ' + (errors.message || 'Erro desconhecido'), 'error');
      }
    });
  } catch (error) {
    console.error('Erro na atualização:', error);
    displayToast('Erro inesperado. Tente novamente.', 'error');
  } finally {
    isUpdatingService.value = false;
  }
};

const removeProductFromOS = async (product) => {
  if (!confirm(`Tem certeza que deseja remover o produto "${product.name}" desta ordem de serviço?`)) {
    return;
  }

  try {
    // Usar router.delete do Inertia
    router.delete(`/work-orders/${props.workOrder.id}/products/${product.id}`, {
      preserveScroll: true,
      onSuccess: () => {
      },
      onError: (errors) => {
        displayToast('Erro ao remover produto: ' + (errors.message || 'Erro desconhecido'), 'error');
      }
    });
  } catch (error) {
    console.error('Erro na remoção:', error);
    displayToast('Erro inesperado. Tente novamente.', 'error');
  }
};

const removeServiceFromOS = async (service) => {
  if (!confirm(`Tem certeza que deseja remover o serviço "${service.name}" desta ordem de serviço?`)) {
    return;
  }

  try {
    // Usar router.delete do Inertia
    router.delete(`/work-orders/${props.workOrder.id}/services/${service.id}`, {
      preserveScroll: true,
      onSuccess: () => {
      },
      onError: (errors) => {
        displayToast('Erro ao remover serviço: ' + (errors.message || 'Erro desconhecido'), 'error');
      }
    });
  } catch (error) {
    console.error('Erro na remoção:', error);
    displayToast('Erro inesperado. Tente novamente.', 'error');
  }
};

// Função para visualizar um evento existente
const viewEvent = (event) => {
  console.log('Evento selecionado para visualização:', event);
  selectedEvent.value = event;
  showViewEventModal.value = true;
};

// Função para visualizar um avistamento de praga existente
const viewPestSighting = async (sighting) => {
  try {
    const resp = await fetch(`/pest-sightings/${sighting.id}?json=1`, {
      headers: { 'Accept': 'application/json' },
    });
    const data = await resp.json();
    selectedPestSighting.value = data?.pestSighting || sighting;
  } catch (e) {
    selectedPestSighting.value = sighting;
  } finally {
    nextTick(() => {
      showViewPestSightingModal.value = true;
    });
  }
};

// Função para editar um avistamento de praga existente
const editPestSighting = async (sighting) => {
  let full = sighting;
  try {
    const resp = await fetch(`/pest-sightings/${sighting.id}?json=1`, {
      headers: { 'Accept': 'application/json' },
    });
    const data = await resp.json();
    if (data?.pestSighting) full = data.pestSighting;
  } catch (e) {}

  selectedPestSighting.value = full;

  const sightingDate = new Date(full.sighting_date);
  const formattedDate = sightingDate.toISOString().slice(0, 16);

  editPestSightingForm.id = full.id;
  editPestSightingForm.pest_type = full.pest_type;
  editPestSightingForm.severity_level = full.severity_level;
  editPestSightingForm.sighting_date = formattedDate;
  editPestSightingForm.location_description = full.location_description || '';
  editPestSightingForm.description = full.description || '';
  editPestSightingForm.observations = full.observations || '';
  editPestSightingForm.environmental_conditions = full.environmental_conditions || '';
  editPestSightingForm.control_measures_applied = full.control_measures_applied || '';
  editPestSightingForm.technician_notes = full.technician_notes || '';
  editPestSightingForm.work_order_id = props.workOrder.id;
  editPestSightingForm.address_id = props.workOrder.address_id;

  nextTick(() => {
    showEditPestSightingModal.value = true;
  });
};

// Função para atualizar um avistamento de praga existente
const updatePestSighting = async () => {
  if (!editPestSightingForm.id) {
    displayToast('Avistamento inválido para edição.', 'error');
    return;
  }

  if (!editPestSightingForm.pest_type || !editPestSightingForm.severity_level || !editPestSightingForm.sighting_date || !editPestSightingForm.location_description) {
    displayToast('Por favor, preencha todos os campos obrigatórios.', 'error');
    return;
  }

  isUpdatingPestSighting.value = true;

  try {
    // Preparar dados para envio
    const updateData = {
      pest_type: editPestSightingForm.pest_type,
      severity_level: editPestSightingForm.severity_level,
      sighting_date: editPestSightingForm.sighting_date,
      location_description: editPestSightingForm.location_description || '',
      description: editPestSightingForm.description || '',
      observations: editPestSightingForm.observations || '',
      environmental_conditions: editPestSightingForm.environmental_conditions || '',
      control_measures_applied: editPestSightingForm.control_measures_applied || '',
      technician_notes: editPestSightingForm.technician_notes || '',
      work_order_id: editPestSightingForm.work_order_id,
      address_id: editPestSightingForm.address_id,
      active: true
    };

    console.log('Enviando dados para atualização do avistamento:', updateData);

    const response = await fetch(`/pest-sightings/${editPestSightingForm.id}`, {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
      },
      body: JSON.stringify(updateData)
    });

    const result = await response.json();

    if (result.success) {
      displayToast(result.message, 'success');
      showEditPestSightingModal.value = false;
      editPestSightingForm.reset();
      selectedPestSighting.value = null;

      // Aguardar 2 segundos antes de recarregar para permitir ver os logs
      setTimeout(() => {
        router.reload({ only: ["workOrder"] });
      }, 2000);
    } else {
      displayToast('Erro ao atualizar avistamento: ' + result.message, 'error');
    }
  } catch (error) {
    console.error('Erro na atualização do avistamento:', error);
    displayToast('Erro inesperado. Tente novamente.', 'error');
  } finally {
    isUpdatingPestSighting.value = false;
  }
};

// Formatação de datas e valores
const formatDate = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleDateString('pt-BR');
};

const formatDateTime = (date) => {
  if (!date) return '';
  return new Date(date).toLocaleString('pt-BR');
};

const formatCurrency = (value) => {
  if (!value && value !== 0) return '0,00';
  return parseFloat(value).toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
};

// Funções para converter valores em texto legível
const getEventTypeText = (eventTypeId) => {
  // Buscar o tipo de evento na lista de eventTypes
  const eventType = props.eventTypes?.find(et => et.id == eventTypeId);
  return eventType?.name || 'Tipo não encontrado';
};


const getPestTypeText = (pestType) => {
  const types = {
    'cockroach': 'Barata',
    'ant': 'Formiga',
    'spider': 'Aranha',
    'rat': 'Rato',
    'mosquito': 'Mosquito',
    'fly': 'Mosca',
    'termite': 'Cupim',
    'other': 'Outro'
  };
  return types[pestType] || pestType;
};

// Funções para máscara de moeda
const formatCurrencyInput = (value) => {
  if (!value || value === '') return '';

  // Se já é um número, formata diretamente
  if (typeof value === 'number') {
    return value.toLocaleString('pt-BR', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  // Se já está formatado como moeda brasileira (tem vírgula), retorna como está
  if (value.includes(',') && !value.includes('.')) {
    return value;
  }

  // Se tem ponto (formato americano), converte para vírgula
  if (value.includes('.')) {
    const amount = parseFloat(value) || 0;
    return amount.toLocaleString('pt-BR', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  // Remove tudo que não é dígito
  const numbers = value.toString().replace(/\D/g, '');

  if (numbers === '') return '';

  // Se tem mais de 2 dígitos, assume que os últimos 2 são centavos
  if (numbers.length > 2) {
    const amount = parseInt(numbers) / 100;
    return amount.toLocaleString('pt-BR', {
      minimumFractionDigits: 2,
      maximumFractionDigits: 2,
    });
  }

  // Se tem 2 dígitos ou menos, formata como valor inteiro com centavos
  const amount = parseInt(numbers) / 100;
  return amount.toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
};

const parseCurrencyInput = (value) => {
  if (!value || value === '') return '';

  // Se já é um número, retorna como string
  if (typeof value === 'number') {
    return value.toString();
  }

  // Remove tudo exceto dígitos e vírgula
  const cleanValue = value.toString().replace(/[^\d,]/g, '');

  if (cleanValue === '') return '';

  // Se tem vírgula, substitui por ponto para conversão
  if (cleanValue.includes(',')) {
    const normalizedValue = cleanValue.replace(',', '.');
    const amount = parseFloat(normalizedValue) || 0;
    return amount.toString();
  }

  // Se não tem vírgula, trata como centavos se necessário
  // Se tem mais de 2 dígitos, assume que os últimos 2 são centavos
  if (cleanValue.length > 2) {
    const amount = parseInt(cleanValue) / 100;
    return amount.toString();
  }

  // Se tem 2 dígitos ou menos, trata como valor inteiro
  return cleanValue;
};

// Função para lidar com entrada de moeda
const handleCurrencyInput = (event, field) => {
  const value = event.target.value;
  const parsedValue = parseCurrencyInput(value);

  if (field === 'total_cost') {
    editPaymentForm.total_cost = parsedValue;
  } else if (field === 'discount_amount') {
    editPaymentForm.discount_amount = parsedValue;
  } else if (field === 'final_amount') {
    editPaymentForm.final_amount = parsedValue;
  } else if (field === 'amount_paid') {
    editPaymentForm.amount_paid = parsedValue;
  } else if (field === 'payment_total_cost') {
    paymentForm.total_cost = parsedValue;
  } else if (field === 'payment_discount_amount') {
    paymentForm.discount_amount = parsedValue;
  } else if (field === 'payment_final_amount') {
    paymentForm.final_amount = parsedValue;
  } else if (field === 'payment_amount_paid') {
    paymentForm.amount_paid = parsedValue;
  }
};

// Funções para pagamentos
const getTotalPaid = () => {
  if (!props.workOrder?.payment_details) return 0;
  return props.workOrder.payment_details
    .filter(payment => payment.payment_date)
    .reduce((total, payment) => total + (parseFloat(payment.amount_paid) || 0), 0);
};

const getRemainingAmount = () => {
  const finalAmount = props.workOrder?.final_amount || props.workOrder?.total_cost || 0;
  const totalPaid = getTotalPaid();
  return Math.max(0, finalAmount - totalPaid);
};

const getPaymentMethodText = (method) => {
  switch (method) {
    case 'pix': return 'PIX';
    case 'credit_card': return 'Cartão de Crédito';
    case 'debit_card': return 'Cartão de Débito';
    case 'boleto': return 'Boleto Bancário';
    case 'cash': return 'Dinheiro';
    case 'bank_transfer': return 'Transferência Bancária';
    default: return 'Não informado';
  }
};

const getPaymentStatusBadgeClass = (status) => {
  switch (status) {
    case 'pending': return 'bg-yellow-100 text-yellow-800';
    case 'paid': return 'bg-green-100 text-green-800';
    case 'overdue': return 'bg-red-100 text-red-800';
    case 'partial': return 'bg-blue-100 text-blue-800';
    case 'cancelled': return 'bg-gray-100 text-gray-800';
    default: return 'bg-gray-100 text-gray-800';
  }
};

// Funções para manipular pagamentos
const receivePayment = (payment) => {
  selectedPayment.value = payment;

  // Definir data atual como padrão
  const today = new Date().toISOString().split('T')[0];
  receivePaymentForm.payment_date = today;
  receivePaymentForm.amount_received = formatCurrency(payment.amount_paid);
  receivePaymentForm.payment_method = payment.payment_method; // Preencher com a forma original
  receivePaymentForm.payment_notes = '';

  showReceivePaymentModal.value = true;
};

const confirmReceivePayment = async () => {
  if (!selectedPayment.value) return;

  isSubmittingReceivePayment.value = true;

  try {
    const amountReceived = parseCurrencyValue(receivePaymentForm.amount_received);
    const amountDue = selectedPayment.value.amount_paid;
    const remainingAmount = getPaymentRemainingAmount();

    console.log('Valores:', { amountReceived, amountDue, remainingAmount });

    // 1. Atualizar o pagamento atual com o valor recebido
    const updatePaymentData = {
      work_order_id: props.workOrder.id, // Adicionar work_order_id
      payment_date: receivePaymentForm.payment_date,
      amount_paid: amountReceived,
      payment_method: receivePaymentForm.payment_method, // Incluir forma de pagamento
      payment_notes: receivePaymentForm.payment_notes,
      payment_status: 'paid' // Sempre marcamos como 'paid' pois o valor foi efetivamente recebido
    };

    console.log('Atualizando pagamento:', updatePaymentData);

    const updateResponse = await fetch(`/payment-details/${selectedPayment.value.id}`, {
      method: 'PUT',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify(updatePaymentData)
    });

    const updateData = await updateResponse.json();
    console.log('Resposta da atualização:', updateData);

    if (!updateData.success) {
      throw new Error(updateData.message || 'Erro ao atualizar pagamento');
    }

    // 2. O backend já cria automaticamente a parcela pendente quando necessário
    // Não precisamos criar manualmente aqui
    if (remainingAmount > 0) {
      displayToast(`Pagamento parcial recebido! Parcela pendente de ${formatCurrency(remainingAmount)} será criada automaticamente.`, 'success');
    } else {
    }

    showReceivePaymentModal.value = false;
    selectedPayment.value = null;
    receivePaymentForm.reset();
    // Recarregar a página para atualizar os dados
    router.reload({ only: ["workOrder"] });

  } catch (error) {
    console.error('Erro ao receber pagamento:', error);
    displayToast('Erro ao receber pagamento: ' + error.message, 'error');

    // Fechar modal em caso de erro
    showReceivePaymentModal.value = false;
    selectedPayment.value = null;
    receivePaymentForm.reset();
  } finally {
    isSubmittingReceivePayment.value = false;
  }
};

const editPayment = (payment) => {
  selectedPayment.value = payment;

  // Função para formatar data para input
  const formatDateForInput = (dateString) => {
    if (!dateString) return '';
    const date = new Date(dateString);
    return date.toISOString().split('T')[0];
  };

  // Preencher o formulário de edição com os dados do pagamento
  editPaymentForm.payment_due_date = formatDateForInput(payment.payment_due_date);
  editPaymentForm.payment_date = formatDateForInput(payment.payment_date);
  editPaymentForm.payment_method = payment.payment_method || '';
  editPaymentForm.amount_paid = payment.amount_paid || '';
  editPaymentForm.payment_notes = payment.payment_notes || '';
  editPaymentForm.is_partial_payment = payment.is_partial_payment || false;
  editPaymentForm.payment_status = payment.payment_status || 'pending';

  console.log('Editing payment:', payment);
  console.log('Form data:', editPaymentForm.data());

  showEditPaymentModal.value = true;
};

const deletePayment = async (paymentId) => {
  if (!confirm('Tem certeza que deseja excluir este pagamento?')) {
    return;
  }

  try {
    const response = await fetch(`/payment-details/${paymentId}`, {
      method: 'DELETE',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        'Content-Type': 'application/json',
      },
    });

    if (response.ok) {
      // Recarregar a página para atualizar os dados
      router.reload({ only: ["workOrder"] });
    } else {
      const errorData = await response.json();
      throw new Error(errorData.message || 'Erro ao excluir pagamento');
    }
  } catch (error) {
    console.error('Erro ao excluir pagamento:', error);
    displayToast(error.message || 'Erro ao excluir pagamento', 'error');
  }
};

const reopenPayment = async (payment) => {
  if (!confirm(`Tem certeza que deseja reabrir o pagamento de R$ ${formatCurrency(payment.amount_paid)}? O valor será debitado das entradas financeiras.`)) {
    return;
  }

  try {
    const response = await fetch(`/payment-details/${payment.id}/reopen`, {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        'Content-Type': 'application/json',
      },
    });

    if (response.ok) {
      // Recarregar a página para atualizar os dados
      router.reload({ only: ["workOrder"] });
    } else {
      const errorData = await response.json();
      throw new Error(errorData.message || 'Erro ao reabrir pagamento');
    }
  } catch (error) {
    console.error('Erro ao reabrir pagamento:', error);
    displayToast(error.message || 'Erro ao reabrir pagamento', 'error');
  }
};

// Funções para manipular formulários de pagamento
const submitPayment = async () => {
  isSubmittingPayment.value = true;

  try {
    // Sempre criar pagamento como pendente
    const amountPaid = parseCurrencyValue(paymentForm.amount_paid);

    // Para adicionar pagamento, sempre criar como pendente
    const formData = {
      work_order_id: paymentForm.work_order_id,
      payment_due_date: paymentForm.payment_due_date,
      payment_method: paymentForm.payment_method,
      amount_paid: amountPaid,
      payment_notes: paymentForm.payment_notes,
      payment_status: 'pending'
    };

    console.log('Dados do pagamento sendo enviados:', formData);

    const response = await fetch('/payment-details', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify(formData)
    });

    const data = await response.json();

    if (data.success) {
      showAddPaymentModal.value = false;
      paymentForm.reset();
      // Recarregar a página para atualizar os dados
      router.reload({ only: ["workOrder"] });
    } else {
      throw new Error(data.message || 'Erro ao adicionar pagamento');
    }
  } catch (error) {
    console.error('Erro ao adicionar pagamento:', error);
    displayToast('Erro ao adicionar pagamento: ' + error.message, 'error');
  } finally {
    isSubmittingPayment.value = false;
  }
};

const updatePayment = async () => {
  if (!selectedPayment.value) return;

  isSubmittingPayment.value = true;

  try {
    const formData = editPaymentForm.data();

    // Converter valor formatado para número
    if (formData.amount_paid) {
      formData.amount_paid = parseCurrencyValue(formData.amount_paid);
    }

    console.log('Dados sendo enviados:', formData);
    console.log('Payment ID:', selectedPayment.value.id);

    const response = await fetch(`/payment-details/${selectedPayment.value.id}`, {
      method: 'PUT',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify(formData)
    });

    const data = await response.json();
    console.log('Resposta do servidor:', data);

    if (data.success) {
      showEditPaymentModal.value = false;
      selectedPayment.value = null;
      // Recarregar a página para atualizar os dados
      router.reload({ only: ["workOrder"] });
    } else {
      throw new Error(data.message || 'Erro ao atualizar pagamento');
    }
  } catch (error) {
    console.error('Erro ao atualizar pagamento:', error);
    displayToast('Erro ao atualizar pagamento: ' + error.message, 'error');
  } finally {
    isSubmittingPayment.value = false;
  }
};

// Função para abrir modal de edição financeira
const openEditFinancialModal = () => {
  // Inicializar formulário com dados atuais da work order
  editFinancialForm.total_cost = formatCurrency(props.workOrder.total_cost || 0);
  editFinancialForm.discount_amount = formatCurrency(props.workOrder.discount_amount || 0);
  editFinancialForm.final_amount = formatCurrency(props.workOrder.final_amount || 0);
  editFinancialForm.payment_due_date = props.workOrder.payment_due_date || '';

  showEditFinancialModal.value = true;
};

// Função para submeter informações financeiras básicas
const submitFinancialInfo = async () => {
  isSubmittingPayment.value = true;

  try {
    const response = await fetch(`/work-orders/${props.workOrder.id}/financial-info`, {
      method: 'PUT',
      headers: {
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: JSON.stringify({
        total_cost: parseCurrencyValue(editFinancialForm.total_cost),
        discount_amount: parseCurrencyValue(editFinancialForm.discount_amount),
        final_amount: parseCurrencyValue(editFinancialForm.final_amount),
        payment_due_date: editFinancialForm.payment_due_date
      })
    });

    const data = await response.json();

    if (data.success) {
      showEditFinancialModal.value = false;

      // Atualizar os dados do workOrder com os novos valores
      if (data.work_order) {
        // Atualizar propriedades específicas
        props.workOrder.total_cost = data.work_order.total_cost;
        props.workOrder.discount_amount = data.work_order.discount_amount;
        props.workOrder.final_amount = data.work_order.final_amount;
        props.workOrder.payment_due_date = data.work_order.payment_due_date;
      }
    } else {
      throw new Error(data.message || 'Erro ao atualizar informações financeiras');
    }
  } catch (error) {
    console.error('Erro ao atualizar informações financeiras:', error);
    displayToast('Erro ao atualizar informações financeiras: ' + error.message, 'error');
  } finally {
    isSubmittingPayment.value = false;
  }
};

// Função para obter o texto do tipo de evento de dispositivo
const getDeviceEventTypeText = (eventType) => {
  switch (eventType) {
    case 'bait_consumption':
      return 'Consumo de Isca';
    case 'cleaning':
      return 'Limpeza';
    case 'bait_change':
      return 'Troca de Isca';
    case 'technician_notes':
      return 'Observações do Técnico';
    default:
      return eventType; // Retorna o tipo original se não encontrado
  }
};

// Função para obter o texto do status do consumo de isca
const getBaitConsumptionStatusText = (status) => {
  switch (status) {
    case 'partial':
      return 'Parcial';
    case 'total':
      return 'Total';
    case 'none':
      return 'Não houve';
    case 'spoiled':
      return 'Estragada';
    case 'replacement':
      return 'Reposição';
    default:
      return status; // Retorna o status original se não encontrado
  }
};

// Função para obter o texto do tipo de praga de avistamento
const getPestSightingTypeText = (pestType) => {
  switch (pestType) {
    case 'rats':
      return 'Ratos';
    case 'mice':
      return 'Camundongos';
    case 'cockroaches':
      return 'Baratas';
    case 'ants':
      return 'Formigas';
    case 'termites':
      return 'Cupins';
    case 'flies':
      return 'Moscas';
    case 'fleas':
      return 'Pulgas';
    case 'ticks':
      return 'Carrapatos';
    case 'scorpions':
      return 'Escorpiões';
    case 'spiders':
      return 'Aranhas';
    case 'bees':
      return 'Abelhas';
    case 'wasps':
      return 'Vespas';
    case 'other':
      return 'Outros';
    default:
      return pestType; // Retorna o tipo original se não encontrado
  }
};

// Função para obter o texto do nível de severidade
const getSeverityLevelText = (severityLevel) => {
  switch (severityLevel) {
    case 'low':
      return 'Baixa';
    case 'medium':
      return 'Média';
    case 'high':
      return 'Alta';
    case 'critical':
      return 'Crítica';
    default:
      return severityLevel; // Retorna o nível original se não encontrado
  }
};

// Computed properties para produtos e serviços disponíveis
const availableProductsForOS = computed(() => {
  console.log('🔍 Debug availableProductsForOS:');
  console.log('  - props.availableProducts:', props.availableProducts);
  console.log('  - props.workOrder.products:', props.workOrder.products);

  if (!props.availableProducts || !props.workOrder.products) {
    console.log('  - Retornando todos os produtos (sem filtro)');
    return props.availableProducts || [];
  }

  // Filtrar produtos que ainda não estão vinculados à OS
  const linkedProductIds = props.workOrder.products.map(p => p.id);
  const filtered = props.availableProducts.filter(product => !linkedProductIds.includes(product.id));
  console.log('  - Produtos filtrados:', filtered);
  return filtered;
});

const availableServicesForOS = computed(() => {
  console.log('🔍 Debug availableServicesForOS:');
  console.log('  - props.availableServices:', props.availableServices);
  console.log('  - props.workOrder.services:', props.workOrder.services);

  if (!props.availableServices || !props.workOrder.services) {
    console.log('  - Retornando todos os serviços (sem filtro)');
    return props.availableServices || [];
  }

  // Filtrar serviços que ainda não estão vinculados à OS
  const linkedServiceIds = props.workOrder.services.map(s => s.id);
  const filtered = props.availableServices.filter(service => !linkedServiceIds.includes(service.id));
  console.log('  - Serviços filtrados:', filtered);
  return filtered;
});

const availableTechniciansForOS = computed(() => {
  if (!props.availableTechnicians || !props.workOrder.technicians) {
    return props.availableTechnicians || [];
  }

  // Filtrar técnicos que ainda não estão vinculados à OS
  const linkedTechnicianIds = props.workOrder.technicians.map(t => t.id);
  return props.availableTechnicians.filter(technician => !linkedTechnicianIds.includes(technician.id));
});

// Métodos para gerenciar técnicos
const addTechnicianToOS = async () => {
  if (!technicianForm.technician_id) {
    displayToast('Por favor, selecione um técnico.', 'error');
    return;
  }

  isSubmittingTechnician.value = true;

  try {
    const formData = {
      is_primary: technicianForm.is_primary ? 1 : 0
    };

    // Usar router.post do Inertia
    router.post(`/work-orders/${props.workOrder.id}/technicians/${technicianForm.technician_id}`, formData, {
      preserveScroll: true,
      onSuccess: () => {
        showTechnicianModal.value = false;
        technicianForm.reset();
        router.reload({ only: ["workOrder"] });
      },
      onError: (errors) => {
        displayToast('Erro ao adicionar técnico: ' + (errors.message || 'Erro desconhecido'), 'error');
      }
    });
  } catch (error) {
    console.error('Erro ao adicionar técnico:', error);
    displayToast('Erro ao adicionar técnico. Tente novamente.', 'error');
  } finally {
    isSubmittingTechnician.value = false;
  }
};

const removeTechnicianFromOS = async (technician) => {
  if (!confirm(`Tem certeza que deseja remover o técnico "${technician.name}" desta ordem de serviço?`)) {
    return;
  }

  try {
    // Usar router.delete do Inertia
    router.delete(`/work-orders/${props.workOrder.id}/technicians/${technician.id}`, {
      preserveScroll: true,
      onSuccess: () => {
      },
      onError: (errors) => {
        displayToast('Erro ao remover técnico: ' + (errors.message || 'Erro desconhecido'), 'error');
      }
    });
  } catch (error) {
    console.error('Erro ao remover técnico:', error);
    displayToast('Erro ao remover técnico. Tente novamente.', 'error');
  }
};

// Métodos para gerenciar cômodos
const loadAvailableRooms = async () => {
  isLoadingAvailableRooms.value = true;

  try {
    const response = await fetch(`/work-orders/${props.workOrder.id}/rooms/available`, {
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      }
    });

    const result = await response.json();

    if (response.ok) {
      availableRooms.value = result.rooms;
    } else {
      displayToast('Erro ao carregar cômodos disponíveis: ' + (result.message || 'Erro desconhecido'), 'error');
    }
  } catch (error) {
    console.error('Erro ao carregar cômodos disponíveis:', error);
    displayToast('Erro ao carregar cômodos disponíveis: ' + error.message, 'error');
  } finally {
    isLoadingAvailableRooms.value = false;
  }
};


// Função para validar formulário de adicionar cômodo
const validateRoomForm = () => {
  // Limpar erros anteriores
  roomFormErrors.value = {
    room_id: '',
    event_type: '',
    event_date: '',
    general: ''
  };

  let hasErrors = false;

  // Validar cômodo
  if (!newRoomForm.room_id) {
    roomFormErrors.value.room_id = 'Selecione um cômodo';
    hasErrors = true;
  }

  // Validar tipo de evento
  if (!newRoomForm.event_type) {
    roomFormErrors.value.event_type = 'Selecione o tipo de evento';
    hasErrors = true;
  }

  // Validar data do evento
  if (!newRoomForm.event_date) {
    roomFormErrors.value.event_date = 'Selecione a data do evento';
    hasErrors = true;
  } else {
    // Validar se a data não é futura
    const selectedDate = new Date(newRoomForm.event_date);
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    if (selectedDate > today) {
      roomFormErrors.value.event_date = 'A data do evento não pode ser futura';
      hasErrors = true;
    }
  }

  return !hasErrors;
};

const addRoomWithEventAndPest = async () => {
  // Validar formulário
  if (!validateRoomForm()) {
    displayToast('Por favor, corrija os erros no formulário.', 'error');
    return;
  }

  isAddingRoom.value = true;

  // Preparar dados para envio
  const roomData = {
    room_id: parseInt(newRoomForm.room_id),
    event_type: parseInt(newRoomForm.event_type),
    event_date: newRoomForm.event_date,
    event_description: newRoomForm.event_description || '',
    event_observations: newRoomForm.event_observations || '',
    pest_type: newRoomForm.pest_type || '',
    pest_sighting_date: newRoomForm.pest_sighting_date || '',
    pest_location: newRoomForm.pest_location || '',
    pest_quantity: newRoomForm.pest_quantity && newRoomForm.pest_quantity !== '' ? parseInt(newRoomForm.pest_quantity) : null,
    pest_observation: newRoomForm.pest_observation || ''
  };

  // Verificar se os valores convertidos são válidos
  if (isNaN(roomData.room_id) || isNaN(roomData.event_type)) {
    displayToast('Erro: Valores inválidos detectados. Por favor, recarregue a página e tente novamente.', 'error');
    return;
  }

  router.post(`/work-orders/${props.workOrder.id}/rooms`, roomData, {
    preserveScroll: true,
    onSuccess: () => {
      showAddRoomModal.value = false;
      newRoomForm.reset();
      // Limpar erros
      roomFormErrors.value = {
        room_id: '',
        event_type: '',
        event_date: '',
        general: ''
      };
      availableRooms.value = availableRooms.value.filter(room => room.id !== newRoomForm.room_id);
      router.reload({ only: ["workOrder"] });
    },
    onError: (errors) => {
      // Tratar erros de validação do backend
      roomFormErrors.value = {
        room_id: errors.room_id || '',
        event_type: errors.event_type || '',
        event_date: errors.event_date || '',
        general: errors.message || ''
      };
      displayToast('Erro ao adicionar cômodo: ' + (errors.message || 'Erro desconhecido'), 'error');
    },
    onFinish: () => {
      isAddingRoom.value = false;
    }
  });
};

// Função para validar formulário de adicionar dispositivo
const validateDeviceForm = () => {
  // Limpar erros anteriores
  deviceFormErrors.value = {
    device_id: '',
    event_type: '',
    event_date: '',
    general: ''
  };

  let hasErrors = false;

  // Validar dispositivo
  if (!newDeviceForm.device_id) {
    deviceFormErrors.value.device_id = 'Selecione um dispositivo';
    hasErrors = true;
  }

  // Validar tipo de evento
  if (!newDeviceForm.event_type) {
    deviceFormErrors.value.event_type = 'Selecione o tipo de evento';
    hasErrors = true;
  }

  // Validar data do evento
  if (!newDeviceForm.event_date) {
    deviceFormErrors.value.event_date = 'Selecione a data do evento';
    hasErrors = true;
  } else {
    // Validar se a data não é futura
    const selectedDate = new Date(newDeviceForm.event_date);
    const today = new Date();
    today.setHours(0, 0, 0, 0);

    if (selectedDate > today) {
      deviceFormErrors.value.event_date = 'A data do evento não pode ser futura';
      hasErrors = true;
    }
  }

  return !hasErrors;
};

const addDeviceWithEvent = async () => {
  // Validar formulário
  if (!validateDeviceForm()) {
    displayToast('Por favor, corrija os erros no formulário.', 'error');
    return;
  }

  isAddingDevice.value = true;

  // Preparar dados para envio
  const deviceData = {
    event_type: parseInt(newDeviceForm.event_type),
    event_date: newDeviceForm.event_date,
    event_description: newDeviceForm.event_description || '',
    event_observations: newDeviceForm.event_observations || ''
  };

  // Verificar se os valores convertidos são válidos
  if (isNaN(deviceData.event_type)) {
    displayToast('Erro: Valores inválidos detectados. Por favor, recarregue a página e tente novamente.', 'error');
    isAddingDevice.value = false;
    return;
  }

  router.post(`/work-orders/${props.workOrder.id}/devices/${newDeviceForm.device_id}/events`, deviceData, {
    preserveScroll: true,
    onSuccess: () => {
      showAddDeviceModal.value = false;
      newDeviceForm.reset();
      // Limpar erros
      deviceFormErrors.value = {
        device_id: '',
        event_type: '',
        event_date: '',
        general: ''
      };
      displayToast('Dispositivo adicionado com sucesso!', 'success');
      router.reload({ only: ["workOrder"] });
    },
    onError: (errors) => {
      // Tratar erros de validação do backend
      deviceFormErrors.value = {
        device_id: errors.device_id || '',
        event_type: errors.event_type || '',
        event_date: errors.event_date || '',
        general: errors.message || ''
      };
      displayToast('Erro ao adicionar dispositivo: ' + (errors.message || 'Erro desconhecido'), 'error');
    },
    onFinish: () => {
      isAddingDevice.value = false;
    }
  });
};

const removeRoom = async (roomId) => {
  if (!confirm('Tem certeza que deseja remover este cômodo da ordem de serviço?')) {
    return;
  }

  isRemovingRoom.value = true;

  try {
    // Usar router.delete do Inertia
    router.delete(`/work-orders/${props.workOrder.id}/rooms/${roomId}`, {
      preserveScroll: true,
      onError: (errors) => {
        displayToast('Erro ao remover cômodo: ' + (errors.message || 'Erro desconhecido'), 'error');
      }
    });
  } catch (error) {
    console.error('Erro ao remover cômodo:', error);
    displayToast('Erro ao remover cômodo: ' + error.message, 'error');
  } finally {
    isRemovingRoom.value = false;
  }
};

const updateRoomObservation = async (roomId) => {
  const observation = roomObservations.value[roomId];
  editingRoomId.value = roomId;

  try {
    const formData = new FormData();
    formData.append('_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
    formData.append('_method', 'PUT');
    formData.append('observation', observation || '');

    const response = await fetch(`/work-orders/${props.workOrder.id}/rooms/${roomId}/observation`, {
      method: 'POST',
      headers: {
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest'
      },
      body: formData
    });

    const result = await response.json();

    if (response.ok) {
      // Atualizar o valor original para sincronizar o botão
      const room = props.workOrder.rooms.find(r => r.id === roomId);
      if (room && room.pivot) {
        room.pivot.observation = observation;
      }
      router.reload({ only: ["workOrder"] });
    } else {
      displayToast('Erro ao atualizar observação: ' + (result.message || 'Erro desconhecido'), 'error');
    }
  } catch (error) {
    console.error('Erro ao atualizar observação:', error);
    displayToast('Erro ao atualizar observação: ' + error.message, 'error');
  } finally {
    editingRoomId.value = null;
  }
};

// Funções para gerenciar eventos e avistamentos de cômodos
const openRoomEventModal = (roomId) => {
  selectedRoomForEvent.value = roomId;

  // Buscar o cômodo e preencher o formulário se já houver evento
  const room = props.workOrder.rooms?.find(r => r.id === roomId);

  if (room?.pivot?.event_type_id) {
    // Preencher formulário com dados existentes
    roomEventForm.event_type = room.pivot.event_type_id || '';
    roomEventForm.event_date = room.pivot.event_date ? formatDateForInput(room.pivot.event_date) : '';
    roomEventForm.event_description = room.pivot.event_description || '';
    roomEventForm.event_observations = room.pivot.event_observations || '';
  } else {
    // Limpar formulário para novo evento
    roomEventForm.reset();
  }

  showRoomEventModal.value = true;
};

const openRoomPestSightingModal = (roomId) => {
  selectedRoomForPestSighting.value = roomId;

  // Buscar o cômodo e preencher o formulário se já houver avistamento
  const room = props.workOrder.rooms?.find(r => r.id === roomId);
  if (room?.pivot?.pest_type) {
    // Preencher formulário com dados existentes
    roomPestSightingForm.pest_type = room.pivot.pest_type || '';
    roomPestSightingForm.pest_sighting_date = room.pivot.pest_sighting_date ? formatDateForInput(room.pivot.pest_sighting_date) : '';
    roomPestSightingForm.pest_location = room.pivot.pest_location || '';
    roomPestSightingForm.pest_quantity = room.pivot.pest_quantity || '';
    roomPestSightingForm.pest_observation = room.pivot.pest_observation || '';
  } else {
    // Limpar formulário para novo avistamento
    roomPestSightingForm.reset();
  }

  showRoomPestSightingModal.value = true;
};

// Função auxiliar para formatar data para input
const formatDateForInput = (dateString) => {
  if (!dateString) return '';
  const date = new Date(dateString);
  return date.toISOString().split('T')[0];
};

// Funções para salvar eventos e avistamentos
const saveRoomEvent = async () => {
  if (!roomEventForm.event_type || !roomEventForm.event_date) {
    displayToast('Por favor, preencha o tipo e a data do evento.', 'error');
    return;
  }

  try {
    const room = props.workOrder.rooms?.find(r => r.id === selectedRoomForEvent.value);
    const isEditing = room?.pivot?.event_type_id;

    // Garantir que event_type seja enviado
    if (!roomEventForm.event_type) {
      displayToast('Por favor, selecione um tipo de evento.', 'error');
      return;
    }

    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    if (!csrfToken) {
      displayToast('Erro: Token CSRF não encontrado. Por favor, recarregue a página.', 'error');
      return;
    }

    // Preparar dados para envio
    const requestData = {
      event_type: parseInt(roomEventForm.event_type),
      event_date: roomEventForm.event_date,
      event_description: roomEventForm.event_description || '',
      event_observations: roomEventForm.event_observations || '',
    };


    console.log('Sending data:', {
      method: isEditing ? 'PUT' : 'POST',
      url: `/work-orders/${props.workOrder.id}/rooms/${selectedRoomForEvent.value}/event`,
      data: requestData
    });

    // Usar router do Inertia
    const url = route(isEditing ? 'work-orders.rooms.event.update' : 'work-orders.rooms.event.add', {
      workOrder: props.workOrder.id,
      roomId: selectedRoomForEvent.value
    });
    const options = {
      preserveScroll: true,
      onSuccess: () => {
        showRoomEventModal.value = false;
        router.reload({ only: ["workOrder"] });
      },
      onError: (errors) => {
        const message = errors?.message || 'Erro ao salvar evento';
        displayToast(message, 'error');
      }
    };

    if (isEditing) {
      router.put(url, requestData, options);
    } else {
      router.post(url, requestData, options);
    }
  } catch (error) {
    console.error('Erro ao salvar evento:', error);
    displayToast('Erro ao salvar evento: ' + error.message, 'error');
  }
};

const saveRoomPestSighting = async () => {
  if (!roomPestSightingForm.pest_type || !roomPestSightingForm.pest_sighting_date) {
    displayToast('Por favor, preencha o tipo e a data do avistamento.', 'error');
    return;
  }

  try {
    const room = props.workOrder.rooms?.find(r => r.id === selectedRoomForPestSighting.value);
    const isEditing = room?.pivot?.pest_type;

    const formData = new FormData();
    formData.append('_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));

    if (isEditing) {
      formData.append('_method', 'PUT');
    }

    formData.append('pest_type', roomPestSightingForm.pest_type);
    formData.append('pest_sighting_date', roomPestSightingForm.pest_sighting_date);
    formData.append('pest_location', roomPestSightingForm.pest_location || '');
    if (roomPestSightingForm.pest_quantity && roomPestSightingForm.pest_quantity !== '') {
      formData.append('pest_quantity', roomPestSightingForm.pest_quantity);
    }
    formData.append('pest_observation', roomPestSightingForm.pest_observation || '');

    // Usar router.post do Inertia
    const psUrl = route('work-orders.rooms.pest-sighting.add', {
      workOrder: props.workOrder.id,
      roomId: selectedRoomForPestSighting.value
    });
    const psData = Object.fromEntries(formData);
    router.post(psUrl, psData, {
      preserveScroll: true,
      onSuccess: () => {
        showRoomPestSightingModal.value = false;
        router.reload({ only: ["workOrder"] });
      },
      onError: (errors) => {
        const message = errors?.message || 'Erro ao salvar avistamento';
        displayToast(message, 'error');
      }
    });
  } catch (error) {
    console.error('Erro ao salvar avistamento:', error);
    displayToast('Erro ao salvar avistamento: ' + error.message, 'error');
  }
};


// Watch para carregar cômodos disponíveis quando o modal abrir
watch(showAddRoomModal, (newValue) => {
  if (newValue) {
    loadAvailableRooms();
    // Limpar dispositivos e cômodo selecionado quando modal abrir
    // Limpar erros
    roomFormErrors.value = {
      room_id: '',
      event_type: '',
      event_date: '',
      general: ''
    };
  }
});

watch(showAddDeviceModal, (newValue) => {
  if (newValue) {
    // Limpar erros quando modal abrir
    deviceFormErrors.value = {
      device_id: '',
      event_type: '',
      event_date: '',
      general: ''
    };
  }
});


// Watch para inicializar observações quando a tab de cômodos for ativada
watch(() => props.activeTab, (newTab) => {
  if (newTab === 'rooms' && props.workOrder.rooms) {
    // Inicializar as observações dos cômodos
    roomObservations.value = {};
    props.workOrder.rooms.forEach(room => {
      roomObservations.value[room.id] = room.pivot?.observation || '';
    });
  }
});


// Inicializar observações se a tab de cômodos já estiver ativa
if (props.activeTab === 'rooms' && props.workOrder.rooms) {
  roomObservations.value = {};
  props.workOrder.rooms.forEach(room => {
    roomObservations.value[room.id] = room.pivot?.observation || '';
  });
}

// Computed para agrupar eventos de dispositivo por dispositivo
const groupedDeviceEvents = computed(() => {
  // Obter todos os dispositivos da OS (com ou sem eventos)
  const devices = props.workOrder.devices || [];

  // Obter eventos de dispositivos
  const events = props.workOrder.workOrderDeviceEvents || props.workOrder.work_order_device_events || [];

  // Criar mapa de eventos por device_id
  const eventsByDevice = {};
  events.forEach(event => {
    const deviceId = event.device_id;
    if (!eventsByDevice[deviceId]) {
      eventsByDevice[deviceId] = [];
    }
    eventsByDevice[deviceId].push(event);
  });

  // Criar grupos: um para cada dispositivo da OS
  const grouped = {};

  // Adicionar dispositivos que estão na OS
  devices.forEach(device => {
    if (device && device.id) {
      grouped[device.id] = {
        device: device,
        events: eventsByDevice[device.id] || []
      };
    }
  });

  // Também adicionar dispositivos que têm eventos mas não estão na lista de devices
  // (para compatibilidade com dados antigos)
  events.forEach(event => {
    if (event.device && event.device.id && !grouped[event.device.id]) {
      grouped[event.device.id] = {
        device: event.device,
        events: eventsByDevice[event.device.id] || []
      };
    }
  });

  return Object.values(grouped);
});

const availableDevicesForWorkOrder = computed(() => {
  try {
    // Se não houver endereço, retornar array vazio
    if (!props.workOrder || !props.workOrder.address) {
      return [];
    }

    // Se não houver dispositivos no endereço, retornar array vazio
    const addressDevices = props.workOrder.address.devices;
    // Verificar se é array ou Proxy array (Vue 3 usa Proxy)
    if (!addressDevices || (typeof addressDevices.length === 'undefined') || addressDevices.length === 0) {
      return [];
    }

    // Converter para array se necessário (para lidar com Proxy)
    const devicesArray = Array.from(addressDevices);

    // Obter IDs dos dispositivos que já estão na OS
    const devicesInWorkOrder = new Set();
    const workOrderDevices = props.workOrder.devices || [];
    if (workOrderDevices && (typeof workOrderDevices.length !== 'undefined') && workOrderDevices.length > 0) {
      Array.from(workOrderDevices).forEach(device => {
        if (device && device.id) {
          devicesInWorkOrder.add(device.id);
        }
      });
    }

    const workOrderEvents = props.workOrder.workOrderDeviceEvents || props.workOrder.work_order_device_events || [];
    if (workOrderEvents && (typeof workOrderEvents.length !== 'undefined') && workOrderEvents.length > 0) {
      Array.from(workOrderEvents).forEach(event => {
        if (event && event.device_id) {
          devicesInWorkOrder.add(event.device_id);
        } else if (event && event.device && event.device.id) {
          devicesInWorkOrder.add(event.device.id);
        }
      });
    }

    // Filtrar dispositivos que ainda não estão na OS (mostrar apenas ativos)
    return devicesArray.filter(device => {
      if (!device || !device.id) return false;
      const isActive = device.active !== false; // Considerar true se não for explicitamente false
      const notInWorkOrder = !devicesInWorkOrder.has(device.id);
      return isActive && notInWorkOrder;
    });
  } catch (error) {
    console.error('Error in availableDevicesForWorkOrder:', error);
    return [];
  }
});

const openDeviceEventModal = (deviceId, eventId = null) => {
  selectedDeviceForEvent.value = deviceId;
  selectedEventId.value = eventId;

  // Se estiver editando, buscar o evento
  const events = props.workOrder.workOrderDeviceEvents || props.workOrder.work_order_device_events || [];
  if (eventId && events.length > 0) {
    const event = events.find(e => e.id === eventId && e.device_id === deviceId);
    if (event) {
      deviceEventFormWO.event_type = event.event_type_id || event.eventType?.id || event.event_type?.id || '';
      deviceEventFormWO.event_date = event.event_date ? formatDateForInput(event.event_date) : '';
      deviceEventFormWO.event_description = event.event_description || '';
      deviceEventFormWO.event_observations = event.event_observations || '';
    }
  } else {
    deviceEventFormWO.reset();
  }
  showDeviceEventModal.value = true;
};

const openEditDeviceEventModal = (eventId, deviceId) => {
  openDeviceEventModal(deviceId, eventId);
};

const closeDeviceEventModal = () => {
  showDeviceEventModal.value = false;
  selectedDeviceForEvent.value = null;
  selectedEventId.value = null;
  deviceEventFormWO.reset();
};

const saveDeviceEvent = async () => {
  if (!deviceEventFormWO.event_type || !deviceEventFormWO.event_date) {
    displayToast('Por favor, preencha o tipo e a data do evento.', 'error');
    return;
  }

  isSavingDeviceEvent.value = true;

  try {
    const requestData = {
      event_type: parseInt(deviceEventFormWO.event_type),
      event_date: deviceEventFormWO.event_date,
      event_description: deviceEventFormWO.event_description || '',
      event_observations: deviceEventFormWO.event_observations || '',
    };

    if (selectedEventId.value) {
      // Atualizar evento existente
      router.put(`/work-orders/${props.workOrder.id}/devices/${selectedDeviceForEvent.value}/events/${selectedEventId.value}`, requestData, {
        preserveScroll: true,
        onSuccess: () => {
          showDeviceEventModal.value = false;
          deviceEventFormWO.reset();
          selectedEventId.value = null;
          router.reload({ only: ["workOrder"] });
        },
        onError: (errors) => {
          displayToast('Erro ao atualizar evento: ' + (errors.message || 'Erro desconhecido'), 'error');
        }
      });
    } else {
      // Criar novo evento
      router.post(`/work-orders/${props.workOrder.id}/devices/${selectedDeviceForEvent.value}/events`, requestData, {
        preserveScroll: true,
        onSuccess: () => {
          showDeviceEventModal.value = false;
          deviceEventFormWO.reset();
          router.reload({ only: ["workOrder"] });
        },
        onError: (errors) => {
          displayToast('Erro ao adicionar evento: ' + (errors.message || 'Erro desconhecido'), 'error');
        }
      });
    }
  } catch (error) {
    console.error('Erro ao salvar evento:', error);
    displayToast('Erro ao salvar evento: ' + error.message, 'error');
  } finally {
    isSavingDeviceEvent.value = false;
  }
};

const deleteDeviceEvent = async (deviceId, eventId) => {
  if (!confirm('Tem certeza que deseja remover este evento?')) {
    return;
  }

  try {
    router.delete(`/work-orders/${props.workOrder.id}/devices/${deviceId}/events/${eventId}`, {
      preserveScroll: true,
      onSuccess: () => {
        router.reload({ only: ["workOrder"] });
      },
      onError: (errors) => {
        displayToast('Erro ao remover evento: ' + (errors.message || 'Erro desconhecido'), 'error');
      }
    });
  } catch (error) {
    console.error('Erro ao remover evento:', error);
    displayToast('Erro ao remover evento: ' + error.message, 'error');
  }
};

// Watcher para debug do activeTab
watch(() => props.activeTab, (newVal, oldVal) => {
  console.log('WorkOrderTabContent: activeTab mudou de', oldVal, 'para', newVal);
  if (newVal === 'devices') {
    console.log('WorkOrderTabContent: Aba dispositivos ativada!');
    console.log('WorkOrderTabContent: workOrder.address existe?', !!props.workOrder?.address);
    console.log('WorkOrderTabContent: workOrder.address.devices existe?', !!props.workOrder?.address?.devices);
    console.log('WorkOrderTabContent: workOrder.address.devices.length =', props.workOrder?.address?.devices?.length || 0);
  }
}, { immediate: true });

// Em <script setup>, todas as variáveis e funções são automaticamente exportadas
// Não é necessário export explícito
</script>
