<?php

namespace App\Services;

use App\Models\ActiveIngredient;
use App\Models\Antidote;
use App\Models\BaitType;
use App\Models\ChemicalGroup;
use App\Models\Company;
use App\Models\EventType;
use App\Models\OnboardingStep;
use App\Models\OrganRegistration;
use App\Models\Product;
use App\Models\Service;
use App\Models\ServiceType;
use App\Models\User;
use App\Support\PassosDeOnboarding;
use App\Support\TenantAtual;
use Database\Seeders\CatalogoInicialSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Deixa um tenant recém-criado pronto para operar (Plano 8, Task 8.3).
 *
 * Três coisas nascem juntas aqui, e a ordem importa pouco, mas o "juntas"
 * importa muito:
 *
 * 1. O usuário administrador da empresa, com o papel `administrador`.
 * 2. O catálogo inicial de cadastros de apoio e regulatórios (Task 8.2).
 * 3. A trilha de primeiros passos, toda pendente (Task 8.5).
 *
 * Por que tudo em uma transação
 * -----------------------------
 * Tenant meio provisionado é pior que tenant inexistente: ele aparece na
 * listagem da plataforma com cara de pronto, o cliente entra, tenta abrir a
 * primeira ordem de serviço e não encontra tipo de serviço nem tipo de evento
 * para escolher. Ninguém descobre isso por erro no log, descobre pela
 * reclamação. Falhou qualquer passo, nada fica gravado e a criação do tenant
 * inteira volta atrás.
 *
 * Por que tudo dentro de `TenantAtual::comTenant()`
 * -------------------------------------------------
 * Todo registro semeado precisa nascer com o `company_id` do tenant novo, e
 * quem preenche essa coluna é a trait `BelongsToCompany`, a partir do tenant
 * corrente. Fora do `comTenant()`, o tenant corrente seria o de quem está
 * criando (o super admin, ou ninguém em cadastro público), e o catálogo do
 * cliente novo nasceria dentro da empresa errada. Isso é vazamento entre
 * tenants, que neste sistema é a falha mais grave possível.
 *
 * O que este Service **não** faz, de propósito
 * --------------------------------------------
 * - Não cria nem altera papel do Spatie. Papéis e permissões são globais
 *   (Spatie sem `teams`, decisão do Plano 2), já existem por
 *   `RolesAndPermissionsSeeder` e valem para todos os tenants. O que acontece
 *   por tenant é só a atribuição do papel ao usuário.
 * - Não envia e-mail nenhum. Convite e boas-vindas são a Task 8.4.
 * - Não cria a empresa. Quem cria é `TenantService::criar()` (painel da
 *   plataforma) ou o cadastro público da Task 8.7; os dois chamam este Service
 *   com a empresa já persistida.
 * - Não consulta o usuário autenticado. Quem chama informa a empresa.
 */
class ProvisionamentoService
{
    /**
     * Papel que o primeiro usuário do tenant recebe.
     *
     * Já existe globalmente (`RolesAndPermissionsSeeder`). Nenhum papel é
     * criado por tenant.
     */
    public const PAPEL_DO_ADMINISTRADOR = 'administrador';

