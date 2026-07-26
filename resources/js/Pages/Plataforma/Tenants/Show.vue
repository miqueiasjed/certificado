<template>
  <PlataformaLayout>
    <template #header>
      <PageHeader :title="tenant.name" description="Detalhe do tenant">
        <template #actions>
          <Link :href="route('plataforma.tenants.edit', tenant.id)" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
            </svg>
            Editar
          </Link>
          <Link :href="route('plataforma.tenants.index')" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-5xl mx-auto space-y-6">
      <!-- Mensagens flash -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!-- Cabeçalho de identificação -->
      <Card>
        <div class="flex flex-wrap items-center gap-3 mb-4">
          <span
            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
            :class="CLASSES_SITUACAO[tenant.situacao] || CLASSES_SITUACAO.cancelada"
          >
            {{ ROTULOS_SITUACAO[tenant.situacao] || tenant.situacao }}
          </span>
          <span
            v-if="tenant.is_internal"
            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-200 text-slate-700"
          >
            Interno
          </span>
          <span v-if="tenant.motivo_suspensao" class="text-sm text-gray-500">
            Motivo da suspensão: {{ tenant.motivo_suspensao }}
          </span>
        </div>

        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <dt class="text-sm font-medium text-gray-500">CNPJ</dt>
            <dd class="mt-1 text-sm text-gray-900">{{ tenant.cnpj || '-' }}</dd>
          </div>
          <div>
            <dt class="text-sm font-medium text-gray-500">E-mail</dt>
            <dd class="mt-1 text-sm text-gray-900">{{ tenant.email || '-' }}</dd>
          </div>
          <div>
            <dt class="text-sm font-medium text-gray-500">Telefone</dt>
            <dd class="mt-1 text-sm text-gray-900">{{ tenant.phone || '-' }}</dd>
          </div>
          <div>
            <dt class="text-sm font-medium text-gray-500">Plano</dt>
            <dd class="mt-1 text-sm text-gray-900">{{ tenant.plan?.nome || 'Sem plano' }}</dd>
          </div>
          <div>
            <dt class="text-sm font-medium text-gray-500">Entrada</dt>
            <dd class="mt-1 text-sm text-gray-900">{{ formatarData(tenant.created_at) }}</dd>
          </div>
          <div>
            <dt class="text-sm font-medium text-gray-500">Último acesso</dt>
            <dd class="mt-1 text-sm text-gray-900">
              {{ tenant.ultimo_acesso_em ? formatarDataHora(tenant.ultimo_acesso_em) : 'Nunca' }}
            </dd>
          </div>
          <div v-if="tenant.trial_ends_at">
            <dt class="text-sm font-medium text-gray-500">Fim da avaliação</dt>
            <dd class="mt-1 text-sm text-gray-900">{{ formatarData(tenant.trial_ends_at) }}</dd>
          </div>
          <div class="sm:col-span-2 lg:col-span-3">
            <dt class="text-sm font-medium text-gray-500">Endereço</dt>
            <dd class="mt-1 text-sm text-gray-900">{{ enderecoCompleto || '-' }}</dd>
          </div>
        </dl>
      </Card>

      <!-- Uso do mês corrente -->
      <div>
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-medium text-gray-900">Uso do mês corrente</h3>
          <Link :href="route('plataforma.tenants.uso', tenant.id)" class="btn-secondary-sm">
            Ver uso completo
          </Link>
        </div>

        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <StatCard title="Usuários" :value="usoAtual.usuarios" color="blue" />
          <StatCard title="Clientes" :value="usoAtual.clientes" color="green" />
          <StatCard title="Ordens de serviço" :value="usoAtual.ordens_servico" color="purple" />
          <StatCard title="Certificados" :value="usoAtual.certificados" color="yellow" />
          <StatCard title="Armazenamento" :value="`${usoAtual.armazenamento_mb} MB`" color="gray" />
        </div>
      </div>

      <!-- Histórico de apuração -->
      <Card v-if="historicoDeUso.length > 0" padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Histórico de uso apurado</h3>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Referência</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usuários</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Clientes</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ordens de serviço</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Certificados</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Armazenamento</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="apuracao in historicoDeUso" :key="apuracao.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ apuracao.referencia }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ apuracao.usuarios }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ apuracao.clientes }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ apuracao.ordens_servico }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ apuracao.certificados }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-600">{{ apuracao.armazenamento_mb }} MB</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  </PlataformaLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, usePage } from '@inertiajs/vue3';
import PlataformaLayout from '@/Layouts/PlataformaLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import { formatarData, formatarDataHora } from '@/utils/formatDate';

const props = defineProps({
  tenant: {
    type: Object,
    required: true,
  },
  usoAtual: {
    type: Object,
    default: () => ({ usuarios: 0, clientes: 0, ordens_servico: 0, certificados: 0, armazenamento_mb: 0 }),
  },
  historicoDeUso: {
    type: Array,
    default: () => [],
  },
});

const $page = usePage();

const ROTULOS_SITUACAO = {
  ativa: 'Ativa',
  suspensa: 'Suspensa',
  em_avaliacao: 'Em avaliação',
  cancelada: 'Cancelada',
};

const CLASSES_SITUACAO = {
  ativa: 'bg-green-100 text-green-800',
  suspensa: 'bg-red-100 text-red-800',
  em_avaliacao: 'bg-yellow-100 text-yellow-800',
  cancelada: 'bg-gray-100 text-gray-800',
};

const enderecoCompleto = computed(() => {
  const partes = [];

  if (props.tenant.street) partes.push(props.tenant.street);
  if (props.tenant.number) partes.push(props.tenant.number);
  if (props.tenant.complement) partes.push(props.tenant.complement);
  if (props.tenant.district) partes.push(props.tenant.district);
  if (props.tenant.city) partes.push(props.tenant.state ? `${props.tenant.city}/${props.tenant.state}` : props.tenant.city);
  if (props.tenant.zip) partes.push(`CEP: ${props.tenant.zip}`);

  return partes.join(', ');
});
</script>
