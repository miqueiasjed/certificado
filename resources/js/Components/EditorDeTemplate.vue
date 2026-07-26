<template>
  <div
    class="fixed inset-0 bg-gray-600 bg-opacity-50 overflow-y-auto h-full w-full z-50 flex items-center justify-center p-4"
    @click.self="fechar"
  >
    <div class="relative bg-white rounded-xl shadow-xl max-w-4xl w-full mx-4 max-h-[92vh] flex flex-col">
      <!-- Cabeçalho -->
      <div class="flex items-start justify-between gap-4 px-6 py-4 border-b border-gray-200 flex-shrink-0">
        <div>
          <h3 class="text-lg font-semibold text-gray-900">Editar template</h3>
          <p class="text-sm text-gray-500">{{ rotuloEvento }} &middot; {{ rotuloCanal }}</p>
        </div>
        <button type="button" @click="fechar" class="text-gray-400 hover:text-gray-600 flex-shrink-0">
          <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
          </svg>
        </button>
      </div>

      <!-- Corpo -->
      <div class="px-6 py-4 overflow-y-auto flex-1">
        <div v-if="erro" class="mb-4 bg-red-50 border border-red-200 rounded-md p-4">
          <p class="text-sm font-medium text-red-800">{{ erro }}</p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
          <!-- Campos do template + pré-visualização -->
          <div class="lg:col-span-2 space-y-4">
            <div class="flex items-center gap-2">
              <span
                class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                :class="origem === 'tenant' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'"
              >
                {{ origem === 'tenant' ? 'Personalizado' : 'Padrão do sistema' }}
              </span>
            </div>

            <div v-if="ehEmail">
              <label class="block text-sm font-medium text-gray-700 mb-1">Assunto</label>
              <input
                ref="assuntoInputRef"
                v-model="form.assunto"
                type="text"
                @focus="campoFocado = 'assunto'"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              />
            </div>

            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Corpo da mensagem</label>
              <textarea
                ref="corpoTextareaRef"
                v-model="form.corpo"
                rows="10"
                @focus="campoFocado = 'corpo'"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500 font-mono text-sm"
              ></textarea>
            </div>

            <div class="flex items-center gap-2">
              <input id="template-ativo" v-model="form.ativo" type="checkbox" class="h-4 w-4 text-green-600 border-gray-300 rounded focus:ring-green-500" />
              <label for="template-ativo" class="text-sm text-gray-700">Canal ativo para este evento</label>
            </div>

            <div>
              <h4 class="text-sm font-medium text-gray-700 mb-2">Pré-visualização</h4>
              <div class="bg-gray-50 border border-gray-200 rounded-md p-4 space-y-2">
                <p v-if="ehEmail && previewAssunto" class="text-sm font-semibold text-gray-900">
                  {{ previewAssunto }}
                </p>
                <p class="text-sm text-gray-700 whitespace-pre-wrap">{{ previewCorpo || 'Digite o corpo da mensagem para ver a pré-visualização.' }}</p>
              </div>
              <p class="mt-1 text-xs text-gray-500">
                A pré-visualização usa dados de exemplo, só para conferir o texto. Nenhum envio acontece aqui.
              </p>
            </div>
          </div>

          <!-- Painel de variáveis -->
          <div>
            <h4 class="text-sm font-medium text-gray-700 mb-2">Variáveis disponíveis</h4>
            <p class="text-xs text-gray-500 mb-3">Clique para inserir no campo em edição.</p>
            <div class="flex flex-wrap gap-2 lg:flex-col lg:gap-1">
              <button
                v-for="variavel in variaveis"
                :key="variavel"
                type="button"
                @click="inserirVariavel(variavel)"
                class="text-left px-3 py-1.5 text-sm font-mono bg-green-50 text-green-800 border border-green-200 rounded-md hover:bg-green-100 transition-colors"
              >
                {{ marcadorDaVariavel(variavel) }}
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Rodapé -->
      <div class="px-6 py-4 border-t border-gray-200 flex items-center justify-between gap-3 flex-shrink-0">
        <button
          v-if="origem === 'tenant'"
          type="button"
          @click="mostrarConfirmacaoRestaurar = true"
          :disabled="salvando"
          class="text-sm text-red-600 hover:text-red-800 disabled:opacity-50 disabled:cursor-not-allowed"
        >
          Voltar ao padrão
        </button>
        <div v-else></div>
        <div class="flex gap-3">
          <button type="button" @click="fechar" :disabled="salvando" class="btn-secondary disabled:opacity-50 disabled:cursor-not-allowed">
            Cancelar
          </button>
          <button type="button" @click="salvar" :disabled="salvando" class="btn-primary disabled:opacity-50 disabled:cursor-not-allowed">
            {{ salvando ? 'Salvando...' : 'Salvar' }}
          </button>
        </div>
      </div>
    </div>

    <ConfirmDeleteModal
      :show="mostrarConfirmacaoRestaurar"
      title="Voltar ao padrão"
      subtitle="O texto personalizado deste canal será apagado."
      message="Tem certeza que deseja voltar a usar o texto padrão do sistema para"
      :item-name="`${rotuloEvento} (${rotuloCanal})`"
      confirm-text="Voltar ao padrão"
      processing-text="Restaurando..."
      :processing="restaurando"
      @confirm="restaurarPadrao"
      @cancel="mostrarConfirmacaoRestaurar = false"
    />
  </div>
</template>

<script setup>
import { computed, reactive, nextTick, ref } from 'vue';
import ConfirmDeleteModal from '@/Components/ConfirmDeleteModal.vue';

