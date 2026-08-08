<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        :title="contract.contract_number || `Contrato #${contract.id}`"
        :description="address?.client?.name ? `Cliente: ${address.client.name}` : 'Detalhes do contrato'"
      >
        <template #actions>
          <Link href="/contracts" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Voltar
          </Link>
          <button type="button" class="btn-secondary" @click="gerarPDF">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 21h10a2 2 0 002-2V9.414a1 1 0 00-.293-.707l-5.414-5.414A1 1 0 0012.586 3H7a2 2 0 00-2 2v14a2 2 0 002 2z"></path>
            </svg>
            PDF
          </button>
          <Link v-if="pode('contrato-editar') && !bloqueadoParaEdicao" :href="`/contracts/${contract.id}/edit`" class="btn-primary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path>
            </svg>
            Editar
          </Link>
          <button v-if="pode('contrato-editar')" type="button" class="btn-danger" @click="abrirEncerramento">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
            </svg>
            Encerrar contrato
          </button>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-6xl mx-auto space-y-6">
      <!-- Mensagens Flash -->
      <div v-if="$page.props.flash.success" class="bg-green-50 border border-green-200 rounded-md p-4">
        <p class="text-sm font-medium text-green-800">{{ $page.props.flash.success }}</p>
      </div>
      <div v-if="$page.props.flash.error" class="bg-red-50 border border-red-200 rounded-md p-4">
        <p class="text-sm font-medium text-red-800">{{ $page.props.flash.error }}</p>
      </div>

      <!-- Contrato travado para edição: campo editável que não salva é pior
           que campo bloqueado com a explicação do caminho de saída. -->
      <div v-if="bloqueadoParaEdicao" class="rounded-md border border-blue-200 bg-blue-50 p-4">
        <p class="text-sm font-medium text-blue-800">Contrato em assinatura: somente leitura</p>
        <p class="mt-1 text-sm text-blue-700">
          Enquanto o pedido de assinatura estiver aberto, o texto do contrato não pode ser alterado —
          o cliente está lendo o PDF que já foi enviado, e mudar o texto agora faria a assinatura
          valer para uma versão diferente da que ele aceitou. Para editar, cancele o pedido de
          assinatura abaixo.
        </p>
      </div>

      <!-- Informações do Contrato -->
      <Card>
        <div class="px-6 py-4 border-b border-gray-200 flex items-center gap-3">
          <h3 class="text-lg font-medium text-gray-900">Informações do Contrato</h3>
          <span
            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
            :class="contract.service_type === 'periodico' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'"
          >
            {{ contract.service_type === 'periodico' ? 'Periódico' : 'Pontual' }}
          </span>
        </div>
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label class="block text-sm font-medium text-gray-500">Início</label>
              <div class="mt-1 text-sm text-gray-900">{{ formatarData(contract.start_date) }}</div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Término</label>
              <div class="mt-1 text-sm text-gray-900">{{ formatarData(contract.end_date) || 'Sem data de término' }}</div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Periodicidade</label>
              <div class="mt-1 text-sm text-gray-900">{{ textoPeriodicidade }}</div>
            </div>
            <div v-if="contract.visit_count">
              <label class="block text-sm font-medium text-gray-500">Total de visitas previstas</label>
              <div class="mt-1 text-sm text-gray-900">{{ contract.visit_count }}</div>
            </div>
            <div v-if="contract.service_value">
              <label class="block text-sm font-medium text-gray-500">Valor do serviço</label>
              <div class="mt-1 text-sm text-gray-900">R$ {{ formatarMoeda(contract.service_value) }}</div>
            </div>
            <div v-if="contract.pest_target">
              <label class="block text-sm font-medium text-gray-500">Praga-alvo</label>
              <div class="mt-1 text-sm text-gray-900">{{ contract.pest_target }}</div>
            </div>
            <div v-if="contract.payment_method">
              <label class="block text-sm font-medium text-gray-500">Forma de pagamento</label>
              <div class="mt-1 text-sm text-gray-900">{{ contract.payment_method }}</div>
            </div>
            <div v-if="contract.jurisdiction">
              <label class="block text-sm font-medium text-gray-500">Foro</label>
              <div class="mt-1 text-sm text-gray-900">{{ contract.jurisdiction }}</div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Cliente e Endereço -->
      <Card>
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Cliente e Endereço</h3>
        </div>
        <div class="p-6">
          <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
              <label class="block text-sm font-medium text-gray-500">Cliente</label>
              <div class="mt-1 text-sm text-gray-900">{{ address?.client?.name }}</div>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-500">Endereço</label>
              <div class="mt-1 text-sm text-gray-900">
                {{ address?.nickname }}<template v-if="address?.street">, {{ address.street }}, {{ address.number }}</template>
              </div>
              <div v-if="address?.city" class="text-sm text-gray-600">{{ address.city }}/{{ address.state }}</div>
            </div>
          </div>
        </div>
      </Card>

      <!-- Assinatura eletrônica (Plano 26, Task 26.5) -->
      <Card v-if="mostrarBlocoDeAssinatura">
        <div class="px-6 py-4 border-b border-gray-200 flex flex-wrap items-center gap-3">
          <h3 class="text-lg font-medium text-gray-900">Assinatura eletrônica</h3>
          <span
            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
            :class="CLASSE_DA_SITUACAO[situacaoDaAssinatura] || 'bg-gray-100 text-gray-800'"
          >
            {{ ROTULO_DA_SITUACAO[situacaoDaAssinatura] || 'Não enviado' }}
          </span>
          <span
            v-if="ambienteDeAssinatura === 'sandbox'"
            class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800"
            title="Documento assinado em sandbox não tem validade jurídica."
          >
            Ambiente de teste
          </span>
        </div>

        <div class="p-6 space-y-4">
          <p v-if="carregandoAssinatura" class="text-sm text-gray-500">Carregando situação...</p>

          <template v-else>
            <p v-if="situacaoDaAssinatura === 'nao_enviado'" class="text-sm text-gray-600">
              Este contrato ainda não foi enviado para assinatura eletrônica.
            </p>

            <!-- Recusa: o motivo é o que a empresa precisa ler para saber se
                 o caso é renegociação ou erro de cadastro. -->
            <div v-if="pedido?.motivo_recusa" class="rounded-md border border-red-200 bg-red-50 p-4">
              <p class="text-sm font-medium text-red-800">Motivo informado</p>
              <p class="mt-1 text-sm text-red-700">{{ pedido.motivo_recusa }}</p>
            </div>

            <div v-if="pedido" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
              <div>
                <label class="block text-sm font-medium text-gray-500">Enviado em</label>
                <div class="mt-1 text-sm text-gray-900">{{ formatarDataHora(pedido.enviado_em) || '—' }}</div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-500">Prazo para assinar</label>
                <div class="mt-1 text-sm text-gray-900">{{ formatarData(pedido.expira_em) || '—' }}</div>
              </div>
              <div>
                <label class="block text-sm font-medium text-gray-500">Concluído em</label>
                <div class="mt-1 text-sm text-gray-900">{{ formatarDataHora(pedido.concluido_em) || '—' }}</div>
              </div>
            </div>

            <!-- Linha do tempo por signatário: é o que a empresa consulta
                 quando o cliente diz que não recebeu. IP e navegador vêm do
                 provedor e compõem a trilha de auditoria. -->
            <div v-if="pedido?.signatarios?.length" class="overflow-x-auto">
              <table class="min-w-full divide-y divide-gray-200">
                <thead class="bg-gray-50">
                  <tr>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Signatário</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Papel</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Situação</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Assinado em</th>
                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">Origem</th>
                  </tr>
                </thead>
                <tbody class="bg-white divide-y divide-gray-200">
                  <tr v-for="signatario in pedido.signatarios" :key="signatario.id">
                    <td class="px-4 py-3 text-sm text-gray-900">
                      <div class="font-medium">{{ signatario.nome }}</div>
                      <div class="text-gray-500">{{ signatario.email }}</div>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600">
                      {{ ROTULO_DO_PAPEL[signatario.papel] || signatario.papel }} ({{ signatario.ordem }}º)
                    </td>
                    <td class="px-4 py-3 text-sm">
                      <span
                        class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                        :class="CLASSE_DO_SIGNATARIO[signatario.situacao] || 'bg-gray-100 text-gray-800'"
                      >
                        {{ ROTULO_DO_SIGNATARIO[signatario.situacao] || signatario.situacao }}
                      </span>
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600">
                      {{ formatarDataHora(signatario.assinado_em) || '—' }}
                    </td>
                    <td class="px-4 py-3 text-sm text-gray-600">
                      <div>{{ signatario.ip || '—' }}</div>
                      <div v-if="signatario.user_agent" class="text-xs text-gray-400 max-w-xs truncate" :title="signatario.user_agent">
                        {{ signatario.user_agent }}
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-if="erroDaAssinatura" class="rounded-md border border-red-200 bg-red-50 p-4">
              <p class="text-sm font-medium text-red-800">{{ erroDaAssinatura }}</p>
            </div>

            <div class="flex flex-wrap gap-3 pt-2">
              <button
                v-if="podeEnviarAssinatura && situacaoDaAssinatura === 'nao_enviado'"
                type="button"
                class="btn-primary"
                @click="mostrarEnvio = true"
              >
                Enviar para assinatura
              </button>

              <button
                v-if="podeEnviarAssinatura && pedidoEmAberto"
                type="button"
                class="btn-secondary"
                :disabled="processandoAssinatura"
                @click="mostrarReenvio = true"
              >
                Reenviar aviso
              </button>

              <button
                v-if="podeEnviarAssinatura && pedidoEmAberto"
                type="button"
                class="btn-danger"
                :disabled="processandoAssinatura"
                @click="mostrarCancelamento = true"
              >
                Cancelar pedido
              </button>

              <a
                v-if="pedido?.tem_arquivo_assinado"
                :href="`/contratos/${contract.id}/assinatura/${pedido.id}/documento`"
                class="btn-secondary"
              >
                Baixar contrato assinado
              </a>
            </div>
          </template>
        </div>
      </Card>

      <!-- Visitas do contrato -->
      <VisitasDoContrato :contrato="contract" :visitas="visitas" />

      <!-- Histórico de alterações -->
      <HistoricoRegistro tipo="contrato" :id="contract.id" />
    </div>

    <!-- Modal de Encerramento de Contrato: cancela as visitas futuras não
         executadas e fecha a vigência. É o caminho que a recusa de exclusão
         de contrato com visita já executada indica ao usuário. -->
    <Modal :show="mostrarEncerramento" @close="cancelarEncerramento">
      <template #icon>
        <svg class="h-6 w-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
        </svg>
      </template>
      <template #title>Encerrar contrato</template>
      <template #content>
        <p class="text-sm text-gray-700 mb-4">
          {{ mensagemEncerramento }}
          Visitas já executadas não são alteradas.
        </p>
        <label class="block text-sm font-medium text-gray-700 mb-1">Motivo do encerramento</label>
        <textarea
          v-model="motivoEncerramento"
          rows="3"
          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          placeholder="Explique por que o contrato está sendo encerrado (opcional). Este texto é anexado a cada visita futura cancelada."
        ></textarea>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="encerrandoContrato" @click="cancelarEncerramento">
          Cancelar
        </button>
        <button type="button" class="btn-danger ml-3" :disabled="encerrandoContrato" @click="confirmarEncerramento">
          {{ encerrandoContrato ? 'Encerrando...' : 'Encerrar contrato' }}
        </button>
      </template>
    </Modal>

    <!-- Envio para assinatura, com pré-visualização do PDF. -->
    <EnvioParaAssinaturaModal
      v-if="mostrarBlocoDeAssinatura"
      :show="mostrarEnvio"
      :contrato="contract"
      :address="address"
      :usuario="$page.props.auth?.user"
      :ambiente="ambienteDeAssinatura"
      @close="mostrarEnvio = false"
      @enviado="aoEnviar"
    />

    <!-- Reenvio: dispara e-mail de verdade para quem ainda não assinou, então
         pede confirmação em vez de agir no primeiro clique. -->
    <Modal :show="mostrarReenvio" @close="mostrarReenvio = false">
      <template #icon>
        <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path>
        </svg>
      </template>
      <template #title>Reenviar aviso de assinatura</template>
      <template #content>
        <p class="text-sm text-gray-700">
          Isto dispara um novo e-mail do provedor para quem ainda não assinou. Nenhum documento novo
          é criado: é o mesmo contrato, com a mesma assinatura pendente.
        </p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="processandoAssinatura" @click="mostrarReenvio = false">
          Cancelar
        </button>
        <button type="button" class="btn-primary ml-3" :disabled="processandoAssinatura" @click="reenviarAssinatura">
          {{ processandoAssinatura ? 'Reenviando...' : 'Reenviar aviso' }}
        </button>
      </template>
    </Modal>

    <!-- Cancelamento do pedido: encerra no provedor e devolve o contrato ao
         estado editável. Ação destrutiva, com o efeito descrito e sem
         `confirm()` nativo. -->
    <Modal :show="mostrarCancelamento" @close="mostrarCancelamento = false">
      <template #icon>
        <svg class="h-6 w-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"></path>
        </svg>
      </template>
      <template #title>Cancelar pedido de assinatura</template>
      <template #content>
        <p class="text-sm text-gray-700 mb-4">
          O documento deixa de aceitar assinatura no provedor, quem ainda não assinou não vai mais
          conseguir, e o contrato volta a aceitar alteração. Quem já assinou perde o efeito dessa
          assinatura. Para retomar, é preciso enviar o contrato de novo.
        </p>
        <label class="block text-sm font-medium text-gray-700 mb-1">Motivo do cancelamento</label>
        <textarea
          v-model="motivoDoCancelamento"
          rows="3"
          class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          placeholder="Opcional. Fica registrado no pedido e no provedor."
        ></textarea>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="processandoAssinatura" @click="mostrarCancelamento = false">
          Voltar
        </button>
        <button type="button" class="btn-danger ml-3" :disabled="processandoAssinatura" @click="cancelarAssinatura">
          {{ processandoAssinatura ? 'Cancelando...' : 'Sim, cancelar pedido' }}
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import EnvioParaAssinaturaModal from '@/Components/EnvioParaAssinaturaModal.vue';
import VisitasDoContrato from '@/Components/VisitasDoContrato.vue';
import HistoricoRegistro from '@/Components/HistoricoRegistro.vue';
import { formatarData, formatarDataHora } from '@/utils/formatDate';
import { usePermissoes } from '@/Composables/usePermissoes';
import { useModulos } from '@/Composables/useModulos';

