<?php

namespace App\Services;

use App\Contracts\GatewayAssinatura;
use App\Exceptions\AssinaturaInvalidaException;
use App\Exceptions\TenantInternoException;
use App\Models\Company;
use App\Models\Plan;
use App\Models\Subscription;
use App\Support\BusinessDate;
use App\Support\Gateway\RespostaDeAssinatura;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Assinatura do tenant no plano comercial: contratar, trocar de plano, trocar a
 * forma de pagamento, cancelar e consultar (Plano 7, Task 7.4).
 *
 * É a cobrança que a plataforma faz do tenant, não a que o tenant faz dos
 * clientes dele (que é o Plano 19).
 *
 * ## A regra que não pode falhar
 *
 * **Tenant interno (`companies.is_internal = true`) não assina, não é cobrado e
 * não pode ser bloqueado.** A verificação é a primeira linha de toda entrada
 * pública deste Service, antes de qualquer escrita e antes de qualquer chamada
 * ao provedor. O tenant interno é a empresa que opera o sistema hoje e gera a
 * receita atual do negócio: criar assinatura para ela a colocaria dentro da
 * régua de inadimplência do Plano 7, onde uma fatura não paga termina em
 * bloqueio de acesso.
 *
 * A checagem é feita aqui, e não delegada a `TenantService`, de propósito: o
 * critério da Task 7.11 é que remover a verificação de qualquer Service derrube
 * `TenantInternoImuneTest`. Cada Service que pode cobrar ou bloquear carrega a
 * sua, e uma cópia de quatro linhas custa menos que a chance de alguém trocar a
 * injeção do outro Service por um dublê e apagar a regra sem perceber.
 *
 * ## Ordem de toda operação que fala com o provedor
 *
 * 1. Recusas do domínio (tenant interno, forma desconhecida, cartão ausente,
 *    assinatura já existente). Nada aconteceu em lugar nenhum.
 * 2. Contas que podem falhar (data da próxima cobrança). Ainda nada aconteceu.
 * 3. Chamada ao provedor, **fora** da transação. Fazer HTTP dentro de uma
 *    transação segura lock de banco pelo tempo da rede, e o rollback não
 *    desfaz nada do lado do provedor de qualquer forma.
 * 4. Gravação no banco, dentro de `DB::transaction()`.
 *
 * É essa ordem que cumpre as duas exigências da task: falha no provedor não
 * deixa assinatura no banco (porque nada foi gravado ainda), e falha no banco
 * depois do provedor ter aceitado vira pendência registrada em log com o
 * identificador do provedor, para conciliação manual. Ver `gravar()`.
 *
 * ## Cartão: o passo que o provedor exige antes
 *
 * O cartão é cifrado no navegador com a chave pública da conta no provedor;
 * número, validade e código de segurança nunca chegam ao servidor. Com forma
 * `cartao`, `registrarMeioDePagamento()` vem antes de `criarAssinatura()`,
 * porque sem meio de pagamento cadastrado o provedor não tem com o que cobrar a
 * recorrência (ver o contrato em `App\Contracts\GatewayAssinatura`). Chamar de
 * novo troca o cartão em vigor: é assim que a troca de cartão do tenant
 * acontece, e por isso `trocarFormaDePagamento()` com forma `cartao` também
 * exige o cifrado.
 *
 * Cartão ausente em forma `cartao` é `AssinaturaInvalidaException`, nunca
 * `GatewayRecusouException`: o formulário é que não enviou o cartão, e dizer ao
 * tenant que o cartão dele foi recusado seria mentira cara de suporte.
 *
 * ## O que este Service não faz, de propósito
 *
 * - Não gera fatura nem cobra (Task 7.5) e não aplica régua de inadimplência
 *   (Task 7.7). Não recebe webhook: quem recebe é `GatewayEventService`
 *   (Task 7.6), e a única porta que ele usa aqui é
 *   `refletirCancelamentoDoGateway()`.
 * - Não bloqueia nem reativa acesso. Cancelar assinatura **não** mexe em
 *   `companies.situacao` nem em `companies.plan_id`: o tenant usa o que pagou
 *   até o fim do período.
 * - Não consulta o usuário autenticado. Quem chama informa a empresa.
 * - Não filtra por escopo global: `subscriptions` fica fora dele (Task 7.1), e
 *   por isso toda consulta aqui filtra `company_id` explicitamente.
 */
