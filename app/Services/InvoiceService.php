<?php

namespace App\Services;

use App\Contracts\GatewayAssinatura;
use App\Exceptions\GatewayIndisponivelException;
use App\Exceptions\GatewayRecusouException;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Support\BusinessDate;
use App\Support\Gateway\RespostaDeAssinatura;
use App\Support\Gateway\RespostaDeCobranca;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Fatura de cada período de uma assinatura: gerar, cobrar no provedor, dar
 * baixa e listar o histórico (Plano 7, Task 7.5).
 *
 * É a cobrança que a plataforma faz do tenant, não a que o tenant faz dos
 * clientes dele (que é o Plano 19).
 *
 * ## A regra que não pode falhar
 *
 * **Tenant interno (`companies.is_internal = true`) não é cobrado e não pode
 * ser bloqueado.** Ele não tem assinatura, então nem deveria chegar ao laço de
 * `gerarDoCiclo()`; a verificação está lá mesmo assim, e também em
 * `gerarPara()`, porque o custo do erro é a operação do cliente que paga a
 * conta hoje. Cópia deliberada de `SubscriptionService::ehTenantInterno()`:
 * cada Service que pode cobrar ou bloquear carrega a sua, e o critério da Task
 * 7.11 é que remover a verificação de qualquer um deles derrube
 * `TenantInternoImuneTest`.
 *
 * ## Idempotência: o que impede cobrar duas vezes
 *
 * A fatura é criada com `firstOrCreate` na chave `[subscription_id,
 * referencia]`, que é a mesma unique composta que a migration da Task 7.1
 * gravou no banco. Duas passadas da rotina no mesmo dia, ou uma passada
 * simultânea a um reprocessamento manual, encontram a linha que já existe em
 * vez de criar a segunda.
 *
 * A referência de cada assinatura sai de `proxima_cobranca_em`, o dia em que o
 * ciclo dela vence, e **não** do mês corrente. A diferença aparece quando a
 * fatura não é paga: `proxima_cobranca_em` só avança em `marcarComoPaga()`,
 * então a referência continua sendo a do período em aberto na virada do mês, e
 * a passada do dia 1 reencontra a fatura de dezembro em vez de emitir uma
 * segunda cobrança do mesmo ciclo. Referência tirada de "hoje" duplicaria a
 * cobrança de todo tenant inadimplente na virada do mês.
 *
 * Disso decorre o modelo de ciclo: uma assinatura tem no máximo uma fatura em
 * aberto por vez, e o ciclo anda quando o período é pago. Um tenant que pague
 * com dois meses de atraso recebe o período seguinte na passada seguinte da
 * rotina, que é o que ele de fato deve.
 *
 * ## Ordem em relação ao provedor
 *
 * Aqui a ordem é o inverso de `SubscriptionService`, e é o contrato que obriga:
 * `GatewayAssinatura::gerarCobranca()` recebe uma `Invoice`, então a fatura
 * precisa existir no banco antes da chamada. A consequência é deliberada e é a
 * mais segura das duas: falha do provedor deixa a fatura gravada sem link,
 * linha digitável ou QR, e a próxima passada da rotina tenta emitir a cobrança
 * de novo, sem criar fatura nova. Falha do provedor **não** interrompe as
 * demais assinaturas do laço.
 *
 * ## O que este Service não faz, de propósito
 *
 * - Não avisa, não marca em atraso e não suspende ninguém. A régua de
 *   inadimplência é a Task 7.7 (`InadimplenciaService`), e `marcarComoVencida()`
 *   mexe só na fatura. A única coisa que este Service pede à régua é a
 *   reativação depois da baixa, em `marcarComoPaga()`.
 * - Não processa webhook (Task 7.6). Quem recebe o evento do provedor é
 *   `GatewayEventService`, e é ele que chama `marcarComoPaga()`,
 *   `marcarComoVencida()` ou `marcarComoCancelada()` conforme o tipo. Os três
 *   refletem o que o provedor já decidiu e nenhum deles liga de volta para
 *   ele.
 * - Não consulta o usuário autenticado. Quem chama informa a empresa.
 * - Não filtra por escopo global: `invoices` e `subscriptions` ficam fora dele
 *   (Task 7.1), e por isso toda consulta aqui filtra `company_id`
 *   explicitamente.
 */
