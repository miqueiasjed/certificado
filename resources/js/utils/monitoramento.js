// Agrupa por endereço os quatro blocos que `ConsolidadorDePeriodo::consolidar()`
// devolve em listas paralelas (`tendencia`, `ranking_pontos_criticos`,
// `mapa_de_calor`, `ocorrencia_por_especie`), cada uma com um item por
// endereço do retrato. Junta por `address_id`, nunca por posição na lista -
// mesmo critério de `RelatorioPdfService::montarPorEndereco()` no backend
// ("nada garante às cegas que as quatro listas preservem exatamente a mesma
// ordem para sempre").
//
// Usado tanto pela visão ao vivo (`Monitoring/Index.vue`, dado vindo de
// `consolidado`) quanto pelo relatório congelado
// (`Monitoring/Relatorios/Show.vue`, dado vindo de `relatorio.dados`): as
// duas têm exatamente o mesmo formato, então a mesma função serve às duas.
//
// @param {object} dados - o array `consolidado`/`relatorio.dados` inteiro.
// @returns {Array<{address_id: number, endereco: string, evolucaoSemanal: object, evolucaoMensal: object, ranking: object|null, mapa: object|null, especies: object|null}>}
export function montarPorEndereco(dados) {
  const tendencias = dados?.tendencia ?? [];
  const rankings = dados?.ranking_pontos_criticos ?? [];
  const mapas = dados?.mapa_de_calor ?? [];
  const especiesLista = dados?.ocorrencia_por_especie ?? [];

  const porId = (lista) => {
    const mapa = new Map();
    lista.forEach((item) => mapa.set(item.address_id, item));
    return mapa;
  };

  const rankingPorId = porId(rankings);
  const mapaPorId = porId(mapas);
  const especiesPorId = porId(especiesLista);

  return tendencias.map((tendencia) => ({
    address_id: tendencia.address_id,
    endereco: tendencia.endereco,
    evolucaoSemanal: tendencia.evolucao_semanal,
    evolucaoMensal: tendencia.evolucao_mensal,
    ranking: rankingPorId.get(tendencia.address_id) ?? null,
    mapa: mapaPorId.get(tendencia.address_id) ?? null,
    especies: especiesPorId.get(tendencia.address_id) ?? null,
  }));
}

// Soma, período a período, os totais de todos os dispositivos de uma
// evolução (`evolucao_semanal`/`evolucao_mensal` de um único endereço,
// exatamente o formato que `TendenciaService::evolucaoPorDispositivo()`
// devolve). Mesma agregação de `RelatorioPdfService::graficoEvolucaoMensal()`:
// um período só é "sem visita" quando a soma de `visitas` de TODOS os
// dispositivos naquele período é zero - um único dispositivo visitado já
// tira o período da marcação "sem visita" no nível do endereço.
//
// @param {object} evolucao - `{ periodos: [...], dispositivos: [...] }`
// @returns {Array<{chave: string, inicio: string, fim: string, semVisita: boolean, capturas: number, visitas: number}>}
export function agregarEvolucaoPorPeriodo(evolucao) {
  const periodos = evolucao?.periodos ?? [];
  const dispositivos = evolucao?.dispositivos ?? [];

  return periodos.map((periodo, indice) => {
    let totalVisitas = 0;
    let totalCapturas = 0;

    dispositivos.forEach((dispositivo) => {
      const ponto = dispositivo.serie?.[indice];
      if (!ponto) return;

      totalVisitas += ponto.visitas || 0;
      totalCapturas += ponto.capturas || 0;
    });

    return {
      chave: periodo.chave,
      inicio: periodo.inicio,
      fim: periodo.fim,
      semVisita: totalVisitas === 0,
      capturas: totalCapturas,
      visitas: totalVisitas,
    };
  });
}

export default { montarPorEndereco, agregarEvolucaoPorPeriodo };
