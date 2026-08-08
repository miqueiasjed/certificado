<template>
  <AuthenticatedLayout>
    <template #header>
      <PageHeader
        title="Pendências de coordenada"
        description="Endereços sem coordenada ou com precisão só no nível da cidade - arraste o marcador até o ponto certo"
      >
        <template #actions>
          <Link :href="route('roteiros.painel')" class="btn-secondary">Voltar ao roteiro</Link>
        </template>
      </PageHeader>
    </template>

    <div class="space-y-6">
      <div v-if="erro" class="rounded-md border border-red-200 bg-red-50 p-4">
        <p class="text-sm text-red-800">{{ erro }}</p>
      </div>

      <div v-if="carregando" class="py-8 text-center text-sm text-gray-500">
        Carregando pendências...
      </div>

      <template v-else-if="pendencias">
        <p class="text-sm text-gray-600">
          {{ pendencias.total }} endereço(s) pendente(s) de coordenada.
        </p>

        <div v-if="pendencias.data.length === 0" class="rounded-md border border-green-200 bg-green-50 p-6 text-center text-sm text-green-800">
          Nenhuma pendência de coordenada. Todos os endereços têm coordenada confiável.
        </div>

        <div v-else class="grid grid-cols-1 gap-4 lg:grid-cols-2">
          <Card v-for="item in pendencias.data" :key="item.id" padding="small">
            <div class="space-y-3">
              <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                  <p class="truncate text-sm font-medium text-gray-900">{{ item.client?.name || 'Cliente não informado' }}</p>
                  <p class="text-sm text-gray-500">{{ enderecoCompleto(item) }}</p>
                </div>
                <span class="flex-shrink-0 inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-yellow-100 text-yellow-800">
                  {{ motivoPendencia(item) }}
                </span>
              </div>

              <div
                :ref="(elemento) => registrarMapaContainer(elemento, item.id)"
                class="h-48 w-full overflow-hidden rounded-lg border border-gray-200"
              />
              <p class="text-xs text-gray-500">Arraste o marcador até a posição correta. A coordenada é salva ao soltar.</p>

              <div class="grid grid-cols-2 gap-2">
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Latitude</label>
                  <input
                    v-model="formularioDoItem(item).latitude"
                    type="number"
                    step="any"
                    class="w-full px-2 py-1.5 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  />
                </div>
                <div>
                  <label class="block text-xs font-medium text-gray-600 mb-1">Longitude</label>
                  <input
                    v-model="formularioDoItem(item).longitude"
                    type="number"
                    step="any"
                    class="w-full px-2 py-1.5 border border-gray-300 rounded-md shadow-sm text-sm focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                  />
                </div>
              </div>

              <p v-if="erroPorId[item.id]" class="text-xs text-red-600">{{ erroPorId[item.id] }}</p>

              <div class="flex flex-wrap gap-2">
                <button
                  type="button"
                  class="btn-secondary-sm"
                  :disabled="salvandoPorId[item.id]"
                  @click="salvarCoordenadaDigitada(item)"
                >
                  {{ salvandoPorId[item.id] ? 'Salvando...' : 'Salvar coordenada' }}
                </button>
                <button
                  type="button"
                  class="btn-secondary-sm"
                  :disabled="geocodificandoPorId[item.id]"
                  @click="tentarNovamente(item)"
                >
                  {{ geocodificandoPorId[item.id] ? 'Tentando...' : 'Tentar geocodificar de novo' }}
                </button>
              </div>
            </div>
          </Card>
        </div>

        <!-- Paginação: navegação própria, não o componente Pagination.vue
             (aquele usa <Link> do Inertia, que espera resposta Inertia; esta
             página busca a lista via fetch de um endpoint JSON puro - Link
             navegando para uma URL JSON quebraria a página, ver o motivo
             completo no comentário de `carregarPagina()`). -->
        <div v-if="pendencias.last_page > 1" class="flex items-center justify-between">
          <button
            type="button"
            class="btn-secondary-sm"
            :disabled="!pendencias.prev_page_url"
            @click="carregarPagina(pendencias.prev_page_url)"
          >
            Anterior
          </button>
          <p class="text-sm text-gray-600">Página {{ pendencias.current_page }} de {{ pendencias.last_page }}</p>
          <button
            type="button"
            class="btn-secondary-sm"
            :disabled="!pendencias.next_page_url"
            @click="carregarPagina(pendencias.next_page_url)"
          >
            Próximo
          </button>
        </div>
      </template>
    </div>
  </AuthenticatedLayout>
</template>

<script setup>
/**
 * Correção manual de coordenada de endereço, por arrastar o marcador
 * (Plano 22, Task 22.6). Cada card tem seu próprio mapa Leaflet independente
 * com um marcador arrastável (`draggable: true`, suporte nativo do Leaflet -
 * não precisa de nenhuma biblioteca extra para isso).
 *
 * Página "casca" + fetch, mesmo padrão de `Roteiros/Index.vue`:
 * `AddressGeoController::painel()` só renderiza a casca Inertia; a lista em
 * si vem de `GET /enderecos/pendencias-geo` (JSON paginado, contrato da Task
 * 22.5), buscada aqui.
 */
