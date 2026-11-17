<!--
  Pop-up "O sistema foi atualizado". Montado no AuthenticatedLayout (painel
  das empresas), fora de login, portal do cliente e área da plataforma. Busca
  sozinho os avisos pendentes de quem entrou: o público é decidido no
  servidor, por isso o componente não recebe área nem papel. Qualquer falha da
  API deixa o pop-up quieto; o aviso volta na próxima entrada e a tela nunca
  fica presa por causa dele.

  O layout remonta a cada navegação da Inertia, então a busca fica guardada no
  módulo: uma chamada por carregamento de página e por usuário.
-->
<template>
  <Modal :show="visivel" @close="confirmar">
    <template #icon>
      <svg class="h-6 w-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
      </svg>
    </template>

    <template #title>O sistema foi atualizado</template>

    <template #content>
      <div class="space-y-4 max-h-[60vh] overflow-y-auto">
        <section v-for="grupo in grupos" :key="grupo.data" class="space-y-2">
          <p class="text-xs text-gray-500">{{ formatarData(grupo.data) }}</p>
          <div v-for="aviso in grupo.avisos" :key="aviso.id" class="space-y-1">
            <p class="text-sm font-semibold text-gray-900">{{ aviso.titulo }}</p>
            <ul v-if="aviso.itens?.length" class="list-disc space-y-1 pl-5 text-sm text-gray-700">
              <li v-for="(item, indice) in aviso.itens" :key="indice">{{ item }}</li>
            </ul>
          </div>
        </section>
        <p class="border-t border-gray-200 pt-3 text-sm text-gray-500">
          Se alguma tela parecer estranha, aperte F5 para carregar a versão nova.
        </p>
      </div>
    </template>

    <template #actions>
      <button type="button" class="btn-primary w-full sm:w-auto" :disabled="marcando" @click="confirmar">
        {{ marcando ? 'Salvando...' : 'OK' }}
      </button>
    </template>
  </Modal>
</template>

<script>
// Estado do módulo: sobrevive à remontagem do layout entre páginas.
let buscaDoUsuario = null;
let promessaDaBusca = null;
const confirmados = new Set();
</script>

<script setup>
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import Modal from '@/Components/Modal.vue';
import { formatarData } from '@/utils/formatDate';
import { buscarPendentes, marcarLidos } from '@/utils/avisosAtualizacao';
import { deveRecarregar, recarregarParaVersao, versaoCarregada } from '@/utils/versaoBuild';

// Teto de espera da marcação. Passado o tempo, o pop-up fecha do mesmo jeito;
// se a marcação não gravou, o aviso reaparece uma vez na próxima entrada.
const ESPERA_MAXIMA_MS = 4000;

const page = usePage();
const avisos = ref([]);
const versao = ref(null);
const marcando = ref(false);
let confirmando = false;

const idUsuario = computed(() => page.props.auth?.user?.id ?? null);
// Super admin em modo suporte nunca recebe aviso.
const podeVer = computed(() => idUsuario.value !== null && page.props.suporte?.ativo !== true);

const visivel = computed(() => avisos.value.length > 0);

const grupos = computed(() => {
  const lista = [];
  for (const aviso of avisos.value) {
    const existente = lista.find((g) => g.data === aviso.publicado_em);
    if (existente) {
      existente.avisos.push(aviso);
    } else {
      lista.push({ data: aviso.publicado_em, avisos: [aviso] });
    }
  }
  return lista;
});

function esperarNoMaximo(promessa, ms) {
  return new Promise((resolver) => {
    const timer = window.setTimeout(resolver, ms);
    promessa.finally(() => {
      window.clearTimeout(timer);
      resolver();
    });
  });
}

onMounted(async () => {
  if (!podeVer.value) {
    return;
  }

  if (buscaDoUsuario !== idUsuario.value || promessaDaBusca === null) {
    buscaDoUsuario = idUsuario.value;
    promessaDaBusca = buscarPendentes().catch(() => null);
  }

  const dono = idUsuario.value;
  const dados = await promessaDaBusca;

  // 403, rede fora, erro do servidor ou troca de usuário: nada aparece.
  if (!dados || dono !== idUsuario.value) {
    return;
  }

  avisos.value = (dados.avisos ?? []).filter((aviso) => !confirmados.has(aviso.id));
  versao.value = dados.versao ?? null;
});

async function confirmar() {
  if (confirmando || avisos.value.length === 0) return;
  confirmando = true;
  marcando.value = true;

  const ids = avisos.value.map((aviso) => aviso.id);
  ids.forEach((id) => confirmados.add(id));

  await esperarNoMaximo(marcarLidos(ids).catch(() => undefined), ESPERA_MAXIMA_MS);

  avisos.value = [];
  marcando.value = false;
  confirmando = false;

  // Só recarrega quando houve aviso e a versão desta aba é a antiga. A trava
  // em sessionStorage impede o laço se o cache devolver a página velha.
  if (versao.value && deveRecarregar(versaoCarregada(), versao.value)) {
    recarregarParaVersao(versao.value);
  }
}
</script>
