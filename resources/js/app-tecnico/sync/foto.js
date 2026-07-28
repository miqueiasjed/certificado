// Fila e envio de fotos do aplicativo do técnico (Plano 12, Task 12.11).
//
// Por que este arquivo existe separado de `sync/fila.js` e `sync/sincronizador.js`
// -------------------------------------------------------------------------------
// A foto nunca entra no lote de até 50 operações de `POST /api/app/sincronizar`:
// embutir o arquivo em base64 no lote aumentaria o tamanho em um terço e faria
// o lote inteiro falhar por causa de uma imagem (`.claude/tasks/12/12.5.md`).
// Por isso a foto vai em requisição própria (`multipart/form-data` para
// `POST /api/app/fotos`), com fila e ciclo de envio próprios, guardados aqui, na
// tabela `db.fotos` (schema.js, versão 3) - nunca em `db.fila`. As duas filas
// são independentes: uma falha no envio de uma foto nunca bloqueia o lote de
// operações, e vice-versa.
//
// Este arquivo é a única porta de entrada para `db.fotos`, do mesmo jeito que
// `fila.js` é a única porta de entrada para `db.fila`.
//
// Compressão acontece na captura, nunca no envio
// -----------------------------------------------
// `capturarFoto()` já recebe o arquivo, comprime e grava o Blob comprimido -
// o arquivo original nunca é guardado (guardar o original encheria o celular
// em um dia de trabalho) e é descartado (sai de escopo, sem referência) assim
// que a função retorna.
//
// Garantia de EXIF (documentada aqui porque é o ponto mais fácil de quebrar
// sem perceber): `canvas.toBlob()` nunca escreve metadado EXIF no arquivo de
// saída. A foto comprimida por este arquivo não carrega orientação, câmera,
// data nem localização do arquivo original - a única forma de a localização
// chegar ao servidor seria este código reintroduzi-la manualmente, o que ele
// não faz em nenhum ponto. A coordenada da execução, quando existir, é
// registrada de forma explícita e consentida em outro lugar (Plano 13), nunca
// escondida na foto.

import { db } from '../db/schema';
import { obterMeta } from '../db/repositorio';
import { calcularEsperaMs, atingiuTetoDeTentativas } from './sincronizador';

const URL_FOTOS = '/api/app/fotos';
const CHAVE_DO_TOKEN = 'token';

// -----------------------------------------------------------------------
// Parâmetros de compressão (Task 12.11)
// -----------------------------------------------------------------------

export const LADO_MAXIMO_PX = 1600;
export const ALVO_DE_BYTES = 500 * 1024;

// Qualidade JPEG em inteiros de 0 a 100 (e não em fração 0-1): evita o erro de
// arredondamento de ponto flutuante que `0.75 - 0.05` acumularia a cada passo.
export const QUALIDADE_INICIAL = 75;
export const QUALIDADE_MINIMA = 50; // nunca abaixo disso - foto de praga ilegível não serve como prova.
export const PASSO_DE_QUALIDADE = 5;

// -----------------------------------------------------------------------
// Teto de fotos pendentes na base local (Task 12.11)
// -----------------------------------------------------------------------

export const LIMITE_DE_FOTOS_PENDENTES = 200;
export const AVISO_DE_FOTOS_PENDENTES = 180;

// -----------------------------------------------------------------------
// Leitura do EXIF de orientação (implementado à mão: não há biblioteca de
// EXIF nas dependências do projeto - ver package.json - e ler só a tag de
// orientação não justifica uma dependência nova)
// -----------------------------------------------------------------------

