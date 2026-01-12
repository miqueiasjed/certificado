<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Endereços" description="Gerenciar endereços dos clientes">
        <template #actions>
          <Link href="/addresses/create" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
            </svg>
            Novo Endereço
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-7xl mx-auto">
      <!-- Mensagens flash -->
      <div v-if="$page.props.flash.success" class="mb-4">
        <Alert type="success" :message="$page.props.flash.success" />
      </div>
      <div v-if="$page.props.flash.error" class="mb-4">
        <Alert type="error" :message="$page.props.flash.error" />
      </div>

      <!-- Estatísticas -->
      <div class="grid grid-cols-1 md:grid-cols-4 gap-6 mb-6">
        <StatCard
          title="Total de Endereços"
          :value="addresses.total"
          icon="home"
          color="blue"
        />
        <StatCard
          title="Endereços Ativos"
          :value="addresses.data.filter(a => a.active).length"
          icon="check-circle"
          color="green"
        />
        <StatCard
          title="Clientes com Endereços"
          :value="uniqueClients"
          icon="users"
          color="purple"
        />
        <StatCard
          title="Cidades"
          :value="uniqueCities"
          icon="map-pin"
          color="orange"
        />
      </div>

      <!-- Filtros e Busca -->
      <Card class="mb-6">
        <div class="p-6">
          <div class="flex flex-col md:flex-row gap-4">
            <div class="flex-1">
              <label for="search" class="block text-sm font-medium text-gray-700 mb-1">
                Buscar Endereços
              </label>
              <input
                type="text"
                id="search"
                v-model="searchQuery"
                @input="debouncedSearch"
                placeholder="Buscar por apelido, rua, cidade, cliente..."
                class="w-full px-3 py-2 border border-gray-300 rounded-md focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-transparent"
              />
            </div>
            <div class="flex gap-2">
              <button
                @click="clearSearch"
                class="px-4 py-2 text-sm font-medium text-gray-700 bg-white border border-gray-300 rounded-md hover:bg-gray-50 focus:outline-none focus:ring-2 focus:ring-green-500 focus:ring-offset-2 transition-colors"
              >
                Limpar
              </button>
            </div>
          </div>
        </div>
      </Card>

      <!-- Lista de Endereços -->
      <Card>
        <div class="p-6">
          <div v-if="addresses.data.length === 0" class="text-center py-12">
            <svg class="mx-auto h-12 w-12 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
            </svg>
            <h3 class="mt-2 text-sm font-medium text-gray-900">Nenhum endereço encontrado</h3>
            <p class="mt-1 text-sm text-gray-500">
              {{ searchQuery ? 'Tente ajustar os termos de busca.' : 'Comece criando o primeiro endereço.' }}
            </p>
            <div class="mt-6">
              <Link href="/addresses/create" class="btn-primary">
                <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                  <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6"></path>
                </svg>
                Novo Endereço
              </Link>
            </div>
          </div>

          <div v-else class="space-y-4">
            <div
              v-for="address in addresses.data"
              :key="address.id"
              class="border border-gray-200 rounded-lg p-6 hover:shadow-md transition-shadow"
            >
              <div class="flex items-start justify-between">
                <div class="flex-1">
                  <div class="flex items-center gap-3 mb-2">
                    <h3 class="text-lg font-semibold text-gray-900">
                      {{ address.nickname }}
                    </h3>
                    <span
                      :class="[
                        'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                        address.active ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800'
                      ]"
                    >
                      {{ address.active ? 'Ativo' : 'Inativo' }}
                    </span>
                  </div>

                  <div class="text-sm text-gray-600 mb-3">
                    <p class="font-medium">{{ address.street }}, {{ address.number }}</p>
                    <p>{{ address.district }} - {{ address.city }}/{{ address.state }}</p>
                    <p>CEP: {{ address.zip }}</p>
                    <p v-if="address.reference" class="mt-1 italic">
                      Ref: {{ address.reference }}
                    </p>
                  </div>

                  <div class="flex items-center gap-4 text-sm text-gray-500">
                    <div class="flex items-center gap-1">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"></path>
                      </svg>
                      {{ address.client?.name }}
                    </div>
                    <div class="flex items-center gap-1">
                      <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path>
                      </svg>
                      {{ address.rooms_count || 0 }} cômodos
                    </div>
                  </div>
                </div>

                <div class="flex items-center gap-2">
                  <Link
                    :href="`/addresses/${address.id}`"
                    class="px-3 py-1 text-sm text-blue-600 hover:text-blue-800 font-medium"
                  >
                    Ver
                  </Link>
                  <Link
                    :href="`/addresses/${address.id}/edit`"
                    class="px-3 py-1 text-sm text-green-600 hover:text-green-800 font-medium"
                  >
                    Editar
                  </Link>
                  <Link
                    :href="`/addresses/${address.id}/contracts/create`"
                    class="px-3 py-1 text-sm text-purple-600 hover:text-purple-800 font-medium"
                  >
                    <svg class="w-4 h-4 inline mr-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                      <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"></path>
                    </svg>
                    Contrato
                  </Link>
                  <button
                    @click="deleteAddress(address.id)"
                    class="px-3 py-1 text-sm text-red-600 hover:text-red-800 font-medium"
                  >
                    Excluir
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- Paginação -->
          <Pagination
            v-if="addresses.data.length > 0"
            :links="addresses.links"
            class="mt-6"
          />
        </div>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref, computed, onMounted } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import Pagination from '@/Components/Pagination.vue';
import Alert from '@/Components/Alert.vue';

const props = defineProps({
  addresses: Object,
  search: String,
});

const searchQuery = ref(props.search || '');

// Computed properties para estatísticas
const uniqueClients = computed(() => {
  const clientIds = new Set(props.addresses.data.map(a => a.client_id));
  return clientIds.size;
});

const uniqueCities = computed(() => {
  const cities = new Set(props.addresses.data.map(a => a.city));
  return cities.size;
});

// Busca com debounce
let searchTimeout;
const debouncedSearch = () => {
  clearTimeout(searchTimeout);
  searchTimeout = setTimeout(() => {
    if (searchQuery.value) {
      router.get('/addresses', { search: searchQuery.value }, {
        preserveState: true,
        replace: true,
      });
    } else {
      router.get('/addresses', {}, {
        preserveState: true,
        replace: true,
      });
    }
  }, 300);
};

const clearSearch = () => {
  searchQuery.value = '';
  router.get('/addresses', {}, {
    preserveState: true,
    replace: true,
  });
};

const deleteAddress = (id) => {
  if (confirm('Tem certeza que deseja excluir este endereço?')) {
    router.delete(`/addresses/${id}`);
  }
};
</script>






