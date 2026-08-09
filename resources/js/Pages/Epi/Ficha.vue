<!--
  Ficha de entrega de EPI de um técnico (Plano 28, Task 28.6).

  É o registro exigido pelo item 6.5.1 da NR-6 e a prova do empregador em
  reclamatória trabalhista. Três coisas nesta tela não são detalhe de layout:

  - O CA exibido é o DA ENTREGA, copiado no dia em que o técnico recebeu o
    equipamento, e não o do cadastro hoje. Renovar o certificado não reescreve a
    ficha do ano passado.
  - A entrega SEM ASSINATURA aparece marcada como pendência, com o caminho
    direto para assinar. Sem a assinatura, a entrega não é ficha válida.
  - A entrega ESTORNADA nunca some da lista: aparece marcada, com o motivo. A
    sequência "a errada, estornada com motivo, e a certa" é o que prova que a
    correção foi correção, e não reescrita. Não existe editar nem excluir
    entrega, e por isso esta tela não oferece nenhum dos dois.

  Técnico inativo continua com ficha acessível: o desligamento não apaga o que
  foi entregue enquanto ele trabalhou, e o arquivamento recomendado é de 20 anos.
-->
<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        :title="`Ficha de EPI — ${tecnico.name}`"
        :description="descricaoDoTecnico"
      >
        <template #actions>
          <button
            v-if="podeEntregar"
            type="button"
            class="btn-primary"
            @click="modalEntrega = true"
          >
            Registrar entrega
          </button>
          <a :href="route('epi.tecnicos.ficha-pdf', tecnico.id)" target="_blank" class="btn-secondary">
            Ficha em PDF
          </a>
          <Link :href="route('epi.index')" class="btn-secondary">Voltar</Link>
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

      <div v-if="!tecnico.is_active" class="bg-gray-100 border border-gray-200 rounded-md p-4">
        <p class="text-sm font-medium text-gray-800">Técnico inativo.</p>
        <p class="mt-1 text-sm text-gray-600">
          A ficha continua acessível e extraível: o desligamento não apaga o registro do que foi
          entregue enquanto ele trabalhou.
        </p>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <StatCard
          title="EPIs em uso"
          :value="emUso.length"
          color="green"
          subtitle="Entregues, não devolvidos e não estornados"
        />
        <StatCard
          title="Trocas vencidas"
          :value="trocasVencidas"
          color="red"
          subtitle="Item na mão do técnico passou do prazo"
        />
        <StatCard
          title="Pendentes de assinatura"
          :value="pendentes"
          color="yellow"
          subtitle="Sem a assinatura, a entrega não é ficha válida"
        />
      </div>

      <!-- Situação atual -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Em uso hoje</h3>
          <p class="text-sm text-gray-500">
            O que este técnico tem na mão. A troca é do item entregue e não se confunde com a
            validade do Certificado de Aprovação.
          </p>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">EPI</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Entregue em</th>
                <th class="px-6 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Qtd.</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trocar até</th>
                <th class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assinatura</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <tr v-for="entrega in emUso" :key="`uso-${entrega.id}`" class="hover:bg-gray-50">
                <td class="px-6 py-4 text-sm font-medium text-gray-900">
                  {{ entrega.personal_protective_equipment?.nome || 'EPI removido do cadastro' }}
                  <p class="text-xs text-gray-500">
                    {{ rotuloDeTipoDeEpi(entrega.personal_protective_equipment?.tipo) }}
                  </p>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-900">
                  {{ formatarData(entrega.entregue_em) }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                  {{ entrega.quantidade }}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <div class="text-gray-900">
                    <span v-if="entrega.trocar_ate">{{ formatarData(entrega.trocar_ate) }}</span>
                    <span v-else class="text-gray-400">—</span>
                  </div>
                  <span
                    class="mt-1 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                    :class="situacaoDaTroca(entrega).cor"
                  >
                    {{ situacaoDaTroca(entrega).rotulo }}
                  </span>
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm">
                  <span
                    v-if="entrega.assinado_em"
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-green-100 text-green-800"
                  >
                    Assinada
                  </span>
                  <span
                    v-else
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800"
                  >
                    {{ TEXTO_PENDENTE_DE_ASSINATURA }}
                  </span>
                </td>
              </tr>
              <tr v-if="emUso.length === 0">
                <td colspan="5" class="px-6 py-8 text-center text-sm text-gray-500">
                  Nenhum EPI em uso por este técnico no momento.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>

      <!-- Histórico completo -->
      <Card padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Histórico de entregas</h3>
          <p class="text-sm text-gray-500">
            O CA mostrado é o da entrega, copiado no dia em que o técnico recebeu o equipamento. A
            entrega não se edita: a correção de um erro é o estorno, com motivo, e a linha continua
            aqui marcada como estornada.
          </p>
        </div>
        <div class="overflow-x-auto">
          <table class="min-w-full divide-y divide-gray-200">
            <thead class="bg-gray-50">
              <tr>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Entregue em</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">EPI</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Qtd.</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">CA da entrega</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Trocar até</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assinatura</th>
                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Situação</th>
                <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase tracking-wider">Ações</th>
              </tr>
            </thead>
            <tbody class="bg-white divide-y divide-gray-200">
              <template v-for="entrega in entregas" :key="entrega.id">
              <tr :class="entrega.estornada_em ? 'bg-red-50' : 'hover:bg-gray-50'">
                <td class="px-4 py-4 whitespace-nowrap text-sm text-gray-900">
                  {{ formatarData(entrega.entregue_em) }}
                  <p class="text-xs text-gray-500">
                    {{ rotuloDeMotivoDeEntrega(entrega.motivo) }}
                    <template v-if="entrega.user"> · por {{ entrega.user.name }}</template>
                  </p>
                </td>
                <td class="px-4 py-4 text-sm text-gray-900">
                  {{ entrega.personal_protective_equipment?.nome || 'EPI removido do cadastro' }}
                  <p class="text-xs text-gray-500">
                    {{ rotuloDeTipoDeEpi(entrega.personal_protective_equipment?.tipo) }}
                    <template v-if="entrega.personal_protective_equipment?.fabricante">
                      · {{ entrega.personal_protective_equipment.fabricante }}
                    </template>
                  </p>
                </td>
                <td class="px-4 py-4 whitespace-nowrap text-sm text-right text-gray-900">
                  {{ entrega.quantidade }}
                </td>
                <td class="px-4 py-4 whitespace-nowrap text-sm">
                  <div class="text-gray-900">
                    <span v-if="entrega.numero_ca">{{ entrega.numero_ca }}</span>
                    <span v-else class="text-gray-400">Não informado</span>
                  </div>
                  <p class="text-xs text-gray-500">
                    <template v-if="entrega.validade_ca">
                      Validade {{ formatarData(entrega.validade_ca) }}
                    </template>
                    <template v-else>Sem validade registrada</template>
                  </p>
                </td>
                <td class="px-4 py-4 whitespace-nowrap text-sm">
                  <div class="text-gray-900">
                    <span v-if="entrega.trocar_ate">{{ formatarData(entrega.trocar_ate) }}</span>
                    <span v-else class="text-gray-400">—</span>
                  </div>
                  <span
                    v-if="!entrega.estornada_em && !entrega.devolvido_em"
                    class="mt-1 inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                    :class="situacaoDaTroca(entrega).cor"
                  >
                    {{ situacaoDaTroca(entrega).rotulo }}
                  </span>
                  <span v-else-if="!entrega.trocar_ate" class="text-xs text-gray-500">
                    {{ TEXTO_SEM_TROCA_PROGRAMADA }}
                  </span>
                </td>
                <td class="px-4 py-4 whitespace-nowrap text-sm">
                  <span v-if="entrega.assinado_em" class="text-gray-900">
                    {{ formatarDataHora(entrega.assinado_em) }}
                  </span>
                  <span
                    v-else-if="entrega.estornada_em"
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-800"
                  >
                    Sem assinatura
                  </span>
                  <span
                    v-else
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-yellow-100 text-yellow-800"
                  >
                    {{ TEXTO_PENDENTE_DE_ASSINATURA }}
                  </span>
                </td>
                <td class="px-4 py-4 whitespace-nowrap text-sm">
                  <span
                    class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium"
                    :class="situacaoDaEntrega(entrega).cor"
                  >
                    {{ situacaoDaEntrega(entrega).rotulo }}
                  </span>
                </td>
                <!-- Ações em texto, e não em botão cheio: são três por linha, e a
                     tabela do histórico já é larga. Com botões, a terceira ação (o
                     estorno) só aparecia depois de rolar a tabela para o lado. -->
                <td class="px-4 py-4 whitespace-nowrap text-sm text-right">
                  <div v-if="!entrega.estornada_em" class="flex justify-end gap-3">
                    <button
                      v-if="podeEntregar"
                      type="button"
                      class="text-xs font-medium text-green-700 hover:text-green-800 hover:underline"
                      @click="abrirAssinatura(entrega)"
                    >
                      {{ entrega.assinado_em ? 'Reassinar' : 'Assinar' }}
                    </button>
                    <button
                      v-if="podeEntregar && !entrega.devolvido_em"
                      type="button"
                      class="text-xs font-medium text-gray-700 hover:text-gray-900 hover:underline"
                      @click="abrirDevolucao(entrega)"
                    >
                      Devolver
                    </button>
                    <button
                      v-if="podeEstornar"
                      type="button"
                      class="text-xs font-medium text-red-700 hover:text-red-800 hover:underline"
                      @click="abrirEstorno(entrega)"
                    >
                      Estornar
                    </button>
                  </div>
                  <span v-else class="text-gray-400">—</span>
                </td>
              </tr>
              <!-- O motivo do estorno e o da devolução ocupam a linha inteira, em vez
                   de espremer um texto livre dentro de uma coluna: o motivo é o que
                   explica a correção, e precisa ser legível. -->
              <tr v-if="entrega.estornada_em" class="bg-red-50">
                <td colspan="8" class="px-4 pb-4 text-xs text-red-700">
                  Estornada em {{ formatarDataHora(entrega.estornada_em) }}:
                  {{ entrega.motivo_estorno || 'motivo não registrado' }}
                </td>
              </tr>
              <tr v-else-if="entrega.devolvido_em">
                <td colspan="8" class="px-4 pb-4 text-xs text-gray-500">
                  Devolvida em {{ formatarData(entrega.devolvido_em) }}
                  <template v-if="entrega.motivo_devolucao">: {{ entrega.motivo_devolucao }}</template>
                </td>
              </tr>
              </template>
              <tr v-if="entregas.length === 0">
                <td colspan="8" class="px-6 py-10 text-center text-sm text-gray-500">
                  Nenhuma entrega registrada para este técnico.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </Card>
    </div>

    <EntregaDeEpiModal
      :show="modalEntrega"
      :tecnico="tecnico"
      :epis="episDisponiveis"
      :motivos="motivos"
      @close="modalEntrega = false"
    />

    <!-- Assinatura de recebimento: a prova exigida pela NR-6. Reassinar corrige
         a MESMA linha, e nunca cria uma segunda entrega. -->
    <Modal :show="entregaParaAssinar !== null" @close="fecharAssinatura">
      <template #icon>
        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"
          />
        </svg>
      </template>

      <template #title>Assinatura de recebimento</template>

      <template #content>
        <div class="space-y-3">
          <p class="text-sm text-gray-600">
            {{ tecnico.name }} declara ter recebido
            <strong class="text-gray-900">
              {{ entregaParaAssinar?.quantidade }}
              {{ entregaParaAssinar?.personal_protective_equipment?.nome }}
            </strong>
            em {{ formatarData(entregaParaAssinar?.entregue_em) }}.
          </p>

          <QuadroDeAssinatura
            v-if="entregaParaAssinar"
            :key="entregaParaAssinar.id"
            ref="quadro"
            @mudou="(vazio) => (assinaturaVazia = vazio)"
          />

          <p v-if="erroDaAssinatura" class="text-sm text-red-600">{{ erroDaAssinatura }}</p>
          <p v-if="formAssinatura.errors.entrega" class="text-sm text-red-600">
            {{ formAssinatura.errors.entrega }}
          </p>
          <p v-if="formAssinatura.errors.assinatura" class="text-sm text-red-600">
            {{ formAssinatura.errors.assinatura }}
          </p>
        </div>
      </template>

      <template #actions>
        <button
          type="button"
          class="btn-primary"
          :disabled="assinaturaVazia || formAssinatura.processing"
          @click="confirmarAssinatura"
        >
          {{ formAssinatura.processing ? 'Gravando...' : 'Confirmar recebimento' }}
        </button>
        <button
          type="button"
          class="btn-secondary"
          :disabled="formAssinatura.processing"
          @click="fecharAssinatura"
        >
          Cancelar
        </button>
      </template>
    </Modal>

    <!-- Devolução: "esta entrega aconteceu e terminou". Não é estorno, que diz
         "esta entrega está errada". -->
    <Modal :show="entregaParaDevolver !== null" @close="fecharDevolucao">
      <template #icon>
        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"
          />
        </svg>
      </template>

      <template #title>Registrar devolução</template>

      <template #content>
        <div class="space-y-4">
          <p class="text-sm text-gray-600">
            {{ entregaParaDevolver?.personal_protective_equipment?.nome }}, entregue em
            {{ formatarData(entregaParaDevolver?.entregue_em) }}. O item devolvido deixa de contar
            como EPI em uso e continua na ficha.
          </p>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Motivo da devolução *</label>
            <input
              v-model="formDevolucao.motivo_devolucao"
              type="text"
              maxlength="255"
              placeholder="Troca, desligamento, dano..."
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': formDevolucao.errors.motivo_devolucao }"
            />
            <p v-if="formDevolucao.errors.motivo_devolucao" class="mt-1 text-sm text-red-600">
              {{ formDevolucao.errors.motivo_devolucao }}
            </p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Data da devolução</label>
            <input
              v-model="formDevolucao.devolvido_em"
              type="date"
              :max="hoje"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': formDevolucao.errors.devolvido_em }"
            />
            <p v-if="formDevolucao.errors.devolvido_em" class="mt-1 text-sm text-red-600">
              {{ formDevolucao.errors.devolvido_em }}
            </p>
          </div>

          <p v-if="formDevolucao.errors.entrega" class="text-sm text-red-600">
            {{ formDevolucao.errors.entrega }}
          </p>
        </div>
      </template>

      <template #actions>
        <button
          type="button"
          class="btn-primary"
          :disabled="formDevolucao.processing || !formDevolucao.motivo_devolucao"
          @click="confirmarDevolucao"
        >
          {{ formDevolucao.processing ? 'Registrando...' : 'Registrar devolução' }}
        </button>
        <button
          type="button"
          class="btn-secondary"
          :disabled="formDevolucao.processing"
          @click="fecharDevolucao"
        >
          Cancelar
        </button>
      </template>
    </Modal>

    <!-- Estorno: única correção possível de uma entrega errada, com motivo
         obrigatório. Nada de confirm() nativo. -->
    <Modal :show="entregaParaEstornar !== null" @close="fecharEstorno">
      <template #icon>
        <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path
            stroke-linecap="round"
            stroke-linejoin="round"
            stroke-width="2"
            d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"
          />
        </svg>
      </template>

      <template #title>Estornar entrega</template>

      <template #content>
        <div class="space-y-4">
          <p class="text-sm text-gray-700">
            {{ entregaParaEstornar?.personal_protective_equipment?.nome }}, entregue em
            {{ formatarData(entregaParaEstornar?.entregue_em) }}.
          </p>
          <p class="text-sm text-gray-600">
            A entrega não é apagada: ela permanece na ficha e na extração por período, marcada como
            estornada, e sai dos alertas. O motivo é o que transforma a correção em documento
            defensável.
          </p>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Motivo do estorno *</label>
            <textarea
              v-model="formEstorno.motivo_estorno"
              rows="3"
              maxlength="500"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': formEstorno.errors.motivo_estorno }"
            ></textarea>
            <p v-if="formEstorno.errors.motivo_estorno" class="mt-1 text-sm text-red-600">
              {{ formEstorno.errors.motivo_estorno }}
            </p>
          </div>

          <p v-if="formEstorno.errors.entrega" class="text-sm text-red-600">
            {{ formEstorno.errors.entrega }}
          </p>
        </div>
      </template>

      <template #actions>
        <button
          type="button"
          class="btn-danger"
          :disabled="formEstorno.processing || !formEstorno.motivo_estorno"
          @click="confirmarEstorno"
        >
          {{ formEstorno.processing ? 'Estornando...' : 'Estornar entrega' }}
        </button>
        <button
          type="button"
          class="btn-secondary"
          :disabled="formEstorno.processing"
          @click="fecharEstorno"
        >
          Cancelar
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, ref } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import StatCard from '@/Components/StatCard.vue';
import Modal from '@/Components/Modal.vue';
import EntregaDeEpiModal from '@/Components/EntregaDeEpiModal.vue';
import QuadroDeAssinatura from '@/app-tecnico/Components/QuadroDeAssinatura.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { formatarData, formatarDataHora, hojeISO } from '@/utils/formatDate';
import {
  TEXTO_PENDENTE_DE_ASSINATURA,
  TEXTO_SEM_TROCA_PROGRAMADA,
  pendenteDeAssinatura,
  rotuloDeMotivoDeEntrega,
  rotuloDeTipoDeEpi,
  situacaoDaEntrega,
  situacaoDaTroca,
} from '@/utils/epi';

