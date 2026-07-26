<?php

namespace App\Support;

use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Resolvedor único da empresa (tenant) corrente.
 *
 * Todo o isolamento entre empresas passa por aqui. A trait
 * `App\Models\Concerns\BelongsToCompany` pergunta a esta classe qual é o tenant
 * antes de filtrar qualquer consulta e antes de preencher `company_id` na
 * criação de um registro. Nenhum outro lugar do código deve inferir o tenant
 * por conta própria.
 *
 * Ordem de resolução
 * ------------------
 * 1. Tenant fixado explicitamente por `definir()` ou `comTenant()`. É o caminho
 *    obrigatório fora de requisição HTTP (comando artisan, seeder, job de fila)
 *    e o caminho do "assumir tenant" do super admin, no Plano 5.
 * 2. `company_id` do usuário autenticado. É o caminho normal de toda requisição
 *    web do sistema.
 * 3. `null`, quando não há nem um nem outro.
 *
 * O nível 3 significa "sem tenant resolvido", e nele o escopo global não filtra
 * nada. Isso é deliberado: migration, seeder e boa parte da suíte de testes
 * rodam sem usuário autenticado e precisam enxergar o banco inteiro. É também
 * o motivo de a Task 4.11 (teste de vazamento entre tenants) ser obrigatória:
 * ela é a rede que pega o caso em que o tenant não foi resolvido em uma
 * requisição real.
 *
 * Estado estático
 * ---------------
 * O tenant explícito e o sinalizador de escopo vivem em propriedades estáticas,
 * com vida útil do processo. Em requisição HTTP isso é um ciclo de request. Em
 * worker de fila, que é um processo longo, o estado precisa ser limpo entre
 * jobs, e é exatamente o que a Task 4.9 vai amarrar usando `comTenant()`, que
 * restaura o valor anterior sozinho.
 *
 * Ponto de extensão para a Task 4.9 (fila e CLI)
 * ----------------------------------------------
 * Esta classe já funciona fora de requisição HTTP: nada aqui depende de sessão,
 * de request ou de rota. O que a Task 4.9 acrescenta é quem chama `definir()` e
 * `comTenant()` em cada contexto, sem alterar esta classe:
 *
 * - Job de fila: carrega `company_id` no payload e envolve o `handle()` em
 *   `TenantAtual::comTenant($this->companyId, fn () => ...)`, ou usa um
 *   middleware de job que faz isso para todos.
 * - Comando artisan de tenant único: opção `--empresa=` que chama `definir()`.
 * - Rotina que roda para todos os tenants: itera as empresas e aplica
 *   `comTenant()` a cada volta, nunca uma consulta sem escopo seguida de
 *   filtro em memória.
 *
 * Utilitário de infraestrutura: sem regra de domínio e sem acesso a banco.
 */
final class TenantAtual
{
    /**
     * Tenant fixado explicitamente. `null` significa "resolver pelo usuário
     * autenticado", não "sem tenant".
     */
    private static ?int $tenantExplicito = null;

    /**
     * Profundidade do modo sem escopo. É contador, e não booleano, para que
     * chamadas aninhadas de `semEscopo()` não religuem o escopo cedo demais ao
     * sair da mais interna.
     */
    private static int $nivelSemEscopo = 0;

    /**
     * Id da empresa corrente, ou `null` quando não há tenant resolvido.
     */
    public static function id(): ?int
    {
        if (self::$tenantExplicito !== null) {
            return self::$tenantExplicito;
        }

        return self::idDoUsuarioAutenticado();
    }

    /**
     * Fixa o tenant explicitamente, acima do usuário autenticado.
     *
     * Quem usa: comando artisan, seeder, job de fila (Task 4.9) e o "assumir
     * tenant" do super admin (Plano 5). Em código de requisição normal isto não
     * deve aparecer: lá o tenant vem do usuário autenticado.
     *
     * Prefira `comTenant()` sempre que a troca for temporária. `definir()` sem
     * `limpar()` em processo longo, como worker de fila, vaza o tenant de um
     * job para o próximo.
     */
    public static function definir(int $companyId): void
    {
        if ($companyId < 1) {
            throw new InvalidArgumentException(
                "Id de empresa inválido para o tenant corrente: {$companyId}."
            );
        }

        self::$tenantExplicito = $companyId;
    }

    /**
     * Descarta o tenant explícito e volta a resolver pelo usuário autenticado.
     */
    public static function limpar(): void
    {
        self::$tenantExplicito = null;
    }

    /**
     * Existe tenant fixado explicitamente?
     *
     * Serve para o código de fila e de comando conferir que o contexto foi
     * aplicado antes de operar, em vez de descobrir pelo dado gravado errado.
     */
    public static function definido(): bool
    {
        return self::$tenantExplicito !== null;
    }