const props = defineProps({
  contract: {
    type: Object,
    required: true,
  },
  address: {
    type: Object,
    default: null,
  },
  visitas: {
    type: Array,
    default: () => [],
  },
  visitasFuturasCount: {
    type: Number,
    default: 0,
  },
});

const { pode } = usePermissoes();
const { temModulo } = useModulos();

// -----------------------------------------------------------------
// Assinatura eletrônica (Plano 26, Task 26.5)
// -----------------------------------------------------------------
//
// A situação vem por `fetch` do endpoint JSON, e não como prop da página, de
// propósito: o bloco só existe para quem tem o módulo e a permissão, e a tela
// do contrato é aberta o tempo todo por quem não tem nenhum dos dois. Buscar
// sob demanda evita carregar em toda visita um dado que quase ninguém vê, e
// mantém `ContractController::show()` sem saber de assinatura nenhuma.

const ROTULO_DA_SITUACAO = {
  nao_enviado: 'Não enviado',
  em_assinatura: 'Em assinatura',
  assinado: 'Assinado',
  recusado: 'Recusado',
};

const CLASSE_DA_SITUACAO = {
  nao_enviado: 'bg-gray-100 text-gray-800',
  em_assinatura: 'bg-blue-100 text-blue-800',
  assinado: 'bg-green-100 text-green-800',
  recusado: 'bg-red-100 text-red-800',
};

