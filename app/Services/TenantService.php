<?php

namespace App\Services;

use App\Exceptions\TenantInternoException;
use App\Models\Company;
use App\Models\Plan;
use App\Support\BusinessDate;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Regras de ciclo de vida de um tenant, usadas pela área da plataforma.
 *
 * Toda a política de tenant vive aqui: qual situação a empresa nasce, quem pode
 * ser suspenso, o que a suspensão grava e o que a reativação limpa. O controller
 * da Task 5.7 valida entrada, chama um método destes e devolve a resposta.
 *
 * A regra mais importante do Plano 5 é a do `suspender()`: o tenant interno
 * nunca é suspenso, por caminho nenhum. Ela está no Service, e não na tela, para
 * valer igual no formulário do painel, num comando artisan, num job e em
 * qualquer código futuro que resolva suspender em lote.
 *
 * O que este Service **não** faz, de propósito:
 *
 * - Não exclui tenant. A FK do Plano 4 é `restrictOnDelete` justamente para que
 *   apagar uma empresa exija um fluxo pensado, que não é escopo do Plano 5.
 * - Não bloqueia acesso por limite de plano. Rebaixar plano aqui só troca o
 *   vínculo; o efeito do limite é do Plano 6.
 * - Não consulta o usuário autenticado. Quem chama informa a empresa.
 */
class TenantService
{
    /**
     * Situações possíveis de um tenant.
     *
     * São string em `companies.situacao`, não enum de banco: a lista cresce no
     * Plano 7 (inadimplência), e alterar enum em MySQL é ALTER TABLE com
     * reescrita. O motivo completo está na migration
     * `2026_07_26_190001_add_platform_fields_to_companies_and_users`.
     *
     * Públicas para que o FormRequest da Task 5.7 e as telas validem contra a
     * mesma fonte, em vez de repetirem literais que depois divergem.
     */
    public const SITUACAO_ATIVA = 'ativa';

    public const SITUACAO_SUSPENSA = 'suspensa';

    public const SITUACAO_EM_AVALIACAO = 'em_avaliacao';

    public const SITUACAO_CANCELADA = 'cancelada';

    /**
     * Todas as situações conhecidas, na ordem em que fazem sentido na tela.
     *
     * @var array<int, string>
     */
    public const SITUACOES = [
        self::SITUACAO_ATIVA,
        self::SITUACAO_EM_AVALIACAO,
        self::SITUACAO_SUSPENSA,
        self::SITUACAO_CANCELADA,
    ];

    /**
     * Campos da empresa que o super admin edita pelo formulário de tenant.
     *
     * É uma lista de permissão deliberada, e não `Arr::except()` dos campos do
     * administrador: `Company::$fillable` também contém `is_internal`,
     * `situacao`, `suspensa_em`, `motivo_suspensao` e `ultimo_acesso_em`, que
     * são estado de plataforma, não dado de cadastro. Um campo a mais no
     * formulário, hoje ou daqui a um ano, não pode ser capaz de marcar um tenant
     * como interno (o que o tornaria imune à suspensão) nem de reativar uma
     * empresa suspensa por dentro do "salvar cadastro". Quem muda situação são
     * `suspender()` e `reativar()`, e só eles.
     *
     * `is_internal` não entra por nenhum caminho deste Service: o único tenant
     * interno é o fundador, marcado pela migration da Task 5.1.
     *
     * @var array<int, string>
     */
    public const CAMPOS_DO_TENANT = [
        'name',
        'cnpj',
        'email',
        'phone',
        'street',
        'number',
        'complement',
        'district',
        'city',
        'state',
        'zip',
        'plan_id',
        'trial_ends_at',
    ];

    /**
     * O preparo do tenant novo (administrador, catálogo inicial e trilha de
     * primeiros passos) é da Task 8.3 e vive em `ProvisionamentoService`, para
     * o cadastro público da Task 8.7 aproveitar exatamente o mesmo preparo sem
     * passar por este Service.
     */
    public function __construct(
        private readonly ProvisionamentoService $provisionamentoService,
    ) {}

