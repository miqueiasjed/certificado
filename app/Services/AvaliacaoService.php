<?php

namespace App\Services;

use App\Exceptions\TenantInternoException;
use App\Models\AccessLog;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Período de avaliação do tenant novo: início, transição para plano pago e
 * encerramento sem assinatura (Plano 8, Task 8.6).
 *
 * ## A regra que não pode falhar
 *
 * **Tenant interno (`companies.is_internal = true`) nunca entra em avaliação e
 * nunca é avaliado por este Service.** Ele já opera sem limite e sem cobrança;
 * colocá-lo em avaliação o exporia à suspensão de `avaliarVencimentos()` quando
 * o prazo vencesse, que é exatamente o bloqueio que o cliente que sustenta a
 * operação atual do sistema não pode sofrer. A checagem é repetida em cada
 * método público que escreve, e não delegada a outro Service, mesmo critério
 * de `SubscriptionService` e `InadimplenciaService`: o critério da Task 7.11 é
 * que remover a verificação de qualquer Service derrube
 * `TenantInternoImuneTest`.
 *
 * ## Fim de avaliação sem assinatura suspende, nunca apaga
 *
 * `avaliarVencimentos()` bloqueia exatamente como `InadimplenciaService`
 * bloqueia por falta de pagamento: marca `companies.situacao` e mais nada.
 * Cliente, OS, certificado e financeiro continuam íntegros, e voltam
 * acessíveis assim que alguém assina (`converter()`) ou o super admin reativa
 * manualmente. O tenant novo que ainda está decidindo comprar não pode perder
 * dado nenhum por não ter decidido a tempo.
 *
 * ## Prazo contado por dia, nunca por instante
 *
 * `trial_ends_at` é campo `date` (dia puro, cast em `Company`), e toda
 * comparação com "hoje" usa `BusinessDate::hoje()`, no fuso do negócio. Um
 * tenant cujo prazo vence hoje ainda está em avaliação às 21h de Brasília;
 * comparar em UTC tiraria um dia de quem está decidindo comprar.
 *
 * ## Os avisos são o Plano 14
 *
 * Enquanto a central de notificações não existir, o aviso de fim de avaliação
 * próximo fica em log da aplicação e em `access_logs`, mesma decisão da Task
 * 7.7 (`InadimplenciaService::registrarAviso()`). `registrarAviso()` é o ponto
 * único de integração: é lá que a chamada ao despachante do Plano 14 entra.
 */
class AvaliacaoService
{
    /**
     * Desfecho de cada empresa em uma passada de `avaliarVencimentos()`, do
     * mais brando ao mais severo. Só o mais severo é reportado por empresa.
     */
    public const ACAO_NENHUMA = 'nenhuma';

    public const ACAO_AVISO_FIM_DE_AVALIACAO = 'aviso_fim_de_avaliacao';

    public const ACAO_SUSPENSA = 'suspensa';

    /**
     * Motivo gravado em `companies.motivo_suspensao` quando a avaliação
     * termina sem o tenant ter assinado.
     *
     * Pública pelo mesmo motivo de `InvoiceService::MOTIVO_SUSPENSAO_INADIMPLENCIA`:
     * é o vocabulário que separa, na tela do super admin, uma suspensão por
     * falta de pagamento de uma suspensão por fim de avaliação sem assinatura.
     */
    public const MOTIVO_SUSPENSAO_FIM_DE_AVALIACAO = 'fim_de_avaliacao';

    /**
     * Eventos gravados em `access_logs.evento`.
     *
     * Prefixados com o domínio, mesmo padrão de `InadimplenciaService`, para a
     * tela de auditoria separar o que é régua de avaliação do que é régua de
     * cobrança.
     */
    public const EVENTO_AVISO_FIM_DE_AVALIACAO = 'avaliacao.aviso_fim_de_avaliacao';

