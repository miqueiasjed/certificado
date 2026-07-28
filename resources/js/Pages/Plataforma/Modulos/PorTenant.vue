<template>
  <PlataformaLayout>
    <template #header>
      <PageHeader :title="tituloDaPagina" :description="`Plano: ${tenant.plan?.nome || 'Sem plano'}`">
        <template #actions>
          <Link :href="route('plataforma.tenants.index')" class="btn-secondary">Voltar</Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-4xl mx-auto space-y-6">
      <!-- Mensagens flash -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <Card padding="none">
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Módulo</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Situação</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Origem</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="modulo in modulos" :key="modulo.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                  {{ modulo.nome }}
                  <span v-if="modulo.descricao" class="block text-xs text-gray-400 whitespace-normal">{{ modulo.descricao }}</span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                  <span
                    class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                    :class="modulo.ativo ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800'">
                    {{ modulo.ativo ? 'Ativo' : 'Inativo' }}
                  </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-500">
                  {{ ORIGEM_ROTULOS[modulo.origem] || modulo.origem }}
                  <span v-if="modulo.origem === 'bloqueio_pontual' && modulo.motivo" class="block text-xs text-gray-400">
                    Motivo: {{ modulo.motivo }}
                  </span>
                  <span v-if="modulo.origem === 'liberacao_pontual' && modulo.liberado_ate" class="block text-xs text-gray-400">
                    Até {{ formatarData(modulo.liberado_ate) }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                  <template v-if="!modulo.sempre_ativo">
                    <button
                      v-if="modulo.origem !== 'liberacao_pontual'"
                      type="button"
                      class="text-green-600 hover:text-green-800 font-medium"
                      @click="abrirLiberar(modulo)">
                      Liberar
                    </button>
                    <button
                      v-if="modulo.origem !== 'bloqueio_pontual'"
                      type="button"
                      class="ml-4 text-red-600 hover:text-red-800 font-medium"
                      @click="abrirBloquear(modulo)">
                      Bloquear
                    </button>
                    <button
                      v-if="['bloqueio_pontual', 'liberacao_pontual'].includes(modulo.origem)"
                      type="button"
                      class="ml-4 text-gray-600 hover:text-gray-800 font-medium"
                      @click="voltarAoPlano(modulo)">
                      Voltar ao plano
                    </button>
                  </template>
                  <span v-else class="text-xs text-gray-400">-</span>
                </td>
              </tr>
              <tr v-if="!modulos.length">
                <td colspan="4" class="px-6 py-4 text-sm text-gray-500 text-center">Nenhum módulo cadastrado.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>
    </div>

    <!-- Liberar pontualmente: motivo e prazo são opcionais. -->
    <Modal :show="!!moduloParaLiberar" @close="fecharLiberar">
      <template #icon>
        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
        </svg>
      </template>
      <template #title>Liberar módulo</template>
      <template #content>
        <p class="text-sm text-gray-700 mb-4">
          Libera <strong>"{{ moduloParaLiberar?.nome }}"</strong> para "{{ tenant.name }}", mesmo que o plano atual não o inclua.
        </p>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Liberado até (opcional)</label>
            <input
              v-model="liberarForm.liberado_ate"
              type="date"
              :min="amanha"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': liberarForm.errors.liberado_ate }" />
            <p v-if="liberarForm.errors.liberado_ate" class="mt-1 text-sm text-red-600">{{ liberarForm.errors.liberado_ate }}</p>
            <p class="mt-1 text-xs text-gray-500">Em branco, a liberação vale sem prazo.</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Motivo (opcional)</label>
            <textarea
              v-model="liberarForm.motivo"
              rows="3"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': liberarForm.errors.motivo }"></textarea>
            <p v-if="liberarForm.errors.motivo" class="mt-1 text-sm text-red-600">{{ liberarForm.errors.motivo }}</p>
          </div>
        </div>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="liberarForm.processing" @click="fecharLiberar">Cancelar</button>
        <button type="button" class="btn-primary" :disabled="liberarForm.processing" @click="confirmarLiberar">
          {{ liberarForm.processing ? 'Liberando...' : 'Liberar' }}
        </button>
      </template>
    </Modal>

    <!-- Bloquear pontualmente: motivo é obrigatório, para quem for reativar
         o módulo depois saber por quê. -->
    <Modal :show="!!moduloParaBloquear" @close="fecharBloquear">
      <template #icon>
        <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.36 6.64a9 9 0 11-12.73 0M12 3v9" />
        </svg>
      </template>
      <template #title>Bloquear módulo</template>
      <template #content>
        <p class="text-sm text-gray-700 mb-4">
          Bloqueia <strong>"{{ moduloParaBloquear?.nome }}"</strong> para "{{ tenant.name }}", mesmo que o plano atual libere.
        </p>
        <label class="block text-sm font-medium text-gray-700 mb-1">Motivo *</label>
        <textarea
          v-model="bloquearForm.motivo"
          rows="3"
          placeholder="Descreva o motivo do bloqueio..."
          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          :class="{ 'border-red-500': bloquearForm.errors.motivo }"></textarea>
        <p v-if="bloquearForm.errors.motivo" class="mt-1 text-sm text-red-600">{{ bloquearForm.errors.motivo }}</p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="bloquearForm.processing" @click="fecharBloquear">Cancelar</button>
        <button
          type="button"
          class="btn-danger disabled:opacity-50 disabled:cursor-not-allowed"
          :disabled="bloquearForm.processing || !bloquearForm.motivo.trim()"
          @click="confirmarBloquear">
          {{ bloquearForm.processing ? 'Bloqueando...' : 'Bloquear' }}
        </button>
      </template>
    </Modal>
  </PlataformaLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import PlataformaLayout from '@/Layouts/PlataformaLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import { formatarData, hojeISO } from '@/utils/formatDate';

const props = defineProps({
  tenant: {
    type: Object,
    required: true,
  },
  modulos: {
    type: Array,
    default: () => [],
  },
});

const $page = usePage();

// Calculado em script, e não interpolado direto no atributo `:title` do
// template: o nome do tenant pode conter aspas retas, e misturar aspas
// dentro de um atributo já delimitado por aspas é frágil (o parser de HTML
// não entende escape de barra invertida).
const tituloDaPagina = computed(() => `Módulos de "${props.tenant.name}"`);

const ORIGEM_ROTULOS = {
  sempre_ativo: 'Sempre ativo',
  bloqueio_pontual: 'Bloqueado pontualmente',
  liberacao_pontual: 'Liberado pontualmente',
  plano: 'Pelo plano',
  nenhum: 'Fora do plano',
};

// Dia seguinte a hoje, no fuso do negócio: o backend recusa `liberado_ate`
// que não seja estritamente futuro, e o atributo `min` do input só evita o
// caso mais comum de erro, sem substituir a validação do servidor.
const amanha = (() => {
  const [ano, mes, dia] = hojeISO().split('-').map(Number);
  const data = new Date(Date.UTC(ano, mes - 1, dia + 1));

  return data.toISOString().slice(0, 10);
})();

// Liberar pontualmente ---------------------------------------------------

const moduloParaLiberar = ref(null);
const liberarForm = useForm({ module_id: null, motivo: '', liberado_ate: '' });

const abrirLiberar = (modulo) => {
  liberarForm.reset();
  liberarForm.clearErrors();
  liberarForm.module_id = modulo.id;
  moduloParaLiberar.value = modulo;
};

const fecharLiberar = () => {
  if (liberarForm.processing) return;

  moduloParaLiberar.value = null;
  liberarForm.clearErrors();
};

const confirmarLiberar = () => {
  liberarForm
    .transform((dados) => ({
      ...dados,
      motivo: dados.motivo?.trim() || null,
      liberado_ate: dados.liberado_ate || null,
    }))
    .post(route('plataforma.tenants.modulos.liberar', props.tenant.id), {
      preserveScroll: true,
      onSuccess: () => {
        moduloParaLiberar.value = null;
      },
    });
};

// Bloquear pontualmente ---------------------------------------------------

const moduloParaBloquear = ref(null);
const bloquearForm = useForm({ module_id: null, motivo: '' });

const abrirBloquear = (modulo) => {
  bloquearForm.reset();
  bloquearForm.clearErrors();
  bloquearForm.module_id = modulo.id;
  moduloParaBloquear.value = modulo;
};

const fecharBloquear = () => {
  if (bloquearForm.processing) return;

  moduloParaBloquear.value = null;
  bloquearForm.clearErrors();
};

const confirmarBloquear = () => {
  if (!bloquearForm.motivo.trim()) return;

  bloquearForm.post(route('plataforma.tenants.modulos.bloquear', props.tenant.id), {
    preserveScroll: true,
    onSuccess: () => {
      moduloParaBloquear.value = null;
    },
  });
};

// Voltar ao plano ----------------------------------------------------------
// Reversível e restrito a um módulo só: diferente de salvar os módulos de um
// plano inteiro, não é ação de efeito amplo, por isso segue direto, no mesmo
// critério de "Reativar" em Tenants/Index.vue.

const voltarAoPlano = (modulo) => {
  router.post(
    route('plataforma.tenants.modulos.remover', props.tenant.id),
    { module_id: modulo.id },
    { preserveScroll: true }
  );
};
</script>
