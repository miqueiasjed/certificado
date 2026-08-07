<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Pendências fiscais" description="Corrija os dados agrupados por motivo e reprocesse as notas afetadas.">
        <template #actions>
          <Link href="/notas" class="btn-secondary">Ver notas</Link>
          <Link v-if="pode('fiscal-configurar')" href="/fiscal/configuracao" class="btn-secondary">Configuração</Link>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <div v-if="ambiente === 'homologacao'" class="rounded-lg border border-yellow-300 bg-yellow-50 p-4 text-sm text-yellow-900" role="alert">
        Ambiente de homologação ativo. O reprocessamento continuará gerando notas de teste.
      </div>

      <Alert
        v-if="alerta"
        :key="alertaChave"
        :type="alerta.tipo"
        :title="alerta.titulo"
        :message="alerta.mensagem"
      />

      <Card v-if="grupos.length === 0">
        <div class="py-10 text-center">
          <p class="font-medium text-gray-900">Nenhuma pendência fiscal</p>
          <p class="mt-1 text-sm text-gray-500">As notas com erro aparecerão aqui agrupadas pelo motivo.</p>
        </div>
      </Card>

      <Card v-for="(grupo, indice) in grupos" :key="`${grupo.motivo}-${indice}`" padding="none">
        <div class="border-b border-gray-200 bg-red-50 px-4 py-4 sm:px-6">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
            <div>
              <div class="flex flex-wrap items-center gap-2">
                <h2 class="font-semibold text-red-900">{{ grupo.motivo }}</h2>
                <span class="rounded-full bg-red-100 px-2.5 py-0.5 text-xs font-medium text-red-800">
                  {{ grupo.contagem }} nota(s)
                </span>
              </div>
              <p v-if="grupo.reprocessaveis_em_lote < grupo.contagem" class="mt-2 text-xs text-red-700">
                {{ grupo.contagem - grupo.reprocessaveis_em_lote }} substituição(ões) precisa(m) ser refeita(s) pela nota original.
              </p>
            </div>
            <button
              v-if="pode('fiscal-emitir')"
              type="button"
              class="btn-primary shrink-0"
              :disabled="processandoGrupo === indice || grupo.reprocessaveis_em_lote === 0"
              @click="reprocessarGrupo(grupo, indice)"
            >
              {{ processandoGrupo === indice ? 'Reprocessando...' : 'Reprocessar todas deste grupo' }}
            </button>
          </div>
        </div>

        <div class="p-4 sm:p-6">
          <p class="mb-3 text-sm font-medium text-gray-700">Clientes afetados</p>
          <ul class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <li v-for="cliente in grupo.clientes" :key="cliente.id" class="flex items-center justify-between gap-3 rounded-lg border border-gray-200 p-3">
              <span class="min-w-0 truncate text-sm text-gray-900">{{ cliente.nome }}</span>
              <button
                v-if="pode('fiscal-emitir') && cliente.nota_ids.length > 0"
                type="button"
                class="shrink-0 text-sm font-medium text-green-700 hover:text-green-900"
                @click="abrirCorrecao(cliente)"
              >
                Corrigir cadastro
              </button>
            </li>
          </ul>
        </div>
      </Card>
    </div>

    <Modal :show="mostrarCorrecao" @close="fecharCorrecao">
      <template #title>Corrigir dados fiscais do cliente</template>
      <template #content>
        <div v-if="carregandoCliente" class="py-8 text-center text-sm text-gray-500">Carregando cadastro...</div>
        <form v-else class="max-h-[65vh] space-y-5 overflow-y-auto pr-1" @submit.prevent="salvarCliente">
          <div v-if="resumoOrigens" class="rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
            {{ resumoOrigens }}
          </div>
          <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
            <Campo v-model="formCliente.name" label="Nome ou razão social" erro-chave="name" :erros="errosCliente" />
            <Campo v-model="formCliente.cnpj" label="CPF ou CNPJ" erro-chave="cnpj" :erros="errosCliente" />
            <Campo v-model="formCliente.email" label="E-mail principal" type="email" erro-chave="email" :erros="errosCliente" />
            <Campo v-model="formCliente.email_nfe" label="E-mail para NFS-e" type="email" erro-chave="email_nfe" :erros="errosCliente" />
            <Campo v-model="formCliente.phone" label="Telefone" erro-chave="phone" :erros="errosCliente" />
            <Campo v-model="formCliente.inscricao_municipal" label="Inscrição municipal" erro-chave="inscricao_municipal" :erros="errosCliente" />
            <Campo v-model="formCliente.inscricao_estadual" label="Inscrição estadual" erro-chave="inscricao_estadual" :erros="errosCliente" />
          </div>

          <div class="border-t border-gray-200 pt-4">
            <div class="mb-4">
              <label for="endereco-fiscal" class="mb-1 block text-sm font-medium text-gray-700">Endereço usado na nota</label>
              <select id="endereco-fiscal" v-model="enderecoSelecionado" class="campo" @change="trocarEndereco">
                <option v-for="endereco in enderecosDisponiveis" :key="endereco.id" :value="String(endereco.id)">
                  {{ endereco.nickname }}: {{ endereco.street }}, {{ endereco.number }}
                </option>
                <option value="novo">Cadastrar novo endereço</option>
              </select>
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Campo v-model="formCliente.address.nickname" label="Identificação" erro-chave="address.nickname" :erros="errosCliente" />
              <Campo v-model="formCliente.address.zip" label="CEP" erro-chave="address.zip" :erros="errosCliente" />
              <Campo v-model="formCliente.address.street" label="Logradouro" erro-chave="address.street" :erros="errosCliente" />
              <Campo v-model="formCliente.address.number" label="Número" erro-chave="address.number" :erros="errosCliente" />
              <Campo v-model="formCliente.address.district" label="Bairro" erro-chave="address.district" :erros="errosCliente" />
              <Campo v-model="formCliente.address.city" label="Cidade" erro-chave="address.city" :erros="errosCliente" />
              <Campo v-model="formCliente.address.state" label="UF" maxlength="2" erro-chave="address.state" :erros="errosCliente" />
              <Campo v-model="formCliente.address.codigo_municipio_ibge" label="Código do município (IBGE)" maxlength="7" erro-chave="address.codigo_municipio_ibge" :erros="errosCliente" />
            </div>
          </div>
          <p v-if="erroGeralCliente" class="text-sm text-red-600">{{ erroGeralCliente }}</p>
        </form>
      </template>
      <template #actions>
        <button type="button" class="btn-secondary" :disabled="salvandoCliente" @click="fecharCorrecao">Voltar</button>
        <button type="button" class="btn-primary sm:ml-3" :disabled="salvandoCliente || carregandoCliente" @click="salvarCliente">
          {{ salvandoCliente ? 'Salvando...' : 'Salvar correção' }}
        </button>
      </template>
    </Modal>
  </AuthenticatedLayout>
