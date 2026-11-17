<?php

namespace App\Services\AvisosAtualizacao;

use App\Models\AvisoAtualizacaoLido;
use App\Models\Company;
use App\Models\User;
use App\Support\BusinessDate;
use App\Support\TenantAtual;

/**
 * Decide quais avisos de atualização estão pendentes para uma pessoa e marca os exibidos como lidos.
 *
 * Público: o aviso só entra se casar com os públicos dos papéis dela e, quando tem `tenants`, se o
 * `slug_publico` da empresa da requisição estiver na lista.
 * Permissão: aviso com `permissao` só entra para quem tem a permissão (`can`).
 * Cadastro: entra aviso publicado no dia do cadastro ou depois, contado em Brasília (`created_at` está em UTC).
 * Leitura: aviso já marcado como lido por ela nesta empresa não volta.
 */
final class AvisosAtualizacaoService
{
    public function __construct(private readonly CatalogoAvisosAtualizacao $catalogo) {}

    /**
     * Avisos pendentes, do mais antigo ao mais novo, cada um uma vez só.
     *
     * @return list<array{id: string, publicado_em: string, titulo: string, itens: list<string>}>
     */
    public function pendentes(User $usuario): array
    {
        $publicosDoUsuario = PublicosDoUsuario::doUsuario($usuario);

        if ($publicosDoUsuario === [] || TenantAtual::id() === null) {
            return [];
        }

        $slugDaEmpresa = $this->slugDaEmpresaAtual();
        $diaDoCadastro = $this->diaDoCadastro($usuario);
        $lidos = array_flip($this->idsLidos($usuario));

        $pendentes = [];

        foreach ($this->catalogo->todos() as $aviso) {
            if (! PublicosDoUsuario::avisoVale($aviso['publicos'], $publicosDoUsuario)) {
                continue;
            }

            if (! $this->valeParaEmpresa($aviso['tenants'] ?? null, $slugDaEmpresa)) {
                continue;
            }

            if (! $this->valeParaPermissao($aviso['permissao'] ?? null, $usuario)) {
                continue;
            }

            // Só quem se cadastrou depois do dia do aviso já chegou na versão nova. No próprio dia
            // a pessoa pode ter entrado antes do deploy, então recebe.
            if ($diaDoCadastro !== null && $aviso['publicado_em'] < $diaDoCadastro) {
                continue;
            }

            if (isset($lidos[$aviso['id']])) {
                continue;
            }

            $pendentes[] = [
                'id' => $aviso['id'],
                'publicado_em' => $aviso['publicado_em'],
                'titulo' => $aviso['titulo'],
                'itens' => $aviso['itens'],
            ];
        }

        return $pendentes;
    }

    /**
     * Marca como lidos os ids que estão pendentes para a pessoa. Id desconhecido, de outro público,
     * de outra empresa ou já lido é ignorado em silêncio: o pop-up pode ter sido aberto antes de
     * outra aba marcar o mesmo aviso.
     *
     * @param  array<mixed>  $ids
     * @return int quantos avisos foram marcados nesta chamada
     */
    public function marcarLidos(User $usuario, array $ids): int
    {
        $pedidos = array_flip(array_filter($ids, 'is_string'));

        if ($pedidos === []) {
            return 0;
        }

        $marcados = 0;

        foreach ($this->pendentes($usuario) as $aviso) {
            if (! isset($pedidos[$aviso['id']])) {
                continue;
            }

            // createOrFirst se apoia no índice único (company_id, user_id, aviso_id) e tolera duas
            // abas confirmando ao mesmo tempo. O company_id vem do trait BelongsToCompany. Fica
            // fora de transação: dentro de uma, a violação da unique abortaria a transação no MySQL.
            $registro = AvisoAtualizacaoLido::createOrFirst(
                ['company_id' => TenantAtual::exigirId(), 'user_id' => $usuario->id, 'aviso_id' => $aviso['id']],
                ['lido_em' => now()],
            );

            if ($registro->wasRecentlyCreated) {
                $marcados++;
            }
        }

        return $marcados;
    }

    /**
     * Sem empresa resolvida ou sem slug, nenhum aviso restrito por empresa passa.
     */
    private function slugDaEmpresaAtual(): ?string
    {
        $slug = Company::query()->whereKey(TenantAtual::id())->value('slug_publico');

        return is_string($slug) && $slug !== '' ? $slug : null;
    }

    /**
     * @param  list<string>|null  $empresasDoAviso
     */
    private function valeParaEmpresa(?array $empresasDoAviso, ?string $slugDaEmpresa): bool
    {
        if ($empresasDoAviso === null) {
            return true;
        }

        return $slugDaEmpresa !== null && in_array($slugDaEmpresa, $empresasDoAviso, true);
    }

    /**
     * Sem `permissao`, vale para o público inteiro. Permissão que não existe no banco nega.
     */
    private function valeParaPermissao(?string $permissao, User $usuario): bool
    {
        return $permissao === null || $usuario->can($permissao);
    }

    /**
     * Dia do cadastro no Brasil, em `Y-m-d`. Nulo quando `created_at` falta: conta como cadastro
     * antigo e recebe os avisos.
     */
    private function diaDoCadastro(User $usuario): ?string
    {
        if ($usuario->created_at === null) {
            return null;
        }

        return $usuario->created_at->copy()->setTimezone(BusinessDate::fuso())->toDateString();
    }

    /**
     * A consulta passa pelo escopo global de empresa do trait.
     *
     * @return list<string>
     */
    private function idsLidos(User $usuario): array
    {
        return AvisoAtualizacaoLido::query()
            ->where('user_id', $usuario->id)
            ->pluck('aviso_id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }
}