class InvoiceService
{
    /**
     * Motivo gravado em `companies.motivo_suspensao` quando a suspensão é por
     * falta de pagamento.
     *
     * Pública porque é o vocabulário compartilhado entre quem suspende
     * (`InadimplenciaService::suspenderPorInadimplencia()`, Task 7.7, que chama
     * `TenantService::suspender($empresa, InvoiceService::MOTIVO_SUSPENSAO_INADIMPLENCIA)`)
     * e quem reativa (`InadimplenciaService::reativarPorPagamento()`). Se os
     * dois lados repetissem o literal, bastaria um deles mudar de grafia para o
     * pagamento deixar de devolver o acesso, e o tenant continuaria bloqueado
     * depois de pagar.
     *
     * A constante fica aqui, e não na régua, porque é fatura paga que aciona o
     * desbloqueio: quem lê `InvoiceService` precisa encontrar o vocabulário sem
     * sair do arquivo. A régua a lê daqui, e ler constante de classe não passa
     * pelo container, então isso não recria a dependência circular que o
     * construtor evita.
     */
    public const MOTIVO_SUSPENSAO_INADIMPLENCIA = 'inadimplencia';

    /**
     * Situações de assinatura que o pagamento devolve para `ativa`.
     *
     * `cancelada` não entra: quem cancelou pediu para encerrar, e uma fatura
     * atrasada paga depois disso quita o período, não ressuscita o contrato.
     *
     * @var array<int, string>
     */
    public const SITUACOES_QUE_O_PAGAMENTO_REATIVA = [
        RespostaDeAssinatura::SITUACAO_EM_ATRASO,
        RespostaDeAssinatura::SITUACAO_SUSPENSA,
    ];

    /**
     * `InadimplenciaService` (Task 7.7) entra aqui, e não o contrário.
     *
     * A dependência entre os dois corre em uma direção só: `InvoiceService ->
     * InadimplenciaService -> TenantService`. A régua não injeta este Service de
     * volta (ela mexe direto no Model `Invoice` para marcar vencimento, que é um
     * `update` de uma coluna), justamente para não fechar um ciclo que o
     * container não resolve e que estouraria na baixa de um pagamento real.
     */
    public function __construct(
        private readonly GatewayAssinatura $gateway,
        private readonly SubscriptionService $subscriptionService,
        private readonly InadimplenciaService $inadimplenciaService,
    ) {}

