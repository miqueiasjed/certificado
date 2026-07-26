// Data sem hora nunca passa por `new Date`: o JavaScript interpreta "2026-07-25" como
// meia-noite UTC e, em fuso negativo como o de Brasília, devolve o dia anterior.

const FUSO = 'America/Sao_Paulo';

const MESES = [
    'janeiro',
    'fevereiro',
    'março',
    'abril',
    'maio',
    'junho',
    'julho',
    'agosto',
    'setembro',
    'outubro',
    'novembro',
    'dezembro',
];

// "2026-07-25"
const SO_DATA = /^(\d{4})-(\d{2})-(\d{2})$/;

// "2026-07-25T14:30:00.000000Z", "2026-07-25 14:30:00", "2026-07-25T14:30-03:00"
const DATA_COM_HORA =
    /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d+)?)?\s*(Z|[+-]\d{2}:?\d{2})?$/i;

// "14:30" ou "14:30:00"
const SO_HORA = /^(\d{2}):(\d{2})(?::\d{2}(?:\.\d+)?)?$/;

// Valor de <input type="datetime-local">: "2026-07-25T14:30" ou "2026-07-25T14:30:00"
const INPUT_DATE_TIME = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::\d{2})?$/;

const MILISSEGUNDOS_POR_DIA = 24 * 60 * 60 * 1000;

let formatadorFuso = null;

function preencher(valor, tamanho) {
    return String(valor).padStart(tamanho, '0');
}

function diasNoMes(ano, mes) {
    const tamanhos = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    if (mes === 2) {
        const bissexto = (ano % 4 === 0 && ano % 100 !== 0) || ano % 400 === 0;
        return bissexto ? 29 : 28;
    }

    return tamanhos[mes - 1];
}

function diaValido(ano, mes, dia) {
    const numeroAno = Number(ano);
    const numeroMes = Number(mes);
    const numeroDia = Number(dia);

    if (!numeroAno || numeroMes < 1 || numeroMes > 12 || numeroDia < 1) {
        return false;
    }

    return numeroDia <= diasNoMes(numeroAno, numeroMes);
}

function ehUtc(deslocamento) {
    return deslocamento === 'Z' || deslocamento === 'z' || /^[+-]00:?00$/.test(deslocamento);
}

function normalizarDeslocamento(deslocamento) {
    if (ehUtc(deslocamento)) {
        return 'Z';
    }

    // Aceita tanto "-0300" quanto "-03:00".
    return deslocamento.length === 5
        ? `${deslocamento.slice(0, 3)}:${deslocamento.slice(3)}`
        : deslocamento;
}

// Quebra um instante nas partes de calendário já convertidas para America/Sao_Paulo.
function partesNoFuso(data) {
    try {
        if (!formatadorFuso) {
            formatadorFuso = new Intl.DateTimeFormat('en-US', {
                timeZone: FUSO,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit',
                hourCycle: 'h23',
            });
        }

        const encontradas = {};

        formatadorFuso.formatToParts(data).forEach((parte) => {
            encontradas[parte.type] = parte.value;
        });

        if (!encontradas.year || !encontradas.month || !encontradas.day) {
            return null;
        }

        // Alguns motores devolvem "24" para meia-noite mesmo com hourCycle h23.
        const hora = encontradas.hour === '24' ? '00' : encontradas.hour;

        return {
            ano: preencher(encontradas.year, 4),
            mes: preencher(encontradas.month, 2),
            dia: preencher(encontradas.day, 2),
            hora: preencher(hora || '0', 2),
            minuto: preencher(encontradas.minute || '0', 2),
        };
    } catch (erro) {
        // Ambiente sem base de fusos: usa UTC-3, o horário padrão de Brasília.
        const deslocada = new Date(data.getTime() - 3 * 60 * 60 * 1000);

        return {
            ano: preencher(deslocada.getUTCFullYear(), 4),
            mes: preencher(deslocada.getUTCMonth() + 1, 2),
            dia: preencher(deslocada.getUTCDate(), 2),
            hora: preencher(deslocada.getUTCHours(), 2),
            minuto: preencher(deslocada.getUTCMinutes(), 2),
        };
    }
}

