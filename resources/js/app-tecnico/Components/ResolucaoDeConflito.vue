<!--
    Resolução de conflito de sincronização (Plano 12, Task 12.10).

    Mostra lado a lado (empilhado, não em colunas: a tela é um celular
    estreito, e duas colunas de texto aqui ficariam ilegíveis) o que o técnico
    registrou (`valor_do_aplicativo`) e o que está no servidor
    (`valor_do_servidor`), cada um com a própria data e hora, e o motivo do
    conflito em português (`.claude/tasks/12/12.4.md`).

    `registrada_em` (quando o técnico registrou) só existe na cópia local da
    fila (`sync/fila.js`) - o servidor nunca guarda isso em
    `valor_do_aplicativo` (`AppSyncService::valorDoAplicativo()`). Por isso
    quem monta este componente (`Pendencias.vue`) mescla o registro local com
    a resposta de `GET /api/app/conflitos` antes de passar a prop `conflito`.

    Duas ações possíveis, nenhuma em destaque (a decisão é do técnico, não do
    sistema): "Manter o meu registro" (`escolha: 'aplicativo'`) e "Manter o do
    servidor" / "Descartar meu registro" (`escolha: 'servidor'`).

    `os_travada` e `registro_removido` escondem "Manter o meu registro": o
    próprio backend recusa aplicar o lado do aplicativo nesses dois motivos
    (`AppSyncService::aplicarLadoDoAplicativo()` lança exceção para
    `registro_removido`, e uma OS assinada nunca aceita alteração de campo) -
    oferecer o botão seria convidar o técnico a tentar algo que o servidor já
    sabe que vai falhar. A única saída nesses casos é descartar o registro
    local ou falar com o escritório, como o texto do motivo explica.

    Nada aqui é descartado sozinho: mesmo escolher "servidor" é um toque
    explícito do técnico, nunca uma resolução automática.
-->
<template>
    <Modal :show="!!conflito" @close="fechar">
        <template #icon>
            <svg class="h-6 w-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    stroke-width="2"
                    d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.964-.833-2.732 0L3.34 16.5c-.77.833.192 2.5 1.732 2.5z"
                />
            </svg>
        </template>

        <template #title>Resolver conflito</template>

        <template #content>
            <div v-if="conflito" class="space-y-4 text-left">
                <div>
                    <p class="text-sm font-medium text-gray-900">{{ rotuloDoTipo }}</p>
                    <p class="text-sm text-gray-700">{{ rotuloDaOrdem }}</p>
                </div>

                <div class="rounded-md border border-yellow-200 bg-yellow-50 p-3">
                    <p class="text-sm font-medium text-yellow-900">{{ motivoInfo.rotulo }}</p>
                    <p class="text-sm text-yellow-800 mt-1">{{ motivoInfo.explicacao }}</p>
                </div>

                <div class="rounded-md border border-gray-200 p-3 space-y-2">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">O que você registrou</p>
                    <ul class="text-sm text-gray-800 space-y-1">
                        <li v-for="campo in camposDoAplicativo" :key="campo.chave">
                            <span class="text-gray-500">{{ campo.rotulo }}:</span> {{ campo.valor }}
                        </li>
                    </ul>
                    <p class="text-xs text-gray-500">
                        Registrado em {{ dataDoTecnico || 'data não identificada' }}
                    </p>
                </div>

                <div class="rounded-md border border-gray-200 p-3 space-y-2">
                    <p class="text-xs font-semibold text-gray-500 uppercase tracking-wide">O que está no servidor</p>

                    <template v-if="camposDoServidor.length">
                        <ul class="text-sm text-gray-800 space-y-1">
                            <li v-for="campo in camposDoServidor" :key="campo.chave">
                                <span class="text-gray-500">{{ campo.rotulo }}:</span> {{ campo.valor }}
                            </li>
                        </ul>
                        <p class="text-xs text-gray-500">
                            Atualizado em {{ dataDoServidor || 'data não identificada' }}
                        </p>
                    </template>
                    <p v-else class="text-sm text-gray-700">Este registro não existe mais no servidor.</p>
                </div>

                <p v-if="erro" class="text-sm text-red-600">{{ erro }}</p>
            </div>
        </template>

        <template #actions>
            <button
                v-if="motivoInfo.permiteManterRegistro"
                type="button"
                class="btn-secondary"
                :disabled="enviando"
                @click="escolher('aplicativo')"
            >
                {{ enviando ? 'Enviando...' : 'Manter o meu registro' }}
            </button>
            <button type="button" class="btn-secondary" :disabled="enviando" @click="escolher('servidor')">
                {{
                    enviando
                        ? 'Enviando...'
                        : motivoInfo.permiteManterRegistro
                          ? 'Manter o do servidor'
                          : 'Descartar meu registro'
                }}
            </button>
            <button
                type="button"
                class="text-sm text-gray-600 underline min-h-[44px] w-full sm:w-auto"
                :disabled="enviando"
                @click="fechar"
            >
                Voltar sem decidir
            </button>
        </template>
    </Modal>
</template>

<script setup>
import { computed, ref } from 'vue';
import Modal from '@/Components/Modal.vue';
import { obterMeta } from '../db/repositorio';
import { formatarDataHora } from '@/utils/formatDate';