</template>

<script setup>
import { defineComponent, h, reactive, ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import Alert from '@/Components/Alert.vue';
import Card from '@/Components/Card.vue';
import Modal from '@/Components/Modal.vue';
import PageHeader from '@/Components/PageHeader.vue';
import { usePermissoes } from '@/Composables/usePermissoes';

defineProps({
  grupos: { type: Array, default: () => [] },
  ambiente: { type: String, default: null },
});

const { pode } = usePermissoes();

const Campo = defineComponent({
  props: {
    modelValue: { type: [String, Number], default: '' },
    label: { type: String, required: true },
    type: { type: String, default: 'text' },
    maxlength: { type: [String, Number], default: null },
    erroChave: { type: String, required: true },
    erros: { type: Object, default: () => ({}) },
  },
  emits: ['update:modelValue'],
  setup(propriedades, { emit }) {
    const id = `fiscal-cliente-${propriedades.erroChave.replaceAll('.', '-')}`;

    return () => h('div', [
      h('label', { for: id, class: 'mb-1 block text-sm font-medium text-gray-700' }, propriedades.label),
      h('input', {
        id,
        value: propriedades.modelValue,
        type: propriedades.type,
        maxlength: propriedades.maxlength,
        class: ['campo', propriedades.erros[propriedades.erroChave] ? 'border-red-500' : ''],
        onInput: (evento) => emit('update:modelValue', evento.target.value),
      }),
      propriedades.erros[propriedades.erroChave]
        ? h('p', { class: 'mt-1 text-sm text-red-600' }, propriedades.erros[propriedades.erroChave][0])
        : null,
    ]);
  },
});

const alerta = ref(null);
const alertaChave = ref(0);
function avisar(tipo, titulo, mensagem) {
  alerta.value = { tipo, titulo, mensagem };
  alertaChave.value += 1;
}

function csrf() {
  return document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');
}

function mensagem(dados, padrao) {
  return Object.values(dados.errors || {}).flat()[0] || dados.message || padrao;
}

const processandoGrupo = ref(null);

async function reprocessarGrupo(grupo, indice) {
  processandoGrupo.value = indice;
  try {
    const resposta = await fetch('/notas/pendencias/reprocessar', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
      body: JSON.stringify({ nota_ids: grupo.nota_ids }),
    });
    const dados = await resposta.json().catch(() => ({}));
    if (!resposta.ok) throw new Error(mensagem(dados, 'O grupo não pôde ser reprocessado.'));
    const falhas = dados.falhas?.length || 0;
    const aguardando = (dados.processadas || []).filter((item) => item.resultado_fiscal === 'pendente').length;
    const temPendencia = falhas > 0 || aguardando > 0;
    const detalhe = falhas > 0
      ? `${falhas} nota(s) continuam com erro. Confira o grupo atualizado.`
      : aguardando > 0
        ? `${aguardando} nota(s) foram aceitas e aguardam resposta da prefeitura.`
        : dados.message;
    avisar(temPendencia ? 'warning' : 'success', temPendencia ? 'Lote ainda em processamento' : 'Lote concluído', detalhe);
    router.reload({ only: ['grupos'], preserveScroll: true });
  } catch (erro) {
    avisar('error', 'Falha no reprocessamento', erro.message);
  } finally {
    processandoGrupo.value = null;
  }
}