class SubscriptionService
{
    /**
     * Formas de pagamento aceitas em `subscriptions.forma_pagamento`.
     *
     * Públicas para que o FormRequest e as telas da Task 7.8 validem contra a
     * mesma fonte, em vez de repetirem literais que depois divergem, no mesmo
     * critério de `TenantService::SITUACOES`.
     *
     * `PagBankGateway` declara os mesmos três valores do lado dele. A repetição
     * é deliberada e tem uma direção: o domínio não importa a implementação
     * concreta do gateway, senão trocar de provedor voltaria a exigir mexer
     * neste arquivo, que é exatamente o que a interface existe para evitar.
     */
    public const FORMA_PIX = 'pix';

    public const FORMA_BOLETO = 'boleto';

    public const FORMA_CARTAO = 'cartao';

    /**
     * @var array<int, string>
     */
    public const FORMAS = [
        self::FORMA_PIX,
        self::FORMA_BOLETO,
        self::FORMA_CARTAO,
    ];

    /**
     * Periodicidades de plano que este Service sabe converter em data de
     * próxima cobrança. É o mesmo par validado em `PlanRequest`.
     */
    public const PERIODICIDADE_MENSAL = 'mensal';

    public const PERIODICIDADE_ANUAL = 'anual';

    /**
     * @var array<int, string>
     */
    public const PERIODICIDADES = [
        self::PERIODICIDADE_MENSAL,
        self::PERIODICIDADE_ANUAL,
    ];

    /**
     * Situações em que a assinatura continua existindo para o provedor.
     *
     * Assinatura em atraso ou suspensa por inadimplência não é assinatura
     * livre: contratar por cima dela criaria uma segunda no provedor, cobrando
     * o tenant duas vezes. Só `cancelada` libera contratar de novo.
     *
     * @var array<int, string>
     */
    public const SITUACOES_EM_VIGOR = [
        RespostaDeAssinatura::SITUACAO_ATIVA,
        RespostaDeAssinatura::SITUACAO_EM_ATRASO,
        RespostaDeAssinatura::SITUACAO_SUSPENSA,
    ];

    /**
     * Motivo gravado em `subscriptions.motivo_cancelamento` quando quem encerrou
     * a assinatura foi o provedor, e o sistema só ficou sabendo pelo webhook
     * (`refletirCancelamentoDoGateway()`, Task 7.6).
     *
     * Texto fixo, e não o campo livre de `cancelar()`: aqui não existe pedido de
     * ninguém deste lado para citar. Pública porque a tela do tenant (Task 7.8)
     * precisa reconhecer este caso para explicar o encerramento em vez de
     * repetir o texto cru; se os dois lados repetissem o literal, bastaria um
     * mudar de grafia para a tela deixar de reconhecê-lo.
     */
    public const MOTIVO_CANCELADO_PELO_PROVEDOR = 'Cancelada pelo provedor de pagamento.';

    public function __construct(
        private readonly GatewayAssinatura $gateway
    ) {}

    /**
     * Contrata o plano para o tenant: cria a assinatura no provedor e grava a
     * linha de `subscriptions`.
     *
     * `inicio_em` é hoje no fuso do negócio e `proxima_cobranca_em` é hoje mais
     * um período do plano, os dois campos `date`: vencimento é um dia do
     * calendário, e converter fuso em campo `date` é o que produz o erro de um
     * dia. As duas datas são calculadas **antes** de falar com o provedor, para
     * que um plano com periodicidade desconhecida seja recusado sem deixar
     * assinatura órfã do lado de lá.
     *
     * `companies.plan_id` é atualizado na mesma transação. É esse vínculo que o
     * Plano 6 lê para liberar módulo e limite: gravar o plano só na assinatura
     * deixaria o tenant pagando um plano e usando outro.
     *
     * Empresa que já tem assinatura em vigor é recusada antes do provedor. Uma
     * assinatura cancelada é reaproveitada (a mesma linha, porque
     * `subscriptions.company_id` é unique), com as marcas do cancelamento
     * limpas.
     *
     * @param  string  $formaPagamento  Um de `self::FORMAS`.
     * @param  string|null  $cartaoCifrado  Obrigatório, e só usado, na forma `cartao`. Resultado da cifragem feita no navegador; em pix e boleto é ignorado mesmo que venha preenchido, porque não há meio de pagamento a guardar.
     *
     * @throws TenantInternoException Quando a empresa é o tenant interno.
     * @throws AssinaturaInvalidaException Forma desconhecida, cartão ausente na forma cartão, periodicidade do plano desconhecida, ou empresa que já assina.
     * @throws \App\Exceptions\GatewayRecusouException Quando o provedor responde e recusa.
     * @throws \App\Exceptions\GatewayIndisponivelException Quando o provedor não responde.
     */
    public function assinar(
        Company $empresa,
        Plan $plano,
        string $formaPagamento,
        ?string $cartaoCifrado = null
    ): Subscription {
        if ($this->ehTenantInterno($empresa)) {
            throw TenantInternoException::naoPodeAssinar($empresa->name);
        }

        $this->exigirFormaConhecida($formaPagamento);
        $cifrado = $this->exigirCartaoQuandoNecessario($formaPagamento, $cartaoCifrado);

        $assinaturaAtual = $this->assinaturaDe($empresa);

        if ($assinaturaAtual !== null && in_array($assinaturaAtual->situacao, self::SITUACOES_EM_VIGOR, true)) {
            throw AssinaturaInvalidaException::empresaJaAssinou(
                $empresa->name,
                (string) $assinaturaAtual->situacao
            );
        }

        $inicio = BusinessDate::hoje();
        $proximaCobranca = $this->proximaCobrancaApos($inicio, $plano);

        if ($cifrado !== null) {
            $this->gateway->registrarMeioDePagamento($empresa, $cifrado);
        }

        $resposta = $this->gateway->criarAssinatura($empresa, $plano, $formaPagamento);

        return $this->gravar(
            operacao: 'assinar',
            empresa: $empresa,
            idNoGateway: $resposta->idNoGateway,
            gravacao: function () use ($empresa, $plano, $formaPagamento, $resposta, $inicio, $proximaCobranca): Subscription {
                $assinatura = Subscription::updateOrCreate(
                    ['company_id' => $empresa->id],
                    [
                        'plan_id' => $plano->id,
                        'situacao' => $resposta->situacao,
                        'forma_pagamento' => $formaPagamento,
                        'gateway_subscription_id' => $resposta->idNoGateway,
                        'inicio_em' => $inicio->toDateString(),
                        'proxima_cobranca_em' => $proximaCobranca->toDateString(),
                        // Contratação nova apaga a marca do cancelamento
                        // anterior: sem isso a linha reaproveitada continuaria
                        // dizendo que foi cancelada, com data e motivo antigos.
                        'cancelada_em' => null,
                        'motivo_cancelamento' => null,
                    ]
                );

                $empresa->update(['plan_id' => $plano->id]);

                return $assinatura->refresh();
            },
        );
    }