const ROTULO_DO_SIGNATARIO = {
  pendente: 'Aguardando',
  visualizou: 'Visualizou',
  assinou: 'Assinou',
  recusou: 'Recusou',
};

const CLASSE_DO_SIGNATARIO = {
  pendente: 'bg-yellow-100 text-yellow-800',
  visualizou: 'bg-blue-100 text-blue-800',
  assinou: 'bg-green-100 text-green-800',
  recusou: 'bg-red-100 text-red-800',
};

const ROTULO_DO_PAPEL = {
  contratante: 'Contratante',
  contratada: 'Contratada',
  testemunha: 'Testemunha',
};

const SITUACOES_EM_ABERTO = ['rascunho', 'enviado', 'visualizado'];

const podeEnviarAssinatura = computed(() => pode('contrato-enviar-assinatura'));
const mostrarBlocoDeAssinatura = computed(
  () => temModulo('assinatura_eletronica') && podeEnviarAssinatura.value
);

const pedido = ref(null);
const situacaoDaAssinatura = ref(props.contract.situacao_assinatura || 'nao_enviado');
const ambienteDeAssinatura = ref(null);
const carregandoAssinatura = ref(false);
const processandoAssinatura = ref(false);
const erroDaAssinatura = ref('');
const mostrarEnvio = ref(false);
const mostrarReenvio = ref(false);
const mostrarCancelamento = ref(false);
const motivoDoCancelamento = ref('');

