<template>
  <div class="print:hidden">
    <div
      v-if="paradasComCoordenada.length > 0"
      ref="mapaContainer"
      class="h-72 w-full overflow-hidden rounded-lg border border-gray-200 sm:h-96"
    />
    <div
      v-else
      class="flex h-48 w-full items-center justify-center rounded-lg border border-dashed border-gray-300 bg-gray-50 text-sm text-gray-500"
    >
      Nenhuma parada com coordenada boa para mostrar no mapa.
    </div>

    <!-- Paradas sem coordenada boa: nunca somem da tela, só saem do mapa
         (regra de negócio inegociável da Task 22.6). -->
    <div
      v-if="paradasSemCoordenada.length > 0"
      class="mt-4 rounded-md border border-amber-200 bg-amber-50 p-4"
    >
      <p class="text-sm font-medium text-amber-800">
        {{ paradasSemCoordenada.length }}
        {{ paradasSemCoordenada.length === 1 ? 'parada sem coordenada boa não aparece' : 'paradas sem coordenada boa não aparecem' }}
        no mapa.
      </p>
      <ul class="mt-2 space-y-1">
        <li
          v-for="parada in paradasSemCoordenada"
          :key="parada.route_stop_id"
          class="text-sm text-amber-900"
        >
          {{ parada.ordem }}. {{ ehCompromisso(parada) ? `${rotuloDeTipoDeCompromisso(parada.compromisso_tipo)} - ` : '' }}{{ tituloDaParada(parada) }} - {{ parada.endereco || 'Endereço não informado' }}
        </li>
      </ul>
      <Link
        v-if="podeCorrigirCoordenada"
        :href="route('enderecos.pendencias-geo.painel')"
        class="mt-3 inline-block text-sm font-medium text-amber-900 underline hover:text-amber-700"
      >
        Corrigir coordenadas pendentes
      </Link>
    </div>
  </div>
</template>

<script setup>
/**
 * Mapa das paradas do roteiro do dia (Plano 22, Task 22.6).
 *
 * Biblioteca escolhida: Leaflet direto (sem wrapper `@vue-leaflet/vue-leaflet`)
 * - uso pontual (marcador numerado com `L.divIcon`, popup por parada, linha
 * conectando as paradas em ordem), então instanciar o mapa manualmente em
 * `onMounted`/limpar em `onBeforeUnmount` é mais simples e mais direto de
 * depurar do que aprender a API de um wrapper para um caso de uso único.
 * Tiles do OpenStreetMap por padrão, configurável por `VITE_MAP_TILES_URL`
 * (variável do Vite - só chega ao bundle com o prefixo `VITE_`, documentada
 * em `.env.example`), mesmo espírito da escolha do Nominatim na Task 22.2:
 * leve, gratuito, sem chave de API.
 *
 * ## Marcador da sede
 *
 * `RouteController::sedeCoordenada()` devolve `null` até `companies` ganhar
 * coordenada própria (ver o cabeçalho daquele controller) - por isso este
 * componente aceita `sede` como prop opcional, pronta para quando essa
 * informação existir, mas nenhuma tela hoje tem de onde passar um valor
 * diferente de `null`. Sem sede, a linha começa na primeira parada, como o
 * enunciado da task pede explicitamente.
 */
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Link } from '@inertiajs/vue3';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { formatarHora } from '@/utils/formatDate';
import { usePermissoes } from '@/Composables/usePermissoes';
import { rotuloDeTipoDeCompromisso } from '@/utils/compromisso';

const props = defineProps({
  // Paradas no formato de `RouteController::apresentarRota()`: cada item
  // tem `route_stop_id`, `ordem`, `cliente`, `endereco`, `chegada_estimada`,
  // `latitude`, `longitude`, `precisao_geocodificacao`.
  paradas: {
    type: Array,
    default: () => [],
  },
  // { latitude, longitude } da sede, ou `null` enquanto não existir (ver o
  // cabeçalho acima).
  sede: {
    type: Object,
    default: null,
  },
});

const { pode } = usePermissoes();
const podeCorrigirCoordenada = computed(() => pode('endereco-geo'));

const mapaContainer = ref(null);
let mapa = null;
let camadaDeParadas = null;

// Coordenada "boa" (Task 22.6): tem latitude/longitude E a precisão não é
// `cidade` - mesmo critério que `RouteService::gravarParadas()`/
// `recalcularDistancias()` já usam no backend para decidir se uma parada
// serve de origem para o próximo trecho (`ResultadoGeo::PRECISAO_CIDADE`).
function temCoordenadaBoa(parada) {
  return parada.latitude !== null
    && parada.latitude !== undefined
    && parada.longitude !== null
    && parada.longitude !== undefined
    && parada.precisao_geocodificacao !== 'cidade';
}

const paradasComCoordenada = computed(() => props.paradas.filter(temCoordenadaBoa));
const paradasSemCoordenada = computed(() => props.paradas.filter((parada) => !temCoordenadaBoa(parada)));

// Parada de compromisso avulso (Plano 31, Task 31.4): mesma marcação
// `tipo_item` e mesma paleta âmbar já adotadas na Agenda (Plano 30, Task
// 30.5, ver `CartaoDeVisita.vue`), para o usuário reconhecer o mesmo padrão
// visual no mapa do roteiro.
function ehCompromisso(parada) {
  return parada.tipo_item === 'compromisso';
}