/**
 * Lê a tag EXIF de orientação (0x0112) de um JPEG, a partir do `ArrayBuffer`
 * do arquivo original. Devolve `1` (sem rotação) para qualquer arquivo que não
 * seja JPEG, que não tenha bloco EXIF ou que não traga a tag - é o valor
 * neutro do padrão EXIF, então tratar a ausência de informação como "sem
 * rotação" é o comportamento seguro, nunca um giro inventado.
 *
 * Percorre os marcadores JPEG a partir do início do arquivo (todo JPEG começa
 * com o marcador SOI, `0xFFD8`) até achar o marcador APP1 (`0xFFE1`), onde o
 * EXIF vive; dentro dele, lê o cabeçalho TIFF (que informa se os números estão
 * em little-endian ou big-endian) e percorre as entradas do primeiro IFD até
 * achar a tag `0x0112` (Orientation). Função pura, exportada para poder ser
 * conferida isoladamente (não há Vitest/Jest configurado neste projeto).
 */
export function lerOrientacaoExif(arrayBuffer) {
    const view = new DataView(arrayBuffer);

    if (view.byteLength < 4 || view.getUint16(0, false) !== 0xffd8) {
        return 1; // Não começa com o marcador SOI: não é um JPEG.
    }

    let offset = 2;
    const comprimento = view.byteLength;

    while (offset + 4 <= comprimento) {
        const marcador = view.getUint16(offset, false);
        offset += 2;

        if (marcador === 0xffe1) {
            return lerOrientacaoDoBlocoApp1(view, offset, comprimento);
        }

        if ((marcador & 0xff00) !== 0xff00) {
            break; // Não é mais um marcador JPEG válido: para a varredura.
        }

        const tamanhoDoBloco = view.getUint16(offset, false);

        if (tamanhoDoBloco < 2) {
            break; // Bloco malformado: para em vez de entrar em loop.
        }

        offset += tamanhoDoBloco;
    }

    return 1;
}

function lerOrientacaoDoBlocoApp1(view, offset, comprimento) {
    if (offset + 10 > comprimento) {
        return 1;
    }

    // "Exif\0\0" (6 bytes) logo depois do tamanho do bloco APP1.
    if (view.getUint32(offset + 2, false) !== 0x45786966) {
        return 1; // Bloco APP1 sem cabeçalho EXIF.
    }

    const tiffOffset = offset + 8;
    const ehLittleEndian = view.getUint16(tiffOffset, false) === 0x4949;
    const primeiroIfdOffset = view.getUint32(tiffOffset + 4, ehLittleEndian);
    const ifdOffset = tiffOffset + primeiroIfdOffset;

    if (ifdOffset + 2 > comprimento) {
        return 1;
    }

    const numeroDeEntradas = view.getUint16(ifdOffset, ehLittleEndian);

    for (let indice = 0; indice < numeroDeEntradas; indice += 1) {
        const entradaOffset = ifdOffset + 2 + indice * 12;

        if (entradaOffset + 12 > comprimento) {
            break;
        }

        const tag = view.getUint16(entradaOffset, ehLittleEndian);

        if (tag === 0x0112) {
            const valor = view.getUint16(entradaOffset + 8, ehLittleEndian);

            return valor >= 1 && valor <= 8 ? valor : 1;
        }
    }

    return 1; // Tag de orientação ausente.
}

/**
 * Aplica, no contexto 2D de um canvas, a transformação que corrige a
 * orientação EXIF lida acima. `largura`/`altura` são as dimensões do desenho
 * ANTES da rotação (o próprio tamanho passado a `drawImage()` em seguida) -
 * são elas, e não as do canvas já trocado, que os deslocamentos abaixo usam,
 * porque o `transform()` roda antes do desenho, no espaço de coordenadas
 * original da imagem.
 */
export function aplicarOrientacaoNoContexto(contexto, orientacao, largura, altura) {
    switch (orientacao) {
        case 2:
            contexto.transform(-1, 0, 0, 1, largura, 0); // espelho horizontal
            break;
        case 3:
            contexto.transform(-1, 0, 0, -1, largura, altura); // 180°
            break;
        case 4:
            contexto.transform(1, 0, 0, -1, 0, altura); // espelho vertical
            break;
        case 5:
            contexto.transform(0, 1, 1, 0, 0, 0); // espelho + 90° horário
            break;
        case 6:
            contexto.transform(0, 1, -1, 0, altura, 0); // 90° horário (o caso comum de foto vertical)
            break;
        case 7:
            contexto.transform(0, -1, -1, 0, altura, largura); // espelho + 90° anti-horário
            break;
        case 8:
            contexto.transform(0, -1, 1, 0, 0, largura); // 90° anti-horário
            break;
        default:
            break; // 1 (ou qualquer valor não mapeado): nenhuma correção a aplicar.
    }
}

