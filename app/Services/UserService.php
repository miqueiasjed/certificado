<?php

namespace App\Services;

use App\Models\Company;
use App\Models\Technician;
use App\Models\User;
use App\Support\DominioMultiempresa;
use App\Support\TenantAtual;
use Illuminate\Support\Str;

class UserService
{
    public function __construct(
        private readonly LimitesDoPlanoService $limitesDoPlanoService,
    ) {
    }

    /**
     * Cria um usuário, atribui o papel informado e, quando houver
     * technician_id, grava o vínculo em technicians.user_id.
     *
     * Antes de criar, checa o limite de usuários do plano (Task 6.5):
     * usuário é o único recurso com bloqueio duro na criação, porque dar
     * acesso a mais uma pessoa é ato administrativo consciente, e não fluxo
     * de trabalho que não pode parar (diferente de cliente e OS, que
     * continuam sendo criados acima do teto, só com aviso).
     */
    public function criar(array $dados): User
    {
        $this->recusarSeEstourarLimiteDeUsuarios();

        $senha = $dados['password'] ?? Str::password(12);

        $usuario = User::create([
            'name' => $dados['name'],
            'email' => $dados['email'],
            'password' => $senha,
            'is_active' => $dados['is_active'] ?? true,
        ]);

        if (! empty($dados['role'])) {
            $usuario->assignRole($dados['role']);
        }

        if (! empty($dados['technician_id'])) {
            $this->vincularTecnico($usuario, (int) $dados['technician_id']);
        }

        return $usuario->fresh();
    }

    /**
     * Atualiza nome, e-mail, papel e vínculo com técnico de um usuário.
     * Troca de papel de um administrador passa antes pela checagem de
     * administrador restante.
     */
    public function atualizar(User $usuario, array $dados): User
    {
        if (array_key_exists('role', $dados) && $dados['role'] !== null) {
            if ($usuario->hasRole('administrador') && $dados['role'] !== 'administrador') {
                $this->garantirAdministradorRestante($usuario);
            }

            $usuario->syncRoles([$dados['role']]);
        }

        $usuario->fill([
            'name' => $dados['name'] ?? $usuario->name,
            'email' => $dados['email'] ?? $usuario->email,
        ]);

        $usuario->save();

        if (array_key_exists('technician_id', $dados)) {
            $this->desvincularTecnicos($usuario);

            if (! empty($dados['technician_id'])) {
                $this->vincularTecnico($usuario, (int) $dados['technician_id']);
            }
        }

        return $usuario->fresh();
    }

    /**
     * Ativa ou desativa um usuário. Desativar passa antes pela checagem de
     * administrador restante e pela regra de não desativar a si mesmo.
     *
     * $idUsuarioAutenticado é quem está pedindo a operação. O Service não
     * consulta o usuário autenticado sozinho: quem chama informa o id.
     */
    public function alterarStatus(User $usuario, bool $ativo, ?int $idUsuarioAutenticado = null): User
    {
        if (! $ativo) {
            if ($idUsuarioAutenticado !== null && $idUsuarioAutenticado === $usuario->id) {
                throw new \RuntimeException('Você não pode desativar o próprio usuário.');
            }

            $this->garantirAdministradorRestante($usuario);
        }

        $usuario->is_active = $ativo;
        $usuario->save();

        return $usuario->fresh();
    }

    /**
     * Lança exceção, nomeando o limite e o plano, quando a empresa já está
     * no teto de usuários do plano contratado.
     *
     * `Company::current()` resolve o tenant da requisição, no mesmo padrão
     * já usado por outros Services do projeto (`CertificateService`,
     * `BudgetService`, `WorkOrderService`). Teto nulo (plano sem limite, ou
     * empresa sem plano) nunca recusa: `LimitesDoPlanoService::podeCriar()`
     * já trata isso.
     */
    private function recusarSeEstourarLimiteDeUsuarios(): void
    {
        $empresa = Company::current();

        if ($this->limitesDoPlanoService->podeCriar('usuarios', $empresa)) {
            return;
        }

        $empresa->loadMissing('plan');

        throw new \RuntimeException(sprintf(
            'O plano %s permite no máximo %d usuário(s) ativos, e esse limite já foi atingido. Fale com o suporte para ampliar o plano.',
            $empresa->plan?->nome ?? 'atual',
            $empresa->plan?->limite_usuarios
        ));
    }

    /**
     * Garante que a operação sobre este usuário não deixe a empresa sem
     * nenhum administrador ativo. Lança exceção quando este usuário é o
     * único administrador ativo restante.
     *
     * A contagem é escopada pela empresa do usuário ALVO, e não pela de quem
     * pediu a operação. A diferença importa: contar no tenant do solicitante
     * fazia a checagem responder sobre a empresa errada, e a empresa do alvo
     * podia ficar sem nenhum administrador porque a do solicitante ainda tinha
     * os dela. O binding de `{user}` já impede que as duas sejam diferentes em
     * requisição HTTP; contar pela do alvo é o que mantém a regra correta
     * também quando o Service é chamado de outro contexto.
     *
     * Sem empresa no alvo nem no contexto, a contagem não filtra, como o resto
     * do sistema faz quando não há tenant resolvido. Isso só acontece fora de
     * requisição HTTP, porque `users.company_id` é NOT NULL e o registro que
     * vem do banco sempre traz a coluna.
     */
    private function garantirAdministradorRestante(User $usuario): void
    {
        if (! $usuario->hasRole('administrador') || ! $usuario->is_active) {
            return;
        }

        $consulta = User::role('administrador')
            ->where('is_active', true)
            ->where('id', '!=', $usuario->id);

        $empresaDoAlvo = $usuario->getAttribute(DominioMultiempresa::COLUNA_TENANT) ?? TenantAtual::id();

        if ($empresaDoAlvo !== null) {
            $consulta->where(
                $usuario->qualifyColumn(DominioMultiempresa::COLUNA_TENANT),
                $empresaDoAlvo
            );
        }

        if ($consulta->count() === 0) {
            throw new \RuntimeException('Não é possível concluir a operação: é necessário manter ao menos um administrador ativo.');
        }
    }

    /**
     * Solta o vínculo de qualquer técnico que aponte para este usuário.
     *
     * `Technician` tem o escopo global por empresa, então a limpeza nunca
     * atravessa o tenant corrente.
     */
    private function desvincularTecnicos(User $usuario): void
    {
        Technician::query()
            ->where('user_id', $usuario->id)
            ->update(['user_id' => null]);
    }

    /**
     * Vincula o usuário ao técnico informado.
     *
     * O técnico é RESOLVIDO antes de ser alterado, em vez de receber um
     * `whereKey()->update()` direto. A diferença é de desenho: o update em
     * massa dependia do escopo global para não escrever no técnico de outra
     * empresa, e quando o id era de fora ele simplesmente não afetava nenhuma
     * linha, em silêncio. Resolvendo primeiro, id de outra empresa vira erro
     * explícito e o vínculo nunca fica pela metade sem ninguém saber.
     */
    private function vincularTecnico(User $usuario, int $tecnicoId): void
    {
        $tecnico = Technician::query()->whereKey($tecnicoId)->first();

        if ($tecnico === null) {
            throw new \RuntimeException('O técnico selecionado não existe nesta empresa.');
        }

        $tecnico->user_id = $usuario->id;
        $tecnico->save();
    }
}
