// Vocabulário compartilhado das telas de EPI (Plano 28, Task 28.6).
//
// Os rótulos são os mesmos do `FichaDeEpiService`, que monta a ficha em PDF e a
// extração por período: tela e documento precisam falar igual, senão o usuário
// confere um "Respirador / Substituição" na tela e recebe outro texto no
// arquivo que entrega ao fiscal.
//
// Nenhuma data passa por `new Date(...)` de string nem por `toLocaleDateString`:
// a comparação de dia é do `diasAte`, que compara dia contra dia no fuso do
// negócio.

import { diasAte } from '@/utils/formatDate';

export const ROTULOS_DE_TIPO_DE_EPI = {
    respirador: 'Respirador',
    luva: 'Luva',
    oculos: 'Óculos',
    protetor_auricular: 'Protetor auricular',
    calcado: 'Calçado',
    vestimenta: 'Vestimenta',
    outro: 'Outro',
};

export const ROTULOS_DE_MOTIVO_DE_ENTREGA = {
    primeira_entrega: 'Primeira entrega',
    substituicao: 'Substituição',
    dano: 'Dano',
    perda: 'Perda',
    vencimento: 'Vencimento',
    outro: 'Outro',
};

// Mesma janela do `AlertaDeEpiService::JANELAS_DE_CA`, cujo aviso mais distante
// sai a 60 dias do vencimento. A tela avisa junto com o e-mail, e não antes nem
// depois dele.
export const DIAS_DE_AVISO_DO_CA = 60;

export const TEXTO_SEM_TROCA_PROGRAMADA = 'Sem troca programada';
export const TEXTO_PENDENTE_DE_ASSINATURA = 'Pendente de assinatura';
export const TEXTO_NAO_INFORMADO = 'Não informado';

export function rotuloDeTipoDeEpi(tipo) {
    return ROTULOS_DE_TIPO_DE_EPI[tipo] || 'Outro';
}

export function rotuloDeMotivoDeEntrega(motivo) {
    return ROTULOS_DE_MOTIVO_DE_ENTREGA[motivo] || 'Outro';
}

/**
 * Situação do Certificado de Aprovação, para o indicador visual.
 *
 * `nao_informado` é estado NEUTRO, em cinza, nunca vermelho: campo em branco não
 * é irregularidade, e acusar de irregular quem apenas não preencheu destrói a
 * confiança na tela inteira (a lição registrada no checklist do Plano 24). Só
 * quem tem validade cadastrada e já passou dela é que aparece em vermelho.
 *
 * Devolve `{ chave, rotulo, cor, dias }`, com `dias` nulo quando não há validade
 * cadastrada.
 */
export function situacaoDoCa(epi) {
    const numero = epi?.numero_ca;
    const validade = epi?.validade_ca;

    if (!validade) {
        return {
            chave: 'nao_informado',
            rotulo: numero ? 'Validade não informada' : 'CA não informado',
            cor: 'bg-gray-100 text-gray-800',
            dias: null,
        };
    }

    const dias = diasAte(validade);

    if (dias === null) {
        return {
            chave: 'nao_informado',
            rotulo: 'CA não informado',
            cor: 'bg-gray-100 text-gray-800',
            dias: null,
        };
    }

    if (dias < 0) {
        return {
            chave: 'vencido',
            rotulo: 'CA vencido',
            cor: 'bg-red-100 text-red-800',
            dias,
        };
    }

    if (dias <= DIAS_DE_AVISO_DO_CA) {
        return {
            chave: 'a_vencer',
            rotulo: dias === 0 ? 'CA vence hoje' : `CA vence em ${dias} dia(s)`,
            cor: 'bg-yellow-100 text-yellow-800',
            dias,
        };
    }

    return {
        chave: 'em_dia',
        rotulo: 'CA em dia',
        cor: 'bg-green-100 text-green-800',
        dias,
    };
}

/**
 * O EPI está com o CA vencido e por isso não pode ser entregue hoje.
 *
 * Sem validade cadastrada devolve `false`, de propósito: é a mesma regra do
 * `PpeDeliveryService`, que só recusa a entrega quando existe uma validade e ela
 * já passou.
 */