// -----------------------------------------------------------------------
// Compressão (Task 12.11)
// -----------------------------------------------------------------------

/**
 * Calcula largura/altura reduzidas para que o maior lado não passe de
 * `ladoMaximo`, preservando a proporção. Não amplia imagem menor que o limite.
 */
export function calcularDimensoesReduzidas(larguraOriginal, alturaOriginal, ladoMaximo) {
    const maiorLado = Math.max(larguraOriginal, alturaOriginal);

    if (maiorLado <= ladoMaximo) {
        return { largura: larguraOriginal, altura: alturaOriginal };
    }

    const fator = ladoMaximo / maiorLado;

    return {
        largura: Math.max(1, Math.round(larguraOriginal * fator)),
        altura: Math.max(1, Math.round(alturaOriginal * fator)),
    };
}

/**
 * Decodifica o arquivo para uma fonte desenhável no canvas (`ImageBitmap` ou,
 * no fallback, um `Image`), explicitamente SEM a rotação automática que
 * navegadores modernos aplicam por padrão a partir do próprio EXIF.
 *
 * `imageOrientation: 'none'` é o que evita a foto girar duas vezes (uma pelo
 * navegador, outra por `aplicarOrientacaoNoContexto()` acima): sem essa opção,
 * um navegador que já corrige sozinho receberia por cima a correção manual
 * deste arquivo, resultando numa rotação dobrada.
 */
async function decodificarSemRotacaoAutomatica(arquivo) {
    if (typeof createImageBitmap === 'function') {
        try {
            return await createImageBitmap(arquivo, { imageOrientation: 'none' });
        } catch {
            // Navegador aceita a chamada mas falhou por outro motivo (formato
            // raro, memória): cai para o caminho de `<img>`, mais tolerante.
        }
    }

    return decodificarViaElementoImagem(arquivo);
}

// Caminho de reserva para navegador sem `createImageBitmap`: um `Image()`
// nunca inserido no DOM não recebe a rotação automática que só se aplica à
// renderização visual de um `<img>` de verdade, então aplicar a correção
// manual em cima dele é seguro, sem risco da rotação dobrada citada acima.
async function decodificarViaElementoImagem(arquivo) {
    const url = URL.createObjectURL(arquivo);

    try {
        const imagem = new Image();

        await new Promise((resolve, reject) => {
            imagem.onload = () => resolve();
            imagem.onerror = () => reject(new Error('Não foi possível abrir esta imagem.'));
            imagem.src = url;
        });

        return imagem;
    } finally {
        // Seguro revogar aqui: a decodificação de pixels já aconteceu no
        // `onload` acima, e o elemento continua desenhável depois disso.
        URL.revokeObjectURL(url);
    }
}

function larguraNaturalDe(fonte) {
    return fonte.naturalWidth ?? fonte.width;
}

function alturaNaturalDe(fonte) {
    return fonte.naturalHeight ?? fonte.height;
}

function canvasParaBlob(canvas, qualidadeInteira) {
    return new Promise((resolve) => {
        canvas.toBlob((blob) => resolve(blob), 'image/jpeg', qualidadeInteira / 100);
    });
}

/**
 * Comprime o canvas já redimensionado e rotacionado até caber em
 * `ALVO_DE_BYTES`, reduzindo a qualidade em passos de `PASSO_DE_QUALIDADE` a
 * partir de `QUALIDADE_INICIAL`, sem nunca passar do piso `QUALIDADE_MINIMA`:
 * abaixo disso a foto de praga fica ilegível e deixa de servir como prova.
 */