    /**
     * Executa o callback dentro do tenant informado e restaura o valor anterior
     * ao sair, inclusive quando o callback lança exceção.
     *
     * Forma preferida de trocar de tenant. Aninhamento é seguro: cada nível
     * devolve o que encontrou.
     */
    public static function comTenant(int $companyId, Closure $callback): mixed
    {
        $anterior = self::$tenantExplicito;

        self::definir($companyId);

        try {
            return $callback();
        } finally {
            self::$tenantExplicito = $anterior;
        }
    }

    /**
     * PORTA DE EMERGÊNCIA: executa o callback com o escopo global por empresa
     * desligado.
     *
     * O que isto permite, e por isso o nome: dentro do callback, toda consulta
     * de todo model que usa `BelongsToCompany` passa a enxergar os registros de
     * todas as empresas ao mesmo tempo. É a única saída do isolamento que o
     * sistema tem, e usar errado significa mostrar dado de uma dedetizadora
     * para a concorrente dela.
     *
     * Quem pode usar:
     *
     * - Área de plataforma do super admin (Plano 5), que por definição não
     *   pertence a empresa nenhuma, com aviso visível na interface e registro
     *   em auditoria.
     * - Rotina de manutenção da plataforma: relatório consolidado, migração de
     *   dado, conferência de integridade entre tenants.
     *
     * Quem não pode: código de domínio. Nenhum controller, service, model ou
     * job de negócio deste sistema deve chamar isto. Se aparecer em código de
     * domínio, precisa de comentário justificando na própria linha, e a revisão
     * trata como exceção a ser removida.
     *
     * Para operar dentro de uma empresa específica, o certo é `comTenant()`,
     * que mantém o isolamento ligado apontando para outro tenant. `semEscopo()`
     * é para quando a operação é genuinamente entre empresas.
     *
     * O desligamento vale só durante o callback e é restaurado mesmo em caso de
     * exceção. Ele não afeta o preenchimento de `company_id` na criação, que
     * continua vindo do tenant corrente: ler entre empresas é uma decisão,
     * gravar sem empresa é sempre bug.
     *
     * Atenção ao momento: os escopos globais do Eloquent são aplicados quando a
     * consulta é executada, não quando o builder é criado. Um builder montado
     * fora e executado dentro do callback sai sem o filtro, e o contrário
     * também vale. Monte e execute a consulta dentro do callback.
     */
    public static function semEscopo(Closure $callback): mixed
    {
        self::$nivelSemEscopo++;

        try {
            return $callback();
        } finally {
            self::$nivelSemEscopo--;
        }
    }

    /**
     * O escopo global por empresa está ativo neste instante?
     *
     * Lido pela trait `BelongsToCompany` a cada consulta.
     */
    public static function escopoAtivo(): bool
    {
        return self::$nivelSemEscopo === 0;
    }

    /**
     * Id da empresa corrente, exigindo que exista.
     *
     * Usar onde operar sem tenant é bug e não conveniência: geração de
     * numeração, emissão de documento, criação de registro em rotina de fila.
     * A exceção é preferível a gravar no tenant errado em silêncio.
     */
    public static function exigirId(): int
    {
        $empresa = self::id();

        if ($empresa === null) {
            throw new RuntimeException(
                'Nenhuma empresa resolvida para esta operação. '
                .'Em requisição HTTP isso significa usuário sem company_id. '
                .'Fora de requisição (comando artisan, seeder, job de fila) o tenant precisa ser '
                .'informado explicitamente com TenantAtual::definir() ou TenantAtual::comTenant().'
            );
        }

        return $empresa;
    }

    /**
     * `company_id` do usuário autenticado, quando houver.
     *
     * Precisa sobreviver a contexto sem autenticação nenhuma: comando artisan,
     * seeder, job de fila e migration rodam sem guard utilizável, e resolver o
     * tenant nunca pode derrubar a execução. Daí a checagem do container e o
     * try/catch: na dúvida, "sem tenant".
     *
     * A Task 4.7 troca `Company::current()` para resolver a empresa por este
     * mesmo caminho. Enquanto ela não entra, quem lê o usuário autenticado é
     * apenas este método.
     */
    private static function idDoUsuarioAutenticado(): ?int
    {
        if (! app()->bound('auth')) {
            return null;
        }

        try {
            $usuario = auth()->user();
        } catch (Throwable) {
            return null;
        }

        if ($usuario === null) {
            return null;
        }

        $empresa = $usuario->company_id ?? null;

        return is_numeric($empresa) ? (int) $empresa : null;
    }
}
