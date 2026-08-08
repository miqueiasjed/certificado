<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Company;
use App\Models\PersonalProtectiveEquipment;
use App\Models\PpeDelivery;
use App\Models\Technician;
use App\Services\ModuleService;
use App\Services\NotificationService;
use App\Services\Ppe\AlertaDeEpiService;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rotina diária que avisa antes de o problema de EPI virar autuação (Plano 28,
 * Task 28.3): CA prestes a vencer, CA já vencido no cadastro e item entregue
 * cuja troca passou da data.
 *
 * Três avisos, três cadências
 * ---------------------------
 * - `epi_com_ca_a_vencer`: um aviso por modelo de EPI e por marco (60, 30 e 7
 *   dias), mesmas janelas do documento de veículo do Plano 27. O marco é a
 *   janela chamada, e não o `dias_para_vencer` do item: é o que permite ao
 *   mesmo CA gerar até três avisos ao longo do tempo sem duplicar dentro da
 *   mesma janela.
 * - `epi_com_ca_vencido`: reenvio **semanal** enquanto o CA continuar vencido,
 *   mesmo critério do documento regulatório do Plano 24 e do documento de
 *   veículo do Plano 27. Tem consequência legal: com o CA vencido o modelo não
 *   pode mais ser entregue (regra da Task 28.2), e o silêncio depois do
 *   primeiro aviso não resolve nada.
 * - `epi_com_troca_vencida`: reenvio **semanal** por entrega, enquanto o técnico
 *   continuar em campo com o item vencido.
 *
 * Rodar duas vezes no mesmo dia não duplica nada: quem garante isso é a chave de
 * idempotência do `NotificationService` (evento + destinatário + registro +
 * marco), não uma trava desta rotina.
 *
 * O que faz cada aviso parar
 * --------------------------
 * Aviso que se repete para sempre é aviso que o usuário aprende a ignorar — e
 * junto com ele, os avisos que importam. Quem decide a parada é o
 * `AlertaDeEpiService`: validade atualizada ou modelo inativado, para o CA;
 * devolução, estorno ou **entrega mais recente do mesmo EPI ao mesmo técnico**,
 * para a troca. Este último critério é o que evita repetir aqui o defeito
 * corrigido no `d9a3a9c`, onde o documento de veículo renovado por linha nova
 * reenviava aviso semanal para sempre.
 *
 * Nada aqui bloqueia nada: o alerta informa. Quem recusa a entrega com CA
 * vencido é o Service da Task 28.2, no ato da entrega.
 *
 * Isolamento por tenant e por registro
 * -------------------------------------
 * Mesmo padrão de `VerificarFrota` (Task 27.3) e `VerificarEstoque` (Task
 * 17.6): laço por empresa (`OperaPorTenant`, que restaura o tenant anterior e
 * isola o erro de uma empresa das demais) e cada disparo com o próprio
 * try/catch, para que um registro com dado inconsistente não cale os avisos dos
 * outros.
 *
 * `--dry-run` antes de ligar
 * --------------------------
 * A primeira passada depois de o módulo ser ligado pode gerar uma leva grande de
 * avisos, porque toda entrega retroativa lançada com data antiga já nasce com a
 * troca vencida. `--dry-run` mostra o volume e não grava nada — é o que a Task
 * 27.5 aprendeu com `frota:verificar`.
 *
 * Só roda para o tenant com o módulo `epi` ligado, e o módulo nasce desligado:
 * é isso que permite subir esta entrega com a rotina já agendada sem que nenhum
 * aviso saia antes de a empresa cadastrar os EPIs com CA e validade reais.
 */
class VerificarEpi extends Command
{
    use OperaPorTenant;

    protected $signature = 'epi:verificar
                            {--dry-run : Mostra o que seria avisado e não grava nada}';

    protected $description = 'Enfileira avisos de CA de EPI a vencer, CA vencido e troca de item entregue vencida (Plano 28).';