export async function comprimirCanvasAteOAlvo(canvas) {
    let qualidade = QUALIDADE_INICIAL;
    let blob = await canvasParaBlob(canvas, qualidade);

    while (blob && blob.size > ALVO_DE_BYTES && qualidade > QUALIDADE_MINIMA) {
        qualidade = Math.max(QUALIDADE_MINIMA, qualidade - PASSO_DE_QUALIDADE);
        blob = await canvasParaBlob(canvas, qualidade);
    }

    return blob;
}

/**
 * Ponto de entrada da compressão: recebe o `File`/`Blob` original (câmera ou
 * galeria) e devolve o `Blob` JPEG final, já com a rotação EXIF corrigida,
 * redimensionado para no máximo `LADO_MAXIMO_PX` no lado maior e comprimido
 * até `ALVO_DE_BYTES` (ou até o piso de qualidade, o que vier primeiro).
 *
 * A ordem importa: a orientação é lida e corrigida ANTES da compressão -
 * corrigir depois giraria um JPEG que o `canvas.toBlob()` já gravou sem
 * nenhum EXIF, perdendo a informação de orientação original de vez.
 */
export async function comprimirImagem(arquivo) {
    const bufferOriginal = await arquivo.arrayBuffer();
    const orientacao = lerOrientacaoExif(bufferOriginal);

    const fonte = await decodificarSemRotacaoAutomatica(arquivo);

    try {
        const { largura, altura } = calcularDimensoesReduzidas(
            larguraNaturalDe(fonte),
            alturaNaturalDe(fonte),
            LADO_MAXIMO_PX,
        );

        // Orientações 5 a 8 giram a imagem 90°: o canvas final troca largura
        // por altura para a foto vertical realmente chegar em pé (e não
        // deitada num retângulo com o lado errado).
        const trocaLados = orientacao >= 5 && orientacao <= 8;

        const canvas = document.createElement('canvas');
        canvas.width = trocaLados ? altura : largura;
        canvas.height = trocaLados ? largura : altura;

        const contexto = canvas.getContext('2d');
        aplicarOrientacaoNoContexto(contexto, orientacao, largura, altura);
        contexto.drawImage(fonte, 0, 0, largura, altura);

        return await comprimirCanvasAteOAlvo(canvas);
    } finally {
        fonte.close?.(); // `ImageBitmap` expõe `close()`; `Image` não - por isso o optional chaining.
    }
}

// -----------------------------------------------------------------------
// Notificação de mudança (mesmo padrão de `sync/fila.js`: evento, não polling)
// -----------------------------------------------------------------------

const escutadores = new Set();

export function aoMudar(callback) {
    escutadores.add(callback);

    return () => escutadores.delete(callback);
}

function notificar() {
    escutadores.forEach((callback) => callback());
}

// -----------------------------------------------------------------------
// Captura e gravação local
// -----------------------------------------------------------------------

/**
 * Comprime o arquivo capturado e grava o registro na fila própria de fotos.
 * `contexto` é `{ entityType, entityId, roomId }`, o mesmo trio já usado no
 * upload do painel web (`WorkOrderTabContent.vue`), aqui traduzido para o
 * formato snake_case que `POST /api/app/fotos` espera, porque é exatamente
 * esse objeto que o envio (mais abaixo) manda no `FormData`, sem tradução
 * nenhuma no meio do caminho.
 *
 * `legenda` é opcional na captura (pode ficar `null` e ser preenchida depois,
 * offline, com `atualizarLegenda()`).
 */