    /**
     * Cadastros do catálogo inicial e o model de cada um, usados **apenas para
     * contar** o resultado de `reprovisionarCadastros()`.
     *
     * Quem aplica o catálogo é `CatalogoInicialSeeder`, e é lá que vive a fonte
     * da verdade sobre o que é semeado. Este mapa serve para o relatório do
     * reprovisionamento dizer quantos itens faltavam em cada cadastro, o que é
     * a informação que interessa a quem roda a manutenção. Cadastro novo que
     * apareça no seeder e não aqui continua sendo aplicado normalmente, só não
     * aparece no relatório.
     *
     * As chaves são as mesmas usadas pelo seeder, para os dois relatórios se
     * lerem juntos.
     *
     * @var array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    private const CADASTROS_DO_CATALOGO = [
        'tipos_de_evento' => EventType::class,
        'tipos_de_isca' => BaitType::class,
        'tipos_de_servico' => ServiceType::class,
        'servicos' => Service::class,
        'grupos_quimicos' => ChemicalGroup::class,
        'antidotos' => Antidote::class,
        'principios_ativos' => ActiveIngredient::class,
        'registros_em_orgao' => OrganRegistration::class,
        'produtos' => Product::class,
    ];

    /**
     * Provisiona a empresa: administrador, catálogo inicial e trilha.
     *
     * ## Contrato de `$dadosDoAdministrador`
     *
     * - `nome` (string, obrigatório)
     * - `email` (string, obrigatório, único em `users`; a unique de e-mail é
     *   global de propósito, porque o login é por e-mail)
     * - `senha` (string, opcional) - quando ausente, uma senha aleatória de 32
     *   caracteres é gravada e nunca devolvida. É o caso do cadastro feito pelo
     *   super admin, em que o acesso é combinado por fora e o usuário entra
     *   pela recuperação de senha. O cadastro público da Task 8.7 é quem
     *   informa a senha escolhida por quem se cadastrou.
     * - `telefone` (string, opcional)
     *
     * Chave desconhecida é ignorada: o `User::create()` recebe uma lista fixa
     * de atributos, e não o array de entrada, para nenhum campo a mais no
     * formulário virar campo gravado.
     *
     * ## Ordem das operações
     *
     * O administrador vem primeiro de propósito. É o passo que mais falha na
     * prática (e-mail repetido bate no unique de `users`), e falhar antes de
     * semear ~50 registros de catálogo evita gravar e desfazer tudo isso à toa.
     *
     * @param  array<string, mixed>  $dadosDoAdministrador
     *
     * @throws InvalidArgumentException Quando a empresa não está persistida ou faltam nome/e-mail do administrador.
     */
    public function provisionar(Company $empresa, array $dadosDoAdministrador): User
    {
        $this->exigirEmpresaPersistida($empresa);

        return TenantAtual::comTenant(
            $empresa->id,
            fn (): User => DB::transaction(function () use ($dadosDoAdministrador): User {
                $administrador = $this->criarAdministrador($dadosDoAdministrador);

                $this->aplicarCatalogo();
                $this->iniciarTrilha();

                return $administrador;
            })
        );
    }

    /**
     * Reaplica só o catálogo inicial em uma empresa que já existe.
     *
     * Serve à manutenção: tenant criado antes deste plano, ou tenant cujo
     * provisionamento foi interrompido em uma versão anterior do sistema, e que
     * ficou sem algum cadastro de apoio. Não toca em usuário, papel nem trilha.
     *
     * Idempotente: o seeder usa `firstOrCreate` pela chave natural de cada
     * cadastro (`name`, ou `record` em `OrganRegistration`), então rodar de novo
     * não duplica nada. O retorno mostra exatamente isso: na segunda execução
     * todos os cadastros vêm com zero.
     *
     * CUIDADO DE OPERAÇÃO: use isto só no tenant que está comprovadamente sem
     * algum cadastro. O catálogo é um ponto de partida, e a empresa edita e
     * apaga o que não usa; reaplicar traz de volta os itens que ela removeu de
     * propósito. Rodar em lote para "uniformizar" os tenants desfaz decisão de
     * cliente. O `INDEX.md` do Plano 8 registra isso para o tenant fundador, que
     * já tem os cadastros dele e não deve receber o catálogo.
     *
     * @return array<string, int> Quantos registros nasceram agora, por cadastro. Tudo zero significa tenant já completo.
     *
     * @throws InvalidArgumentException Quando a empresa não está persistida.
     */
    public function reprovisionarCadastros(Company $empresa): array
    {
        $this->exigirEmpresaPersistida($empresa);

        return TenantAtual::comTenant($empresa->id, function (): array {
            $antes = $this->contagemDoCatalogo();

            DB::transaction(fn () => $this->aplicarCatalogo());

            $depois = $this->contagemDoCatalogo();

            $criados = [];

            foreach ($antes as $cadastro => $quantidade) {
                $criados[$cadastro] = $depois[$cadastro] - $quantidade;
            }

            return $criados;
        });
    }

