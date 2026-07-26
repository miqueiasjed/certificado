<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader title="Templates de notificação" description="Edite o texto que os avisos automáticos enviam para clientes, empresa e usuários.">
        <template #actions>
          <Link :href="route('notificacoes.index')" class="btn-secondary">
            Ver histórico de envios
          </Link>
        </template>
      </PageHeader>
    </template>

    <div class="max-w-5xl mx-auto space-y-6">
      <Alert v-if="mensagemFlash" :key="flashKey" :type="mensagemFlash.tipo" :title="mensagemFlash.texto" />

      <Card v-for="evento in eventos" :key="evento.evento" padding="none">
        <div class="px-6 py-4 border-b border-gray-200">
          <h3 class="text-lg font-medium text-gray-900">{{ evento.rotulo }}</h3>
          <p class="text-sm text-gray-500">Destinatário padrão: {{ rotuloDestinatario(evento.destinatario) }}</p>
        </div>
        <div class="divide-y divide-gray-200">
          <div
            v-for="canalItem in evento.canais"
            :key="canalItem.canal"
            class="px-6 py-4 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3"
            :class="{ 'cursor-pointer hover:bg-gray-50': pode('notificacao-gerenciar') }"
            @click="abrirEditor(evento, canalItem)"
          >
            <div class="flex-1 min-w-0">
              <div class="flex flex-wrap items-center gap-2 mb-1">
                <span class="text-sm font-medium text-gray-900">{{ rotuloCanal(canalItem.canal) }}</span>
                <span
                  class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium"
                  :class="canalItem.origem === 'tenant' ? 'bg-blue-100 text-blue-800' : 'bg-gray-100 text-gray-800'"
                >
                  {{ canalItem.origem === 'tenant' ? 'Personalizado' : 'Padrão' }}
                </span>
                <span v-if="!canalItem.ativo" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-800">
                  Inativo
                </span>
              </div>
              <p class="text-sm text-gray-500 truncate">{{ trechoDe(canalItem.corpo) }}</p>
            </div>
            <button
              v-if="pode('notificacao-gerenciar')"
              type="button"
              class="btn-secondary-sm flex-shrink-0"
              @click.stop="abrirEditor(evento, canalItem)"
            >
              Editar
            </button>
          </div>
        </div>
      </Card>

      <p v-if="!eventos.length" class="text-center text-sm text-gray-500 py-10">
        Nenhum evento de notificação cadastrado.
      </p>
    </div>

    <EditorDeTemplate
      v-if="itemEmEdicao"
      :key="`${itemEmEdicao.evento}:${itemEmEdicao.canal}`"
      :evento="itemEmEdicao.evento"
      :rotulo-evento="itemEmEdicao.rotuloEvento"
      :canal="itemEmEdicao.canal"
      :origem="itemEmEdicao.origem"
      :assunto-inicial="itemEmEdicao.assunto"
      :corpo-inicial="itemEmEdicao.corpo"
      :ativo-inicial="itemEmEdicao.ativo"
      :variaveis="itemEmEdicao.variaveis"
      @close="itemEmEdicao = null"
      @salvo="aoSalvar"
      @restaurado="aoRestaurar"
    />
  </AuthenticatedLayout>
</template>

<script setup>
import { ref } from 'vue';
import { Link, router } from '@inertiajs/vue3';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';
import Alert from '@/Components/Alert.vue';
import EditorDeTemplate from '@/Components/EditorDeTemplate.vue';
import { usePermissoes } from '@/Composables/usePermissoes';

const props = defineProps({
  eventos: {
    type: Array,
    required: true,
  },
});

const { pode } = usePermissoes();

const DESTINATARIO_ROTULOS = {
  cliente: 'Cliente',
  empresa: 'Empresa (uso interno)',
  usuario: 'Usuário do sistema',
};

const CANAL_ROTULOS = {
  email: 'E-mail',
  whatsapp: 'WhatsApp',
};

function rotuloDestinatario(destinatario) {
  return DESTINATARIO_ROTULOS[destinatario] || destinatario;
}

function rotuloCanal(canal) {
  return CANAL_ROTULOS[canal] || canal;
}

function trechoDe(corpo) {
  if (!corpo) return '';

  const semQuebras = corpo.replace(/\s+/g, ' ').trim();

  return semQuebras.length > 120 ? `${semQuebras.slice(0, 120)}...` : semQuebras;
}

const itemEmEdicao = ref(null);
const mensagemFlash = ref(null);
const flashKey = ref(0);

function abrirEditor(evento, canalItem) {
  if (!pode('notificacao-gerenciar')) return;

  itemEmEdicao.value = {
    evento: evento.evento,
    rotuloEvento: evento.rotulo,
    canal: canalItem.canal,
    origem: canalItem.origem,
    assunto: canalItem.assunto,
    corpo: canalItem.corpo,
    ativo: canalItem.ativo,
    variaveis: evento.variaveis,
  };
}

function mostrarFlash(tipo, texto) {
  mensagemFlash.value = { tipo, texto: texto || 'Feito.' };
  flashKey.value += 1;
}

function aoSalvar(mensagem) {
  itemEmEdicao.value = null;
  mostrarFlash('success', mensagem);
  router.reload({ only: ['eventos'], preserveScroll: true });
}

function aoRestaurar(mensagem) {
  itemEmEdicao.value = null;
  mostrarFlash('success', mensagem);
  router.reload({ only: ['eventos'], preserveScroll: true });
}
</script>
