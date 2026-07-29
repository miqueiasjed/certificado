<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Disponibilidade e agendamento online"
        description="Dias de atendimento, capacidade por período e o endereço público de agendamento."
      />
    </template>

    <div class="max-w-4xl mx-auto space-y-6">
      <div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>

      <form @submit.prevent="submit" class="space-y-6">
        <!-- Dias de atendimento -->
        <Card>
          <h3 class="text-lg font-medium text-gray-900 mb-4">Dias de atendimento</h3>

          <div class="flex flex-wrap gap-2">
            <button
              v-for="dia in DIAS_DA_SEMANA"
              :key="dia.valor"
              type="button"
              @click="alternarDia(dia.valor)"
              class="px-3 py-2 rounded-md text-sm font-medium border transition-colors"
              :class="form.dias_da_semana.includes(dia.valor)
                ? 'bg-green-600 border-green-600 text-white'
                : 'bg-white border-gray-300 text-gray-700 hover:bg-gray-50'"
            >
              {{ dia.rotulo }}
            </button>
          </div>
          <p v-if="form.errors.dias_da_semana" class="mt-2 text-sm text-red-600">{{ form.errors.dias_da_semana }}</p>
        </Card>

        <!-- Capacidade -->
        <Card>
          <h3 class="text-lg font-medium text-gray-900 mb-4">Capacidade e prazos</h3>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-6">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Visitas por período, por técnico</label>
              <input
                v-model.number="form.visitas_por_periodo"
                type="number"
                min="1"
                max="50"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.visitas_por_periodo }"
              />
              <p v-if="form.errors.visitas_por_periodo" class="mt-1 text-sm text-red-600">{{ form.errors.visitas_por_periodo }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Antecedência mínima (dias)</label>
              <input
                v-model.number="form.antecedencia_minima_dias"
                type="number"
                min="0"
                max="365"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.antecedencia_minima_dias }"
              />
              <p v-if="form.errors.antecedencia_minima_dias" class="mt-1 text-sm text-red-600">{{ form.errors.antecedencia_minima_dias }}</p>
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Janela máxima (dias)</label>
              <input
                v-model.number="form.janela_maxima_dias"
                type="number"
                min="1"
                max="365"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                :class="{ 'border-red-500': form.errors.janela_maxima_dias }"
              />
              <p v-if="form.errors.janela_maxima_dias" class="mt-1 text-sm text-red-600">{{ form.errors.janela_maxima_dias }}</p>
            </div>
          </div>
        </Card>

        <!-- Página pública -->
        <Card>
          <div class="flex items-start justify-between gap-4 mb-4">
            <div>
              <h3 class="text-lg font-medium text-gray-900">Página pública de agendamento</h3>
              <p class="text-sm text-gray-500 mt-1">
                Quando ligada, qualquer pessoa pode pedir horário pelo endereço abaixo, sem precisar de login.
              </p>
            </div>

            <button
              type="button"
              role="switch"
              :aria-checked="form.aceita_agendamento_online"
              @click="form.aceita_agendamento_online = !form.aceita_agendamento_online"
              class="shrink-0 relative inline-flex h-6 w-11 items-center rounded-full transition-colors"
              :class="form.aceita_agendamento_online ? 'bg-green-600' : 'bg-gray-300'"
            >
              <span
                class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"
                :class="form.aceita_agendamento_online ? 'translate-x-6' : 'translate-x-1'"
              ></span>
            </button>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Endereço da página *</label>
            <div class="flex flex-col sm:flex-row gap-2">
              <div class="flex-1 flex items-stretch rounded-md shadow-sm">
                <span class="inline-flex items-center px-3 rounded-l-md border border-r-0 border-gray-300 bg-gray-50 text-gray-500 text-sm truncate max-w-[40%] sm:max-w-none">
                  {{ urlPublicaBase }}
                </span>
                <input
                  v-model="form.slug_publico"
                  @input="normalizarSlugDigitado"
                  type="text"
                  placeholder="minha-empresa"
                  class="flex-1 min-w-0 px-3 py-2 border border-gray-300 rounded-r-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  :class="{ 'border-red-500': form.errors.slug_publico || erroDeFormatoDoSlug }"
                />
              </div>
              <button type="button" class="btn-secondary-sm shrink-0" :disabled="!form.slug_publico" @click="copiarUrl">
                {{ textoDoBotaoCopiar }}
              </button>
            </div>

            <p v-if="erroDeFormatoDoSlug" class="mt-1 text-sm text-red-600">{{ erroDeFormatoDoSlug }}</p>
            <p v-else-if="form.errors.slug_publico" class="mt-1 text-sm text-red-600">{{ form.errors.slug_publico }}</p>
            <p v-else class="mt-1 text-xs text-gray-500">
              Só letras minúsculas, números e hífen entre palavras. Sem espaço e sem acento.
            </p>

            <div v-if="mudouOSlugExistente" class="mt-3 bg-amber-50 border border-amber-200 rounded-md p-3">
              <p class="text-sm text-amber-800">
                Este endereço já foi divulgado como <strong>{{ urlPublicaBase }}{{ slugOriginal }}</strong>.
                Alterar quebra qualquer link já compartilhado com clientes: quem tiver o endereço antigo salvo
                deixa de encontrar a página.
              </p>
            </div>
          </div>
        </Card>

        <div class="flex justify-end">
          <button type="submit" class="btn-primary" :disabled="form.processing || !!erroDeFormatoDoSlug">
            {{ form.processing ? 'Salvando...' : 'Salvar configuração' }}
          </button>
        </div>
      </form>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const props = defineProps({
  configuracao: Object,
  slugPublico: String,
  urlPublicaBase: String,
});

