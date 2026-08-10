<!--
  Criação de compromisso avulso pela Agenda (Plano 30, Task 30.5).

  Por que fetch() direto, e não `useForm` da Inertia
  ----------------------------------------------------
  `POST /compromissos` devolve JSON puro (`CompromissoController::store`), no
  mesmo padrão de `reagendarOrdem`/`atribuirTecnico`, que `Agenda/Index.vue` já
  usa: sem redirecionamento, sem `Inertia::render()` no meio. `useForm().post()`
  espera o protocolo de resposta da Inertia; este modal segue o mesmo `fetch()`
  com CSRF manual que o resto do módulo de Agenda já usa.

  Por que a busca de cliente não é uma chamada JSON direta
  ------------------------------------------------------------
  Este plano não criou um endpoint de busca de cliente por nome em JSON puro, e
  criar um está fora do escopo desta task (que é só frontend, sobre o
  calendário/agenda): mexer em `ClientController`/rotas de cliente arrisca
  colidir com outro trabalho em andamento na mesma branch. A única fonte de
  clientes por nome que já existe é a própria página Inertia `/clients`
  (`ClientController::index`, que já filtra por nome/e-mail/CNPJ a partir de 2
  caracteres). Sem o cabeçalho `X-Inertia`, essa rota devolve o HTML da página
  inteira, como qualquer página Inertia visitada direto pelo navegador, e é
  exatamente esse HTML que este modal lê, extraindo o mesmo payload que
  `@inertia` (`app.blade.php`) embute no atributo `data-page` do `<div
  id="app">`, o mesmo dado que o Vue leria de `usePage()` se a página fosse
  aquela. Nenhum cabeçalho de versão da Inertia é enviado (evita o 409 de
  "versão mudou"), e qualquer falha aqui é silenciosa: dá zero resultado, nunca
  quebra o formulário. Fica atrás da permissão `cliente-ver`: quem não pode ver
  cliente nem enxerga o campo de busca, e usa direto o endereço em texto livre.
