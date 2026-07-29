<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Agendamentos"
        description="Pedidos de horário abertos pelo cliente, pelo portal ou pela página pública de agendamento."
      />
    </template>

    <div class="max-w-6xl mx-auto space-y-6">
      <div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!-- Filtro por situação -->
      <Card padding="small">
        <div class="flex flex-col sm:flex-row sm:items-center gap-3">
          <label class="text-sm font-medium text-gray-700 shrink-0">Situação</label>
          <select
            v-model="filtroSituacao"
            @change="aplicarFiltro"
            class="w-full sm:w-64 px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          >
            <option :value="null">Todas</option>
            <option v-for="situacao in situacoes" :key="situacao" :value="situacao">
              {{ ROTULO_DA_SITUACAO[situacao] || situacao }}
            </option>
          </select>
        </div>
      </Card>

      <!-- Fila de pedidos -->
      <Card padding="none">
        <div v-if="pedidos.data.length === 0" class="p-8 text-center text-sm text-gray-500">
          Nenhum pedido de horário encontrado{{ filtroSituacao ? ' com esta situação' : '' }}.
        </div>

        <div v-else class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Solicitante</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Endereço</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data / período pedidos</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Origem</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Situação</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="pedido in pedidos.data" :key="pedido.id" class="hover:bg-gray-50">
                <td class="px-4 py-4 text-sm">
                  <p class="font-medium text-gray-900">{{ pedido.nome }}</p>
                  <p class="text-gray-500">{{ pedido.email }}</p>
                  <p class="text-gray-500">{{ pedido.telefone }}</p>
                </td>
                <td class="px-4 py-4 text-sm text-gray-700 max-w-xs">
                  {{ enderecoDoPedido(pedido) }}
                </td>
                <td class="px-4 py-4 text-sm text-gray-700 whitespace-nowrap">
                  <p>{{ formatarData(pedido.data_preferida) }}</p>
                  <p class="text-gray-500">{{ ROTULO_DO_PERIODO[pedido.periodo] || pedido.periodo }}</p>
                </td>
                <td class="px-4 py-4 text-sm text-gray-700 whitespace-nowrap">
                  {{ ROTULO_DA_ORIGEM[pedido.origem] || pedido.origem }}
                </td>
                <td class="px-4 py-4 whitespace-nowrap">
                  <span class="inline-flex px-2 py-1 text-xs font-medium rounded-full" :class="CLASSE_DA_SITUACAO[pedido.situacao]">
                    {{ ROTULO_DA_SITUACAO[pedido.situacao] || pedido.situacao }}
                  </span>
                </td>
                <td class="px-4 py-4 text-right whitespace-nowrap">
                  <div v-if="pedido.situacao === 'pendente'" class="flex justify-end gap-2">
                    <button type="button" class="btn-secondary-sm" @click="abrirRecusar(pedido)">Recusar</button>
                    <button type="button" class="btn-primary" @click="abrirConfirmar(pedido)">Confirmar</button>
                  </div>
                  <span v-else class="text-xs text-gray-400">
                    {{ pedido.respondidaPor?.name ? `Respondido por ${pedido.respondidaPor.name}` : '' }}
                  </span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="p-4 border-t border-gray-200" v-if="pedidos.data.length > 0">
          <Pagination :links="pedidos.links" />
        </div>
      </Card>
    </div>

    <!-- Modal: Confirmar -->
    <Modal :show="mostrarConfirmar" @close="fecharConfirmar">
      <template #icon>
        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </template>
      <template #title>Confirmar pedido de {{ pedidoAtivo?.nome }}</template>
      <template #content>
        <p class="text-sm text-gray-600 mb-4">
          Confirmar cria a ordem de serviço e avisa o solicitante por e-mail. Reveja a data, o período e o
          técnico antes de salvar.
        </p>

        <div v-if="erroConfirmar" class="mb-4 bg-red-50 border border-red-200 rounded-md p-3">
          <p class="text-sm text-red-800">{{ erroConfirmar }}</p>
        </div>

        <div class="space-y-4 text-left">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Data</label>
            <input
              v-model="formConfirmar.data"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Período</label>
            <select
              v-model="formConfirmar.periodo"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="manha">Manhã</option>
              <option value="tarde">Tarde</option>
            </select>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Técnico *</label>
            <select
              v-model="formConfirmar.technician_id"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option :value="null">Selecione um técnico</option>
              <option v-for="tecnico in tecnicos" :key="tecnico.id" :value="tecnico.id">
                {{ tecnico.name }}<template v-if="tecnico.specialty"> ({{ tecnico.specialty }})</template>
              </option>
            </select>
          </div>
        </div>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="processandoConfirmar" @click="fecharConfirmar">
          Cancelar
        </button>
        <button type="button" class="btn-primary" :disabled="processandoConfirmar" @click="confirmarPedido">
          {{ processandoConfirmar ? 'Confirmando...' : 'Confirmar pedido' }}
        </button>
      </template>
    </Modal>

    <!-- Modal: Recusar -->
    <Modal :show="mostrarRecusar" @close="fecharRecusar">
      <template #icon>
        <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
        </svg>
      </template>
      <template #title>Recusar pedido de {{ pedidoAtivo?.nome }}</template>
      <template #content>
        <p class="text-sm text-gray-600 mb-4">
          O motivo é enviado ao solicitante por e-mail. Escolha um motivo pronto ou escreva o seu.
        </p>

        <div v-if="erroRecusar" class="mb-4 bg-red-50 border border-red-200 rounded-md p-3">
          <p class="text-sm text-red-800">{{ erroRecusar }}</p>
        </div>

        <div class="space-y-3 text-left">
          <div class="flex flex-wrap gap-2">
            <button
              v-for="motivo in motivosProntos"
              :key="motivo"
              type="button"
              class="btn-secondary-sm"
              @click="formRecusar.motivo = motivo"
            >
              {{ motivo }}
            </button>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Motivo *</label>
            <textarea
              v-model="formRecusar.motivo"
              rows="3"
              maxlength="1000"
              placeholder="Escreva o motivo da recusa..."
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            ></textarea>
          </div>
        </div>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="processandoRecusar" @click="fecharRecusar">
          Cancelar
        </button>
        <button type="button" class="btn-danger" :disabled="processandoRecusar || !formRecusar.motivo.trim()" @click="recusarPedido">
          {{ processandoRecusar ? 'Recusando...' : 'Recusar pedido' }}
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import Pagination from '@/Components/Pagination.vue';
import { formatarData, paraInputDate } from '@/utils/formatDate';