    /**
     * Cria o tenant e o usuário administrador dele.
     *
     * ## Contrato de `$dados`
     *
     * Esta é a fonte da verdade para o `TenantRequest` da Task 5.7. Duas famílias
     * de chave, separadas aqui dentro:
     *
     * Campos da empresa (ver `CAMPOS_DO_TENANT`; qualquer outra chave é
     * ignorada):
     * - `name` (string, obrigatório)
     * - `cnpj`, `email`, `phone` (string, opcionais)
     * - `street`, `number`, `complement`, `district`, `city`, `state`, `zip`
     *   (string, opcionais)
     * - `plan_id` (int|null) - tenant sem plano é estado válido até o super
     *   admin definir um
     * - `trial_ends_at` (string `Y-m-d`|null) - dia puro, sem hora e sem
     *   conversão de fuso; converter campo `date` é o que produz erro de um dia
     *
     * Campos do administrador (não vão para `companies`):
     * - `administrador_nome` (string, obrigatório)
     * - `administrador_email` (string, obrigatório, único em `users`)
     * - `administrador_senha` (string, opcional) - ver "Senha do administrador"
     *
     * ## Situação inicial
     *
     * `em_avaliacao` quando vem `trial_ends_at`, `ativa` quando não vem. Nunca é
     * lida de `$dados`: quem entra em avaliação é quem tem prazo de avaliação.
     *
     * ## Senha do administrador
     *
     * Sem `administrador_senha`, uma senha aleatória de 32 caracteres é gravada
     * e nunca devolvida. É o caso do formulário do super admin (`TenantRequest`
     * não coleta senha): ele combina o acesso por fora, e o usuário entra pela
     * recuperação de senha.
     *
     * Com `administrador_senha`, ela é a senha do administrador. Quem informa é
     * o cadastro público da Task 8.7, em que a própria pessoa escolhe a senha e
     * é autenticada logo em seguida. A chave é repassada a
     * `ProvisionamentoService::provisionar()`, que já a previa no contrato dele,
     * para que a senha escolhida seja gravada dentro da mesma transação: um
     * `update()` de senha depois do `criar()` deixaria a senha aleatória
     * gravada, e cifrada, no intervalo entre as duas escritas.
     *
     * O hash é do cast `hashed` de `User`; nada em texto legível trafega para
     * fora deste caminho.
     *
     * ## O que acontece depois da empresa (Task 8.3)
     *
     * A criação do administrador saiu daqui e virou
     * `ProvisionamentoService::provisionar()`, junto com duas coisas que o
     * tenant novo também precisa para operar: o catálogo inicial de cadastros
     * (tipos de evento, de isca, de serviço e os cadastros regulatórios) e a
     * trilha de primeiros passos. Antes disso, um tenant recém-criado abria o
     * sistema sem nenhum tipo de serviço para escolher na primeira ordem de
     * serviço, e alguém precisava semear à mão.
     *
     * O provisionamento é um Service à parte porque o cadastro público da
     * Task 8.7 cria a empresa por outro caminho e precisa exatamente do mesmo
     * preparo.
     *
     * ## Transação
     *
     * Empresa, administrador, catálogo e trilha nascem juntos ou não nascem.
     * E-mail duplicado estoura no unique de `users` e desfaz a empresa, em vez
     * de deixar um tenant órfão, sem ninguém que consiga entrar nele. A
     * transação aberta aqui envolve o provisionamento inteiro: a que
     * `provisionar()` abre por dentro vira savepoint e não solta nada sozinha.
     *
     * @param  array<string, mixed>  $dados  Já validados pelo FormRequest.
     */
    public function criar(array $dados): Company
    {
        return DB::transaction(function () use ($dados): Company {
            $camposDaEmpresa = Arr::only($dados, self::CAMPOS_DO_TENANT);

            $camposDaEmpresa['situacao'] = filled($dados['trial_ends_at'] ?? null)
                ? self::SITUACAO_EM_AVALIACAO
                : self::SITUACAO_ATIVA;

            $empresa = Company::create($camposDaEmpresa);

            $this->provisionamentoService->provisionar($empresa, [
                'nome' => $dados['administrador_nome'],
                'email' => $dados['administrador_email'],
                // Nulo quando quem chamou não coletou senha (formulário do
                // super admin): `provisionar()` trata isso gerando uma
                // aleatória. Lista fixa de chaves, e não o `$dados` inteiro,
                // pelo mesmo motivo de `Arr::only()` acima.
                'senha' => $dados['administrador_senha'] ?? null,
            ]);

            // `refresh()` para devolver a empresa com os padrões que quem
            // preenche é o banco (`is_internal = false`, timestamps). Sem ele o
            // model recém-criado carrega null nesses campos, e quem receber o
            // retorno leria "is_internal desconhecido" como "não é interno".
            return $empresa->refresh();
        });
    }

