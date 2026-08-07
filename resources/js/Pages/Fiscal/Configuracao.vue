<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Configuração fiscal" description="Dados usados na emissão de NFS-e da empresa.">
        <template #actions>
          <Link v-if="pode('fiscal-ver')" href="/notas" class="btn-secondary">Ver notas</Link>
        </template>
      </PageHeader>
    </template>

    <div class="mx-auto max-w-4xl space-y-6">
      <div v-if="ambientePersistido === 'homologacao'" class="rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-900" role="alert">
        Ambiente de homologação ativo. Toda emissão será um teste e não deve ser enviada ao cliente como documento válido.
      </div>

      <Alert
        v-if="alerta"
        :key="alertaChave"
        :type="alerta.tipo"
        :title="alerta.titulo"
        :message="alerta.mensagem"
        persistent
      />

      <Card>
        <form class="space-y-8" @submit.prevent="salvar">
          <section>
            <h2 class="text-lg font-medium text-gray-900">Provedor e ambiente</h2>
            <div class="mt-4 grid grid-cols-1 gap-5 sm:grid-cols-2">
              <CampoSelect v-model="form.provedor" label="Provedor" erro-chave="provedor" :erros="erros">
                <option value="nuvem_fiscal">Nuvem Fiscal</option>
              </CampoSelect>
              <CampoSelect v-model="form.ambiente" label="Ambiente" erro-chave="ambiente" :erros="erros">
                <option value="homologacao">Homologação</option>
                <option value="producao">Produção</option>
              </CampoSelect>
            </div>
          </section>

          <section class="border-t border-gray-200 pt-6">
            <h2 class="text-lg font-medium text-gray-900">Credencial</h2>
            <p class="mt-1 text-sm text-gray-600">
              Os campos sempre aparecem vazios. {{ possuiCredencial ? 'Deixe ambos vazios para manter a credencial salva.' : 'Informe os dois campos para validar e ativar a configuração.' }}
            </p>
            <div class="mt-4 grid grid-cols-1 gap-5 sm:grid-cols-2">
              <Campo v-model="form.client_id" label="Client ID" autocomplete="off" erro-chave="client_id" :erros="erros" />
              <Campo v-model="form.client_secret" label="Client secret" type="password" autocomplete="new-password" erro-chave="client_secret" :erros="erros" />
            </div>
            <p v-if="erros.credenciais" class="mt-2 text-sm text-red-600">{{ erros.credenciais[0] }}</p>
          </section>

          <section class="border-t border-gray-200 pt-6">
            <h2 class="text-lg font-medium text-gray-900">Tributação do serviço</h2>
            <div class="mt-4 grid grid-cols-1 gap-5 sm:grid-cols-2">
              <CampoSelect v-model="form.regime_tributario" label="Regime tributário" erro-chave="regime_tributario" :erros="erros">
                <option value="simples_nacional">Simples Nacional</option>
                <option value="mei">MEI</option>
                <option value="lucro_presumido">Lucro Presumido</option>
                <option value="lucro_real">Lucro Real</option>
              </CampoSelect>
              <Campo v-model="form.codigo_servico" label="Código nacional do serviço" erro-chave="codigo_servico" :erros="erros" />
              <Campo v-model="form.cnae" label="CNAE" erro-chave="cnae" :erros="erros" />
              <Campo v-model="form.aliquota_iss" label="Alíquota do ISS (%)" type="number" min="0" max="100" step="0.01" erro-chave="aliquota_iss" :erros="erros" />
              <CampoSelect v-model="form.natureza_operacao" label="Natureza da operação" erro-chave="natureza_operacao" :erros="erros">
                <option value="tributacao_no_municipio">Tributação no município</option>
                <option value="tributacao_fora_municipio">Tributação fora do município</option>
                <option value="isencao">Isenção</option>
                <option value="imunidade">Imunidade</option>
              </CampoSelect>
              <Campo v-model="form.serie" label="Série" erro-chave="serie" :erros="erros" />
              <Campo v-model="form.proximo_numero" label="Próximo número" type="number" min="1" erro-chave="proximo_numero" :erros="erros" />
            </div>
            <div class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2">
              <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-4">
                <input v-model="form.iss_retido" type="checkbox" class="mt-0.5 rounded border-gray-300 text-green-600 focus:ring-green-500" />
                <span><span class="block text-sm font-medium text-gray-900">ISS retido pelo tomador</span><span class="block text-xs text-gray-500">O imposto será descontado do valor líquido.</span></span>
              </label>
              <label class="flex items-start gap-3 rounded-lg border border-gray-200 p-4">
                <input v-model="form.exige_inscricao_municipal_tomador" type="checkbox" class="mt-0.5 rounded border-gray-300 text-green-600 focus:ring-green-500" />
                <span><span class="block text-sm font-medium text-gray-900">Exigir inscrição municipal</span><span class="block text-xs text-gray-500">Aplicada aos tomadores com CNPJ.</span></span>
              </label>
            </div>
          </section>

          <section class="border-t border-gray-200 pt-6">
            <h2 class="text-lg font-medium text-gray-900">Emissão automática</h2>
            <div class="mt-4 rounded-lg border border-yellow-200 bg-yellow-50 p-4">
              <label class="flex items-start gap-3">
                <input
                  :checked="form.emissao_automatica"
                  type="checkbox"
                  class="mt-0.5 rounded border-gray-300 text-green-600 focus:ring-green-500"
                  @change="alterarEmissaoAutomatica"
                />
                <span>
                  <span class="block text-sm font-medium text-yellow-900">Emitir notas automaticamente</span>
                  <span class="mt-1 block text-sm text-yellow-800">A emissão gera consequência fiscal. Ative somente após a decisão do contador da empresa.</span>
                </span>
              </label>
              <div v-if="form.emissao_automatica" class="mt-4 max-w-sm">
                <CampoSelect v-model="form.gatilho_emissao_automatica" label="Quando emitir" erro-chave="gatilho_emissao_automatica" :erros="erros">
                  <option value="conclusao_os">Ao concluir a ordem de serviço</option>
                  <option value="quitacao_titulo">Ao quitar o título a receber</option>
                </CampoSelect>
              </div>
            </div>
          </section>

          <p v-if="erroGeral" class="text-sm text-red-600">{{ erroGeral }}</p>

          <div class="flex justify-end border-t border-gray-200 pt-6">
            <button type="submit" class="btn-primary" :disabled="salvando">
              {{ salvando ? 'Validando e salvando...' : 'Validar e salvar' }}
            </button>
          </div>
        </form>
      </Card>
    </div>

    <Modal :show="confirmarAutomatica" @close="confirmarAutomatica = false">
      <template #icon><span class="text-xl font-bold text-yellow-700">!</span></template>
      <template #title>Ativar emissão automática</template>
      <template #content>
        <p class="text-sm text-gray-700">Confirme que o contador da empresa aprovou o gatilho fiscal. Depois de salvar, novas operações elegíveis poderão emitir NFS-e sem confirmação manual.</p>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" @click="confirmarAutomatica = false">Voltar</button>
        <button type="button" class="btn-primary sm:ml-3" @click="aprovarAutomatica">Confirmar ativação</button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { defineComponent, h, reactive, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Alert from '@/Components/Alert.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { usePermissoes } from '@/Composables/usePermissoes';

const props = defineProps({ configuracao: { type: Object, default: null } });
const { pode } = usePermissoes();

const Campo = defineComponent({
  props: {
    modelValue: { type: [String, Number], default: '' }, label: { type: String, required: true }, type: { type: String, default: 'text' },
    erroChave: { type: String, required: true }, erros: { type: Object, default: () => ({}) }, autocomplete: { type: String, default: null },
    min: { type: [String, Number], default: null }, max: { type: [String, Number], default: null }, step: { type: [String, Number], default: null },
  },
  emits: ['update:modelValue'],
  setup(p, { emit }) {
    const id = `config-fiscal-${p.erroChave.replaceAll('.', '-')}`;
    return () => h('div', [
      h('label', { for: id, class: 'mb-1 block text-sm font-medium text-gray-700' }, p.label),
      h('input', {
        id,
        value: p.modelValue, type: p.type, autocomplete: p.autocomplete, min: p.min, max: p.max, step: p.step,
        class: ['campo', p.erros[p.erroChave] ? 'border-red-500' : ''], onInput: (e) => emit('update:modelValue', e.target.value),
      }),
      p.erros[p.erroChave] ? h('p', { class: 'mt-1 text-sm text-red-600' }, p.erros[p.erroChave][0]) : null,
    ]);
  },
});

const CampoSelect = defineComponent({
  props: { modelValue: { type: [String, Number], default: '' }, label: { type: String, required: true }, erroChave: { type: String, required: true }, erros: { type: Object, default: () => ({}) } },
  emits: ['update:modelValue'],
  setup(p, { emit, slots }) {
    const id = `config-fiscal-${p.erroChave.replaceAll('.', '-')}`;
    return () => h('div', [
      h('label', { for: id, class: 'mb-1 block text-sm font-medium text-gray-700' }, p.label),
      h('select', { id, value: p.modelValue, class: ['campo', p.erros[p.erroChave] ? 'border-red-500' : ''], onChange: (e) => emit('update:modelValue', e.target.value) }, slots.default?.()),
      p.erros[p.erroChave] ? h('p', { class: 'mt-1 text-sm text-red-600' }, p.erros[p.erroChave][0]) : null,
    ]);
  },
});

const atual = props.configuracao || {};
const possuiCredencial = ref(Boolean(atual.possui_credencial));
const ambientePersistido = ref(atual.ambiente || null);
const form = reactive({
  provedor: atual.provedor || 'nuvem_fiscal',
  ambiente: atual.ambiente || 'homologacao',
  client_id: '',
  client_secret: '',
  regime_tributario: atual.regime_tributario || 'simples_nacional',
  codigo_servico: atual.codigo_servico || '',
  cnae: atual.cnae || '',
  aliquota_iss: atual.aliquota_iss || '',
  iss_retido: Boolean(atual.iss_retido),
  natureza_operacao: atual.natureza_operacao || 'tributacao_no_municipio',
  serie: atual.serie || '',
  proximo_numero: atual.proximo_numero || '',
  emissao_automatica: Boolean(atual.emissao_automatica),
  gatilho_emissao_automatica: atual.gatilho_emissao_automatica || 'conclusao_os',
  exige_inscricao_municipal_tomador: Boolean(atual.exige_inscricao_municipal_tomador),
});

const confirmarAutomatica = ref(false);
function alterarEmissaoAutomatica(evento) {
  if (evento.target.checked) {
    evento.target.checked = false;
    confirmarAutomatica.value = true;
  } else {
    form.emissao_automatica = false;
  }
}
function aprovarAutomatica() {
  form.emissao_automatica = true;
  confirmarAutomatica.value = false;
}

const salvando = ref(false);
const erros = ref({});
const erroGeral = ref('');
const alerta = ref(null);
const alertaChave = ref(0);

async function salvar() {
  salvando.value = true;
  erros.value = {};
  erroGeral.value = '';
  try {
    const resposta = await fetch('/fiscal/configuracao', {
      method: 'PUT',
      headers: {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        Accept: 'application/json',
      },
      body: JSON.stringify(form),
    });
    const dados = await resposta.json().catch(() => ({}));
    if (!resposta.ok) {
      erros.value = dados.errors || {};
      throw new Error(Object.values(erros.value).flat()[0] || dados.message || 'A configuração não pôde ser salva.');
    }
    form.client_id = '';
    form.client_secret = '';
    Object.entries(dados.configuracao || {}).forEach(([chave, valor]) => {
      if (chave in form && !['client_id', 'client_secret'].includes(chave)) form[chave] = valor ?? '';
    });
    possuiCredencial.value = Boolean(dados.configuracao?.possui_credencial);
    ambientePersistido.value = dados.configuracao?.ambiente || ambientePersistido.value;
    alerta.value = { tipo: 'success', titulo: 'Configuração validada', mensagem: dados.message };
    alertaChave.value += 1;
  } catch (erro) {
    erroGeral.value = erro.message;
  } finally {
    salvando.value = false;
  }
}
</script>

<style scoped>
.campo { @apply w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm; }
</style>