    public const EVENTO_SUSPENSAO = 'avaliacao.suspensao';

    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly TenantService $tenantService,
    ) {}

    /**
     * Coloca o tenant em avaliação, com o prazo contado a partir de hoje.
     *
     * `$dias` sobrescreve `config('assinatura.dias_de_avaliacao')` (padrão 14)
     * para o caso de o super admin conceder um prazo diferente do padrão a um
     * tenant específico; a rota pública de cadastro (Task 8.7) chama sem
     * argumento e recebe o padrão.
     *
     * `trial_ends_at` é gravado como dia puro (`Y-m-d`), sem hora e sem
     * conversão de fuso: é campo `date`, e converter fuso nele é o que produz
     * o erro de um dia.
     *
     * @throws TenantInternoException Quando a empresa é o tenant interno.
     */
    public function iniciar(Company $empresa, ?int $dias = null): Company
    {
        if ($this->ehTenantInterno($empresa)) {
            throw TenantInternoException::naoEntraEmAvaliacao($empresa->name);
        }

        // Piso de 1, mesma defesa de `InadimplenciaService::diasDeTolerancia()`:
        // um valor zero ou negativo, vindo de config cacheado ou de um `$dias`
        // errado, não pode virar avaliação que já nasce vencida.
        $duracao = max(1, $dias ?? $this->diasDeAvaliacao());

        $empresa->update([
            'situacao' => TenantService::SITUACAO_EM_AVALIACAO,
            'trial_ends_at' => BusinessDate::hoje()->addDays($duracao)->toDateString(),
        ]);

        return $empresa->refresh();
    }

    /**
     * Converte a avaliação em assinatura paga: contrata o plano pelo
     * `SubscriptionService` e devolve a empresa ao estado normal, sem prazo de
     * avaliação.
     *
     * A empresa volta inteira para `ativa`, com `trial_ends_at`, `suspensa_em`
     * e `motivo_suspensao` limpos, mesmo padrão de
     * `TenantService::reativar()`: um tenant que a avaliação suspendeu e que
     * assina depois disso sai da suspensão pelo mesmo caminho de quem assina
     * ainda dentro do prazo, sem precisar de reativação manual do super admin.
     *
     * Limite conhecido, herdado do contrato de `SubscriptionService::assinar()`:
     * este método não recebe cartão cifrado, então converter para a forma
     * `cartao` sem cartão previamente registrado lança
     * `AssinaturaInvalidaException`. A tela que oferece a conversão (fora do
     * escopo desta task) precisa coletar o cartão antes de chamar aqui, ou
     * oferecer só pix e boleto neste fluxo.
     *
     * @throws TenantInternoException Quando a empresa é o tenant interno.
     * @throws \App\Exceptions\AssinaturaInvalidaException Ver `SubscriptionService::assinar()`.
     * @throws \App\Exceptions\GatewayRecusouException Quando o provedor responde e recusa.
     * @throws \App\Exceptions\GatewayIndisponivelException Quando o provedor não responde.
     */
    public function converter(Company $empresa, Plan $plano, string $formaPagamento): Subscription
    {
        if ($this->ehTenantInterno($empresa)) {
            throw TenantInternoException::naoEntraEmAvaliacao($empresa->name);
        }

        // Fora da transação de `assinar()`, de propósito: ela já cobre a
        // gravação de `subscriptions` e de `plan_id`, e reabrir uma segunda
        // transação por cima só para a atualização abaixo prenderia lock sem
        // necessidade. Se este `update()` falhar depois do provedor já ter
        // aceitado a assinatura, a empresa fica com assinatura ativa e
        // situação desatualizada até a próxima escrita; um problema bem menor
        // do que o que `SubscriptionService::gravar()` já protege (assinatura
        // órfã no provedor), e recuperável reabrindo a tela do tenant.
        $assinatura = $this->subscriptionService->assinar($empresa, $plano, $formaPagamento);

        $empresa->update([
            'situacao' => TenantService::SITUACAO_ATIVA,
            'trial_ends_at' => null,
            'suspensa_em' => null,
            'motivo_suspensao' => null,
        ]);

        return $assinatura;
    }

    /**
     * Percorre os tenants em avaliação e aplica a régua a cada um: avisa quem
     * está perto do fim e suspende quem passou do prazo sem assinar.
     *
     * Ordem da decisão, por empresa, olhando `diasRestantes()`:
     *
     * - **Ainda não venceu**, e falta um dos `dias_de_aviso_da_avaliacao`:
     *   registra o aviso de fim de avaliação próximo. Nada mais muda.
     * - **Venceu** (dias restantes negativo): suspende. `situacao = suspensa`
     *   é o que tira a empresa desta consulta na passada seguinte, e por isso
     *   rodar duas vezes no mesmo dia não suspende duas vezes nem reescreve
     *   `suspensa_em`.
     *
     * Empresa que assina antes do fim (`converter()`) sai de `em_avaliacao`
     * para `ativa` e some desta consulta: não existe caminho para suspender
     * quem já tem assinatura em vigor.
     *
     * Erro de uma empresa não impede as demais: cada volta do laço é isolada,
     * a exceção vai para o log da aplicação e vira uma linha com `erro`
     * preenchido no resumo.
     *
     * @return array<int, array{empresa: Company, acao: string, dias: int, erro: ?string}>
     */
    public function avaliarVencimentos(): array
    {
        $hoje = BusinessDate::hoje();

        return $this->empresasEmAvaliacao()
            ->map(fn (Company $empresa): array => $this->avaliarIsolando($empresa, $hoje))
            ->all();
    }

    /**
     * Dias que faltam para o fim da avaliação, ou já passados (negativo),
     * contados no fuso do negócio. Nulo quando a empresa não tem
     * `trial_ends_at`, seja por nunca ter entrado em avaliação, seja por já
     * ter convertido (`converter()` limpa o campo).
     *
     * Positivo é o que falta, negativo é o que já passou do prazo: sinal
     * invertido em relação a `InadimplenciaService`, de propósito, porque aqui
     * o nome do método promete "restantes", e um `diasRestantes()` que
     * devolvesse positivo para quem já venceu enganaria quem chama.
     *
     * No dia da virada (hoje é `trial_ends_at`), devolve exatamente 0.
     */
    public function diasRestantes(Company $empresa): ?int
    {
        $fim = BusinessDate::paraFusoNegocio($empresa->trial_ends_at)?->startOfDay();

        if ($fim === null) {
            return null;
        }

        // `round()`, e não cast direto, mesma razão de
        // `InadimplenciaService::aplicarRegua()`: a diferença sai em float, e
        // um dia com 23 ou 25 horas (horário de verão em data antiga)
        // devolveria uma fração que arredondaria para o dia errado.
        return (int) round(BusinessDate::hoje()->diffInDays($fim, false));
    }

    // -----------------------------------------------------------------
    // Uma volta do laço
    // -----------------------------------------------------------------

    /**
     * Empresas em avaliação, elegíveis para a régua desta rotina.
     *
     * Tenant interno é excluído aqui, na consulta, e não depois em memória: o
     * mais barato é ele nunca chegar ao laço. Defesa em profundidade, porque
     * `iniciar()` já recusa colocá-lo em avaliação.
     *
     * @return Collection<int, Company>
     */
    private function empresasEmAvaliacao(): Collection
    {
        return Company::query()
            ->where('situacao', TenantService::SITUACAO_EM_AVALIACAO)
            ->where('is_internal', false)
            ->get();
    }

    /**
     * Uma empresa do laço de `avaliarVencimentos()`, com o erro dela contido.
     *
     * @return array{empresa: Company, acao: string, dias: int, erro: ?string}
     */
    private function avaliarIsolando(Company $empresa, CarbonImmutable $hoje): array
    {
        $linha = [
            'empresa' => $empresa,
            'acao' => self::ACAO_NENHUMA,
            'dias' => 0,
            'erro' => null,
        ];

        try {
            return $this->aplicarRegua($linha, $empresa, $hoje);
        } catch (Throwable $erro) {
            report($erro);

            $linha['erro'] = $erro->getMessage();

            return $linha;
        }
    }

    /**
     * A decisão em si. Ver a ordem completa no docblock de
     * `avaliarVencimentos()`.
     *
     * @param  array{empresa: Company, acao: string, dias: int, erro: ?string}  $linha
     * @return array{empresa: Company, acao: string, dias: int, erro: ?string}
     */
    private function aplicarRegua(array $linha, Company $empresa, CarbonImmutable $hoje): array
    {
        $dias = $this->diasRestantes($empresa);

        if ($dias === null) {
            Log::warning('Empresa em avaliação sem trial_ends_at: fora da régua de avaliação.', [
                'company_id' => $empresa->getKey(),
            ]);

            return $linha;
        }

        $linha['dias'] = $dias;

        if ($dias < 0) {
            $this->tenantService->suspender($empresa, self::MOTIVO_SUSPENSAO_FIM_DE_AVALIACAO);
            $this->registrarAviso(self::EVENTO_SUSPENSAO, $empresa, $dias);

            $linha['acao'] = self::ACAO_SUSPENSA;

            return $linha;
        }

        if (in_array($dias, $this->diasDeAvisoDaAvaliacao(), true)) {
            $this->registrarAviso(self::EVENTO_AVISO_FIM_DE_AVALIACAO, $empresa, $dias);

            $linha['acao'] = self::ACAO_AVISO_FIM_DE_AVALIACAO;
        }

        return $linha;
    }

    // -----------------------------------------------------------------
    // Avisos
    // -----------------------------------------------------------------

    /**
     * Registra o aviso no log da aplicação e em `access_logs`.
     *
     * PONTO DE INTEGRAÇÃO DO PLANO 14: o envio de fato (e-mail, WhatsApp,
     * notificação no painel) entra aqui, enfileirando pela central de
     * notificações, mesma decisão de
     * `InadimplenciaService::registrarAviso()` (Task 7.7). A decisão de quando
     * avisar é desta classe e não muda; o que falta é o canal.
     *
     * A linha nasce dentro de `TenantAtual::comTenant()` da empresa avisada,
     * porque `AccessLog` usa `BelongsToCompany` e preencheria `company_id` a
     * partir do tenant corrente, que numa rotina de linha de comando é nenhum.
     *
     * `user_id`, `ip` e `user_agent` ficam nulos: não há usuário nem
     * requisição, quem age é a rotina. `email` é o da empresa.
     *
     * Falha na gravação é engolida e vai para o log. Auditoria de aviso não
     * pode impedir a régua de avaliar as demais empresas, nem de suspender
     * quem já passou do prazo.
     *
     * @param  int  $dias  Dias que faltam (positivo) ou já passaram (negativo) do fim da avaliação.
     */
    private function registrarAviso(string $evento, Company $empresa, int $dias): void
    {
        $detalhes = [
            'company_id' => (int) $empresa->getKey(),
            'trial_ends_at' => BusinessDate::diaDe($empresa->trial_ends_at),
            'dias' => $dias,
        ];

        Log::info($evento, $detalhes);

        try {
            TenantAtual::comTenant((int) $empresa->getKey(), function () use ($empresa, $evento, $detalhes): void {
                AccessLog::create([
                    'user_id' => null,
                    'email' => (string) ($empresa->email ?? ''),
                    'evento' => $evento,
                    'ip' => null,
                    'user_agent' => null,
                    'detalhes' => $detalhes,
                    'created_at' => now(),
                ]);
            });
        } catch (Throwable $falha) {
            Log::error('Falha ao registrar o aviso da régua de avaliação em access_logs. A régua seguiu.', [
                'evento' => $evento,
                'company_id' => $empresa->getKey(),
                'excecao' => $falha::class,
                'mensagem' => $falha->getMessage(),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Configuração
    // -----------------------------------------------------------------

    /**
     * Duração padrão da avaliação, em dias.
     */
    private function diasDeAvaliacao(): int
    {
        return max(1, (int) config('assinatura.dias_de_avaliacao', 14));
    }

    /**
     * Marcos de aviso antes do fim da avaliação, em dias.
     *
     * A normalização existe porque a comparação com o dia calculado é estrita
     * (`in_array(..., true)`): um `"7"` vindo de config cacheado nunca casaria
     * com o inteiro `7`, mesmo risco documentado em
     * `InadimplenciaService::diasConfigurados()`.
     *
     * @return array<int, int>
     */
    private function diasDeAvisoDaAvaliacao(): array
    {
        $valor = config('assinatura.dias_de_aviso_da_avaliacao', [7, 3, 1]);

        if (! is_array($valor)) {
            return [7, 3, 1];
        }

        $dias = [];

        foreach ($valor as $dia) {
            if (is_numeric($dia) && (int) $dia > 0) {
                $dias[] = (int) $dia;
            }
        }

        return $dias;
    }

    // -----------------------------------------------------------------
    // Regras de empresa
    // -----------------------------------------------------------------

    /**
     * A empresa é o tenant interno?
     *
     * Confere o valor persistido, e não apenas o atributo em memória, mesmo
     * motivo documentado em `SubscriptionService::ehTenantInterno()`: uma
     * instância que passou por `fill()` ou que veio de `select` parcial
     * poderia responder "não é interno" para a empresa que é.
     *
     * Cópia deliberada de `TenantService`, `SubscriptionService` e
     * `InadimplenciaService`: cada Service que pode cobrar ou bloquear carrega
     * a sua, e o critério da Task 7.11 é que remover a verificação de qualquer
     * um deles derrube `TenantInternoImuneTest`.
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
