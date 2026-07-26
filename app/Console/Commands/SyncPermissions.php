<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class SyncPermissions extends Command
{
    protected $signature = 'permissions:sync
                            {--dry-run : Simula a execução e mostra o que seria feito, sem gravar nada}
                            {--force : Remove do banco as permissões órfãs, que não estão mais no catálogo}';

    protected $description = 'Sincroniza a tabela de permissões com o catálogo definido em código, organizado por módulo.';

    private const GUARD = 'web';

    public function handle(): int
    {
        $simulacao = (bool) $this->option('dry-run');
        $forcar = (bool) $this->option('force');

        $this->info('Sincronizando permissões...');
        $this->line($simulacao
            ? 'Modo simulação (--dry-run): nada será gravado.'
            : 'Modo aplicação: as permissões serão gravadas.');

        $catalogo = self::catalogo();
        $nomesCatalogo = collect($catalogo)->flatten()->all();

        $existentes = Permission::query()
            ->where('guard_name', self::GUARD)
            ->pluck('name')
            ->all();

        $novas = array_values(array_diff($nomesCatalogo, $existentes));
        $orfas = array_values(array_diff($existentes, $nomesCatalogo));

        $this->criarPermissoes($novas, $simulacao);
        $this->tratarOrfas($orfas, $simulacao, $forcar);

        if (! $simulacao) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        $this->imprimirResumo($catalogo, $novas, $orfas, $forcar, $simulacao);

        return Command::SUCCESS;
    }

    /**
     * Fonte única do catálogo de permissões do sistema, agrupado por módulo.
     * Seeder e testes consomem este método; nenhum outro lugar do sistema
     * redeclara nome de permissão.
     *
     * @return array<string, array<int, string>>
     */
    public static function catalogo(): array
    {
        return [
            'clientes' => [
                'cliente-ver',
                'cliente-criar',
                'cliente-editar',
                'cliente-excluir',
            ],
            'enderecos' => [
                'endereco-ver',
                'endereco-criar',
                'endereco-editar',
                'endereco-excluir',
            ],
            'comodos' => [
                'comodo-gerenciar',
            ],
            'dispositivos' => [
                'dispositivo-ver',
                'dispositivo-criar',
                'dispositivo-editar',
                'dispositivo-excluir',
            ],
            'ordens_servico' => [
                'ordem-servico-ver',
                'ordem-servico-criar',
                'ordem-servico-editar',
                'ordem-servico-excluir',
                'ordem-servico-executar',
            ],
            'servicos_agendados' => [
                'servico-agendado-ver',
                'servico-agendado-criar',
                'servico-agendado-editar',
                'servico-agendado-excluir',
            ],
            'certificados' => [
                'certificado-ver',
                'certificado-emitir',
                'certificado-editar',
                'certificado-excluir',
            ],
            'contratos' => [
                'contrato-ver',
                'contrato-criar',
                'contrato-editar',
                'contrato-excluir',
            ],
            'orcamentos' => [
                'orcamento-ver',
                'orcamento-criar',
                'orcamento-editar',
                'orcamento-excluir',
                'orcamento-converter',
            ],
            'financeiro' => [
                'financeiro-ver',
                'financeiro-exportar',
                'financeiro-lancamento-criar',
                'financeiro-lancamento-editar',
                'financeiro-lancamento-excluir',
                'financeiro-saida-criar',
                'financeiro-saida-editar',
                'financeiro-saida-excluir',
            ],
            'pagamentos' => [
                'pagamento-ver',
                'pagamento-registrar',
                'pagamento-editar',
                'pagamento-excluir',
                'pagamento-reabrir',
            ],
            'cadastros' => [
                'cadastro-ver',
                'produto-criar',
                'produto-editar',
                'produto-excluir',
                'servico-gerenciar',
                'tipo-evento-gerenciar',
                'tipo-isca-gerenciar',
                'principio-ativo-gerenciar',
                'grupo-quimico-gerenciar',
                'antidoto-gerenciar',
                'registro-orgao-gerenciar',
                'evento-dispositivo-gerenciar',
                'avistamento-praga-gerenciar',
            ],
            'tecnicos' => [
                'tecnico-ver',
                'tecnico-criar',
                'tecnico-editar',
                'tecnico-excluir',
            ],
            'administracao' => [
                'usuario-ver',
                'usuario-criar',
                'usuario-editar',
                'usuario-desativar',
                'empresa-configurar',
                'auditoria-ver',
                'acesso-log-ver',
            ],
            'notificacoes' => [
                'notificacao-ver',
                'notificacao-gerenciar',
            ],
        ];
    }

    /**
     * Cria com firstOrCreate toda permissão do catálogo que ainda não existe
     * no banco. Em modo simulação, nada é gravado.
     *
     * @param  array<int, string>  $novas
     */
    private function criarPermissoes(array $novas, bool $simulacao): void
    {
        if ($simulacao) {
            return;
        }

        foreach ($novas as $nome) {
            Permission::firstOrCreate(['name' => $nome, 'guard_name' => self::GUARD]);
        }
    }

    /**
     * Permissão órfã (existe no banco, saiu do catálogo) é apenas avisada.
     * Apagar automaticamente removeria o vínculo de papel em produção, então
     * a remoção só acontece com --force, informado explicitamente.
     *
     * @param  array<int, string>  $orfas
     */
    private function tratarOrfas(array $orfas, bool $simulacao, bool $forcar): void
    {
        if ($simulacao || ! $forcar || $orfas === []) {
            return;
        }

        Permission::query()
            ->where('guard_name', self::GUARD)
            ->whereIn('name', $orfas)
            ->delete();
    }

    /**
     * @param  array<string, array<int, string>>  $catalogo
     * @param  array<int, string>  $novas
     * @param  array<int, string>  $orfas
     */
    private function imprimirResumo(array $catalogo, array $novas, array $orfas, bool $forcar, bool $simulacao): void
    {
        $this->newLine();
        $this->line($simulacao ? 'Permissões que seriam criadas:' : 'Permissões criadas:');

        if ($novas === []) {
            $this->line('  nenhuma');
        }

        foreach ($novas as $nome) {
            $this->line("  {$nome}");
        }

        $this->newLine();

        if ($orfas === []) {
            $this->line('Nenhuma permissão órfã encontrada.');
        } else {
            $rotulo = $forcar && ! $simulacao
                ? 'Permissões órfãs removidas (--force):'
                : 'Permissões órfãs (existem no banco, fora do catálogo, use --force para remover):';

            $this->warn($rotulo);

            foreach ($orfas as $nome) {
                $this->line("  {$nome}");
            }
        }

        $this->newLine();
        $this->line('Contagem por módulo:');

        $total = 0;

        foreach ($catalogo as $modulo => $permissoes) {
            $quantidade = count($permissoes);
            $total += $quantidade;
            $this->line("  {$modulo}: {$quantidade}");
        }

        $this->line("Total: {$total}");

        $this->info($simulacao
            ? 'Simulação concluída. Nenhuma permissão foi gravada.'
            : 'Permissões sincronizadas com sucesso!');
    }
}
