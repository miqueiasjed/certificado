// Comparação da versão com que a página foi carregada com a versão publicada,
// para o aviso de atualização. A versão carregada vem da meta `versao-build`
// do HTML. Em desenvolvimento a meta vem vazia, então nada recarrega.

export const CHAVE_RECARGA = 'certificados.versao.recarregada';

// Lê a versão da meta `versao-build`. Ausente ou vazia vira null.
export function versaoCarregada(doc = document) {
    const conteudo = doc.querySelector('meta[name="versao-build"]')?.getAttribute('content')?.trim();

    return conteudo ? conteudo : null;
}

// Versão que já motivou uma recarga nesta aba. Modo privado pode lançar.
function ultimaRecarregada() {
    try {
        return window.sessionStorage.getItem(CHAVE_RECARGA);
    } catch {
        return null;
    }
}

// Só recarrega quando as duas versões existem, diferem e essa versão ainda não
// motivou recarga nesta aba. A trava evita laço se o cache devolver a meta antiga.
export function deveRecarregar(carregada, atual) {
    if (!carregada || !atual || carregada === atual) {
        return false;
    }

    return ultimaRecarregada() !== atual;
}

// Grava a trava e recarrega. Sem sessionStorage, apenas recarrega.
export function recarregarParaVersao(atual) {
    try {
        window.sessionStorage.setItem(CHAVE_RECARGA, atual);
    } catch {
        // Sem storage a trava não existe; segue para a recarga.
    }

    window.location.reload();
}
