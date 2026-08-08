<template>
  <Card v-if="temModulo('laudo_ia') && pode('ia-gerar')" padding="none">
    <div class="px-6 py-4 border-b border-gray-200 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3">
      <div>
        <h3 class="text-lg font-medium text-gray-900">Sugestão de preço</h3>
        <p class="text-sm text-gray-500">
          Calculada a partir dos orçamentos aprovados da própria empresa nos últimos 24 meses.
        </p>
      </div>

      <button type="button" class="btn-secondary whitespace-nowrap" :disabled="carregando" @click="buscar">
        <span v-if="carregando">Consultando...</span>
        <span v-else>Consultar histórico</span>
      </button>
    </div>

    <div class="p-6 space-y-4">
      <div v-if="erro" class="rounded-md border border-red-300 bg-red-50 p-4">
        <p class="text-sm font-medium text-red-800">{{ erro }}</p>
      </div>

      <p v-if="!sugestao && !carregando && !erro" class="text-sm text-gray-500">
        Consulte o histórico para ver a faixa de preço já praticada em serviços parecidos.
      </p>

      <template v-if="sugestao">
        <!-- Amostra pequena não vira número. Preço sugerido a partir de dois
             orçamentos leva a empresa a errar com confiança. -->
        <div v-if="!sugestao.suficiente" class="rounded-md border border-yellow-300 bg-yellow-50 p-4">
          <p class="text-sm font-semibold text-yellow-900">
            Histórico insuficiente para sugerir preço
          </p>
          <p class="mt-1 text-sm text-yellow-800">
            Foram encontradas {{ sugestao.quantidade }} referência(s), e são necessárias pelo menos 5
            para uma faixa confiável.
          </p>
        </div>

        <div v-else class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Primeiro quartil</p>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ emReais(sugestao.primeiro_quartil) }}</p>
          </div>
          <div class="rounded-md border border-green-200 bg-green-50 p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-green-700">Mediana</p>
            <p class="mt-1 text-lg font-semibold text-green-900">{{ emReais(sugestao.mediana) }}</p>
          </div>
          <div class="rounded-md border border-gray-200 bg-gray-50 p-4">
            <p class="text-xs font-medium uppercase tracking-wide text-gray-500">Terceiro quartil</p>
            <p class="mt-1 text-lg font-semibold text-gray-900">{{ emReais(sugestao.terceiro_quartil) }}</p>
          </div>
        </div>

        <p v-if="sugestao.suficiente" class="text-sm text-gray-600">
          Faixa calculada sobre {{ sugestao.quantidade }} orçamento(s) aprovado(s) da própria empresa.
          O valor do orçamento não é preenchido automaticamente: a decisão comercial é de quem orça.
        </p>

        <!-- Só a justificativa passa pelo modelo. O número é estatística
             determinística, conferível à mão. -->
        <div v-if="justificativa" class="rounded-md border border-gray-200 p-4">
          <div class="flex items-center gap-2 mb-2">
            <span class="inline-flex items-center rounded-full bg-blue-100 px-2 py-0.5 text-xs font-medium text-blue-800">
              Gerado automaticamente
            </span>
            <span class="text-xs text-gray-500">Texto de apoio, não revisado</span>
          </div>
          <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ textoDaJustificativa }}</p>
        </div>

        <div v-if="sugestao.referencias.length" class="border-t border-gray-200 pt-4">
          <h4 class="text-sm font-medium text-gray-900 mb-3">Referências encontradas</h4>
          <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200">
              <thead class="bg-gray-50">
                <tr>
                  <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Data</th>
                  <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Ambiente</th>
                  <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Área</th>
                  <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Cômodos</th>
                  <th class="px-4 py-2 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Valor</th>
                </tr>
              </thead>
              <tbody class="bg-white divide-y divide-gray-200">
                <tr v-for="referencia in sugestao.referencias" :key="referencia.budget_id" class="hover:bg-gray-50">
                  <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-900">{{ formatarData(referencia.data) }}</td>
                  <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700">{{ referencia.ambiente || '-' }}</td>
                  <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700">{{ referencia.area ?? '-' }}</td>
                  <td class="px-4 py-2 whitespace-nowrap text-sm text-gray-700">{{ referencia.comodos ?? '-' }}</td>
                  <td class="px-4 py-2 whitespace-nowrap text-sm text-right text-gray-900">{{ emReais(referencia.valor) }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </template>
    </div>
  </Card>
</template>

<script setup>
import { computed, ref } from 'vue';
import Card from '@/Components/Card.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { useModulos } from '@/Composables/useModulos';
import { formatarData } from '@/utils/formatDate';

/**
 * Faixa de preço sugerida a partir do histórico da própria empresa
 * (Plano 25, Task 25.6).
 *
 * O componente **nunca preenche o campo de valor do orçamento**: ele exibe a
 * faixa ao lado, e quem digita é a pessoa. Valor preenchido sozinho é valor
 * que ninguém revisa, e preço é decisão comercial da empresa.
 *
 * O número (mediana e quartis) é estatística determinística calculada no
 * backend, sem passar por modelo nenhum. Só a justificativa em texto é
 * gerada automaticamente, e vem marcada como tal.
 */
const props = defineProps({
  budgetId: { type: [Number, String], default: null },
  criterios: { type: Object, default: () => ({}) },
});

const { pode } = usePermissoes();
const { temModulo } = useModulos();

const sugestao = ref(null);
const justificativa = ref(null);
const carregando = ref(false);
const erro = ref('');

const textoDaJustificativa = computed(
  () => justificativa.value?.conteudo_revisado ?? justificativa.value?.conteudo_gerado ?? ''
);

function emReais(valor) {
  if (valor === null || valor === undefined) {
    return '-';
  }

  return `R$ ${Number(valor).toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.')}`;
}

async function buscar() {
  carregando.value = true;
  erro.value = '';

  try {
    const resposta = await fetch(route('ia.precos.sugerir'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
      },
      body: JSON.stringify({ ...props.criterios, budget_id: props.budgetId }),
    });

    const corpo = await resposta.json();

    if (!resposta.ok) {
      erro.value = corpo?.message
        ?? 'Não foi possível consultar o histórico de preços. Tente novamente.';

      return;
    }

    sugestao.value = corpo.data.sugestao;
    justificativa.value = corpo.data.justificativa;
  } catch {
    erro.value = 'Não foi possível falar com o servidor. Verifique a conexão e tente novamente.';
  } finally {
    carregando.value = false;
  }
}
</script>