export async function capturarFoto({ workOrderId, contexto, arquivo, legenda = null }) {
    const blob = await comprimirImagem(arquivo);

    const registro = {
        uuid: crypto.randomUUID(),
        work_order_id: workOrderId,
        contexto: {
            entity_type: contexto.entityType,
            entity_id: contexto.entityId ?? null,
            room_id: contexto.roomId ?? null,
        },
        legenda: legenda || null,
        blob,
        situacao: 'pendente',
        tentativas: 0,
        ultimo_erro: null,
        proxima_tentativa_em: null,
        criada_em: new Date().toISOString(),
    };

    await db.fotos.add(registro);
    notificar();

    return registro;
}

export async function listarFotosDaOrdem(workOrderId) {
    const fotos = await db.fotos.where('work_order_id').equals(workOrderId).toArray();

    return [...fotos].sort((a, b) => (a.criada_em < b.criada_em ? -1 : a.criada_em > b.criada_em ? 1 : 0));
}

/**
 * Fotos de um contexto específico dentro de uma ordem (uma adequação, um
 * evento de dispositivo ou um evento de cômodo). Filtra em memória depois de
 * ler por `work_order_id`, mesmo padrão de `listarComodosDaOrdem()` em
 * `repositorio.js`: o volume por ordem é pequeno, e o schema não indexa
 * dentro de `contexto` de propósito (ver comentário em `schema.js`).
 */
export async function listarFotosDoContexto(workOrderId, { entityType, entityId = null, roomId = null }) {
    const fotos = await listarFotosDaOrdem(workOrderId);

    return fotos.filter(
        (foto) =>
            foto.contexto?.entity_type === entityType &&
            (foto.contexto?.entity_id ?? null) === (entityId ?? null) &&
            (foto.contexto?.room_id ?? null) === (roomId ?? null),
    );
}

/**
 * Total de fotos ainda não confirmadas pelo servidor (qualquer situação:
 * pendente, enviando ou com falha - todas ainda ocupam a base local e ainda
 * não têm cópia segura do outro lado). É esta contagem, e não só as
 * `pendente`, que decide o aviso de aproximação do teto de 200: uma foto em
 * `falha` continua sendo espaço ocupado no aparelho do mesmo jeito.
 */
export async function contarNaoEnviadas() {
    return db.fotos.count();
}

export async function precisaAvisarSobreLimite() {
    return (await contarNaoEnviadas()) >= AVISO_DE_FOTOS_PENDENTES;
}

export async function atingiuLimite() {
    return (await contarNaoEnviadas()) >= LIMITE_DE_FOTOS_PENDENTES;
}

export async function atualizarLegenda(uuid, legenda) {
    await db.fotos.update(uuid, { legenda: legenda || null });
    notificar();
}

/**
 * Remove uma foto ainda não enviada (ação do técnico, antes do envio). Nunca
 * chamar para uma foto em `enviando`: o chamador (`CapturaDeFoto.vue`) já
 * esconde a ação de remover nesse estado, para não apagar uma foto no meio de
 * uma requisição em andamento.
 */
export async function removerFotoLocal(uuid) {
    await db.fotos.delete(uuid);
    notificar();
}

/**
 * Devolve uma foto `falha` para `pendente`, com a contagem de tentativas
 * zerada - decisão manual e explícita do técnico, mesmo espírito do botão
 * "tentar de novo" da fila de operações (Task 12.10).
 */
export async function tentarNovamente(uuid) {
    await db.fotos.update(uuid, {
        situacao: 'pendente',
        tentativas: 0,
        ultimo_erro: null,
        proxima_tentativa_em: null,
    });
    notificar();
}

// -----------------------------------------------------------------------
// Envio (Task 12.11): uma foto de cada vez, em requisição própria
// -----------------------------------------------------------------------

async function obterToken() {
    return obterMeta(CHAVE_DO_TOKEN);
}

async function obterProximaFotoPronta() {
    const candidatas = await db.fotos.where('situacao').equals('pendente').toArray();
    const agora = Date.now();

    const prontas = candidatas.filter((foto) => {
        if (!foto.proxima_tentativa_em) {
            return true;
        }

        return new Date(foto.proxima_tentativa_em).getTime() <= agora;
    });

    if (!prontas.length) {
        return null;
    }

    return prontas.sort((a, b) => (a.criada_em < b.criada_em ? -1 : a.criada_em > b.criada_em ? 1 : 0))[0];
}