    /**
     * Troca o plano de uma assinatura em vigor, no provedor e no banco.
     *
     * `proxima_cobranca_em` **não** se move: o período corrente já foi cobrado
     * pelo plano antigo, e o novo passa a valer no próximo ciclo. Antecipar a
     * data cobraria o tenant duas vezes no mesmo mês.
     *
     * Rebaixar plano não apaga nem arquiva registro nenhum, aqui nem em lugar
     * algum: um tenant que caia do plano de 500 clientes para o de 100 continua
     * com os 500 cadastrados, e o que muda é o que ele consegue criar dali em
     * diante (regra do Plano 6, que se aplica sozinha assim que
     * `companies.plan_id` muda). Apagar dado de cliente por decisão comercial
     * seria perda irreversível.
     *
     * A situação vem do que o provedor respondeu, sem tradução no meio do
     * caminho: ele é a fonte da verdade sobre a própria assinatura. Isso não
     * devolve acesso a tenant suspenso por inadimplência, porque
     * `companies.situacao` não é tocada aqui; quem reativa é o pagamento
     * (Tasks 7.5 e 7.7).
     *
     * Trocar para o plano que a assinatura já tem não chama o provedor e
     * devolve a assinatura como está.
     *
     * @throws TenantInternoException Quando a empresa da assinatura é o tenant interno.
     * @throws AssinaturaInvalidaException Quando a assinatura não tem identificador no provedor.
     */
    public function trocarPlano(Subscription $assinatura, Plan $novoPlano): Subscription
    {
        $empresa = $this->empresaDa($assinatura);

        if ($this->ehTenantInterno($empresa)) {
            throw TenantInternoException::naoTemAssinatura($empresa->name);
        }

        if ((int) $assinatura->plan_id === (int) $novoPlano->id) {
            return $assinatura;
        }

        $idNoGateway = $this->exigirIdNoGateway($assinatura, 'trocar o plano');

        $resposta = $this->gateway->trocarPlano($idNoGateway, $novoPlano);

        return $this->gravar(
            operacao: 'trocarPlano',
            empresa: $empresa,
            idNoGateway: $resposta->idNoGateway,
            gravacao: function () use ($assinatura, $novoPlano, $empresa, $resposta): Subscription {
                $assinatura->update([
                    'plan_id' => $novoPlano->id,
                    'situacao' => $resposta->situacao,
                    'gateway_subscription_id' => $resposta->idNoGateway,
                ]);

                $empresa->update(['plan_id' => $novoPlano->id]);

                return $assinatura->refresh();
            },
        );
    }