const props = defineProps({
  pedidos: Object,
  situacaoFiltro: String,
  situacoes: Array,
  motivosProntos: Array,
  tecnicos: Array,
});

const ROTULO_DA_SITUACAO = {
  pendente: 'Pendente',
  confirmada: 'Confirmada',
  recusada: 'Recusada',
  cancelada: 'Cancelada',
};

const CLASSE_DA_SITUACAO = {
  pendente: 'bg-yellow-100 text-yellow-800',
  confirmada: 'bg-green-100 text-green-800',
  recusada: 'bg-red-100 text-red-800',
  cancelada: 'bg-gray-100 text-gray-800',
};

const ROTULO_DO_PERIODO = {
  manha: 'Manhã',
  tarde: 'Tarde',
};

const ROTULO_DA_ORIGEM = {
  portal: 'Portal do cliente',
  pagina_publica: 'Página pública',
};

// O endereço vinculado (`pedido.address`) chega com as colunas cruas
// (street, number, district, city, state, zip): `full_address` é um
// accessor do model `Address` que não é serializado por padrão, então o
// texto é montado aqui, no mesmo formato de `Address::getFullAddressAttribute()`.
// Pedido de quem ainda não é cliente cadastrado não tem `address`, e cai no
// `endereco_texto` livre que a página pública coletou.
const enderecoDoPedido = (pedido) => {
  const endereco = pedido.address;

  if (!endereco) {
    return pedido.endereco_texto || 'Não informado';
  }

  return `${endereco.street}, ${endereco.number} - ${endereco.district}, ${endereco.city}/${endereco.state} - CEP: ${endereco.zip}`;
};

// -----------------------------------------------------------------
// Filtro por situação
// -----------------------------------------------------------------

const filtroSituacao = ref(props.situacaoFiltro || null);

const aplicarFiltro = () => {
  router.get(
    route('solicitacoes-de-horario.index'),
    filtroSituacao.value ? { situacao: filtroSituacao.value } : {},
    { preserveState: true, replace: true }
  );
};