const props = defineProps({
  evento: {
    type: String,
    required: true,
  },
  rotuloEvento: {
    type: String,
    required: true,
  },
  canal: {
    type: String,
    required: true,
  },
  origem: {
    type: String,
    required: true,
  },
  assuntoInicial: {
    type: String,
    default: '',
  },
  corpoInicial: {
    type: String,
    default: '',
  },
  ativoInicial: {
    type: Boolean,
    default: true,
  },
  variaveis: {
    type: Array,
    default: () => [],
  },
});

const emit = defineEmits(['close', 'salvo', 'restaurado']);

// Dados de exemplo para a pré-visualização: nenhuma chamada ao backend, só uma
// troca de texto local enquanto o tenant digita. Cobre todas as variáveis do
// catálogo (App\Support\EventosDeNotificacao); variável fora daqui cai no
// fallback "[nome]", que ainda deixa claro que ali entra um dado.
const EXEMPLOS = {
  cliente_nome: 'Maria Souza',
  empresa_nome: 'Controle Pragas Ltda',
  empresa_telefone: '(11) 4002-8922',
  os_numero: 'OS-001234',
  data_visita: '28/07/2026',
  endereco: 'Rua das Flores, 123 - Centro',
  tecnico_nome: 'João Silva',
  certificado_numero: 'CERT-005678',
  data_vencimento: '15/08/2026',
  dias_para_vencer: '10',
  contrato_numero: 'CTR-000456',
  valor: 'R$ 350,00',
  dias_em_atraso: '5',
  orcamento_numero: 'ORC-000789',
  data_validade: '20/08/2026',
  dias_para_expirar: '3',
  data_prevista: '30/07/2026',
  rotina_nome: 'Geração de visitas periódicas',
  horario_esperado: '06:00',
  ultima_execucao: '25/07/2026 06:00',
  mensagem_erro: 'Falha de conexão com o banco de dados',
  data_execucao: '26/07/2026',
};

const ehEmail = computed(() => props.canal === 'email');
const rotuloCanal = computed(() => (ehEmail.value ? 'E-mail' : 'WhatsApp'));

const form = reactive({
  assunto: props.assuntoInicial || '',
  corpo: props.corpoInicial || '',
  ativo: props.ativoInicial,
});

const assuntoInputRef = ref(null);
const corpoTextareaRef = ref(null);
const campoFocado = ref('corpo');

const salvando = ref(false);
const restaurando = ref(false);
const erro = ref('');
const mostrarConfirmacaoRestaurar = ref(false);

function substituirVariaveis(texto) {
  if (!texto) return '';

  return texto.replace(/\{\{\s*([a-zA-Z0-9_]+)\s*\}\}/g, (correspondencia, nome) => EXEMPLOS[nome] ?? `[${nome}]`);
}

const previewAssunto = computed(() => substituirVariaveis(form.assunto));
const previewCorpo = computed(() => substituirVariaveis(form.corpo));

function marcadorDaVariavel(nome) {
  return `{{${nome}}}`;
}

function inserirVariavel(nome) {
  const trecho = marcadorDaVariavel(nome);
  const noAssunto = campoFocado.value === 'assunto' && ehEmail.value;
  const elemento = noAssunto ? assuntoInputRef.value : corpoTextareaRef.value;
  const textoAtual = noAssunto ? form.assunto : form.corpo;

  const inicio = elemento?.selectionStart ?? textoAtual.length;
  const fim = elemento?.selectionEnd ?? inicio;
  const novoTexto = textoAtual.slice(0, inicio) + trecho + textoAtual.slice(fim);

  if (noAssunto) {
    form.assunto = novoTexto;
  } else {
    form.corpo = novoTexto;
  }

  nextTick(() => {
    if (!elemento) return;
    elemento.focus();
    const novaPosicao = inicio + trecho.length;
    elemento.setSelectionRange(novaPosicao, novaPosicao);
  });
}

function cabecalhosJson() {
  return {
    'Content-Type': 'application/json',
    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
    Accept: 'application/json',
  };
}

function fechar() {
  if (salvando.value || restaurando.value) return;
  emit('close');
}

async function salvar() {
  salvando.value = true;
  erro.value = '';

  try {
    const resposta = await fetch(route('notificacoes.templates.update', [props.evento, props.canal]), {
      method: 'PUT',
      headers: cabecalhosJson(),
      body: JSON.stringify({
        assunto: form.assunto || '',
        corpo: form.corpo,
        ativo: form.ativo,
      }),
    });

    const dados = await resposta.json();

    if (!resposta.ok || !dados.success) {
      erro.value = dados.errors?.corpo?.[0] || dados.message || 'Não foi possível salvar o template.';
      return;
    }

    emit('salvo', dados.message);
  } catch (excecao) {
    erro.value = 'Não foi possível salvar o template. Tente novamente.';
  } finally {
    salvando.value = false;
  }
}

async function restaurarPadrao() {
  restaurando.value = true;
  erro.value = '';

  try {
    const resposta = await fetch(route('notificacoes.templates.destroy', [props.evento, props.canal]), {
      method: 'DELETE',
      headers: cabecalhosJson(),
    });

    const dados = await resposta.json();

    if (!resposta.ok || !dados.success) {
      erro.value = dados.message || 'Não foi possível voltar ao padrão.';
      mostrarConfirmacaoRestaurar.value = false;
      return;
    }

    mostrarConfirmacaoRestaurar.value = false;
    emit('restaurado', dados.message);
  } catch (excecao) {
    erro.value = 'Não foi possível voltar ao padrão. Tente novamente.';
    mostrarConfirmacaoRestaurar.value = false;
  } finally {
    restaurando.value = false;
  }
}
</script>