-->
<template>
  <Modal :show="show" @close="fechar">
    <template #icon>
      <svg class="h-6 w-6 text-amber-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path
          stroke-linecap="round"
          stroke-linejoin="round"
          stroke-width="2"
          d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"
        />
      </svg>
    </template>

    <template #title>Novo compromisso</template>

    <template #content>
      <div class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Tipo *</label>
            <select
              v-model="form.tipo"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': erros.tipo }"
            >
              <option value="" disabled>Escolha o tipo</option>
              <option v-for="tipo in TIPOS_DE_COMPROMISSO" :key="tipo" :value="tipo">
                {{ rotuloDeTipoDeCompromisso(tipo) }}
              </option>
            </select>
            <p v-if="erros.tipo" class="mt-1 text-sm text-red-600">{{ erros.tipo[0] }}</p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Título *</label>
            <input
              v-model="form.titulo"
              type="text"
              maxlength="255"
              placeholder="Ex.: Orçamento de dedetização"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': erros.titulo }"
            />
            <p v-if="erros.titulo" class="mt-1 text-sm text-red-600">{{ erros.titulo[0] }}</p>
          </div>
        </div>

        <!-- Cliente: busca com debounce, atrás da permissão de ver cliente -->
        <div v-if="podeVerClientes">
          <label class="block text-sm font-medium text-gray-700 mb-1">Cliente</label>

          <div
            v-if="clienteSelecionado"
            class="flex items-center justify-between px-3 py-2 border border-green-200 bg-green-50 rounded-md"
          >
            <span class="text-sm font-medium text-green-800">{{ clienteSelecionado.name }}</span>
            <button type="button" class="text-green-600 hover:text-green-800 p-1" @click="limparCliente">
              <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
              </svg>
            </button>
          </div>

          <div v-else ref="buscaClienteEl" class="relative">
            <input
              v-model="termoDeBuscaDeCliente"
              type="text"
              placeholder="Buscar por nome, e-mail ou CNPJ (mín. 2 letras)"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              @input="aoDigitarCliente"
              @focus="resultadosAbertos = Boolean(termoDeBuscaDeCliente)"
            />

            <div
              v-if="resultadosAbertos"
              class="absolute z-20 mt-1 w-full max-h-56 overflow-auto rounded-md border border-gray-200 bg-white shadow-lg"
            >
              <p v-if="buscandoCliente" class="px-3 py-2 text-sm text-gray-500">Buscando...</p>
              <template v-else>
                <button
                  v-for="cliente in resultadosDeCliente"
                  :key="cliente.id"
                  type="button"
                  class="block w-full text-left px-3 py-2 text-sm hover:bg-green-50"
                  @click="selecionarCliente(cliente)"
                >
                  <span class="font-medium text-gray-900">{{ cliente.name }}</span>
                  <span v-if="cliente.cnpj || cliente.email" class="block text-xs text-gray-500">
                    {{ cliente.cnpj || cliente.email }}
                  </span>
                </button>
                <p v-if="!resultadosDeCliente.length" class="px-3 py-2 text-sm text-gray-500">
                  {{
                    termoDeBuscaDeCliente.trim().length < MINIMO_PARA_BUSCAR
                      ? `Digite ao menos ${MINIMO_PARA_BUSCAR} letras.`
                      : 'Nenhum cliente encontrado.'
                  }}
                </p>
              </template>
            </div>
          </div>
          <p v-if="erros.client_id" class="mt-1 text-sm text-red-600">{{ erros.client_id[0] }}</p>
        </div>

        <!-- Endereço: escolha entre os cadastrados do cliente, ou texto livre -->
        <div>
          <div class="flex items-center justify-between mb-1">
            <label class="block text-sm font-medium text-gray-700">Endereço</label>
            <button
              v-if="clienteSelecionado && enderecosDoCliente.length > 0"
              type="button"
              class="text-xs font-semibold text-green-700 hover:text-green-800"
              @click="usarEnderecoLivre = !usarEnderecoLivre"
            >
              {{ usarEnderecoLivre ? 'Usar endereço cadastrado' : 'Digitar outro endereço' }}
            </button>
          </div>

          <select
            v-if="mostrarSelectDeEndereco"
            v-model="form.address_id"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            :class="{ 'border-red-500': erros.address_id }"
          >
            <option :value="null">Selecione o endereço...</option>
            <option v-for="endereco in enderecosDoCliente" :key="endereco.id" :value="endereco.id">
              {{ rotuloDoEndereco(endereco) }}
            </option>
          </select>

          <input
            v-else
            v-model="form.endereco_texto"
            type="text"
            maxlength="255"
            placeholder="Rua, número, bairro, cidade..."
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            :class="{ 'border-red-500': erros.endereco_texto }"
          />

          <p v-if="carregandoEnderecos" class="mt-1 text-xs text-gray-400">Carregando endereços do cliente...</p>
          <p
            v-else-if="clienteSelecionado && enderecosDoCliente.length === 0"
            class="mt-1 text-xs text-gray-500"
          >
            Este cliente não tem endereço cadastrado.
          </p>
          <p v-if="erros.address_id" class="mt-1 text-sm text-red-600">{{ erros.address_id[0] }}</p>
          <p v-if="erros.endereco_texto" class="mt-1 text-sm text-red-600">{{ erros.endereco_texto[0] }}</p>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Técnico</label>
          <select
            v-model="form.technician_id"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            :class="{ 'border-red-500': erros.technician_id }"
          >
            <option :value="null">Sem técnico</option>
            <option v-for="tecnico in tecnicos" :key="tecnico.id" :value="tecnico.id">{{ tecnico.name }}</option>
          </select>
          <p v-if="erros.technician_id" class="mt-1 text-sm text-red-600">{{ erros.technician_id[0] }}</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Data *</label>
            <input
              v-model="form.data"
              type="date"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': erros.data }"
            />
            <p v-if="erros.data" class="mt-1 text-sm text-red-600">{{ erros.data[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Início</label>
            <input
              v-model="form.hora_inicio"
              type="time"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': erros.hora_inicio }"
            />
            <p v-if="erros.hora_inicio" class="mt-1 text-sm text-red-600">{{ erros.hora_inicio[0] }}</p>
          </div>
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Término</label>
            <input
              v-model="form.hora_fim"
              type="time"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              :class="{ 'border-red-500': erros.hora_fim }"
            />
            <p v-if="erros.hora_fim" class="mt-1 text-sm text-red-600">{{ erros.hora_fim[0] }}</p>
          </div>
        </div>

        <div>
          <label class="block text-sm font-medium text-gray-700 mb-1">Observações</label>
          <textarea
            v-model="form.observacoes"
            rows="3"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            :class="{ 'border-red-500': erros.observacoes }"
          ></textarea>
          <p v-if="erros.observacoes" class="mt-1 text-sm text-red-600">{{ erros.observacoes[0] }}</p>
        </div>

        <p v-if="erroGeral" class="text-sm text-red-600">{{ erroGeral }}</p>
      </div>
    </template>

    <template #actions>
      <button
        type="button"
        class="btn-primary"
        :disabled="processando || !form.tipo || !form.titulo.trim() || !form.data"
        @click="criar"
      >
        {{ processando ? 'Criando...' : 'Criar compromisso' }}
      </button>
      <button type="button" class="btn-secondary" :disabled="processando" @click="fechar">Cancelar</button>
    </template>
  </Modal>
</template>

<script setup>
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';
import Modal from '@/Components/Modal.vue';
import { usePermissoes } from '@/Composables/usePermissoes';
import { tokenCsrf } from '@/utils/agendaApi';
import { hojeISO } from '@/utils/formatDate';
import { TIPOS_DE_COMPROMISSO, rotuloDeTipoDeCompromisso } from '@/utils/compromisso';

const props = defineProps({
  show: { type: Boolean, default: false },
  tecnicos: { type: Array, default: () => [] },
  // Data sugerida para o novo compromisso: o dia que a Agenda está mostrando no
  // momento em que o usuário abriu o modal. Cai para hoje se não vier nada.
  dataInicial: { type: String, default: '' },
});

const emit = defineEmits(['close', 'criado']);

const MINIMO_PARA_BUSCAR = 2;

const { pode } = usePermissoes();
const podeVerClientes = computed(() => pode('cliente-ver'));

function estadoInicialDoFormulario() {
  return {
    tipo: '',
    titulo: '',
    address_id: null,
    endereco_texto: '',
    technician_id: null,
    data: props.dataInicial || hojeISO(),
    hora_inicio: '',
    hora_fim: '',
    observacoes: '',
  };
}

const form = reactive(estadoInicialDoFormulario());
const erros = reactive({});
const erroGeral = ref(null);
const processando = ref(false);

// --- Cliente: busca com debounce -----------------------------------------------------
const termoDeBuscaDeCliente = ref('');
const resultadosDeCliente = ref([]);
const resultadosAbertos = ref(false);
const buscandoCliente = ref(false);
const clienteSelecionado = ref(null);
const buscaClienteEl = ref(null);
let temporizadorDeBusca = null;

/**
 * Lê a página Inertia `/clients?search=...` como HTML puro e extrai o payload
 * embutido no `data-page` do `<div id="app">` (ver o comentário no topo do
 * arquivo). Qualquer falha devolve lista vazia, nunca lança.
 */
async function buscarClientesNaPaginaDeClientes(termo) {
  const resposta = await fetch(`/clients?search=${encodeURIComponent(termo)}`, {
    headers: { Accept: 'text/html' },
    credentials: 'same-origin',
  });

  if (!resposta.ok) return [];

  const html = await resposta.text();
  const pagina = new DOMParser().parseFromString(html, 'text/html');
  const bruto = pagina.getElementById('app')?.getAttribute('data-page');

  if (!bruto) return [];

  const payload = JSON.parse(bruto);

  return payload?.props?.clients?.data ?? [];
}

function aoDigitarCliente() {
  clearTimeout(temporizadorDeBusca);
  resultadosAbertos.value = true;

  if (termoDeBuscaDeCliente.value.trim().length < MINIMO_PARA_BUSCAR) {
    resultadosDeCliente.value = [];
    return;
  }

  temporizadorDeBusca = setTimeout(async () => {
    buscandoCliente.value = true;

    try {
      resultadosDeCliente.value = await buscarClientesNaPaginaDeClientes(termoDeBuscaDeCliente.value.trim());
    } catch (erro) {
      resultadosDeCliente.value = [];
    } finally {
      buscandoCliente.value = false;
    }
  }, 400);
}

function selecionarCliente(cliente) {
  clienteSelecionado.value = cliente;
  form.address_id = null;
  usarEnderecoLivre.value = false;
  termoDeBuscaDeCliente.value = '';
  resultadosDeCliente.value = [];
  resultadosAbertos.value = false;
  buscarEnderecosDoCliente(cliente.id);
}

function limparCliente() {
  clienteSelecionado.value = null;
  form.address_id = null;
  enderecosDoCliente.value = [];
  usarEnderecoLivre.value = false;
}

function aoClicarFora(evento) {
  if (buscaClienteEl.value && !buscaClienteEl.value.contains(evento.target)) {
    resultadosAbertos.value = false;
  }
}

onMounted(() => document.addEventListener('click', aoClicarFora));
onUnmounted(() => {
  document.removeEventListener('click', aoClicarFora);
  clearTimeout(temporizadorDeBusca);
});

// --- Endereço do cliente selecionado --------------------------------------------------
const enderecosDoCliente = ref([]);
const carregandoEnderecos = ref(false);
const usarEnderecoLivre = ref(false);

// Endpoint JSON já existente (`ClientController::getAddresses`), o mesmo que
// `Budgets/BudgetForm.vue` usa: não é a rota Inertia de clientes, é uma rota `/api/`
// que sempre devolve JSON puro.
async function buscarEnderecosDoCliente(clientId) {
  carregandoEnderecos.value = true;
  enderecosDoCliente.value = [];

  try {
    const resposta = await fetch(`/api/clients/${clientId}/addresses`, {
      headers: { Accept: 'application/json' },
    });

    if (!resposta.ok) return;

    const dados = await resposta.json();
    enderecosDoCliente.value = dados.addresses || [];
  } catch (erro) {
    enderecosDoCliente.value = [];
  } finally {
    carregandoEnderecos.value = false;
  }
}

function rotuloDoEndereco(endereco) {
  const linha = [endereco.street, endereco.number].filter(Boolean).join(', ');
  const complemento = [endereco.district, endereco.city].filter(Boolean).join(' - ');
  const base = [linha, complemento].filter(Boolean).join(' - ') || 'Endereço sem detalhes';

  return endereco.nickname ? `${endereco.nickname} (${base})` : base;
}

// Mesma condição decide o que aparece na tela (select vs. texto livre) e o que vai
// no corpo da requisição (`criar()`), para as duas nunca divergirem.
const mostrarSelectDeEndereco = computed(
  () => Boolean(clienteSelecionado.value) && enderecosDoCliente.value.length > 0 && !usarEnderecoLivre.value
);

// --- Ciclo de vida do modal ------------------------------------------------------------
watch(
  () => props.show,
  (aberto) => {
    if (!aberto) return;

    Object.assign(form, estadoInicialDoFormulario());
    Object.keys(erros).forEach((chave) => delete erros[chave]);
    erroGeral.value = null;
    clienteSelecionado.value = null;
    termoDeBuscaDeCliente.value = '';
    resultadosDeCliente.value = [];
    resultadosAbertos.value = false;
    enderecosDoCliente.value = [];
    usarEnderecoLivre.value = false;
  }
);

function fechar() {
  emit('close');
}

// Mesmo critério de `Agenda/Index.vue`: primeiro campo com erro de validação, senão
// a mensagem geral que o controller mandou (`message` do Laravel ou `mensagem` do
// `CompromissoController`).
function primeiraMensagemDeErro(dados) {
  const primeiraChave = dados?.errors ? Object.keys(dados.errors)[0] : null;

  return primeiraChave ? dados.errors[primeiraChave][0] : dados?.message || dados?.mensagem || null;
}

async function criar() {
  processando.value = true;
  erroGeral.value = null;
  Object.keys(erros).forEach((chave) => delete erros[chave]);

  const corpo = {
    tipo: form.tipo,
    titulo: form.titulo,
    client_id: clienteSelecionado.value?.id ?? null,
    address_id: mostrarSelectDeEndereco.value ? form.address_id : null,
    endereco_texto: mostrarSelectDeEndereco.value ? null : form.endereco_texto || null,
    technician_id: form.technician_id,
    data: form.data,
    hora_inicio: form.hora_inicio || null,
    hora_fim: form.hora_fim || null,
    observacoes: form.observacoes || null,
  };

  try {
    const resposta = await fetch(route('compromissos.store'), {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': tokenCsrf(),
        Accept: 'application/json',
      },
      body: JSON.stringify(corpo),
    });

    const dados = await resposta.json();

    if (!resposta.ok) {
      if (dados.errors) {
        Object.assign(erros, dados.errors);
      }

      erroGeral.value = primeiraMensagemDeErro(dados) || 'Não foi possível criar o compromisso.';

      return;
    }

    emit('criado', dados.compromisso);
  } catch (erro) {
    erroGeral.value = 'Não foi possível criar o compromisso. Tente novamente.';
  } finally {
    processando.value = false;
  }
}
</script>
