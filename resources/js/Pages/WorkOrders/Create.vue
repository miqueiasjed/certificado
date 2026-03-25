<template>
  <AuthenticatedLayout>
    <PageHeader
      title="Nova Ordem de Serviço"
      subtitle="Crie uma nova ordem de serviço"
    />

    <div class="max-w-6xl mx-auto">
      <Card>
        <form @submit.prevent="submit" class="p-4 sm:p-6 space-y-6">
          <!-- Alerta de Validação para Cômodos -->
          <div
            v-if="hasValidationErrors"
            class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6"
          >
            <div class="flex items-start">
              <div class="flex-shrink-0">
                <svg class="h-5 w-5 text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                </svg>
              </div>
              <div class="ml-3 flex-1">
                <h3 class="text-sm font-medium text-red-800 mb-2">
                  Campos Obrigatórios Não Preenchidos
                </h3>
                <div class="space-y-2">
                  <p class="text-sm text-red-700">
                    Os seguintes campos são obrigatórios para os cômodos selecionados:
                  </p>
                  <ul class="list-disc list-inside space-y-1 text-sm text-red-600">
                    <li v-for="error in validationErrors" :key="error">
                      {{ error }}
                    </li>
                  </ul>
                  <p class="text-sm text-red-600 font-medium mt-2">
                    💡 Dica: Clique em "Adicionar Evento" para preencher os dados obrigatórios.
                  </p>
                </div>
              </div>
            </div>
          </div>

          <!-- Primeira linha: Cliente -->
          <div class="grid grid-cols-1 gap-6">
            <!-- Cliente -->
            <div>
              <ClientSearch
                v-model="form.client_id"
                @change="onClientChange"
                :clients="clients"
                label="Cliente *"
              />
              <p v-if="form.errors.client_id" class="mt-1 text-sm text-red-600">
                {{ form.errors.client_id }}
              </p>
            </div>

          </div>

          <!-- Segunda linha: Endereço e Técnicos -->
          <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- Endereço -->
            <div>
              <label for="address_id" class="block text-sm font-medium text-gray-700 mb-2">
                Endereço *
              </label>
              <select
                id="address_id"
                v-model="form.address_id"
                required
                :disabled="!form.client_id"
                class="w-full min-w-0 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 disabled:bg-gray-100 disabled:cursor-not-allowed"
                :class="{ 'border-red-500': form.errors.address_id }"
              >
                <option value="">Selecione um endereço</option>
                <option v-for="address in filteredAddresses" :key="address.id" :value="address.id">
                  {{ address.nickname }} - {{ address.street }}, {{ address.number }} - {{ address.city }}/{{ address.state }}
                </option>
              </select>
              <p v-if="form.errors.address_id" class="mt-1 text-sm text-red-600">
                {{ form.errors.address_id }}
              </p>
            </div>

            <!-- Técnicos -->
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-2">
                Técnicos *
              </label>
              <div class="space-y-2">
                <div v-for="(technicianId, index) in form.technicians" :key="index" class="flex gap-2 items-center">
                  <select
                    v-model="form.technicians[index]"
                    class="flex-1 min-w-0 h-10 px-3 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                    :class="{ 'border-red-500': form.errors.technicians }"
                    required
                  >
                    <option value="">Selecione um técnico</option>
                    <option v-for="technician in getAvailableTechnicians(index)" :key="technician.id" :value="technician.id">
                      {{ technician.name }} - {{ technician.specialty }}
                    </option>
                  </select>
                  <button
                    type="button"
                    @click="removeTechnician(index)"
                    class="h-10 w-10 text-red-600 border border-red-300 rounded-md hover:bg-red-50 focus:outline-none focus:ring-2 focus:ring-red-500 transition-colors flex items-center justify-center"
                    title="Remover técnico"
                  >
                    <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                  </button>
                </div>
                <div class="flex gap-2">
                  <button
                    type="button"
                    @click="addTechnician"
                    class="px-3 py-2 text-green-600 border border-green-300 rounded-md hover:bg-green-50 focus:outline-none focus:ring-2 focus:ring-green-500 transition-colors text-sm"
                  >
                    + Adicionar Técnico
                  </button>
                </div>
              </div>
              <p v-if="form.errors.technicians" class="mt-1 text-sm text-red-600">
                {{ form.errors.technicians }}
              </p>
            </div>
          </div>

          <!-- Segunda linha: Nível de Prioridade, Data Agendada -->
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <!-- Nível de Prioridade -->
            <div>
              <label for="priority_level" class="block text-sm font-medium text-gray-700 mb-2">
                Nível de Prioridade *
              </label>
              <select
                id="priority_level"
                v-model="form.priority_level"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.priority_level }"
              >
                <option value="">Selecione o nível de prioridade</option>
                <option value="low">Baixa</option>
                <option value="medium">Média</option>
                <option value="high">Alta</option>
                <option value="urgent">Urgente</option>
                <option value="emergency">Emergência</option>
              </select>
              <p v-if="form.errors.priority_level" class="mt-1 text-sm text-red-600">
                {{ form.errors.priority_level }}
              </p>
            </div>

            <!-- Data Agendada -->
            <div>
              <label for="scheduled_date" class="block text-sm font-medium text-gray-700 mb-2">
                Data Agendada *
              </label>
              <input
                id="scheduled_date"
                v-model="form.scheduled_date"
                type="date"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.scheduled_date }"
              />
              <p v-if="form.errors.scheduled_date" class="mt-1 text-sm text-red-600">
                {{ form.errors.scheduled_date }}
              </p>
            </div>
          </div>

          <!-- Terceira linha: Horário de Início, Serviço, Status -->
          <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
            <!-- Horário de Início -->
            <div>
              <label for="start_time" class="block text-sm font-medium text-gray-700 mb-2">
                Horário de Início
              </label>
              <input
                id="start_time"
                v-model="form.start_time"
                type="datetime-local"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.start_time }"
              />
              <p v-if="form.errors.start_time" class="mt-1 text-sm text-red-600">
                {{ form.errors.start_time }}
              </p>
            </div>

            <!-- Serviço -->
            <div>
              <label for="service_id" class="block text-sm font-medium text-gray-700 mb-2">
                Serviço *
              </label>
              <select
                id="service_id"
                v-model="form.service_id"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.service_id }"
              >
                <option value="">Selecione um serviço</option>
                <option v-for="service in services" :key="service.id" :value="service.id">
                  {{ service.name }}
                </option>
              </select>
              <p v-if="form.errors.service_id" class="mt-1 text-sm text-red-600">
                {{ form.errors.service_id }}
              </p>
            </div>

            <!-- Status -->
            <div>
              <label for="status" class="block text-sm font-medium text-gray-700 mb-2">
                Status *
              </label>
              <select
                id="status"
                v-model="form.status"
                required
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.status }"
              >
                <option value="">Selecione o status</option>
                <option value="pending">Pendente</option>
                <option value="scheduled">Agendada</option>
                <option value="in_progress">Em Andamento</option>
                <option value="completed">Concluída</option>
                <option value="cancelled">Cancelada</option>
                <option value="on_hold">Em Espera</option>
              </select>
              <p v-if="form.errors.status" class="mt-1 text-sm text-red-600">
                {{ form.errors.status }}
              </p>
            </div>

          </div>

          <!-- Campos de texto em largura total -->
          <!-- Descrição -->
          <div>
            <label for="description" class="block text-sm font-medium text-gray-700 mb-2">
              Descrição
            </label>
            <textarea
              id="description"
              v-model="form.description"
              rows="4"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.description }"
              placeholder="Descreva detalhadamente o serviço a ser realizado..."
            ></textarea>
            <p v-if="form.errors.description" class="mt-1 text-sm text-red-600">
              {{ form.errors.description }}
            </p>
          </div>

          <!-- Observações -->
          <div>
            <label for="observations" class="block text-sm font-medium text-gray-700 mb-2">
              Observações
            </label>
            <textarea
              id="observations"
              v-model="form.observations"
              rows="3"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.observations }"
              placeholder="Adicione observações adicionais..."
            ></textarea>
            <p v-if="form.errors.observations" class="mt-1 text-sm text-red-600">
              {{ form.errors.observations }}
            </p>
          </div>

          <!-- Produtos -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Produtos Utilizados
            </label>
            <div class="space-y-4">
              <div v-for="(product, index) in form.products" :key="index" class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                <div class="md:col-span-3">
                  <select
                    v-model="product.id"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  >
                    <option value="">Selecione um produto</option>
                    <option v-for="prod in availableProducts(index)" :key="prod.id" :value="prod.id">
                      {{ prod.name }}
                    </option>
                  </select>
                </div>
                <div class="md:col-span-2">
                  <input
                    v-model.number="product.quantity"
                    type="number"
                    step="0.01"
                    min="0"
                    placeholder="Qtd"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  />
                </div>
                <div class="md:col-span-2">
                  <select
                    v-model="product.unit"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  >
                    <option value="">Unidade</option>
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
                <div class="md:col-span-4">
                  <input
                    v-model="product.observations"
                    type="text"
                    placeholder="Observações (opcional)"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  />
                </div>
                <div class="md:col-span-1">
                  <button
                    type="button"
                    @click="removeProduct(index)"
                    class="w-full px-3 py-2 bg-red-600 text-white rounded-md hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-red-500"
                  >
                    ×
                  </button>
                </div>
              </div>
              <button
                type="button"
                @click="addProduct"
                class="w-full px-4 py-2 border-2 border-dashed border-gray-300 rounded-md text-gray-600 hover:border-green-500 hover:text-green-600 focus:outline-none focus:ring-2 focus:ring-green-500"
              >
                + Adicionar Produto
              </button>
            </div>
          </div>

          <!-- Abas para Cômodos e Dispositivos -->
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-2">
              Cômodos e Dispositivos
            </label>
            
            <!-- Tabs -->
            <div class="border-b border-gray-200 mb-4">
              <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                <button
                  type="button"
                  @click="activeTab = 'rooms'"
                  :class="[
                    'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm',
                    activeTab === 'rooms'
                      ? 'border-green-500 text-green-600'
                      : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                  ]"
                >
                  Cômodos
                </button>
                <button
                  type="button"
                  @click="activeTab = 'devices'"
                  :class="[
                    'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm',
                    activeTab === 'devices'
                      ? 'border-green-500 text-green-600'
                      : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300'
                  ]"
                >
                  Dispositivos
                </button>
              </nav>
            </div>

            <!-- Conteúdo da Aba Cômodos -->
            <div v-show="activeTab === 'rooms'" class="space-y-6">
              <div v-for="(room, index) in form.rooms" :key="index" class="border border-gray-200 rounded-lg p-4 space-y-4">
                <!-- Cabeçalho do Cômodo -->
                <div class="flex items-center justify-between">
                  <h4 class="text-sm font-medium text-gray-900">Cômodo {{ index + 1 }}</h4>
                  <button
                    type="button"
                    @click="removeRoom(index)"
                    class="text-red-600 hover:text-red-800 focus:outline-none focus:ring-2 focus:ring-red-500 rounded"
                  >
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                  </button>
                </div>

                <!-- Seleção do Cômodo -->
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">
                    Cômodo *
                  </label>
                  <select
                    v-model="room.id"
                    required
                    class="w-full min-w-0 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                    :class="{ 'border-red-500': form.errors[`rooms.${index}.id`] }"
                  >
                    <option value="">Selecione um cômodo</option>
                    <option v-for="rm in availableRooms(index)" :key="rm.id" :value="rm.id">
                      {{ rm.full_name }}
                    </option>
                  </select>
                  <p v-if="form.errors[`rooms.${index}.id`]" class="mt-1 text-sm text-red-600">
                    {{ form.errors[`rooms.${index}.id`] }}
                  </p>
                </div>


                <!-- Botões para Evento e Avistamento (só aparecem se cômodo selecionado) -->
                <div v-if="room.id" class="border-t pt-4">
                  <div class="flex gap-3">
                    <button
                      type="button"
                      @click="openEventModal(index)"
                      class="inline-flex items-center px-4 py-2 bg-green-600 text-white text-sm font-medium rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500"
                    >
                      <svg class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                      </svg>
                      Adicionar Evento
                    </button>
                    <button
                      type="button"
                      @click="openPestSightingModal(index)"
                      class="inline-flex items-center px-4 py-2 bg-orange-600 text-white text-sm font-medium rounded-md hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-orange-500"
                    >
                      <svg class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                      </svg>
                      Adicionar Avistamento de Praga
                    </button>
                  </div>

                  <!-- Resumo dos dados adicionados -->
                  <div v-if="room.event_type || room.pest_type" class="mt-4 space-y-2">
                    <div v-if="room.event_type" class="flex items-center text-sm text-green-700">
                      <svg class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                      </svg>
                      Evento: {{ getEventTypeText(room.event_type) }} - {{ room.event_date }}
                    </div>
                    <div v-if="room.pest_type" class="flex items-center text-sm text-orange-700">
                      <svg class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                      </svg>
                      Avistamento: {{ getPestTypeText(room.pest_type) }} - {{ room.pest_sighting_date }}
                    </div>
                  </div>

                  <!-- Aviso se cômodo selecionado não tem evento -->
                  <div v-if="showValidationErrors && room.id && !room.event_type" class="mt-4 p-4 bg-red-50 border-2 border-red-300 rounded-lg shadow-sm">
                    <div class="flex items-start">
                      <div class="flex-shrink-0">
                        <svg class="h-5 w-5 text-red-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
                        </svg>
                      </div>
                      <div class="ml-3">
                        <h4 class="text-sm font-medium text-red-800 mb-1">
                          Evento Obrigatório
                        </h4>
                        <p class="text-sm text-red-700">
                          É obrigatório adicionar um evento para este cômodo antes de salvar a ordem de serviço.
                        </p>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
              <button
                type="button"
                @click="addRoom"
                class="w-full px-4 py-2 border-2 border-dashed border-gray-300 rounded-md text-gray-600 hover:border-green-500 hover:text-green-600 focus:outline-none focus:ring-2 focus:ring-green-500"
              >
                + Adicionar Cômodo
              </button>
            </div>

            <!-- Conteúdo da Aba Dispositivos -->
            <div v-show="activeTab === 'devices'" class="space-y-6">
              <div v-if="!form.address_id" class="p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                <p class="text-sm text-yellow-800">
                  Selecione um endereço acima para poder adicionar dispositivos.
                </p>
              </div>
              
              <div v-else>
                <div v-for="(device, index) in form.devices" :key="index" class="border border-gray-200 rounded-lg p-4 space-y-4">
                  <!-- Cabeçalho do Dispositivo -->
                  <div class="flex items-center justify-between">
                    <h4 class="text-sm font-medium text-gray-900">Dispositivo {{ index + 1 }}</h4>
                    <button
                      type="button"
                      @click="removeDevice(index)"
                      class="text-red-600 hover:text-red-800 focus:outline-none focus:ring-2 focus:ring-red-500 rounded"
                    >
                      <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                      </svg>
                    </button>
                  </div>

                  <!-- Seleção do Dispositivo -->
                  <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">
                      Dispositivo
                    </label>
                    <select
                      v-model="device.id"
                      class="w-full min-w-0 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                      :class="{ 'border-red-500': form.errors[`devices.${index}.id`] }"
                    >
                      <option value="">Selecione um dispositivo</option>
                      <option v-for="dev in availableDevices(index)" :key="dev.id" :value="dev.id">
                        {{ dev.display_name }}
                      </option>
                    </select>
                    <p v-if="form.errors[`devices.${index}.id`]" class="mt-1 text-sm text-red-600">
                      {{ form.errors[`devices.${index}.id`] }}
                    </p>
                  </div>

                  <!-- Botão para adicionar eventos ao dispositivo -->
                  <div v-if="device.id" class="border-t pt-4">
                    <button
                      type="button"
                      @click="openDeviceEventModal(index)"
                      class="inline-flex items-center px-4 py-2 bg-blue-600 text-white text-sm font-medium rounded-md hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500"
                    >
                      <svg class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                      </svg>
                      Adicionar Evento ao Dispositivo
                    </button>

                    <!-- Resumo dos eventos adicionados -->
                    <div v-if="device.device_events && device.device_events.length > 0" class="mt-4 space-y-2">
                      <div
                        v-for="(event, eventIndex) in device.device_events"
                        :key="eventIndex"
                        class="flex items-center justify-between text-sm text-blue-700 bg-blue-50 p-2 rounded"
                      >
                        <div class="flex items-center">
                          <svg class="h-4 w-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                          </svg>
                          {{ getEventTypeName(event.event_type) }} - {{ event.event_date }}
                        </div>
                        <button
                          type="button"
                          @click="removeDeviceEvent(index, eventIndex)"
                          class="text-red-600 hover:text-red-800"
                        >
                          <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                          </svg>
                        </button>
                      </div>
                    </div>
                  </div>
                </div>
                
                <button
                  type="button"
                  @click="addDevice"
                  :disabled="!form.address_id"
                  class="w-full px-4 py-2 border-2 border-dashed border-gray-300 rounded-md text-gray-600 hover:border-green-500 hover:text-green-600 focus:outline-none focus:ring-2 focus:ring-green-500 disabled:opacity-50 disabled:cursor-not-allowed"
                >
                  + Adicionar Dispositivo
                </button>
              </div>
            </div>
          </div>

          <!-- Status Ativo -->
          <div class="flex items-center">
            <input
              id="active"
              v-model="form.active"
              type="checkbox"
              class="h-4 w-4 text-green-600 focus:ring-green-500 border-gray-300 rounded"
            />
            <label for="active" class="ml-2 block text-sm text-gray-900">
              Ordem de serviço ativa
            </label>
          </div>

          <!-- Botões -->
          <div class="flex justify-end space-x-3">
            <Link :href="route('work-orders.index')" class="btn-secondary">
              Cancelar
            </Link>
            <button
              type="submit"
              :disabled="form.processing"
              class="btn-primary"
            >
              <svg v-if="form.processing" class="animate-spin -ml-1 mr-3 h-5 w-5 text-white" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
              </svg>
              {{ form.processing ? 'Salvando...' : 'Salvar Ordem de Serviço' }}
            </button>
          </div>
        </form>
      </Card>
    </div>



    <!-- Modal para Adicionar Evento -->
    <div v-if="showEventModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
      <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="mt-3">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Adicionar Evento ao Cômodo</h3>

          <form @submit.prevent="saveEvent">
            <div class="space-y-4">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">
                    Tipo de Evento *
                  </label>
                  <select
                    v-model="eventForm.event_type"
                    required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  >
                    <option value="">Selecione o tipo</option>
                    <option
                      v-for="eventType in eventTypes"
                      :key="eventType.id"
                      :value="eventType.id"
                    >
                      {{ eventType.name }}
                    </option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">
                    Data do Evento *
                  </label>
                  <input
                    v-model="eventForm.event_date"
                    type="date"
                    required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  />
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                  Descrição do Evento
                </label>
                <textarea
                  v-model="eventForm.event_description"
                  rows="3"
                  placeholder="Descreva detalhadamente o evento realizado no cômodo"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                ></textarea>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                  Observações do Evento
                </label>
                <textarea
                  v-model="eventForm.event_observations"
                  rows="2"
                  placeholder="Observações adicionais sobre o evento"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                ></textarea>
              </div>

            </div>

            <div class="flex justify-end space-x-3 mt-6">
              <button
                type="button"
                @click="closeEventModal"
                class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500"
              >
                Cancelar
              </button>
              <button
                type="submit"
                class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500"
              >
                Salvar Evento
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal para Adicionar Avistamento de Praga -->
    <div v-if="showPestSightingModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
      <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="mt-3">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Adicionar Avistamento de Praga ao Cômodo</h3>

          <form @submit.prevent="savePestSighting">
            <div class="space-y-4">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">
                    Tipo de Praga
                  </label>
                  <select
                    v-model="pestSightingForm.pest_type"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                  >
                    <option value="">Selecione o tipo</option>
                    <option value="cockroach">Barata</option>
                    <option value="ant">Formiga</option>
                    <option value="spider">Aranha</option>
                    <option value="rat">Rato</option>
                    <option value="mosquito">Mosquito</option>
                    <option value="fly">Mosca</option>
                    <option value="termite">Cupim</option>
                    <option value="other">Outro</option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">
                    Data do Avistamento
                  </label>
                  <input
                    v-model="pestSightingForm.pest_sighting_date"
                    type="date"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                  />
                </div>
              </div>
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">
                    Localização no Cômodo
                  </label>
                  <input
                    v-model="pestSightingForm.pest_location"
                    type="text"
                    placeholder="Ex: Parede norte, próximo à janela"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                  />
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">
                    Quantidade Observada
                  </label>
                  <input
                    v-model.number="pestSightingForm.pest_quantity"
                    type="number"
                    min="1"
                    placeholder="Ex: 3"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                  />
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                  Observação
                </label>
                <textarea
                  v-model="pestSightingForm.pest_observation"
                  rows="3"
                  placeholder="Observação sobre o avistamento de praga..."
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-orange-500 focus:border-orange-500"
                ></textarea>
              </div>
            </div>

            <div class="flex justify-end space-x-3 mt-6">
              <button
                type="button"
                @click="closePestSightingModal"
                class="px-4 py-2 bg-gray-300 text-gray-700 rounded-md hover:bg-gray-400 focus:outline-none focus:ring-2 focus:ring-gray-500"
              >
                Cancelar
              </button>
              <button
                type="submit"
                class="px-4 py-2 bg-orange-600 text-white rounded-md hover:bg-orange-700 focus:outline-none focus:ring-2 focus:ring-orange-500"
              >
                Salvar Avistamento
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>

    <!-- Modal para Adicionar Evento ao Dispositivo -->
    <div v-if="showDeviceEventModal" class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50">
      <div class="relative top-20 mx-auto p-5 border w-full max-w-2xl shadow-lg rounded-md bg-white">
        <div class="mt-3">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Adicionar Evento ao Dispositivo</h3>

          <form @submit.prevent="saveDeviceEvent">
            <div class="space-y-4">
              <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">
                    Tipo de Evento *
                  </label>
                  <select
                    v-model="deviceEventForm.event_type"
                    required
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  >
                    <option value="">Selecione o tipo</option>
                    <option
                      v-for="eventType in eventTypes"
                      :key="eventType.id"
                      :value="eventType.id"
                    >
                      {{ eventType.name }}
                    </option>
                  </select>
                </div>
                <div>
                  <label class="block text-sm font-medium text-gray-700 mb-1">
                    Data do Evento *
                  </label>
                  <input
                    v-model="deviceEventForm.event_date"
                    type="date"
                    required
                    :max="new Date().toISOString().split('T')[0]"
                    class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  />
                </div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                  Descrição do Evento
                </label>
                <textarea
                  v-model="deviceEventForm.event_description"
                  rows="3"
                  placeholder="Descreva detalhadamente o evento realizado no dispositivo"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                ></textarea>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">
                  Observações do Evento
                </label>
                <textarea
                  v-model="deviceEventForm.event_observations"
                  rows="2"
                  placeholder="Observações adicionais sobre o evento"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
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
                class="px-4 py-2 bg-green-600 text-white rounded-md hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-green-500"
              >
                Salvar Evento
              </button>
            </div>
          </form>
        </div>
      </div>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, watch, getCurrentInstance } from 'vue';