    /**
     * Troca a forma de pagamento da assinatura.
     *
     * Com forma `cartao`, `registrarMeioDePagamento()` vem antes de refletir a
     * troca: é a chamada que cadastra o cartão cifrado no provedor, e chamá-la
     * de novo para quem já tem cartão é justamente como a troca de cartão
     * acontece. Por isso a forma `cartao` sempre passa pelo provedor, mesmo
     * quando a assinatura já estava no cartão: o pedido nesse caso é trocar o
     * cartão, não a forma.
     *
     * Pix e boleto não têm meio de pagamento a guardar, então a troca para
     * elas é só a gravação local, e um `$cartaoCifrado` que venha junto é
     * ignorado em vez de virar erro.
     *
     * Limite conhecido, registrado de propósito: o contrato do gateway não
     * expõe alteração da forma de pagamento de uma assinatura já criada no
     * provedor. Na prática a troca vale a partir da próxima fatura, que é
     * emitida pela forma gravada aqui (`InvoiceService`, Task 7.5, lê
     * `subscription.forma_pagamento`). Sair do cartão para pix ou boleto no
     * meio de um ciclo exige conciliar a recorrência no painel do provedor;
     * quando houver um método no contrato para isso, ele entra aqui.
     *
     * @param  string  $forma  Um de `self::FORMAS`.
     * @param  string|null  $cartaoCifrado  Obrigatório na forma `cartao`, ignorado nas demais.
     *
     * @throws TenantInternoException Quando a empresa da assinatura é o tenant interno.
     * @throws AssinaturaInvalidaException Forma desconhecida ou cartão ausente na forma cartão.
     */
    public function trocarFormaDePagamento(
        Subscription $assinatura,
        string $forma,
        ?string $cartaoCifrado = null
    ): Subscription {
        $empresa = $this->empresaDa($assinatura);

        if ($this->ehTenantInterno($empresa)) {
            throw TenantInternoException::naoTemAssinatura($empresa->name);
        }

        $this->exigirFormaConhecida($forma);
        $cifrado = $this->exigirCartaoQuandoNecessario($forma, $cartaoCifrado);

        if ($cifrado === null && $forma === $assinatura->forma_pagamento) {
            return $assinatura;
        }

        if ($cifrado !== null) {
            $this->gateway->registrarMeioDePagamento($empresa, $cifrado);
        }

        return $this->gravar(
            operacao: 'trocarFormaDePagamento',
            empresa: $empresa,
            idNoGateway: $assinatura->gateway_subscription_id,
            // Só há pendência a conciliar quando o cartão já foi cadastrado no
            // provedor. Em pix e boleto nada saiu daqui, e registrar pendência
            // nesse caso encheria de ruído um log que precisa continuar sendo
            // lido como alarme.
            provedorJaMudou: $cifrado !== null,
            gravacao: function () use ($assinatura, $forma): Subscription {
                $assinatura->update(['forma_pagamento' => $forma]);

                return $assinatura->refresh();
            },
        );
    }

    /**
     * Cancela a assinatura no provedor e registra o cancelamento.
     *
     * O acesso continua até o fim do período já pago. Cancelar aqui **não**
     * mexe em `companies.situacao` nem em `companies.plan_id`, e não apaga
     * nada: `proxima_cobranca_em` fica onde está, marcando justamente o fim do
     * período pago, e é ela que a tela do tenant e a régua leem para saber até
     * quando o acesso vale. Cortar o acesso no clique do cancelamento tiraria
     * do tenant um período que ele já pagou.
     *
     * `cancelada_em` é instante, gravado com `now()` no mesmo UTC de
     * `created_at`: o fuso do negócio entra na exibição. Usar o "agora" de
     * Brasília aqui gravaria a hora de Brasília rotulada como UTC, que é o erro
     * que a skill `datas-timezone` proíbe.
     *
     * Cancelar assinatura já cancelada não chama o provedor e devolve a
     * assinatura como está: o cancelamento no PagBank é definitivo, e repetir a
     * chamada só renderia uma recusa 4xx.
     *
     * Assinatura sem identificador no provedor é cancelada só no banco, com
     * aviso em log. É a exceção deliberada à regra de `trocarPlano()`: recusar
     * o cancelamento deixaria o tenant preso a uma assinatura que ele pediu
     * para encerrar.
     *
     * @throws TenantInternoException Quando a empresa da assinatura é o tenant interno.
     */
    public function cancelar(Subscription $assinatura, string $motivo): Subscription
    {
        $empresa = $this->empresaDa($assinatura);

        if ($this->ehTenantInterno($empresa)) {
            throw TenantInternoException::naoTemAssinatura($empresa->name);
        }

        if ($assinatura->situacao === RespostaDeAssinatura::SITUACAO_CANCELADA) {
            return $assinatura;
        }

        $idNoGateway = $assinatura->gateway_subscription_id;
        $cancelouNoProvedor = false;

        if (filled($idNoGateway)) {
            $this->gateway->cancelarAssinatura((string) $idNoGateway);
            $cancelouNoProvedor = true;
        } else {
            Log::warning('Assinatura cancelada apenas no banco: não havia identificador no provedor de pagamento.', [
                'operacao' => 'cancelar',
                'company_id' => $empresa->id,
                'subscription_id' => $assinatura->id,
            ]);
        }

        $motivoLimpo = trim($motivo);

        return $this->registrarCancelamento(
            operacao: 'cancelar',
            assinatura: $assinatura,
            empresa: $empresa,
            motivo: $motivoLimpo === '' ? null : $motivoLimpo,
            provedorJaMudou: $cancelouNoProvedor,
        );
    }

