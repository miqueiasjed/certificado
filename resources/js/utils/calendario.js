// Aritmética de grade de calendário, sem dependência externa e sem `Date` para os
// cálculos de dia. `Date` não entra aqui pelo mesmo motivo do resto do projeto: string
// de data sem hora ("2026-07-25") é interpretada em UTC e desloca o dia em fuso
// negativo. Em vez de instanciar `Date`, os dias viram um número inteiro (dias desde
// 1970-01-01) com o algoritmo de calendário civil de Howard Hinnant, domínio público,
// correto para todo o calendário gregoriano proléptico, incluindo anos bissextos.
//
// Formatação de data/hora para exibição continua vindo de `resources/js/utils/formatDate.js`
// (`formatarData`, `formatarHora`, `hojeISO`...). Este arquivo não formata nada para o
// usuário: só resolve a matriz de dias, a semana e as faixas de hora da grade.

export const NOMES_DIAS_SEMANA = [
    'Segunda-feira',
    'Terça-feira',
    'Quarta-feira',
    'Quinta-feira',
    'Sexta-feira',
    'Sábado',
    'Domingo',
];

export const NOMES_DIAS_SEMANA_ABREVIADO = ['Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb', 'Dom'];

export const NOMES_MESES = [
    'Janeiro',
    'Fevereiro',
    'Março',
    'Abril',
    'Maio',
    'Junho',
    'Julho',
    'Agosto',
    'Setembro',
    'Outubro',
    'Novembro',
    'Dezembro',
];

const FORMATO_ISO = /^(\d{4})-(\d{2})-(\d{2})$/;

function preencher(valor, tamanho) {
    return String(valor).padStart(tamanho, '0');
}

/**
 * Quebra "yyyy-MM-dd" em números. Lança erro para entrada fora do formato: os
 * chamadores deste módulo sempre trabalham com datas já validadas pelo backend ou
 * geradas por este próprio arquivo.
 */
export function partesDeIso(dataIso) {
    const encontrado = FORMATO_ISO.exec(String(dataIso || '').trim());

    if (!encontrado) {
        throw new Error(`calendario.js: data fora do formato yyyy-MM-dd: "${dataIso}"`);
    }

    const [, ano, mes, dia] = encontrado;

    return { ano: Number(ano), mes: Number(mes), dia: Number(dia) };
}

/**
 * Monta "yyyy-MM-dd" a partir das partes numéricas.
 */
export function formatarIso(ano, mes, dia) {
    return `${preencher(ano, 4)}-${preencher(mes, 2)}-${preencher(dia, 2)}`;
}

