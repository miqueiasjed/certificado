<!--
    Tela de login do aplicativo do técnico (Plano 12, Task 12.9).

    E-mail, senha e nome do aparelho (auto-preenchido, editável). Chama
    `POST /api/app/login` (Task 12.2) e guarda o token no IndexedDB via
    `definirMeta('token', ...)` - nunca em `localStorage`, para viver na mesma
    base transacional do resto do aplicativo.

    As mensagens de credencial inválida, conta desativada e conta sem técnico
    vinculado já vêm distintas do backend (`AppSessionService::autenticar()`);
    esta tela só precisa exibir `message` como veio, sem reinterpretar status
    HTTP. Sem rede é tratado à parte, antes mesmo de chamar o servidor: não é
    erro, é o primeiro acesso que exige conexão.
-->
<template>
    <div class="min-h-screen flex flex-col items-center justify-center bg-gray-50 px-6 py-10">
        <img src="/images/logo.png" alt="Sistema de Certificados" class="h-14 w-auto mb-6" />

        <div class="w-full max-w-sm bg-white rounded-lg border border-gray-200 shadow-sm p-6">
            <h1 class="text-lg font-semibold text-gray-900 mb-1">Entrar</h1>
            <p class="text-sm text-gray-600 mb-6">Aplicativo do técnico de campo.</p>

            <div v-if="avisoDeConexao" class="mb-4 rounded-md bg-blue-50 border border-blue-200 p-3">
                <p class="text-sm text-blue-800">{{ avisoDeConexao }}</p>
            </div>

            <div v-if="erro" class="mb-4 rounded-md bg-red-50 border border-red-200 p-3">
                <p class="text-sm text-red-800">{{ erro }}</p>
            </div>

            <form class="space-y-4" @submit.prevent="entrar">
                <div>
                    <label for="email" class="block text-sm font-medium text-gray-700 mb-1">E-mail</label>
                    <input
                        id="email"
                        v-model="email"
                        type="email"
                        autocomplete="username"
                        required
                        class="w-full min-h-[44px] px-3 py-2 border border-gray-300 rounded-md shadow-sm text-base text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                    />
                </div>

                <div>
                    <label for="senha" class="block text-sm font-medium text-gray-700 mb-1">Senha</label>
                    <input
                        id="senha"
                        v-model="senha"
                        type="password"
                        autocomplete="current-password"
                        required
                        class="w-full min-h-[44px] px-3 py-2 border border-gray-300 rounded-md shadow-sm text-base text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                    />
                </div>

                <div>
                    <label for="dispositivo" class="block text-sm font-medium text-gray-700 mb-1">
                        Nome do aparelho
                    </label>
                    <input
                        id="dispositivo"
                        v-model="dispositivo"
                        type="text"
                        required
                        maxlength="255"
                        class="w-full min-h-[44px] px-3 py-2 border border-gray-300 rounded-md shadow-sm text-base text-gray-900 focus:outline-none focus:ring-2 focus:ring-green-500 focus:border-green-500"
                    />
                    <p class="mt-1 text-xs text-gray-500">
                        Ajuda a empresa a reconhecer este aparelho na lista de sessões.
                    </p>
                </div>

                <button
                    type="submit"
                    class="btn-primary w-full min-h-[44px] text-base"
                    :disabled="entrando"
                >
                    {{ entrando ? 'Entrando...' : 'Entrar' }}
                </button>
            </form>
        </div>
    </div>
</template>

<script setup>
import { ref } from 'vue';
import { definirMeta } from '../db/repositorio';
import { iniciar, retomarAposLogin } from '../sync/sincronizador';

const emit = defineEmits(['autenticado']);

const email = ref('');
const senha = ref('');
const dispositivo = ref(sugerirNomeDoAparelho());
const erro = ref(null);
const avisoDeConexao = ref(null);
const entrando = ref(false);

/**
 * Nome de aparelho razoável a partir de `navigator.userAgent`, só para
 * pré-preencher o campo - o técnico pode editar antes de entrar. Sem
 * ambição de detectar modelo exato de aparelho, só o suficiente para a
 * empresa reconhecer a sessão na lista (`GET /api/app/sessoes`).
 */
function sugerirNomeDoAparelho() {
    if (typeof navigator === 'undefined' || !navigator.userAgent) {
        return 'Aparelho do técnico';
    }

    const agente = navigator.userAgent;

    let plataforma = 'Aparelho';
    if (/android/i.test(agente)) plataforma = 'Android';
    else if (/iphone/i.test(agente)) plataforma = 'iPhone';
    else if (/ipad/i.test(agente)) plataforma = 'iPad';
    else if (/windows/i.test(agente)) plataforma = 'Windows';
    else if (/macintosh|mac os/i.test(agente)) plataforma = 'Mac';
    else if (/linux/i.test(agente)) plataforma = 'Linux';

    let navegador = '';
    if (/edg\//i.test(agente)) navegador = 'Edge';
    else if (/crios\//i.test(agente)) navegador = 'Chrome';
    else if (/chrome\//i.test(agente)) navegador = 'Chrome';
    else if (/firefox\//i.test(agente)) navegador = 'Firefox';
    else if (/safari\//i.test(agente)) navegador = 'Safari';

    return navegador ? `${plataforma} - ${navegador}` : plataforma;
}

async function extrairMensagemDeErro(resposta) {
    const corpo = await resposta.json().catch(() => null);

    if (corpo?.message) {
        return corpo.message;
    }

    const primeiraMensagemDeValidacao = corpo?.errors ? Object.values(corpo.errors)[0]?.[0] : null;

    return primeiraMensagemDeValidacao || `Não foi possível entrar agora (HTTP ${resposta.status}).`;
}

async function entrar() {
    erro.value = null;
    avisoDeConexao.value = null;

    // Sem token local ainda (primeiro acesso deste aparelho): sem rede não há
    // como abrir logado, e isso não é um erro, é a condição normal descrita
    // na Task 12.9. Verificado antes de qualquer chamada ao servidor.
    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
        avisoDeConexao.value =
            'O primeiro acesso ao aplicativo exige conexão com a internet. Conecte-se e tente novamente.';
        return;
    }

    entrando.value = true;

    try {
        const resposta = await fetch('/api/app/login', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
            },
            body: JSON.stringify({
                email: email.value,
                password: senha.value,
                dispositivo: dispositivo.value,
            }),
        });

        if (!resposta.ok) {
            erro.value = await extrairMensagemDeErro(resposta);
            return;
        }

        const corpo = await resposta.json();

        await definirMeta('token', corpo.token);
        await definirMeta('usuario_logado', {
            usuario: corpo.usuario,
            tecnico: corpo.tecnico,
            empresa: corpo.empresa,
        });

        // Liga os gatilhos automáticos do sincronizador (idempotente - se
        // `AppLayout` já os tiver ligado via `useSincronizacao()`, esta
        // chamada não duplica nada) e garante uma tentativa imediata mesmo
        // que a sessão anterior tivesse ficado marcada como expirada.
        iniciar();
        retomarAposLogin();

        emit('autenticado');
    } catch (erroDeRede) {
        avisoDeConexao.value = 'Não foi possível conectar ao servidor. Verifique sua internet e tente novamente.';
    } finally {
        entrando.value = false;
    }
}
</script>
