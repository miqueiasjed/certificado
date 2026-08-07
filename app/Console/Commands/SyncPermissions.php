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
                // Fora do padrão "recurso-acao" das demais linhas deste
                // arquivo, de propósito: nome definido no Plano 13 (Task 13.3)
                // para a família de permissões de assinatura em campo, com
                // "os." como prefixo. Única porta de correção de uma OS já
                // assinada (WorkOrderSignatureService::corrigirComJustificativa);
                // fica reservada ao papel administrador, sem tratamento
                // especial no RolesAndPermissionsSeeder, porque o
                // administrador já recebe automaticamente todo o catálogo.
                // A Task 13.4 acrescenta a irmã desta, `os.assinar`, com
                // atribuição explícita ao papel técnico.
                'os.corrigir_assinada',
                // Task 13.4: libera a coleta da assinatura do cliente
                // (`WorkOrderSignatureService::coletar`) e o registro da
                // recusa (`registrarRecusa`), pelo painel. Técnico recebe
                // esta permissão explicitamente no RolesAndPermissionsSeeder;
                // administrador já a recebe por ter o catálogo inteiro.
                'os.assinar',
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
                // Estorno de recebimento já lançado no caixa (Plano 18, Task
                // 18.4, `SettlementService::estornar()`). O arquivo da task
                // pedia "financeiro.estornar", com ponto; vale o nome no
                // padrão "recurso-acao" do resto do catálogo, mesma correção
                // já aplicada às Tasks 14.6, 16.4 e 17.7. Por começar com
                // "financeiro-", entra no papel `financeiro` pelo filtro por
                // prefixo do RolesAndPermissionsSeeder, e no `administrador`,
                // que recebe o catálogo inteiro; nenhum outro papel alcança
                // dinheiro já lançado.
                'financeiro-estornar',
                // Títulos a receber e a pagar, e o plano de contas (Plano 18,
                // Task 18.7). O arquivo da task pedia "financeiro.titulos" e
                // "financeiro.plano_de_contas", com ponto; valem os nomes
                // abaixo, no padrão "recurso-acao" do resto do catálogo, mesma
                // correção já aplicada às Tasks 14.6, 16.4, 17.7 e 18.4.
                //
                // Três permissões e não uma, pelo alcance de cada ação:
                //
                // - `financeiro-titulos` cobre listar, criar e cancelar título
                //   dos dois lados (receber e pagar) e alterar o valor de um
                //   recorrente. Mexe no que a empresa tem a cobrar e a pagar,
                //   sem tocar em dinheiro já lançado.
                // - `financeiro-baixar` cobre a baixa, que é o que transforma
                //   "o cliente pagou" em dinheiro no caixa. Separada de
                //   `financeiro-titulos` porque quem cadastra a cobrança não é
                //   necessariamente quem confirma o recebimento no extrato.
                // - `financeiro-plano-de-contas` cobre a categorização. Mudar
                //   a árvore de categorias reescreve todo relatório por
                //   categoria, e por isso não anda junto do cadastro de título.
                //
                // O estorno reaproveita `financeiro-estornar`, já criado na
                // Task 18.4: é a mesma ação (desfazer dinheiro já lançado) dos
                // dois lados do caixa, e duplicar o nome só criaria a chance de
                // um dos dois ficar de fora de um papel.
                //
                // As três começam com "financeiro-", então entram no papel
                // `financeiro` pelo filtro por prefixo do
                // `RolesAndPermissionsSeeder`, e no `administrador`, que recebe
                // o catálogo inteiro. Nenhuma termina em "-ver", então o papel
                // `leitura` não alcança nenhuma delas, pelo mesmo critério que
                // já mantém `financeiro-ver` fora da leitura.
                'financeiro-titulos',
                'financeiro-baixar',
                'financeiro-plano-de-contas',
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
            // Estoque com lote, validade e custo (Plano 17, Task 17.7). O
            // arquivo da task pedia "estoque.ver"/"estoque.movimentar"/
            // "estoque.inventariar", mas o catálogo inteiro usa "recurso-acao"
            // com hífen (a família "os.*" das Tasks 13.3/13.4 é a única
            // exceção, por um motivo documentado ali), então valem os nomes
            // abaixo. Mesma correção já aplicada às Tasks 14.6 e 16.4.
            //
            // Três permissões e não uma: ver o saldo é rotina de quem atende o
            // telefone; movimentar altera o razão do estoque, que vale perante
            // fiscalização; e o inventário reescreve o saldo por contagem
            // física. "estoque-ver" termina em "-ver" e por isso entra no papel
            // leitura pelo filtro genérico do RolesAndPermissionsSeeder; as
            // outras duas ficam só com administrador, que recebe o catálogo
            // inteiro. O técnico não recebe nenhuma das três nesta entrega: o
            // consumo em campo é baixa automática pela OS (Task 17.4), não
            // movimentação manual.
            'estoque' => [
                'estoque-ver',
                'estoque-movimentar',
                'estoque-inventariar',
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
            'solicitacoes' => [
                'solicitacao-ver',
                'solicitacao-responder',
            ],
            'assinatura' => [
                'assinatura-gerenciar',
            ],
            // Pedidos de horário do agendamento online (Plano 16, Task
            // 16.4). O arquivo da task pedia "agendamentos.ver"/
            // "agendamentos.responder" para os papéis "administrador e
            // coordenador", mas este projeto não tem papel "coordenador"
            // (os cinco existentes são administrador, financeiro, comercial,
            // tecnico, leitura - ver `RolesAndPermissionsSeeder`) e o
            // catálogo inteiro usa o padrão "recurso-acao" com hífen, não
            // ponto (a família "os.*" das Tasks 13.3/13.4 é a única exceção,
            // por um motivo documentado ali). Mesma correção já aplicada à
            // Task 14.6 (`notificacao-ver`/`notificacao-gerenciar` em vez de
            // "notificacoes.ver"): nome no padrão do restante do catálogo, e
            // "agendamento-responder" fica só com administrador (automático,
            // ele recebe o catálogo inteiro); "agendamento-ver" entra em
            // leitura pelo filtro genérico de permissão terminada em "-ver"
            // que já existe em RolesAndPermissionsSeeder, sem precisar tocar
            // no seeder.
            'agendamentos' => [
                'agendamento-ver',
                'agendamento-responder',
            ],
            // Cobrança recorrente (Plano 19, Task 19.6): listagem e
            // conciliação, emissão/reemissão/cancelamento de boleto e Pix, e a
            // configuração da credencial do gateway e da régua. O arquivo da
            // task pedia "cobrancas.ver"/"cobrancas.emitir"/
            // "cobrancas.configurar", com ponto e no plural; valem os nomes
            // abaixo, no padrão "recurso-acao" no singular do resto do
            // catálogo (mesmo critério de "estoque-ver", "agendamento-ver"),
            // mesma correção já aplicada às Tasks 14.6, 16.4, 17.7 e 18.4.
            //
            // `cobranca-configurar` fica de fora do prefixo "cobranca-" que o
            // papel financeiro recebe (RolesAndPermissionsSeeder): salvar a
            // credencial do gateway de pagamento do tenant é ação reservada
            // ao administrador, mesmo critério de `assinatura-gerenciar`.
            'cobrancas' => [
                'cobranca-ver',
                'cobranca-emitir',
                'cobranca-configurar',
            ],
            'fiscal' => [
                'fiscal-ver',
                'fiscal-emitir',
                'fiscal-cancelar',
                'fiscal-configurar',
            ],
            // Planta versionada do endereço e posicionamento dos dispositivos
            // sobre ela (Plano 21, Task 21.4). O arquivo da task pedia
            // "plantas.gerenciar", com ponto; vale o nome abaixo, no padrão
            // "recurso-acao" no singular do resto do catálogo (mesmo critério
            // de "comodo-gerenciar", que também cobre enviar e editar um
            // recurso inteiro com uma permissão só), mesma correção já
            // aplicada às Tasks 14.6, 16.4, 17.7, 18.4 e 19.6.
            //
            // Uma permissão só, e não separada em enviar/posicionar: as duas
            // ações (enviar a planta e posicionar dispositivo sobre ela)
            // andam juntas na mesma tela, e separar criaria a chance de um
            // papel enviar planta sem poder posicionar nada nela, o que a
            // deixaria sempre incompleta. Fica de fora do filtro por sufixo
            // "-ver" do papel leitura e de qualquer prefixo do
            // RolesAndPermissionsSeeder, então só administrador recebe (mesmo
            // critério de "assinatura-gerenciar" e "cobranca-configurar");
            // estender a técnico ou comercial é decisão de produto para uma
            // task futura de tela, não desta.
            'plantas' => [
                'planta-gerenciar',
            ],
            // Relatório de monitoramento consolidado por período (Plano 21,
            // Task 21.5). O arquivo da task pedia "monitoramento.ver"/
            // "monitoramento.gerar"/"monitoramento.publicar", com ponto;
            // valem os nomes abaixo, no padrão "recurso-acao" no singular do
            // resto do catálogo, mesma correção já aplicada às Tasks 14.6,
            // 16.4, 17.7, 18.4, 19.6 e 21.4 (`planta-gerenciar`).
            //
            // Três permissões, com alcance crescente de risco:
            //
            // - `monitoramento-ver`: a visão ao vivo e a lista/detalhe de
            //   relatório já gerado. Leitura pura, sem custo nem dado
            //   pessoal de terceiro na resposta (ver `PortalRelatorioController`).
            //   Termina em "-ver", então entra automaticamente no papel
            //   `leitura` pelo filtro genérico de `RolesAndPermissionsSeeder`
            //   (mesmo critério de `fiscal-ver`), sem precisar de entrada
            //   própria lá.
            // - `monitoramento-gerar`: congela o consolidado num
            //   `MonitoringReport` novo. Mexe em dado novo (nunca no que já
            //   foi entregue), e por isso entra explicitamente no papel
            //   `tecnico` em `RolesAndPermissionsSeeder`: é o técnico que
            //   acompanha o monitoramento em campo quem tem o contexto para
            //   decidir que o período está pronto para consolidar.
            // - `monitoramento-publicar`: alterna a visibilidade do
            //   relatório no portal do cliente. Deliberadamente mais
            //   restrita que `monitoramento-gerar` (ver `MonitoringReportController::publicar()`):
            //   publicar é o que entrega o documento para a auditoria do
            //   cliente, e essa decisão fica só com `administrador` (que
            //   recebe o catálogo inteiro), mesmo critério de
            //   `fiscal-cancelar`/`cobranca-configurar` - nenhum prefixo ou
            //   sufixo de `RolesAndPermissionsSeeder` a alcança de propósito.
            'monitoramento' => [
                'monitoramento-ver',
                'monitoramento-gerar',
                'monitoramento-publicar',
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
