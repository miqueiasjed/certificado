<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Conformidade"
        description="O que o sistema consegue verificar sobre a regularidade da empresa perante a RDC 622/2022."
      >
        <template #actions>
          <div class="flex flex-wrap gap-2">
            <Link :href="rotaDeValidades" class="btn-secondary">Validades dos documentos</Link>
            <Link :href="rotaDeReferencias" class="btn-secondary">Referência normativa</Link>
            <button type="button" class="btn-primary" :disabled="recalculando" @click="recalcular">
              {{ recalculando ? 'Recalculando...' : 'Recalcular agora' }}
            </button>
          </div>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-6xl mx-auto space-y-6">
      <div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!--
        A ressalva fica no topo, antes de qualquer número, e não em rodapé:
        quem lê o checklist precisa saber o que ele é ANTES de tomar decisão a
        partir dele. O checklist informa, não certifica.
      -->
      <div class="bg-amber-50 border border-amber-200 rounded-lg p-4">
        <h2 class="text-sm font-semibold text-amber-900">Antes de usar este checklist</h2>
        <p class="mt-1 text-sm text-amber-800">{{ checklist.ressalva }}</p>
        <p class="mt-2 text-sm text-amber-800">
          Ele não substitui a avaliação do responsável técnico da empresa.
        </p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
        <StatCard title="Irregulares" :value="checklist.resumo.irregular" color="red" />
        <StatCard title="Atenção" :value="checklist.resumo.atencao" color="yellow" />
        <StatCard title="Regulares" :value="checklist.resumo.regular" color="green" />
        <StatCard title="Não aplicáveis" :value="checklist.resumo.nao_aplicavel" color="gray" />
      </div>

      <p v-if="verificadoEm" class="text-sm text-gray-500">
        Última verificação registrada em {{ verificadoEm }}.
      </p>
      <p v-else class="text-sm text-gray-500">
        A verificação automática ainda não rodou nesta empresa. Os itens abaixo foram calculados agora,
        ao abrir a tela.
      </p>

      <Card v-for="grupo in gruposComItens" :key="grupo.chave" padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">
            {{ grupo.titulo }}
            <span class="text-sm font-normal text-gray-500">({{ grupo.itens.length }})</span>
          </h3>
          <p class="text-sm text-gray-500">{{ grupo.descricao }}</p>
        </div>

        <ul class="divide-y divide-gray-200">
          <li v-for="item in grupo.itens" :key="item.item" class="px-6 py-4">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
              <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-2">
                  <h4 class="text-sm font-semibold text-gray-900">{{ item.rotulo }}</h4>
                  <!--
                    Situação sempre com TEXTO além da cor: quem enxerga cor de
                    forma diferente, ou imprime em preto e branco para levar à
                    fiscalização, precisa ler a situação do mesmo jeito.
                  -->
                  <span class="px-2 py-0.5 rounded-full text-xs font-medium" :class="corDaSituacao(item.situacao)">
                    {{ textoDaSituacao(item.situacao) }}
                  </span>
                </div>
                <p class="mt-1 text-sm text-gray-700">{{ item.detalhe }}</p>
                <p v-if="item.exigencia" class="mt-1 text-sm text-gray-500">
                  <span class="font-medium text-gray-600">O que a norma pede:</span> {{ item.exigencia }}
                </p>
              </div>

              <div class="flex-shrink-0">
                <Link
                  v-if="item.acao && item.acao.rota"
                  :href="route(item.acao.rota)"
                  class="btn-secondary-sm whitespace-nowrap"
                >
                  {{ item.acao.texto }}
                </Link>
                <span v-else-if="item.acao" class="text-sm text-gray-400">{{ item.acao.texto }}</span>
              </div>
            </div>
          </li>
        </ul>
      </Card>

      <Card v-if="gruposComItens.length === 0">
        <p class="text-sm text-gray-600">Nenhum item de conformidade a mostrar.</p>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import { formatarDataHora } from '@/utils/formatDate';

const props = defineProps({
  checklist: {
    type: Object,
    required: true,
  },
});

const recalculando = ref(false);

const rotaDeValidades = computed(() => route('settings.validades.edit'));
const rotaDeReferencias = computed(() => route('conformidade.referencias.index'));

// `verificado_em` é um instante (UTC no banco). Formatado pelos utilitários do
// projeto, nunca por toLocaleString, que usaria o fuso do navegador.
const verificadoEm = computed(() =>
  props.checklist.verificado_em ? formatarDataHora(props.checklist.verificado_em) : null
);

// Irregulares primeiro, depois atenção, depois regulares e por último os que
// não se aplicam: a ordem da tela é a ordem da urgência, e quem abre esta tela
// abre para saber o que resolver.
const GRUPOS = [
  {
    chave: 'irregular',
    titulo: 'Irregulares',
    descricao: 'Precisam de providência: o sistema encontrou o documento vencido, cancelado ou ausente.',
  },
  {
    chave: 'atencao',
    titulo: 'Atenção',
    descricao: 'Vencem em breve ou dependem de conferência antes da próxima fiscalização.',
  },
  {
    chave: 'regular',
    titulo: 'Regulares',
    descricao: 'Sem pendência no que o sistema consegue verificar.',
  },
  {
    chave: 'nao_aplicavel',
    titulo: 'Não aplicáveis ou não informados',
    descricao:
      'Item que não se aplica à empresa, ou cujo dado ainda não foi preenchido. Campo em branco não é irregularidade.',
  },
];

const gruposComItens = computed(() =>
  GRUPOS.map((grupo) => ({
    ...grupo,
    itens: (props.checklist.itens || []).filter((item) => item.situacao === grupo.chave),
  })).filter((grupo) => grupo.itens.length > 0)
);

const TEXTOS = {
  irregular: 'Irregular',
  atencao: 'Atenção',
  regular: 'Regular',
  nao_aplicavel: 'Não aplicável',
};

const CORES = {
  irregular: 'bg-red-100 text-red-800',
  atencao: 'bg-yellow-100 text-yellow-800',
  regular: 'bg-green-100 text-green-800',
  nao_aplicavel: 'bg-gray-100 text-gray-800',
};

function textoDaSituacao(situacao) {
  return TEXTOS[situacao] || situacao;
}

function corDaSituacao(situacao) {
  return CORES[situacao] || CORES.nao_aplicavel;
}

function recalcular() {
  recalculando.value = true;

  router.post(
    route('conformidade.verificar'),
    {},
    {
      preserveScroll: true,
      onFinish: () => {
        recalculando.value = false;
      },
    }
  );
}
</script>
