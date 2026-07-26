// Alfabeto e regras do código público do dispositivo no frontend, espelhando
// `App\Support\CodigoDeDispositivo` no backend: sem `I`, `O`, `0` e `1`, que
// são exatamente os caracteres que o técnico confunde ao digitar de olho na
// etiqueta. As duas cópias precisam ficar iguais; quem mudar uma muda a
// outra.

export const ALFABETO_CODIGO_DISPOSITIVO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

export const TAMANHO_CODIGO_DISPOSITIVO = 10;

/**
 * Um caractere pertence ao alfabeto do código?
 *
 * Aceita tanto maiúscula quanto minúscula, comparando em maiúsculas: o
 * `keydown` do input recebe a tecla do jeito que a pessoa digitou, e a
 * decisão de aceitar ou recusar tem que valer para as duas capitalizações
 * antes de qualquer normalização acontecer. Quem quiser a forma final em
 * maiúsculas usa `normalizarDigitacaoDeCodigo`.
 */
export function caractereDoCodigoEhValido(caractere) {
  if (typeof caractere !== 'string' || caractere.length !== 1) {
    return false;
  }

  return ALFABETO_CODIGO_DISPOSITIVO.includes(caractere.toUpperCase());
}

/**
 * Forma final do que foi digitado ou colado: maiúsculas, só caracteres do
 * alfabeto e no máximo dez posições.
 *
 * Serve de rede de segurança para colar (paste) e autocompletar, que não
 * passam pelo bloqueio de tecla do `keydown` do input.
 */
export function normalizarDigitacaoDeCodigo(valor) {
  if (typeof valor !== 'string') {
    return '';
  }

  return valor
    .toUpperCase()
    .split('')
    .filter((caractere) => ALFABETO_CODIGO_DISPOSITIVO.includes(caractere))
    .slice(0, TAMANHO_CODIGO_DISPOSITIVO)
    .join('');
}

/**
 * Extrai o código do dispositivo do texto que a câmera decodificou do QR
 * code.
 *
 * A etiqueta impressa (`DeviceLabelService::qrCode`) codifica a URL completa
 * de leitura (".../devices/ler/{codigo}"), e não o código isolado, para a
 * câmera nativa do celular abrir o link sem precisar de aplicativo. Este
 * leitor recebe o mesmo texto e usa só o último trecho do caminho. Texto que
 * não é uma URL (etiqueta antiga com o código puro, ou teste manual) volta
 * como veio, sem alteração — quem valida se aquilo é mesmo um código é quem
 * chama, não esta função.
 */
export function extrairCodigoDoTextoLido(texto) {
  if (typeof texto !== 'string') {
    return '';
  }

  const semEspacos = texto.trim();

  if (semEspacos === '') {
    return '';
  }

  try {
    const url = new URL(semEspacos);
    const segmentos = url.pathname.split('/').filter(Boolean);

    if (segmentos.length > 0) {
      return segmentos[segmentos.length - 1];
    }
  } catch {
    // Não é uma URL: segue com o texto puro.
  }

  return semEspacos;
}
