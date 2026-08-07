// Paleta única do relatório de monitoramento (Plano 21, Task 21.8), replicada
// exatamente das mesmas cores que `RelatorioPdfService` já usa nos gráficos
// SVG do PDF (`svgEvolucao()`, `svgMapaDeCalor()`, `svgCroqui()`), para a
// tela ao vivo, o relatório congelado e o portal do cliente nunca divergirem
// visualmente do documento oficial.
//
// - `captura` é o verde primário do tema (`#059669`, mesmo hex do
//   `section-title`/barra cheia do PDF).
// - `semVisita` é o cinza que o PDF usa para a barra "sem visita"
//   (`#9CA3AF`) - nunca a mesma cor de dado real, de propósito: confundir as
//   duas é a distorção mais grave do relatório (ver `TendenciaService`).
// - `periodoAnterior` é um passo mais claro do mesmo verde (green-200 do
//   Tailwind), usado só para comparar "antes" x "depois" dentro da mesma
//   família de cor - nunca uma cor nova sem relação com o tema.
export const CORES_MONITORAMENTO = {
  captura: '#059669',
  semVisita: '#9CA3AF',
  periodoAnterior: '#A7F3D0',
  trilha: '#E5E7EB',
  bordaGrafico: '#D1D5DB',
};

// Mesmo vocabulário fechado de `pest_sightings.pest_type`
// (`OcorrenciaPorEspecieService::ESPECIES`) e a mesma tradução de
// `PestSighting::getPestTypeTextAttribute()` no backend - duplicado aqui de
// propósito (o dado chega em inglês do backend) em vez de o backend enviar o
// rótulo pronto, mesmo critério já usado no restante do frontend para texto
// estático de enum.
export const ROTULOS_ESPECIE = {
  rats: 'Ratos',
  mice: 'Camundongos',
  cockroaches: 'Baratas',
  ants: 'Formigas',
  termites: 'Cupins',
  flies: 'Moscas',
  fleas: 'Pulgas',
  ticks: 'Carrapatos',
  scorpions: 'Escorpiões',
  spiders: 'Aranhas',
  bees: 'Abelhas',
  wasps: 'Vespas',
  other: 'Outros',
};

export function rotuloEspecie(pestType) {
  return ROTULOS_ESPECIE[pestType] || 'Desconhecido';
}

// Mesmo texto para os quatro estados de `PontosCriticosService::TENDENCIA_*`
// usados no ranking de pontos críticos.
export function rotuloTendencia(estado) {
  return {
    subindo: 'Subindo',
    caindo: 'Caindo',
    estavel: 'Estável',
    sem_comparacao: 'Sem comparação',
  }[estado] || 'Sem comparação';
}

// Classe de badge por estado da tendência: "subindo" é mais captura no
// período (piora), "caindo" é menos (melhora) - mesma tabela de cores de
// status da skill de design (verde/vermelho/cinza), nunca inventada aqui.
export function classeTendencia(estado) {
  return {
    subindo: 'bg-red-100 text-red-800',
    caindo: 'bg-green-100 text-green-800',
    estavel: 'bg-gray-100 text-gray-800',
    sem_comparacao: 'bg-gray-100 text-gray-500',
  }[estado] || 'bg-gray-100 text-gray-500';
}

export default {
  CORES_MONITORAMENTO,
  ROTULOS_ESPECIE,
  rotuloEspecie,
  rotuloTendencia,
  classeTendencia,
};