    /**
     * Atualiza o cadastro do tenant.
     *
     * Só os campos de `CAMPOS_DO_TENANT`, e nenhum campo de estado. Editar o
     * cadastro não muda situação: uma empresa suspensa continua suspensa depois
     * de corrigirem o telefone dela, e mexer em `trial_ends_at` aqui não a tira
     * nem a coloca em avaliação. Mudança de situação tem método próprio, com
     * regra própria.
     *
     * @param  array<string, mixed>  $dados  Já validados pelo FormRequest.
     */
    public function atualizar(Company $empresa, array $dados): Company
    {
        $empresa->update(Arr::only($dados, self::CAMPOS_DO_TENANT));

        return $empresa->refresh();
    }

    /**
     * Suspende o tenant, tirando o acesso sem tocar em nenhum dado dele.
     *
     * REGRA CRÍTICA: tenant interno nunca é suspenso. A checagem vem antes de
     * qualquer escrita, e a exceção sai com a empresa exatamente como estava.
     *
     * Suspender não apaga nem esconde registro: cliente, OS, certificado e
     * financeiro continuam íntegros e voltam inteiros no `reativar()`. O que a
     * suspensão faz é marcar a situação, o instante e o motivo.
     *
     * `suspensa_em` é instante, não dia: grava com `now()`, no mesmo UTC de
     * `created_at`/`updated_at`. Usar o "agora" do fuso do negócio aqui gravaria
     * a hora de Brasília rotulada como UTC, que é o erro de fuso que a skill
     * `datas-timezone` proíbe. O fuso do negócio entra na exibição.
     *
     * @throws TenantInternoException Quando a empresa é o tenant interno.
     */
    public function suspender(Company $empresa, string $motivo): Company
    {
        if ($this->ehTenantInterno($empresa)) {
            throw TenantInternoException::naoPodeSerSuspenso($empresa->name);
        }

        $empresa->update([
            'situacao' => self::SITUACAO_SUSPENSA,
            'suspensa_em' => now(),
            'motivo_suspensao' => $motivo,
        ]);

        return $empresa->refresh();
    }

    /**
     * Reativa o tenant e apaga as marcas da suspensão.
     *
     * Volta para `ativa`, e não para a situação anterior: quem foi suspenso
     * durante a avaliação e voltou é porque o super admin resolveu a pendência,
     * e devolvê-lo a `em_avaliacao` com o prazo já vencido o deixaria bloqueado
     * de novo no Plano 6.
     *
     * Não precisa recusar tenant interno: ele nunca chega a ficar suspenso, e
     * reativar quem já está ativo não altera nada de relevante.
     */
    public function reativar(Company $empresa): Company
    {
        $empresa->update([
            'situacao' => self::SITUACAO_ATIVA,
            'suspensa_em' => null,
            'motivo_suspensao' => null,
        ]);

        return $empresa->refresh();
    }

    /**
     * Troca o plano contratado pelo tenant.
     *
     * Só o vínculo. Rebaixamento não apaga nem arquiva registro nenhum: um
     * tenant que passe do plano de 500 clientes para o de 100 continua com os
     * 500 cadastrados, e o que muda é o que ele consegue criar dali em diante,
     * regra que é do Plano 6. Apagar dado do cliente por mudança de plano seria
     * perda irreversível a partir de uma decisão comercial.
     */
    public function trocarPlano(Company $empresa, Plan $plano): Company
    {
        $empresa->update(['plan_id' => $plano->id]);

        return $empresa->refresh();
    }