    /**
     * Gera (ou reaproveita) a fatura de um período da assinatura e emite a
     * cobrança no provedor.
     *
     * Devolve `null` quando não há o que cobrar, sem gravar nada:
     *
     * - assinatura do tenant interno, que não é cobrado por caminho nenhum;
     * - assinatura sem empresa ou sem plano, que é linha inconsistente e não
     *   tem a quem cobrar nem quanto;
     * - plano sem valor (`valor <= 0`), porque emitir cobrança de R$ 0,00
     *   gastaria uma ida ao provedor para receber uma recusa.
     *
     * `referencia` é o rótulo do período, no formato `YYYY-MM`, e é metade da
     * chave de idempotência (ver o cabeçalho da classe). A outra metade é a
     * unique `[subscription_id, referencia]` no banco.
     *
     * O vencimento é o dia da próxima cobrança da assinatura, **nunca anterior
     * a hoje**: uma fatura que nasce vencida começa a contar a tolerância da
     * régua de inadimplência (Task 7.7) antes de o tenant ter tido a chance de
     * pagar, e nesse sistema o fim dessa contagem é bloqueio de acesso. Se a
     * rotina ficar dez dias parada, o tenant recebe a fatura com vencimento
     * hoje, e não com vencimento de dez dias atrás. O período cobrado
     * (`referencia`) não muda com isso, então nada se perde comercialmente.
     *
     * Recusa e indisponibilidade do provedor são capturadas aqui, e não
     * propagadas: a fatura fica gravada sem instrução de pagamento e a próxima
     * passada tenta emitir de novo. Deixar a exceção subir interromperia o laço
     * de `gerarDoCiclo()` e o tenant seguinte deixaria de ser faturado por causa
     * do problema do anterior.
     *
     * @param  string  $referencia  Período no formato `YYYY-MM`, calculado no fuso do negócio.
     *
     * @throws InvalidArgumentException Quando a referência não está em `YYYY-MM`.
     */
    public function gerarPara(Subscription $assinatura, string $referencia): ?Invoice
    {
        $this->validarReferencia($referencia);

        $empresa = $assinatura->loadMissing('company')->company;

        if (! $empresa instanceof Company) {
            Log::warning('Assinatura sem empresa: nada a faturar.', [
                'subscription_id' => $assinatura->getKey(),
                'referencia' => $referencia,
            ]);

            return null;
        }

        if ($this->ehTenantInterno($empresa)) {
            Log::warning('Assinatura encontrada no tenant interno: fatura não gerada.', [
                'company_id' => $empresa->getKey(),
                'subscription_id' => $assinatura->getKey(),
                'referencia' => $referencia,
            ]);

            return null;
        }

        $plano = $assinatura->loadMissing('plan')->plan;
        $valor = $plano === null ? 0.0 : (float) $plano->valor;

        if ($plano === null || $valor <= 0) {
            Log::warning('Assinatura sem plano com valor a cobrar: fatura não gerada.', [
                'company_id' => $empresa->getKey(),
                'subscription_id' => $assinatura->getKey(),
                'plan_id' => $assinatura->plan_id,
                'referencia' => $referencia,
            ]);

            return null;
        }

        $fatura = Invoice::firstOrCreate(
            [
                'subscription_id' => $assinatura->getKey(),
                'referencia' => $referencia,
            ],
            [
                'company_id' => $empresa->getKey(),
                'valor' => $plano->valor,
                'situacao' => RespostaDeCobranca::SITUACAO_ABERTA,
                'vencimento' => $this->vencimentoDe($assinatura)->toDateString(),
            ]
        );

        // As relações são semeadas em memória porque a implementação concreta
        // do gateway monta o pedido a partir delas (dados do assinante, forma
        // de pagamento). Sem isto cada fatura do laço faria duas consultas a
        // mais para reler empresa e assinatura que já estão aqui na mão.
        $fatura->setRelation('company', $empresa);
        $fatura->setRelation('subscription', $assinatura);

        return $this->emitirCobranca($fatura, $assinatura, $empresa);
    }

    /**
     * Gera a fatura de toda assinatura ativa cujo ciclo já venceu.
     *
     * "Já venceu" é `proxima_cobranca_em` até hoje **no fuso do negócio**:
     * comparar com o dia em UTC anteciparia a cobrança de todo tenant a partir
     * das 21h de Brasília do dia anterior.
     *
     * Erro de uma assinatura não impede as demais. Cada volta do laço é isolada:
     * a exceção é reportada para o log da aplicação e vira uma linha com `erro`
     * preenchido no resumo, e o laço segue. Quem chama (o comando
     * `plataforma:gerar-faturas`) decide como exibir.
     *
     * @param  string|null  $referencia  Força a mesma referência `YYYY-MM` em todas as faturas da passada, para reprocessamento manual. Sem ela, cada assinatura usa o próprio período (ver o cabeçalho da classe).
     * @param  Company|null  $empresa  Restringe a passada a um tenant.
     * @return array<int, array{
     *     assinatura: Subscription,
     *     empresa: ?Company,
     *     fatura: ?Invoice,
     *     nova: bool,
     *     motivo: ?string,
     *     erro: ?string
     * }>
     *
     * @throws InvalidArgumentException Quando a referência forçada não está em `YYYY-MM`.
     */
    public function gerarDoCiclo(?string $referencia = null, ?Company $empresa = null): array
    {
        if ($referencia !== null) {
            $this->validarReferencia($referencia);
        }

        $hoje = BusinessDate::hoje()->toDateString();

        $consulta = Subscription::query()
            ->with(['company', 'plan'])
            ->where('situacao', RespostaDeAssinatura::SITUACAO_ATIVA)
            ->whereNotNull('proxima_cobranca_em')
            ->whereDate('proxima_cobranca_em', '<=', $hoje)
            ->orderBy('id');

        if ($empresa !== null) {
            $consulta->where('company_id', $empresa->getKey());
        }

        $resumo = [];

        foreach ($consulta->get() as $assinatura) {
            $resumo[] = $this->gerarIsolando($assinatura, $referencia);
        }

        return $resumo;
    }

