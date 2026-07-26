import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Composable de permissões do usuário autenticado.
 *
 * Lê `auth.user.permissoes` e `auth.user.papeis` compartilhados pelo
 * HandleInertiaRequests. Esconder item de menu é conveniência visual,
 * a proteção real fica na rota (middleware/policy do backend).
 */
export function usePermissoes() {
  const $page = usePage();

  const permissoes = computed(() => $page.props.auth?.user?.permissoes || []);
  const papeis = computed(() => $page.props.auth?.user?.papeis || []);

  const pode = (permissao) => permissoes.value.includes(permissao);

  const podeAlguma = (listaPermissoes) => {
    if (!Array.isArray(listaPermissoes)) return false;

    return listaPermissoes.some((permissao) => pode(permissao));
  };

  const temPapel = (papel) => papeis.value.includes(papel);

  const ehAdministrador = computed(() => temPapel('administrador'));

  return {
    pode,
    podeAlguma,
    temPapel,
    ehAdministrador,
  };
}