    /**
     * Registra no domínio o cancelamento que o provedor já executou por conta
     * própria, avisado pelo webhook `assinatura_cancelada` (Task 7.6).
     *
     * A diferença para `cancelar()` é quem decidiu, e ela muda duas coisas:
     *
     * 1. **Não chama `GatewayAssinatura::cancelarAssinatura()`.** O evento
     *    chegando significa que a assinatura já foi encerrada lá. Ligar de volta
     *    seria uma ida à rede para pedir o que já aconteceu, e o cancelamento no
     *    PagBank é definitivo: a segunda chamada só renderia uma recusa 4xx que
     *    viraria erro de processamento de um evento que estava correto.
     * 2. **O motivo é fixo** (`self::MOTIVO_CANCELADO_PELO_PROVEDOR`), e não o
     *    texto livre que `cancelar()` recebe de quem pediu o cancelamento pelo
     *    sistema. Aqui não há pedido de ninguém daqui: guardar quem encerrou é o
     *    que permite, meses depois, saber se o contrato acabou por escolha do
     *    tenant ou por decisão do provedor (expiração, fraude, falta de meio de
     *    pagamento).
     *
     * O que segue igual a `cancelar()`: assinatura já cancelada é devolvida como
     * está, sem nova gravação (é essa saída que faz o mesmo evento chegando duas
     * vezes produzir um único efeito), o acesso continua até o fim do período já
     * pago, e `companies.situacao`, `companies.plan_id` e `proxima_cobranca_em`
     * não são tocados.
     *
     * A verificação de tenant interno continua aqui, mesmo sendo caminho
     * improvável: ele não assina, então não tem assinatura no provedor e não
     * recebe evento. Se uma linha existir por importação ou correção manual em
     * banco, um webhook não a altera.
     *
     * @throws TenantInternoException Quando a empresa da assinatura é o tenant interno.
     */
    public function refletirCancelamentoDoGateway(Subscription $assinatura): Subscription
    {
        $empresa = $this->empresaDa($assinatura);

        if ($this->ehTenantInterno($empresa)) {
            throw TenantInternoException::naoTemAssinatura($empresa->name);
        }

        if ($assinatura->situacao === RespostaDeAssinatura::SITUACAO_CANCELADA) {
            return $assinatura;
        }

        return $this->registrarCancelamento(
            operacao: 'refletirCancelamentoDoGateway',
            assinatura: $assinatura,
            empresa: $empresa,
            motivo: self::MOTIVO_CANCELADO_PELO_PROVEDOR,
            // O provedor já encerrou a assinatura antes de mandar o aviso.
            // Falha na gravação daqui deixa os dois lados divergentes (cancelada
            // lá, em vigor aqui), que é exatamente a pendência de conciliação que
            // `gravar()` registra.
            provedorJaMudou: true,
        );
    }

    /**
     * Grava o cancelamento da assinatura, sem decidir nada sobre ele.
     *
     * Parte comum de `cancelar()` (pedido feito pelo sistema) e de
     * `refletirCancelamentoDoGateway()` (aviso vindo do provedor). O que muda
     * entre os dois é só o que já foi decidido antes de chegar aqui: se o
     * provedor foi chamado e qual motivo registrar. Duas cópias desta gravação
     * acabariam divergindo em qual campo é limpo no encerramento de um contrato.
     *
     * @param  string  $operacao  Nome do método de origem, para achar a pendência no log.
     * @param  string|null  $motivo  Texto já limpo, ou nulo quando não há motivo a registrar.
     * @param  bool  $provedorJaMudou  Ver `gravar()`.
     */
    private function registrarCancelamento(
        string $operacao,
        Subscription $assinatura,
        Company $empresa,
        ?string $motivo,
        bool $provedorJaMudou,
    ): Subscription {
        return $this->gravar(
            operacao: $operacao,
            empresa: $empresa,
            idNoGateway: $assinatura->gateway_subscription_id,
            provedorJaMudou: $provedorJaMudou,
            gravacao: function () use ($assinatura, $motivo): Subscription {
                $assinatura->update([
                    'situacao' => RespostaDeAssinatura::SITUACAO_CANCELADA,
                    'cancelada_em' => now(),
                    'motivo_cancelamento' => $motivo,
                ]);

                return $assinatura->refresh();
            },
        );
    }