function diasNoMes(ano, mes) {
    const tamanhos = [31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

    if (mes === 2) {
        const bissexto = (ano % 4 === 0 && ano % 100 !== 0) || ano % 400 === 0;
        return bissexto ? 29 : 28;
    }

    return tamanhos[mes - 1];
}

// --- Algoritmo de Howard Hinnant (days_from_civil / civil_from_days) ---------------
// Converte data civil <-> número de dias desde 1970-01-01, com aritmética inteira pura.
// Referência: http://howardhinnant.github.io/date_algorithms.html (domínio público).

function paraNumeroDeDias(ano, mes, dia) {
    const y = mes <= 2 ? ano - 1 : ano;
    const era = Math.floor((y >= 0 ? y : y - 399) / 400);
    const yoe = y - era * 400; // [0, 399]
    const doy = Math.floor((153 * (mes + (mes > 2 ? -3 : 9)) + 2) / 5) + dia - 1; // [0, 365]
    const doe = yoe * 365 + Math.floor(yoe / 4) - Math.floor(yoe / 100) + doy; // [0, 146096]

    return era * 146097 + doe - 719468;
}

function deNumeroDeDias(numeroDeDias) {
    const z = numeroDeDias + 719468;
    const era = Math.floor((z >= 0 ? z : z - 146096) / 146097);
    const doe = z - era * 146097; // [0, 146096]
    const yoe = Math.floor(
        (doe - Math.floor(doe / 1460) + Math.floor(doe / 36524) - Math.floor(doe / 146096)) / 365
    ); // [0, 399]
    const y = yoe + era * 400;
    const doy = doe - (365 * yoe + Math.floor(yoe / 4) - Math.floor(yoe / 100)); // [0, 365]
    const mp = Math.floor((5 * doy + 2) / 153); // [0, 11]
    const dia = doy - Math.floor((153 * mp + 2) / 5) + 1; // [1, 31]
    const mes = mp + (mp < 10 ? 3 : -9); // [1, 12]
    const ano = y + (mes <= 2 ? 1 : 0);

    return { ano, mes, dia };
}

/**
 * Dia da semana com segunda-feira como índice 0 (a operação de campo conta a
 * semana assim, não a partir de domingo). 1970-01-01 (dia 0 do epoch) é uma
 * quinta-feira, índice 3, e é a âncora usada abaixo.
 */
export function diaDaSemanaSegunda(ano, mes, dia) {
    const numeroDeDias = paraNumeroDeDias(ano, mes, dia);

    return ((numeroDeDias % 7) + 7 + 3) % 7;
}

/**
 * Soma (ou subtrai, com número negativo) dias a uma data "yyyy-MM-dd", devolvendo
 * outra data no mesmo formato.
 */
export function somarDias(dataIso, dias) {
    const { ano, mes, dia } = partesDeIso(dataIso);
    const resultado = deNumeroDeDias(paraNumeroDeDias(ano, mes, dia) + dias);

    return formatarIso(resultado.ano, resultado.mes, resultado.dia);
}

/**
 * Soma (ou subtrai) meses a uma data "yyyy-MM-dd". O dia é preservado quando o mês de
 * destino comporta esse dia, e "clampado" para o último dia do mês de destino quando
 * não comporta (ex.: 31 de janeiro + 1 mês vira o último dia de fevereiro).
 */
export function somarMeses(dataIso, meses) {
    const { ano, mes, dia } = partesDeIso(dataIso);

    const totalMeses = (mes - 1) + meses;
    const novoAno = ano + Math.floor(totalMeses / 12);
    const novoMes = ((totalMeses % 12) + 12) % 12 + 1;
    const novoDia = Math.min(dia, diasNoMes(novoAno, novoMes));

    return formatarIso(novoAno, novoMes, novoDia);
}

export function nomeDoMes(mes) {
    return NOMES_MESES[mes - 1] ?? '';
}

/**
 * Matriz de semanas (array de arrays de 7 dias) cobrindo o mês inteiro, incluindo os
 * dias do mês anterior e seguinte que completam a primeira e a última semana. Semana
 * começa na segunda-feira. Cada dia é `{ data, dia, mesAtual, diaSemana }`, onde
 * `mesAtual` diz se o dia pertence ao mês pedido (falso para os dias de
 * preenchimento) e `diaSemana` é o índice 0 (segunda) a 6 (domingo) dentro da semana.
 */
export function gradeDoMes(ano, mes) {
    const indicePrimeiroDia = diaDaSemanaSegunda(ano, mes, 1);
    const numeroDaPrimeiraCelula = paraNumeroDeDias(ano, mes, 1) - indicePrimeiroDia;
    const totalCelulas = Math.ceil((indicePrimeiroDia + diasNoMes(ano, mes)) / 7) * 7;

    const dias = [];

    for (let i = 0; i < totalCelulas; i += 1) {
        const partes = deNumeroDeDias(numeroDaPrimeiraCelula + i);

        dias.push({
            data: formatarIso(partes.ano, partes.mes, partes.dia),
            dia: partes.dia,
            mesAtual: partes.ano === ano && partes.mes === mes,
            diaSemana: i % 7,
        });
    }

    const semanas = [];

    for (let i = 0; i < dias.length; i += 7) {
        semanas.push(dias.slice(i, i + 7));
    }

    return semanas;
}

/**
 * Os 7 dias da semana (segunda a domingo) que contém a data informada
 * ("yyyy-MM-dd"). Cada dia é `{ data, dia, diaSemana }`.
 */
export function diasDaSemana(data) {
    const { ano, mes, dia } = partesDeIso(data);
    const indice = diaDaSemanaSegunda(ano, mes, dia);
    const numeroDaSegunda = paraNumeroDeDias(ano, mes, dia) - indice;

    const dias = [];

    for (let i = 0; i < 7; i += 1) {
        const partes = deNumeroDeDias(numeroDaSegunda + i);

        dias.push({
            data: formatarIso(partes.ano, partes.mes, partes.dia),
            dia: partes.dia,
            diaSemana: i,
        });
    }

    return dias;
}

/**
 * Faixas de hora cheias (em ponto) usadas nas visões de dia e semana, de `inicio` a
 * `fim` (inclusive). Cada faixa é `{ hora, label }`, com `label` em "HH:00".
 */
export function faixasDeHora(inicio = 7, fim = 19) {
    const faixas = [];

    for (let hora = inicio; hora <= fim; hora += 1) {
        faixas.push({ hora, label: `${preencher(hora, 2)}:00` });
    }

    return faixas;
}

export default {
    NOMES_DIAS_SEMANA,
    NOMES_DIAS_SEMANA_ABREVIADO,
    NOMES_MESES,
    partesDeIso,
    formatarIso,
    diaDaSemanaSegunda,
    somarDias,
    somarMeses,
    nomeDoMes,
    gradeDoMes,
    diasDaSemana,
    faixasDeHora,
};