const props = defineProps({
    // Registro mesclado por `Pendencias.vue`: os campos de
    // `GET /api/app/conflitos` (`id`, `work_order_id`, `motivo`,
    // `valor_do_aplicativo`, `valor_do_servidor`, `criado_em`) mais
    // `registrada_em` e `tipo`, lidos do registro local da fila
    // (`sync/fila.js`). `null` quando nenhum conflito está selecionado.
    conflito: {
        type: Object,
        default: null,
    },
    rotuloDaOrdem: {
        type: String,
        default: 'Sem ordem de serviço vinculada',
    },
});

const emit = defineEmits(['fechar', 'resolvido']);

const enviando = ref(false);
const erro = ref(null);

const TIPOS = {
    evento_dispositivo: 'Evento de dispositivo',
    avistamento: 'Avistamento',
    adequacao: 'Adequação',
    conclusao_os: 'Conclusão da OS',
    foto: 'Foto',
};

// `permiteManterRegistro: false` reflete uma regra do backend, não só um
// texto: os dois motivos abaixo são os únicos em que
// `AppSyncService::aplicarLadoDoAplicativo()` não tem como aplicar o lado do
// aplicativo (ver comentário no topo do arquivo).
const MOTIVOS = {
    registro_alterado: {
        rotulo: 'Registro alterado no servidor',
        explicacao: 'Alguém alterou esta ordem de serviço depois que você começou a registrar esta informação.',
        permiteManterRegistro: true,
    },
    registro_removido: {
        rotulo: 'Registro removido do servidor',
        explicacao:
            'A ordem de serviço ou o registro desta operação não existe mais no servidor. A única saída é descartar o seu registro.',
        permiteManterRegistro: false,
    },
    os_travada: {
        rotulo: 'Ordem de serviço travada',
        explicacao:
            'Esta ordem de serviço já foi assinada e não aceita mais alterações de campo. A única saída é descartar o seu registro ou falar com o escritório.',
        permiteManterRegistro: false,
    },
    regra_de_negocio: {
        rotulo: 'Recusado por uma regra do sistema',
        explicacao: 'O servidor recusou este registro por uma regra de negócio.',
        permiteManterRegistro: true,
    },
};
const MOTIVO_PADRAO = {
    rotulo: 'Motivo não identificado',
    explicacao: 'Não foi possível identificar o motivo deste conflito.',
    permiteManterRegistro: true,
};

const rotuloDoTipo = computed(() => TIPOS[props.conflito?.tipo] || 'Registro pendente');
const motivoInfo = computed(() => MOTIVOS[props.conflito?.motivo] || MOTIVO_PADRAO);

const dataDoTecnico = computed(() =>
    props.conflito?.registrada_em ? formatarDataHora(props.conflito.registrada_em) : '',
);
const dataDoServidor = computed(() =>
    props.conflito?.valor_do_servidor?.updated_at ? formatarDataHora(props.conflito.valor_do_servidor.updated_at) : '',
);

/**
 * Transforma um objeto genérico de payload em uma lista de "rótulo: valor"
 * para exibição. O conteúdo varia por tipo de operação (evento, avistamento,
 * adequação...), então não há como listar campos fixos aqui - só humanizar a
 * chave (`nome_do_campo` -> "Nome do campo") e ocultar o que já aparece em
 * outro lugar da tela (`work_order_id`, `status`, `updated_at`).
 */
function paraCampos(objeto) {
    if (!objeto || typeof objeto !== 'object') {
        return [];
    }

    return Object.entries(objeto)
        .filter(([chave, valor]) => !['work_order_id', 'updated_at'].includes(chave) && valor !== null && valor !== '')
        .map(([chave, valor]) => ({
            chave,
            rotulo: humanizarChave(chave),
            valor: typeof valor === 'object' ? JSON.stringify(valor) : String(valor),
        }));
}

function humanizarChave(chave) {
    const texto = chave.replace(/_/g, ' ');

    return texto.charAt(0).toUpperCase() + texto.slice(1);
}

const camposDoAplicativo = computed(() => paraCampos(props.conflito?.valor_do_aplicativo));
const camposDoServidor = computed(() => paraCampos(props.conflito?.valor_do_servidor));

function fechar() {
    if (enviando.value) return;

    erro.value = null;
    emit('fechar');
}

async function extrairMensagemDeErro(resposta) {
    const corpo = await resposta.json().catch(() => null);

    return corpo?.message || corpo?.mensagem || `Não foi possível resolver este conflito agora (HTTP ${resposta.status}).`;
}

/**
 * `POST /api/app/conflitos/{id}/resolver`. O valor do lado "aplicativo" já
 * está guardado no servidor desde que o conflito foi aberto
 * (`valor_do_aplicativo`) - esta chamada só diz qual lado aplicar, nunca
 * reenvia o payload de novo.
 */
async function escolher(escolha) {
    if (!props.conflito || enviando.value) return;

    enviando.value = true;
    erro.value = null;

    try {
        const token = await obterMeta('token');
        const resposta = await fetch(`/api/app/conflitos/${props.conflito.id}/resolver`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                ...(token ? { Authorization: `Bearer ${token}` } : {}),
            },
            body: JSON.stringify({ escolha }),
        });

        if (!resposta.ok) {
            erro.value = await extrairMensagemDeErro(resposta);
            return;
        }

        emit('resolvido', { conflito: props.conflito, escolha });
    } catch (falhaDeRede) {
        erro.value = 'Não foi possível conectar ao servidor. Verifique sua internet e tente novamente.';
    } finally {
        enviando.value = false;
    }
}
</script>
