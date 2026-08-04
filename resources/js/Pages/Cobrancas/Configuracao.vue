<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Configuração de cobrança" description="Credencial do gateway de pagamento e régua de cobrança automática.">
        <template #actions>
          <Link href="/cobrancas" class="btn-secondary">
            <svg class="w-4 h-4 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
            </svg>
            Cobranças
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-4xl mx-auto space-y-6">
      <!-- Aviso permanente de sandbox: reflete o ambiente salvo no servidor, não o
           select ainda não confirmado, senão trocar a opção sem clicar em "Salvar"
           faria o aviso sumir com o ambiente real continuando em sandbox. -->
      <div v-if="props.gateway.ambiente === 'sandbox'" class="rounded-md border border-red-300 bg-red-50 p-4">
        <div class="flex items-start gap-3">
          <svg class="h-5 w-5 shrink-0 text-red-600 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" />
          </svg>
          <div class="min-w-0">
            <p class="text-sm font-medium text-red-800">
              Ambiente de teste (sandbox) ativo. Nenhuma cobrança emitida agora é real.
            </p>
            <p class="text-sm text-red-700 mt-1">
              Enviar um boleto ou Pix de teste para um cliente de verdade é o erro mais constrangedor deste módulo.
              Troque para "Produção" abaixo só quando a credencial de produção estiver validada.
            </p>
          </div>
        </div>
      </div>

      <Alert
        v-if="mensagemResultado"
        :key="alertaChave"
        :type="mensagemResultado.tipo"
        :title="mensagemResultado.titulo"
        :message="mensagemResultado.detalhe"
      />

      <!-- Credencial do gateway -->
      <Card>
        <div class="flex items-center justify-between mb-4">
          <h3 class="text-lg font-medium text-gray-900">Credencial do gateway de pagamento</h3>
          <span class="text-xs text-gray-500">PagBank</span>
        </div>

        <div class="rounded-md p-3 mb-4" :class="gateway.possui_credencial ? 'bg-green-50' : 'bg-gray-50'">
          <p class="text-sm font-medium" :class="gateway.possui_credencial ? 'text-green-800' : 'text-gray-600'">
            {{ gateway.possui_credencial ? 'Credencial configurada' : 'Nenhuma credencial configurada ainda' }}
          </p>
          <p v-if="gateway.possui_credencial" class="text-xs mt-1" :class="gateway.verificado_em ? 'text-green-700' : 'text-amber-700'">
            {{ gateway.verificado_em ? `Verificada em ${formatarDataHora(gateway.verificado_em)}` : 'Ainda não verificada com o provedor.' }}
          </p>
        </div>

        <div v-if="resultadoValidacao" class="rounded-md p-3 mb-4" :class="resultadoValidacao.ok ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'">
          <p class="text-sm font-medium">{{ resultadoValidacao.mensagem }}</p>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Ambiente *</label>
            <select
              v-model="formCredencial.ambiente"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            >
              <option value="sandbox">Sandbox (teste)</option>
              <option value="producao">Produção</option>
            </select>
          </div>
          <div class="flex items-end">
            <label class="inline-flex items-center gap-2">
              <input v-model="formCredencial.ativo" type="checkbox" class="h-4 w-4 text-green-600 border-gray-300 rounded" />
              <span class="text-sm text-gray-700">Gateway ativo (permite emitir cobranças)</span>
            </label>
          </div>
        </div>

        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-1">Token de acesso</label>
          <input
            v-model="formCredencial.token"
            type="password"
            autocomplete="off"
            :placeholder="gateway.possui_credencial ? 'Preenchido - deixe em branco para manter o atual' : 'Cole o token da conta PagBank'"
            class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
          />
          <p class="mt-1 text-xs text-gray-500">
            Por segurança, o token salvo nunca é mostrado aqui. Deixe em branco para manter o token atual e mudar
            só o ambiente ou a ativação.
          </p>
        </div>

        <div class="flex flex-wrap gap-3">
          <button type="button" class="btn-primary" :disabled="salvandoCredencial" @click="salvarCredencial">
            {{ salvandoCredencial ? 'Salvando...' : 'Salvar credencial' }}
          </button>
          <button
            type="button"
            class="btn-secondary"
            :disabled="!gateway.possui_credencial || validando"
            :title="!gateway.possui_credencial ? 'Salve uma credencial antes de validar.' : ''"
            @click="validarCredencial"
          >
            {{ validando ? 'Validando...' : 'Validar credencial' }}
          </button>
        </div>
      </Card>

      <!-- Régua de cobrança -->
      <Card>
        <h3 class="text-lg font-medium text-gray-900 mb-4">Régua de cobrança</h3>

        <label class="inline-flex items-center gap-2 mb-4">
          <input v-model="formRegua.regua_ativa" type="checkbox" class="h-4 w-4 text-green-600 border-gray-300 rounded" />
          <span class="text-sm text-gray-700">Régua ativa (avisos automáticos de vencimento)</span>
        </label>

        <div class="mb-4">
          <label class="block text-sm font-medium text-gray-700 mb-2">Marcos ativos</label>
          <div class="space-y-2">
            <label v-for="marco in regua.marcos_disponiveis" :key="marco" class="flex items-center gap-2">
              <input
                type="checkbox"
                :checked="marcosSelecionados.has(marco)"
                class="h-4 w-4 text-green-600 border-gray-300 rounded"
                @change="alternarMarco(marco)"
              />
              <span class="text-sm text-gray-700">{{ MARCO_LABEL[marco] || marco }}</span>
            </label>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 mb-4">
          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Antecedência da emissão automática (dias) *</label>
            <input
              v-model.number="formRegua.antecedencia_emissao_dias"
              type="number"
              min="1"
              max="60"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
            <p class="mt-1 text-xs text-gray-500">Quantos dias antes do vencimento a cobrança da próxima parcela do contrato é emitida sozinha.</p>
          </div>
          <div class="flex items-end">
            <label class="inline-flex items-center gap-2">
              <input v-model="formRegua.emissao_pelo_cliente_habilitada" type="checkbox" class="h-4 w-4 text-green-600 border-gray-300 rounded" />
              <span class="text-sm text-gray-700">Cliente pode gerar a própria cobrança pelo portal</span>
            </label>
          </div>
        </div>

        <p v-if="erroRegua" class="mb-3 text-sm text-red-600">{{ erroRegua }}</p>

        <button type="button" class="btn-primary" :disabled="salvandoRegua" @click="salvarRegua">
          {{ salvandoRegua ? 'Salvando...' : 'Salvar régua' }}
        </button>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Alert from '@/Components/Alert.vue';
