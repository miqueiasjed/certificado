import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Composable de módulos ativos do tenant atual (Plano 6).
 *
 * Lê `modulos` compartilhado pelo HandleInertiaRequests. Esconder item de
 * menu é conforto visual, não segurança: quem barra o acesso de verdade é o
 * middleware `module:<chave>` (Task 6.4) nas rotas protegidas.
 */
export function useModulos() {
  const $page = usePage();

  const modulos = computed(() => $page.props.modulos || []);

  const temModulo = (chave) => modulos.value.includes(chave);

  return {
    modulos,
    temModulo,
  };
}