    /**
     * Cria o usuário administrador do tenant corrente e atribui o papel.
     *
     * Só é chamado de dentro de um `comTenant()` já aberto: `company_id` está
     * fora do `$fillable` de `User` (é privilégio, não dado de cadastro), e
     * quem o preenche é a trait `BelongsToCompany` a partir do tenant corrente.
     *
     * `is_platform_admin` não aparece aqui e não pode aparecer: administrador
     * de tenant é o dono da empresa dele, não super admin da plataforma.
     *
     * @param  array<string, mixed>  $dados
     */
    private function criarAdministrador(array $dados): User
    {
        $nome = trim((string) ($dados['nome'] ?? ''));
        $email = trim((string) ($dados['email'] ?? ''));

        if ($nome === '' || $email === '') {
            throw new InvalidArgumentException(
                'Provisionamento exige nome e e-mail do administrador do tenant.'
            );
        }

        $administrador = User::create([
            'name' => $nome,
            'email' => $email,
            // O cast `hashed` de `User` cuida do hash. Senha aleatória quando
            // quem chamou não informou uma: o usuário entra pela recuperação de
            // senha, e nada de legível é gravado nem devolvido.
            'password' => (string) ($dados['senha'] ?? Str::random(32)),
            'phone' => $dados['telefone'] ?? null,
            'is_active' => true,
        ]);

        $administrador->assignRole(self::PAPEL_DO_ADMINISTRADOR);

        return $administrador;
    }

    /**
     * Aplica o catálogo inicial ao tenant corrente.
     *
     * Delega ao `CatalogoInicialSeeder` (Task 8.2) em vez de repetir a lista de
     * cadastros aqui: o seeder também é o caminho manual
     * (`php artisan db:seed --class=CatalogoInicialSeeder`), e duas cópias da
     * mesma semeadura divergiriam na primeira alteração do catálogo.
     *
     * O seeder detecta o tenant já fixado por `TenantAtual::definido()` e usa
     * exatamente ele, sem tentar adivinhar a empresa mais recente por cima da
     * decisão de quem chamou. Resolvido pelo container, ele nasce sem
     * `$this->command`, então as mensagens de console dele ficam em silêncio,
     * que é o certo dentro de uma requisição HTTP.
     */
    private function aplicarCatalogo(): void
    {
        app(CatalogoInicialSeeder::class)->run();
    }

    /**
     * Cria os passos da trilha de primeiros passos, todos pendentes.
     *
     * As chaves vêm de `PassosDeOnboarding::catalogo()` (Task 8.5), que é a
     * fonte única da trilha: título, descrição, rota e condição de conclusão
     * automática vivem lá, e daqui só interessa a chave. Repetir a lista neste
     * arquivo faria o provisionamento parar de criar o passo novo na primeira
     * vez que alguém acrescentasse um item ao catálogo.
     *
     * Pendente é `concluido_em` e `ignorado_em` nulos, que é o padrão da
     * tabela. `firstOrCreate` pela chave, e não `create`: a unique composta
     * `[company_id, chave]` recusaria a segunda tentativa, e mais importante,
     * `updateOrCreate` apagaria o progresso de quem já concluiu ou ignorou um
     * passo se este método fosse chamado de novo por engano.
     *
     * @return int Quantos passos nasceram agora.
     */
    private function iniciarTrilha(): int
    {
        $criados = 0;

        foreach (array_column(PassosDeOnboarding::catalogo(), 'chave') as $chave) {
            $passo = OnboardingStep::query()->firstOrCreate(['chave' => $chave]);

            if ($passo->wasRecentlyCreated) {
                $criados++;
            }
        }

        return $criados;
    }

    /**
     * Quantos registros de cada cadastro do catálogo o tenant corrente tem.
     *
     * Chamado de dentro de um `comTenant()`, então o escopo global já limita a
     * contagem à empresa certa.
     *
     * @return array<string, int>
     */
    private function contagemDoCatalogo(): array
    {
        $contagem = [];

        foreach (self::CADASTROS_DO_CATALOGO as $cadastro => $model) {
            $contagem[$cadastro] = $model::query()->count();
        }

        return $contagem;
    }

    /**
     * A empresa precisa existir no banco antes de receber qualquer registro.
     *
     * Sem esta guarda, `comTenant()` receberia `null` e o erro sairia como
     * "argumento inválido" lá de dentro, longe da causa. Pior: um id ausente
     * faria o escopo global não filtrar nada, e o catálogo nasceria sem empresa
     * definida.
     */
    private function exigirEmpresaPersistida(Company $empresa): void
    {
        if (! $empresa->exists || $empresa->id === null) {
            throw new InvalidArgumentException(
                'Provisionamento exige uma empresa já persistida: crie o tenant antes de provisioná-lo.'
            );
        }
    }
}
