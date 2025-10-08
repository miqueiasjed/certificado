<template>
    <AuthenticatedLayout>
      <PageHeader
        title="Detalhes da Ordem de Serviço"
        subtitle="Visualize todas as informações da ordem de serviço"
      />

      <div class="max-w-7xl mx-auto space-y-8 px-4 sm:px-6 lg:px-8">
        <!-- Mensagens de Sucesso/Erro -->
        <Alert v-if="$page.props.flash.success" type="success" title="Sucesso!" :message="$page.props.flash.success" class="mb-4" />
        <Alert v-if="$page.props.flash.error" type="error" title="Erro!" :message="$page.props.flash.error" class="mb-4" />

        <!-- Breadcrumb de Navegação -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200">
          <div class="px-6 py-4">
            <nav class="flex" aria-label="Breadcrumb">
              <ol class="flex items-center space-x-4">
                <li>
                  <Link :href="route('work-orders.index')" class="text-gray-500 hover:text-gray-700 transition-colors duration-200 font-medium">
                    Ordens de Serviço
                  </Link>
                </li>
                <li>
                  <div class="flex items-center">
                    <svg class="flex-shrink-0 h-5 w-5 text-gray-400 mx-2" fill="currentColor" viewBox="0 0 20 20">
                      <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd" />
                    </svg>
                    <span class="text-sm font-medium text-gray-900">Detalhes</span>
                  </div>
                </li>
              </ol>
            </nav>
          </div>
        </div>

        <!-- Cabeçalho da Ordem -->
        <div class="bg-gradient-to-r from-blue-50 to-indigo-50 rounded-xl shadow-sm border border-gray-200">
          <div class="p-8">
            <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between space-y-6 lg:space-y-0">
              <div class="flex items-start space-x-6">
                <div class="flex-shrink-0">
                  <div class="h-20 w-20 rounded-xl bg-blue-500 flex items-center justify-center shadow-lg">
                    <svg class="h-10 w-10 text-white" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v10a2 2 0 002 2h8a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"></path>
                    </svg>
                  </div>
                </div>
                <div class="flex-1">
                  <h1 class="text-3xl font-bold text-gray-900 mb-2">{{ workOrder.order_number }}</h1>
                  <div class="flex flex-col space-y-1 text-sm text-gray-600">
                    <div class="flex items-center">
                      <span class="font-medium text-gray-700">ID:</span>
                      <span class="ml-2">{{ workOrder.id }}</span>
                    </div>
                    <div class="flex items-center">
                      <span class="font-medium text-gray-700">Criado em:</span>
                      <span class="ml-2">{{ formatDate(workOrder.created_at) }}</span>
                    </div>
                  </div>
                </div>
              </div>

              <div class="flex flex-col space-y-3 lg:items-end">
                <span
                  :class="`inline-flex items-center px-4 py-2 rounded-full text-sm font-semibold shadow-sm ${
                    workOrder.status === 'completed' ? 'bg-green-100 text-green-800 border border-green-200' :
                    workOrder.status === 'in_progress' ? 'bg-yellow-100 text-yellow-800 border border-yellow-200' :
                    workOrder.status === 'cancelled' ? 'bg-red-100 text-red-800 border border-red-200' :
                    workOrder.status === 'on_hold' ? 'bg-orange-100 text-orange-800 border border-orange-200' :
                    'bg-gray-100 text-gray-800 border border-gray-200'
                  }`"
                >
                  <div class="w-2 h-2 rounded-full mr-2" :class="{
                    'bg-green-500': workOrder.status === 'completed',
                    'bg-yellow-500': workOrder.status === 'in_progress',
                    'bg-red-500': workOrder.status === 'cancelled',
                    'bg-orange-500': workOrder.status === 'on_hold',
                    'bg-gray-500': !['completed', 'in_progress', 'cancelled', 'on_hold'].includes(workOrder.status)
                  }"></div>
                  {{ workOrder.status_text }}
                </span>
                <span
                  :class="`inline-flex items-center px-4 py-2 rounded-full text-sm font-semibold shadow-sm ${
                    workOrder.priority_level === 'emergency' ? 'bg-purple-100 text-purple-800 border border-purple-200' :
                    workOrder.priority_level === 'urgent' ? 'bg-red-100 text-red-800 border border-red-200' :
                    workOrder.priority_level === 'high' ? 'bg-orange-100 text-orange-800 border border-orange-200' :
                    workOrder.priority_level === 'medium' ? 'bg-yellow-100 text-yellow-800 border border-yellow-200' :
                    'bg-green-100 text-green-800 border border-green-200'
                  }`"
                >
                  {{ workOrder.priority_level_text }}
                </span>
              </div>
            </div>
          </div>
        </div>

        <!-- Navegação por Abas -->
        <div class="bg-white rounded-lg shadow-sm border border-gray-200 overflow-hidden">

          <div class="border-b border-gray-200">
            <nav class="overflow-x-auto px-6" aria-label="Tabs">
              <div class="flex space-x-8">
                <button
                  v-for="tab in allTabs"
                  :key="tab.name"
                  @click="activeTab = tab.name"
                  :class="[
                    activeTab === tab.name
                      ? 'border-green-500 text-green-600'
                      : 'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300',
                    'whitespace-nowrap py-4 px-1 border-b-2 font-medium text-sm transition-all duration-200 flex items-center flex-shrink-0'
                  ]"
                >
                  {{ tab.label }}
                  <span v-if="tab.count !== undefined" class="ml-2 bg-gray-100 text-gray-900 py-0.5 px-2.5 rounded-full text-xs font-medium">
                    {{ tab.count }}
                  </span>
                </button>
              </div>
            </nav>
          </div>

          <!-- Conteúdo das Abas -->
          <div class="min-h-[600px]">
            <WorkOrderTabContent
              :work-order="workOrder"
              :active-tab="activeTab"
              :available-devices="availableDevices"
              :available-addresses="availableAddresses"
              :available-products="availableProducts"
              :available-services="availableServices"
              @show-device-event-modal="showDeviceEventModal = true"
              @show-pest-sighting-modal="showPestSightingModal = true"
            />
          </div>
        </div>

        <!-- Ações Fixas na Parte Inferior -->
        <div class="sticky bottom-0 bg-white rounded-lg shadow-lg border border-gray-200 z-10">
          <div class="px-8 py-6">
            <div class="flex flex-col sm:flex-row sm:justify-between sm:items-center space-y-4 sm:space-y-0">
              <div class="flex items-center text-sm text-gray-500">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>
                </svg>
                Última atualização: {{ formatDate(workOrder.updated_at) }}
              </div>
              <div class="flex space-x-4">
                <Link
                  :href="route('work-orders.index')"
                  class="inline-flex items-center px-6 py-3 border border-gray-300 shadow-sm text-sm font-medium rounded-lg text-gray-700 bg-white hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 transition-all duration-200"
                >
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                  </svg>
                  Voltar à Lista
                </Link>
                <Link
                  :href="route('work-orders.edit', workOrder.id)"
                  class="inline-flex items-center px-6 py-3 border border-transparent text-sm font-medium rounded-lg text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 shadow-sm transition-all duration-200"
                >
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
                  </svg>
                  Editar Ordem
                </Link>
                <button
                  @click="showCertificateModal = true"
                  class="inline-flex items-center px-6 py-3 border border-transparent text-sm font-medium rounded-lg text-white bg-blue-600 hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-blue-500 shadow-sm transition-all duration-200"
                >
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3M4 11h16M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                  </svg>
                  Emitir Certificado
                </button>

                <!-- Botão Emitir OS PDF -->
                <button
                  @click="generateWorkOrderPDF"
                  class="inline-flex items-center px-6 py-3 border border-transparent text-sm font-medium rounded-lg text-white bg-red-600 hover:bg-red-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-red-500 shadow-sm transition-all duration-200"
                >
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                  </svg>
                  Emitir OS PDF
                </button>

                <!-- Botão Emitir Recibo - só aparece quando status = 'paid' -->
                <button
                  v-if="workOrder.payment_status === 'paid'"
                  @click="generateReceipt"
                  class="inline-flex items-center px-6 py-3 border border-transparent text-sm font-medium rounded-lg text-white bg-green-600 hover:bg-green-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-green-500 shadow-sm transition-all duration-200"
                >
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                  </svg>
                  Emitir Recibo
                </button>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Modal para criar Avistamento de Praga -->
      <Teleport to="body">
        <div v-if="showPestSightingModal" class="fixed inset-0 bg-black bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4">
          <div class="relative bg-white rounded-xl shadow-xl max-w-md w-full mx-4 transform transition-all">
            <div class="p-8">
              <div class="flex items-center justify-between mb-6">
                <h3 class="text-xl font-semibold text-gray-900">Novo Avistamento de Praga</h3>
                <button
                  @click="showPestSightingModal = false"
                  class="text-gray-400 hover:text-gray-600 transition-colors"
                >
                  <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                  </svg>
                </button>
              </div>
              <p class="text-gray-600 mb-8">
                Este avistamento será associado à ordem de serviço <strong>{{ workOrder.order_number }}</strong>.
              </p>
              <div class="flex justify-end space-x-4">
                <button
                  @click="showPestSightingModal = false"
                  class="px-6 py-3 border border-gray-300 rounded-lg text-gray-700 hover:bg-gray-50 font-medium transition-colors"
                >
                  Cancelar
                </button>
                <Link
                  :href="route('pest-sightings.create', { work_order_id: workOrder.id })"
                  class="px-6 py-3 bg-green-600 text-white rounded-lg hover:bg-green-700 font-medium transition-colors"
                >
                  Continuar
                </Link>
              </div>
            </div>
          </div>
        </div>
      </Teleport>

      <!-- Modal para Emitir Certificado -->
      <div v-if="showCertificateModal" class="fixed inset-0 bg-black bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4">
        <div class="relative bg-white rounded-xl shadow-xl max-w-4xl w-full mx-4 transform transition-all max-h-[90vh] overflow-hidden flex flex-col">
          <div class="flex-shrink-0 p-6 border-b border-gray-200">
            <div class="flex items-center justify-between">
              <h3 class="text-xl font-semibold text-gray-900">Emitir Certificado</h3>
              <button @click="showCertificateModal = false" class="text-gray-400 hover:text-gray-600 transition-colors">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                </svg>
              </button>
            </div>
          </div>

          <div class="flex-1 overflow-y-auto p-6">
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
              <!-- Coluna Esquerda - Dados da OS -->
              <div class="space-y-4">
                <!-- Informações da OS -->
                <div class="bg-gray-50 rounded-lg p-4">
                  <h4 class="text-sm font-medium text-gray-900 mb-3">Dados da Ordem de Serviço</h4>
                  <div class="space-y-2">
                    <div class="flex justify-between text-sm">
                      <span class="text-gray-500">Número:</span>
                      <span class="font-medium">{{ workOrder.order_number }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                      <span class="text-gray-500">Cliente:</span>
                      <span class="font-medium">{{ workOrder.client?.name }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                      <span class="text-gray-500">Endereço:</span>
                      <span class="font-medium text-right">{{ workOrder.address?.nickname }} - {{ workOrder.address?.street }}, {{ workOrder.address?.number }}</span>
                    </div>
                    <div class="flex justify-between text-sm">
                      <span class="text-gray-500">Data Agendada:</span>
                      <span class="font-medium">{{ new Date(workOrder.scheduled_date).toLocaleDateString('pt-BR') }}</span>
                    </div>
                  </div>
                </div>

                <!-- Produtos e Serviços da OS -->
                <div class="bg-gray-50 rounded-lg p-4">
                  <h4 class="text-sm font-medium text-gray-900 mb-3">Produtos e Serviços</h4>

                  <!-- Produtos -->
                  <div v-if="workOrder.products && workOrder.products.length > 0" class="mb-3">
                    <h5 class="text-xs font-medium text-gray-600 mb-2">Produtos ({{ workOrder.products.length }})</h5>
                    <div class="space-y-1">
                      <div v-for="product in workOrder.products" :key="product.id" class="bg-blue-50 rounded p-2">
                        <div class="flex justify-between items-center">
                          <span class="text-xs font-medium text-blue-900">{{ product.name }}</span>
                          <span class="text-xs text-blue-600">Qty: {{ product.pivot.quantity || 1 }}</span>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Serviços -->
                  <div v-if="workOrder.services && workOrder.services.length > 0">
                    <h5 class="text-xs font-medium text-gray-600 mb-2">Serviços ({{ workOrder.services.length }})</h5>
                    <div class="space-y-1">
                      <div v-for="service in workOrder.services" :key="service.id" class="bg-green-50 rounded p-2">
                        <div>
                          <span class="text-xs font-medium text-green-900">{{ service.name }}</span>
                          <p v-if="service.description" class="text-xs text-green-600 mt-1 line-clamp-2">{{ service.description }}</p>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Mensagem quando não há produtos/serviços -->
                  <div v-if="(!workOrder.products || workOrder.products.length === 0) && (!workOrder.services || workOrder.services.length === 0)" class="text-center py-2 text-gray-500">
                    <p class="text-xs">Esta OS não possui produtos ou serviços registrados.</p>
                  </div>
                </div>
              </div>

              <!-- Coluna Direita - Formulário -->
              <div>
                <form @submit.prevent="emitCertificateFromOS" class="space-y-4">
                  <div>
                    <label for="execution_date" class="block text-sm font-medium text-gray-700 mb-2">
                      Data da Execução *
                    </label>
                    <input
                      type="date"
                      id="execution_date"
                      v-model="certificateForm.execution_date"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                      required
                    />
                  </div>

                  <div>
                    <label for="warranty" class="block text-sm font-medium text-gray-700 mb-2">
                      Data da Garantia
                    </label>
                    <input
                      type="date"
                      id="warranty"
                      v-model="certificateForm.warranty"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                    />
                  </div>

                  <div>
                    <label for="notes" class="block text-sm font-medium text-gray-700 mb-2">
                      Observações
                    </label>
                    <textarea
                      id="notes"
                      v-model="certificateForm.notes"
                      rows="3"
                      class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500"
                      placeholder="Observações adicionais para o certificado..."
                    ></textarea>
                  </div>
                </form>
              </div>
            </div>
          </div>

          <!-- Footer com botões -->
          <div class="flex-shrink-0 p-6 border-t border-gray-200 bg-gray-50">
            <div class="flex justify-end space-x-4">
              <button
                type="button"
                @click="showCertificateModal = false"
                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 hover:bg-gray-50 rounded-lg transition-colors"
              >
                Cancelar
              </button>
              <button
                type="submit"
                @click="emitCertificateFromOS"
                :disabled="isSubmittingCertificate"
                class="px-6 py-2 text-sm font-medium text-white bg-blue-600 hover:bg-blue-700 disabled:opacity-50 disabled:cursor-not-allowed rounded-lg transition-colors"
              >
                <span v-if="isSubmittingCertificate">Emitindo...</span>
                <span v-else>Emitir Certificado</span>
              </button>
            </div>
          </div>
        </div>
      </div>
    </AuthenticatedLayout>
  </template>

  <script setup>
import { ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import WorkOrderTabContent from '@/Components/WorkOrderTabContent.vue';
import Alert from '@/Components/Alert.vue';

  const props = defineProps({
    workOrder: Object,
    availableDevices: Array,
    availableAddresses: Array,
    availableProducts: Array,
    availableServices: Array,
  });

  const activeTab = ref('financial');
  const showDeviceEventModal = ref(false);
  const showPestSightingModal = ref(false);
  const showCertificateModal = ref(false);
  const isSubmittingCertificate = ref(false);

  const certificateForm = ref({
    execution_date: '',
    warranty: '',
    notes: ''
  });

  const allTabs = [
    { name: 'financial', label: 'Informações Financeiras' },
    { name: 'details', label: 'Detalhes da Ordem' },
    { name: 'products-services', label: 'Produtos e Serviços' },
    { name: 'technician', label: 'Técnico' },
    { name: 'device-events', label: 'Eventos de Dispositivos', count: props.workOrder?.device_events?.length || 0 },
    { name: 'pest-sightings', label: 'Avistamentos de Pragas', count: props.workOrder?.pest_sightings?.length || 0 },
  ];

  const formatDate = (date) => {
    if (!date) return '';
    return new Date(date).toLocaleDateString('pt-BR');
  };

  // Função para gerar recibo
  const generateReceipt = () => {
    const url = route('service-orders.receipt', props.workOrder.id);
    window.open(url, '_blank');
  };

  // Função para gerar PDF da OS
  const generateWorkOrderPDF = () => {
    const url = route('work-orders.pdf', props.workOrder.id);
    window.open(url, '_blank');
  };

  // Função para emitir certificado a partir da OS
  const emitCertificateFromOS = async () => {
    isSubmittingCertificate.value = true;

    try {
      const formData = {
        client_id: props.workOrder.client_id,
        address_id: props.workOrder.address_id,
        work_order_id: props.workOrder.id,
        execution_date: certificateForm.value.execution_date,
        warranty: certificateForm.value.warranty || null,
        notes: certificateForm.value.notes || '',
        status: 'active'
      };

      const response = await fetch(route('work-orders.certificates.store', props.workOrder.id), {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content')
        },
        body: JSON.stringify(formData)
      });

      if (response.ok) {
        // Sucesso - redirecionar para a página de certificados
        window.location.href = route('certificates.index');
      } else {
        // Erro - mostrar mensagem
        const errorData = await response.json();
        alert('Erro ao emitir certificado: ' + (errorData.message || 'Erro desconhecido'));
      }
    } catch (error) {
      console.error('Erro ao emitir certificado:', error);
      alert('Erro ao emitir certificado. Tente novamente.');
    } finally {
      isSubmittingCertificate.value = false;
    }
  };
</script>

<style scoped>
/* Estilos para navegação suave das tabs */
.transition-transform {
  transition: transform 0.3s ease-in-out;
}

/* Estilo para truncar texto em múltiplas linhas */
.line-clamp-2 {
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
</style>