// Deslocamento do fuso do negócio, em minutos, no instante informado. Devolve -180
// no horário padrão de Brasília. Serve para achar o instante a partir da hora de parede.
function deslocamentoDoFuso(instante) {
    const partes = partesNoFuso(instante);

    if (!partes) {
        return null;
    }

    const paredeComoUtc = Date.UTC(
        Number(partes.ano),
        Number(partes.mes) - 1,
        Number(partes.dia),
        Number(partes.hora),
        Number(partes.minuto),
    );

    // partesNoFuso não devolve segundos: compara no mesmo minuto.
    const instanteNoMinuto = Math.floor(instante.getTime() / 60000) * 60000;

    return (paredeComoUtc - instanteNoMinuto) / 60000;
}

function deInstante(data) {
    if (!(data instanceof Date) || Number.isNaN(data.getTime())) {
        return null;
    }

    return partesNoFuso(data);
}

// Devolve { ano, mes, dia, hora, minuto } prontos para exibição, ou null se a entrada
// não representar uma data. Nenhum caminho aqui lança exceção.
function analisar(valor) {
    if (valor === null || valor === undefined) {
        return null;
    }

    if (valor instanceof Date) {
        return deInstante(valor);
    }

    if (typeof valor === 'number') {
        return Number.isFinite(valor) ? deInstante(new Date(valor)) : null;
    }

    if (typeof valor !== 'string') {
        return null;
    }

    const texto = valor.trim();

    if (texto === '') {
        return null;
    }

    const soData = SO_DATA.exec(texto);

    if (soData) {
        const [, ano, mes, dia] = soData;

        return diaValido(ano, mes, dia) ? { ano, mes, dia, hora: '00', minuto: '00' } : null;
    }

    const comHora = DATA_COM_HORA.exec(texto);

    if (comHora) {
        const [, ano, mes, dia, hora, minuto, segundo, deslocamento] = comHora;

        if (!diaValido(ano, mes, dia) || Number(hora) > 23 || Number(minuto) > 59) {
            return null;
        }

        // Sem marcador de fuso o valor é hora de parede: exibe como veio, sem converter.
        if (!deslocamento) {
            return { ano, mes, dia, hora, minuto };
        }

        // O Laravel serializa campo `date` como meia-noite UTC. Nesse caso o valor
        // representa um dia, não um instante, e converter é o que rouba um dia.
        const meiaNoiteUtc =
            ehUtc(deslocamento) && hora === '00' && minuto === '00' && (!segundo || segundo === '00');

        if (meiaNoiteUtc) {
            return { ano, mes, dia, hora: '00', minuto: '00' };
        }

        const iso = `${ano}-${mes}-${dia}T${hora}:${minuto}:${segundo || '00'}${normalizarDeslocamento(deslocamento)}`;

        return deInstante(new Date(iso));
    }

    return null;
}

/**
 * Formata um dia como dd/MM/yyyy. Devolve '' para entrada inválida.
 */
export function formatarData(valor) {
    const partes = analisar(valor);

    return partes ? `${partes.dia}/${partes.mes}/${partes.ano}` : '';
}

/**
 * Formata um instante como dd/MM/yyyy HH:mm no fuso do negócio.
 */
export function formatarDataHora(valor) {
    const partes = analisar(valor);

    return partes
        ? `${partes.dia}/${partes.mes}/${partes.ano} ${partes.hora}:${partes.minuto}`
        : '';
}

/**
 * Formata um dia por extenso: 25 de julho de 2026.
 */
export function formatarDataExtensa(valor) {
    const partes = analisar(valor);

    if (!partes) {
        return '';
    }

    const mes = MESES[Number(partes.mes) - 1];

    return mes ? `${partes.dia} de ${mes} de ${partes.ano}` : '';
}

/**
 * Formata a hora como HH:mm. Aceita ISO com hora e também "14:30:00".
 */
export function formatarHora(valor) {
    if (typeof valor === 'string') {
        const soHora = SO_HORA.exec(valor.trim());

        if (soHora) {
            const [, hora, minuto] = soHora;

            return Number(hora) > 23 || Number(minuto) > 59 ? '' : `${hora}:${minuto}`;
        }
    }

    const partes = analisar(valor);

    return partes ? `${partes.hora}:${partes.minuto}` : '';
}

/**
 * Dias inteiros entre hoje e a data informada, comparando dia contra dia no fuso do
 * negócio. Devolve 0 para hoje, negativo para data passada e null para entrada inválida.
 */
export function diasAte(valor) {
    const alvo = analisar(valor);

    if (!alvo) {
        return null;
    }

    const hoje = partesNoFuso(new Date());

    if (!hoje) {
        return null;
    }

    const inicio = Date.UTC(Number(hoje.ano), Number(hoje.mes) - 1, Number(hoje.dia));
    const fim = Date.UTC(Number(alvo.ano), Number(alvo.mes) - 1, Number(alvo.dia));

    return Math.round((fim - inicio) / MILISSEGUNDOS_POR_DIA);
}

