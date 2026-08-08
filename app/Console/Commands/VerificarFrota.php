<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Company;
use App\Models\Vehicle;
use App\Models\VehicleDocument;
use App\Models\VehicleMaintenance;
use App\Services\Fleet\AlertaDeFrotaService;
use App\Services\NotificationService;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rotina diária que avisa antes de a manutenção preventiva vencer e antes de o
 * documento do veículo perder a validade (Plano 27, Task 27.3).
 *
 * Três avisos, três cadências
 * ---------------------------
 * - `manutencao_de_veiculo_a_vencer`: um aviso por manutenção e por marco. O
 *   marco é `{criterio}-{janela}` (`data-30`, `data-7`, `km-1000`, `km-200`),
 *   e o critério vem escolhido pelo `AlertaDeFrotaService`: quando data e
 *   quilometragem estão preenchidas, só a que vier primeiro gera aviso, para a
 *   mesma manutenção não mandar dois e-mails dizendo a mesma coisa.
 * - `documento_de_veiculo_a_vencer`: um aviso por documento e por marco (60, 30
 *   e 7 dias). O vencido é a exceção e reenvia **semanalmente** até a validade
 *   ser atualizada, mesmo critério do lote vencido do Plano 17: circular com
 *   licenciamento vencido é infração, e o silêncio depois do primeiro aviso não
 *   resolve nada.
 * - `quilometragem_de_veiculo_desatualizada`: um aviso por veículo, sem marco.
 *   Mesmo critério de `estoque_abaixo_do_minimo`: o e-mail é o empurrão
 *   inicial, e a ficha do veículo mostra a pendência depois disso.
 *
 * Rodar duas vezes no mesmo dia não duplica nada: quem garante isso é a chave
 * de idempotência do `NotificationService` (evento + destinatário + registro +
 * marco), não uma trava desta rotina.
 *
 * Isolamento por tenant e por registro
 * -------------------------------------
 * Mesmo padrão de `VerificarEstoque` (Task 17.6): laço por empresa
 * (`OperaPorTenant`, que restaura o tenant anterior e isola o erro de uma
 * empresa das demais) e cada disparo com o próprio try/catch, para que um
 * veículo com dado inconsistente não cale o aviso dos outros.
 *
 * Nasce parada
 * ------------
 * O módulo `frota` nasce desligado e esta rotina nasce sem agendamento em
 * `RotinasAgendadas::DIARIAS`, de propósito: ligar a verificação antes de os
 * veículos estarem cadastrados e com pelo menos três abastecimentos de tanque
 * cheio geraria uma leva de avisos sobre dado incompleto, que é a forma mais
 * rápida de a empresa aprender a ignorar o alerta. O agendamento entra junto
 * com a liberação do módulo para o tenant que pedir.
 */
class VerificarFrota extends Command
{
    use OperaPorTenant;

    protected $signature = 'frota:verificar';

    protected $description = 'Enfileira avisos de manutenção de veículo a vencer, documento a vencer ou vencido e quilometragem desatualizada (Plano 27).';

    /**
     * O Service e o auxiliar chegam pelo `handle()`, e não pelo construtor,
     * para não serem resolvidos em toda chamada de artisan do sistema.
     */
    private ?NotificationService $notificacoes = null;

    private ?AlertaDeFrotaService $alertas = null;

    /**
     * Contagem da empresa corrente, zerada a cada volta do laço por tenant.
     *
     * @var array<string, int>
     */
    private array $contagem = [];

    /**
     * Algum registro, em alguma empresa, terminou com erro.
     *
     * Mesmo critério de `VerificarEstoque::$algumRegistroFalhou`: sem este
     * sinal a rotina terminaria com código de sucesso mesmo com avisos que
     * deixaram de sair, e a verificação de rotinas (Task 14.5) não teria como
     * saber.
     */
    private bool $algumRegistroFalhou = false;

    public function handle(NotificationService $notificacoes, AlertaDeFrotaService $alertas): int
    {
        $this->notificacoes = $notificacoes;
        $this->alertas = $alertas;

        $this->info('Verificando manutenções a vencer, documentos de veículo e quilometragem desatualizada...');

        $codigo = $this->paraCadaTenant(fn (Company $empresa): int => $this->verificarDaEmpresa());

        return $codigo === Command::SUCCESS && $this->algumRegistroFalhou ? Command::FAILURE : $codigo;
    }

