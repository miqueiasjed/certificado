<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Validades dos documentos"
        description="Registro do responsável técnico no conselho e licenças da empresa, com número, validade e o documento digitalizado."
      >
        <template #actions>
          <Link :href="rotaDoChecklist" class="btn-secondary">Ver checklist de conformidade</Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-4xl mx-auto space-y-6">
      <div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <div class="bg-blue-50 border border-blue-200 rounded-lg p-4">
        <p class="text-sm text-blue-800">
          Campo de validade em branco significa apenas que a data ainda não foi informada. Não é
          irregularidade, e não gera aviso de vencimento — só o lembrete mensal de cadastro incompleto.
        </p>
        <p class="mt-2 text-sm text-blue-800">
          Assim que você salvar uma data nova, os avisos de vencimento daquele documento param de ser
          enviados na hora.
        </p>
      </div>

      <form class="space-y-6" @submit.prevent="salvar">
        <Card v-for="documento in documentos" :key="documento.item">
          <div class="space-y-4">
            <div class="flex flex-wrap items-center gap-2">
              <h3 class="text-base font-semibold text-gray-900">{{ documento.rotulo }}</h3>
              <!-- Situação com texto além da cor, sempre. -->
              <span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="corDaSituacao(documento.situacao)">
                {{ textoDaSituacao(documento.situacao) }}
              </span>
            </div>

            <p class="text-sm text-gray-600">{{ documento.detalhe }}</p>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Número do documento</label>
                <input
                  v-model="form[documento.campo_numero]"
                  type="text"
                  maxlength="50"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors[documento.campo_numero] }"
                />
                <p v-if="form.errors[documento.campo_numero]" class="mt-1 text-sm text-red-600">
                  {{ form.errors[documento.campo_numero] }}
                </p>
              </div>

              <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Validade</label>
                <input
                  v-model="form[documento.campo_validade]"
                  type="date"
                  class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors[documento.campo_validade] }"
                />
                <p v-if="form.errors[documento.campo_validade]" class="mt-1 text-sm text-red-600">
                  {{ form.errors[documento.campo_validade] }}
                </p>
                <p v-else-if="documento.validade" class="mt-1 text-sm text-gray-500">
                  Vence em {{ formatarData(documento.validade) }}.
                </p>
              </div>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Documento digitalizado</label>
              <input
                type="file"
                accept=".pdf,.jpg,.jpeg,.png"
                class="w-full text-sm text-gray-700 file:mr-3 file:py-2 file:px-3 file:rounded-md file:border-0 file:text-sm file:font-medium file:bg-green-50 file:text-green-700 hover:file:bg-green-100"
                :class="{ 'border border-red-500 rounded-md': form.errors[documento.campo_arquivo] }"
                @change="selecionarArquivo(documento.campo_arquivo, $event)"
              />
              <p class="mt-1 text-sm text-gray-500">PDF, JPG ou PNG, até 4 MB.</p>
              <p v-if="form.errors[documento.campo_arquivo]" class="mt-1 text-sm text-red-600">
                {{ form.errors[documento.campo_arquivo] }}
              </p>
              <p v-if="documento.anexo_url" class="mt-1 text-sm">
                <a :href="documento.anexo_url" target="_blank" rel="noopener" class="text-green-700 underline">
                  Ver documento anexado
                </a>
                <span class="text-gray-500"> — enviar um arquivo novo substitui este.</span>
              </p>
            </div>
          </div>
        </Card>

        <div class="flex justify-end gap-3">
          <Link :href="rotaDoChecklist" class="btn-secondary">Cancelar</Link>
          <button type="submit" class="btn-primary" :disabled="form.processing">
            {{ form.processing ? 'Salvando...' : 'Salvar validades' }}
          </button>
        </div>
      </form>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import { formatarData } from '@/utils/formatDate';

const props = defineProps({
  empresa: {
    type: Object,
    required: true,
  },
  documentos: {
    type: Array,
    required: true,
  },
});

const rotaDoChecklist = computed(() => route('conformidade.index'));

// O formulário nasce com os números e as validades atuais. As validades vêm do
// backend já como 'yyyy-MM-dd', que é exatamente o que `<input type="date">`
// espera: não há conversão nem instância de Date no caminho, e por isso não há
// como a data escorregar um dia por causa do fuso do navegador.
const valoresIniciais = {
  register_crea: props.empresa.register_crea || '',
  crq: props.empresa.crq || '',
  license_sanitary: props.empresa.license_sanitary || '',
  license_environmental: props.empresa.license_environmental || '',
  license_business: props.empresa.license_business || '',
};

props.documentos.forEach((documento) => {
  valoresIniciais[documento.campo_validade] = documento.validade || '';
  valoresIniciais[documento.campo_arquivo] = null;
});

const form = useForm(valoresIniciais);

function selecionarArquivo(campo, evento) {
  form[campo] = evento.target.files?.[0] || null;
}

function salvar() {
  // POST, e não PUT: o formulário tem upload de arquivo, e `multipart` com
  // `_method=PUT` só complicaria a leitura sem ganho nenhum. A rota é POST do
  // outro lado.
  form.post(route('settings.validades.update'), {
    preserveScroll: true,
    forceFormData: true,
  });
}

const TEXTOS = {
  irregular: 'Vencido',
  atencao: 'Vence em breve',
  regular: 'Dentro da validade',
  nao_informado: 'Validade não informada',
};

const CORES = {
  irregular: 'bg-red-100 text-red-800',
  atencao: 'bg-yellow-100 text-yellow-800',
  regular: 'bg-green-100 text-green-800',
  nao_informado: 'bg-gray-100 text-gray-800',
};

function textoDaSituacao(situacao) {
  return TEXTOS[situacao] || situacao;
}

function corDaSituacao(situacao) {
  return CORES[situacao] || CORES.nao_informado;
}
</script>
