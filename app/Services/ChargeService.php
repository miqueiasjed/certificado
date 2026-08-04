<?php

namespace App\Services;

use App\Exceptions\CobrancaAtivaExistenteException;
use App\Exceptions\CobrancaJaPagaException;
use App\Exceptions\DadosDeCobrancaInvalidosException;
use App\Exceptions\GatewayIndisponivelException;
use App\Exceptions\GatewayRecusouException;
use App\Models\Address;
use App\Models\Charge;
use App\Models\Client;
use App\Models\Company;
use App\Models\PaymentGatewayConfig;
use App\Models\ReceivableInstallment;
use App\Services\Payments\GatewayDeCobranca;
use App\Services\Payments\ResolvedorDeGateway;
use App\Services\Payments\ValidadorDeDadosDeCobranca;
use App\Support\Dinheiro;
use App\Support\EventosDeNotificacao;
use App\Support\Gateway\RespostaDeCobranca;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Transforma uma parcela a receber em cobrança emitida no gateway do tenant,
 * e administra o ciclo de vida dela: reemissão de boleto vencido e
 * cancelamento (Plano 19, Task 19.3).
 *
 * Regra de negócio crítica deste plano: **uma cobrança ativa por parcela**.
 * Dois boletos abertos da mesma parcela geram pagamento em duplicidade, e
 * estornar o pagamento em dobro do cliente final é problema da empresa, não
 * da plataforma. `emitir()` recusa antes de chamar o gateway
 * (`CobrancaAtivaExistenteException`) sempre que a parcela já tem uma
 * cobrança em `pendente`, `emitida` ou `vencida`; a checagem é repetida
 * dentro de uma transação com a parcela travada (`lockForUpdate()`), para
 * duas emissões simultâneas da mesma parcela não passarem as duas pela
 * checagem antes de qualquer uma gravar.
 *
 * ## Nunca falha em silêncio
 *
 * Toda tentativa de emissão grava uma `Charge`: primeiro como `pendente`,
 * antes de qualquer chamada de rede, depois atualizada para `emitida` (com
 * link, linha digitável ou QR code) ou para `erro` (com a mensagem do
 * gateway, já traduzida para português pelas exceções da Task 19.2). Uma
 * falha de rede no meio do caminho, sem chance de rodar o `catch`, deixa o
 * registro em `pendente`: nunca some, sempre reprocessável.
 *
 * ## Chamada de rede fora de transação de banco
 *
 * A checagem de cobrança ativa e a criação do registro `pendente` acontecem
 * dentro de uma transação curta, só de banco. A chamada ao gateway
 * (`GatewayDeCobranca`) roda **fora** dela: segurar uma transação aberta
 * durante uma chamada de rede lenta prende a linha da parcela por todo esse
 * tempo, e trava qualquer outra operação que dependa dela.
 *
 * ## Escopo por empresa
 *
 * Como `App\Services\SettlementService`, este Service não resolve o tenant
 * sozinho: opera sobre o `ReceivableInstallment`/`Charge` que recebe, e as
 * consultas subordinadas (`$parcela->charges()`, `$cobranca->company`) valem
 * o escopo global de `BelongsToCompany` do tenant corrente no momento da
 * chamada. Uma rotina que percorra várias empresas (Task 19.5, cobrança
 * recorrente) precisa envolver cada chamada em
 * `TenantAtual::comTenant($empresa->id, fn () => ...)`, o mesmo critério já
 * documentado em `ResolvedorDeGateway`.
 */
class ChargeService
{
    private const TIPO_PIX = 'pix';

    private const SITUACAO_PARCELA_PAGA = 'paga';

    private const SITUACAO_PARCELA_CANCELADA = 'cancelada';

    private const SITUACAO_PENDENTE = 'pendente';

    private const SITUACAO_EMITIDA = 'emitida';

    private const SITUACAO_PAGA = 'paga';

    private const SITUACAO_VENCIDA = 'vencida';

    private const SITUACAO_CANCELADA = 'cancelada';

    private const SITUACAO_ERRO = 'erro';

    /**
     * Situações de `Charge` que contam como "cobrança ativa" da parcela.
     * `vencida` entra aqui de propósito: o boleto vencido continua sendo a
     * cobrança da parcela até alguém decidir reemiti-lo (que cancela esta
     * antes de criar a próxima) ou cancelá-lo.
     *
     * @var array<int, string>
     */
    private const SITUACOES_ATIVAS = [self::SITUACAO_PENDENTE, self::SITUACAO_EMITIDA, self::SITUACAO_VENCIDA];