import { onBeforeUnmount, onMounted, ref } from 'vue';
import { Link } from '@inertiajs/vue3';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import AuthenticatedLayout from '@/Layouts/AuthenticatedLayout.vue';
import PageHeader from '@/Components/PageHeader.vue';
import Card from '@/Components/Card.vue';

const pendencias = ref(null);
const carregando = ref(false);
const erro = ref('');

const salvandoPorId = ref({});
const geocodificandoPorId = ref({});
const erroPorId = ref({});
const formularios = ref({});

// { [addressId]: { mapa, marcador } }, fora da reatividade do Vue de
// propósito: instâncias do Leaflet não são dados de aplicação, e deixar o
// Vue tentar observar profundamente esses objetos (canvas, listeners
// internos) só custaria desempenho sem nenhum ganho.
let mapasPorId = {};

const tilesUrl = import.meta.env.VITE_MAP_TILES_URL || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';

// Mesmo formato de `Address::getFullAddressAttribute()` (backend): o
// accessor não vem no JSON de `pendencias()` (não está em `$appends`, de
// propósito - ver o cabeçalho do model), então a lista paginada só traz os
// campos crus (`street`, `number`, `district`, `city`, `state`, `zip`), e
// este componente monta o texto do mesmo jeito que o backend montaria.
function enderecoCompleto(item) {
  return `${item.street}, ${item.number} - ${item.district}, ${item.city}/${item.state} - CEP: ${item.zip}`;
}

function motivoPendencia(item) {
  if (item.latitude === null || item.longitude === null) return 'Sem coordenada';
  if (item.precisao_geocodificacao === 'cidade') return 'Só no nível da cidade';
  return 'Pendente';
}

function itemAtual(id) {
  return pendencias.value?.data.find((registro) => registro.id === id) ?? null;
}

// `latitude`/`longitude` chegam como string (cast `decimal:7` no model -
// mesmo cuidado documentado em `RouteController::apresentarRota()`), por
// isso o `parseFloat` explícito.
function coordenadaInicial(item) {
  if (item?.latitude !== null && item?.latitude !== undefined && item?.longitude !== null && item?.longitude !== undefined) {
    return {
      lat: parseFloat(item.latitude),
      lng: parseFloat(item.longitude),
      zoom: item.precisao_geocodificacao === 'cidade' ? 12 : 16,
    };
  }

  // Sem nenhuma coordenada ainda: centro do Brasil, bem afastado - só um
  // ponto de partida para o usuário arrastar até o lugar certo.
  return { lat: -14.235, lng: -51.9253, zoom: 4 };
}

function formularioDoItem(item) {
  if (!formularios.value[item.id]) {
    const centro = coordenadaInicial(item);
    formularios.value[item.id] = {
      latitude: item.latitude !== null && item.latitude !== undefined ? String(parseFloat(item.latitude)) : String(centro.lat),
      longitude: item.longitude !== null && item.longitude !== undefined ? String(parseFloat(item.longitude)) : String(centro.lng),
    };
  }

  return formularios.value[item.id];
}

function registrarMapaContainer(elemento, id) {
  if (!elemento || mapasPorId[id]) return;

  const item = itemAtual(id);
  const centro = coordenadaInicial(item);

  const mapa = L.map(elemento, { scrollWheelZoom: false }).setView([centro.lat, centro.lng], centro.zoom);

  L.tileLayer(tilesUrl, {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
    maxZoom: 19,
  }).addTo(mapa);

  const marcador = L.marker([centro.lat, centro.lng], { draggable: true }).addTo(mapa);

  marcador.on('dragend', () => {
    const posicao = marcador.getLatLng();
    salvarCoordenada(id, posicao.lat, posicao.lng);
  });

  mapasPorId[id] = { mapa, marcador };
}

function reposicionarMarcador(id) {
  const instancia = mapasPorId[id];
  const item = itemAtual(id);
  if (!instancia || !item) return;

  const centro = coordenadaInicial(item);
  instancia.marcador.setLatLng([centro.lat, centro.lng]);
  instancia.mapa.setView([centro.lat, centro.lng]);
}

function destruirMapas() {
  Object.values(mapasPorId).forEach((instancia) => instancia.mapa.remove());
  mapasPorId = {};
}

/**
 * Busca a página de pendências informada (ou a primeira). Fetch direto, não
 * `router.get()`/`<Link>` do Inertia: `/enderecos/pendencias-geo` é o
 * contrato JSON puro da Task 22.5 (`AddressGeoController::pendencias()`),
 * sem resposta Inertia - navegar por lá com o mecanismo do Inertia
 * confundiria os dois contratos.
 */