    /**
     * Marca o último acesso do tenant.
     *
     * Chamado uma vez por sessão (no login, Task 5.9), nunca a cada requisição:
     * o dado serve para o painel identificar tenant parado há semanas, e um
     * UPDATE por requisição custaria uma escrita em toda navegação para uma
     * precisão que ninguém usa.
     *
     * Instante, como `suspensa_em`: `now()` em UTC, conversão só na exibição.
     * Move junto o `updated_at` da empresa, o que é aceitável na frequência de
     * uma vez por sessão.
     */
    public function registrarAcesso(Company $empresa): void
    {
        $empresa->update(['ultimo_acesso_em' => now()]);
    }

    /**
     * Números gerais da plataforma, para o painel da Task 5.8.
     *
     * `Company` é a própria tabela de tenants e fica fora do escopo por empresa
     * (não usa `BelongsToCompany`), então estas consultas já enxergam todos os
     * tenants sem `semEscopo()`.
     *
     * `total` conta todas as situações, inclusive `cancelada`, que não tem chave
     * própria aqui: a soma das nomeadas pode ser menor que o total, e isso é
     * esperado.
     *
     * A janela de 30 dias é corrida, contada do instante atual para trás, e
     * `created_at` é instante em UTC: comparar com `now()` é exato. Este é o
     * caso em que o fuso do negócio não entra, porque não há "dia" sendo
     * comparado.
     *
     * @return array{total: int, ativos: int, suspensos: int, em_avaliacao: int, criados_ultimos_30_dias: int}
     */
    public function estatisticasDaPlataforma(): array
    {
        $porSituacao = Company::query()
            ->selectRaw('situacao, count(*) as total')
            ->groupBy('situacao')
            ->pluck('total', 'situacao');

        return [
            'total' => (int) $porSituacao->sum(),
            'ativos' => (int) ($porSituacao[self::SITUACAO_ATIVA] ?? 0),
            'suspensos' => (int) ($porSituacao[self::SITUACAO_SUSPENSA] ?? 0),
            'em_avaliacao' => (int) ($porSituacao[self::SITUACAO_EM_AVALIACAO] ?? 0),
            'criados_ultimos_30_dias' => Company::query()
                ->where('created_at', '>=', now()->subDays(30))
                ->count(),
        ];
    }

    /**
     * Quantidade de tenants criados por mês, nos últimos `$meses` meses
     * (padrão 6), para o gráfico do painel geral da Task 5.8.
     *
     * Os buckets de mês são montados aqui no PHP, do mês corrente para trás,
     * no fuso do negócio (`BusinessDate::agora()`), e não por `GROUP BY` no
     * banco: um `GROUP BY` só devolve os meses que têm alguma linha, e um
     * mês sem nenhum tenant criado precisa aparecer com valor zero na série,
     * não sumir dela. `created_at` é instante em UTC; os limites de cada mês
     * são montados no fuso do negócio e convertidos para UTC só na hora de
     * comparar, mesmo padrão de `TenantUsageService::limitesDoMes()`.
     *
     * Rótulo de mês em português (`jul/2026`) via `translatedFormat()`, que
     * respeita `config('app.locale')` (`pt_BR`); `format()` sairia em
     * inglês.
     *
     * @return array{labels: array<int, string>, valores: array<int, int>}
     */
    public function entradasPorMes(int $meses = 6): array
    {
        $inicioDoMesAtual = BusinessDate::agora()->startOfMonth();

        $labels = [];
        $valores = [];

        for ($i = $meses - 1; $i >= 0; $i--) {
            $inicioDoMes = $inicioDoMesAtual->subMonthsNoOverflow($i);
            $fimDoMes = $inicioDoMes->addMonthNoOverflow();

            $labels[] = $inicioDoMes->translatedFormat('M/Y');

            $valores[] = Company::query()
                ->where('created_at', '>=', $inicioDoMes->setTimezone('UTC'))
                ->where('created_at', '<', $fimDoMes->setTimezone('UTC'))
                ->count();
        }

        return ['labels' => $labels, 'valores' => $valores];
    }