    /**
     * O Service e os auxiliares chegam pelo `handle()`, e não pelo construtor,
     * para não serem resolvidos em toda chamada de artisan do sistema.
     */
    private ?NotificationService $notificacoes = null;

    private ?AlertaDeEpiService $alertas = null;

    private ?ModuleService $modulos = null;

    /**
     * Simulação: nada é gravado e a contagem vai para `simulados`.
     */
    private bool $simulacao = false;

    /**
     * Contagem da empresa corrente, zerada a cada volta do laço por tenant.
     *
     * @var array<string, int>
     */
    private array $contagem = [];

    /**
     * Algum registro, em alguma empresa, terminou com erro.
     *
     * Mesmo critério de `VerificarFrota::$algumRegistroFalhou`: sem este sinal a
     * rotina terminaria com código de sucesso mesmo com avisos que deixaram de
     * sair, e a verificação de rotinas (Task 14.5) não teria como saber.
     */
    private bool $algumRegistroFalhou = false;

    public function handle(
        NotificationService $notificacoes,
        AlertaDeEpiService $alertas,
        ModuleService $modulos
    ): int {
        $this->notificacoes = $notificacoes;
        $this->alertas = $alertas;
        $this->modulos = $modulos;
        $this->simulacao = (bool) $this->option('dry-run');

        $this->info('Verificando CA de EPI a vencer, CA vencido e troca de item entregue vencida...');

        if ($this->simulacao) {
            $this->warn('Modo simulação (--dry-run): nada será gravado.');
            $this->line('  A contagem é do que seria avaliado hoje, sem descontar o que já está na fila.');
        }

        $codigo = $this->paraCadaTenant(fn (Company $empresa): int => $this->verificarDaEmpresa($empresa));

        return $codigo === Command::SUCCESS && $this->algumRegistroFalhou ? Command::FAILURE : $codigo;
    }

    /**
     * Uma empresa, já dentro do tenant dela.
     *
     * @return int quantidade de avisos novos enfileirados nesta empresa (ou que
     *             seriam enfileirados, em simulação)
     */
    private function verificarDaEmpresa(Company $empresa): int
    {
        // Interruptor da rotina, mesmo critério de
        // `VerificarValidadesRegulatorias`: o módulo `epi` nasce desligado, e é
        // isso que permite subir o código com a rotina já agendada sem que
        // nenhum aviso saia antes de a empresa cadastrar os EPIs com CA e
        // validade reais e registrar as entregas em aberto.
        if (! $this->modulos->ativo('epi', $empresa)) {
            $this->line('  Módulo de EPI desligado nesta empresa: nada a verificar.');

            if ($this->simulacao) {
                $this->line('  Para conferir o volume de avisos, ligue o módulo e rode a simulação de novo.');
            }

            return 0;
        }

        $this->contagem = ['enfileirados' => 0, 'duplicados' => 0, 'sem_envio' => 0, 'simulados' => 0, 'erros' => 0];

        $this->certificadosAVencer();
        $this->trocasVencidas();

        if ($this->simulacao) {
            $this->line("  Avisos que sairiam: {$this->contagem['simulados']}");
            $this->line('  Nada foi gravado.');

            return $this->contagem['simulados'];
        }

        $this->line("  Avisos novos: {$this->contagem['enfileirados']}");
        $this->line("  Já estavam na fila: {$this->contagem['duplicados']}");
        $this->line('  Sem envio (falta de e-mail ou de template): '.$this->contagem['sem_envio']);
        $this->line("  Registros com erro: {$this->contagem['erros']}");

        return $this->contagem['enfileirados'];
    }

    // -----------------------------------------------------------------
    // Validade do CA no cadastro
    // -----------------------------------------------------------------