    /**
     * Situação de assinatura da empresa, para a tela do tenant (Task 7.8) e
     * para o painel da plataforma.
     *
     * Tenant interno devolve `imune = true` e nenhum dado de cobrança: ele não
     * tem assinatura, não tem fatura e não entra em número de receita. O plano
     * dele continua aparecendo, porque é o plano interno que libera os módulos.
     * O texto que a tela mostra nesse caso é decisão da tela, não deste array.
     *
     * Datas de dia (`inicio_em`, `proxima_cobranca_em`) saem como `Y-m-d`, sem
     * hora e sem conversão de fuso, que é o formato que os utilitários de data
     * do frontend esperam. `cancelada_em` é instante e sai em ISO-8601 com o
     * deslocamento, para o frontend converter para o fuso do negócio na
     * exibição.
     *
     * @return array{
     *     imune: bool,
     *     tem_assinatura: bool,
     *     plano: array{id: int, nome: string, valor: string|null, periodicidade: string|null}|null,
     *     situacao: string|null,
     *     forma_pagamento: string|null,
     *     inicio_em: string|null,
     *     proxima_cobranca_em: string|null,
     *     cancelada_em: string|null,
     *     motivo_cancelamento: string|null
     * }
     */
    public function situacaoDe(Company $empresa): array
    {
        $imune = $this->ehTenantInterno($empresa);

        // A assinatura do tenant interno nem é consultada: se uma linha existir
        // por importação ou correção manual em banco, ela continua não valendo
        // como cobrança, e mostrá-la na tela seria dizer que ele é cobrado.
        $assinatura = $imune ? null : $this->assinaturaDe($empresa);

        if ($assinatura === null) {
            return [
                'imune' => $imune,
                'tem_assinatura' => false,
                'plano' => $this->planoEmArray($empresa->loadMissing('plan')->plan),
                'situacao' => null,
                'forma_pagamento' => null,
                'inicio_em' => null,
                'proxima_cobranca_em' => null,
                'cancelada_em' => null,
                'motivo_cancelamento' => null,
            ];
        }

        return [
            'imune' => false,
            'tem_assinatura' => true,
            'plano' => $this->planoEmArray($assinatura->loadMissing('plan')->plan),
            'situacao' => $assinatura->situacao,
            'forma_pagamento' => $assinatura->forma_pagamento,
            'inicio_em' => BusinessDate::diaDe($assinatura->inicio_em),
            'proxima_cobranca_em' => BusinessDate::diaDe($assinatura->proxima_cobranca_em),
            'cancelada_em' => $assinatura->cancelada_em?->toIso8601String(),
            'motivo_cancelamento' => $assinatura->motivo_cancelamento,
        ];
    }

    /**
     * Assinatura da empresa, ou nulo.
     *
     * O filtro por `company_id` é explícito porque `subscriptions` fica fora do
     * escopo global por empresa (Task 7.1): a plataforma precisa ver a
     * assinatura de todos os tenants na mesma tela, e por isso não há escopo
     * automático protegendo esta consulta.
     */
    public function assinaturaDe(Company $empresa): ?Subscription
    {
        return Subscription::query()
            ->where('company_id', $empresa->getKey())
            ->first();
    }

    /**
     * Quantidade de assinaturas por situação, para o painel de receita da
     * plataforma (Task 7.8).
     *
     * O tenant interno não entra em contador nenhum: ele não assina (ver o
     * cabeçalho da classe), então o filtro por `company.is_internal = false`
     * é defesa em profundidade, para o caso de uma linha existir por
     * importação ou correção manual em banco.
     *
     * @return array{ativa: int, em_atraso: int, suspensa: int, cancelada: int}
     */
    public function contadoresPorSituacao(): array
    {
        $porSituacao = Subscription::query()
            ->whereHas('company', fn ($consulta) => $consulta->where('is_internal', false))
            ->selectRaw('situacao, count(*) as total')
            ->groupBy('situacao')
            ->pluck('total', 'situacao');

        return [
            'ativa' => (int) ($porSituacao[RespostaDeAssinatura::SITUACAO_ATIVA] ?? 0),
            'em_atraso' => (int) ($porSituacao[RespostaDeAssinatura::SITUACAO_EM_ATRASO] ?? 0),
            'suspensa' => (int) ($porSituacao[RespostaDeAssinatura::SITUACAO_SUSPENSA] ?? 0),
            'cancelada' => (int) ($porSituacao[RespostaDeAssinatura::SITUACAO_CANCELADA] ?? 0),
        ];
    }