async function marcarComoEnviando(uuid) {
    await db.fotos.update(uuid, { situacao: 'enviando' });
    notificar();
}

async function reverterParaPendente(uuid) {
    await db.fotos.update(uuid, { situacao: 'pendente' });
    notificar();
}

/**
 * Falha transitória (5xx, 429 ou falha de rede/transporte): agenda a próxima
 * tentativa com a mesma espera crescente do lote de operações
 * (`calcularEsperaMs`/`atingiuTetoDeTentativas`, importadas de
 * `sincronizador.js` - funções puras, reaproveitadas aqui em vez de duplicar o
 * mesmo cálculo de backoff para uma fila irmã).
 */
async function registrarFalhaTemporaria(foto, mensagemDeErro) {
    const tentativas = (foto.tentativas || 0) + 1;

    if (atingiuTetoDeTentativas(tentativas)) {
        await db.fotos.update(foto.uuid, {
            situacao: 'falha',
            tentativas,
            ultimo_erro: mensagemDeErro,
            proxima_tentativa_em: null,
        });
    } else {
        await db.fotos.update(foto.uuid, {
            situacao: 'pendente',
            tentativas,
            ultimo_erro: mensagemDeErro,
            proxima_tentativa_em: new Date(Date.now() + calcularEsperaMs(tentativas)).toISOString(),
        });
    }

    notificar();
}

/**
 * Falha definitiva por decisão do servidor (`conflito` ou `recusada`): fica
 * visível em `falha`, sem nova tentativa automática. Não interrompe a fila de
 * fotos - a próxima foto pendente segue sendo tentada normalmente, porque uma
 * falha nesta não pode bloquear as demais.
 */
async function marcarComoFalhaDefinitiva(foto, mensagemDeErro) {
    await db.fotos.update(foto.uuid, {
        situacao: 'falha',
        tentativas: (foto.tentativas || 0) + 1,
        ultimo_erro: mensagemDeErro,
        proxima_tentativa_em: null,
    });
    notificar();
}

async function extrairMensagemDeErro(resposta) {
    try {
        const corpo = await resposta.json();

        return corpo?.mensagem || corpo?.message || corpo?.motivo || `Erro HTTP ${resposta.status}.`;
    } catch {
        return `Erro HTTP ${resposta.status}.`;
    }
}

function paraFormData(foto) {
    const formData = new FormData();

    formData.append('uuid', foto.uuid);
    formData.append('work_order_id', String(foto.work_order_id));
    formData.append('foto', foto.blob, 'foto.jpg');
    formData.append('entity_type', foto.contexto?.entity_type ?? '');

    if (foto.contexto?.entity_id !== null && foto.contexto?.entity_id !== undefined) {
        formData.append('entity_id', String(foto.contexto.entity_id));
    }

    if (foto.contexto?.room_id !== null && foto.contexto?.room_id !== undefined) {
        formData.append('room_id', String(foto.contexto.room_id));
    }

    if (foto.legenda) {
        formData.append('legenda', foto.legenda);
    }

    if (foto.criada_em) {
        formData.append('registrada_em', foto.criada_em);
    }

    return formData;
}

let sessaoExpirada = false; // 401: para de tentar sozinho até o próximo login (ver `retomarAposLoginDeFotos`).

/**
 * Envia uma única foto e traduz a resposta em transição de situação.
 * Devolve `{ parar }`: `true` interrompe o ciclo (erro de transporte, 401,
 * 5xx ou 429 - situações em que insistir imediatamente com as demais fotos
 * só martelaria um servidor ou uma sessão já sabidamente fora do ar); `false`
 * segue para a próxima foto pendente, inclusive quando ESTA foto terminou em
 * `conflito`/`recusada` - uma falha de negócio numa foto não pode bloquear as
 * outras.
 */
