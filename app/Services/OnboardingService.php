<?php

namespace App\Services;

use App\Models\Company;
use App\Models\OnboardingStep;
use App\Support\PassosDeOnboarding;
use App\Support\TenantAtual;

/**
 * Trilha de primeiros passos do tenant novo (Plano 8, Task 8.5).
 *
 * O catálogo de passos, com a condição de conclusão de cada um, vive em
 * `PassosDeOnboarding::catalogo()`. Este Service só orquestra: lê o estado
 * gravado em `OnboardingStep`, roda as condições e persiste o que mudou.
 *
 * Todo método recebe a empresa avaliada por parâmetro e opera dentro de
 * `TenantAtual::comTenant($empresa->id, ...)`, nunca do tenant da sessão
 * corrente. Duas razões: `OnboardingStep` e os cadastros de domínio
 * (`Client`, `Technician`, `WorkOrder`, `Invitation`) só ficam visíveis com o
 * escopo global de `BelongsToCompany` apontando para a empresa certa, e nada
 * aqui garante que a empresa avaliada seja a empresa da requisição corrente.
 */
class OnboardingService
{
    /**
     * Estado de cada um dos 8 passos da trilha, mais o percentual do total já
     * resolvido (concluído ou ignorado).
     *
     * Sempre devolve os 8 passos do catálogo, mesmo quando a empresa ainda
     * não tem `OnboardingStep` nenhum gravado (passo sem registro = pendente):
     * é o formato esperado tanto pela verificação manual quanto pela prop
     * `onboarding` do Inertia.
     *
     * @return array{passos: array<int, array{chave: string, titulo: string, descricao: string, rota: string, estado: string}>, percentual: int}
     */
    public function situacao(Company $empresa): array
    {
        return TenantAtual::comTenant($empresa->id, function () {
            $registros = OnboardingStep::query()->get()->keyBy('chave');
            $catalogo = PassosDeOnboarding::catalogo();

            $resolvidos = 0;
            $passos = [];

            foreach ($catalogo as $passo) {
                $registro = $registros->get($passo['chave']);
                $estado = $this->estadoDoRegistro($registro);

                if ($estado !== 'pendente') {
                    $resolvidos++;
                }

                $passos[] = [
                    'chave' => $passo['chave'],
                    'titulo' => $passo['titulo'],
                    'descricao' => $passo['descricao'],
                    'rota' => $passo['rota'],
                    'estado' => $estado,
                ];
            }

            $total = count($catalogo);

            return [
                'passos' => $passos,
                'percentual' => $total > 0 ? (int) round($resolvidos / $total * 100) : 100,
            ];
        });
    }

    /**
     * Roda a condição de cada passo ainda pendente e marca como concluído o
     * que ela satisfizer. Passo já concluído ou ignorado não é reavaliado.
     *
     * Chamada de forma preguiçosa por quem exibe a trilha (o middleware do
     * Inertia, Task 8.5, dentro de uma closure), nunca em toda requisição: é
     * este método que faz a bateria de consultas de existência (cliente,
     * técnico, ordem de serviço, convite, assinatura).
     *
     * Cria o `OnboardingStep` do passo se ele ainda não existir. Isso não
     * deveria acontecer para um tenant provisionado por `ProvisionamentoService`
     * (Task 8.3), que já grava os 8 pendentes na criação, mas evita a trilha
     * travar por uma linha faltante em vez de reportar um passo pendente.
     */
    public function avaliar(Company $empresa): void
    {
        TenantAtual::comTenant($empresa->id, function () use ($empresa) {
            $registros = OnboardingStep::query()->get()->keyBy('chave');

            foreach (PassosDeOnboarding::catalogo() as $passo) {
                $registro = $registros->get($passo['chave'])
                    ?? OnboardingStep::query()->create(['chave' => $passo['chave']]);

                if ($registro->concluido_em !== null || $registro->ignorado_em !== null) {
                    continue;
                }

                if (($passo['condicao'])($empresa)) {
                    $registro->forceFill(['concluido_em' => now()])->save();
                }
            }
        });
    }

    /**
     * O usuário dispensa um passo pendente. Não marca como concluído: o passo
     * sai da conta de pendentes, mas a condição continua sem ter sido
     * satisfeita de fato.
     *
     * Passo já concluído não se ignora: o dado real (a condição satisfeita)
     * já venceu, dispensar depois disso não desfaz nada.
     */
    public function ignorar(Company $empresa, string $chave): void
    {
        TenantAtual::comTenant($empresa->id, function () use ($chave) {
            $registro = OnboardingStep::query()->firstOrCreate(['chave' => $chave]);

            if ($registro->concluido_em !== null || $registro->ignorado_em !== null) {
                return;
            }

            $registro->forceFill(['ignorado_em' => now()])->save();
        });
    }

    /**
     * Desfaz o dispensar de um passo: ele volta a contar como pendente.
     *
     * Só desfaz o que `ignorar()` fez. Passo concluído não é alterado aqui: o
     * dado que provou a condição satisfeita continua valendo, e nada nesta
     * trilha desfaz uma condição já cumprida.
     */
    public function retomar(Company $empresa, string $chave): void
    {
        TenantAtual::comTenant($empresa->id, function () use ($chave) {
            $registro = OnboardingStep::query()->firstOrCreate(['chave' => $chave]);

            if ($registro->concluido_em !== null || $registro->ignorado_em === null) {
                return;
            }

            $registro->forceFill(['ignorado_em' => null])->save();
        });
    }

    /**
     * A trilha está concluída: todo passo do catálogo está concluído ou
     * ignorado.
     *
     * Empresa sem `OnboardingStep` nenhum (tenant anterior a este plano, como
     * o tenant fundador) também devolve `true` aqui: não existe passo pendente
     * gravado para ela, e é exatamente isso que faz o middleware nunca chamar
     * `avaliar()` nem exibir a trilha para quem nunca a teve.
     *
     * Uma única consulta de existência, sem varrer o catálogo: é o método que
     * decide, a cada requisição, se vale a pena pagar o resto do trabalho
     * desta classe.
     */
    public function concluida(Company $empresa): bool
    {
        return TenantAtual::comTenant($empresa->id, function () {
            return OnboardingStep::query()
                ->whereNull('concluido_em')
                ->whereNull('ignorado_em')
                ->doesntExist();
        });
    }

    /**
     * Estado de exibição de um registro: concluído, ignorado ou pendente
     * (inclusive quando o registro ainda nem existe).
     */
    private function estadoDoRegistro(?OnboardingStep $registro): string
    {
        if ($registro?->concluido_em !== null) {
            return 'concluido';
        }

        if ($registro?->ignorado_em !== null) {
            return 'ignorado';
        }

        return 'pendente';
    }
}
