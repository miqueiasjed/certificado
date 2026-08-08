<?php

namespace App\Console\Commands;

use App\Models\Address;
use App\Services\Geo\GeocodificacaoService;
use App\Support\Geo\ResultadoGeo;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Geocodificação em lote dos endereços (Plano 22, Task 22.2).
 *
 * `php artisan enderecos:geocodificar --dry-run --limite=20` percorre
 * endereços sem coordenada, consulta o provedor configurado
 * (`App\Services\Geo\ProvedorDeGeocodificacao`, Nominatim por padrão) e
 * imprime o relatório por precisão. Sem `--dry-run`, grava de verdade.
 *
 * Decisões que espelham o estilo de `BackfillAcesso` e
 * `BackfillCodigoDeDispositivo` (comando de terminal, sem tenant resolvido):
 *
 * - **Reexecutável, ignora quem já tem.** Candidato é
 *   `geocodificado_em IS NULL`: endereço já processado (com coordenada, com
 *   precisão "cidade" ou sem resultado nenhum) não entra de novo, exceto com
 *   `--forcar`. O motivo de usar `geocodificado_em`, e não
 *   `latitude IS NULL`, para decidir quem é candidato está em
 *   `GeocodificacaoService`: uma busca que respondeu "não encontrei nada"
 *   marca `geocodificado_em` mesmo sem gravar coordenada, porque repetir a
 *   mesma consulta tende a repetir o mesmo resultado - gastar uma consulta
 *   de novo sem motivo é exatamente o que a task pede para evitar.
 * - **Sem tenant explícito.** Endereço é `App\Models\Address`, que aplica o
 *   escopo global de empresa (`BelongsToCompany`), mas um comando de
 *   terminal não tem usuário autenticado nem empresa corrente. Sem tenant
 *   resolvido o escopo global não filtra nada (comportamento documentado em
 *   `App\Support\TenantAtual`), então esta rotina processa os endereços de
 *   todas as empresas na mesma execução, cada linha carregando o
 *   `company_id` que já tinha. Não há regra de negócio aqui que dependa de
 *   qual empresa é qual (a consulta ao provedor é só texto de endereço), e é
 *   o mesmo raciocínio que `BackfillCodigoDeDispositivo` já usa para
 *   `devices`.
 * - **Limite e pausa configuráveis, nunca implícitos.** `--limite` existe
 *   porque o provedor cobra por consulta (Nominatim é gratuito hoje, mas o
 *   princípio vale igual para um provedor pago amanhã); `--pausa-ms` existe
 *   porque a política de uso do Nominatim pede no máximo ~1 requisição por
 *   segundo (ver o cabeçalho de `App\Services\Geo\ProvedorNominatim`).
 * - **Precisão "cidade" e "não encontrado" são pendência, nunca sucesso
 *   silencioso.** O relatório final separa as duas de "exata"/"aproximada" e
 *   lista os endereços, para conferência manual.
 */
class GeocodificarEnderecos extends Command
{
    private const LIMITE_PADRAO = 100;

    private const PAUSA_MS_PADRAO = 1000;

    /**
     * Resultados que representam pendência (sem coordenada útil para
     * roteiro), na ordem em que aparecem no relatório.
     *
     * @var array<int, string>
     */
    private const RESULTADOS_DE_PENDENCIA = [
        ResultadoGeo::PRECISAO_CIDADE,
        GeocodificacaoService::RESULTADO_NAO_ENCONTRADO,
    ];

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'enderecos:geocodificar
                            {--dry-run : Mostra o relatório por precisão e não grava nada}
                            {--limite='.self::LIMITE_PADRAO.' : Quantidade máxima de endereços consultados nesta execução}
                            {--pausa-ms='.self::PAUSA_MS_PADRAO.' : Pausa entre chamadas ao provedor, em milissegundos}
                            {--forcar : Reconsulta endereços já processados nesta execução, inclusive com correção manual}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Geocodifica endereços sem coordenada, com relatório por precisão e reconsulta explícita via --forcar.';

    public function __construct(private readonly GeocodificacaoService $geocodificacao)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $simulacao = (bool) $this->option('dry-run');
        $forcar = (bool) $this->option('forcar');

        $limite = $this->lerInteiro('limite', self::LIMITE_PADRAO, minimo: 1);
        $pausaMs = $this->lerInteiro('pausa-ms', self::PAUSA_MS_PADRAO, minimo: 0);

        if ($limite === null || $pausaMs === null) {
            return Command::INVALID;
        }

        $this->imprimirCabecalho($simulacao, $forcar, $limite, $pausaMs);

        $enderecos = $this->candidatos($forcar, $limite);

        if ($enderecos->isEmpty()) {
            $this->info($forcar
                ? 'Nenhum endereço cadastrado. Nada a fazer.'
                : 'Nenhum endereço pendente: todos já foram processados ao menos uma vez. Use --forcar para reconsultar.');

            return Command::SUCCESS;
        }

        if ($forcar) {
            $this->warn('--forcar reconsulta inclusive endereços com correção manual (origem_coordenada = manual). Elas serão sobrescritas pelo resultado do provedor.');
            $this->newLine();
        }

        [$relatorio, $pendencias] = $this->processar($enderecos, $simulacao, $forcar, $pausaMs);

        $this->newLine();
        $this->imprimirRelatorio($relatorio, $enderecos->count(), $simulacao);
        $this->imprimirPendencias($pendencias);

