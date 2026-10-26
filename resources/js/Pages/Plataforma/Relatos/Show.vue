<template>
  <PlataformaLayout>
    <template #header>
      <PageHeader :title="relato.titulo" :description="`Relato de problema #${relato.id}`">
        <template #actions>
          <Link :href="route('plataforma.relatos.index')" class="btn-secondary">
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

      <!-- Identificação -->
      <Card>
        <div class="flex flex-wrap items-center gap-3 mb-4">
          <span
            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
            :class="CLASSES_SITUACAO_RELATO[relato.situacao] || CLASSES_SITUACAO_RELATO.novo"
          >
            {{ ROTULOS_SITUACAO_RELATO[relato.situacao] || relato.situacao }}
          </span>
          <span
            v-if="relato.enviado_em"
            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800"
          >
            Enviado ao suporte técnico
          </span>
          <span v-else class="text-sm text-gray-500">Ainda não enviado ao suporte técnico</span>
        </div>

        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div>
            <dt class="text-sm font-medium text-gray-500">Tenant</dt>
            <dd class="mt-1 text-sm text-gray-900">{{ relato.company?.name || '-' }}</dd>
          </div>
          <div>
            <dt class="text-sm font-medium text-gray-500">Quem relatou</dt>
            <dd class="mt-1 text-sm text-gray-900">{{ quemRelatou(relato) }}</dd>
          </div>
          <div>
            <dt class="text-sm font-medium text-gray-500">Recebido em</dt>
            <dd class="mt-1 text-sm text-gray-900">{{ formatarDataHora(relato.created_at) }}</dd>
          </div>
          <div v-if="relato.enviado_em">
            <dt class="text-sm font-medium text-gray-500">Enviado ao suporte em</dt>
            <dd class="mt-1 text-sm text-gray-900">{{ formatarDataHora(relato.enviado_em) }}</dd>
          </div>
          <div v-if="linkDaTarefa">
            <dt class="text-sm font-medium text-gray-500">Tarefa no suporte técnico</dt>
            <dd class="mt-1 text-sm">
              <a
                :href="linkDaTarefa"
                target="_blank"
                rel="noopener noreferrer"
                class="text-green-700 hover:text-green-800 underline break-all"
              >
                Tarefa #{{ relato.sistema_task_id }}
              </a>
            </dd>
          </div>
        </dl>
      </Card>

      <!-- Relato -->
      <Card>
        <h3 class="text-lg font-medium text-gray-900 mb-3">O que aconteceu</h3>
        <p class="text-sm text-gray-700 whitespace-pre-line break-words">{{ relato.descricao }}</p>

        <dl class="mt-6 grid grid-cols-1 gap-4 border-t border-gray-200 pt-4">
          <div>
            <dt class="text-sm font-medium text-gray-500">Tela</dt>
            <dd class="mt-1 text-sm">
              <a
                v-if="linkDaPagina"
                :href="linkDaPagina"
                target="_blank"
                rel="noopener noreferrer"
                class="text-green-700 hover:text-green-800 underline break-all"
              >
                {{ relato.pagina_url }}
              </a>
              <span v-else class="text-gray-900">{{ relato.pagina_url || '-' }}</span>
            </dd>
          </div>
          <div>
            <dt class="text-sm font-medium text-gray-500">Navegador</dt>
            <dd class="mt-1 text-sm text-gray-900 break-words">{{ relato.user_agent || '-' }}</dd>
          </div>
        </dl>
      </Card>

      <!-- Situação -->
      <Card>
        <h3 class="text-lg font-medium text-gray-900 mb-3">Situação</h3>
        <form class="flex flex-col gap-3 sm:flex-row sm:items-end" @submit.prevent="salvarSituacao">
          <div class="sm:w-64">
            <label for="situacao" class="block text-sm font-medium text-gray-700 mb-1">Situação do relato</label>
            <select
              id="situacao"
              v-model="formSituacao.situacao"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 sm:text-sm"
              :class="{ 'border-red-500': formSituacao.errors.situacao }"
            >
              <option v-for="situacao in situacoes" :key="situacao" :value="situacao">
                {{ ROTULOS_SITUACAO_RELATO[situacao] || situacao }}
              </option>
            </select>
            <p v-if="formSituacao.errors.situacao" class="mt-1 text-sm text-red-600">
              {{ formSituacao.errors.situacao }}
            </p>
          </div>
          <button
            type="submit"
            class="btn-primary disabled:opacity-50 disabled:cursor-not-allowed"
            :disabled="formSituacao.processing || formSituacao.situacao === relato.situacao"
          >
            {{ formSituacao.processing ? 'Salvando...' : 'Salvar situação' }}
          </button>
        </form>
      </Card>
    </div>
  </PlataformaLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import PlataformaLayout from '@/Layouts/PlataformaLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import { formatarDataHora } from '@/utils/formatDate';
import { CLASSES_SITUACAO_RELATO, ROTULOS_SITUACAO_RELATO, quemRelatou } from '@/utils/relatos';

const props = defineProps({
  relato: {
    type: Object,
    required: true,
  },
  situacoes: {
    type: Array,
    required: true,
  },
});

const formSituacao = useForm({
  situacao: props.relato.situacao,
});

// A URL da tela vem do navegador de quem relatou, e a da tarefa vem do
// sistema central. O backend só grava http(s) na primeira, e a conferência
// se repete aqui nas duas para um link nunca virar `javascript:`.
const somenteHttp = (url) => (/^https?:\/\//i.test(url || '') ? url : null);

const linkDaPagina = computed(() => somenteHttp(props.relato.pagina_url));
const linkDaTarefa = computed(() => somenteHttp(props.relato.sistema_task_url));

const salvarSituacao = () => {
  formSituacao.put(route('plataforma.relatos.situacao', props.relato.id), {
    preserveScroll: true,
  });
};
</script>
