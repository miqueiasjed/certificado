<template>
  <PlataformaLayout>
    <template #header>
      <PageHeader
        :title="`Uso: ${tenant.name}`"
        :description="tenant.plan ? `Plano ${tenant.plan.nome} · ${tenant.plan.periodicidade}` : 'Sem plano vinculado'">
        <template #actions>
          <Link :href="`/plataforma/tenants/${tenant.id}`" class="btn-secondary">
            Voltar ao tenant
          </Link>
          <Link :href="route('plataforma.tenants.edit', tenant.id)" class="btn-primary">
            Trocar plano
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <!-- Uso do mês corrente -->
      <Card>
        <h3 class="text-lg font-medium text-gray-900 mb-4">Uso no mês corrente</h3>

        <div class="space-y-5">
          <div v-for="metrica in metricasComLimite" :key="metrica.chave">
            <div class="flex items-center justify-between mb-1">
              <span class="text-sm font-medium text-gray-700">{{ metrica.label }}</span>

              <span v-if="percentuais[metrica.chave] === null" class="text-sm text-gray-500">
                {{ metrica.formatar(usoAtual[metrica.chave]) }} · ilimitado
              </span>
              <span v-else class="text-sm" :class="percentuais[metrica.chave] > 100 ? 'text-red-600 font-semibold' : 'text-gray-500'">
                {{ metrica.formatar(usoAtual[metrica.chave]) }} / {{ metrica.formatar(limiteDe(metrica)) }}
                · {{ percentuais[metrica.chave] }}% usado
              </span>
            </div>

            <!-- Ilimitado não ganha barra: nem cheia, nem vazia, para não sugerir um teto que não existe. -->
            <div v-if="percentuais[metrica.chave] !== null" class="w-full bg-gray-200 rounded-full h-2.5 overflow-hidden">
              <div
                class="h-2.5 rounded-full transition-all"
                :class="corDaBarra(percentuais[metrica.chave])"
                :style="{ width: `${Math.min(percentuais[metrica.chave], 100)}%` }">
              </div>
            </div>

            <p v-if="percentuais[metrica.chave] > 100" class="mt-1 text-xs text-red-600">
              Excedente: {{ metrica.formatar(usoAtual[metrica.chave] - limiteDe(metrica)) }} acima do limite do plano.
            </p>
          </div>

          <!-- Certificados não tem limite no plano: só o número, sem barra. -->
          <div class="flex items-center justify-between pt-2 border-t border-gray-100">
            <span class="text-sm font-medium text-gray-700">Certificados emitidos no mês</span>
            <span class="text-sm text-gray-500">{{ usoAtual.certificados }}</span>
          </div>
        </div>
      </Card>

      <!-- Histórico -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Histórico</h3>
          <p class="text-sm text-gray-500 mt-1">Últimas {{ historico.length }} apurações</p>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Mês</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Usuários</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Clientes</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">OS</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Certificados</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Armazenamento</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Apurado em</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="registro in historico" :key="registro.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">{{ formatarReferencia(registro.referencia) }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ registro.usuarios }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ registro.clientes }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ registro.ordens_servico }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ registro.certificados }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatarArmazenamento(registro.armazenamento_mb) }}</td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">{{ formatarDataHora(registro.apurado_em) }}</td>
              </tr>
              <tr v-if="!historico.length">
                <td colspan="7" class="px-6 py-4 text-sm text-gray-500 text-center">Nenhuma apuração registrada ainda.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>
    </div>
  </PlataformaLayout>
</template>

<script setup>
import { Link } from '@inertiajs/vue3';
import PlataformaLayout from '@/Layouts/PlataformaLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import { formatarDataHora } from '@/utils/formatDate';

const props = defineProps({
  tenant: {
    type: Object,
    required: true,
  },
  usoAtual: {
    type: Object,
    required: true,
  },
  historico: {
    type: Array,
    required: true,
  },
  percentuais: {
    type: Object,
    required: true,
  },
});

const identidade = (valor) => String(valor);

// Cada métrica com teto no plano, e o campo de `tenant.plan` correspondente ao
// limite. `certificados` fica fora de propósito: não existe `limite_certificados`
// no plano (ver `UsageController::METRICA_PARA_LIMITE`).
const metricasComLimite = [
  { chave: 'usuarios', label: 'Usuários ativos', campoLimite: 'limite_usuarios', formatar: identidade },
  { chave: 'clientes', label: 'Clientes', campoLimite: 'limite_clientes', formatar: identidade },
  { chave: 'ordens_servico', label: 'Ordens de serviço (mês)', campoLimite: 'limite_os_mes', formatar: identidade },
  { chave: 'armazenamento_mb', label: 'Armazenamento', campoLimite: 'limite_armazenamento_mb', formatar: (mb) => formatarArmazenamento(mb) },
];

const limiteDe = (metrica) => props.tenant.plan?.[metrica.campoLimite] ?? null;

// Acima de 1024 MB o número fica difícil de ler de relance; a partir daí o
// valor passa a fazer mais sentido em GB, com uma casa decimal.
function formatarArmazenamento(mb) {
  if (mb === null || mb === undefined) {
    return '';
  }

  return mb > 1024 ? `${(mb / 1024).toFixed(1)} GB` : `${mb} MB`;
}

const corDaBarra = (percentual) => {
  if (percentual > 100) return 'bg-red-500';
  if (percentual >= 80) return 'bg-yellow-500';

  return 'bg-green-500';
};

const MESES_ABREVIADOS = [
  'jan', 'fev', 'mar', 'abr', 'mai', 'jun',
  'jul', 'ago', 'set', 'out', 'nov', 'dez',
];

// `referencia` chega como texto "YYYY-MM" (sem dia, sem hora): divide a
// string em vez de instanciar `Date`, mesma regra usada para campo `date`
// puro na skill de datas.
function formatarReferencia(referencia) {
  const partes = /^(\d{4})-(\d{2})$/.exec(String(referencia ?? ''));

  if (!partes) {
    return referencia ?? '';
  }

  const [, ano, mes] = partes;
  const nomeMes = MESES_ABREVIADOS[Number(mes) - 1] ?? mes;

  return `${nomeMes}/${ano}`;
}
</script>