    /**
     * Dá baixa no pagamento da fatura, devolve o acesso e avança o ciclo da
     * assinatura.
     *
     * Fatura já paga é devolvida como está, sem novo efeito. É essa saída
     * antecipada que faz o mesmo webhook chegando duas vezes produzir uma única
     * baixa e um único avanço de `proxima_cobranca_em` (critério da Task 7.11):
     * sem ela, o segundo aviso empurraria a próxima cobrança um mês para a
     * frente e o tenant deixaria de ser cobrado por um período inteiro.
     *
     * Quem devolve o acesso é `InadimplenciaService::reativarPorPagamento()`
     * (Task 7.7), chamado de dentro da transação daqui: a baixa da fatura, o
     * avanço do ciclo e a reativação são um efeito atômico só. A regra vive lá
     * porque é a mesma régua que bloqueou, e ter duas implementações dela seria
     * o caminho para bloquear por um critério e desbloquear por outro. Em
     * resumo, e o detalhe está no docblock de lá:
     *
     * - A **assinatura** volta para `ativa` quando estava `em_atraso` ou
     *   `suspensa`. Cancelada continua cancelada.
     * - A **empresa** só é reativada quando está suspensa **e** o motivo
     *   gravado é `self::MOTIVO_SUSPENSAO_INADIMPLENCIA`.
     *
     * Não existe reativação no provedor. O PagBank não tem esse conceito aqui:
     * quem bloqueia e desbloqueia o acesso é a régua da plataforma (Task 7.7),
     * a partir de `Invoice.vencimento`, e não do status da assinatura do lado
     * de lá.
     *
     * `pago_em` é dia, sem hora, como a coluna manda: pagamento de fatura não
     * tem hora relevante, e comparar por instante é o que faria uma fatura
     * paga hoje aparecer paga ontem às 21h de Brasília.
     *
     * @param  string  $pagoEm  Dia da confirmação, `Y-m-d` ou qualquer valor que `BusinessDate` saiba ler. É convertido para o dia no fuso do negócio.
     */
    public function marcarComoPaga(Invoice $fatura, string $pagoEm): Invoice
    {
        if ($fatura->situacao === RespostaDeCobranca::SITUACAO_PAGA) {
            return $fatura;
        }

        $dia = BusinessDate::paraFusoNegocio($pagoEm) ?? BusinessDate::hoje();
        $assinatura = $fatura->loadMissing('subscription')->subscription;

        // A conta do próximo ciclo é feita fora da transação, e a falha dela não
        // pode custar o registro do pagamento: um plano com periodicidade que
        // ninguém sabe converter é problema de cadastro, e desfazer a baixa por
        // causa dele apagaria a confirmação que o provedor mandou uma vez só.
        $proximaCobranca = $this->proximaCobrancaDe($assinatura);

        return DB::transaction(function () use ($fatura, $dia, $assinatura, $proximaCobranca): Invoice {
            $fatura->update([
                'situacao' => RespostaDeCobranca::SITUACAO_PAGA,
                'pago_em' => $dia->toDateString(),
            ]);

            if ($assinatura instanceof Subscription) {
                $this->inadimplenciaService->reativarPorPagamento($assinatura);

                if ($proximaCobranca !== null) {
                    $assinatura->update(['proxima_cobranca_em' => $proximaCobranca->toDateString()]);
                }
            }

            return $fatura->refresh();
        });
    }

    /**
     * Marca a fatura como vencida.
     *
     * Só age sobre fatura em aberto. Fatura paga ou cancelada é devolvida como
     * está: transformar em vencida uma fatura já quitada seria apagar a baixa e
     * mandar para a régua de inadimplência um tenant que não deve nada.
     *
     * Mexe na fatura e em mais nada. Levar a assinatura para `em_atraso` e
     * suspender a empresa é decisão da régua (Task 7.7), que conhece a
     * tolerância; aqui não há como saber se o vencimento de ontem já autoriza
     * bloquear alguém.
     *
     * Na prática, quem chama isto é raro: o PagBank não manda evento de boleto
     * vencido (ver `PagBankGateway::eventoDePedido()`), e quem transiciona
     * `aberta -> vencida` no dia a dia é `InadimplenciaService::avaliar()`, que
     * compara o vencimento com o dia de hoje. Este método continua existindo
     * para o tipo `cobranca_vencida` do contrato de gateway, por completude e
     * para o provedor futuro que avise.
     */
    public function marcarComoVencida(Invoice $fatura): Invoice
    {
        if ($fatura->situacao !== RespostaDeCobranca::SITUACAO_ABERTA) {
            return $fatura;
        }

        if ($this->ehFaturaDoTenantInterno($fatura, 'marcarComoVencida')) {
            return $fatura;
        }

        $fatura->update(['situacao' => RespostaDeCobranca::SITUACAO_VENCIDA]);

        return $fatura->refresh();
    }