    /**
     * Situações em que a cobrança tem, de fato, um identificador válido no
     * gateway, e por isso cancelar precisa chamar o provedor. Fora destas
     * (`pendente`, `erro`), `gateway_charge_id` é só a referência local
     * gerada antes da chamada, e cancelar é operação puramente local.
     *
     * @var array<int, string>
     */
    private const SITUACOES_COM_COBRANCA_NO_GATEWAY = [self::SITUACAO_EMITIDA, self::SITUACAO_VENCIDA];

    public function __construct(
        private readonly ValidadorDeDadosDeCobranca $validador,
        private readonly ResolvedorDeGateway $resolvedorDeGateway,
        private readonly NotificationService $notificationService,
    ) {}

    /**
     * Emite um boleto ou um Pix para a parcela informada.
     *
     * Ordem das conferências, da mais barata para a que depende de rede:
     * tipo válido, parcela em situação cobrável, sem cobrança ativa, dados do
     * cliente completos, gateway configurado. Só depois disso a rede é
     * usada.
     *
     * @throws InvalidArgumentException Tipo diferente de "boleto" ou "pix".
     * @throws ValidationException Parcela paga ou cancelada.
     * @throws CobrancaAtivaExistenteException Parcela já tem cobrança em aberto.
     * @throws DadosDeCobrancaInvalidosException Cadastro do cliente incompleto.
     * @throws \App\Exceptions\GatewayNaoConfiguradoException Empresa sem gateway ativo.
     * @throws GatewayRecusouException Gateway recusou a emissão (a `Charge` fica com situação `erro`).
     * @throws GatewayIndisponivelException Gateway não respondeu (a `Charge` fica com situação `erro`).
     */
    public function emitir(ReceivableInstallment $parcela, string $tipo): Charge
    {
        $this->validarTipo($tipo);
        $this->garantirParcelaCobravel($parcela);
        $this->garantirSemCobrancaAtiva($parcela);

        $cliente = $this->clienteDaParcela($parcela);
        $faltando = $this->validador->validar($cliente);

        if ($faltando !== []) {
            throw DadosDeCobrancaInvalidosException::paraCliente($cliente, $faltando);
        }

        // Já confirmado pela validação acima: existe endereço com CEP.
        $endereco = $this->validador->enderecoPrincipal($cliente);

        $empresa = $this->empresaDaParcela($parcela);
        $configuracao = $this->resolvedorDeGateway->configuracaoAtiva($empresa);
        $gateway = $this->resolvedorDeGateway->para($empresa);

        $valorEmCentavos = $this->saldoDevedorEmCentavos($parcela);
        $referencia = $this->novaReferencia($parcela);

        $cobranca = $this->criarCobrancaPendente($parcela, $tipo, $configuracao, $referencia, $valorEmCentavos);

        try {
            $resposta = $this->chamarGateway(
                $gateway,
                $configuracao,
                $tipo,
                $referencia,
                $parcela,
                $cliente,
                $endereco,
                $valorEmCentavos
            );
        } catch (GatewayRecusouException|GatewayIndisponivelException $falha) {
            $cobranca->update([
                'situacao' => self::SITUACAO_ERRO,
                'erro_mensagem' => $falha->getMessage(),
            ]);

            throw $falha;
        }

        $cobranca->update([
            'gateway_charge_id' => $resposta->idNoGateway,
            'situacao' => $this->situacaoLocal($resposta->situacao),
            'link_pagamento' => $resposta->linkPagamento,
            'linha_digitavel' => $resposta->linhaDigitavel,
            'qr_code_pix' => $resposta->qrCodePix,
            'pago_em' => $resposta->pagoEm?->toDateString(),
            'valor_pago' => $resposta->situacao === RespostaDeCobranca::SITUACAO_PAGA
                ? Dinheiro::paraDecimal($valorEmCentavos)
                : null,
        ]);

        $cobranca = $cobranca->fresh();

        $this->enfileirarAvisoDeCobrancaEmitida($cobranca, $cliente, $tipo);

        return $cobranca;
    }

