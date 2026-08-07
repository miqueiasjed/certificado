<?php

namespace Database\Seeders;

use App\Console\Commands\SyncPermissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    private const GUARD = 'web';

    /**
     * Garante os cinco papéis do sistema e sincroniza as permissões de cada
     * um a partir do catálogo único de SyncPermissions::catalogo(). Nenhuma
     * permissão é redeclarada aqui, e nenhum papel é atribuído a usuário.
     *
     * `assinatura-gerenciar` (Task 7.8) não entra em nenhum dos filtros por
     * prefixo ou sufixo abaixo (não termina em "-ver" nem começa com
     * "financeiro-"/"pagamento-"), então só `administrador` recebe a
     * permissão, mesmo critério de `financeiro-ver`/`pagamento-ver`: mudar a
     * forma de pagamento da empresa perante o provedor não é ação de papel
     * nenhum além do administrativo.
     */
    public function run(): void
    {
        Artisan::call('permissions:sync');

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $catalogo = SyncPermissions::catalogo();
        $todasAsPermissoes = collect($catalogo)->flatten()->values();

        $this->sincronizarPapel('administrador', $todasAsPermissoes->all());
        $this->sincronizarPapel('financeiro', $this->permissoesFinanceiro($todasAsPermissoes));
        $this->sincronizarPapel('comercial', $this->permissoesComercial($todasAsPermissoes));
        $this->sincronizarPapel('tecnico', $this->permissoesTecnico());
        $this->sincronizarPapel('leitura', $this->permissoesLeitura($todasAsPermissoes));
    }

    /**
     * Permissões do papel financeiro: todo o módulo financeiro e de
     * pagamentos, mais visualização do necessário para dar contexto ao
     * financeiro sem alcançar edição ou exclusão de outros módulos.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $todasAsPermissoes
     * @return array<int, string>
     */
    private function permissoesFinanceiro($todasAsPermissoes): array
    {
        // 'cobranca-' entra no mesmo filtro de prefixo do financeiro e do
        // pagamento (Plano 19, Task 19.6), mas `cobranca-configurar` é
        // retirada logo abaixo: salvar a credencial do gateway de pagamento
        // do tenant é ação reservada ao administrador, mesmo critério de
        // `assinatura-gerenciar` (que também não entra em nenhum papel além
        // dele).
        $porPrefixo = $todasAsPermissoes
            ->filter(fn (string $nome) => Str::startsWith($nome, ['financeiro-', 'pagamento-', 'cobranca-']))
            ->reject(fn (string $nome) => $nome === 'cobranca-configurar')
            ->all();

        $especificas = [
            'orcamento-ver',
            'contrato-ver',
            'cliente-ver',
            'ordem-servico-ver',
            'certificado-ver',
            'cadastro-ver',
        ];

        return array_values(array_unique(array_merge($porPrefixo, $especificas)));
    }

    /**
     * Permissões do papel comercial: cadastro completo de clientes,
     * endereços, orçamentos, contratos e serviços agendados, mais o
     * necessário de ordem de serviço e certificado para fechar uma venda.
     * Sem nenhuma permissão financeira ou de pagamento.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $todasAsPermissoes
     * @return array<int, string>
     */
    private function permissoesComercial($todasAsPermissoes): array
    {
        $porPrefixo = $todasAsPermissoes
            ->filter(fn (string $nome) => Str::startsWith($nome, [
                'cliente-',
                'endereco-',
                'orcamento-',
                'contrato-',
                'servico-agendado-',
            ]))
            ->all();

        $especificas = [
            'ordem-servico-ver',
            'ordem-servico-criar',
            'ordem-servico-editar',
            'certificado-ver',
            'certificado-emitir',
            'dispositivo-ver',
            'tecnico-ver',
            'cadastro-ver',
        ];

        return array_values(array_unique(array_merge($porPrefixo, $especificas)));
    }

    /**
     * Permissões do papel tecnico: execução de ordem de serviço, gestão de
     * cômodo e visualização do necessário em campo. Sem financeiro e sem
     * nenhuma permissão de exclusão.
     *
     * @return array<int, string>
     */
    private function permissoesTecnico(): array
    {
        return [
            'ordem-servico-ver',
            'ordem-servico-executar',
            'comodo-gerenciar',
            'dispositivo-ver',
            'dispositivo-editar',
            'cliente-ver',
            'endereco-ver',
            'certificado-ver',
            'cadastro-ver',
            // Task 13.4: o técnico coleta a assinatura do cliente e registra
            // a recusa. `os.corrigir_assinada` (a correção de uma OS já
            // assinada) fica só com administrador, e por isso não entra
            // aqui: técnico corrigir a própria OS assinada anularia o
            // efeito do travamento.
            'os.assinar',
            // Plano 21, Task 21.5: é o técnico que acompanha o monitoramento
            // em campo quem tem o contexto para decidir que o período está
            // pronto para consolidar, e para isso precisa ver a visão ao
            // vivo antes de gerar. `monitoramento-publicar` fica de fora de
            // propósito - publicar entrega o relatório para a auditoria do
            // cliente, e essa decisão fica só com administrador (ver
            // `SyncPermissions::catalogo()`).
            'monitoramento-ver',
            'monitoramento-gerar',
        ];
    }

    /**
     * Permissões do papel leitura: todas as permissões terminadas em "-ver"
     * do catálogo, exceto financeiro-ver, pagamento-ver, cobranca-ver e
     * usuario-ver. As três primeiras porque leitura não alcança dinheiro
     * (cobrança recorrente, Plano 19, Task 19.6, entra no mesmo critério de
     * financeiro-ver/pagamento-ver); a quarta porque a tela de usuários é
     * administração da empresa, não consulta de dado.
     *
     * auditoria-ver e acesso-log-ver também ficam de fora: o histórico mostra
     * o valor antes e depois de campo de qualquer módulo, inclusive financeiro,
     * e o registro de acesso expõe quem entrou e de onde. Só administrador lê.
     *
     * @param  \Illuminate\Support\Collection<int, string>  $todasAsPermissoes
     * @return array<int, string>
     */
    private function permissoesLeitura($todasAsPermissoes): array
    {
        return $todasAsPermissoes
            ->filter(fn (string $nome) => Str::endsWith($nome, '-ver'))
            ->reject(fn (string $nome) => in_array($nome, [
                'financeiro-ver',
                'pagamento-ver',
                'cobranca-ver',
                'usuario-ver',
                'auditoria-ver',
                'acesso-log-ver',
            ], true))
            ->values()
            ->all();
    }

    /**
     * Cria o papel se não existir e sincroniza suas permissões. Rodar de
     * novo corrige um papel que ficou com permissão a mais, sem apagar o
     * papel nem remover permissão de outro papel.
     *
     * @param  array<int, string>  $permissoes
     */
    private function sincronizarPapel(string $nome, array $permissoes): void
    {
        $papel = Role::firstOrCreate(['name' => $nome, 'guard_name' => self::GUARD]);

        $papel->syncPermissions(
            Permission::whereIn('name', $permissoes)
                ->where('guard_name', self::GUARD)
                ->get()
        );
    }
}
