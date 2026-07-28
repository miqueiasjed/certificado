// Composable da carga do dia do aplicativo do técnico (Plano 12, Task 12.7).
//
// Regra de negócio central: a lista de ordens sai sempre do IndexedDB local,
// nunca da rede, direto na tela. `recarregar()` é a única função aqui que
// toca a rede, e o resultado dela só chega à tela depois de passar por
// `salvarCarga()` (repositorio.js) e ser relido do banco - o mesmo caminho
// que o aplicativo usa ao abrir offline. Assim a tela nunca trata "dado da
// rede" e "dado local" como duas fontes diferentes.

import { ref, computed, onMounted } from 'vue';
import { hojeISO } from '@/utils/formatDate';
import { listarOrdens, obterMeta, salvarCarga } from '../db/repositorio';

const MILISSEGUNDOS_POR_HORA = 60 * 60 * 1000;
const HORAS_PARA_CONSIDERAR_DESATUALIZADA = 24;

/**
 * Chave em `meta` (repositorio.js) onde a Task 12.9 guarda o token Sanctum
 * emitido no login. Lida aqui, nunca escrita: quem autentica é a tela de
 * login, este composable só usa o token se ele já existir.
 */
const CHAVE_META_TOKEN = 'token';
const CHAVE_META_ULTIMA_CARGA = 'ultima_carga';

export function useCargaDoDia() {
    const todasAsOrdens = ref([]);
    const diaSelecionado = ref(hojeISO());
    const carregando = ref(false);
    const ultimaCarga = ref(null);
    const erro = ref(null);

    /**
     * As "OS do dia" propriamente ditas: as ordens do dia selecionado
     * (hoje, por padrão), dentro de tudo que está carregado localmente.
     */
    const ordensDoDia = computed(() =>
        todasAsOrdens.value.filter((ordem) => ordem.data_agendada === diaSelecionado.value),
    );

    /**
     * Idade da carga em horas, a partir do instante em que o servidor gerou
     * a carga (`gerado_em`, gravado em `meta.ultima_carga`). `null` antes da
     * primeira carga, quando ainda não há nada para medir.
     */
    const idadeEmHoras = computed(() => {
        if (!ultimaCarga.value) {
            return null;
        }

        const instanteDaCarga = new Date(ultimaCarga.value).getTime();

        if (Number.isNaN(instanteDaCarga)) {
            return null;
        }

        return (Date.now() - instanteDaCarga) / MILISSEGUNDOS_POR_HORA;
    });

    /**
     * Mais de 24 horas desde a última carga: o técnico está trabalhando com
     * dado potencialmente velho, e a interface (Task 12.9) precisa avisar
     * disso.
     */
    const desatualizada = computed(
        () => idadeEmHoras.value !== null && idadeEmHoras.value > HORAS_PARA_CONSIDERAR_DESATUALIZADA,
    );

    function selecionarDia(data) {
        diaSelecionado.value = data;
    }

    /**
     * Relê o estado local (ordens e instante da última carga) para os refs
     * reativos. Chamada na montagem e depois de toda `recarregar()` bem
     * sucedida - é o que garante que a tela sempre reflete o que está no
     * IndexedDB, com ou sem rede.
     */
    async function carregarDoDispositivo() {
        const [ordens, ultimaCargaSalva] = await Promise.all([
            listarOrdens(),
            obterMeta(CHAVE_META_ULTIMA_CARGA),
        ]);

        todasAsOrdens.value = ordens;
        ultimaCarga.value = ultimaCargaSalva;
    }

    /**
     * Busca a carga no servidor e aplica no IndexedDB. Usa `desde` (última
     * carga conhecida) quando existir, para o servidor devolver só o que
     * mudou (AppDayLoadService, Task 12.3) em vez do pacote inteiro de novo.
     *
     * Sem rede (ou com o servidor fora do ar) não é tratado como falha
     * grave: a exceção só fica registrada em `erro`, para a interface (Task
     * 12.9) mostrar um aviso discreto, e a tela continua com o que já
     * estava carregado localmente.
     */
    async function recarregar() {
        carregando.value = true;
        erro.value = null;

        try {
            const desde = await obterMeta(CHAVE_META_ULTIMA_CARGA);
            const token = await obterMeta(CHAVE_META_TOKEN);

            const parametros = new URLSearchParams();
            if (desde) {
                parametros.set('desde', desde);
            }

            const resposta = await fetch(`/api/app/carga?${parametros.toString()}`, {
                headers: {
                    Accept: 'application/json',
                    ...(token ? { Authorization: `Bearer ${token}` } : {}),
                },
            });

            if (!resposta.ok) {
                throw new Error(`Não foi possível atualizar a carga do dia (HTTP ${resposta.status}).`);
            }

            const carga = await resposta.json();

            await salvarCarga(carga);
            await carregarDoDispositivo();
        } catch (erroCapturado) {
            erro.value = erroCapturado?.message || 'Não foi possível atualizar a carga do dia agora.';
        } finally {
            carregando.value = false;
        }
    }

    onMounted(async () => {
        carregando.value = true;

        try {
            await carregarDoDispositivo();
        } finally {
            carregando.value = false;
        }
    });

    return {
        todasAsOrdens,
        ordensDoDia,
        diaSelecionado,
        selecionarDia,
        carregando,
        ultimaCarga,
        idadeEmHoras,
        desatualizada,
        erro,
        recarregar,
    };
}