const DIAS_DA_SEMANA = [
  { valor: 1, rotulo: 'Segunda' },
  { valor: 2, rotulo: 'Terça' },
  { valor: 3, rotulo: 'Quarta' },
  { valor: 4, rotulo: 'Quinta' },
  { valor: 5, rotulo: 'Sexta' },
  { valor: 6, rotulo: 'Sábado' },
  { valor: 7, rotulo: 'Domingo' },
];

// Minúsculas, dígitos e hífen simples entre blocos - mesma regra do
// backend (AtualizarDisponibilidadeRequest::REGRA_DE_FORMATO_DO_SLUG).
const REGRA_DO_SLUG = /^[a-z0-9]+(-[a-z0-9]+)*$/;

const slugOriginal = props.slugPublico || '';

const form = useForm({
  dias_da_semana: [...(props.configuracao?.dias_da_semana || [])],
  visitas_por_periodo: props.configuracao?.visitas_por_periodo ?? 4,
  antecedencia_minima_dias: props.configuracao?.antecedencia_minima_dias ?? 2,
  janela_maxima_dias: props.configuracao?.janela_maxima_dias ?? 60,
  aceita_agendamento_online: props.configuracao?.aceita_agendamento_online ?? false,
  slug_publico: slugOriginal,
});

const alternarDia = (valor) => {
  const posicao = form.dias_da_semana.indexOf(valor);

  if (posicao === -1) {
    form.dias_da_semana.push(valor);
  } else {
    form.dias_da_semana.splice(posicao, 1);
  }
};

// Normaliza o que a pessoa digita: minúsculas e sem espaço, para o erro de
// formato só aparecer em caractere que ela realmente escolheu manter (como
// acento ou símbolo), não em maiúscula/espaço que a maioria digita sem
// perceber.
const normalizarSlugDigitado = () => {
  form.slug_publico = (form.slug_publico || '').toLowerCase().replace(/\s+/g, '-');
};

const erroDeFormatoDoSlug = computed(() => {
  const valor = form.slug_publico || '';

  if (valor === '') {
    return form.aceita_agendamento_online
      ? 'Defina o endereço da página antes de ligar o agendamento online.'
      : '';
  }

  return REGRA_DO_SLUG.test(valor)
    ? ''
    : 'O endereço só pode ter letras minúsculas, números e hífen, sem espaço e sem acento.';
});

const mudouOSlugExistente = computed(() => {
  return slugOriginal !== '' && form.slug_publico !== slugOriginal;
});

const textoDoBotaoCopiar = ref('Copiar link');

const copiarUrl = async () => {
  const url = `${props.urlPublicaBase}${form.slug_publico}`;

  try {
    await navigator.clipboard.writeText(url);
    textoDoBotaoCopiar.value = 'Copiado!';
  } catch (erro) {
    textoDoBotaoCopiar.value = 'Não foi possível copiar';
  }

  setTimeout(() => {
    textoDoBotaoCopiar.value = 'Copiar link';
  }, 2000);
};

const submit = () => {
  if (erroDeFormatoDoSlug.value) {
    return;
  }

  form.put(route('settings.disponibilidade.update'), { preserveScroll: true });
};
</script>
