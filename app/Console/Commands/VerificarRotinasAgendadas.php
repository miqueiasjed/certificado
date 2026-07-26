<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Company;
use App\Services\Notification\AvisoDeRotinaFalha;
use App\Support\RotinasAgendadas;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Rotina de hora em hora que descobre qual rotina agendada parou e avisa o
 * administrador de cada empresa (Task 14.5).
 *
 * Fecha a lacuna que o Plano 1 deixou aberta. `routines:status` mostra o
 * histórico para quem abre o terminal e pergunta; esta rotina pergunta sozinha,
 * de hora em hora, e manda a resposta para quem pode agir. A diferença importa
 * porque rotina que deixa de rodar não grava linha de erro: o histórico dela
 * fica idêntico ao de um sistema saudável, e ninguém descobre o problema até um
 * certificado vencido aparecer como ativo na fiscalização.
 *
 * Duas passadas, com escopos diferentes
 * -------------------------------------
 * A detecção roda uma vez, fora de tenant: `scheduled_task_runs` é tabela de
 * plataforma e tem uma linha por execução do comando, não uma por empresa
 * processada dentro dele. O aviso roda uma vez por empresa, dentro do tenant
 * dela (`OperaPorTenant`), porque enfileirar exige empresa resolvida e porque o
 * administrador que recebe é o daquela empresa.
 *
 * Por que rotina com problema não faz este comando falhar
 * -------------------------------------------------------
 * O código de saída aqui responde "a verificação funcionou?", e não "o sistema
 * está saudável?". Devolver `FAILURE` ao encontrar uma rotina parada marcaria
 * esta execução como `failed` em `scheduled_task_runs`, e a passada seguinte
 * acusaria a própria verificação de ter falhado. O problema encontrado vira
 * e-mail e saída no terminal; falha de verdade é não conseguir avisar.
 */
class VerificarRotinasAgendadas extends Command
{
    use OperaPorTenant;

    protected $signature = 'rotinas:verificar';

    protected $description = 'Verifica se as rotinas agendadas estão rodando e avisa o administrador de cada empresa quando uma falha ou deixa de executar dentro da janela esperada.';

    /**
     * Larguras de coluna da tabela de problemas impressa no terminal.
     *
     * @var array<int, int>
     */
    private const LARGURA_COLUNAS = [30, 12, 38, 20];

    public function handle(AvisoDeRotinaFalha $aviso): int
    {
        $this->info('Verificando as rotinas agendadas...');
        $this->line('Rotinas no catálogo: '.count(RotinasAgendadas::assinaturas()));

        $problemas = $aviso->problemas();

        if ($problemas === []) {
            $this->info('Nenhuma rotina com problema. Nada a avisar.');

            return Command::SUCCESS;
        }

        $this->imprimirProblemas($problemas);

        $dePlataforma = array_values(array_filter(
            $problemas,
            static fn (array $problema): bool => RotinasAgendadas::ehDePlataforma($problema['assinatura'])
        ));

        $deTenant = array_values(array_filter(
            $problemas,
            static fn (array $problema): bool => ! RotinasAgendadas::ehDePlataforma($problema['assinatura'])
        ));

        $this->avisarSuperAdmin($dePlataforma);

        if ($deTenant === []) {
            return Command::SUCCESS;
        }

        return $this->paraCadaTenant(
            fn (Company $empresa): int => $this->avisarEmpresa($aviso, $deTenant, $empresa)
        );
    }

    /**
     * Uma empresa, já dentro do tenant dela.
     *
     * @param  array<int, array<string, string>>  $problemas
     * @return int quantidade de avisos novos enfileirados nesta empresa
     */
    private function avisarEmpresa(AvisoDeRotinaFalha $aviso, array $problemas, Company $empresa): int
    {
        $contagem = $aviso->avisarAdministradores($problemas, $empresa);

        if ($contagem['administradores'] === 0) {
            $this->error('  Nenhum administrador ativo nesta empresa: o aviso não tem para quem ir.');
            $this->line('  Cadastre ao menos um usuário com o papel administrador em Configurações.');

            return 0;
        }

        $this->line("  Administradores avisados: {$contagem['administradores']}");
        $this->line("  Avisos novos: {$contagem['enfileirados']}");
        $this->line("  Já estavam na fila (aviso do dia já enviado): {$contagem['duplicados']}");

        if ($contagem['sem_envio'] > 0) {
            $this->line('  Sem envio (administrador sem e-mail ou evento sem template): '.$contagem['sem_envio']);
        }

        return $contagem['enfileirados'];
    }

    /**
     * Caminho da rotina de plataforma, que não pertence a empresa nenhuma.
     *
     * Hoje não roda nunca: `RotinasAgendadas::DE_PLATAFORMA` está vazia porque
     * todas as oito rotinas atuais processam dado de empresa. O gancho fica
     * pronto e mínimo de propósito, porque o destinatário dele é o super admin
     * do Plano 5, que ainda não existe no sistema (não há papel, tabela nem
     * usuário de plataforma modelado). Quando o Plano 5 entrar, é aqui que o
     * aviso passa a ser enfileirado para ele; até lá, o problema vai para o log
     * da aplicação, que é o canal que sobra.
     *
     * @param  array<int, array<string, string>>  $problemas
     */
    private function avisarSuperAdmin(array $problemas): void
    {
        if ($problemas === []) {
            return;
        }

        $assinaturas = array_column($problemas, 'assinatura');

        $this->warn('Rotina de plataforma com problema: '.implode(', ', $assinaturas));
        $this->line('  Quem recebe este aviso é o super admin (Plano 5), que ainda não existe. Registrado em log.');

        Log::error('Rotina de plataforma com problema e sem super admin para avisar.', [
            'rotinas' => $assinaturas,
        ]);
    }

    /**
     * @param  array<int, array<string, string>>  $problemas
     */
    private function imprimirProblemas(array $problemas): void
    {
        $this->newLine();
        $this->error('Rotinas com problema: '.count($problemas));
        $this->line($this->formatarLinha(['Rotina', 'Problema', 'Horário esperado', 'Último sucesso']));
        $this->line(str_repeat('-', array_sum(self::LARGURA_COLUNAS) + count(self::LARGURA_COLUNAS) - 1));

        foreach ($problemas as $problema) {
            $this->line($this->formatarLinha([
                $problema['assinatura'],
                $problema['tipo'] === AvisoDeRotinaFalha::PROBLEMA_FALHOU ? 'falhou' : 'não rodou',
                $problema['horario_esperado'],
                $problema['ultima_execucao'],
            ]));
        }
    }

    /**
     * @param  array<int, string>  $valores
     */
    private function formatarLinha(array $valores): string
    {
        $colunas = [];

        foreach (array_values($valores) as $indice => $valor) {
            // mb_str_pad não existe antes do PHP 8.3, e str_pad conta bytes:
            // "não rodou" e "Horário esperado" levam acento e sairiam tortos.
            $faltam = self::LARGURA_COLUNAS[$indice] - mb_strlen($valor);
            $colunas[] = $faltam > 0 ? $valor.str_repeat(' ', $faltam) : $valor;
        }

        return implode(' ', $colunas);
    }
}