import { formatarDataHora } from '@/utils/formatDate';

// Formato exato de `gateway` e `regua`: `ChargeController::configuracao()`
// (Task 19.6/19.7). `gateway.possui_credencial`/`verificado_em` são a única
// informação sobre a credencial salva que chega aqui - o valor cifrado nunca
// sai do backend, ver o cabeçalho de `App\Models\PaymentGatewayConfig`.
const props = defineProps({
  gateway: {
    type: Object,
    default: () => ({ gateway: null, ambiente: null, possui_credencial: false, ativo: false, verificado_em: null }),
  },
  regua: {
    type: Object,
    default: () => ({
      regua_ativa: true,
      marcos_ativos: null,
      marcos_disponiveis: [],
      antecedencia_emissao_dias: 10,
      emissao_pelo_cliente_habilitada: false,
    }),
  },
});

const MARCO_LABEL = {
  antes_3: '3 dias antes do vencimento',
  no_dia: 'No dia do vencimento',
  depois_3: '3 dias depois do vencimento',
  depois_7: '7 dias depois do vencimento',
  depois_15: '15 dias depois do vencimento',
};

function csrfToken() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
}

const mensagemResultado = ref(null);
const alertaChave = ref(0);

function mostrarResultado(tipo, titulo, detalhe) {
  mensagemResultado.value = { tipo, titulo, detalhe };
  alertaChave.value += 1;
}

