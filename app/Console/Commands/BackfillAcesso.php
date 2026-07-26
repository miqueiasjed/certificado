<?php

namespace App\Console\Commands;

use App\Models\Technician;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Backfill de acesso, passo único de dado separado das migrations.
 *
 * Faz duas coisas, sempre em modo simulação primeiro:
 *
 * 1. Atribui o papel de --papel a todo usuário sem papel nenhum.
 * 2. Vincula Technician a User quando o e-mail é idêntico, comparado sem
 *    caixa e sem espaço nas pontas. Nunca por nome, nunca por aproximação.
 *
 * Idempotente: rodar de novo depois de aplicado não encontra mais nada para
 * fazer. Fora de uma requisição HTTP não existe usuário autenticado nem
 * empresa corrente, então o comando não assume nenhum tenant, apenas lê e
 * grava o que já está no banco.
 */
class BackfillAcesso extends Command
{
    private const PAPEL_ADMINISTRADOR = 'administrador';

    private const PAPEL_TECNICO = 'tecnico';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'acesso:backfill
                            {--dry-run : Lista o plano e não grava nada}
                            {--papel=administrador : Papel atribuído a usuário sem papel nenhum}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Atribui papel inicial a usuário sem papel nenhum e vincula técnico a usuário quando o e-mail for idêntico.';

    public function handle(): int
    {
        $simulacao = (bool) $this->option('dry-run');
        $papel = trim((string) $this->option('papel'));

        if ($papel === '') {
            $this->error('A opção --papel não pode ficar vazia.');

            return Command::FAILURE;
        }

        $this->info('Backfill de acesso: papel inicial e vínculo técnico-usuário');
        $this->line($simulacao
            ? 'Modo simulação (--dry-run): nada será gravado.'
            : 'Modo aplicação: a gravação exige confirmação antes de começar.');
        $this->newLine();

        $usuariosSemPapel = $this->levantarUsuariosSemPapel();
        $this->imprimirUsuariosSemPapel($usuariosSemPapel, $papel);

        $this->newLine();

        $vinculos = $this->levantarVinculos();
        $this->imprimirVinculos($vinculos['encontrados'], $vinculos['pendentes'], $vinculos['avisos']);

        $temMudanca = $usuariosSemPapel->isNotEmpty() || $vinculos['encontrados']->isNotEmpty();

        if ($simulacao) {
            $this->newLine();
            $this->info('Simulação concluída. Nada foi gravado.');

            return $this->verificarAdministradorSimulado($usuariosSemPapel, $papel);
        }

        if (! $temMudanca) {
            $this->newLine();
            $this->info('Nada a gravar. Papéis e vínculos já estão em dia.');

            return $this->verificarAdministradorReal();
        }

        $this->newLine();

        if (! $this->confirm('Gravar as mudanças listadas acima?', false)) {
            $this->warn('Operação cancelada. Nada foi gravado.');

            return $this->verificarAdministradorReal();
        }

        $papeisAtribuidos = $this->aplicarPapeis($usuariosSemPapel, $papel);
        $vinculosCriados = $this->aplicarVinculos($vinculos['encontrados']);

        $this->newLine();
        $this->imprimirResumo($papeisAtribuidos, $vinculosCriados, $vinculos['pendentes']->count());

        return $this->verificarAdministradorReal();
    }

    /**
     * Nunca grava. Só lê quem hoje não tem nenhum papel atribuído.
     *
     * @return Collection<int, User>
     */
    private function levantarUsuariosSemPapel(): Collection
    {
        return User::query()
            ->with('roles')
            ->orderBy('id')
            ->get()
            ->filter(fn (User $usuario) => $usuario->roles->isEmpty())
            ->values();
    }

    /**
     * Nunca grava. Casa Technician sem user_id com User pelo e-mail idêntico,
     * comparado sem caixa e sem espaço nas pontas. Um usuário só entra em um
     * vínculo por rodada: se dois técnicos apontarem para o mesmo e-mail, o
     * segundo vira pendência, porque a coluna user_id de technicians é única
     * e gravar os dois quebraria a restrição do banco.
     *
     * @return array{encontrados: Collection<int, array{tecnico: Technician, usuario: User}>, pendentes: Collection<int, array{tecnico: Technician, motivo: string}>, avisos: Collection<int, string>}
     */
    private function levantarVinculos(): array
    {
        $usuariosPorEmail = User::query()
            ->get()
            ->keyBy(fn (User $usuario) => $this->normalizarEmail($usuario->email));

        $encontrados = collect();
        $pendentes = collect();
        $avisos = collect();
        $usuariosReservados = [];

        Technician::query()
            ->whereNull('user_id')
            ->orderBy('id')
            ->get()
            ->each(function (Technician $tecnico) use ($usuariosPorEmail, &$encontrados, &$pendentes, &$avisos, &$usuariosReservados) {
                $emailNormalizado = $this->normalizarEmail($tecnico->email);
                $usuario = $emailNormalizado === '' ? null : $usuariosPorEmail->get($emailNormalizado);

                if (! $usuario) {
                    $pendentes->push([
                        'tecnico' => $tecnico,
                        'motivo' => 'nenhum usuário com este e-mail',
                    ]);

                    return;
                }

                if (in_array($usuario->id, $usuariosReservados, true)) {
                    $pendentes->push([
                        'tecnico' => $tecnico,
                        'motivo' => "usuário #{$usuario->id} já vinculado a outro técnico neste lote",
                    ]);

                    return;
                }

                $usuariosReservados[] = $usuario->id;
                $encontrados->push(['tecnico' => $tecnico, 'usuario' => $usuario]);

                if (! $usuario->hasRole(self::PAPEL_TECNICO)) {
                    $papeisAtuais = $usuario->getRoleNames()->implode(', ');

                    $avisos->push(sprintf(
                        'Técnico #%d (%s) vai vincular ao usuário #%d (%s), que não tem o papel "%s" (papel atual: %s). O papel não é trocado.',
                        $tecnico->id,
                        $tecnico->email,
                        $usuario->id,
                        $usuario->email,
                        self::PAPEL_TECNICO,
                        $papeisAtuais === '' ? 'nenhum' : $papeisAtuais
                    ));
                }
            });

        return compact('encontrados', 'pendentes', 'avisos');
    }

