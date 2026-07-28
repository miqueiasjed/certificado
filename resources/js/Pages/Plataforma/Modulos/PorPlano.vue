<template>
  <PlataformaLayout>
    <template #header>
      <PageHeader :title="tituloDaPagina" description="Escolha quais módulos este plano libera para os tenants que o assinam.">
        <template #actions>
          <Link :href="route('plataforma.planos.index')" class="btn-secondary">Voltar</Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-3xl mx-auto space-y-6">
      <!-- Mensagens flash -->
      <div v-if="$page.props.flash?.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash?.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Módulos</h3>
          <p class="text-sm text-gray-500 mt-1">
            Clientes, ordens de serviço e certificados são sempre ativos e não podem ser desmarcados de plano nenhum.
          </p>
        </div>

        <ul class="divide-y divide-gray-200">
          <li v-for="modulo in modulos" :key="modulo.id" class="flex items-start gap-3 px-6 py-4">
            <input
              :id="`modulo-${modulo.id}`"
              type="checkbox"
              class="mt-1 rounded border-gray-300 text-green-600 focus:ring-green-500 disabled:opacity-60"
              :checked="selecionados.includes(modulo.id)"
              :disabled="modulo.sempre_ativo"
              @change="alternar(modulo.id)" />
            <label :for="`modulo-${modulo.id}`" class="flex-1 cursor-pointer">
              <span class="block text-sm font-medium text-gray-900">
                {{ modulo.nome }}
                <span
                  v-if="modulo.sempre_ativo"
                  class="ml-2 inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-green-100 text-green-800">
                  Sempre ativo
                </span>
              </span>
              <span v-if="modulo.descricao" class="block text-sm text-gray-500">{{ modulo.descricao }}</span>
            </label>
          </li>
        </ul>
      </Card>

      <div class="flex justify-end">
        <button type="button" class="btn-primary" @click="abrirConfirmacao">Salvar</button>
      </div>
    </div>

    <!-- Ação de efeito amplo: confirmação com a contagem de tenants afetados,
         nunca `confirm()` nativo. -->
    <ConfirmDeleteModal
      :show="showConfirmacao"
      variant="warning"
      title="Salvar módulos do plano"
      subtitle="A alteração vale para os tenants deste plano imediatamente."
      :message="mensagemConfirmacao"
      confirm-text="Salvar"
      cancel-text="Cancelar"
      processing-text="Salvando..."
      :processing="form.processing"
      @confirm="salvar"
      @cancel="showConfirmacao = false" />
  </PlataformaLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';
import PlataformaLayout from '@/Layouts/PlataformaLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';

const props = defineProps({
  plano: {
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
// template: o nome do plano pode conter aspas retas, e misturar aspas dentro
// de um atributo já delimitado por aspas é frágil (o parser de HTML não
// entende escape de barra invertida).
const tituloDaPagina = computed(() => `Módulos do plano "${props.plano.nome}"`);

// Estado local dos módulos marcados, inicializado com o que o backend já
// considera liberado (inclui os `sempre_ativo`, sempre marcados).
const selecionados = ref(props.modulos.filter((modulo) => modulo.liberado).map((modulo) => modulo.id));

const alternar = (id) => {
  const modulo = props.modulos.find((item) => item.id === id);

  // Sempre ativo não sai da lista por clique nenhum: o checkbox já vem
  // desabilitado, isto é só uma segunda trava.
  if (modulo?.sempre_ativo) {
    return;
  }

  selecionados.value = selecionados.value.includes(id)
    ? selecionados.value.filter((valor) => valor !== id)
    : [...selecionados.value, id];
};

const form = useForm({ module_ids: [] });

const showConfirmacao = ref(false);

const abrirConfirmacao = () => {
  showConfirmacao.value = true;
};

const mensagemConfirmacao = computed(() => {
  const quantidade = props.plano.companies_count ?? 0;
  const tenant = quantidade === 1 ? 'tenant' : 'tenants';
  const verbo = quantidade === 1 ? 'está' : 'estão';

  return `Isso muda os módulos disponíveis para ${quantidade} ${tenant} que ${verbo} no plano "${props.plano.nome}", a partir de agora.`;
});

const salvar = () => {
  form.module_ids = selecionados.value;

  form.put(route('plataforma.planos.modulos.update', props.plano.id), {
    preserveScroll: true,
    onSuccess: () => {
      showConfirmacao.value = false;
    },
  });
};
</script>