    private function certificadosAVencer(): void
    {
        $alerta = $this->alertas->certificadosAVencer();

        foreach ($alerta['vencendo'] as $janela) {
            $dias = (int) $janela['dias'];

            $this->line("  EPI com CA vencendo em até {$dias} dia(s): {$janela['itens']->count()}");

            foreach ($janela['itens'] as $item) {
                // O marco é a janela chamada (60, 30 ou 7), e não o
                // `dias_para_vencer` do item: é o que permite ao mesmo CA gerar
                // até três avisos ao longo do tempo, um por marco, sem duplicar
                // dentro da mesma janela.
                $this->enfileirarCertificado($item, EventosDeNotificacao::EPI_COM_CA_A_VENCER, (string) $dias);
            }
        }

        $vencidos = $alerta['vencidos'];

        $this->line("  EPI com CA já vencido (reenvio semanal até a renovação): {$vencidos->count()}");

        foreach ($vencidos as $item) {
            $this->enfileirarCertificado($item, EventosDeNotificacao::EPI_COM_CA_VENCIDO, $this->marcoSemanal());
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function enfileirarCertificado(array $item, string $evento, string $marco): void
    {
        /** @var PersonalProtectiveEquipment $epi */
        $epi = $item['epi'];

        $dias = (int) $item['dias_para_vencer'];

        $this->enfileirar(
            $evento,
            $epi,
            [
                'marco' => $marco,
                'variaveis' => [
                    'epi_nome' => $epi->nome,
                    'epi_tipo' => $this->tipoEmTexto($epi->tipo),
                    'epi_fabricante' => $epi->fabricante ?? 'não informado',
                    'ca_numero' => $epi->numero_ca ?? 'não informado',
                    'data_vencimento' => $this->dataEmTexto($item['validade']),
                    'dias_para_vencer' => $dias,
                    // Sai positivo: o e-mail diz "há 12 dia(s)", e não "há -12
                    // dia(s)". Mesmo critério de `VerificarValidadesRegulatorias`.
                    'dias_vencido' => abs($dias),
                ],
            ],
            "EPI #{$epi->id} ({$epi->nome}, CA ".($epi->numero_ca ?? 'sem número').", marco {$marco})"
        );
    }

    // -----------------------------------------------------------------
    // Troca do item entregue
    // -----------------------------------------------------------------

    private function trocasVencidas(): void
    {
        $trocas = $this->alertas->trocasVencidas();

        $this->line("  Entregas com troca vencida (reenvio semanal até a substituição): {$trocas->count()}");

        foreach ($trocas as $item) {
            /** @var PpeDelivery $entrega */
            $entrega = $item['entrega'];
            /** @var Technician $tecnico */
            $tecnico = $item['tecnico'];
            /** @var PersonalProtectiveEquipment $epi */
            $epi = $item['epi'];

            $this->enfileirar(
                EventosDeNotificacao::EPI_COM_TROCA_VENCIDA,
                $entrega,
                [
                    'marco' => $this->marcoSemanal(),
                    'variaveis' => [
                        'tecnico_nome' => $tecnico->name,
                        'epi_nome' => $epi->nome,
                        'epi_tipo' => $this->tipoEmTexto($epi->tipo),
                        'quantidade' => (int) $entrega->quantidade,
                        'data_entrega' => $this->dataEmTexto($entrega->entregue_em),
                        'data_troca' => $this->dataEmTexto($item['trocar_ate']),
                        'dias_vencido' => (int) $item['dias_vencido'],
                    ],
                ],
                "entrega #{$entrega->id} ({$epi->nome} com {$tecnico->name}, troca vencida há {$item['dias_vencido']} dia(s))"
            );
        }
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Tipo do EPI em texto legível: `protetor_auricular` vira "protetor
     * auricular", `oculos` vira "óculos".
     *
     * O enum é o vocabulário do banco, sem acento e com sublinhado, e não o do
     * e-mail: o texto que a empresa lê é o mesmo que ela encaminha ao técnico de
     * segurança do trabalho e, no limite, ao fiscal. Valor fora da lista cai no
     * tratamento genérico em vez de sumir, porque um tipo novo no enum não pode
     * apagar a informação do aviso enquanto ninguém lembra de atualizar aqui.
     *
     * A lista de tipos válidos continua sendo só uma:
     * `PersonalProtectiveEquipment::TIPOS`.
     */
    private function tipoEmTexto(?string $tipo): string
    {
        if ($tipo === null || $tipo === '') {
            return 'não informado';
        }

        return match ($tipo) {
            'oculos' => 'óculos',
            'protetor_auricular' => 'protetor auricular',
            'calcado' => 'calçado',
            default => str_replace('_', ' ', $tipo),
        };
    }

    /**
     * Data no formato brasileiro, sempre formatada no backend e no fuso do
     * negócio.
     */
    private function dataEmTexto(mixed $valor): string
    {
        return BusinessDate::paraFusoNegocio($valor)?->format('d/m/Y') ?? 'não informada';
    }

    /**
     * Marco semanal do reenvio do CA vencido e da troca vencida: muda a cada
     * sete dias corridos, contados no fuso do negócio a partir de uma época
     * fixa, e não a cada virada de semana do calendário. Uma pendência
     * descoberta numa sexta-feira reenvia sete dias depois, e não já na segunda
     * seguinte.
     *
     * Mesma implementação de `VerificarFrota::marcoSemanal()` e de
     * `VerificarEstoque::marcoSemanal()`, de propósito: as três rotinas repetem
     * o aviso pelo mesmo motivo (consequência legal ou sanitária), e um único
     * comportamento observável vale mais que uma abstração compartilhada entre
     * comandos que não têm outra relação entre si.
     */
    private function marcoSemanal(): string
    {
        $epoca = CarbonImmutable::createFromDate(1970, 1, 1, BusinessDate::fuso())->startOfDay();
        $diasDesdeAEpoca = (int) $epoca->diffInDays(BusinessDate::hoje());

        return 'semana-'.intdiv($diasDesdeAEpoca, 7);
    }

    // -----------------------------------------------------------------
    // Disparo individual
    // -----------------------------------------------------------------

    /**
     * Um disparo, com o erro isolado no registro que o causou.
     *
     * Sem este try/catch, um registro com dado inconsistente derrubaria a
     * empresa inteira em `OperaPorTenant` e os demais avisos do dia não
     * sairiam. Mesmo critério de `VerificarFrota::enfileirar()`.
     *
     * Em `--dry-run` a linha é impressa e nada é gravado: a simulação existe
     * para medir o volume da primeira passada, e enfileirar "só para conferir"
     * mandaria os e-mails que ela deveria evitar.
     *
     * @param  array<string, mixed>  $opcoes
     */
    private function enfileirar(string $evento, Model $referencia, array $opcoes, string $rotulo): void
    {
        if ($this->simulacao) {
            $this->contagem['simulados']++;
            $this->line("    [simulação] {$evento}: {$rotulo}");

            return;
        }

        try {
            $resultado = $this->notificacoes->enfileirar($evento, $referencia, $opcoes);

            match ($resultado['resultado']) {
                NotificationService::RESULTADO_ENFILEIRADA => $this->contagem['enfileirados']++,
                NotificationService::RESULTADO_DUPLICADA => $this->contagem['duplicados']++,
                default => $this->contagem['sem_envio']++,
            };
        } catch (Throwable $erro) {
            $this->registrarErro($erro, $evento, $rotulo);
        }
    }

    private function registrarErro(Throwable $erro, string $evento, string $rotulo): void
    {
        $this->contagem['erros']++;
        $this->algumRegistroFalhou = true;

        report($erro);

        Log::error('Falha ao enfileirar aviso de EPI.', [
            'evento' => $evento,
            'registro' => $rotulo,
            'mensagem' => $erro->getMessage(),
        ]);

        $this->error("  Falha no {$rotulo} ({$evento}): {$erro->getMessage()}");
        $this->line('  Os demais registros continuam sendo processados.');
    }
}
