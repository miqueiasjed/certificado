<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductBatch;
use App\Services\NotificationService;
use App\Services\StockAlertService;
use App\Services\StockService;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rotina diária que avisa a empresa antes de faltar produto e antes de o lote
 * vencer na prateleira (Plano 17, Task 17.6).
 *
 * Dois avisos, duas cadências de repetição
 * -----------------------------------------
 * - `estoque_abaixo_do_minimo`: um aviso por produto. A chave de idempotência
 *   do `NotificationService` (evento + destinatário + produto, sem marco)
 *   garante que rodar a rotina todo dia não repita o mesmo lembrete enquanto o
 *   produto continuar abaixo do mínimo: o e-mail é o empurrão inicial, e o
 *   painel de estoque é quem mostra a pendência todo santo dia depois disso.
 * - `lote_proximo_do_vencimento`: um aviso por lote e por marco (as janelas de
 *   60, 30 e 7 dias de `StockAlertService::lotesVencendo()`), e o vencido é a
 *   exceção que reenvia semanalmente até o descarte ser registrado. É a única
 *   das duas com consequência sanitária, e por isso o silêncio depois do
 *   primeiro aviso não é aceitável aqui: `marcoSemanal()` muda o marco a cada
 *   sete dias corridos, o suficiente para a chave de idempotência liberar um
 *   aviso novo.
 *
 * Isolamento por tenant e por registro
 * -------------------------------------
 * Mesmo padrão de `EnfileirarAvisosDiarios` (Task 14.4): laço por empresa
 * (`OperaPorTenant`, que também restaura o tenant anterior e isola o erro de
 * uma empresa das demais) e cada disparo com o próprio try/catch, para que um
 * produto ou um lote com dado inconsistente não cale o aviso dos outros nem
 * derrube a empresa seguinte.
 */
class VerificarEstoque extends Command
{
    use OperaPorTenant;

    protected $signature = 'estoque:verificar';

    protected $description = 'Enfileira avisos de estoque abaixo do mínimo e de lote próximo do vencimento ou vencido (Plano 17).';

    /**
     * O Service e os dois auxiliares chegam pelo `handle()`, e não pelo
     * construtor, para não serem resolvidos em toda chamada de artisan do
     * sistema. Ficam em propriedade porque os blocos de disparo os usam.
     */
    private ?NotificationService $notificacoes = null;

    private ?StockAlertService $alertas = null;

    private ?StockService $estoque = null;

    /**
     * Contagem da empresa corrente, zerada a cada volta do laço por tenant.
     *
     * @var array<string, int>
     */
    private array $contagem = [];

    /**
     * Algum registro, em alguma empresa, terminou com erro.
     *
     * Mesmo critério de `EnfileirarAvisosDiarios::$algumRegistroFalhou`: sem
     * este sinal a rotina terminaria com código de sucesso mesmo com avisos
     * que deixaram de sair, e a verificação de rotinas (Task 14.5) não teria
     * como saber.
     */
    private bool $algumRegistroFalhou = false;

    public function handle(
        NotificationService $notificacoes,
        StockAlertService $alertas,
        StockService $estoque
    ): int {
        $this->notificacoes = $notificacoes;
        $this->alertas = $alertas;
        $this->estoque = $estoque;

        $this->info('Verificando estoque abaixo do mínimo e lotes próximos do vencimento...');

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

        $this->produtosAbaixoDoMinimo();
        $this->lotesProximosDoVencimento();

        $this->line("  Avisos novos: {$this->contagem['enfileirados']}");
        $this->line("  Já estavam na fila: {$this->contagem['duplicados']}");
        $this->line('  Sem envio (recusa do cliente, falta de e-mail ou de template): '.$this->contagem['sem_envio']);
        $this->line("  Registros com erro: {$this->contagem['erros']}");

        return $this->contagem['enfileirados'];
    }

    // -----------------------------------------------------------------
    // Estoque abaixo do mínimo
    // -----------------------------------------------------------------

    private function produtosAbaixoDoMinimo(): void
    {
        $produtos = $this->alertas->abaixoDoMinimo();

        $this->line("  Produtos abaixo do estoque mínimo: {$produtos->count()}");

        foreach ($produtos as $linha) {
            /** @var Product $produto */
            $produto = $linha['produto'];

            $this->enfileirar(
                EventosDeNotificacao::ESTOQUE_ABAIXO_DO_MINIMO,
                $produto,
                [
                    'variaveis' => [
                        'produto_nome' => $produto->name,
                        'saldo_atual' => $this->numero($linha['saldo']),
                        'estoque_minimo' => $this->numero($linha['minimo']),
                        'locais' => $this->localizacaoEmTexto($this->estoque->saldoPorLocal($produto)),
                    ],
                ],
                "produto #{$produto->id} ({$produto->name})"
            );
        }
    }

    // -----------------------------------------------------------------
    // Lotes próximos do vencimento ou vencidos
    // -----------------------------------------------------------------