    /**
     * Tenants sem acesso há mais de `$dias` dias (padrão 30), para a lista
     * de risco de cancelamento do painel geral.
     *
     * Critério decidido aqui, porque a task deixou em aberto: entra tanto
     * quem acessou pela última vez há mais de `$dias` dias quanto quem
     * NUNCA acessou (`ultimo_acesso_em IS NULL`), desde que já exista há
     * mais de `$dias` dias. Um tenant recém-criado que ainda não fez o
     * primeiro acesso não é "sem acesso", é "ainda não começou" — por isso
     * a segunda condição também olha `created_at`.
     *
     * `ultimo_acesso_em` e `created_at` são instante em UTC, e a janela é
     * corrida a partir de `now()`: mesmo caso de `estatisticasDaPlataforma()`,
     * em que o fuso do negócio não entra por não haver "dia" sendo
     * comparado, só um instante de corte.
     *
     * Ordenado por `ultimo_acesso_em` crescente — o MySQL já ordena `NULL`
     * antes de qualquer data em `ASC`, o que coloca "nunca acessou" no topo
     * da lista, coerente com ser o caso de maior risco. Limitado a
     * `$limite` (padrão 20): é um card de alerta no painel, não uma
     * listagem paginada.
     *
     * @return Collection<int, Company>
     */
    public function semAcessoRecente(int $dias = 30, int $limite = 20): Collection
    {
        $corte = now()->subDays($dias);

        return Company::query()
            ->select(['id', 'name', 'situacao', 'ultimo_acesso_em', 'created_at'])
            ->where(function ($consulta) use ($corte) {
                $consulta->where('ultimo_acesso_em', '<', $corte)
                    ->orWhere(function ($consulta) use ($corte) {
                        $consulta->whereNull('ultimo_acesso_em')
                            ->where('created_at', '<', $corte);
                    });
            })
            ->orderBy('ultimo_acesso_em')
            ->limit($limite)
            ->get();
    }

    /**
     * Tenants em avaliação com o prazo terminando nos próximos `$dias` dias
     * (padrão 7), para o alerta do painel geral.
     *
     * Critério decidido aqui, porque a task deixou em aberto: `trial_ends_at`
     * entre hoje e hoje + `$dias` dias, os dois extremos inclusive — "vence
     * hoje" já entra no alerta. Quem já venceu (`< hoje`) não entra mais
     * aqui: deixou de ser "prestes a vencer" e passa a ser bloqueio de
     * acesso, regra do Plano 6.
     *
     * Restrito a `situacao = em_avaliacao`: `trial_ends_at` pode continuar
     * preenchido num tenant que já virou cliente pagante (o campo não é
     * limpo em `atualizar()`), e esse tenant não está "perto de perder
     * acesso por fim de avaliação".
     *
     * `trial_ends_at` é campo `date` (dia puro, cast em `Company`): a
     * comparação é string `Y-m-d` contra string `Y-m-d`, sem conversão de
     * fuso, porque converter um campo `date` é o que produz o erro de um
     * dia (skill `datas-timezone`). "Hoje" vem de `BusinessDate::hoje()`,
     * no fuso do negócio.
     *
     * @return Collection<int, Company>
     */
    public function avaliacoesProximasDoFim(int $dias = 7): Collection
    {
        $hoje = BusinessDate::hoje();
        $limite = $hoje->addDays($dias)->toDateString();

        return Company::query()
            ->select(['id', 'name', 'trial_ends_at'])
            ->where('situacao', self::SITUACAO_EM_AVALIACAO)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '>=', $hoje->toDateString())
            ->where('trial_ends_at', '<=', $limite)
            ->orderBy('trial_ends_at')
            ->get();
    }

    /**
     * A empresa é o tenant interno?
     *
     * Confere o valor persistido, e não apenas o atributo em memória. A
     * diferença importa: uma instância que passou por `fill()` com dado de
     * formulário, ou que foi carregada com `select` parcial, poderia responder
     * "não é interno" para a empresa que é. Uma consulta a mais numa operação
     * que acontece raramente é preço barato pela única regra do plano que, se
     * falhar, derruba a operação do cliente que paga a conta.
     *
     * Vale o "ou": interno em memória também barra, para o caso de a empresa
     * ainda não existir no banco.
     */
    private function ehTenantInterno(Company $empresa): bool
    {
        if ((bool) $empresa->is_internal) {
            return true;
        }

        if (! $empresa->exists) {
            return false;
        }

        return (bool) Company::query()
            ->whereKey($empresa->getKey())
            ->value('is_internal');
    }
}