const pedidoEmAberto = computed(
  () => pedido.value !== null && SITUACOES_EM_ABERTO.includes(pedido.value.situacao)
);

// Trava a edição pelo estado que o backend grava, não pela existência do
// pedido: é `ContractService` quem recusa a alteração, e a tela precisa dizer
// exatamente a mesma coisa que ele.
const bloqueadoParaEdicao = computed(() => situacaoDaAssinatura.value === 'em_assinatura');

const cabecalhosJson = () => ({
  'Content-Type': 'application/json',
  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
  Accept: 'application/json',
});

const aplicarResposta = (dados) => {
  pedido.value = dados.pedido || null;
  situacaoDaAssinatura.value = dados.contrato?.situacao_assinatura || situacaoDaAssinatura.value;
  ambienteDeAssinatura.value = dados.ambiente ?? ambienteDeAssinatura.value;
};

const carregarAssinatura = async () => {
  if (!mostrarBlocoDeAssinatura.value) return;

  carregandoAssinatura.value = true;
  erroDaAssinatura.value = '';

  try {
    const resposta = await fetch(`/contratos/${props.contract.id}/assinatura`, {
      headers: { Accept: 'application/json' },
    });

    if (!resposta.ok) {
      erroDaAssinatura.value = 'Não foi possível carregar a situação da assinatura.';
      return;
    }

    aplicarResposta(await resposta.json());
  } catch (falha) {
    erroDaAssinatura.value = 'Não foi possível falar com o servidor para carregar a assinatura.';
  } finally {
    carregandoAssinatura.value = false;
  }
};