    /**
     * Receita recorrente mensal: soma do valor do plano de cada assinatura
     * `ativa`, excluindo o tenant interno.
     *
     * Só `ativa` entra na soma, de propósito. `em_atraso` e `suspensa` ainda
     * existem no provedor (ver `SITUACOES_EM_VIGOR`), mas o período corrente
     * delas não está pago, e somar cobrança não confirmada infla o número que
     * o super admin usa para decidir onde prestar atenção.
     */
    public function receitaRecorrenteMensal(): float
    {
        return (float) Subscription::query()
            ->whereHas('company', fn ($consulta) => $consulta->where('is_internal', false))
            ->where('situacao', RespostaDeAssinatura::SITUACAO_ATIVA)
            ->join('plans', 'plans.id', '=', 'subscriptions.plan_id')
            ->sum('plans.valor');
    }

    /**
     * Cancelamentos dos últimos `$meses` meses (padrão 6), com série mensal
     * para o gráfico do painel de receita (Task 7.8).
     *
     * Mesmo formato de `TenantService::entradasPorMes()`: rótulo traduzido e
     * contagem por mês, do mais antigo para o mais recente, com o corte do mês
     * calculado no fuso do negócio e convertido para UTC só na comparação
     * (`cancelada_em` é `datetime`, gravado em UTC). Comparar direto contra o
     * dia local moveria um cancelamento de fim de mês para o mês errado.
     *
     * @return array{labels: array<int, string>, valores: array<int, int>}
     */
    public function cancelamentosPorMes(int $meses = 6): array
    {
        $inicioDoMesAtual = BusinessDate::agora()->startOfMonth();

        $labels = [];
        $valores = [];

        for ($i = $meses - 1; $i >= 0; $i--) {
            $inicioDoMes = $inicioDoMesAtual->subMonthsNoOverflow($i);
            $fimDoMes = $inicioDoMes->addMonthNoOverflow();

            $labels[] = $inicioDoMes->translatedFormat('M/Y');

            $valores[] = Subscription::query()
                ->whereHas('company', fn ($consulta) => $consulta->where('is_internal', false))
                ->whereNotNull('cancelada_em')
                ->where('cancelada_em', '>=', $inicioDoMes->setTimezone('UTC'))
                ->where('cancelada_em', '<', $fimDoMes->setTimezone('UTC'))
                ->count();
        }

        return ['labels' => $labels, 'valores' => $valores];
    }

    /**
     * Grava no banco o que o provedor já aceitou, em transação, e registra
     * pendência de conciliação quando a gravação falha.
     *
     * Este é o ponto em que os dois lados podem divergir. O provedor não tem
     * rollback: se a assinatura foi criada lá e o banco recusou a linha aqui, a
     * transação volta atrás sozinha e sobra uma assinatura no provedor que
     * ninguém no sistema conhece, cobrando o tenant todo mês. Por isso a
     * exceção é registrada com `critical` e com o identificador do provedor
     * antes de subir: é esse identificador que permite achar e cancelar a
     * assinatura órfã na conciliação manual.
     *
     * A exceção continua subindo. Engolir aqui devolveria ao controller uma
     * assinatura que não existe no banco.
     *
     * @param  string  $operacao  Nome do método de origem, para achar a pendência no log.
     * @param  string|null  $idNoGateway  Identificador da assinatura no provedor, quando existe.
     * @param  bool  $provedorJaMudou  Falso quando a operação não chegou a mexer no provedor: nesse caso a falha do banco não deixa pendência a conciliar, só desfaz o que ia ser gravado.
     * @param  Closure(): Subscription  $gravacao
     */
    private function gravar(
        string $operacao,
        Company $empresa,
        ?string $idNoGateway,
        Closure $gravacao,
        bool $provedorJaMudou = true,
    ): Subscription {
        try {
            return DB::transaction($gravacao);
        } catch (Throwable $erro) {
            if ($provedorJaMudou) {
                Log::critical(
                    'Operação concluída no provedor de pagamento e não gravada no banco. Conciliação manual necessária.',
                    [
                        'operacao' => $operacao,
                        'company_id' => $empresa->getKey(),
                        'gateway_subscription_id' => $idNoGateway,
                        'gateway' => class_basename($this->gateway),
                        'erro' => $erro->getMessage(),
                    ]
                );
            }

            throw $erro;
        }
    }

