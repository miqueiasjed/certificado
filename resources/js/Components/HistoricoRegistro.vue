<template>
  <Card v-if="pode('auditoria-ver')" padding="none">
    <div class="px-4 sm:px-6 py-4 border-b border-gray-200 flex items-center justify-between gap-3">
      <h3 class="text-base font-medium text-gray-900">{{ titulo }}</h3>

      <button
        v-if="!aberto"
        type="button"
        class="btn-secondary-sm flex-shrink-0"
        @click="abrir"
      >
        Ver histórico
      </button>
      <button
        v-else
        type="button"
        class="text-sm text-gray-500 hover:text-gray-700 flex-shrink-0"
        @click="aberto = false"
      >
        Recolher
      </button>
    </div>

    <div v-if="aberto" class="px-4 sm:px-6 py-4">
      <!-- Carregando (primeira busca) -->
      <div v-if="carregando" class="space-y-5 animate-pulse">
        <div v-for="n in 3" :key="n" class="flex gap-3">
          <div class="w-8 h-8 rounded-full bg-gray-200 flex-shrink-0"></div>
          <div class="flex-1 space-y-2 py-1">
            <div class="h-3 bg-gray-200 rounded w-1/3"></div>
            <div class="h-3 bg-gray-200 rounded w-2/3"></div>
          </div>
        </div>
      </div>

      <!-- Erro -->
      <div v-else-if="erro" class="text-center py-6">
        <p class="text-sm text-red-600 mb-3">{{ erro }}</p>
        <button type="button" class="btn-secondary-sm" @click="buscar()">
          Tentar de novo
        </button>
      </div>

      <!-- Vazio -->
      <p v-else-if="itens.length === 0" class="text-sm text-gray-500 text-center py-6">
        Nenhuma alteração registrada.
      </p>

      <!-- Linha do tempo -->
      <ul v-else class="space-y-6">
        <li v-for="(item, indice) in itens" :key="item.id" class="flex gap-3">
          <div class="flex flex-col items-center flex-shrink-0">
            <span :class="['flex items-center justify-center w-8 h-8 rounded-full', corDoIcone(item.acao)]">
              <svg v-if="item.acao === 'criado'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
              </svg>
              <svg v-else-if="item.acao === 'excluido'" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
              </svg>
              <svg v-else class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
              </svg>
            </span>
            <span v-if="indice < itens.length - 1" class="flex-1 w-px bg-gray-200 mt-1"></span>
          </div>

          <div class="flex-1 min-w-0 pb-1">
            <p class="text-sm text-gray-900">
              <span class="font-medium">{{ item.autor }}</span>
              {{ rotuloAcao(item.acao) }}
            </p>
            <p class="text-xs text-gray-500 mt-0.5">{{ item.criado_em }}</p>

            <ul v-if="item.alteracoes.length > 0" class="mt-2 space-y-1">
              <li
                v-for="alteracao in item.alteracoes"
                :key="alteracao.campo"
                class="text-sm text-gray-700 break-words"
              >
                <span class="font-medium">{{ alteracao.rotulo }}:</span>
                <span class="text-gray-400 line-through">{{ alteracao.antes }}</span>
                ->
                <span class="text-gray-900 font-medium">{{ alteracao.depois }}</span>
              </li>
            </ul>
          </div>
        </li>
      </ul>

      <div v-if="!carregando && !erro && itens.length > 0 && (proximaPagina || erroCarregarMais)" class="mt-6 text-center">
        <p v-if="erroCarregarMais" class="text-sm text-red-600 mb-2">{{ erroCarregarMais }}</p>
        <button
          v-if="proximaPagina"
          type="button"
          class="btn-secondary-sm"
          :disabled="carregandoMais"
          @click="carregarMais"
        >
          <svg v-if="carregandoMais" class="animate-spin -ml-1 mr-2 h-4 w-4 text-white" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
          </svg>
          {{ carregandoMais ? 'Carregando...' : erroCarregarMais ? 'Tentar novamente' : 'Carregar mais' }}
        </button>
      </div>
    </div>
  </Card>
</template>

<script setup>
import { ref } from 'vue';
import Card from '@/Components/Card.vue';
import { usePermissoes } from '@/Composables/usePermissoes';

const props = defineProps({
  tipo: {
    type: String,
    required: true,
  },
  id: {
    type: Number,
    required: true,
  },
  titulo: {
    type: String,
    default: 'Histórico de alterações',
  },
});

const { pode } = usePermissoes();

const aberto = ref(false);
const carregando = ref(false);
const carregandoMais = ref(false);
const erro = ref('');
const erroCarregarMais = ref('');
const itens = ref([]);
const proximaPagina = ref(null);

const ROTULOS_ACAO = {
  criado: 'criou o registro',
  alterado: 'alterou o registro',
  excluido: 'excluiu o registro',
};

const CORES_ICONE = {
  criado: 'bg-green-100 text-green-600',
  alterado: 'bg-blue-100 text-blue-600',
  excluido: 'bg-red-100 text-red-600',
};

function rotuloAcao(acao) {
  return ROTULOS_ACAO[acao] ?? 'alterou o registro';
}

function corDoIcone(acao) {
  return CORES_ICONE[acao] ?? 'bg-gray-100 text-gray-600';
}

function abrir() {
  aberto.value = true;

  if (itens.value.length === 0 && !erro.value) {
    buscar();
  }
}

async function buscar() {
  carregando.value = true;
  erro.value = '';
  erroCarregarMais.value = '';

  try {
    const resposta = await fetch(`/audit-logs/${props.tipo}/${props.id}`, {
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });

    if (!resposta.ok) {
      throw new Error(`HTTP ${resposta.status}`);
    }

    const pagina = await resposta.json();

    itens.value = pagina.data ?? [];
    proximaPagina.value = pagina.next_page_url ?? null;
  } catch (excecao) {
    erro.value = 'Não foi possível carregar o histórico.';
  } finally {
    carregando.value = false;
  }
}

async function carregarMais() {
  if (!proximaPagina.value || carregandoMais.value) {
    return;
  }

  carregandoMais.value = true;
  erroCarregarMais.value = '';

  try {
    const resposta = await fetch(proximaPagina.value, {
      headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
      },
    });

    if (!resposta.ok) {
      throw new Error(`HTTP ${resposta.status}`);
    }

    const pagina = await resposta.json();

    itens.value = itens.value.concat(pagina.data ?? []);
    proximaPagina.value = pagina.next_page_url ?? null;
  } catch (excecao) {
    erroCarregarMais.value = 'Não foi possível carregar mais registros.';
  } finally {
    carregandoMais.value = false;
  }
}
</script>