// -----------------------------------------------------------------
// Cabeçalho csrf para as chamadas fetch (ações sem redirecionamento)
// -----------------------------------------------------------------

const cabecalhosJson = () => ({
  'Content-Type': 'application/json',
  Accept: 'application/json',
  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '',
});

const mensagemDoErro = async (resposta) => {
  try {
    const dados = await resposta.json();

    if (dados?.message) {
      return dados.message;
    }

    if (dados?.errors) {
      return Object.values(dados.errors).flat().join(' ');
    }
  } catch (erro) {
    // resposta sem corpo JSON: cai no texto padrão abaixo.
  }

  return 'Não foi possível concluir a ação. Tente novamente.';
};

// -----------------------------------------------------------------
// Confirmar
// -----------------------------------------------------------------

const mostrarConfirmar = ref(false);
const pedidoAtivo = ref(null);
const processandoConfirmar = ref(false);
const erroConfirmar = ref('');
const formConfirmar = ref({ data: '', periodo: 'manha', technician_id: null });

const abrirConfirmar = (pedido) => {
  pedidoAtivo.value = pedido;
  erroConfirmar.value = '';
  formConfirmar.value = {
    data: paraInputDate(pedido.data_preferida),
    periodo: pedido.periodo,
    technician_id: null,
  };
  mostrarConfirmar.value = true;
};

const fecharConfirmar = () => {
  if (processandoConfirmar.value) return;
  mostrarConfirmar.value = false;
  pedidoAtivo.value = null;
};

const confirmarPedido = async () => {
  if (!formConfirmar.value.technician_id) {
    erroConfirmar.value = 'Selecione o técnico responsável pelo atendimento.';
    return;
  }

  processandoConfirmar.value = true;
  erroConfirmar.value = '';

  try {
    const resposta = await fetch(`/solicitacoes-de-horario/${pedidoAtivo.value.id}/confirmar`, {
      method: 'POST',
      headers: cabecalhosJson(),
      body: JSON.stringify(formConfirmar.value),
    });

    if (!resposta.ok) {
      // Período que lotou entre o pedido e a confirmação (ou outra recusa de
      // negócio) chega aqui como 422: o modal continua aberto, com o aviso,
      // em vez de fechar como se tivesse dado certo.
      erroConfirmar.value = await mensagemDoErro(resposta);
      return;
    }

    mostrarConfirmar.value = false;
    pedidoAtivo.value = null;
    router.reload({ only: ['pedidos'] });
  } catch (erro) {
    erroConfirmar.value = 'Não foi possível conectar ao servidor. Tente novamente.';
  } finally {
    processandoConfirmar.value = false;
  }
};

// -----------------------------------------------------------------
// Recusar
// -----------------------------------------------------------------

const mostrarRecusar = ref(false);
const processandoRecusar = ref(false);
const erroRecusar = ref('');
const formRecusar = ref({ motivo: '' });

const abrirRecusar = (pedido) => {
  pedidoAtivo.value = pedido;
  erroRecusar.value = '';
  formRecusar.value = { motivo: '' };
  mostrarRecusar.value = true;
};

const fecharRecusar = () => {
  if (processandoRecusar.value) return;
  mostrarRecusar.value = false;
  pedidoAtivo.value = null;
};

const recusarPedido = async () => {
  if (!formRecusar.value.motivo.trim()) {
    erroRecusar.value = 'Informe o motivo da recusa.';
    return;
  }

  processandoRecusar.value = true;
  erroRecusar.value = '';

  try {
    const resposta = await fetch(`/solicitacoes-de-horario/${pedidoAtivo.value.id}/recusar`, {
      method: 'POST',
      headers: cabecalhosJson(),
      body: JSON.stringify({ motivo: formRecusar.value.motivo.trim() }),
    });

    if (!resposta.ok) {
      erroRecusar.value = await mensagemDoErro(resposta);
      return;
    }

    mostrarRecusar.value = false;
    pedidoAtivo.value = null;
    router.reload({ only: ['pedidos'] });
  } catch (erro) {
    erroRecusar.value = 'Não foi possível conectar ao servidor. Tente novamente.';
  } finally {
    processandoRecusar.value = false;
  }
};
</script>
