<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Referência normativa"
        description="O texto da resolução citada nos documentos emitidos pela empresa."
      >
        <template #actions>
          <div class="flex flex-wrap gap-2">
            <Link :href="rotaDoChecklist" class="btn-secondary">Voltar ao checklist</Link>
            <button type="button" class="btn-primary" @click="abrirCriacao">Cadastrar referência</button>
          </div>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-5xl mx-auto space-y-6">
      <div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!--
        Aviso obrigatório e no topo: documento antigo não é reprocessado. O
        certificado que o cliente arquivou continua com o texto da época, que é
        o exemplar que o fiscal compara.
      -->
      <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
        <h2 class="text-sm font-semibold text-amber-900">A alteração vale só para documentos futuros</h2>
        <p class="mt-1 text-sm text-amber-800">
          Trocar a referência muda o texto dos certificados, ordens de serviço, contratos e recibos
          emitidos daqui em diante. Documento já emitido não é reprocessado: ele continua com o texto da
          época, que é o exemplar que o cliente arquivou e que a fiscalização compara.
        </p>
      </div>

      <Card>
        <h3 class="text-base font-semibold text-gray-900">Texto usado hoje nos documentos</h3>
        <p v-if="vigente" class="mt-1 text-sm text-gray-700">{{ vigente }}</p>
        <p v-else class="mt-1 text-sm text-red-700">
          Nenhuma referência ativa: os documentos estão sendo emitidos sem citar a resolução.
        </p>
      </Card>

      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Referências cadastradas</h3>
          <p class="text-sm text-gray-500">
            A referência da empresa tem prioridade sobre a padrão do sistema, quando as duas têm a mesma
            chave.
          </p>
        </div>

        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Chave</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Texto</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Vigente desde</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Origem</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="referencia in referencias" :key="referencia.id" class="hover:bg-gray-50">
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">{{ referencia.chave }}</td>
                <td class="px-6 py-4 text-sm text-gray-900">
                  {{ referencia.texto }}
                  <span v-if="!referencia.ativo" class="ml-2 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                    Inativa
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-700">
                  {{ referencia.vigente_desde ? formatarData(referencia.vigente_desde) : 'Não informada' }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <span
                    class="px-2 py-0.5 rounded-full text-xs font-medium"
                    :class="referencia.da_plataforma ? 'bg-blue-100 text-blue-800' : 'bg-green-100 text-green-800'"
                  >
                    {{ referencia.da_plataforma ? 'Padrão do sistema' : 'Da empresa' }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                  <div v-if="referencia.editavel" class="flex justify-end gap-2">
                    <button type="button" class="btn-secondary-sm" @click="abrirEdicao(referencia)">Editar</button>
                    <button type="button" class="btn-secondary-sm" @click="pedirExclusao(referencia)">Remover</button>
                  </div>
                  <span v-else class="text-gray-400">Somente leitura</span>
                </td>
              </tr>
              <tr v-if="referencias.length === 0">
                <td colspan="5" class="px-6 py-6 text-sm text-gray-500">Nenhuma referência cadastrada.</td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>
    </div>

    <!-- Cadastro e edição em modal: não há tela própria para nenhum dos dois. -->
    <Modal :show="modalAberto" @close="fecharModal">
      <template #title>
        {{ emEdicao ? 'Editar referência normativa' : 'Cadastrar referência normativa' }}
      </template>

      <template #content>
        <div class="space-y-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Chave *</label>
            <input
              v-model="form.chave"
              type="text"
              maxlength="60"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.chave }"
            />
            <p class="mt-1 text-sm text-gray-500">
              Use "{{ chavePrincipal }}" para trocar a resolução citada nos documentos. Letras minúsculas,
              números e sublinhado.
            </p>
            <p v-if="form.errors.chave" class="mt-1 text-sm text-red-600">{{ form.errors.chave }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Texto da referência *</label>
            <input
              v-model="form.texto"
              type="text"
              maxlength="255"
              placeholder="RDC nº 622, de 9 de março de 2022, da Anvisa"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.texto }"
            />
            <p class="mt-1 text-sm text-gray-500">É este texto que sai impresso nos documentos.</p>
            <p v-if="form.errors.texto" class="mt-1 text-sm text-red-600">{{ form.errors.texto }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Texto curto</label>
            <input
              v-model="form.texto_curto"
              type="text"
              maxlength="255"
              placeholder="RDC nº 622/2022"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.texto_curto }"
            />
            <p v-if="form.errors.texto_curto" class="mt-1 text-sm text-red-600">{{ form.errors.texto_curto }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Vigente desde</label>
            <input
              v-model="form.vigente_desde"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': form.errors.vigente_desde }"
            />
            <p v-if="form.errors.vigente_desde" class="mt-1 text-sm text-red-600">{{ form.errors.vigente_desde }}</p>
          </div>

          <label class="flex items-center gap-2 text-sm text-gray-700">
            <input v-model="form.ativo" type="checkbox" class="rounded border-gray-300 text-green-600 focus:ring-green-500" />
            Ativa (usada nos documentos)
          </label>
        </div>
      </template>

      <template #actions>
        <button type="button" class="btn-secondary" @click="fecharModal">Cancelar</button>
        <button type="button" class="btn-primary" :disabled="form.processing" @click="salvar">
          {{ form.processing ? 'Salvando...' : 'Salvar' }}
        </button>
      </template>
    </Modal>

    <!-- Nunca confirm() nativo para ação destrutiva. -->
    <ConfirmDeleteModal
      :show="referenciaParaExcluir !== null"
      title="Remover referência normativa"
      subtitle="Os documentos voltam a usar o padrão do sistema."
      :message="'Remover esta referência da empresa?'"
      :item-name="referenciaParaExcluir?.texto || ''"
      :processing="excluindo"
      confirm-text="Sim, remover"
      @confirm="confirmarExclusao"
      @cancel="referenciaParaExcluir = null"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';
import { formatarData } from '@/utils/formatDate';

const props = defineProps({
  referencias: {
    type: Array,
    required: true,
  },
  chave_principal: {
    type: String,
    required: true,
  },
  vigente: {
    type: String,
    default: null,
  },
});

const chavePrincipal = computed(() => props.chave_principal);
const rotaDoChecklist = computed(() => route('conformidade.index'));

const modalAberto = ref(false);
const emEdicao = ref(null);
const referenciaParaExcluir = ref(null);
const excluindo = ref(false);

const form = useForm({
  chave: props.chave_principal,
  texto: '',
  texto_curto: '',
  vigente_desde: '',
  ativo: true,
});

function abrirCriacao() {
  emEdicao.value = null;
  form.reset();
  form.clearErrors();
  form.chave = props.chave_principal;
  form.ativo = true;
  modalAberto.value = true;
}

function abrirEdicao(referencia) {
  emEdicao.value = referencia;
  form.clearErrors();
  form.chave = referencia.chave;
  form.texto = referencia.texto;
  form.texto_curto = referencia.texto_curto || '';
  // A data vem 'yyyy-MM-dd' do backend, que é o formato do input date: sem
  // instanciar Date, não há como o dia escorregar pelo fuso do navegador.
  form.vigente_desde = referencia.vigente_desde || '';
  form.ativo = referencia.ativo;
  modalAberto.value = true;
}

function fecharModal() {
  modalAberto.value = false;
  emEdicao.value = null;
}

function salvar() {
  const aoConcluir = {
    preserveScroll: true,
    onSuccess: () => fecharModal(),
  };

  if (emEdicao.value) {
    form.put(route('conformidade.referencias.update', emEdicao.value.id), aoConcluir);

    return;
  }

  form.post(route('conformidade.referencias.store'), aoConcluir);
}

function pedirExclusao(referencia) {
  referenciaParaExcluir.value = referencia;
}

function confirmarExclusao() {
  if (!referenciaParaExcluir.value) {
    return;
  }

  excluindo.value = true;

  router.delete(route('conformidade.referencias.destroy', referenciaParaExcluir.value.id), {
    preserveScroll: true,
    onFinish: () => {
      excluindo.value = false;
      referenciaParaExcluir.value = null;
    },
  });
}
</script>