onMounted(carregarAssinatura);

const aoEnviar = () => {
  mostrarEnvio.value = false;
  carregarAssinatura();
};

const acaoNoPedido = async (caminho, corpo = {}) => {
  if (!pedido.value) return;

  processandoAssinatura.value = true;
  erroDaAssinatura.value = '';

  try {
    const resposta = await fetch(
      `/contratos/${props.contract.id}/assinatura/${pedido.value.id}/${caminho}`,
      { method: 'POST', headers: cabecalhosJson(), body: JSON.stringify(corpo) }
    );

    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) {
      erroDaAssinatura.value = dados.message || 'Não foi possível concluir a ação.';
      return;
    }

    await carregarAssinatura();
  } catch (falha) {
    erroDaAssinatura.value = 'Não foi possível falar com o servidor. Verifique a conexão e tente de novo.';
  } finally {
    processandoAssinatura.value = false;
    mostrarReenvio.value = false;
    mostrarCancelamento.value = false;
  }
};

const reenviarAssinatura = () => acaoNoPedido('reenviar');

const cancelarAssinatura = async () => {
  await acaoNoPedido('cancelar', { motivo: motivoDoCancelamento.value || null });
  motivoDoCancelamento.value = '';
};

const UNIDADE_LABEL = {
  dias: (valor) => (valor === 1 ? 'dia' : 'dias'),
  semanas: (valor) => (valor === 1 ? 'semana' : 'semanas'),
  meses: (valor) => (valor === 1 ? 'mês' : 'meses'),
};

const textoPeriodicidade = computed(() => {
  const valor = props.contract.visit_frequency_valor;
  const unidade = props.contract.visit_frequency_unidade;

  if (props.contract.service_type !== 'periodico') {
    return 'Não se aplica (contrato pontual)';
  }

  if (!valor || !unidade) {
    return 'Não configurada';
  }

  const rotuloUnidade = UNIDADE_LABEL[unidade] ? UNIDADE_LABEL[unidade](valor) : unidade;

  return `A cada ${valor} ${rotuloUnidade}`;
});

const formatarMoeda = (valor) => {
  if (!valor) return '0,00';
  return parseFloat(valor).toLocaleString('pt-BR', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
};

const gerarPDF = () => {
  if (!props.address?.id) return;
  window.open(`/addresses/${props.address.id}/contract/pdf`, '_blank');
};

// Encerramento de contrato: cancela as visitas futuras não executadas e fecha
// a vigência (grava end_date). `visitasFuturasCount` vem calculado pelo
// controller, para o aviso ser exato sem precisar de uma segunda requisição.
const mostrarEncerramento = ref(false);
const motivoEncerramento = ref('');
const encerrandoContrato = ref(false);

const mensagemEncerramento = computed(() => {
  const quantidade = props.visitasFuturasCount;

  if (quantidade === 0) {
    return 'Este contrato não tem visita futura agendada para cancelar. O encerramento só fecha a vigência.';
  }

  return quantidade === 1
    ? 'Isso cancela 1 visita futura ainda não executada deste contrato.'
    : `Isso cancela ${quantidade} visitas futuras ainda não executadas deste contrato.`;
});

const abrirEncerramento = () => {
  motivoEncerramento.value = '';
  mostrarEncerramento.value = true;
};

const cancelarEncerramento = () => {
  if (encerrandoContrato.value) return;
  mostrarEncerramento.value = false;
  motivoEncerramento.value = '';
};

const confirmarEncerramento = () => {
  encerrandoContrato.value = true;

  router.post(`/contracts/${props.contract.id}/encerrar`, {
    motivo: motivoEncerramento.value,
  }, {
    preserveScroll: true,
    onFinish: () => {
      encerrandoContrato.value = false;
      mostrarEncerramento.value = false;
      motivoEncerramento.value = '';
    },
  });
};
</script>