    /**
     * Marca a fatura como cancelada, refletindo o que o provedor já decidiu do
     * lado dele (evento `cobranca_cancelada`, Task 7.6).
     *
     * Só age sobre fatura em aberto, mesmo critério de `marcarComoVencida()`.
     * Fatura paga **não** vira cancelada por um evento tardio: isso apagaria uma
     * baixa já registrada e faria reaparecer como devedor um tenant que pagou.
     * Fatura já cancelada é devolvida como está, e é essa saída que torna o
     * método idempotente quando o mesmo evento chega duas vezes.
     *
     * Não chama o provedor. O evento chegando significa que o cancelamento já
     * aconteceu lá; pedir de volta seria uma ida à rede para confirmar o que já
     * é fato, com a chance de falhar no meio.
     *
     * Mexe na fatura e em mais nada. Cobrança cancelada não encerra a
     * assinatura: o provedor manda um evento próprio para isso
     * (`assinatura_cancelada`), e deduzir um do outro cancelaria o contrato de
     * quem só teve um boleto cancelado.
     */
    public function marcarComoCancelada(Invoice $fatura): Invoice
    {
        if ($fatura->situacao !== RespostaDeCobranca::SITUACAO_ABERTA) {
            return $fatura;
        }

        if ($this->ehFaturaDoTenantInterno($fatura, 'marcarComoCancelada')) {
            return $fatura;
        }

        $fatura->update(['situacao' => RespostaDeCobranca::SITUACAO_CANCELADA]);

        return $fatura->refresh();
    }