export function caVencido(epi) {
    return situacaoDoCa(epi).chave === 'vencido';
}

/**
 * Situação da troca programada do item que o técnico tem na mão.
 *
 * `trocar_ate` nulo é "sem troca programada", em cinza — não é pendência. Vida
 * útil ausente no cadastro é escolha legítima, e um prazo inventado viraria
 * alerta falso.
 */
export function situacaoDaTroca(entrega) {
    if (!entrega?.trocar_ate) {
        return {
            chave: 'sem_troca',
            rotulo: TEXTO_SEM_TROCA_PROGRAMADA,
            cor: 'bg-gray-100 text-gray-800',
            dias: null,
        };
    }

    const dias = diasAte(entrega.trocar_ate);

    if (dias === null) {
        return {
            chave: 'sem_troca',
            rotulo: TEXTO_SEM_TROCA_PROGRAMADA,
            cor: 'bg-gray-100 text-gray-800',
            dias: null,
        };
    }

    if (dias < 0) {
        return {
            chave: 'vencida',
            rotulo: 'Troca vencida',
            cor: 'bg-red-100 text-red-800',
            dias,
        };
    }

    if (dias <= 30) {
        return {
            chave: 'a_vencer',
            rotulo: dias === 0 ? 'Trocar hoje' : `Trocar em ${dias} dia(s)`,
            cor: 'bg-yellow-100 text-yellow-800',
            dias,
        };
    }

    return {
        chave: 'em_dia',
        rotulo: 'Dentro do prazo',
        cor: 'bg-green-100 text-green-800',
        dias,
    };
}

/**
 * Situação da linha da ficha.
 *
 * A entrega estornada NUNCA some da lista: ela aparece marcada, com o motivo. A
 * sequência "a errada, estornada com motivo, e a certa" é o que prova que a
 * correção foi correção, e não reescrita.
 */
export function situacaoDaEntrega(entrega) {
    if (entrega?.estornada_em) {
        return { chave: 'estornada', rotulo: 'Estornada', cor: 'bg-red-100 text-red-800' };
    }

    if (entrega?.devolvido_em) {
        return { chave: 'devolvida', rotulo: 'Devolvida', cor: 'bg-gray-100 text-gray-800' };
    }

    return { chave: 'em_uso', rotulo: 'Em uso', cor: 'bg-green-100 text-green-800' };
}

/**
 * Entrega válida (não estornada) e ainda sem assinatura de recebimento.
 *
 * A assinatura é a prova exigida pela NR-6: sem ela a entrega é pendência, e a
 * pendência precisa ficar visível na tela, com caminho direto para assinar.
 */
export function pendenteDeAssinatura(entrega) {
    return Boolean(entrega) && !entrega.estornada_em && !entrega.assinado_em;
}

/**
 * Dia somado a uma quantidade de dias, no formato yyyy-MM-dd.
 *
 * Aritmética em `Date.UTC` sobre as partes do calendário, nunca `new Date` de
 * string: a string "2026-08-12" seria lida como meia-noite UTC e voltaria um dia
 * em Brasília. Serve só para a PRÉVIA da troca programada no modal de entrega —
 * quem grava `trocar_ate` é o `PpeDeliveryService`, com a mesma conta.
 */
export function somarDias(dia, quantidade) {
    const partes = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(dia || '').trim());

    if (!partes || !Number.isFinite(Number(quantidade))) {
        return '';
    }

    const [, ano, mes, dias] = partes;
    const instante = new Date(Date.UTC(Number(ano), Number(mes) - 1, Number(dias)));

    instante.setUTCDate(instante.getUTCDate() + Number(quantidade));

    const preencher = (valor, tamanho) => String(valor).padStart(tamanho, '0');

    return [
        preencher(instante.getUTCFullYear(), 4),
        preencher(instante.getUTCMonth() + 1, 2),
        preencher(instante.getUTCDate(), 2),
    ].join('-');
}