async function carregarPagina(url = null) {
  carregando.value = true;
  erro.value = '';
  destruirMapas();

  try {
    const resposta = await fetch(url || route('enderecos.pendencias-geo'), {
      headers: { Accept: 'application/json' },
    });

    if (!resposta.ok) {
      erro.value = 'Não foi possível carregar as pendências de coordenada.';
      return;
    }

    pendencias.value = await resposta.json();
  } catch {
    erro.value = 'Não foi possível carregar as pendências de coordenada. Verifique sua conexão.';
  } finally {
    carregando.value = false;
  }
}

function removerDaLista(id) {
  if (!pendencias.value) return;

  pendencias.value = {
    ...pendencias.value,
    data: pendencias.value.data.filter((item) => item.id !== id),
    total: Math.max(0, pendencias.value.total - 1),
  };

  const instancia = mapasPorId[id];
  if (instancia) {
    instancia.mapa.remove();
    delete mapasPorId[id];
  }
}

function atualizarItemNaLista(id, camposNovos) {
  if (!pendencias.value) return;

  pendencias.value = {
    ...pendencias.value,
    data: pendencias.value.data.map((item) => (item.id === id ? { ...item, ...camposNovos } : item)),
  };
}

/**
 * Endereço sem coordenada OU com precisão `cidade` - mesmo critério de
 * `Address::scopePendenteDeGeocodificacao()`.
 */
function aindaPendente(endereco) {
  return endereco.latitude === null || endereco.longitude === null || endereco.precisao_geocodificacao === 'cidade';
}

async function salvarCoordenada(id, latitude, longitude) {
  salvandoPorId.value = { ...salvandoPorId.value, [id]: true };
  erroPorId.value = { ...erroPorId.value, [id]: '' };

  try {
    const resposta = await fetch(route('enderecos.coordenada.update', id), {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ latitude, longitude }),
    });

    if (!resposta.ok) {
      const corpo = await resposta.json().catch(() => null);
      erroPorId.value = { ...erroPorId.value, [id]: corpo?.message || 'Não foi possível salvar a coordenada.' };
      reposicionarMarcador(id);
      return;
    }

    // Correção manual sempre grava precisão `exata` (`GeocodificacaoService::definirManualmente()`),
    // então o endereço nunca continua pendente depois de um salvamento bem-sucedido.
    removerDaLista(id);
  } catch {
    erroPorId.value = { ...erroPorId.value, [id]: 'Não foi possível salvar a coordenada. Verifique sua conexão.' };
    reposicionarMarcador(id);
  } finally {
    salvandoPorId.value = { ...salvandoPorId.value, [id]: false };
  }
}

function salvarCoordenadaDigitada(item) {
  const forma = formularioDoItem(item);

  if (String(forma.latitude).trim() === '' || String(forma.longitude).trim() === '') {
    erroPorId.value = { ...erroPorId.value, [item.id]: 'Informe latitude e longitude.' };
    return;
  }

  const latitude = Number(forma.latitude);
  const longitude = Number(forma.longitude);

  if (!Number.isFinite(latitude) || latitude < -90 || latitude > 90) {
    erroPorId.value = { ...erroPorId.value, [item.id]: 'Informe uma latitude válida, entre -90 e 90.' };
    return;
  }

  if (!Number.isFinite(longitude) || longitude < -180 || longitude > 180) {
    erroPorId.value = { ...erroPorId.value, [item.id]: 'Informe uma longitude válida, entre -180 e 180.' };
    return;
  }

  const instancia = mapasPorId[item.id];
  if (instancia) {
    instancia.marcador.setLatLng([latitude, longitude]);
    instancia.mapa.setView([latitude, longitude]);
  }

  salvarCoordenada(item.id, latitude, longitude);
}

async function tentarNovamente(item) {
  geocodificandoPorId.value = { ...geocodificandoPorId.value, [item.id]: true };
  erroPorId.value = { ...erroPorId.value, [item.id]: '' };

  try {
    const resposta = await fetch(route('enderecos.geocodificar', item.id), {
      method: 'POST',
      headers: { Accept: 'application/json' },
    });

    const corpo = await resposta.json().catch(() => null);

    if (!resposta.ok) {
      erroPorId.value = { ...erroPorId.value, [item.id]: corpo?.message || 'Não foi possível geocodificar novamente.' };
      return;
    }

    const enderecoAtualizado = corpo.endereco;

    if (aindaPendente(enderecoAtualizado)) {
      atualizarItemNaLista(item.id, enderecoAtualizado);
      delete formularios.value[item.id];
      reposicionarMarcador(item.id);
      erroPorId.value = { ...erroPorId.value, [item.id]: 'Ainda sem coordenada confiável. Ajuste manualmente.' };
    } else {
      removerDaLista(item.id);
    }
  } catch {
    erroPorId.value = { ...erroPorId.value, [item.id]: 'Não foi possível geocodificar novamente. Verifique sua conexão.' };
  } finally {
    geocodificandoPorId.value = { ...geocodificandoPorId.value, [item.id]: false };
  }
}

onMounted(() => carregarPagina());
onBeforeUnmount(destruirMapas);
</script>