    /**
     * Uma empresa, já dentro do tenant dela.
     *
     * @return int quantidade de avisos novos enfileirados nesta empresa
     */
    private function verificarDaEmpresa(): int
    {
        $this->contagem = ['enfileirados' => 0, 'duplicados' => 0, 'sem_envio' => 0, 'erros' => 0];

        $this->manutencoesAVencer();
        $this->documentosAVencer();
        $this->quilometragemDesatualizada();

        $this->line("  Avisos novos: {$this->contagem['enfileirados']}");
        $this->line("  Já estavam na fila: {$this->contagem['duplicados']}");
        $this->line('  Sem envio (falta de e-mail ou de template): '.$this->contagem['sem_envio']);
        $this->line("  Registros com erro: {$this->contagem['erros']}");

        return $this->contagem['enfileirados'];
    }

    // -----------------------------------------------------------------
    // Manutenção preventiva
    // -----------------------------------------------------------------

    private function manutencoesAVencer(): void
    {
        $alertas = $this->alertas->manutencoesAVencer();

        $this->line("  Manutenções preventivas em janela de alerta: {$alertas->count()}");

        foreach ($alertas as $alerta) {
            /** @var VehicleMaintenance $manutencao */
            $manutencao = $alerta['manutencao'];
            /** @var Vehicle $veiculo */
            $veiculo = $alerta['veiculo'];

            // O marco carrega o critério além da janela: sem ele, a janela de
            // 30 dias e a de 1.000 km colidiriam na mesma chave de
            // idempotência e uma das duas nunca sairia.
            $marco = $alerta['criterio'].'-'.$alerta['janela'];

            $this->enfileirar(
                EventosDeNotificacao::MANUTENCAO_DE_VEICULO_A_VENCER,
                $manutencao,
                [
                    'marco' => $marco,
                    'variaveis' => [
                        'veiculo_placa' => $veiculo->placa,
                        'veiculo_modelo' => trim($veiculo->marca.' '.$veiculo->modelo),
                        'manutencao_descricao' => $manutencao->descricao,
                        'criterio' => $alerta['criterio'] === AlertaDeFrotaService::CRITERIO_DATA
                            ? 'data'
                            : 'quilometragem',
                        'restante_texto' => $this->restanteEmTexto($alerta),
                        'proxima_em_data' => $alerta['proxima_em_data'] ?? 'não definida',
                        'proxima_em_km' => $alerta['proxima_em_km'] !== null
                            ? $alerta['proxima_em_km'].' km'
                            : 'não definida',
                        'km_atual' => (int) $veiculo->km_atual,
                    ],
                ],
                "manutenção #{$manutencao->id} ({$manutencao->descricao}, veículo {$veiculo->placa}, marco {$marco})"
            );
        }
    }

    /**
     * "12 dia(s)" ou "340 km", conforme o critério escolhido pelo Service.
     *
     * Valor negativo é dito por extenso ("vencida há 3 dia(s)", "300 km além
     * do previsto") em vez de aparecer como número com sinal: aviso que exige
     * do leitor interpretar um menos é aviso que vai ser lido errado.
     *
     * @param  array<string, mixed>  $alerta
     */
    private function restanteEmTexto(array $alerta): string
    {
        if ($alerta['criterio'] === AlertaDeFrotaService::CRITERIO_DATA) {
            $dias = (int) $alerta['dias_para_vencer'];

            return $dias >= 0 ? "{$dias} dia(s)" : 'vencida há '.abs($dias).' dia(s)';
        }

        $km = (int) $alerta['km_restantes'];

        return $km >= 0 ? "{$km} km" : abs($km).' km além do previsto';
    }

    // -----------------------------------------------------------------
    // Documentos do veículo
    // -----------------------------------------------------------------