    /**
     * Cancela a cobrança anterior (no gateway e localmente) e emite outra no
     * lugar dela, para o caso comum de boleto vencido. As duas ficam
     * vinculadas por `charge_anterior_id`, para o histórico continuar
     * navegável a partir da cobrança nova.
     *
     * Também serve para reprocessar uma cobrança que ficou `erro`: como ela
     * nunca chegou a existir no gateway, o cancelamento é só local (ver
     * `SITUACOES_COM_COBRANCA_NO_GATEWAY`), e a emissão nova tenta de novo.
     *
     * @throws CobrancaJaPagaException Cobrança já paga.
     */
    public function reemitir(Charge $cobranca): Charge
    {
        $this->garantirNaoPaga($cobranca, 'reemitida');

        $parcela = $cobranca->receivableInstallment;

        if (! $parcela instanceof ReceivableInstallment) {
            throw new RuntimeException(
                "A cobrança #{$cobranca->getKey()} não está vinculada a uma parcela a receber."
            );
        }

        if ($cobranca->situacao !== self::SITUACAO_CANCELADA) {
            $this->cancelar($cobranca, 'Cobrança reemitida: uma nova cobrança foi gerada para esta parcela.');
        }

        $nova = $this->emitir($parcela, $cobranca->tipo);
        $nova->update(['charge_anterior_id' => $cobranca->getKey()]);

        return $nova->fresh();
    }

    /**
     * Cancela a cobrança no gateway (quando ela chegou a existir lá) e
     * localmente. Idempotente: cobrança já cancelada é devolvida como está.
     *
     * @throws CobrancaJaPagaException Cobrança já paga.
     * @throws ValidationException Motivo em branco.
     */
    public function cancelar(Charge $cobranca, string $motivo): Charge
    {
        $this->garantirNaoPaga($cobranca, 'cancelada');

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw ValidationException::withMessages([
                'motivo' => 'Informe o motivo do cancelamento.',
            ]);
        }

        if ($cobranca->situacao === self::SITUACAO_CANCELADA) {
            return $cobranca;
        }

        if (in_array($cobranca->situacao, self::SITUACOES_COM_COBRANCA_NO_GATEWAY, true)) {
            $empresa = $this->empresaDaCobranca($cobranca);
            $configuracao = $this->resolvedorDeGateway->configuracaoAtiva($empresa);
            $gateway = $this->resolvedorDeGateway->para($empresa);

            $gateway->cancelar($configuracao, $cobranca->gateway_charge_id);
        }

        $cobranca->update([
            'situacao' => self::SITUACAO_CANCELADA,
            // Sem coluna própria para o motivo do cancelamento: reaproveita
            // o texto livre de erro_mensagem, que já existe para explicar
            // por que esta cobrança não seguiu para pagamento.
            'erro_mensagem' => 'Cancelada: '.$motivo,
        ]);