        return Command::SUCCESS;
    }

    /**
     * Lê uma opção numérica, validando que é inteiro e respeita o mínimo.
     * Devolve `null` (e já imprime o erro) quando o valor é inválido.
     */
    private function lerInteiro(string $opcao, int $padrao, int $minimo): ?int
    {
        $valor = $this->option($opcao);

        if ($valor === null) {
            return $padrao;
        }

        if (! ctype_digit((string) $valor) || (int) $valor < $minimo) {
            $this->error("--{$opcao} precisa ser um inteiro maior ou igual a {$minimo}. Valor recebido: \"{$valor}\".");

            return null;
        }

        return (int) $valor;
    }

    private function imprimirCabecalho(bool $simulacao, bool $forcar, int $limite, int $pausaMs): void
    {
        $this->info('Geocodificação de endereços (Plano 22, Task 22.2)');
        $this->line($simulacao
            ? 'Modo simulação (--dry-run): o provedor é consultado de verdade, mas nada é gravado.'
            : 'Modo aplicação: coordenada, precisão e origem são gravadas em cada endereço processado.');
        $this->line("Limite desta execução: {$limite} endereço(s). Pausa entre chamadas: {$pausaMs}ms.");
        $this->line($forcar
            ? 'Reconsulta forçada (--forcar): endereços já processados entram de novo, inclusive correção manual.'
            : 'Só endereços nunca processados (geocodificado_em nulo) entram nesta execução.');
        $this->newLine();
    }

    /**
     * @return Collection<int, Address>
     */
    private function candidatos(bool $forcar, int $limite): Collection
    {
        $query = Address::query()->orderBy('id');

        if (! $forcar) {
            $query->whereNull('geocodificado_em');
        }

        return $query->limit($limite)->get();
    }

    /**
     * Processa cada endereço candidato, respeitando a pausa configurada
     * entre chamadas ao provedor (nenhuma pausa depois do último item).
     *
     * @param  Collection<int, Address>  $enderecos
     * @return array{0: array<string, int>, 1: array<int, array{endereco: Address, motivo: string}>}
     */
    private function processar(Collection $enderecos, bool $simulacao, bool $forcar, int $pausaMs): array
    {
        $relatorio = [];
        $pendencias = [];
        $total = $enderecos->count();
        $indice = 0;

        foreach ($enderecos as $endereco) {
            $indice++;

            $resultado = $simulacao
                ? $this->geocodificacao->simular($endereco, $forcar)
                : $this->geocodificacao->geocodificar($endereco, $forcar);

            $relatorio[$resultado] = ($relatorio[$resultado] ?? 0) + 1;

            if (in_array($resultado, self::RESULTADOS_DE_PENDENCIA, true)) {
                $pendencias[] = ['endereco' => $endereco, 'motivo' => $resultado];
            }

            // Feedback leve de progresso: um ponto por endereço processado,
            // sem tabela nem log por linha, que com --limite alto poluiria o
            // terminal. `--dry-run` também mostra pontos, porque a consulta
            // ao provedor acontece de verdade e pode demorar.
            $this->output->write('.');

            if ($indice < $total && $pausaMs > 0) {
                usleep($pausaMs * 1000);
            }
        }

        $this->newLine(2);

        return [$relatorio, $pendencias];
    }

    /**
     * @param  array<string, int>  $relatorio
     */
    private function imprimirRelatorio(array $relatorio, int $processados, bool $simulacao): void
    {
        $this->line($simulacao ? 'Relatório da simulação:' : 'Relatório da execução:');

        $linhas = [
            ['Exata', $relatorio[ResultadoGeo::PRECISAO_EXATA] ?? 0, 'sucesso'],
            ['Aproximada', $relatorio[ResultadoGeo::PRECISAO_APROXIMADA] ?? 0, 'sucesso'],
            ['Cidade', $relatorio[ResultadoGeo::PRECISAO_CIDADE] ?? 0, 'PENDÊNCIA'],
            ['Não encontrado', $relatorio[GeocodificacaoService::RESULTADO_NAO_ENCONTRADO] ?? 0, 'PENDÊNCIA'],
            ['Ignorado (correção manual)', $relatorio[GeocodificacaoService::RESULTADO_IGNORADO_MANUAL] ?? 0, 'mantido'],
            ['Erro do provedor (tenta de novo na próxima execução)', $relatorio[GeocodificacaoService::RESULTADO_ERRO_PROVEDOR] ?? 0, 'não processado'],
        ];

        $this->table(['Precisão / situação', 'Quantidade', 'Como conta'], $linhas);

        $this->line("Total de endereços examinados: {$processados}.");
        $this->line($simulacao
            ? 'Simulação concluída. Nada foi gravado.'
            : 'Execução concluída.');
    }

    /**
     * @param  array<int, array{endereco: Address, motivo: string}>  $pendencias
     */
    private function imprimirPendencias(array $pendencias): void
    {
        $this->newLine();
        $this->line('Pendências para correção manual (precisão "cidade" ou sem coordenada):');

        if ($pendencias === []) {
            $this->line('  nenhuma');

            return;
        }

        $rotulos = [
            ResultadoGeo::PRECISAO_CIDADE => 'precisão apenas no nível da cidade',
            GeocodificacaoService::RESULTADO_NAO_ENCONTRADO => 'endereço não encontrado pelo provedor',
        ];

        $this->table(
            ['Endereço', 'Cliente', 'Motivo'],
            array_map(function (array $item) use ($rotulos): array {
                /** @var Address $endereco */
                $endereco = $item['endereco'];

                return [
                    "#{$endereco->id} {$endereco->short_address}",
                    $endereco->client_id ? "#{$endereco->client_id}" : '-',
                    $rotulos[$item['motivo']] ?? $item['motivo'],
                ];
            }, $pendencias)
        );

        $this->line('  Total de pendências: '.count($pendencias));
    }
}
