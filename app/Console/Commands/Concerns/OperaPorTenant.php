<?php

namespace App\Console\Commands\Concerns;

use App\Models\Company;
use App\Support\TenantAtual;
use Closure;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

/**
 * Tenant explícito em comando artisan.
 *
 * Comando de terminal não tem usuário autenticado, e portanto não tem empresa.
 * Sem esta trait, uma rotina diária roda com `TenantAtual::id()` devolvendo
 * `null`, o escopo global de `BelongsToCompany` não filtra nada e a rotina
 * percorre a tabela inteira de todas as empresas de uma vez. Isso funciona
 * enquanto existe um tenant só, e no dia em que existir o segundo passa a
 * misturar dado de empresas concorrentes sem nenhum sinal na saída.
 *
 * A trait resolve isso trocando "uma passada sem escopo" por "uma passada por
 * empresa, dentro do tenant dela":
 *
 * - `--company=ID` roda em uma empresa só.
 * - `--todas`, ou nenhuma opção, percorre todas as empresas cadastradas. Sem
 *   opção é o padrão de propósito: é o que mantém o cron atual funcionando sem
 *   precisar mexer na linha agendada em `bootstrap/app.php`.
 *
 * Cada volta do laço roda dentro de `TenantAtual::comTenant()`, que restaura o
 * tenant anterior no `finally`, inclusive quando a empresa estoura exceção. Um
 * tenant que falha não derruba os outros: o erro é capturado, reportado e
 * aparece no resumo final, e o laço segue.
 *
 * Ordem de avaliação do escopo
 * ----------------------------
 * O Eloquent aplica escopo global quando a consulta é executada, não quando o
 * builder é montado. Monte e execute a consulta dentro do callback. Builder
 * guardado em propriedade do comando e reaproveitado entre voltas do laço sai
 * com o filtro da volta em que for executado, não da volta em que foi criado.
 *
 * Enquanto a Task 4.8 não aplica a trait `BelongsToCompany` aos models, este
 * laço roda a mesma consulta sem filtro uma vez por empresa. Com o único tenant
 * de hoje o resultado é idêntico ao de antes. Com dois tenants e sem a 4.8, a
 * rotina processaria o banco inteiro duas vezes: as duas tasks precisam estar
 * no mesmo deploy do segundo tenant.
 *
 * Registro em `scheduled_task_runs` não passa por aqui: quem grava são os
 * ganchos `before`/`then` de `bootstrap/app.php`, por fora do comando, e eles
 * continuam vendo uma execução só, com o código de saída consolidado.
 *
 * Como usar:
 *
 * ```php
 * class MinhaRotina extends Command
 * {
 *     use OperaPorTenant;
 *
 *     public function handle(): int
 *     {
 *         return $this->paraCadaTenant(fn (): int => $this->processarEmpresa());
 *     }
 * }
 * ```
 */
trait OperaPorTenant
{
    /**
     * Acrescenta `--company=` e `--todas` à definição do comando.
     *
     * O gancho é o `configure()` do Symfony, chamado por
     * `parent::__construct()` antes de o Laravel adicionar as opções lidas de
     * `$signature`. As duas listas apenas somam na mesma `InputDefinition`, e
     * por isso convivem.
     *
     * Esta é a razão de não injetar as opções por um construtor na trait:
     * comando com dependência no construtor (como `contratos:gerar-visitas`)
     * define o próprio `__construct()`, que sobrescreveria o da trait e faria
     * as duas opções sumirem em silêncio. Comando que precise sobrescrever
     * `configure()` tem que chamar `parent::configure()`, ou perde as opções
     * do mesmo jeito.
     */
    protected function configure(): void
    {
        parent::configure();

        $this->getDefinition()->addOption(new InputOption(
            'company',
            null,
            InputOption::VALUE_REQUIRED,
            'Id da empresa em que a rotina deve rodar. Sem esta opção, roda em todas.'
        ));

        $this->getDefinition()->addOption(new InputOption(
            'todas',
            null,
            InputOption::VALUE_NONE,
            'Roda em todas as empresas cadastradas. É o comportamento padrão; a opção existe para deixar a intenção explícita.'
        ));
    }