/**
 * Dia de hoje no fuso do negócio, no formato yyyy-MM-dd, pronto para
 * <input type="date">. Substitui a leitura do dia via `toISOString`, que das 21h à
 * meia-noite de Brasília já devolve o dia seguinte.
 */
export function hojeISO() {
    const partes = partesNoFuso(new Date());

    return partes ? `${partes.ano}-${partes.mes}-${partes.dia}` : '';
}

/**
 * Instante atual no fuso do negócio, no formato yyyy-MM-ddTHH:mm, pronto para
 * <input type="datetime-local">. Substitui a leitura da hora via `toISOString`, que
 * devolve a hora de UTC, três horas à frente de Brasília.
 */
export function agoraInputDateTime() {
    const partes = partesNoFuso(new Date());

    return partes
        ? `${partes.ano}-${partes.mes}-${partes.dia}T${partes.hora}:${partes.minuto}`
        : '';
}

/**
 * Converte um instante gravado (ISO com marcador de fuso) no valor de
 * <input type="datetime-local"> em hora de Brasília. Sem marcador de fuso o valor já
 * é hora de parede e volta como veio. Devolve '' para entrada inválida.
 */
export function paraInputDateTime(valor) {
    if (valor instanceof Date) {
        const partes = deInstante(valor);

        return partes
            ? `${partes.ano}-${partes.mes}-${partes.dia}T${partes.hora}:${partes.minuto}`
            : '';
    }

    if (typeof valor !== 'string') {
        return '';
    }

    const comHora = DATA_COM_HORA.exec(valor.trim());

    if (!comHora) {
        return '';
    }

    const [, ano, mes, dia, hora, minuto, segundo, deslocamento] = comHora;

    if (!diaValido(ano, mes, dia) || Number(hora) > 23 || Number(minuto) > 59) {
        return '';
    }

    if (!deslocamento) {
        return `${ano}-${mes}-${dia}T${hora}:${minuto}`;
    }

    const iso = `${ano}-${mes}-${dia}T${hora}:${minuto}:${segundo || '00'}${normalizarDeslocamento(deslocamento)}`;
    const partes = deInstante(new Date(iso));

    return partes
        ? `${partes.ano}-${partes.mes}-${partes.dia}T${partes.hora}:${partes.minuto}`
        : '';
}

/**
 * Caminho inverso de paraInputDateTime: recebe o que o usuário digitou no campo
 * datetime-local, em hora de Brasília, e devolve a mesma hora de parede em UTC, que é
 * como o backend grava o campo. Sem isso, gravar o valor digitado atrasa o registro
 * em três horas. Devolve '' para entrada inválida.
 */
export function inputDateTimeParaUtc(valor) {
    if (typeof valor !== 'string') {
        return '';
    }

    const encontrado = INPUT_DATE_TIME.exec(valor.trim());

    if (!encontrado) {
        return '';
    }

    const [, ano, mes, dia, hora, minuto] = encontrado;

    if (!diaValido(ano, mes, dia) || Number(hora) > 23 || Number(minuto) > 59) {
        return '';
    }

    const desejado = Date.UTC(
        Number(ano),
        Number(mes) - 1,
        Number(dia),
        Number(hora),
        Number(minuto),
    );

    const deslocamento = deslocamentoDoFuso(new Date(desejado));

    if (deslocamento === null) {
        return '';
    }

    let instante = new Date(desejado - deslocamento * 60000);

    // Uma segunda passada resolve a virada de horário de verão, se um dia voltar.
    const conferencia = deslocamentoDoFuso(instante);

    if (conferencia !== null && conferencia !== deslocamento) {
        instante = new Date(desejado - conferencia * 60000);
    }

    return [
        `${preencher(instante.getUTCFullYear(), 4)}`,
        `-${preencher(instante.getUTCMonth() + 1, 2)}`,
        `-${preencher(instante.getUTCDate(), 2)}`,
        `T${preencher(instante.getUTCHours(), 2)}`,
        `:${preencher(instante.getUTCMinutes(), 2)}`,
    ].join('');
}

export default {
    formatarData,
    formatarDataHora,
    formatarDataExtensa,
    formatarHora,
    diasAte,
    hojeISO,
    agoraInputDateTime,
    paraInputDateTime,
    inputDateTimeParaUtc,
};
