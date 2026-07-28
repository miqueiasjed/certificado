<?php

namespace App\Services;

use App\Models\AccessLog;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Subscription;
use App\Support\BusinessDate;
use App\Support\Gateway\RespostaDeAssinatura;
use App\Support\Gateway\RespostaDeCobranca;
use App\Support\TenantAtual;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Régua de inadimplência: avisa antes, bloqueia depois da tolerância e devolve
 * o acesso assim que o pagamento entra (Plano 7, Task 7.7).
 *
 * É a regra que o plano marca como "não pode falhar", porque um erro aqui
 * derruba a operação do cliente que sustenta o negócio hoje. Duas garantias
 * sustentam isso:
 *
 * 1. **Tenant interno nunca é avisado, nunca fica em atraso, nunca é
 *    suspenso.** A verificação está na consulta de `avaliar()` e repetida na
 *    primeira linha de cada método público, com retorno imediato. Nenhum deles
 *    lança exceção nesse caso: são chamados em lote por rotina e de dentro de
 *    `InvoiceService`, não são fluxo de usuário pedindo algo que precise ser
 *    recusado com mensagem.
 * 2. **Bloquear não apaga nem esconde nada.** A suspensão marca a situação da
 *    empresa e da assinatura, e mais nada; cliente, OS, certificado e
 *    financeiro continuam íntegros e voltam inteiros no pagamento.
 *
 * ## Esta classe é quem decide que uma fatura venceu
 *
 * O achado da Task 7.3 é que o PagBank não avisa boleto vencido por webhook:
 * não existe evento `cobranca_vencida` vindo do provedor para PIX e boleto (ver
 * o docblock de `PagBankGateway::eventoDePedido()`, "`cobranca_vencida` não
 * nasce aqui"). Na prática, `avaliar()` é o único lugar do sistema que
 * transiciona uma fatura de `aberta` para `vencida`, comparando
 * `Invoice.vencimento` com o dia de hoje no fuso do negócio.
 *
 * O ramo `cobranca_vencida` de `GatewayEventService` (Task 7.6) continua
 * existindo por completude do contrato, e para o dia em que entrar um gateway
 * que avise. Ele não é o que roda com o PagBank de hoje.
 *
 * ## Por que este Service não injeta `InvoiceService`
 *
 * A dependência entre os dois corre em uma direção só: `InvoiceService ->
 * InadimplenciaService -> TenantService`. `InvoiceService::marcarComoPaga()`
 * chama `reativarPorPagamento()` aqui, então injetar `InvoiceService` de volta
 * fecharia um ciclo que o container do Laravel não resolve, e o erro apareceria
 * em tempo de execução, na baixa de um pagamento real.
 *
 * A consequência prática é pequena: a única coisa que `avaliar()` precisaria de
 * `InvoiceService` é `marcarComoVencida()`, que é um `update` de uma coluna. Ele
 * é feito direto no Model aqui, e não vale acoplar dois Services por causa
 * disso. As constantes de vocabulário compartilhado
 * (`InvoiceService::MOTIVO_SUSPENSAO_INADIMPLENCIA`,
 * `InvoiceService::SITUACOES_QUE_O_PAGAMENTO_REATIVA`) continuam sendo lidas de
 * lá: constante de classe não passa pelo container e não cria ciclo nenhum.
 *
 * ## O envio dos avisos é o Plano 14
 *
 * Enquanto a central de notificações não existir, o aviso é registrado no log
 * da aplicação e em `access_logs`. `registrarAviso()` é o ponto único de
 * integração: é lá que a chamada ao despachante do Plano 14 entra, sem tocar na
 * decisão de quando avisar, que é desta classe.
 *
 * ## Escopo por empresa
 *
 * `subscriptions` e `invoices` ficam fora do escopo global (Task 7.1), e aqui
 * isso é o comportamento desejado: a rotina roda sem usuário autenticado e
 * precisa percorrer todos os tenants. Toda consulta filtra o que precisa
 * explicitamente, e a única escrita escopada por empresa (`AccessLog`) é feita
 * dentro de `TenantAtual::comTenant()`, para a linha nascer na empresa a que ela
 * se refere.
 */
class InadimplenciaService
{
    /**
     * Situações de assinatura que a régua avalia.
     *
     * `em_atraso` entra junto com `ativa`, e é o ponto do filtro: uma assinatura
     * que já caiu em atraso ontem é justamente a que precisa ser reavaliada hoje
     * para eventualmente ser suspensa. Filtrar só por `ativa` congelaria todo
     * inadimplente no primeiro dia de atraso.
     *
     * `suspensa` fica de fora porque já chegou ao fim da régua: reavaliá-la
     * todo dia só reescreveria `suspensa_em` e voltaria a suspender um tenant
     * que o super admin pode ter reativado de propósito. `cancelada` fica de
     * fora porque o contrato acabou, e cobrar acesso de quem não tem mais
     * assinatura não faz sentido.
     *
     * @var array<int, string>
     */
    public const SITUACOES_AVALIADAS = [
        RespostaDeAssinatura::SITUACAO_ATIVA,
        RespostaDeAssinatura::SITUACAO_EM_ATRASO,
    ];

    /**
     * Situações de fatura que representam dívida viva.
     *
     * `cancelada` não entra: cobrança cancelada não é dívida, e tratá-la como
     * tal bloquearia o tenant por uma fatura que o próprio provedor derrubou.
     *
     * @var array<int, string>
     */
    public const SITUACOES_NAO_PAGAS = [
        RespostaDeCobranca::SITUACAO_ABERTA,
        RespostaDeCobranca::SITUACAO_VENCIDA,
    ];

    /**
     * Desfecho de cada assinatura em uma passada de `avaliar()`, do mais brando
     * ao mais severo. Só o mais severo é reportado por assinatura.
     */
    public const ACAO_NENHUMA = 'nenhuma';

    public const ACAO_AVISO_VENCIMENTO = 'aviso_vencimento';

    public const ACAO_EM_ATRASO = 'em_atraso';

    public const ACAO_AVISO_COBRANCA = 'aviso_cobranca';

    public const ACAO_SUSPENSA = 'suspensa';

    /**
     * Eventos gravados em `access_logs.evento`.
     *
     * Prefixados com o domínio para que a tela de auditoria consiga separar o
     * que é régua de cobrança do que é login e de "assumir tenant".
     */
    public const EVENTO_AVISO_VENCIMENTO = 'inadimplencia.aviso_vencimento';

    public const EVENTO_AVISO_COBRANCA = 'inadimplencia.aviso_cobranca';

    public const EVENTO_SUSPENSAO = 'inadimplencia.suspensao';

    public function __construct(
        private readonly TenantService $tenantService,
    ) {}

    /**
     * Percorre as assinaturas com fatura em aberto ou vencida e aplica a régua a
     * cada uma.
     *
     * Ordem da decisão, por assinatura, olhando a fatura não paga mais antiga:
     *
     * - **Ainda não venceu**, e falta um dos `dias_de_aviso`: registra o aviso
     *   de vencimento próximo. Nada mais muda.
     * - **Venceu antes de hoje** e a fatura ainda está `aberta`: passa para
     *   `vencida` (ver o cabeçalho da classe: aqui é o único lugar que faz essa
     *   transição na prática).
     * - **Venceu há menos que a tolerância**: assinatura vai para `em_atraso`,
     *   sem suspender. Se o dia de hoje bate com um dos `dias_de_cobranca`,
     *   registra a cobrança.
     * - **Venceu há `dias_de_tolerancia` dias ou mais**: suspende.
     *
     * "Venceu antes de hoje" é comparação de dia no fuso do negócio, nunca de
     * instante: a fatura que vence hoje não está vencida às 21h de Brasília, e
     * comparar em UTC bloquearia o tenant um dia antes do contrato.
     *
     * Erro de uma assinatura não impede as demais: cada volta do laço é isolada,
     * a exceção vai para o log da aplicação e vira uma linha com `erro`
     * preenchido no resumo. Uma assinatura com dado inconsistente não pode
     * impedir a suspensão (nem o aviso) de todas as outras.
     *
     * @return array<int, array{
     *     assinatura: Subscription,
     *     empresa: ?Company,
     *     fatura: ?Invoice,
     *     acao: string,
     *     dias: int,
     *     marcou_vencida: bool,
     *     erro: ?string
     * }>
     */
    public function avaliar(): array
    {
        $hoje = BusinessDate::hoje();
        $resumo = [];

        foreach ($this->assinaturasComDivida() as $assinatura) {
            $empresa = $assinatura->company;

            // Tenant interno já foi excluído na consulta. A repetição aqui é a
            // defesa em profundidade da regra que não pode falhar: o `continue`
            // tira a assinatura do resumo antes de qualquer decisão, e nem o
            // aviso mais inofensivo chega a ser registrado.
            if ($empresa instanceof Company && $this->ehTenantInterno($empresa)) {
                Log::warning('Tenant interno alcançado pela régua de inadimplência: ignorado.', [
                    'company_id' => $empresa->getKey(),
                    'subscription_id' => $assinatura->getKey(),
                ]);

                continue;
            }

            $resumo[] = $this->avaliarIsolando($assinatura, $hoje);
        }

        return $resumo;
    }

    /**
     * Suspende o acesso do tenant por falta de pagamento.
     *
     * Assinatura para `suspensa` e empresa para `suspensa` com
     * `motivo_suspensao = inadimplencia`, na mesma transação: uma assinatura
     * marcada como suspensa cuja empresa continuou ativa seria um tenant que a
     * régua considera bloqueado e que segue operando, e o inverso seria um
     * tenant bloqueado que a régua não sabe ter bloqueado.
     *
     * O motivo vem de `InvoiceService::MOTIVO_SUSPENSAO_INADIMPLENCIA`, e não do
     * literal: é o mesmo valor que `reativarPorPagamento()` exige para devolver
     * o acesso. Se as duas pontas repetissem a string, bastaria uma delas mudar
     * de grafia para o tenant continuar bloqueado depois de pagar.
     *
     * Empresa já suspensa não é suspensa de novo, por dois motivos: reescrever
     * `suspensa_em` todo dia apagaria a data real do bloqueio, e uma empresa
     * suspensa por outro motivo (fraude, uso indevido) teria o motivo trocado
     * para inadimplência, o que faria o próximo pagamento reativá-la contra a
     * decisão do super admin.
     *
     * Tenant interno sai antes de qualquer escrita. `TenantService::suspender()`
     * já recusa por conta própria, e lança; a checagem aqui evita a consulta e
     * deixa o retorno antecipado explícito no fluxo desta classe.
     */
    public function suspenderPorInadimplencia(Subscription $assinatura): void
    {
        $empresa = $assinatura->loadMissing('company')->company;

        if (! $empresa instanceof Company) {
            Log::warning('Assinatura sem empresa: suspensão por inadimplência não aplicada.', [
                'subscription_id' => $assinatura->getKey(),
            ]);

            return;
        }

        if ($this->ehTenantInterno($empresa)) {
            Log::warning('Tenant interno não é suspenso por inadimplência: nada foi alterado.', [
                'company_id' => $empresa->getKey(),
                'subscription_id' => $assinatura->getKey(),
            ]);

            return;
        }

        DB::transaction(function () use ($assinatura, $empresa): void {
            if ($assinatura->situacao !== RespostaDeAssinatura::SITUACAO_SUSPENSA) {
                $assinatura->update(['situacao' => RespostaDeAssinatura::SITUACAO_SUSPENSA]);
            }

            if ($empresa->situacao !== TenantService::SITUACAO_SUSPENSA) {
                $this->tenantService->suspender(
                    $empresa,
                    InvoiceService::MOTIVO_SUSPENSAO_INADIMPLENCIA
                );
            }
        });
    }

    /**
     * Devolve o acesso depois do pagamento.
     *
     * Chamado por `InvoiceService::marcarComoPaga()`, de dentro da transação que
     * ele já abriu: baixa da fatura, avanço do ciclo e reativação são um efeito
     * atômico só. **Este método não abre transação própria**, de propósito, e
     * quem o chamar de outro lugar precisa saber disso.
     *
     * O que ele reativa, e o que não reativa:
     *
     * - A **assinatura** volta para `ativa` quando está `em_atraso` ou
     *   `suspensa` (`InvoiceService::SITUACOES_QUE_O_PAGAMENTO_REATIVA`).
     *   Cancelada continua cancelada: quem cancelou pediu para encerrar, e uma
     *   fatura antiga paga depois disso quita o período, não ressuscita o
     *   contrato.
     * - A **empresa** só é reativada quando está suspensa **e** o motivo gravado
     *   é `inadimplencia`. Empresa que o super admin suspendeu por fraude ou uso
     *   indevido continua suspensa depois de alguém quitar uma fatura antiga
     *   dela: reativar por pagamento uma suspensão que não era por pagamento
     *   devolveria acesso que ninguém autorizou.
     *
     * Nenhum dado do tenant é tocado: a reativação limpa as marcas da suspensão
     * (`situacao`, `suspensa_em`, `motivo_suspensao`) e o sistema volta
     * exatamente como estava, porque o bloqueio nunca escondeu nem apagou nada.
     */
    public function reativarPorPagamento(Subscription $assinatura): void
    {
        $empresa = $assinatura->loadMissing('company')->company;

        if ($empresa instanceof Company && $this->ehTenantInterno($empresa)) {
            Log::warning('Tenant interno alcançado pela reativação por pagamento: nada foi alterado.', [
                'company_id' => $empresa->getKey(),
                'subscription_id' => $assinatura->getKey(),
            ]);

            return;
        }

        if (in_array($assinatura->situacao, InvoiceService::SITUACOES_QUE_O_PAGAMENTO_REATIVA, true)) {
            $assinatura->update(['situacao' => RespostaDeAssinatura::SITUACAO_ATIVA]);
        }

        if ($empresa instanceof Company && $this->foiSuspensaPorInadimplencia($empresa)) {
            $this->tenantService->reativar($empresa);
        }
    }

    /**
     * Fatura não paga mais antiga da empresa, que é a que a tela de conta
     * suspensa mostra e a que precisa ser quitada para o acesso voltar.
     *
     * Ordenada por vencimento crescente entre `aberta` e `vencida`: quando
     * existe uma vencida, ela é necessariamente a mais antiga das duas, então
     * esta única consulta já entrega "a vencida mais antiga" sem precisar de um
     * segundo caminho para o caso de não haver nenhuma vencida.
     *
     * O filtro por `company_id` é explícito porque `invoices` fica fora do
     * escopo global por empresa (Task 7.1). Esquecer o `where` aqui mostraria a
     * fatura de um tenant para outro, na tela em que ele está justamente
     * copiando um código de pagamento.
     */
    public function faturaEmAbertoDe(Company $empresa): ?Invoice
    {
        return Invoice::query()
            ->where('company_id', $empresa->getKey())
            ->whereIn('situacao', self::SITUACOES_NAO_PAGAS)
            ->orderBy('vencimento')
            ->orderBy('id')
            ->first();
    }

    // -----------------------------------------------------------------
    // Uma volta do laço
    // -----------------------------------------------------------------

    /**
     * Assinaturas que a régua precisa olhar hoje, com a empresa e a fatura não
     * paga mais antiga já carregadas.
     *
     * Tenant interno é excluído aqui, na consulta, e não depois em memória: o
     * mais barato é ele nunca chegar ao laço. `is_internal` tem default `false`
     * no banco e não é nulável, então a comparação direta cobre todas as linhas.
     *
     * A fatura vem por eager loading limitado às situações não pagas e ordenado
     * por vencimento, o que resolve o laço inteiro em duas consultas em vez de
     * uma por assinatura.
     *
     * @return Collection<int, Subscription>
     */
    private function assinaturasComDivida(): Collection
    {
        return Subscription::query()
            ->with([
                'company',
                'invoices' => fn ($consulta) => $consulta
                    ->whereIn('situacao', self::SITUACOES_NAO_PAGAS)
                    ->orderBy('vencimento')
                    ->orderBy('id'),
            ])
            ->whereIn('situacao', self::SITUACOES_AVALIADAS)
            ->whereHas('company', fn ($consulta) => $consulta->where('is_internal', false))
            ->whereHas('invoices', fn ($consulta) => $consulta->whereIn('situacao', self::SITUACOES_NAO_PAGAS))
            ->orderBy('id')
            ->get();
    }

    /**
     * Uma assinatura do laço de `avaliar()`, com o erro dela contido.
     *
     * @return array{
     *     assinatura: Subscription,
     *     empresa: ?Company,
     *     fatura: ?Invoice,
     *     acao: string,
     *     dias: int,
     *     marcou_vencida: bool,
     *     erro: ?string
     * }
     */
    private function avaliarIsolando(Subscription $assinatura, CarbonImmutable $hoje): array
    {
        $linha = [
            'assinatura' => $assinatura,
            'empresa' => $assinatura->company,
            'fatura' => $assinatura->invoices->first(),
            'acao' => self::ACAO_NENHUMA,
            'dias' => 0,
            'marcou_vencida' => false,
            'erro' => null,
        ];

        try {
            return $this->aplicarRegua($linha, $hoje);
        } catch (Throwable $erro) {
            report($erro);

            $linha['erro'] = $erro->getMessage();

            return $linha;
        }
    }

    /**
     * A decisão em si. Ver a ordem completa no docblock de `avaliar()`.
     *
     * @param  array{assinatura: Subscription, empresa: ?Company, fatura: ?Invoice, acao: string, dias: int, marcou_vencida: bool, erro: ?string}  $linha
     * @return array{assinatura: Subscription, empresa: ?Company, fatura: ?Invoice, acao: string, dias: int, marcou_vencida: bool, erro: ?string}
     */
    private function aplicarRegua(array $linha, CarbonImmutable $hoje): array
    {
        $assinatura = $linha['assinatura'];
        $empresa = $linha['empresa'];
        $fatura = $linha['fatura'];

        if (! $fatura instanceof Invoice || ! $empresa instanceof Company) {
            return $linha;
        }

        $vencimento = BusinessDate::paraFusoNegocio($fatura->vencimento)?->startOfDay();

        if ($vencimento === null) {
            Log::warning('Fatura sem vencimento: fora da régua de inadimplência.', [
                'company_id' => $empresa->getKey(),
                'invoice_id' => $fatura->getKey(),
                'referencia' => $fatura->referencia,
            ]);

            return $linha;
        }

        // Positivo é atraso, negativo é o que ainda falta para vencer. Dia
        // contra dia no fuso do negócio, os dois já com a hora zerada.
        //
        // `round()`, e não cast direto: a diferença sai em float, e um dia com
        // 23 ou 25 horas (horário de verão em data antiga) devolveria 4,96 dias
        // onde são 5, o que atrasaria em um dia o fim da tolerância.
        $dias = (int) round($vencimento->diffInDays($hoje, false));
        $linha['dias'] = $dias;

        if ($dias <= 0) {
            return $this->avisarVencimentoProximo($linha, $empresa, $fatura, -$dias);
        }

        if ($fatura->situacao === RespostaDeCobranca::SITUACAO_ABERTA) {
            $fatura->update(['situacao' => RespostaDeCobranca::SITUACAO_VENCIDA]);
            $linha['marcou_vencida'] = true;
        }

        if ($dias >= $this->diasDeTolerancia()) {
            $this->suspenderPorInadimplencia($assinatura);
            $this->registrarAviso(self::EVENTO_SUSPENSAO, $empresa, $fatura, $dias);

            $linha['acao'] = self::ACAO_SUSPENSA;

            return $linha;
        }

        if ($assinatura->situacao !== RespostaDeAssinatura::SITUACAO_EM_ATRASO) {
            $assinatura->update(['situacao' => RespostaDeAssinatura::SITUACAO_EM_ATRASO]);
        }

        $linha['acao'] = self::ACAO_EM_ATRASO;

        if (in_array($dias, $this->diasDeCobranca(), true)) {
            $this->registrarAviso(self::EVENTO_AVISO_COBRANCA, $empresa, $fatura, $dias);

            $linha['acao'] = self::ACAO_AVISO_COBRANCA;
        }

        return $linha;
    }

    /**
     * Fatura que ainda não venceu: só avisa, e só no dia exato de um dos marcos.
     *
     * Nada é alterado neste caminho. Quem está em dia com uma fatura a vencer
     * não fica em atraso por ter recebido um lembrete.
     *
     * @param  array{assinatura: Subscription, empresa: ?Company, fatura: ?Invoice, acao: string, dias: int, marcou_vencida: bool, erro: ?string}  $linha
     * @param  int  $faltam  Dias que faltam para o vencimento. Zero é "vence hoje".
     * @return array{assinatura: Subscription, empresa: ?Company, fatura: ?Invoice, acao: string, dias: int, marcou_vencida: bool, erro: ?string}
     */
    private function avisarVencimentoProximo(array $linha, Company $empresa, Invoice $fatura, int $faltam): array
    {
        if (! in_array($faltam, $this->diasDeAviso(), true)) {
            return $linha;
        }

        $this->registrarAviso(self::EVENTO_AVISO_VENCIMENTO, $empresa, $fatura, -$faltam);

        $linha['acao'] = self::ACAO_AVISO_VENCIMENTO;

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
     * notificações. A decisão de quando avisar é desta classe e não muda; o que
     * falta é o canal. Enquanto ele não existe, o registro em `access_logs` é o
     * que permite conferir depois que o tenant foi avisado antes de ser
     * bloqueado, que é a pergunta que aparece quando alguém reclama do bloqueio.
     *
     * A linha nasce dentro de `TenantAtual::comTenant()` da empresa avisada,
     * mesmo padrão de `AssumirTenantService::registrar()`: `AccessLog` usa
     * `BelongsToCompany` e preencheria `company_id` a partir do tenant corrente,
     * que numa rotina de linha de comando é nenhum.
     *
     * `user_id`, `ip` e `user_agent` ficam nulos: não há usuário nem requisição:
     * quem age é a rotina. `email` é o da empresa (a coluna não aceita nulo), o
     * que também é o destinatário natural do aviso quando o Plano 14 entrar.
     *
     * Falha na gravação é engolida e vai para o log. Auditoria de aviso não pode
     * impedir a régua de avaliar os demais tenants, nem de suspender quem já
     * estourou a tolerância.
     *
     * @param  int  $dias  Dias de atraso (positivo) ou que faltam para vencer (negativo).
     */
    private function registrarAviso(string $evento, Company $empresa, Invoice $fatura, int $dias): void
    {
        $detalhes = [
            'company_id' => (int) $empresa->getKey(),
            'invoice_id' => (int) $fatura->getKey(),
            'referencia' => $fatura->referencia,
            'valor' => (string) $fatura->valor,
            'vencimento' => BusinessDate::diaDe($fatura->vencimento),
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
            Log::error('Falha ao registrar o aviso da régua de inadimplência em access_logs. A régua seguiu.', [
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
     * Dias entre o vencimento e o bloqueio.
     *
     * Piso de 1: tolerância zero ou negativa suspenderia no mesmo dia em que a
     * fatura passou a estar vencida, sem nenhuma folga para compensação
     * bancária, e um valor errado em `.env` não pode virar bloqueio imediato de
     * todos os tenants em atraso.
     */
    private function diasDeTolerancia(): int
    {
        return max(1, (int) config('assinatura.dias_de_tolerancia', 5));
    }

    /**
     * Marcos de aviso antes do vencimento, em dias.
     *
     * @return array<int, int>
     */
    private function diasDeAviso(): array
    {
        return $this->diasConfigurados('assinatura.dias_de_aviso', [3, 1]);
    }

    /**
     * Marcos de cobrança depois do vencimento, em dias.
     *
     * @return array<int, int>
     */
    private function diasDeCobranca(): array
    {
        return $this->diasConfigurados('assinatura.dias_de_cobranca', [1, 3, 5]);
    }

    /**
     * Lê uma lista de marcos da configuração, normalizada para inteiros
     * positivos.
     *
     * A normalização existe porque a comparação com o dia calculado é estrita
     * (`in_array(..., true)`): um `"3"` vindo de config cacheado ou de um valor
     * editado à mão nunca casaria com o inteiro `3`, e o aviso simplesmente não
     * sairia, sem erro nenhum para denunciar.
     *
     * @param  array<int, int>  $padrao
     * @return array<int, int>
     */
    private function diasConfigurados(string $chave, array $padrao): array
    {
        $valor = config($chave, $padrao);

        if (! is_array($valor)) {
            return $padrao;
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
     * A empresa está suspensa, e suspensa por falta de pagamento?
     *
     * As duas condições juntas, sempre. Ver `reativarPorPagamento()`.
     */
    private function foiSuspensaPorInadimplencia(Company $empresa): bool
    {
        return $empresa->situacao === TenantService::SITUACAO_SUSPENSA
            && $empresa->motivo_suspensao === InvoiceService::MOTIVO_SUSPENSAO_INADIMPLENCIA;
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
     * Cópia deliberada de `InvoiceService::ehTenantInterno()` e de
     * `SubscriptionService::ehTenantInterno()`: cada Service que pode cobrar ou
     * bloquear carrega a sua, e o critério da Task 7.11 é que remover a
     * verificação de qualquer um deles derrube `TenantInternoImuneTest`.
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