// -----------------------------------------------------------------
// Credencial: o campo de token nasce sempre vazio, nunca com o valor salvo
// (regra inegociável - ver o cabeçalho de `App\Models\PaymentGatewayConfig`
// e `ChargeController::configuracao()`). Só entra no corpo da requisição se
// o usuário digitar algo.
// -----------------------------------------------------------------

const formCredencial = reactive({
  ambiente: props.gateway.ambiente || 'sandbox',
  ativo: props.gateway.ativo,
  token: '',
});

const salvandoCredencial = ref(false);

async function salvarCredencial() {
  salvandoCredencial.value = true;

  const payload = {
    ambiente: formCredencial.ambiente,
    ativo: formCredencial.ativo,
  };

  if (formCredencial.token.trim() !== '') {
    payload.credenciais = { token: formCredencial.token.trim() };
  }

  try {
    const resposta = await fetch('/cobrancas/configuracao', {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
        Accept: 'application/json',
      },
      body: JSON.stringify(payload),
    });

    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) {
      mostrarResultado('error', 'Não foi possível salvar', dados.message || Object.values(dados.errors ?? {}).flat()[0] || 'Tente novamente.');
      return;
    }

    formCredencial.token = '';
    resultadoValidacao.value = null;
    mostrarResultado('success', 'Credencial salva', dados.message);
    router.reload({ only: ['gateway'] });
  } finally {
    salvandoCredencial.value = false;
  }
}

// -----------------------------------------------------------------
// Validar credencial: resposta imediata, sem esperar a próxima cobrança
// real para descobrir um token errado.
// -----------------------------------------------------------------

const validando = ref(false);
const resultadoValidacao = ref(null);

async function validarCredencial() {
  validando.value = true;
  resultadoValidacao.value = null;

  try {
    const resposta = await fetch('/cobrancas/configuracao/validar', {
      method: 'POST',
      headers: {
        'X-CSRF-TOKEN': csrfToken(),
        Accept: 'application/json',
      },
    });

    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) {
      resultadoValidacao.value = { ok: false, mensagem: dados.message || 'Não foi possível validar agora.' };
      return;
    }

    resultadoValidacao.value = { ok: dados.valida, mensagem: dados.message };

    if (dados.valida) {
      router.reload({ only: ['gateway'] });
    }
  } finally {
    validando.value = false;
  }
}

// -----------------------------------------------------------------
// Régua de cobrança
// -----------------------------------------------------------------

const formRegua = reactive({
  regua_ativa: props.regua.regua_ativa,
  antecedencia_emissao_dias: props.regua.antecedencia_emissao_dias,
  emissao_pelo_cliente_habilitada: props.regua.emissao_pelo_cliente_habilitada,
});

// `marcos_ativos` nulo significa "todos ativos" (ver `CompanyBillingSetting::marcosAtivos()`).
const marcosSelecionados = ref(new Set(props.regua.marcos_ativos ?? props.regua.marcos_disponiveis));

function alternarMarco(marco) {
  const novo = new Set(marcosSelecionados.value);

  if (novo.has(marco)) {
    novo.delete(marco);
  } else {
    novo.add(marco);
  }

  marcosSelecionados.value = novo;
}

const salvandoRegua = ref(false);
const erroRegua = ref('');

async function salvarRegua() {
  salvandoRegua.value = true;
  erroRegua.value = '';

  try {
    const resposta = await fetch('/cobrancas/configuracao', {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': csrfToken(),
        Accept: 'application/json',
      },
      body: JSON.stringify({
        regua_ativa: formRegua.regua_ativa,
        marcos_ativos: Array.from(marcosSelecionados.value),
        antecedencia_emissao_dias: Number(formRegua.antecedencia_emissao_dias),
        emissao_pelo_cliente_habilitada: formRegua.emissao_pelo_cliente_habilitada,
      }),
    });

    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) {
      erroRegua.value = dados.message || Object.values(dados.errors ?? {}).flat()[0] || 'Não foi possível salvar a régua.';
      return;
    }

    mostrarResultado('success', 'Régua salva', dados.message);
    router.reload({ only: ['regua'] });
  } finally {
    salvandoRegua.value = false;
  }
}
</script>