    private function lotesProximosDoVencimento(): void
    {
        $alertaDeLotes = $this->alertas->lotesVencendo();

        foreach ($alertaDeLotes['vencendo'] as $janela) {
            $dias = (int) $janela['dias'];

            $this->line("  Lotes com saldo vencendo em até {$dias} dia(s): {$janela['lotes']->count()}");

            foreach ($janela['lotes'] as $item) {
                // O marco é a janela chamada (60, 30 ou 7), não o
                // `dias_para_vencer` do item: é o que permite ao mesmo lote
                // gerar até três avisos distintos ao longo do tempo, um por
                // marco atingido, sem duplicar enquanto ele permanecer dentro
                // da mesma janela.
                $this->enfileirarLote($item, (string) $dias);
            }
        }

        $vencidos = $alertaDeLotes['vencidos'];

        $this->line("  Lotes já vencidos com saldo (reenvio semanal até o descarte): {$vencidos->count()}");

        foreach ($vencidos as $item) {
            $this->enfileirarLote($item, $this->marcoSemanal());
        }
    }

    /**
     * @param  array{lote: ProductBatch, validade: string, dias_para_vencer: int, saldo: float, locais: array<int, array{stock_location_id: int, nome: string, quantidade: float}>}  $item
     */
    private function enfileirarLote(array $item, string $marco): void
    {
        $lote = $item['lote'];

        $this->enfileirar(
            EventosDeNotificacao::LOTE_PROXIMO_DO_VENCIMENTO,
            $lote,
            [
                'marco' => $marco,
                'variaveis' => [
                    'produto_nome' => $lote->product?->name,
                    'lote_codigo' => $lote->lote,
                    'data_vencimento' => $item['validade'],
                    'dias_para_vencer' => $item['dias_para_vencer'],
                    'saldo_atual' => $this->numero($item['saldo']),
                    'locais' => $this->localizacaoEmTexto($item['locais']),
                ],
            ],
            "lote #{$lote->id} ({$lote->lote})"
        );
    }

    /**
     * Marco semanal do reenvio do lote vencido: muda a cada sete dias
     * corridos, contados no fuso do negócio a partir de uma época fixa, e não
     * a cada virada de semana do calendário. Um lote vencido descoberto numa
     * sexta-feira reenvia sete dias depois, e não já na segunda seguinte.
     *
     * É a exceção de "um aviso por marco" da especificação: sem o marco
     * variar, a chave de idempotência do primeiro aviso seria a mesma para
     * sempre, e o lote vencido ficaria esquecido na prateleira depois do
     * primeiro e-mail.
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
     * Sem este try/catch, um produto ou um lote com dado inconsistente
     * derrubaria a empresa inteira em `OperaPorTenant` e os demais avisos do
     * dia não sairiam. O erro é contado, impresso e registrado em log, e o
     * laço segue. Mesmo critério de `EnfileirarAvisosDiarios::enfileirar()`.
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

        Log::error('Falha ao enfileirar aviso de estoque.', [
            'evento' => $evento,
            'registro' => $rotulo,
            'mensagem' => $erro->getMessage(),
        ]);

        $this->error("  Falha no {$rotulo} ({$evento}): {$erro->getMessage()}");
        $this->line('  Os demais registros continuam sendo processados.');
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Saldo por local em texto, para o aviso dizer também onde o produto
     * está: "o quê" (estoque baixo, lote vencendo) sem "onde" obriga quem
     * recebe o e-mail a abrir o sistema só para descobrir de onde tirar ou
     * repor.
     *
     * Aceita tanto a quebra por local de `StockService::saldoPorLocal()`
     * (array com `nome`/`quantidade`) quanto a de
     * `BatchSelectionService::vencendoEm()`/`vencidos()` (mesmo formato), sem
     * duas implementações.
     *
     * @param  iterable<int, array{nome?: string, quantidade?: float}>  $linhas
     */
    private function localizacaoEmTexto(iterable $linhas): string
    {
        $partes = [];

        foreach ($linhas as $linha) {
            $nome = (string) ($linha['nome'] ?? '');
            $quantidade = (float) ($linha['quantidade'] ?? 0);

            if ($nome === '') {
                continue;
            }

            $partes[] = $nome.': '.$this->numero($quantidade);
        }

        return $partes === [] ? 'sem saldo em local nenhum' : implode('; ', $partes);
    }

    /**
     * Número no formato brasileiro, sem casas decimais supérfluas: "3", e não
     * "3,0000". As colunas de quantidade têm 4 casas para caber fração de
     * mililitro, mas a maioria dos saldos é inteira, e arrastar zeros no
     * e-mail só polui a leitura.
     */
    private function numero(float $valor): string
    {
        $formatado = rtrim(rtrim(number_format($valor, 4, ',', '.'), '0'), ',');

        return $formatado === '' ? '0' : $formatado;
    }
}