    /**
     * Recusa forma de pagamento fora do vocabulário.
     *
     * `subscriptions.forma_pagamento` é string sem restrição no banco, então
     * esta é a barreira que existe fora do FormRequest, e vale também para
     * comando artisan e job, que não passam por FormRequest nenhum.
     *
     * @throws AssinaturaInvalidaException
     */
    private function exigirFormaConhecida(string $forma): void
    {
        if (! in_array($forma, self::FORMAS, true)) {
            throw AssinaturaInvalidaException::formaDePagamentoDesconhecida($forma, self::FORMAS);
        }
    }

    /**
     * Devolve o cartão cifrado quando a forma exige um, e nulo quando não.
     *
     * Em pix e boleto o cifrado é descartado mesmo se vier preenchido: não há
     * meio de pagamento a cadastrar, e mandá-lo ao provedor seria trafegar dado
     * de cartão sem motivo.
     *
     * @throws AssinaturaInvalidaException Quando a forma é cartão e o cifrado chega vazio.
     */
    private function exigirCartaoQuandoNecessario(string $forma, ?string $cartaoCifrado): ?string
    {
        if ($forma !== self::FORMA_CARTAO) {
            return null;
        }

        $cifrado = trim((string) $cartaoCifrado);

        if ($cifrado === '') {
            throw AssinaturaInvalidaException::cartaoCifradoAusente();
        }

        return $cifrado;
    }

    /**
     * Identificador da assinatura no provedor, exigido pelas operações que
     * precisam alterá-la lá.
     *
     * @throws AssinaturaInvalidaException
     */
    private function exigirIdNoGateway(Subscription $assinatura, string $operacao): string
    {
        $id = $assinatura->gateway_subscription_id;

        if (blank($id)) {
            throw AssinaturaInvalidaException::semIdentificadorNoGateway($operacao);
        }

        return (string) $id;
    }

    /**
     * Dia da próxima cobrança, a partir do dia de início e da periodicidade do
     * plano.
     *
     * `addMonthNoOverflow()` e `addYearNoOverflow()`, nunca `addMonth()`: quem
     * assina em 31 de janeiro tem a próxima cobrança em 28 de fevereiro, e não
     * em 3 de março. Um plano anual assinado em 29 de fevereiro cai em 28 de
     * fevereiro pelo mesmo motivo.
     *
     * Periodicidade fora da lista é erro, não um mensal implícito: adivinhar
     * ciclo de cobrança é adivinhar dinheiro.
     *
     * Pública porque `InvoiceService::marcarComoPaga()` (Task 7.5) avança o
     * ciclo pela mesma conta ao dar baixa no pagamento. Quanto tempo dura um
     * período é uma regra só: uma segunda implementação dela em outro Service
     * acabaria divergindo em cima de dinheiro, e a divergência apareceria meses
     * depois, numa cobrança fora da data.
     *
     * @throws AssinaturaInvalidaException
     */
    public function proximaCobrancaApos(CarbonImmutable $inicio, Plan $plano): CarbonImmutable
    {
        $periodicidade = (string) $plano->periodicidade;

        return match ($periodicidade) {
            self::PERIODICIDADE_MENSAL => $inicio->addMonthNoOverflow(),
            self::PERIODICIDADE_ANUAL => $inicio->addYearNoOverflow(),
            default => throw AssinaturaInvalidaException::periodicidadeDesconhecida(
                $periodicidade,
                self::PERIODICIDADES
            ),
        };
    }

    /**
     * Empresa dona da assinatura, carregada do banco.
     *
     * `loadMissing()` garante que a checagem de tenant interno tenha uma
     * empresa de verdade para consultar, mesmo quando quem chamou montou a
     * assinatura sem relação carregada.
     */
    private function empresaDa(Subscription $assinatura): Company
    {
        $empresa = $assinatura->loadMissing('company')->company;

        if (! $empresa instanceof Company) {
            throw new AssinaturaInvalidaException(
                'A assinatura não aponta para nenhuma empresa. Não há a quem cobrar nem a quem recusar.'
            );
        }

        return $empresa;
    }

    /**
     * Plano no formato que a tela consome, ou nulo quando a empresa ainda não
     * tem plano definido.
     *
     * @return array{id: int, nome: string, valor: string|null, periodicidade: string|null}|null
     */
    private function planoEmArray(?Plan $plano): ?array
    {
        if ($plano === null) {
            return null;
        }

        return [
            'id' => (int) $plano->id,
            'nome' => (string) $plano->nome,
            'valor' => $plano->valor === null ? null : (string) $plano->valor,
            'periodicidade' => $plano->periodicidade,
        ];
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
     *
     * Cópia deliberada de `TenantService::ehTenantInterno()`. Ver o cabeçalho
     * da classe: cada Service que pode cobrar ou bloquear carrega a sua.
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
