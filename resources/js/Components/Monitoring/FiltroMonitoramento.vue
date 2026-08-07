<template>
  <div class="rounded-lg border border-gray-200 bg-white p-4">
    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
      <div>
        <label class="mb-1 block text-sm font-medium text-gray-700">Cliente</label>
        <select
          v-model="form.client_id"
          @change="aoTrocarCliente"
          class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500">
          <option value="">Selecione o cliente</option>
          <option v-for="cliente in clientes" :key="cliente.id" :value="cliente.id">{{ cliente.name }}</option>
        </select>
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium text-gray-700">Endereço</label>
        <select
          v-model="form.address_id"
          :disabled="!form.client_id || carregandoEnderecos"
          class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500 disabled:bg-gray-100 disabled:text-gray-400">
          <option value="">Todos os endereços do cliente</option>
          <option v-for="endereco in enderecos" :key="endereco.id" :value="endereco.id">
            {{ endereco.nickname || `${endereco.street}, ${endereco.number}` }}
          </option>
        </select>
        <p v-if="carregandoEnderecos" class="mt-1 text-xs text-gray-500">Carregando endereços...</p>
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium text-gray-700">De</label>
        <input
          v-model="form.de"
          type="date"
          class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500">
      </div>

      <div>
        <label class="mb-1 block text-sm font-medium text-gray-700">Até</label>
        <input
          v-model="form.ate"
          type="date"
          class="w-full rounded-md border border-gray-300 px-3 py-2 text-sm shadow-sm focus:border-green-500 focus:outline-none focus:ring-2 focus:ring-green-500">
      </div>
    </div>

    <p v-if="erro" class="mt-3 text-sm text-red-600">{{ erro }}</p>

    <div class="mt-4 flex flex-wrap items-center gap-3">
      <button type="button" class="btn-primary" @click="filtrar">Filtrar</button>
      <button
        v-if="form.client_id || form.address_id || form.de || form.ate"
        type="button"
        class="text-sm font-medium text-gray-500 hover:text-gray-700"
        @click="limpar">
        Limpar filtros
      </button>
    </div>
  </div>
</template>

<script setup>
/**
 * Filtro de cliente/endereço/período reaproveitado pela visão ao vivo
 * (`monitoramento.index`) e pela lista de relatórios gerados
 * (`monitoramento.relatorios.index`) - as duas leituras aceitam exatamente o
 * mesmo conjunto de filtros opcionais, validados pela mesma
 * `MonitoringReportFilterRequest` no backend (ver o docblock dela).
 *
 * Endereço não vem pronto do controller (só `clientes`, ver
 * `MonitoringReportController::index()`): a lista é buscada aqui, sob
 * demanda, no endpoint já existente `addresses.by-client`
 * (`AddressController::getByClient`, atrás de `endereco-ver` - permissão que
 * o papel técnico, dono de `monitoramento-ver`, já tem, ver
 * `RolesAndPermissionsSeeder::permissoesTecnico()`), o mesmo padrão de
 * dropdown em cascata já usado noutras telas do sistema.
 */
import { reactive, ref, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';

const props = defineProps({
  clientes: { type: Array, required: true },
  filtrosIniciais: { type: Object, required: true },
  rota: { type: String, required: true },
});

const form = reactive({
  client_id: props.filtrosIniciais.client_id ?? '',
  address_id: props.filtrosIniciais.address_id ?? '',
  de: props.filtrosIniciais.de ?? '',
  ate: props.filtrosIniciais.ate ?? '',
});

const enderecos = ref([]);
const carregandoEnderecos = ref(false);
const erro = ref('');

async function carregarEnderecos(clientId) {
  if (!clientId) {
    enderecos.value = [];
    return;
  }

  carregandoEnderecos.value = true;
  erro.value = '';

  try {
    const resposta = await fetch(route('addresses.by-client', clientId), {
      headers: { Accept: 'application/json' },
    });

    if (!resposta.ok) {
      throw new Error('falha ao buscar endereços');
    }

    const dados = await resposta.json();
    enderecos.value = dados.addresses ?? [];
  } catch (falha) {
    erro.value = 'Não foi possível carregar os endereços deste cliente. Tente novamente.';
    enderecos.value = [];
  } finally {
    carregandoEnderecos.value = false;
  }
}

function aoTrocarCliente() {
  form.address_id = '';
  carregarEnderecos(form.client_id);
}

function filtrar() {
  if (form.de && form.ate && form.ate < form.de) {
    erro.value = 'A data final não pode ser anterior à data inicial.';
    return;
  }

  erro.value = '';

  const parametros = {};
  if (form.client_id) parametros.client_id = form.client_id;
  if (form.address_id) parametros.address_id = form.address_id;
  if (form.de) parametros.de = form.de;
  if (form.ate) parametros.ate = form.ate;

  router.get(route(props.rota), parametros, { preserveState: true, preserveScroll: true, replace: true });
}

function limpar() {
  form.client_id = '';
  form.address_id = '';
  form.de = '';
  form.ate = '';
  enderecos.value = [];
  erro.value = '';
  router.get(route(props.rota), {}, { preserveState: true, preserveScroll: true, replace: true });
}

onMounted(() => {
  if (form.client_id) {
    carregarEnderecos(form.client_id);
  }
});
</script>
