// Chamadas do aviso de atualização. `fetch` direto, como o botão de relatar
// problema: o pop-up abre por cima de qualquer tela e não pode disparar uma
// visita da Inertia.

function cabecalhos() {
    return {
        'Content-Type': 'application/json',
        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.getAttribute('content'),
        'X-Requested-With': 'XMLHttpRequest',
        Accept: 'application/json',
    };
}

// `{avisos: [{id, publicado_em, titulo, itens}], versao}`. Lança em qualquer erro.
export async function buscarPendentes() {
    const resposta = await fetch('/avisos-atualizacao/pendentes', {
        headers: cabecalhos(),
        credentials: 'same-origin',
    });

    if (!resposta.ok) {
        throw new Error(`HTTP ${resposta.status}`);
    }

    const corpo = await resposta.json();

    return corpo?.dados ?? { avisos: [], versao: null };
}

export async function marcarLidos(ids) {
    const resposta = await fetch('/avisos-atualizacao/lidos', {
        method: 'POST',
        headers: cabecalhos(),
        credentials: 'same-origin',
        body: JSON.stringify({ ids }),
    });

    if (!resposta.ok) {
        throw new Error(`HTTP ${resposta.status}`);
    }
}