const mostrarCorrecao = ref(false);
const carregandoCliente = ref(false);
const salvandoCliente = ref(false);
const clienteSelecionado = ref(null);
const enderecosDisponiveis = ref([]);
const enderecoSelecionado = ref('novo');
const errosCliente = ref({});
const erroGeralCliente = ref('');
const enderecoVazio = () => ({ id: null, nickname: '', street: '', number: '', district: '', city: '', state: '', zip: '', codigo_municipio_ibge: '' });
const formCliente = reactive({
  name: '', email: '', email_nfe: '', phone: '', cnpj: '', inscricao_municipal: '', inscricao_estadual: '', address: enderecoVazio(), nota_ids: [],
});

const resumoOrigens = ref('');

async function abrirCorrecao(cliente) {
  clienteSelecionado.value = cliente;
  mostrarCorrecao.value = true;
  carregandoCliente.value = true;
  errosCliente.value = {};
  erroGeralCliente.value = '';
  try {
    const resposta = await fetch(`/fiscal/clientes/${cliente.id}`, { headers: { Accept: 'application/json' } });
    const dados = await resposta.json().catch(() => ({}));
    if (!resposta.ok) throw new Error(mensagem(dados, 'O cadastro não pôde ser carregado.'));
    const cadastro = dados.cliente;
    Object.assign(formCliente, {
      name: cadastro.name || '', email: cadastro.email || '', email_nfe: cadastro.email_nfe || '', phone: cadastro.phone || '', cnpj: cadastro.cnpj || '',
      inscricao_municipal: cadastro.inscricao_municipal || '', inscricao_estadual: cadastro.inscricao_estadual || '',
      nota_ids: cliente.nota_ids || [],
    });
    enderecosDisponiveis.value = cadastro.addresses || [];
    const enderecoAtual = cliente.origens?.find((origem) => origem.address_id)?.address_id;
    const primeiro = enderecosDisponiveis.value.find((item) => item.id === enderecoAtual)
      || enderecosDisponiveis.value.find((item) => item.active)
      || enderecosDisponiveis.value[0];
    enderecoSelecionado.value = primeiro ? String(primeiro.id) : 'novo';
    Object.assign(formCliente.address, primeiro || enderecoVazio());
    const ordens = [...new Set((cliente.origens || []).map((origem) => origem.work_order_id).filter(Boolean))];
    const titulos = [...new Set((cliente.origens || []).filter((origem) => !origem.work_order_id).map((origem) => origem.receivable_id).filter(Boolean))];
    const partes = [];
    if (ordens.length) partes.push(`O endereço escolhido será vinculado às OS ${ordens.map((id) => `#${id}`).join(', ')}.`);
    if (titulos.length) partes.push(`Nos títulos ${titulos.map((id) => `#${id}`).join(', ')}, ele ficará como endereço prioritário da próxima tentativa fiscal.`);
    resumoOrigens.value = partes.join(' ');
  } catch (erro) {
    erroGeralCliente.value = erro.message;
  } finally {
    carregandoCliente.value = false;
  }
}

function trocarEndereco() {
  const endereco = enderecosDisponiveis.value.find((item) => String(item.id) === enderecoSelecionado.value);
  Object.assign(formCliente.address, endereco || enderecoVazio());
}

function fecharCorrecao() {
  if (!salvandoCliente.value) mostrarCorrecao.value = false;
}

async function salvarCliente() {
  if (salvandoCliente.value || carregandoCliente.value) return;
  salvandoCliente.value = true;
  errosCliente.value = {};
  erroGeralCliente.value = '';
  try {
    const resposta = await fetch(`/fiscal/clientes/${clienteSelecionado.value.id}`, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf(), Accept: 'application/json' },
      body: JSON.stringify(formCliente),
    });
    const dados = await resposta.json().catch(() => ({}));
    if (!resposta.ok) {
      errosCliente.value = dados.errors || {};
      throw new Error(mensagem(dados, 'A correção não pôde ser salva.'));
    }
    mostrarCorrecao.value = false;
    avisar('success', 'Cadastro corrigido', 'Os dados foram salvos. Reprocesse o grupo para tentar as notas novamente.');
  } catch (erro) {
    erroGeralCliente.value = erro.message;
  } finally {
    salvandoCliente.value = false;
  }
}
</script>

<style scoped>
.campo { @apply w-full rounded-md border-gray-300 shadow-sm focus:border-green-500 focus:ring-green-500 sm:text-sm; }
</style>
