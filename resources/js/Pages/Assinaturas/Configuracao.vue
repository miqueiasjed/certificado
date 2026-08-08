<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Assinatura eletrônica"
        description="Credencial do provedor que assina os contratos desta empresa."
      />
    </template>

    <div class="max-w-3xl mx-auto space-y-6">
      <!-- Aviso permanente de sandbox: documento assinado em ambiente de teste
           não tem validade jurídica, e é exatamente o tipo de coisa que
           ninguém percebe até precisar do contrato. -->
      <div
        v-if="form.ambiente === 'sandbox'"
        class="rounded-md border border-amber-200 bg-amber-50 p-4"
      >
        <p class="text-sm font-medium text-amber-800">Ambiente de teste (sandbox)</p>
        <p class="mt-1 text-sm text-amber-700">
          Nada assinado neste ambiente tem validade jurídica. Use-o para percorrer o ciclo inteiro
          (enviar, assinar pelos dois lados, conferir o arquivo arquivado) e só então troque para
          produção.
        </p>
      </div>

      <div v-if="mensagem" class="rounded-md border border-green-200 bg-green-50 p-4">
        <p class="text-sm font-medium text-green-800">{{ mensagem }}</p>
      </div>
      <div v-if="erro" class="rounded-md border border-red-200 bg-red-50 p-4">
        <p class="text-sm font-medium text-red-800">{{ erro }}</p>
      </div>

      <Card>
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">Provedor</h3>
        </div>
        <div class="p-6 space-y-5">
          <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Provedor *</label>
              <select
                v-model="form.provedor"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              >
                <option v-for="opcao in provedores" :key="opcao" :value="opcao">{{ opcao }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-gray-700 mb-1">Ambiente *</label>
              <select
                v-model="form.ambiente"
                class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
              >
                <option v-for="opcao in ambientes" :key="opcao" :value="opcao">
                  {{ opcao === 'sandbox' ? 'Sandbox (teste)' : 'Produção' }}
                </option>
              </select>
            </div>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">Token da API</label>
            <input
              v-model="form.token"
              type="password"
              autocomplete="off"
              :placeholder="configuracao.possui_credencial ? 'Credencial já cadastrada. Preencha só para trocar.' : 'Cole aqui o token do provedor.'"
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
            <p class="mt-1 text-xs text-gray-500">
              O token nunca volta do servidor depois de salvo: o campo fica vazio de propósito.
              Deixe em branco para manter o que já está cadastrado.
            </p>
          </div>

          <div>
            <label class="block text-sm font-medium text-gray-700 mb-1">
              Segredo do webhook (opcional)
            </label>
            <input
              v-model="form.webhookSecret"
              type="password"
              autocomplete="off"
              placeholder="Preencha só se o provedor permitir cabeçalho próprio de autenticidade."
              class="w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
            />
          </div>

          <label class="flex items-center gap-2">
            <input
              v-model="form.ativo"
              type="checkbox"
              class="h-4 w-4 rounded border-gray-300 text-green-600 focus:ring-green-500"
            />
            <span class="text-sm text-gray-700">Integração ativa</span>
          </label>

          <div class="rounded-md bg-gray-50 border border-gray-200 p-4 text-sm text-gray-600">
            <p>
              <span class="font-medium text-gray-700">Última verificação:</span>
              {{ formatarDataHora(configuracao.verificado_em) || 'nunca verificada' }}
            </p>
            <p v-if="resultadoDaValidacao !== null" class="mt-1">
              <span class="font-medium text-gray-700">Resultado:</span>
              {{ resultadoDaValidacao ? 'credencial aceita pelo provedor' : 'credencial recusada pelo provedor' }}
            </p>
          </div>

          <div class="flex flex-wrap gap-3">
            <button type="button" class="btn-primary" :disabled="salvando" @click="salvar">
              {{ salvando ? 'Salvando...' : 'Salvar' }}
            </button>
            <button
              type="button"
              class="btn-secondary"
              :disabled="validando || !configuracao.possui_credencial"
              @click="validar"
            >
              {{ validando ? 'Validando...' : 'Validar credencial' }}
            </button>
            <button
              type="button"
              class="btn-secondary"
              :disabled="!configuracao.possui_credencial"
              @click="carregarWebhook"
            >
              Mostrar endereço do webhook
            </button>
          </div>

          <div v-if="webhookUrl" class="rounded-md border border-blue-200 bg-blue-50 p-4">
            <p class="text-sm font-medium text-blue-800">Endereço do webhook</p>
            <p class="mt-1 break-all font-mono text-xs text-blue-900">{{ webhookUrl }}</p>
            <p class="mt-2 text-sm text-blue-700">
              Cadastre este endereço no painel do provedor. Ele contém um segredo desta empresa:
              não compartilhe fora da configuração do provedor.
            </p>
          </div>
        </div>
      </Card>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import { formatarDataHora } from '@/utils/formatDate';

/**
 * Configuração da credencial do provedor de assinatura eletrônica (Plano 26,
 * Task 26.5).
 *
 * O campo de token nasce **sempre vazio**, e não preenchido com um valor
 * mascarado: a credencial nunca volta do servidor (ver
 * `SignatureProviderConfigController`), e um campo mascarado daria a impressão
 * de que ela está ali, sugerindo que dá para lê-la de volta pela tela.
 *
 * O endereço do webhook é pedido em separado, por botão, porque ele carrega o
 * segredo desta empresa: quem abre a tela só para conferir o ambiente não
 * precisa carregar o segredo junto.
 */
const props = defineProps({
  configuracao: { type: Object, required: true },
  provedores: { type: Array, default: () => [] },
  ambientes: { type: Array, default: () => [] },
});

const form = ref({
  provedor: props.configuracao.provedor || props.provedores[0] || '',
  ambiente: props.configuracao.ambiente || 'sandbox',
  token: '',
  webhookSecret: '',
  ativo: Boolean(props.configuracao.ativo),
});

const configuracao = ref({ ...props.configuracao });
const salvando = ref(false);
const validando = ref(false);
const mensagem = ref('');
const erro = ref('');
const resultadoDaValidacao = ref(null);
const webhookUrl = ref('');

const cabecalhos = () => ({
  'Content-Type': 'application/json',
  'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
  Accept: 'application/json',
});

const salvar = async () => {
  salvando.value = true;
  mensagem.value = '';
  erro.value = '';

  const credenciais = {};
  if (form.value.token) credenciais.token = form.value.token;
  if (form.value.webhookSecret) credenciais.webhook_secret = form.value.webhookSecret;

  try {
    const resposta = await fetch('/assinaturas/configuracao', {
      method: 'PUT',
      headers: cabecalhos(),
      body: JSON.stringify({
        provedor: form.value.provedor,
        ambiente: form.value.ambiente,
        credenciais: Object.keys(credenciais).length ? credenciais : undefined,
        ativo: form.value.ativo,
      }),
    });

    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) {
      erro.value = dados.message || 'Não foi possível salvar a configuração.';
      return;
    }

    mensagem.value = dados.message || 'Configuração salva.';
    resultadoDaValidacao.value = dados.credencial_valida ?? null;
    configuracao.value = {
      ...configuracao.value,
      provedor: form.value.provedor,
      ambiente: form.value.ambiente,
      ativo: form.value.ativo,
      possui_credencial: configuracao.value.possui_credencial || Boolean(form.value.token),
      verificado_em: dados.verificado_em || null,
    };
    // Nunca guarda o token digitado na memória da tela depois de salvo.
    form.value.token = '';
    form.value.webhookSecret = '';
  } catch (falha) {
    erro.value = 'Não foi possível falar com o servidor. Verifique a conexão e tente de novo.';
  } finally {
    salvando.value = false;
  }
};

const validar = async () => {
  validando.value = true;
  mensagem.value = '';
  erro.value = '';

  try {
    const resposta = await fetch('/assinaturas/configuracao/validar', {
      method: 'POST',
      headers: cabecalhos(),
    });

    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) {
      erro.value = dados.message || 'Não foi possível validar a credencial.';
      return;
    }

    resultadoDaValidacao.value = Boolean(dados.valida);
    mensagem.value = dados.message;
    configuracao.value = { ...configuracao.value, verificado_em: dados.verificado_em || null };
  } catch (falha) {
    erro.value = 'Não foi possível falar com o servidor. Verifique a conexão e tente de novo.';
  } finally {
    validando.value = false;
  }
};

const carregarWebhook = async () => {
  erro.value = '';

  try {
    const resposta = await fetch('/assinaturas/configuracao/webhook', {
      headers: { Accept: 'application/json' },
    });

    const dados = await resposta.json().catch(() => ({}));

    if (!resposta.ok) {
      erro.value = dados.message || 'Não foi possível montar o endereço do webhook.';
      return;
    }

    webhookUrl.value = dados.url;
  } catch (falha) {
    erro.value = 'Não foi possível falar com o servidor. Verifique a conexão e tente de novo.';
  }
};
</script>