const props = defineProps({
  tecnico: { type: Object, required: true },
  entregas: { type: Array, default: () => [] },
  episDisponiveis: { type: Array, default: () => [] },
  motivos: { type: Array, default: () => [] },
});

const { pode } = usePermissoes();

const podeEntregar = computed(() => pode('epi-entregar'));
// Permissão separada de propósito: o estorno altera prova documental. O nome é
// com hífen, como todo o catálogo do projeto.
const podeEstornar = computed(() => pode('epi-estornar'));

const hoje = hojeISO();

const modalEntrega = ref(false);
const entregaParaAssinar = ref(null);
const entregaParaDevolver = ref(null);
const entregaParaEstornar = ref(null);
const quadro = ref(null);
const assinaturaVazia = ref(true);
const erroDaAssinatura = ref('');

const formAssinatura = useForm({ assinatura: '' });
const formDevolucao = useForm({ motivo_devolucao: '', devolvido_em: '' });
const formEstorno = useForm({ motivo_estorno: '' });

const descricaoDoTecnico = computed(() => {
  const partes = [];

  if (props.tecnico.registration_number) {
    partes.push(`Matrícula ${props.tecnico.registration_number}`);
  }

  if (props.tecnico.email) {
    partes.push(props.tecnico.email);
  }

  partes.push(props.tecnico.is_active ? 'Técnico ativo' : 'Técnico inativo');

  return partes.join(' · ');
});

