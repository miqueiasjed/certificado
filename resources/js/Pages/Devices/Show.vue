<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Detalhes do Dispositivo"
        :description="device.label">
        <template #actions>
          <button type="button" class="btn-secondary" @click="imprimirEtiqueta">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 17h2a2 2 0 002-2v-4a2 2 0 00-2-2H5a2 2 0 00-2 2v4a2 2 0 002 2h2m2 4h6a2 2 0 002-2v-4a2 2 0 00-2-2H9a2 2 0 00-2 2v4a2 2 0 002 2zm8-12V5a2 2 0 00-2-2H9a2 2 0 00-2 2v4h10z"></path>
            </svg>
            Imprimir Etiqueta
          </button>
          <button
            v-if="pode('dispositivo-editar') && device.situacao === 'ativo'"
            type="button"
            class="btn-secondary"
            @click="mostrarModalSubstituicao = true"
          >
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"></path>
            </svg>
            Substituir Dispositivo
          </button>
          <Link v-if="device.situacao === 'ativo'" :href="`/devices/${device.id}/edit`" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
            </svg>
            Editar Dispositivo
          </Link>
          <button @click="goBack" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </button>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-4xl mx-auto space-y-6">
      <!-- Breadcrumb de Navegação -->
      <Card>
        <div class="p-4">
          <nav class="flex" aria-label="Breadcrumb">
            <ol class="inline-flex items-center space-x-1 md:space-x-3">
              <li class="inline-flex items-center">
                <Link :href="`/clients/${device.address?.client?.id}`" class="inline-flex items-center text-sm font-medium text-gray-700 hover:text-green-600">
                  <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                  </svg>
                  {{ device.address?.client?.name }}
                </Link>
              </li>
              <li>
                <div class="flex items-center">
                  <svg class="w-6 h-6 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                  </svg>
                  <Link :href="`/addresses/${device.address?.id}`" class="ml-1 text-sm font-medium text-gray-700 hover:text-green-600 md:ml-2">
                    {{ device.address?.nickname || 'Endereço' }}
                  </Link>
                </div>
              </li>
              <li aria-current="page">
                <div class="flex items-center">
                  <svg class="w-6 h-6 text-gray-400" fill="currentColor" viewBox="0 0 20 20">
                    <path fill-rule="evenodd" d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z" clip-rule="evenodd"></path>
                  </svg>
                  <span class="ml-1 text-sm font-medium text-gray-500 md:ml-2">{{ device.label }}</span>
                </div>
              </li>
            </ol>
          </nav>
        </div>
      </Card>

      <!-- Informações Principais -->
      <Card>
        <div class="p-6">
          <div class="flex flex-col sm:flex-row sm:items-center gap-4">
            <div class="flex-shrink-0">
              <div class="h-16 w-16 rounded-full flex items-center justify-center" :class="device.situacao === 'ativo' ? 'bg-green-100' : 'bg-gray-100'">
                <svg class="h-8 w-8" :class="device.situacao === 'ativo' ? 'text-green-600' : 'text-gray-500'" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"></path>
                </svg>
              </div>
            </div>
            <div class="flex-1">
              <h1 class="text-2xl font-bold text-gray-900">{{ device.label }}</h1>
              <p class="text-sm text-gray-500">Número: {{ device.number }}</p>
              <p class="text-sm text-gray-500">Criado em: {{ formatarDataHora(device.created_at) }}</p>

              <!-- Código público, em fonte grande, para conferência rápida com a etiqueta impressa -->
              <div class="mt-3">
                <span class="inline-flex items-center rounded-md bg-gray-100 px-3 py-1.5 font-mono text-xl font-semibold tracking-wider text-gray-800">
                  {{ device.codigo_publico }}
                </span>
              </div>
            </div>
            <div class="flex-shrink-0">
              <span
                class="inline-flex items-center px-3 py-1 rounded-full text-sm font-medium"
                :class="device.situacao === 'ativo' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
              >
                {{ situacaoTexto(device.situacao) }}
              </span>
            </div>
          </div>
        </div>
      </Card>

      <!-- Tipo de Isca -->
      <Card v-if="device.bait_type">
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Tipo de Isca</h3>
          <div class="space-y-3">
            <div class="flex items-center justify-between">
              <span class="text-sm font-medium text-gray-500">Nome:</span>
              <span class="text-sm text-gray-900">{{ device.bait_type.name }}</span>
            </div>
            <div v-if="device.bait_type.brand" class="flex items-center justify-between">
              <span class="text-sm font-medium text-gray-500">Marca:</span>
              <span class="text-sm text-gray-900">{{ device.bait_type.brand }}</span>
            </div>
            <div v-if="device.bait_type.notes" class="flex items-center justify-between">
              <span class="text-sm font-medium text-gray-500">Descrição:</span>
              <span class="text-sm text-gray-900">{{ device.bait_type.notes }}</span>
            </div>
          </div>
        </div>
      </Card>

      <!-- Observações -->
      <Card v-if="device.default_location_note">
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Observação de Localização</h3>
          <div class="space-y-2">
            <p class="text-sm text-gray-900">{{ device.default_location_note }}</p>
            <p class="text-xs text-gray-500">Informações sobre onde o dispositivo está localizado</p>
          </div>
        </div>
      </Card>

      <!-- Histórico do Ponto -->
      <Card>
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-1">Histórico do Ponto</h3>
          <p class="text-xs text-gray-500 mb-4">
            Dispositivos que já ocuparam este ponto de monitoramento, do mais antigo ao atual.
          </p>

          <!--
            Limitação conhecida: `DeviceController::show()` não envia
            `device.historico` (fora do escopo desta task, que não pode
            alterar PHP). Enquanto isso, a linha do tempo completa só aparece
            depois de uma substituição feita NESTA tela, nesta mesma sessão de
            navegação (ver `historicoDaSessao` abaixo). Ao abrir a tela pela
            primeira vez, ou depois de um F5, mostra só a situação atual deste
            dispositivo.
          -->
          <p v-if="!historicoCompleto" class="mb-4 rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-xs text-amber-800">
            Histórico completo ainda não carregado: mostra só a situação atual deste dispositivo.
            A linha do tempo completa aparece aqui assim que uma substituição for feita nesta tela.
          </p>

          <ol class="space-y-4 border-l-2 border-gray-200 pl-4">
            <li v-for="item in historico" :key="item.id" class="relative">
              <span
                class="absolute -left-[21px] top-1 h-3 w-3 rounded-full"
                :class="item.situacao === 'ativo' ? 'bg-green-500' : 'bg-gray-300'"
              ></span>

              <div class="flex flex-wrap items-center gap-2">
                <Link
                  v-if="item.id !== device.id"
                  :href="route('devices.show', item.id)"
                  class="text-sm font-medium text-green-700 hover:text-green-900"
                >
                  {{ item.label }} ({{ item.number }})
                </Link>
                <span v-else class="text-sm font-medium text-gray-900">
                  {{ item.label }} ({{ item.number }}) — este dispositivo
                </span>
                <span
                  class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                  :class="item.situacao === 'ativo' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
                >
                  {{ situacaoTexto(item.situacao) }}
                </span>
              </div>
              <p class="text-xs text-gray-500 mt-0.5">Código: {{ item.codigo_publico }}</p>
              <p v-if="item.substituido_em" class="text-xs text-gray-500">
                Substituído em {{ item.substituido_em }} — motivo: {{ motivoTexto(item.motivo) }}
                <template v-if="item.observacao">({{ item.observacao }})</template>
              </p>
            </li>
          </ol>
        </div>
      </Card>

      <!-- Informações do Sistema -->
      <Card>
        <div class="p-6">
          <h3 class="text-lg font-medium text-gray-900 mb-4">Informações do Sistema</h3>
          <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
            <div>
              <dt class="text-sm font-medium text-gray-500">Data de Criação</dt>
              <dd class="mt-1 text-sm text-gray-900">{{ formatarDataHora(device.created_at) }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Última Atualização</dt>
              <dd class="mt-1 text-sm text-gray-900">{{ formatarDataHora(device.updated_at) }}</dd>
            </div>
            <div>
              <dt class="text-sm font-medium text-gray-500">Situação</dt>
              <dd class="mt-1">
                <span
                  class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                  :class="device.situacao === 'ativo' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'"
                >
                  {{ situacaoTexto(device.situacao) }}
                </span>
              </dd>
            </div>
          </div>
        </div>
      </Card>
    </div>

    <!-- Modal de Substituição -->
    <DeviceReplacementModal
      :show="mostrarModalSubstituicao"
      :device="device"
      @close="mostrarModalSubstituicao = false"
      @substituido="aoSubstituir"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import DeviceReplacementModal from '@/Components/DeviceReplacementModal.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { formatarDataHora } from '@/utils/formatDate';

const props = defineProps({
  device: Object,
});

const { pode } = usePermissoes();

const MOTIVOS_TEXTO = {
  danificado: 'Danificado',
  perdido: 'Perdido',
  desgaste: 'Desgaste',
  troca_de_tipo: 'Troca de tipo',
  outro: 'Outro',
};

function motivoTexto(motivo) {
  return MOTIVOS_TEXTO[motivo] || motivo || '-';
}

function situacaoTexto(situacao) {
  if (situacao === 'substituido') return 'Substituído';
  if (situacao === 'removido') return 'Removido';
  return 'Ativo';
}

// Histórico do ponto trazido pela última substituição feita NESTA tela. Ver
// nota de limitação no template: o backend ainda não envia `device.historico`
// no carregamento normal da página.
const historicoDaSessao = ref(null);
const historicoCompleto = computed(() => historicoDaSessao.value !== null);

const historico = computed(() => {
  if (historicoDaSessao.value) return historicoDaSessao.value;

  if (Array.isArray(props.device.historico) && props.device.historico.length > 0) {
    return props.device.historico;
  }

  // Sem histórico completo disponível: a linha do tempo mostra só a situação
  // atual deste dispositivo, como um único item.
  return [{
    id: props.device.id,
    codigo_publico: props.device.codigo_publico,
    label: props.device.label,
    number: props.device.number,
    situacao: props.device.situacao,
    active: props.device.active,
    substituido_em: null,
    motivo: null,
    observacao: null,
  }];
});

function imprimirEtiqueta() {
  const addressId = props.device.address_id || props.device.address?.id;
  if (!addressId) return;

  const parametros = new URLSearchParams();
  parametros.append('device_ids[]', props.device.id);

  const url = `${route('addresses.devices.etiquetas', addressId)}?${parametros.toString()}`;
  window.open(url, '_blank');
}

const mostrarModalSubstituicao = ref(false);

function aoSubstituir({ historico: historicoRecebido }) {
  historicoDaSessao.value = historicoRecebido;

  // Recarrega a prop `device` a partir do backend: é o jeito mais simples e
  // robusto de garantir que a tela (situação, active, updated_at) bate com o
  // que o Service gravou, em vez de remontar esse estado manualmente aqui.
  // `historicoDaSessao` é estado local do componente e sobrevive ao reload,
  // porque não é uma prop.
  router.reload({ only: ['device'] });
}

const goBack = () => {
  if (window.history.length > 1) {
    window.history.back();
  } else {
    router.visit('/devices');
  }
};
</script>
