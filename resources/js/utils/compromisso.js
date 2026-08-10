// Vocabulário do compromisso avulso da Agenda (Plano 30, Task 30.5), no mesmo
// padrão de `rotuloDeTipoDeEpi` em `@/utils/epi.js`: um rotulador central, para a
// Agenda inteira (calendário, painel de detalhe, modal de criação) falar igual em
// vez de cada tela inventar o próprio texto para o mesmo valor de `tipo`.
//
// Os valores aceitos são os mesmos de `Compromisso::TIPOS` no backend
// (orcamento/garantia/retorno/outro); qualquer valor fora dessa lista cai em
// "Outro", para um tipo novo no backend não quebrar a tela.

export const ROTULOS_DE_TIPO_DE_COMPROMISSO = {
    orcamento: 'Orçamento',
    garantia: 'Garantia',
    retorno: 'Retorno',
    outro: 'Outro',
};

// Mesma ordem de `Compromisso::TIPOS` no backend, para o `<select>` do modal de
// criação (`ModalNovoCompromisso.vue`) nunca divergir do rótulo acima.
export const TIPOS_DE_COMPROMISSO = Object.keys(ROTULOS_DE_TIPO_DE_COMPROMISSO);

export function rotuloDeTipoDeCompromisso(tipo) {
    return ROTULOS_DE_TIPO_DE_COMPROMISSO[tipo] || 'Outro';
}
