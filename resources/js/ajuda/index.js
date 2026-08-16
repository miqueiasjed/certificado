/**
 * Catálogo do manual de uso do sistema.
 *
 * Cada arquivo de área exporta um objeto cujas chaves são o nome do componente
 * Inertia da tela (o mesmo valor de `usePage().component`, ex.: "Clients/Index")
 * e cujos valores seguem o formato descrito em `modelo.js`.
 *
 * A chave pode terminar em `/*` para valer como manual de todas as telas da
 * pasta que não tiverem manual próprio (ex.: "Rooms/*").
 */
import geral from './geral.js';
import clientes from './clientes.js';
import operacao from './operacao.js';
import comercial from './comercial.js';
import financeiro from './financeiro.js';
import tecnico from './tecnico.js';
import configuracoes from './configuracoes.js';
import portal from './portal.js';
import plataforma from './plataforma.js';

export const manuais = {
  ...geral,
  ...clientes,
  ...operacao,
  ...comercial,
  ...financeiro,
  ...tecnico,
  ...configuracoes,
  ...portal,
  ...plataforma,
};

/**
 * Escopos de acesso: cada área do sistema só enxerga os manuais das telas que
 * o usuário dela pode abrir. O cliente que usa o portal não pode navegar pelo
 * manual das telas internas da empresa, e vice-versa.
 */
const ESCOPOS = {
  portal: (chave) => chave.startsWith('Portal/'),
  plataforma: (chave) => chave.startsWith('Plataforma/'),
  sistema: (chave) => !chave.startsWith('Portal/') && !chave.startsWith('Plataforma/'),
};

const entradasDoEscopo = (escopo) => {
  const pertence = ESCOPOS[escopo] ?? ESCOPOS.sistema;

  return Object.entries(manuais)
    .filter(([chave]) => pertence(chave))
    .map(([chave, manual]) => ({ chave, ...manual }));
};

/**
 * Devolve o manual da tela informada, com dois níveis de fallback:
 * 1. chave exata ("Clients/Edit")
 * 2. curinga da pasta ("Clients/*")
 */
export function manualDaTela(componente) {
  if (!componente) {
    return null;
  }

  if (manuais[componente]) {
    return { chave: componente, ...manuais[componente] };
  }

  const pasta = componente.includes('/') ? componente.slice(0, componente.lastIndexOf('/')) : componente;
  const curinga = `${pasta}/*`;

  if (manuais[curinga]) {
    return { chave: curinga, ...manuais[curinga] };
  }

  return null;
}

const normalizar = (texto) => String(texto ?? '')
  .toLowerCase()
  .normalize('NFD')
  .replace(/[\u0300-\u036f]/g, '');

const textoPesquisavel = (manual) => normalizar([
  manual.titulo,
  manual.paraQueServe,
  ...(manual.comoUsar ?? []).flatMap((bloco) => [bloco.titulo, ...(bloco.passos ?? [])]),
  ...(manual.campos ?? []).flatMap((campo) => [campo.nome, campo.descricao]),
  ...(manual.dicas ?? []),
  ...(manual.atencao ?? []),
].join(' '));

/**
 * Busca por termo em todos os manuais. Devolve no máximo `limite` resultados,
 * priorizando quem tem o termo no título.
 */
export function buscarNosManuais(termo, escopo = 'sistema', limite = 20) {
  const busca = normalizar(termo).trim();

  if (busca.length < 2) {
    return [];
  }

  return entradasDoEscopo(escopo)
    .map((manual) => {
      const noTitulo = normalizar(manual.titulo).includes(busca);
      const noCorpo = textoPesquisavel(manual).includes(busca);

      return { manual, relevancia: noTitulo ? 2 : (noCorpo ? 1 : 0) };
    })
    .filter((item) => item.relevancia > 0)
    .sort((a, b) => b.relevancia - a.relevancia || a.manual.titulo.localeCompare(b.manual.titulo, 'pt-BR'))
    .slice(0, limite)
    .map((item) => item.manual);
}

/**
 * Lista os manuais agrupados por área, para o índice geral do painel de ajuda.
 */
export function manuaisPorArea(escopo = 'sistema') {
  const areas = new Map();

  entradasDoEscopo(escopo).forEach((manual) => {
    const area = manual.area ?? 'Outros';

    if (!areas.has(area)) {
      areas.set(area, []);
    }

    areas.get(area).push(manual);
  });

  return Array.from(areas.entries())
    .map(([area, telas]) => ({
      area,
      telas: telas.sort((a, b) => a.titulo.localeCompare(b.titulo, 'pt-BR')),
    }))
    .sort((a, b) => a.area.localeCompare(b.area, 'pt-BR'));
}