async function enviarUmaFoto(foto, token) {
    await marcarComoEnviando(foto.uuid);

    let resposta;

    try {
        resposta = await fetch(URL_FOTOS, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
            // Sem `Content-Type` manual: o navegador define o boundary do
            // multipart sozinho a partir do `FormData`.
            body: paraFormData(foto),
        });
    } catch (erroDeRede) {
        await registrarFalhaTemporaria(foto, erroDeRede?.message || 'Falha de rede ao enviar a foto.');

        return { parar: true };
    }

    if (resposta.status === 401) {
        await reverterParaPendente(foto.uuid);
        sessaoExpirada = true;

        return { parar: true };
    }

    if (!resposta.ok) {
        const mensagem = await extrairMensagemDeErro(resposta);

        if (resposta.status === 429 || resposta.status >= 500) {
            await registrarFalhaTemporaria(foto, mensagem);
        } else {
            await marcarComoFalhaDefinitiva(foto, mensagem);
        }

        return { parar: true };
    }

    const corpo = await resposta.json().catch(() => null);
    const situacao = corpo?.situacao;

    if (situacao === 'aplicada' || situacao === 'duplicada') {
        // Confirmado pelo servidor: some da base local, mesma regra de ouro
        // da fila de operações (nada sai sem confirmação explícita).
        await removerFotoLocal(foto.uuid);

        return { parar: false };
    }

    // `conflito` ou `recusada`: decisão do servidor, não repete sozinho, mas
    // também não interrompe as demais fotos da fila.
    const mensagem = corpo?.mensagem || corpo?.motivo || 'O servidor recusou esta foto.';
    await marcarComoFalhaDefinitiva(foto, mensagem);

    return { parar: false };
}

let emAndamento = false;
let iniciado = false;

/**
 * Ciclo completo: envia, uma de cada vez, toda foto pendente pronta para
 * envio agora, até não sobrar nenhuma ou até uma foto pedir parada (erro de
 * transporte, sessão expirada). Nunca dois ciclos ao mesmo tempo.
 */
export async function enviarFotosPendentes() {
    if (emAndamento) return;
    if (typeof navigator !== 'undefined' && navigator.onLine === false) return;
    if (sessaoExpirada) return;

    emAndamento = true;

    try {
        // eslint-disable-next-line no-constant-condition
        while (true) {
            const foto = await obterProximaFotoPronta();

            if (!foto) {
                break;
            }

            const token = await obterToken();
            const resultado = await enviarUmaFoto(foto, token);

            if (resultado.parar) {
                break;
            }
        }
    } finally {
        emAndamento = false;
    }
}

/**
 * Liga os gatilhos automáticos do envio de fotos: evento `online`, intervalo
 * e uma primeira tentativa imediata - os mesmos gatilhos de conectividade do
 * sincronizador do lote (Task 12.8), só que operando sobre a fila própria de
 * fotos. Idempotente, mesmo padrão de `sincronizador.iniciar()`.
 */
export async function iniciarEnvioDeFotos() {
    if (iniciado) return;
    iniciado = true;

    // Varredura inicial: uma foto presa em `enviando` (aplicativo fechado no
    // meio do envio) volta a `pendente` antes de qualquer envio novo.
    await db.fotos.where('situacao').equals('enviando').modify({ situacao: 'pendente' });

    if (typeof window !== 'undefined') {
        window.addEventListener('online', () => {
            enviarFotosPendentes();
        });
    }

    setInterval(() => {
        enviarFotosPendentes();
    }, 2 * 60 * 1000);

    enviarFotosPendentes();
}

/**
 * Ponto de integração para o fluxo de login (fora do escopo desta task, ver o
 * mesmo comentário em `sincronizador.js`): destrava o envio automático de
 * fotos depois de uma nova autenticação.
 */
export function retomarAposLoginDeFotos() {
    sessaoExpirada = false;
    enviarFotosPendentes();
}