        return $cobranca->fresh();
    }

    /**
     * Emite a mesma cobrança para várias parcelas, uma a uma. Uma falha
     * isolada nunca interrompe o lote: o resultado de cada parcela
     * (sucesso ou erro, com a mensagem) sai em um item próprio, mesmo padrão
     * de retorno do lote de sincronização do aplicativo do técnico (Plano
     * 12, `AppSyncUploadController::sincronizar()`).
     *
     * @param  iterable<ReceivableInstallment>  $parcelas
     * @return array<int, array{receivable_installment_id: int, situacao: string, charge_id: int|null, mensagem: string|null}>
     */
    public function emitirEmLote(iterable $parcelas, string $tipo): array
    {
        $resultados = [];

        foreach ($parcelas as $parcela) {
            $resultados[] = $this->emitirItemDoLote($parcela, $tipo);
        }

        return $resultados;
    }

    // -----------------------------------------------------------------
    // emitir(): passos internos
    // -----------------------------------------------------------------

    private function validarTipo(string $tipo): void
    {
        if (! in_array($tipo, Charge::TIPOS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Tipo de cobrança inválido: "%s". Tipos aceitos: %s.',
                $tipo,
                implode(', ', Charge::TIPOS)
            ));
        }
    }

    private function garantirParcelaCobravel(ReceivableInstallment $parcela): void
    {
        if ($parcela->situacao === self::SITUACAO_PARCELA_CANCELADA) {
            throw ValidationException::withMessages([
                'parcela' => 'Esta parcela está cancelada e não pode gerar cobrança.',
            ]);
        }

        if ($parcela->situacao === self::SITUACAO_PARCELA_PAGA) {
            throw ValidationException::withMessages([
                'parcela' => 'Esta parcela já está paga e não pode gerar cobrança.',
            ]);
        }
    }

    private function garantirSemCobrancaAtiva(ReceivableInstallment $parcela): void
    {
        $ativa = $parcela->charges()->whereIn('situacao', self::SITUACOES_ATIVAS)->first();

        if ($ativa instanceof Charge) {
            throw CobrancaAtivaExistenteException::paraParcela($parcela, $ativa);
        }
    }

    private function clienteDaParcela(ReceivableInstallment $parcela): Client
    {
        $cliente = $parcela->receivable?->client;

        if (! $cliente instanceof Client) {
            throw new RuntimeException(
                "A parcela #{$parcela->getKey()} não está vinculada a um cliente, e por isso não pode gerar cobrança."
            );
        }

        return $cliente;
    }

    private function empresaDaParcela(ReceivableInstallment $parcela): Company
    {
        $empresa = $parcela->company;

        if (! $empresa instanceof Company) {
            throw new RuntimeException("Não foi possível resolver a empresa da parcela #{$parcela->getKey()}.");
        }

        return $empresa;
    }

    private function empresaDaCobranca(Charge $cobranca): Company
    {
        $empresa = $cobranca->company;

        if (! $empresa instanceof Company) {
            throw new RuntimeException("Não foi possível resolver a empresa da cobrança #{$cobranca->getKey()}.");
        }

        return $empresa;
    }

    /**
     * Quanto ainda falta receber na parcela, em centavos. É este valor, e
     * não `parcela.valor`, que vai para o boleto: uma parcela com baixa
     * parcial só pode cobrar o que ainda falta.
     */
    private function saldoDevedorEmCentavos(ReceivableInstallment $parcela): int
    {
        $saldo = Dinheiro::centavos($parcela->valor) - Dinheiro::centavos($parcela->valor_pago);

        return max($saldo, 0);
    }

    private function novaReferencia(ReceivableInstallment $parcela): string
    {
        return sprintf('parcela-%d-%s', $parcela->getKey(), (string) Str::uuid());
    }

    /**
     * Cria a `Charge` em `pendente`, dentro de uma transação que trava a
     * linha da parcela e repete a checagem de cobrança ativa. É a defesa
     * contra duas requisições simultâneas de emissão para a mesma parcela:
     * sem o travamento, as duas passariam pela checagem de
     * `garantirSemCobrancaAtiva()` antes de qualquer uma gravar, e as duas
     * emitiriam boleto.
     *
     * `gateway_charge_id` nasce com a própria referência local: ainda não
     * existe id do provedor neste ponto, e a coluna não aceita nulo. É
     * sobrescrito com o id real assim que o gateway responde.
     */
    private function criarCobrancaPendente(
        ReceivableInstallment $parcela,
        string $tipo,
        PaymentGatewayConfig $configuracao,
        string $referencia,
        int $valorEmCentavos,
    ): Charge {
        return DB::transaction(function () use ($parcela, $tipo, $configuracao, $referencia, $valorEmCentavos) {
            ReceivableInstallment::query()->whereKey($parcela->getKey())->lockForUpdate()->firstOrFail();

            $this->garantirSemCobrancaAtiva($parcela);

            return Charge::create([
                'receivable_installment_id' => $parcela->getKey(),
                'tipo' => $tipo,
                'gateway' => $configuracao->gateway,
                'gateway_charge_id' => $referencia,
                'valor' => Dinheiro::paraDecimal($valorEmCentavos),
                'vencimento' => $parcela->vencimento,
                'situacao' => self::SITUACAO_PENDENTE,
                'tentativas' => 1,
            ]);
        });
    }

    private function chamarGateway(
        GatewayDeCobranca $gateway,
        PaymentGatewayConfig $configuracao,
        string $tipo,
        string $referencia,
        ReceivableInstallment $parcela,
        Client $cliente,
        Address $endereco,
        int $valorEmCentavos,
    ): RespostaDeCobranca {
        $descricao = $this->descricaoDaCobranca($parcela);
        $valorTexto = Dinheiro::paraDecimal($valorEmCentavos);
        $clienteParaGateway = $this->clienteParaGateway($cliente, $endereco);

        return $tipo === self::TIPO_PIX
            ? $gateway->emitirPix($configuracao, $referencia, $descricao, $valorTexto, $parcela->vencimento, $clienteParaGateway)
            : $gateway->emitirBoleto($configuracao, $referencia, $descricao, $valorTexto, $parcela->vencimento, $clienteParaGateway);
    }

    private function descricaoDaCobranca(ReceivableInstallment $parcela): string
    {
        $descricaoDoTitulo = $parcela->receivable?->descricao;
        $descricaoDoTitulo = filled($descricaoDoTitulo) ? $descricaoDoTitulo : 'Cobrança';

        return sprintf('%s - parcela %d', $descricaoDoTitulo, $parcela->numero);
    }

    /**
     * @return array<string, mixed>
     */
    private function clienteParaGateway(Client $cliente, Address $endereco): array
    {
        return [
            'nome' => $cliente->name,
            'documento' => $cliente->cnpj,
            'email' => $cliente->email_notificacao ?: $cliente->email,
            'endereco' => [
                'rua' => $endereco->street,
                'numero' => $endereco->number,
                // Endereco não tem campo de complemento no cadastro atual.
                'complemento' => '',
                'bairro' => $endereco->district,
                'cidade' => $endereco->city,
                'uf' => $endereco->state,
                'cep' => $endereco->zip,
            ],
        ];
    }

    /**
     * Traduz a situação de `RespostaDeCobranca` (vocabulário do gateway)
     * para a situação de `Charge` (vocabulário do domínio). `aberta` vira
     * `emitida`: é o nome que este domínio usa para "cobrança em aberto no
     * provedor, esperando pagamento".
     */
    private function situacaoLocal(string $situacaoDoGateway): string
    {
        return match ($situacaoDoGateway) {
            RespostaDeCobranca::SITUACAO_PAGA => self::SITUACAO_PAGA,
            RespostaDeCobranca::SITUACAO_VENCIDA => self::SITUACAO_VENCIDA,
            RespostaDeCobranca::SITUACAO_CANCELADA => self::SITUACAO_CANCELADA,
            default => self::SITUACAO_EMITIDA,
        };
    }

    /**
     * Enfileira o aviso ao cliente pela central de notificações do Plano 14.
     * Falha ao enfileirar nunca desfaz a emissão: a cobrança já existe no
     * gateway e no domínio, e o pior resultado possível aqui é o cliente não
     * ser avisado automaticamente, não a cobrança sumir.
     */
    private function enfileirarAvisoDeCobrancaEmitida(Charge $cobranca, Client $cliente, string $tipo): void
    {
        try {
            $this->notificationService->enfileirar(
                EventosDeNotificacao::COBRANCA_EMITIDA,
                $cobranca,
                [
                    'destinatario' => $cliente,
                    'variaveis' => [
                        'tipo_cobranca' => $tipo === self::TIPO_PIX ? 'Pix' : 'Boleto',
                        'valor' => Dinheiro::formatar(Dinheiro::centavos($cobranca->valor)),
                        'data_vencimento' => $cobranca->vencimento,
                        'link_pagamento' => $cobranca->link_pagamento ?? '',
                        'linha_digitavel' => $cobranca->linha_digitavel ?? '',
                        'qr_code_pix' => $cobranca->qr_code_pix ?? '',
                    ],
                ]
            );
        } catch (Throwable $falha) {
            Log::error('Falha ao enfileirar o aviso de cobrança emitida.', [
                'charge_id' => $cobranca->getKey(),
                'erro' => $falha->getMessage(),
            ]);
        }
    }

    // -----------------------------------------------------------------
    // cancelar()/reemitir(): passos internos
    // -----------------------------------------------------------------

    private function garantirNaoPaga(Charge $cobranca, string $acao): void
    {
        if ($cobranca->situacao === self::SITUACAO_PAGA) {
            throw CobrancaJaPagaException::paraCobranca($cobranca, $acao);
        }
    }

    // -----------------------------------------------------------------
    // emitirEmLote(): passos internos
    // -----------------------------------------------------------------

    /**
     * @return array{receivable_installment_id: int, situacao: string, charge_id: int|null, mensagem: string|null}
     */
    private function emitirItemDoLote(ReceivableInstallment $parcela, string $tipo): array
    {
        try {
            $cobranca = $this->emitir($parcela, $tipo);

            return [
                'receivable_installment_id' => $parcela->getKey(),
                'situacao' => 'emitida',
                'charge_id' => $cobranca->getKey(),
                'mensagem' => null,
            ];
        } catch (Throwable $falha) {
            // Captura ampla de propósito: o lote não pode parar por uma
            // parcela com problema, seja ele uma recusa de negócio mapeada
            // (exceção de domínio) ou uma falha inesperada.
            //
            // `ValidationException::getMessage()` nunca é a mensagem do
            // campo (o construtor do Laravel sempre grava "The given data
            // was invalid.", em inglês, independente do que
            // `withMessages()` recebeu) - é o caso de `garantirParcelaCobravel()`
            // acima. Sem este tratamento, a Task 19.7 mostraria esse texto
            // genérico em vez de "Esta parcela está cancelada..." na tela.
            // As demais exceções (domínio ou inesperada) já carregam a
            // mensagem certa em `getMessage()`.
            $mensagem = $falha instanceof ValidationException
                ? (collect($falha->errors())->flatten()->first() ?? $falha->getMessage())
                : $falha->getMessage();

            return [
                'receivable_installment_id' => $parcela->getKey(),
                'situacao' => 'erro',
                'charge_id' => null,
                'mensagem' => $mensagem,
            ];
        }
    }
}