    /**
     * Comparação de e-mail idêntico: sem caixa e sem espaço nas pontas.
     * Nunca por nome, nunca por aproximação.
     */
    private function normalizarEmail(?string $email): string
    {
        return mb_strtolower(trim((string) $email));
    }

    /**
     * @param  Collection<int, User>  $usuarios
     */
    private function aplicarPapeis(Collection $usuarios, string $papel): int
    {
        $aplicados = 0;

        foreach ($usuarios as $usuario) {
            $usuario->assignRole($papel);
            $aplicados++;
        }

        return $aplicados;
    }

    /**
     * @param  Collection<int, array{tecnico: Technician, usuario: User}>  $encontrados
     */
    private function aplicarVinculos(Collection $encontrados): int
    {
        $criados = 0;

        foreach ($encontrados as $par) {
            $par['tecnico']->update(['user_id' => $par['usuario']->id]);
            $criados++;
        }

        return $criados;
    }

    /**
     * @param  Collection<int, User>  $usuarios
     */
    private function imprimirUsuariosSemPapel(Collection $usuarios, string $papel): void
    {
        $this->line('Usuários sem papel nenhum:');

        if ($usuarios->isEmpty()) {
            $this->line('  nenhum');

            return;
        }

        $this->table(
            ['ID', 'Nome', 'E-mail', 'Papel a atribuir'],
            $usuarios->map(fn (User $usuario) => [
                $usuario->id,
                $usuario->name,
                $usuario->email,
                $papel,
            ])->all()
        );
    }

    /**
     * @param  Collection<int, array{tecnico: Technician, usuario: User}>  $encontrados
     * @param  Collection<int, array{tecnico: Technician, motivo: string}>  $pendentes
     * @param  Collection<int, string>  $avisos
     */
    private function imprimirVinculos(Collection $encontrados, Collection $pendentes, Collection $avisos): void
    {
        $this->line('Vínculos técnico-usuário por e-mail idêntico:');

        if ($encontrados->isEmpty()) {
            $this->line('  nenhum vínculo novo encontrado');
        } else {
            $this->table(
                ['Técnico', 'E-mail do técnico', 'Usuário', 'E-mail do usuário'],
                $encontrados->map(fn (array $par) => [
                    "#{$par['tecnico']->id} {$par['tecnico']->name}",
                    $par['tecnico']->email,
                    "#{$par['usuario']->id} {$par['usuario']->name}",
                    $par['usuario']->email,
                ])->all()
            );
        }

        if ($avisos->isNotEmpty()) {
            $this->newLine();
            $this->warn('Avisos:');

            foreach ($avisos as $aviso) {
                $this->line("  {$aviso}");
            }
        }

        $this->newLine();
        $this->line('Pendências para conferência manual:');

        if ($pendentes->isEmpty()) {
            $this->line('  nenhuma');

            return;
        }

        $this->table(
            ['Técnico', 'E-mail do técnico', 'Motivo'],
            $pendentes->map(fn (array $item) => [
                "#{$item['tecnico']->id} {$item['tecnico']->name}",
                $item['tecnico']->email,
                $item['motivo'],
            ])->all()
        );
    }

    private function imprimirResumo(int $papeisAtribuidos, int $vinculosCriados, int $pendencias): void
    {
        $this->info('Resumo:');
        $this->line("  Papéis atribuídos: {$papeisAtribuidos}");
        $this->line("  Vínculos criados: {$vinculosCriados}");
        $this->line("  Pendências para conferência manual: {$pendencias}");
    }

    /**
     * Checagem final contra o banco real. Empresa sem nenhum administrador
     * ativo é falha grave: avisa e devolve exit code diferente de zero.
     */
    private function verificarAdministradorReal(): int
    {
        $existeAtivo = User::query()
            ->where('is_active', true)
            ->get()
            ->contains(fn (User $usuario) => $usuario->hasRole(self::PAPEL_ADMINISTRADOR));

        if (! $existeAtivo) {
            $this->newLine();
            $this->error('ALERTA: nenhum usuário ativo tem o papel "administrador". A empresa ficou sem administrador.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Mesma checagem, mas em --dry-run, quando nada foi gravado ainda. Soma
     * ao estado atual o que seria atribuído nesta rodada, só quando o papel
     * simulado é o de administrador.
     *
     * @param  Collection<int, User>  $usuariosSemPapel
     */
    private function verificarAdministradorSimulado(Collection $usuariosSemPapel, string $papel): int
    {
        $existeAtivo = User::query()
            ->where('is_active', true)
            ->get()
            ->contains(fn (User $usuario) => $usuario->hasRole(self::PAPEL_ADMINISTRADOR));

        if (! $existeAtivo && $papel === self::PAPEL_ADMINISTRADOR) {
            $existeAtivo = $usuariosSemPapel->contains(fn (User $usuario) => (bool) $usuario->is_active);
        }

        if (! $existeAtivo) {
            $this->newLine();
            $this->error('ALERTA (simulação): ao final desta execução real, nenhum usuário ativo ficaria com o papel "administrador".');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