    private function documentosAVencer(): void
    {
        $alerta = $this->alertas->documentosAVencer();

        foreach ($alerta['vencendo'] as $janela) {
            $dias = (int) $janela['dias'];

            $this->line("  Documentos de veículo vencendo em até {$dias} dia(s): {$janela['itens']->count()}");

            foreach ($janela['itens'] as $item) {
                // O marco é a janela chamada (60, 30 ou 7), não o
                // `dias_para_vencer` do item: é o que permite ao mesmo
                // documento gerar até três avisos ao longo do tempo, um por
                // marco, sem duplicar dentro da mesma janela.
                $this->enfileirarDocumento($item, (string) $dias);
            }
        }

        $vencidos = $alerta['vencidos'];

        $this->line("  Documentos de veículo já vencidos (reenvio semanal até a renovação): {$vencidos->count()}");

        foreach ($vencidos as $item) {
            $this->enfileirarDocumento($item, $this->marcoSemanal());
        }
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function enfileirarDocumento(array $item, string $marco): void
    {
        /** @var VehicleDocument $documento */
        $documento = $item['documento'];
        /** @var Vehicle $veiculo */
        $veiculo = $item['veiculo'];

        $this->enfileirar(
            EventosDeNotificacao::DOCUMENTO_DE_VEICULO_A_VENCER,
            $documento,
            [
                'marco' => $marco,
                'variaveis' => [
                    'veiculo_placa' => $veiculo->placa,
                    'veiculo_modelo' => trim($veiculo->marca.' '.$veiculo->modelo),
                    'documento_tipo' => $documento->tipo,
                    'documento_numero' => $documento->numero ?? 'não informado',
                    'data_vencimento' => $item['validade'],
                    'dias_para_vencer' => $item['dias_para_vencer'],
                ],
            ],
            "documento #{$documento->id} ({$documento->tipo}, veículo {$veiculo->placa}, marco {$marco})"
        );
    }

    // -----------------------------------------------------------------
    // Quilometragem desatualizada
    // -----------------------------------------------------------------

    private function quilometragemDesatualizada(): void
    {
        $veiculos = $this->alertas->quilometragemDesatualizada();

        $this->line("  Veículos com quilometragem desatualizada: {$veiculos->count()}");

        foreach ($veiculos as $item) {
            /** @var Vehicle $veiculo */
            $veiculo = $item['veiculo'];

            $this->enfileirar(
                EventosDeNotificacao::QUILOMETRAGEM_DE_VEICULO_DESATUALIZADA,
                $veiculo,
                [
                    'variaveis' => [
                        'veiculo_placa' => $veiculo->placa,
                        'veiculo_modelo' => trim($veiculo->marca.' '.$veiculo->modelo),
                        'km_atual' => (int) $veiculo->km_atual,
                        'dias_sem_abastecimento' => $item['dias_sem_abastecimento'],
                        'ultimo_abastecimento' => $item['ultimo_abastecimento'] ?? 'nenhum registrado',
                    ],
                ],
                "veículo #{$veiculo->id} ({$veiculo->placa})"
            );
        }
    }

    /**
     * Marco semanal do reenvio do documento vencido: muda a cada sete dias
     * corridos, contados no fuso do negócio a partir de uma época fixa, e não
     * a cada virada de semana do calendário. Um documento vencido descoberto
     * numa sexta-feira reenvia sete dias depois, e não já na segunda seguinte.
     *
     * Mesma implementação de `VerificarEstoque::marcoSemanal()`, de propósito:
     * as duas rotinas repetem o mesmo aviso pelo mesmo motivo (consequência
     * legal ou sanitária), e um único comportamento observável vale mais que
     * uma abstração compartilhada entre dois comandos que não têm outra
     * relação entre si.
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
     * Sem este try/catch, um veículo com dado inconsistente derrubaria a
     * empresa inteira em `OperaPorTenant` e os demais avisos do dia não
     * sairiam. Mesmo critério de `VerificarEstoque::enfileirar()`.
     *
     * @param  array<string, mixed>  $opcoes
     */
    private function enfileirar(string $evento, Model $referencia, array $opcoes, string $rotulo): void
    {
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

        Log::error('Falha ao enfileirar aviso de frota.', [
            'evento' => $evento,
            'registro' => $rotulo,
            'mensagem' => $erro->getMessage(),
        ]);

        $this->error("  Falha no {$rotulo} ({$evento}): {$erro->getMessage()}");
        $this->line('  Os demais registros continuam sendo processados.');
    }
}