    /**
     * Faturas da empresa nos últimos `$meses` meses (padrão 12), da mais
     * recente para a mais antiga.
     *
     * A janela é contada em meses de referência, contando o mês corrente: com
     * o padrão, é o mês de hoje mais os onze anteriores. `referencia` é texto
     * `YYYY-MM`, cuja ordem alfabética é a ordem cronológica, então o corte é
     * uma comparação de string e não depende de converter fuso em campo de
     * data.
     *
     * O filtro por `company_id` é explícito porque `invoices` fica fora do
     * escopo global por empresa (Task 7.1): a plataforma precisa conciliar a
     * fatura de todos os tenants na mesma tela, e por isso não há escopo
     * automático protegendo esta consulta. Esquecer o `where` aqui mostraria a
     * fatura de um tenant para outro.
     *
     * @return Collection<int, Invoice>
     */
    public function historicoDe(Company $empresa, int $meses = 12): Collection
    {
        $janela = max(1, $meses);
        $corte = BusinessDate::hoje()->subMonthsNoOverflow($janela - 1)->format('Y-m');

        return Invoice::query()
            ->where('company_id', $empresa->getKey())
            ->where('referencia', '>=', $corte)
            ->orderByDesc('referencia')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Faturas em aberto (`aberta` + `vencida`) da plataforma, excluindo o
     * tenant interno: quantidade, valor total inadimplente e a lista de cada
     * fatura, para o painel de receita (Task 7.8) e para a tabela de faturas
     * em aberto do painel de receita da plataforma (Task 7.10).
     *
     * `vencida` entra na soma junto de `aberta` porque as duas ainda não foram
     * pagas; `paga` e `cancelada` não representam dinheiro em aberto. O
     * tenant interno não tem fatura (ver o cabeçalho da classe), então o
     * filtro por `company.is_internal = false` é defesa em profundidade.
     *
     * `lista` traz uma linha por fatura, ordenada por vencimento (a mais
     * antiga primeiro): é a tela que decide como calcular dias de atraso a
     * partir do vencimento, com os utilitários de fuso do frontend, então o
     * vencimento sai como `Y-m-d`, sem hora e sem conversão (é campo `date`).
     * `quantidade` e `valor_total` continuam vindo de agregação no banco, sem
     * depender de `lista`, para não alterar o resultado das duas chaves já
     * consumidas pelo painel de receita.
     *
     * @return array{
     *     quantidade: int,
     *     valor_total: float,
     *     lista: array<int, array{
     *         id: int,
     *         company_id: int,
     *         empresa: string,
     *         valor: float,
     *         vencimento: string|null,
     *         situacao: string,
     *     }>,
     * }
     */
    public function faturasEmAberto(): array
    {
        $baseQuery = fn () => Invoice::query()
            ->whereHas('company', fn ($consulta) => $consulta->where('is_internal', false))
            ->whereIn('situacao', [
                RespostaDeCobranca::SITUACAO_ABERTA,
                RespostaDeCobranca::SITUACAO_VENCIDA,
            ]);

        return [
            'quantidade' => (int) $baseQuery()->count(),
            'valor_total' => (float) $baseQuery()->sum('valor'),
            'lista' => $baseQuery()
                ->with('company')
                ->orderBy('vencimento')
                ->get()
                ->map(fn (Invoice $fatura) => [
                    'id' => $fatura->id,
                    'company_id' => $fatura->company_id,
                    'empresa' => (string) ($fatura->company?->name ?? ''),
                    'valor' => (float) $fatura->valor,
                    'vencimento' => $fatura->vencimento?->toDateString(),
                    'situacao' => $fatura->situacao,
                ])
                ->values()
                ->all(),
        ];
    }

    // -----------------------------------------------------------------
    // Geração: uma volta do laço
    // -----------------------------------------------------------------

    /**
     * Uma assinatura do laço de `gerarDoCiclo()`, com o erro dela contido.
     *
     * @return array{
     *     assinatura: Subscription,
     *     empresa: ?Company,
     *     fatura: ?Invoice,
     *     nova: bool,
     *     motivo: ?string,
     *     erro: ?string
     * }
     */
    private function gerarIsolando(Subscription $assinatura, ?string $referencia): array
    {
        $empresa = $assinatura->company;

        $linha = [
            'assinatura' => $assinatura,
            'empresa' => $empresa,
            'fatura' => null,
            'nova' => false,
            'motivo' => null,
            'erro' => null,
        ];

        // Defesa em profundidade: tenant interno não tem assinatura, então esta
        // consulta não deveria trazê-lo. A verificação fica aqui mesmo assim,
        // antes de `gerarPara()`, porque o custo de ela faltar é cobrar (e
        // depois bloquear) o cliente que sustenta o negócio.
        if ($empresa instanceof Company && $this->ehTenantInterno($empresa)) {
            $linha['motivo'] = 'Tenant interno: não é cobrado.';

            return $linha;
        }

        try {
            $fatura = $this->gerarPara(
                $assinatura,
                $referencia ?? $this->referenciaDe($assinatura)
            );
        } catch (Throwable $erro) {
            report($erro);

            $linha['erro'] = $erro->getMessage();

            return $linha;
        }

        if ($fatura === null) {
            $linha['motivo'] = 'Assinatura sem plano com valor a cobrar.';

            return $linha;
        }

        $linha['fatura'] = $fatura;
        $linha['nova'] = $fatura->wasRecentlyCreated;

        return $linha;
    }

    /**
     * Pede a cobrança ao provedor e grava o que ele devolveu.
     *
     * Fatura que já tem cobrança emitida (`gateway_invoice_id` preenchido) não
     * é pedida de novo: uma segunda emissão para o mesmo período é uma segunda
     * cobrança na mão do cliente. É por isso que rodar a rotina duas vezes no
     * mesmo dia não gera nem fatura nem cobrança duplicada.
     *
     * Fatura que não está mais em aberto (paga, vencida, cancelada) também não
     * recebe cobrança nova.
     */
    private function emitirCobranca(Invoice $fatura, Subscription $assinatura, Company $empresa): Invoice
    {
        if (filled($fatura->gateway_invoice_id)) {
            return $fatura;
        }

        if ($fatura->situacao !== RespostaDeCobranca::SITUACAO_ABERTA) {
            return $fatura;
        }

        try {
            $cobranca = $this->gateway->gerarCobranca($fatura);
        } catch (GatewayRecusouException $erro) {
            $this->registrarCobrancaNaoEmitida($fatura, $assinatura, $empresa, $erro, recusa: true);

            return $fatura;
        } catch (GatewayIndisponivelException $erro) {
            $this->registrarCobrancaNaoEmitida($fatura, $assinatura, $empresa, $erro, recusa: false);

            return $fatura;
        }

        $fatura->update([
            'gateway_invoice_id' => $cobranca->idNoGateway,
            'link_pagamento' => $cobranca->linkPagamento,
            'linha_digitavel' => $cobranca->linhaDigitavel,
            'qr_code_pix' => $cobranca->qrCodePix,
        ]);

        return $fatura->refresh();
    }

    /**
     * Registra que a fatura ficou sem instrução de pagamento, com o nível certo
     * de alarme.
     *
     * Fatura no cartão cai aqui em toda passada, e de propósito: a cobrança do
     * cartão é a própria recorrência do provedor, que roda na data dele, e
     * emitir uma segunda cobraria o cliente duas vezes. Esse caso é esperado e
     * entra como `info`. Registrá-lo como aviso encheria de ruído diário um log
     * que precisa continuar sendo lido como alarme.
     *
     * A decisão do nível olha a forma de pagamento gravada aqui, nunca a
     * mensagem do provedor: o texto da recusa pertence à implementação
     * concreta, e ler texto de provedor para decidir comportamento é como se
     * amarra o domínio ao gateway que a interface existe para desacoplar.
     */
    private function registrarCobrancaNaoEmitida(
        Invoice $fatura,
        Subscription $assinatura,
        Company $empresa,
        Throwable $erro,
        bool $recusa,
    ): void {
        $contexto = [
            'company_id' => $empresa->getKey(),
            'subscription_id' => $assinatura->getKey(),
            'invoice_id' => $fatura->getKey(),
            'referencia' => $fatura->referencia,
            'forma_pagamento' => $assinatura->forma_pagamento,
            'gateway' => class_basename($this->gateway),
            'erro' => $erro->getMessage(),
        ];

        if ($recusa && $assinatura->forma_pagamento === SubscriptionService::FORMA_CARTAO) {
            Log::info(
                'Fatura de cartão registrada sem cobrança avulsa: quem cobra o período é a recorrência do provedor.',
                $contexto
            );

            return;
        }

        Log::warning(
            'Fatura gravada sem instrução de pagamento: o provedor não emitiu a cobrança. '
            .'A próxima passada da rotina tenta de novo, sem gerar fatura nova.',
            $contexto
        );
    }

    // -----------------------------------------------------------------
    // Datas do ciclo
    // -----------------------------------------------------------------

    /**
     * Período que a assinatura está cobrando, no formato `YYYY-MM`.
     *
     * Sai de `proxima_cobranca_em`, e não do mês corrente: é isso que mantém a
     * referência estável enquanto o período não é pago, inclusive depois da
     * virada do mês. Ver o cabeçalho da classe.
     */
    private function referenciaDe(Subscription $assinatura): string
    {
        $dia = BusinessDate::paraFusoNegocio($assinatura->proxima_cobranca_em) ?? BusinessDate::hoje();

        return $dia->format('Y-m');
    }

    /**
     * Dia do vencimento da fatura: o da próxima cobrança da assinatura, nunca
     * anterior a hoje. Ver `gerarPara()` para o motivo do piso.
     */
    private function vencimentoDe(Subscription $assinatura): CarbonImmutable
    {
        $hoje = BusinessDate::hoje();
        $cobranca = BusinessDate::paraFusoNegocio($assinatura->proxima_cobranca_em)?->startOfDay();

        if ($cobranca === null || $cobranca->lessThan($hoje)) {
            return $hoje;
        }

        return $cobranca;
    }

    /**
     * Dia da cobrança seguinte, um período do plano depois da atual, ou `null`
     * quando não dá para calcular.
     *
     * A conta é a de `SubscriptionService::proximaCobrancaApos()`, e não uma
     * cópia dela: quanto tempo dura um ciclo é uma regra só, e duas
     * implementações dela acabariam divergindo em cima de dinheiro.
     *
     * A base é `proxima_cobranca_em`, nunca o dia do pagamento: é o que preserva
     * o aniversário da cobrança. Quem assinou dia 15 continua vencendo dia 15
     * mesmo pagando com atraso; contar a partir do pagamento arrastaria a data
     * um pouco para a frente a cada mês pago em cima do prazo.
     */
    private function proximaCobrancaDe(?Subscription $assinatura): ?CarbonImmutable
    {
        if (! $assinatura instanceof Subscription) {
            return null;
        }

        $plano = $assinatura->loadMissing('plan')->plan;

        if ($plano === null) {
            return null;
        }

        $base = BusinessDate::paraFusoNegocio($assinatura->proxima_cobranca_em)?->startOfDay()
            ?? BusinessDate::hoje();

        try {
            return $this->subscriptionService->proximaCobrancaApos($base, $plano);
        } catch (Throwable $erro) {
            Log::critical(
                'Pagamento registrado sem avançar a próxima cobrança: o plano não define um ciclo conhecido. '
                .'Corrija a periodicidade do plano e ajuste a data da assinatura à mão.',
                [
                    'company_id' => $assinatura->company_id,
                    'subscription_id' => $assinatura->getKey(),
                    'plan_id' => $assinatura->plan_id,
                    'erro' => $erro->getMessage(),
                ]
            );

            return null;
        }
    }

    // -----------------------------------------------------------------
    // Regras de empresa
    // -----------------------------------------------------------------

    /**
     * A fatura é de um tenant interno? Registra o aviso quando é.
     *
     * Defesa em profundidade nos dois caminhos que um webhook alcança
     * (`marcarComoVencida()` e `marcarComoCancelada()`, Task 7.6). O tenant
     * interno não é cobrado e não pode ser bloqueado, então não deveria ter
     * fatura nenhuma; se uma linha aparecer por importação ou correção manual em
     * banco, um evento vindo do provedor não a leva adiante. Vencida é a
     * situação que alimenta a régua de inadimplência (Task 7.7), cujo fim é
     * bloqueio de acesso, e é justamente esse fim que a regra existe para
     * impedir.
     *
     * A fatura é devolvida intacta, sem exceção: quem chama é o processamento de
     * webhook, e transformar isto em erro deixaria o evento em reprocessamento
     * eterno por causa de uma linha que nunca deveria ter existido. O aviso no
     * log é o que faz alguém olhar.
     *
     * @param  string  $operacao  Nome do método de origem, para achar o aviso no log.
     */
    private function ehFaturaDoTenantInterno(Invoice $fatura, string $operacao): bool
    {
        $empresa = $fatura->loadMissing('company')->company;

        if (! $empresa instanceof Company || ! $this->ehTenantInterno($empresa)) {
            return false;
        }

        Log::warning('Fatura do tenant interno: situação não alterada.', [
            'operacao' => $operacao,
            'company_id' => $empresa->getKey(),
            'invoice_id' => $fatura->getKey(),
            'referencia' => $fatura->referencia,
            'situacao' => $fatura->situacao,
        ]);

        return true;
    }

    /**
     * A empresa é o tenant interno?
     *
     * Confere o valor persistido, e não apenas o atributo em memória. A
     * diferença importa: uma instância que passou por `fill()` com dado de
     * formulário, ou que foi carregada com `select` parcial, poderia responder
     * "não é interno" para a empresa que é. Uma consulta a mais numa rotina que
     * roda uma vez por dia é preço barato pela única regra do plano que, se
     * falhar, derruba a operação do cliente que paga a conta.
     *
     * Vale o "ou": interno em memória também barra, para o caso de a empresa
     * ainda não existir no banco.
     *
     * Cópia deliberada de `SubscriptionService::ehTenantInterno()`. Ver o
     * cabeçalho da classe: cada Service que pode cobrar ou bloquear carrega a
     * sua.
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

    /**
     * Recusa referência fora do formato `YYYY-MM`.
     *
     * A coluna é string sem restrição no banco, e a referência é metade da
     * chave que impede fatura duplicada: um mês escrito de outro jeito
     * (`2026-7`, `07/2026`) não colidiria com o registro que já existe e viraria
     * uma segunda cobrança do mesmo período.
     */
    private function validarReferencia(string $referencia): void
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $referencia) !== 1) {
            throw new InvalidArgumentException(
                "Referência de fatura inválida: \"{$referencia}\". Use o formato YYYY-MM."
            );
        }
    }
}