// Compromisso sem cliente vinculado usa o próprio título no lugar do nome do
// cliente; com cliente vinculado, mostra o cliente como sempre.
function tituloDaParada(parada) {
  if (ehCompromisso(parada) && !parada.cliente) {
    return parada.compromisso_titulo || 'Compromisso sem título';
  }

  return parada.cliente || 'Cliente não informado';
}

const sedeComCoordenada = computed(() => {
  if (!props.sede || props.sede.latitude === null || props.sede.longitude === null) {
    return null;
  }

  return props.sede;
});

function escaparHtml(valor) {
  if (valor === null || valor === undefined) return '';

  return String(valor)
    .replaceAll('&', '&amp;')
    .replaceAll('<', '&lt;')
    .replaceAll('>', '&gt;')
    .replaceAll('"', '&quot;')
    .replaceAll("'", '&#39;');
}

function iconeNumerado(numero, ehCompromissoDaParada) {
  const corFundo = ehCompromissoDaParada ? 'bg-amber-500' : 'bg-green-600';

  return L.divIcon({
    className: '',
    html: `<div class="flex h-7 w-7 items-center justify-center rounded-full border-2 border-white ${corFundo} text-xs font-bold text-white shadow-md">${numero}</div>`,
    iconSize: [28, 28],
    iconAnchor: [14, 14],
    popupAnchor: [0, -16],
  });
}

function iconeSede() {
  return L.divIcon({
    className: '',
    html: '<div class="flex h-7 w-7 items-center justify-center rounded-full border-2 border-white bg-gray-700 text-xs font-bold text-white shadow-md">P</div>',
    iconSize: [28, 28],
    iconAnchor: [14, 14],
    popupAnchor: [0, -16],
  });
}

function popupDaParada(parada) {
  const horario = formatarHora(parada.chegada_estimada);
  const badgeTipo = ehCompromisso(parada)
    ? `<span class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-bold uppercase tracking-wide bg-amber-100 text-amber-800 mr-1.5 align-middle">${escaparHtml(rotuloDeTipoDeCompromisso(parada.compromisso_tipo))}</span>`
    : '';

  return `
    <div class="text-sm">
      <p class="font-semibold text-gray-900">${badgeTipo}${parada.ordem}. ${escaparHtml(tituloDaParada(parada))}</p>
      <p class="text-gray-600">${escaparHtml(parada.endereco || 'Endereço não informado')}</p>
      ${horario ? `<p class="mt-1 text-gray-500">Chegada prevista: ${horario}</p>` : ''}
    </div>
  `;
}

function inicializarMapa() {
  if (!mapaContainer.value || mapa !== null) return;

  const tilesUrl = import.meta.env.VITE_MAP_TILES_URL || 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png';

  mapa = L.map(mapaContainer.value, { scrollWheelZoom: true });

  L.tileLayer(tilesUrl, {
    attribution: '&copy; <a href="https://www.openstreetmap.org/copyright" target="_blank" rel="noopener">OpenStreetMap</a>',
    maxZoom: 19,
  }).addTo(mapa);

  camadaDeParadas = L.layerGroup().addTo(mapa);

  desenhar();
}

function desenhar() {
  if (!mapa || !camadaDeParadas) return;

  camadaDeParadas.clearLayers();

  const pontos = [];

  if (sedeComCoordenada.value) {
    const ponto = [sedeComCoordenada.value.latitude, sedeComCoordenada.value.longitude];
    pontos.push(ponto);

    L.marker(ponto, { icon: iconeSede() })
      .bindPopup('<p class="text-sm font-semibold text-gray-900">Sede</p>')
      .addTo(camadaDeParadas);
  }

  paradasComCoordenada.value.forEach((parada) => {
    const ponto = [parada.latitude, parada.longitude];
    pontos.push(ponto);

    L.marker(ponto, { icon: iconeNumerado(parada.ordem, ehCompromisso(parada)) })
      .bindPopup(popupDaParada(parada))
      .addTo(camadaDeParadas);
  });

  if (pontos.length > 1) {
    L.polyline(pontos, { color: '#059669', weight: 3, opacity: 0.8 }).addTo(camadaDeParadas);
  }

  if (pontos.length > 0) {
    mapa.fitBounds(L.latLngBounds(pontos), { padding: [32, 32], maxZoom: 16 });
  } else {
    // Sem nenhum ponto: centro do Brasil, bem afastado, só para o mapa não
    // ficar em branco.
    mapa.setView([-14.235, -51.9253], 4);
  }
}

onMounted(async () => {
  await nextTick();
  inicializarMapa();
});

function destruirMapa() {
  mapa?.remove();
  mapa = null;
  camadaDeParadas = null;
}

onBeforeUnmount(destruirMapa);

// `paradasComCoordenada.value.length === 0` some com o `<div ref="mapaContainer">`
// pelo `v-if` do template (mostra o aviso "nenhuma parada..." no lugar): o
// nó DOM some, então o mapa (preso àquele nó) precisa ser destruído junto,
// senão `mapa` fica travado como "já inicializado" e nunca volta quando uma
// parada com coordenada boa aparecer de novo.
watch(() => paradasComCoordenada.value.length > 0, async (temPontos) => {
  if (!temPontos) {
    destruirMapa();
    return;
  }

  await nextTick();
  inicializarMapa();
});

watch([paradasComCoordenada, sedeComCoordenada], () => {
  if (mapa !== null) {
    desenhar();
  }
});
</script>
