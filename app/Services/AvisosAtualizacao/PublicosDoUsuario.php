<?php

namespace App\Services\AvisosAtualizacao;

use App\Models\User;

/**
 * Traduz os papéis Spatie do usuário nos públicos do aviso de atualização.
 *
 * O público é calculado no servidor a partir dos papéis; o frontend nunca manda
 * o público. Usuário com mais de um papel soma os públicos. O super admin da
 * plataforma (`is_platform_admin`) não tem público, nem quando entra numa
 * empresa em modo de suporte.
 *
 * `tecnico` vale para o técnico que usa o painel web. O aplicativo offline do
 * técnico (`app-tecnico.js`) ainda não mostra o pop-up.
 */
final class PublicosDoUsuario
{
    public const GESTAO = 'gestao';

    public const TECNICO = 'tecnico';

    public const TODOS = 'todos';

    public const VALIDOS = [
        self::GESTAO,
        self::TECNICO,
    ];

    private const POR_PAPEL = [
        'administrador' => self::GESTAO,
        'financeiro' => self::GESTAO,
        'comercial' => self::GESTAO,
        'leitura' => self::GESTAO,
        'tecnico' => self::TECNICO,
    ];

    /**
     * @param  iterable<string>  $papeis
     * @return list<string> públicos sem repetição, em ordem alfabética
     */
    public static function dosPapeis(iterable $papeis): array
    {
        $publicos = [];

        foreach ($papeis as $papel) {
            if (isset(self::POR_PAPEL[$papel])) {
                $publicos[self::POR_PAPEL[$papel]] = true;
            }
        }

        $lista = array_keys($publicos);
        sort($lista);

        return $lista;
    }

    /**
     * @return list<string>
     */
    public static function doUsuario(User $usuario): array
    {
        if ($usuario->is_platform_admin === true) {
            return [];
        }

        return self::dosPapeis($usuario->getRoleNames());
    }

    /**
     * @param  list<string>  $publicosDoAviso
     * @param  list<string>  $publicosDoUsuario
     */
    public static function avisoVale(array $publicosDoAviso, array $publicosDoUsuario): bool
    {
        if ($publicosDoUsuario === []) {
            return false;
        }

        if (in_array(self::TODOS, $publicosDoAviso, true)) {
            return true;
        }

        return array_intersect($publicosDoAviso, $publicosDoUsuario) !== [];
    }
}
