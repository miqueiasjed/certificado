<?php

namespace App\Services;

use App\Models\Technician;
use App\Models\User;
use Illuminate\Support\Str;

class UserService
{
    /**
     * Cria um usuário, atribui o papel informado e, quando houver
     * technician_id, grava o vínculo em technicians.user_id.
     */
    public function criar(array $dados): User
    {
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
            Technician::whereKey($dados['technician_id'])
                ->update(['user_id' => $usuario->id]);
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
            Technician::where('user_id', $usuario->id)->update(['user_id' => null]);

            if (! empty($dados['technician_id'])) {
                Technician::whereKey($dados['technician_id'])
                    ->update(['user_id' => $usuario->id]);
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
     * Garante que a operação sobre este usuário não deixe a empresa sem
     * nenhum administrador ativo. Lança exceção quando este usuário é o
     * único administrador ativo restante.
     */
    private function garantirAdministradorRestante(User $usuario): void
    {
        if (! $usuario->hasRole('administrador') || ! $usuario->is_active) {
            return;
        }

        $administradoresAtivos = User::role('administrador')
            ->where('is_active', true)
            ->where('id', '!=', $usuario->id)
            ->count();

        if ($administradoresAtivos === 0) {
            throw new \RuntimeException('Não é possível concluir a operação: é necessário manter ao menos um administrador ativo.');
        }
    }
}