import { Link, useForm, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import ClientSearch from '@/Components/ClientSearch.vue';

const { proxy } = getCurrentInstance();

const props = defineProps({
  clients: Array,
  addresses: Array,
  technicians: Array,
  products: Array,
  services: Array,
  eventTypes: Array,
  preselectedClient: Number,
  preselectedAddress: Number,
  preselectedTechnician: Number,
  errors: Object,
});


// Estado dos modais
const showEventModal = ref(false);
const showPestSightingModal = ref(false);
const showDeviceEventModal = ref(false);
const currentRoomIndex = ref(null);
const currentDeviceIndex = ref(null);
const activeTab = ref('rooms'); // 'rooms' ou 'devices'

// Formulários dos modais
const eventForm = ref({
  event_type: '',
  event_date: '',
  event_description: '',
  event_observations: '',
  device_id: ''
});

const pestSightingForm = ref({
  pest_description: ''
});

// Formulário para eventos de dispositivo
const deviceEventForm = ref({
  event_type: '',
  event_date: '',
  event_description: '',
  event_observations: ''
});

const form = useForm({
  client_id: props.preselectedClient || '',
  address_id: props.preselectedAddress || '',
  technicians: props.preselectedTechnician ? [props.preselectedTechnician] : [''],
  products: [{ id: '', quantity: 1, unit: '', observations: '50 miligramas a cada 10 litros de agua' }],
  service_id: '',
  rooms: [{
    id: '',
    observation: '',
    event_type: '',
    event_date: '',
    event_description: '',
    event_observations: '',
    pest_description: ''
  }],
  devices: [], // Array de dispositivos com eventos
  priority_level: '',
  scheduled_date: new Date().toISOString().slice(0, 10),
  start_time: '',
  status: 'pending',
  description: '',
  observations: '',
  active: true,
});

// Filtrar endereços baseado no cliente selecionado
const filteredAddresses = computed(() => {
  if (!form.client_id) return [];
  return props.addresses.filter(address => address.client_id == form.client_id);
});

// Buscar cômodos disponíveis para o cliente selecionado
const availableRoomsList = ref([]);

// Dispositivos disponíveis para o endereço selecionado
const availableDevicesForWorkOrder = ref([]);

// Função para buscar cômodos por cliente
const fetchRoomsByClient = async (clientId) => {
  if (!clientId) {
    availableRoomsList.value = [];
    return;
  }

  try {
    const url = route('work-orders.rooms.by-client', { client_id: clientId });
    const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const data = await response.json();
    availableRoomsList.value = data.rooms || [];
  } catch (error) {
    console.error('Erro ao buscar cômodos:', error);
    availableRoomsList.value = [];
  }
};

// Função para buscar dispositivos por endereço
const fetchDevicesByAddress = async (addressId) => {
  if (!addressId) {
    availableDevicesForWorkOrder.value = [];
    return;
  }

  try {
    const url = route('work-orders.devices.by-address', { address_id: addressId });
    const response = await fetch(url, { headers: { 'Accept': 'application/json' } });
    if (!response.ok) throw new Error(`HTTP ${response.status}`);
    const data = await response.json();
    availableDevicesForWorkOrder.value = data.devices || [];
  } catch (error) {
    console.error('Erro ao buscar dispositivos:', error);
    availableDevicesForWorkOrder.value = [];
  }
};

// Computed para cômodos disponíveis (filtrar já selecionados, exceto o atual)
const availableRooms = computed(() => {
  return (currentRoomIndex) => {
    const selectedIds = form.rooms
      .map((r, index) => index !== currentRoomIndex ? r.id : null)
      .filter(id => id);
    return availableRoomsList.value.filter(room => !selectedIds.includes(room.id));
  };
});

// Computed para dispositivos disponíveis (filtrar já selecionados, exceto o atual)
const availableDevices = computed(() => {
  return (currentDeviceIndex) => {
    const selectedIds = form.devices
      .map((d, index) => index !== currentDeviceIndex ? d.id : null)
      .filter(id => id);
    return availableDevicesForWorkOrder.value.filter(device => !selectedIds.includes(device.id));
  };
});

// Filtrar técnicos disponíveis para cada select (evitar duplicatas)
const getAvailableTechnicians = (currentIndex) => {
  const selectedIds = form.technicians.filter((id, index) => index !== currentIndex && id !== '');
  return props.technicians.filter(technician => !selectedIds.includes(technician.id));
};

// Limpar endereço quando cliente muda
const onClientChange = () => {
  form.address_id = '';
  // Buscar cômodos do novo cliente
  fetchRoomsByClient(form.client_id);
};

// Limpar endereço quando cliente é limpo
watch(() => form.client_id, (newValue) => {
  if (!newValue) {
    form.address_id = '';
    availableRoomsList.value = [];
    availableDevicesForWorkOrder.value = [];
  } else {
    // Buscar cômodos quando cliente é selecionado
    fetchRoomsByClient(newValue);
  }
});

// Buscar dispositivos quando endereço muda
watch(() => form.address_id, (newValue) => {
  if (newValue) {
    fetchDevicesByAddress(newValue);
  } else {
    availableDevicesForWorkOrder.value = [];
  }
});

const submit = () => {
  // Ativar validação visual
  showValidationErrors.value = true;

  // Verificar se há erros de validação nos cômodos
  const roomErrors = getRoomValidationErrors();
  if (roomErrors.length > 0) {
    // Há erros de validação, não enviar o formulário
    return;
  }

  // Filtrar técnicos vazios ANTES de criar os dados do form
  const cleanedTechnicians = form.technicians.filter(id => id !== '' && id !== null && id !== undefined);

  // Filtrar produtos vazios ANTES de criar os dados do form
  const cleanedProducts = form.products.filter(product => product.id && product.id !== '');

  // Filtrar cômodos vazios ANTES de criar os dados do form
  const cleanedRooms = form.rooms.filter(room => room.id && room.id !== '');

  // Filtrar dispositivos vazios ANTES de criar os dados do form
  const cleanedDevices = form.devices.filter(device => device.id && device.id !== '');

  // Atualizar o form com dados limpos
  form.technicians = cleanedTechnicians;
  form.products = cleanedProducts;
  form.rooms = cleanedRooms;
  form.devices = cleanedDevices;

  form.post('/work-orders', {
    onSuccess: () => {
      // Ordem de serviço criada com sucesso
    },
    onError: (errors) => {
      console.error('Error details:', JSON.stringify(errors, null, 2));
    }
  });
};

// Funções para gerenciar técnicos
const addTechnician = () => {
  form.technicians.push('');
};

const removeTechnician = (index) => {
  form.technicians.splice(index, 1);
};

// Funções para gerenciar produtos
const addProduct = () => {
  form.products.push({ id: '', quantity: 1, unit: '', observations: '50 miligramas a cada 10 litros de agua' });
};

const removeProduct = (index) => {
  form.products.splice(index, 1);
};

// Funções para gerenciar cômodos
const addRoom = () => {
  form.rooms.push({
    id: '',
    observation: '',
    event_type: '',
    event_date: '',
    event_description: '',
    event_observations: '',
    pest_description: ''
  });
};

const removeRoom = (index) => {
  form.rooms.splice(index, 1);
};

// Funções para gerenciar modais de evento
const openEventModal = (roomIndex) => {
  currentRoomIndex.value = roomIndex;
  showEventModal.value = true;
  
  const room = form.rooms[roomIndex];
  
  // Carregar dados existentes do evento do cômodo
  eventForm.value = {
    event_type: room.event_type || '',
    event_date: room.event_date || '',
    event_description: room.event_description || '',
    event_observations: room.event_observations || ''
  };
};

const closeEventModal = () => {
  showEventModal.value = false;
  currentRoomIndex.value = null;
};

const saveEvent = () => {
  if (currentRoomIndex.value !== null) {
    const room = form.rooms[currentRoomIndex.value];
    room.event_type = parseInt(eventForm.value.event_type);
    room.event_date = eventForm.value.event_date;
    room.event_description = eventForm.value.event_description;
    room.event_observations = eventForm.value.event_observations;
    closeEventModal();
  }
};

// Funções para gerenciar dispositivos
const addDevice = () => {
  form.devices.push({
    id: '',
    device_events: []
  });
};

const removeDevice = (index) => {
  form.devices.splice(index, 1);
};

// Funções para gerenciar eventos de dispositivo
const openDeviceEventModal = (deviceIndex) => {
  console.log('openDeviceEventModal chamado com deviceIndex:', deviceIndex);
  currentDeviceIndex.value = deviceIndex;
  showDeviceEventModal.value = true;
  console.log('showDeviceEventModal.value:', showDeviceEventModal.value);
  
  const device = form.devices[deviceIndex];
  
  // Garantir que device_events existe
  if (!device.device_events) {
    device.device_events = [];
  }
  
  // Limpar formulário de evento de dispositivo
  deviceEventForm.value = {
    event_type: '',
    event_date: '',
    event_description: '',
    event_observations: ''
  };
};

const closeDeviceEventModal = () => {
  showDeviceEventModal.value = false;
  currentDeviceIndex.value = null;
};

const saveDeviceEvent = () => {
  // Validar se tipo e data estão preenchidos
  if (!deviceEventForm.value.event_type || !deviceEventForm.value.event_date) {
    alert('Por favor, preencha o tipo de evento e a data.');
    return;
  }

  if (currentDeviceIndex.value !== null) {
    const device = form.devices[currentDeviceIndex.value];
    if (!device.device_events) {
      device.device_events = [];
    }

    // Adicionar evento de dispositivo
    device.device_events.push({
      event_type: parseInt(deviceEventForm.value.event_type),
      event_date: deviceEventForm.value.event_date,
      event_description: deviceEventForm.value.event_description || '',
      event_observations: deviceEventForm.value.event_observations || ''
    });

    // Limpar formulário
    deviceEventForm.value = {
      event_type: '',
      event_date: '',
      event_description: '',
      event_observations: ''
    };
    
    closeDeviceEventModal();
  }
};

const removeDeviceEvent = (deviceIndex, eventIndex) => {
  if (deviceIndex !== null && form.devices[deviceIndex]) {
    const device = form.devices[deviceIndex];
    if (device.device_events && device.device_events[eventIndex]) {
      device.device_events.splice(eventIndex, 1);
    }
  }
};

const getEventTypeName = (eventTypeId) => {
  const eventType = props.eventTypes.find(et => et.id === parseInt(eventTypeId));
  return eventType ? eventType.name : 'Não informado';
};

// Funções para gerenciar modais de avistamento de praga
const openPestSightingModal = (roomIndex) => {
  currentRoomIndex.value = roomIndex;
  showPestSightingModal.value = true;
  // Limpar formulário
  pestSightingForm.value = {
    pest_description: ''
  };
};

const closePestSightingModal = () => {
  showPestSightingModal.value = false;
  currentRoomIndex.value = null;
};

const savePestSighting = () => {
  if (currentRoomIndex.value !== null) {
    const room = form.rooms[currentRoomIndex.value];
    room.pest_description = pestSightingForm.value.pest_description;
    closePestSightingModal();
  }
};

// Funções auxiliares para converter valores em texto legível
const getEventTypeText = (eventType) => {
  const types = {
    'treatment': 'Tratamento',
    'inspection': 'Inspeção',
    'maintenance': 'Manutenção',
    'installation': 'Instalação',
    'removal': 'Remoção',
    'other': 'Outro'
  };
  return types[eventType] || eventType;
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


// Função para verificar se há erros de validação nos cômodos
const getRoomValidationErrors = () => {
  const errors = [];
  form.rooms.forEach((room, index) => {
    if (room.id && !room.event_type) {
      errors.push(`Cômodo ${index + 1}: Tipo de evento é obrigatório`);
    }
    if (room.id && !room.event_date) {
      errors.push(`Cômodo ${index + 1}: Data do evento é obrigatória`);
    }
  });
  return errors;
};

// Estado para controlar quando mostrar os erros de validação
const showValidationErrors = ref(false);

// Computed para verificar se há erros de validação
const hasValidationErrors = computed(() => {
  return showValidationErrors.value && getRoomValidationErrors().length > 0;
});

// Computed para obter erros de validação
const validationErrors = computed(() => {
  return getRoomValidationErrors();
});

// Computed para produtos disponíveis (filtrar já selecionados, exceto o atual)
const availableProducts = computed(() => {
  return (currentProductIndex) => {
    const selectedIds = form.products
      .map((p, index) => index !== currentProductIndex ? p.id : null)
      .filter(id => id);
    return props.products.filter(prod => !selectedIds.includes(prod.id));
  };
});

// Função para quando um tipo de serviço é criado
</script>
