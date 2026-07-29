// Resolução da identidade de cor do portal do cliente (Plano 15, Task 15.6).
//
// A cor do tenant vem do banco (`companies.cor_primaria`/`cor_destaque`, Company::brandingDoPortal())
// como texto solto, e o único uso que `PortalLayout.vue` faz dela é virar o
// valor de uma variável CSS (`--portal-cor-primaria`/`--portal-cor-destaque`)
// atribuída via binding de objeto do Vue (`:style="{ '--x': valor }"`), nunca
// interpolada dentro de um bloco `<style>` como texto solto. Mesmo assim, a
// validação abaixo roda antes de qualquer valor vindo do banco chegar ao
// `:style`: um texto como `red; background-image:url(...)` não executaria
// nada (não é injeção de JavaScript), mas poderia acrescentar uma declaração
// CSS a mais na regra, e a única forma de fechar essa porta por completo é
// nunca deixar passar nada que não seja estritamente `#RRGGBB`.
//
// Este arquivo não depende de nada do Vue nem do Inertia de propósito: é
// lógica pura, revisável e testável isoladamente (o projeto não tem
// Vitest/Jest configurado hoje, então não há teste automatizado aqui, mas a
// separação em função pura é o que deixaria isso testável no dia em que um
// framework de teste de frontend entrar).

const REGEX_HEXADECIMAL = /^#[0-9a-fA-F]{6}$/;

/** Verde do tema do sistema (`bg-green-600`/`primary-green` do tailwind.config.js). */
export const COR_PRIMARIA_PADRAO = '#059669';

/** Verde escuro do tema do sistema (`hover:bg-green-700`/`dark-green` do tailwind.config.js). */
export const COR_DESTAQUE_PADRAO = '#047857';

/**
 * `true` só para uma string no formato exato `#` seguido de 6 dígitos
 * hexadecimais. Qualquer outra coisa (nulo, vazio, cor CSS nomeada, `rgb(...)`,
 * hex de 3 dígitos, ou qualquer texto com caractere a mais) é inválida de
 * propósito: o objetivo aqui não é aceitar toda cor CSS válida, é aceitar
 * só o formato estreito o suficiente para nunca abrir uma segunda declaração
 * dentro do valor da variável.
 */
export function corHexValida(valor) {
  return typeof valor === 'string' && REGEX_HEXADECIMAL.test(valor.trim());
}

/**
 * Cores efetivas do portal: a do tenant quando válida, o verde padrão do
 * sistema quando ausente ou inválida.
 *
 * @param {{ cor_primaria?: string|null, cor_destaque?: string|null }|null} empresa
 * @returns {{ corPrimaria: string, corDestaque: string }}
 */
export function resolverCoresDoPortal(empresa) {
  const primaria = empresa?.cor_primaria;
  const destaque = empresa?.cor_destaque;

  return {
    corPrimaria: corHexValida(primaria) ? primaria.trim() : COR_PRIMARIA_PADRAO,
    corDestaque: corHexValida(destaque) ? destaque.trim() : COR_DESTAQUE_PADRAO,
  };
}