    /**
     * Executa o callback uma vez por empresa da execução, cada uma dentro do
     * tenant dela.
     *
     * O callback recebe a `Company` da volta e devolve a quantidade processada,
     * que entra no resumo. Devolver `null` é aceito para rotina que não tem uma
     * contagem única a informar.
     *
     * Devolve o código de saída do comando: `FAILURE` quando alguma empresa
     * falhou, `INVALID` quando as opções vieram erradas, `SUCCESS` no resto. A
     * assinatura combinada na task previa `void`; devolver o código de saída
     * evita que cada comando repita a mesma consolidação de erro, e é o que faz
     * `routines:status` enxergar a falha de um tenant.
     *
     * @param  Closure(Company): ?int  $callback
     */
    protected function paraCadaTenant(Closure $callback): int
    {
        $empresas = $this->empresasDaExecucao();

        if ($empresas === null) {
            return Command::INVALID;
        }

        if ($empresas->isEmpty()) {
            $this->error(
                'Nenhuma empresa cadastrada: a rotina não tem em qual tenant rodar. '
                .'Cadastre a empresa em Configurações antes de agendar as rotinas.'
            );

            return Command::FAILURE;
        }

        $resumo = [];

        foreach ($empresas as $empresa) {
            $resumo[] = $this->processarEmpresaIsolando($empresa, $callback);
        }

        $this->imprimirResumoPorEmpresa($resumo);

        $houveErro = collect($resumo)->contains(fn (array $linha): bool => $linha['erro'] !== null);

        return $houveErro ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Roda uma empresa dentro do tenant dela, sem deixar o erro dela alcançar
     * as demais.
     *
     * `comTenant()` devolve o tenant anterior no `finally`, então a volta
     * seguinte do laço começa limpa mesmo quando esta aqui estoura.
     *
     * @param  Closure(Company): ?int  $callback
     * @return array{empresa: string, processados: ?int, erro: ?string}
     */
    private function processarEmpresaIsolando(Company $empresa, Closure $callback): array
    {
        $rotulo = $this->rotuloDaEmpresa($empresa);

        $this->newLine();
        $this->line("=== {$rotulo} ===");

        try {
            $processados = TenantAtual::comTenant(
                (int) $empresa->id,
                fn (): ?int => $callback($empresa)
            );

            return ['empresa' => $rotulo, 'processados' => $processados, 'erro' => null];
        } catch (Throwable $erro) {
            // O erro vai para o log da aplicação além da saída do comando: em
            // execução agendada ninguém está olhando o terminal, e o texto
            // gravado em `scheduled_task_runs` é só a saída consolidada.
            report($erro);

            $this->error("Falha em {$rotulo}: {$erro->getMessage()}");
            $this->line('  As demais empresas continuam sendo processadas.');

            return ['empresa' => $rotulo, 'processados' => null, 'erro' => $erro->getMessage()];
        }
    }

    /**
     * Empresas desta execução, ou `null` quando as opções vieram erradas.
     *
     * @return Collection<int, Company>|null
     */
    private function empresasDaExecucao(): ?Collection
    {
        $informada = $this->option('company');
        $todas = (bool) $this->option('todas');

        if ($informada !== null && $todas) {
            $this->error('Use --company= ou --todas, nunca as duas: elas dizem coisas diferentes sobre onde a rotina roda.');

            return null;
        }

        if ($informada === null) {
            return Company::query()->orderBy('id')->get();
        }

        if (! ctype_digit((string) $informada) || (int) $informada < 1) {
            $this->error("--company precisa ser o id inteiro positivo de uma empresa. Valor recebido: \"{$informada}\".");

            return null;
        }

        $empresa = Company::query()->find((int) $informada);

        if ($empresa === null) {
            $this->error("Empresa #{$informada} não existe. Nada foi processado.");

            return null;
        }

        return collect([$empresa]);
    }

    /**
     * @param  array<int, array{empresa: string, processados: ?int, erro: ?string}>  $resumo
     */
    private function imprimirResumoPorEmpresa(array $resumo): void
    {
        $this->newLine();
        $this->line('Resumo por empresa:');

        foreach ($resumo as $linha) {
            if ($linha['erro'] !== null) {
                $this->line("  {$linha['empresa']}: erro - {$linha['erro']}");

                continue;
            }

            $this->line($linha['processados'] === null
                ? "  {$linha['empresa']}: concluída"
                : "  {$linha['empresa']}: {$linha['processados']} registro(s) processado(s)");
        }

        $comErro = collect($resumo)->filter(fn (array $linha): bool => $linha['erro'] !== null)->count();

        $this->line('  Empresas processadas: '.count($resumo));
        $this->line("  Empresas com erro: {$comErro}");
    }

    private function rotuloDaEmpresa(Company $empresa): string
    {
        $nome = trim((string) ($empresa->name ?? ''));

        return "Empresa #{$empresa->id} (".($nome === '' ? 'sem nome' : $nome).')';
    }
}