/**
 * Em uso é a entrega válida (não estornada) que ainda não foi devolvida. A
 * estornada fica de fora de todo cálculo de situação, e continua na lista de
 * histórico.
 */
const emUso = computed(() =>
  props.entregas.filter((entrega) => !entrega.estornada_em && !entrega.devolvido_em)
);

const trocasVencidas = computed(
  () => emUso.value.filter((entrega) => situacaoDaTroca(entrega).chave === 'vencida').length
);

const pendentes = computed(() => props.entregas.filter((entrega) => pendenteDeAssinatura(entrega)).length);

function abrirAssinatura(entrega) {
  formAssinatura.clearErrors();
  formAssinatura.assinatura = '';
  erroDaAssinatura.value = '';
  assinaturaVazia.value = true;
  entregaParaAssinar.value = entrega;
}

function fecharAssinatura() {
  entregaParaAssinar.value = null;
  assinaturaVazia.value = true;
  erroDaAssinatura.value = '';
}

async function confirmarAssinatura() {
  erroDaAssinatura.value = '';

  const imagem = await quadro.value?.exportarPng();

  if (!imagem) {
    erroDaAssinatura.value = 'Assine no quadro antes de confirmar o recebimento.';
    return;
  }

  formAssinatura.assinatura = imagem;
  formAssinatura.post(route('epi.entregas.assinar', entregaParaAssinar.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      formAssinatura.reset();
      fecharAssinatura();
    },
  });
}

function abrirDevolucao(entrega) {
  formDevolucao.clearErrors();
  formDevolucao.motivo_devolucao = '';
  formDevolucao.devolvido_em = hojeISO();
  entregaParaDevolver.value = entrega;
}

function fecharDevolucao() {
  entregaParaDevolver.value = null;
}

function confirmarDevolucao() {
  formDevolucao.post(route('epi.entregas.devolver', entregaParaDevolver.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      formDevolucao.reset();
      fecharDevolucao();
    },
  });
}

function abrirEstorno(entrega) {
  formEstorno.clearErrors();
  formEstorno.motivo_estorno = '';
  entregaParaEstornar.value = entrega;
}

function fecharEstorno() {
  entregaParaEstornar.value = null;
}

function confirmarEstorno() {
  formEstorno.post(route('epi.entregas.estornar', entregaParaEstornar.value.id), {
    preserveScroll: true,
    onSuccess: () => {
      formEstorno.reset();
      fecharEstorno();
    },
  });
}
</script>
