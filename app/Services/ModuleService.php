<?php

namespace App\Services;

use App\Models\Company;
use App\Models\CompanyModule;
use App\Models\Module;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;

/**
 * Decide quais módulos um tenant tem ativos (Plano 6), na ordem de precedência:
 *
 * 1. `sempre_ativo` -> sempre presente, mesmo em tenant sem plano.
 * 2. Bloqueio pontual (`company_modules.liberado = false`) -> vence o plano,
 *    mesmo que ele libere o módulo.
 * 3. Liberação pontual (`company_modules.liberado = true`, `liberado_ate` nula
 *    ou não vencida) -> ativo mesmo fora do plano.
 * 4. Vínculo com o plano do tenant (`plan_module`) -> ativo.
 * 5. Nenhuma das anteriores -> inativo. Tenant sem plano cai direto aqui, e só
 *    os `sempre_ativo` sobram.
 *
 * `company_modules` e `plan_module` são tabelas de plataforma, fora do escopo
 * de empresa (ver os docblocks de `Module` e `CompanyModule`): este Service
 * consulta as duas diretamente pelo `company_id` informado, sem depender do
 * escopo global por empresa.
 *
 * Rebaixar plano ou bloquear módulo nunca apaga dado de domínio (cliente, OS,
 * certificado). O que este Service controla é só a visibilidade do módulo no
 * menu e o acesso ao caminho protegido por ele; o dado continua intacto e
 * volta a aparecer assim que o módulo for reativado.
 *
 * `ativosPara()` é memoizado por tenant em propriedade estática, porque
 * `ativo()` é chamado em toda rota protegida por módulo (Task 6.4) e não pode
 * repetir a consulta a cada chamada da mesma requisição. `esquecerCache()`
 * limpa essa memória, e os métodos que alteram `company_modules`
 * (`liberarPara`, `bloquearPara`, `removerRegraPontual`) chamam-no sozinhos,
 * para nunca devolver uma decisão desatualizada depois de mudar a regra.
 */
class ModuleService
{
    /**
     * Chaves de módulo ativas por empresa, já calculadas nesta requisição.
     *
     * @var array<int, array<int, string>>
     */
    private static array $cache = [];

    /**
     * Chaves dos módulos ativos para a empresa, na ordem de exibição do
     * catálogo (`modules.ordem`).
     *
     * @return array<int, string>
     */
    public function ativosPara(Company $empresa): array
    {
        $id = (int) $empresa->getKey();

        if (! array_key_exists($id, self::$cache)) {
            self::$cache[$id] = $this->calcularAtivos($empresa);
        }

        return self::$cache[$id];
    }

    /**
     * O módulo de chave informada está ativo para a empresa?
     *
     * Sem empresa informada, usa `Company::current()` (o tenant da requisição
     * corrente). Apoia-se em `ativosPara()`, e por isso herda a mesma
     * memoização: chamadas repetidas na mesma requisição, para a mesma
     * empresa, não repetem a consulta.
     */
    public function ativo(string $chave, ?Company $empresa = null): bool
    {
        $empresa = $empresa ?? Company::current();

        return in_array($chave, $this->ativosPara($empresa), true);
    }

    /**
     * Libera um módulo para a empresa fora do que o plano dela define.
     *
     * `updateOrCreate` pela unique composta `[company_id, module_id]`: se já
     * existir uma regra pontual (mesmo que fosse um bloqueio), ela é
     * substituída por esta liberação, nunca duplicada.
     *
     * `$ate` é o dia (`Y-m-d`) até quando a liberação vale, comparado por dia
     * no fuso do negócio em `calcularAtivos()`; `null` significa liberação sem
     * prazo.
     */
    public function liberarPara(Company $empresa, Module $modulo, ?string $motivo, ?string $ate): CompanyModule
    {
        $regra = CompanyModule::query()->updateOrCreate(
            ['company_id' => $empresa->getKey(), 'module_id' => $modulo->getKey()],
            ['liberado' => true, 'motivo' => $motivo, 'liberado_ate' => $ate]
        );

        $this->esquecerCache();

        return $regra;
    }

    /**
     * Bloqueia um módulo para a empresa, mesmo que o plano dela o libere.
     *
     * `liberado_ate` é sempre limpo aqui: bloqueio não tem prazo de validade
     * próprio, ele vale até alguém revogar (`removerRegraPontual`) ou
     * substituir por uma liberação (`liberarPara`).
     */
    public function bloquearPara(Company $empresa, Module $modulo, ?string $motivo): CompanyModule
    {
        $regra = CompanyModule::query()->updateOrCreate(
            ['company_id' => $empresa->getKey(), 'module_id' => $modulo->getKey()],
            ['liberado' => false, 'motivo' => $motivo, 'liberado_ate' => null]
        );

        $this->esquecerCache();

        return $regra;
    }

    /**
     * Remove a regra pontual (liberação ou bloqueio) da empresa para o
     * módulo, e o acesso volta a ser decidido só pelo plano.
     *
     * Apaga a linha de `company_modules`, não um dado de domínio: é a própria
     * regra pontual que deixa de existir, o que é o comportamento pedido por
     * "voltar a valer o plano". Sem regra alguma, sem efeito, sem erro.
     */
    public function removerRegraPontual(Company $empresa, Module $modulo): void
    {
        CompanyModule::query()
            ->where('company_id', $empresa->getKey())
            ->where('module_id', $modulo->getKey())
            ->delete();

        $this->esquecerCache();
    }

    /**
     * Esquece a memoização de `ativosPara()`/`ativo()` para todas as
     * empresas.
     *
     * Chamado sozinho pelos três métodos que alteram `company_modules`, e
     * também usado pelos testes para isolar cenários que rodam na mesma
     * requisição/processo.
     */
    public function esquecerCache(): void
    {
        self::$cache = [];
    }

    /**
     * Calcula, sem usar o cache estático, as chaves de módulo ativas para a
     * empresa, aplicando a ordem de precedência descrita no docblock da
     * classe.
     *
     * Três consultas ao todo, nenhuma dentro de laço: o catálogo inteiro de
     * módulos, as regras pontuais da empresa (uma só vez, indexadas por
     * `module_id`) e os módulos vinculados ao plano da empresa (vazio quando
     * ela não tem plano).
     *
     * @return array<int, string>
     */
    private function calcularAtivos(Company $empresa): array
    {
        $modulos = Module::query()->orderBy('ordem')->get();

        $regrasPontuais = CompanyModule::query()
            ->where('company_id', $empresa->getKey())
            ->get()
            ->keyBy('module_id');

        $modulosDoPlano = $empresa->plan_id !== null
            ? DB::table('plan_module')
                ->where('plan_id', $empresa->plan_id)
                ->pluck('module_id')
                ->all()
            : [];

        $ativos = [];

        foreach ($modulos as $modulo) {
            if ($modulo->sempre_ativo) {
                $ativos[] = $modulo->chave;

                continue;
            }

            /** @var CompanyModule|null $regra */
            $regra = $regrasPontuais->get($modulo->getKey());

            if ($regra !== null && $regra->liberado === false) {
                // Bloqueio pontual: vence o plano, sem exceção. Não cai para
                // a checagem de plano logo abaixo.
                continue;
            }

            if ($regra !== null && $regra->liberado === true && ! BusinessDate::estaVencido($regra->liberado_ate)) {
                $ativos[] = $modulo->chave;

                continue;
            }

            if (in_array($modulo->getKey(), $modulosDoPlano, true)) {
                $ativos[] = $modulo->chave;
            }
        }

        return $ativos;
    }
}
