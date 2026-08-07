<?php

namespace App\Services;

use App\Exceptions\ArmazenamentoFiscalIndisponivelException;
use App\Exceptions\DadoFiscalInvalidoException;
use App\Exceptions\FalhaFiscalException;
use App\Models\Address;
use App\Models\Client;
use App\Models\Company;
use App\Models\FiscalConfig;
use App\Models\NotificationQueue;
use App\Models\Receivable;
use App\Models\ServiceInvoice;
use App\Models\WorkOrder;
use App\Services\Fiscal\ProvedorDeNfse;
use App\Services\Fiscal\ResolvedorDeProvedor;
use App\Services\Fiscal\ValidadorFiscal;
use App\Services\Payments\ValidadorDeDadosDeCobranca;
use App\Support\BusinessDate;
use App\Support\Dinheiro;
use App\Support\EventosDeNotificacao;
use App\Support\Fiscal\MensagemFiscalPublica;
use App\Support\Fiscal\RespostaDeNfse;
use App\Support\TenantAtual;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class ServiceInvoiceService
{
    private const SITUACOES_QUE_BLOQUEIAM_DUPLICIDADE = ['pendente', 'processando', 'emitida'];

    private const MAXIMO_DE_TENTATIVAS = 5;

    private const ESPERA_INICIAL_MINUTOS = 10;

    private const DURACAO_CLAIM_MINUTOS = 5;

    public function __construct(
        private readonly ResolvedorDeProvedor $resolvedor,
        private readonly ValidadorFiscal $validador,
        private readonly ValidadorDeDadosDeCobranca $dadosDeCobranca,
        private readonly NotificationService $notificacoes,
    ) {}

    /**
     * Monta a DPS de uma nota já persistida. O cancelamento usa este ponto para
     * que a substituta siga as mesmas validações e o mesmo formato da emissão.
     *
     * @return array<string, mixed>
     */
    public function dadosDaDps(ServiceInvoice $nota): array
    {
        if ((int) $nota->company_id !== TenantAtual::exigirId()) {
            throw new RuntimeException('A nota fiscal não pertence à empresa atual.');
        }

        if (is_array($nota->payload_dps)) {
            return $nota->payload_dps;
        }

        $nota->loadMissing(['client', 'address', 'fiscalConfig']);
        $configuracao = $this->configuracaoDaNota($nota);
        $endereco = $nota->address;

        $this->validarCliente($nota->client, $configuracao, $endereco);

        if (! $endereco instanceof Address) {
            throw new DadoFiscalInvalidoException('Preencha o campo Endereço do cliente antes de substituir a nota fiscal.');
        }

        $payload = $this->payload($nota, $configuracao, $nota->client, $endereco);

        ServiceInvoice::query()
            ->whereKey($nota->id)
            ->whereNull('payload_dps')
            ->update(['payload_dps' => $payload]);

        return (array) $nota->refresh()->payload_dps;
    }

    public function emitirDaOs(WorkOrder $os, ?ServiceInvoice $pendenciaReprocessada = null): ServiceInvoice
    {
        $this->garantirOrigemDoTenant($os);
        $os->loadMissing(['client.addresses', 'address', 'services', 'service']);

        return $this->emitir(
            cliente: $os->client,
            os: $os,
            titulo: null,
            valor: $os->final_amount ?? $os->total_cost,
            descricao: $this->descricaoDaOs($os),
            competencia: BusinessDate::diaDe($os->scheduled_date) ?? BusinessDate::hoje()->toDateString(),
            endereco: $os->address,
            pendenciaReprocessada: $pendenciaReprocessada,
        );
    }

    public function emitirDoTitulo(
        Receivable $titulo,
        ?Address $enderecoPrioritario = null,
        ?ServiceInvoice $pendenciaReprocessada = null,
    ): ServiceInvoice {
        $this->garantirOrigemDoTenant($titulo);
        $titulo->loadMissing(['client.addresses', 'workOrder.address', 'workOrder.services', 'workOrder.service']);
        $os = $titulo->workOrder;

        return $this->emitir(
            cliente: $titulo->client,
            os: $os,
            titulo: $titulo,
            valor: $titulo->valor_total,
            descricao: $os instanceof WorkOrder ? $this->descricaoDaOs($os) : trim($titulo->descricao),
            competencia: BusinessDate::diaDe($titulo->emitido_em) ?? BusinessDate::hoje()->toDateString(),
            endereco: $os?->address ?? $enderecoPrioritario ?? $this->dadosDeCobranca->enderecoPrincipal($titulo->client),
            pendenciaReprocessada: $pendenciaReprocessada,
        );
    }

    public function reemitir(ServiceInvoice $nota): ServiceInvoice
    {
        if ((int) $nota->company_id !== TenantAtual::exigirId()) {
            throw new RuntimeException('A nota fiscal não pertence à empresa atual.');
        }

        if ($nota->situacao !== 'erro') {
            throw new DadoFiscalInvalidoException('Somente uma pendência fiscal pode ser reprocessada.');
        }

        if ($nota->metadados_substituicao !== null) {
            throw new DadoFiscalInvalidoException(
                'Esta pendência pertence a uma substituição. Abra a nota original e repita a substituição com os dados corrigidos.'
            );
        }

        if ($nota->reprocessada_por_id !== null) {
            return $nota->reprocessadaPor()->firstOrFail();
        }

        $nota->loadMissing(['workOrder', 'receivable', 'address']);

        if ($nota->workOrder instanceof WorkOrder) {
            return $this->emitirDaOs($nota->workOrder, $nota);
        }

        if ($nota->receivable instanceof Receivable) {
            return $this->emitirDoTitulo($nota->receivable, $nota->address, $nota);
        }

        throw new DadoFiscalInvalidoException(
            'A pendência não possui mais a ordem de serviço ou o título que originou a nota.'
        );
    }

    public function emitirAutomaticamenteDaOs(WorkOrder $os): ?ServiceInvoice
    {
        $configuracao = $this->configuracaoDoGatilho('conclusao_os');

        return $configuracao === null ? null : $this->emitirDaOs($os);
    }

    public function emitirAutomaticamenteDoTitulo(Receivable $titulo): ?ServiceInvoice
    {
        $configuracao = $this->configuracaoDoGatilho('quitacao_titulo');

        return $configuracao === null ? null : $this->emitirDoTitulo($titulo);
    }

    public function processarNotas(): int
    {
        $processadas = 0;

        ServiceInvoice::query()
            ->where(function (Builder $consulta): void {
                $consulta->whereIn('situacao', ['pendente', 'processando'])
                    ->orWhere(function (Builder $erros): void {
                        $erros->where('situacao', 'erro')
                            ->whereNull('metadados_substituicao')
                            ->whereNull('reprocessada_por_id')
                            ->where('erro_temporario', true)
                            ->where('tentativas', '<', self::MAXIMO_DE_TENTATIVAS)
                            ->where(function (Builder $espera): void {
                                $espera->whereNull('proxima_tentativa_em')
                                    ->orWhere('proxima_tentativa_em', '<=', now());
                            });
                    });
            })
            ->where(function (Builder $claims): void {
                $claims->whereNull('processamento_bloqueado_ate')
                    ->orWhere('processamento_bloqueado_ate', '<=', now());
            })
            ->orderBy('id')
            ->chunkById(100, function ($notas) use (&$processadas): void {
                foreach ($notas as $nota) {
                    if ($this->processarComClaim($nota)) {
                        $processadas++;
                    }
                }
            });

        return $processadas;
    }

    private function processarComClaim(ServiceInvoice $nota): bool
    {
        $claim = $this->reivindicar($nota);

        if ($claim === null) {
            return false;
        }

        [$notaReivindicada, $token] = $claim;

        $operacao = match ($notaReivindicada->situacao) {
            'pendente' => 'emitir',
            'processando' => 'consultar',
            default => 'reprocessar',
        };

        try {
            if ($notaReivindicada->situacao === 'pendente') {
                $configuracao = $this->configuracaoDaNota($notaReivindicada);
                $this->enviar($notaReivindicada, $configuracao);
            } elseif ($notaReivindicada->situacao === 'processando') {
                $this->consultar($notaReivindicada);
            } else {
                $this->reprocessar($notaReivindicada);
            }
        } catch (Throwable $falha) {
            $this->registrarFalha($notaReivindicada, $falha, $operacao);
        } finally {
            $this->liberarClaim($notaReivindicada, $token);
        }

        return true;
    }

    /**
     * @return array{ServiceInvoice, string}|null
     */
    private function reivindicar(ServiceInvoice $nota): ?array
    {
        return DB::transaction(function () use ($nota): ?array {
            $atual = ServiceInvoice::query()->whereKey($nota->id)->lockForUpdate()->first();

            if (! $atual instanceof ServiceInvoice
                || ! $this->estaProntaParaProcessar($atual)
                || ($atual->processamento_bloqueado_ate !== null
                    && $atual->processamento_bloqueado_ate->isFuture())) {
                return null;
            }

            $token = (string) Str::uuid();
            $atual->update([
                'processamento_token' => $token,
                'processamento_bloqueado_ate' => now()->addMinutes(self::DURACAO_CLAIM_MINUTOS),
            ]);

            return [$atual, $token];
        });
    }

    private function liberarClaim(ServiceInvoice $nota, string $token): void
    {
        ServiceInvoice::query()
            ->whereKey($nota->id)
            ->where('processamento_token', $token)
            ->update([
                'processamento_token' => null,
                'processamento_bloqueado_ate' => null,
            ]);
    }

    private function estaProntaParaProcessar(ServiceInvoice $nota): bool
    {
        if (in_array($nota->situacao, ['pendente', 'processando'], true)) {
            return true;
        }

        return $nota->situacao === 'erro'
            && $nota->metadados_substituicao === null
            && $nota->reprocessada_por_id === null
            && $nota->erro_temporario
            && $nota->tentativas < self::MAXIMO_DE_TENTATIVAS
            && ($nota->proxima_tentativa_em === null || $nota->proxima_tentativa_em->lessThanOrEqualTo(now()));
    }

    private function emitir(
        Client $cliente,
        ?WorkOrder $os,
        ?Receivable $titulo,
        mixed $valor,
        string $descricao,
        string $competencia,
        ?Address $endereco,
        ?ServiceInvoice $pendenciaReprocessada = null,
    ): ServiceInvoice {
        $configuracao = $this->resolvedor->configuracaoAtiva();
        $this->garantirMesmoTenant($cliente, $configuracao, $os, $titulo, $endereco);

        $existente = DB::transaction(function () use ($os, $titulo, $pendenciaReprocessada): ?ServiceInvoice {
            $pendenciaAtual = $this->travarPendenciaDeReprocessamento($pendenciaReprocessada);

            if ($pendenciaAtual instanceof ServiceInvoice && $pendenciaAtual->reprocessada_por_id !== null) {
                return $pendenciaAtual->reprocessadaPor()->firstOrFail();
            }

            $this->travarOrigem($os, $titulo);

            $existente = $this->notaAtivaDaMesmaPrestacao($os, $titulo);

            if ($existente instanceof ServiceInvoice && $pendenciaAtual instanceof ServiceInvoice) {
                $this->vincularReprocessamento($pendenciaAtual, $existente);
            }

            return $existente;
        });

        if ($existente instanceof ServiceInvoice) {
            return $existente;
        }

        $valorEmCentavos = Dinheiro::centavos($valor);

        if ($valorEmCentavos <= 0) {
            throw new DadoFiscalInvalidoException('O Valor do serviço precisa ser maior que zero para emitir a nota fiscal.');
        }

        $issEmCentavos = $this->calcularIss($valorEmCentavos, $configuracao->aliquota_iss);

        try {
            $this->validarCliente($cliente, $configuracao, $endereco);

            if (! $endereco instanceof Address) {
                throw new DadoFiscalInvalidoException('Preencha o campo Endereço do cliente antes de emitir a nota fiscal.');
            }
        } catch (DadoFiscalInvalidoException $falha) {
            $pendencia = $this->registrarPendenciaDeValidacao(
                $cliente,
                $os,
                $titulo,
                $configuracao,
                $endereco,
                $valorEmCentavos,
                $issEmCentavos,
                $configuracao->iss_retido,
                $descricao,
                $competencia,
                $falha,
                $pendenciaReprocessada,
            );

            if ($pendenciaReprocessada instanceof ServiceInvoice) {
                return $pendencia;
            }

            throw $falha;
        }

        [$nota, $criada] = DB::transaction(function () use ($cliente, $configuracao, $os, $titulo, $endereco, $valorEmCentavos, $issEmCentavos, $descricao, $competencia, $pendenciaReprocessada): array {
            $pendenciaAtual = $this->travarPendenciaDeReprocessamento($pendenciaReprocessada);

            if ($pendenciaAtual instanceof ServiceInvoice && $pendenciaAtual->reprocessada_por_id !== null) {
                return [$pendenciaAtual->reprocessadaPor()->firstOrFail(), false];
            }

            $this->travarOrigem($os, $titulo);
            $existente = $this->notaAtivaDaMesmaPrestacao($os, $titulo);

            if ($existente instanceof ServiceInvoice) {
                if ($pendenciaAtual instanceof ServiceInvoice) {
                    $this->vincularReprocessamento($pendenciaAtual, $existente);
                }

                return [$existente, false];
            }

            $nova = ServiceInvoice::create([
                'fiscal_config_id' => $configuracao->id,
                'client_id' => $cliente->id,
                'address_id' => $endereco->id,
                'work_order_id' => $os?->id,
                'receivable_id' => $titulo?->id,
                'situacao' => 'pendente',
                'valor_servico' => Dinheiro::paraDecimal($valorEmCentavos),
                'valor_iss' => Dinheiro::paraDecimal($issEmCentavos),
                'valor_liquido' => Dinheiro::paraDecimal(max(
                    0,
                    $valorEmCentavos - ($configuracao->iss_retido ? $issEmCentavos : 0),
                )),
                'descricao_servico' => $descricao,
                'competencia' => $competencia,
            ]);

            if ($pendenciaAtual instanceof ServiceInvoice) {
                $this->vincularReprocessamento($pendenciaAtual, $nova);
            }

            return [$nova, true];
        });

        if (! $criada) {
            return $nota;
        }

        try {
            $this->referencia($nota);
            $this->dadosDaDps($nota);
        } catch (Throwable $falha) {
            $this->registrarFalha($nota, $falha, 'preparar_emissao');

            throw $falha;
        }

        $this->processarComClaim($nota);

        return $nota->refresh();
    }

    private function enviar(ServiceInvoice $nota, FiscalConfig $configuracao): void
    {
        $tentativa = $nota->tentativas + 1;
        $nota->update([
            'tentativas' => $tentativa,
            'ultima_tentativa_em' => now(),
            'proxima_tentativa_em' => null,
            'erro_mensagem' => null,
            'erro_temporario' => null,
        ]);

        try {
            $provedor = $this->resolvedor->paraConfiguracao($configuracao);
            $id = $provedor->emitir(
                $configuracao,
                $this->dadosDaDps($nota),
                $this->referencia($nota),
            );
            $nota->update(['provedor_id' => $id, 'situacao' => 'processando']);
        } catch (Throwable $falha) {
            $this->registrarFalha($nota, $falha, 'emitir');
        }

    }

    private function consultar(ServiceInvoice $nota): void
    {
        try {
            $configuracao = $this->configuracaoDaNota($nota);

            if (blank($nota->provedor_id)) {
                throw new RuntimeException('A nota está em processamento sem o identificador do provedor. Faça uma nova emissão após corrigir a pendência.');
            }

            $provedor = $this->resolvedor->paraConfiguracao($configuracao);
            $resposta = $provedor->consultar($configuracao, $nota->provedor_id);

            if ($resposta->situacao === RespostaDeNfse::SITUACAO_AUTORIZADA) {
                $this->autorizar($nota, $provedor, $configuracao, $resposta);
            } elseif ($resposta->situacao === RespostaDeNfse::SITUACAO_ERRO) {
                $this->registrarErroDaResposta($nota, $resposta);
            }
        } catch (Throwable $falha) {
            $this->registrarFalha($nota, $falha, 'consultar');
        }
    }

    private function reprocessar(ServiceInvoice $nota): void
    {
        if ($nota->tentativas >= self::MAXIMO_DE_TENTATIVAS) {
            $nota->update(['proxima_tentativa_em' => null]);

            return;
        }

        try {
            $configuracao = $this->configuracaoDaNota($nota);

            $nota->update([
                'tentativas' => $nota->tentativas + 1,
                'ultima_tentativa_em' => now(),
                'situacao' => 'processando',
                'proxima_tentativa_em' => null,
            ]);

            if (filled($nota->provedor_id)) {
                $this->consultar($nota->refresh());

                return;
            }

            $provedor = $this->resolvedor->paraConfiguracao($configuracao);
            $id = $provedor->emitir(
                $configuracao,
                $this->dadosDaDps($nota),
                $this->referencia($nota),
            );
            $nota->update([
                'provedor_id' => $id,
                'situacao' => 'processando',
                'erro_mensagem' => null,
                'erro_temporario' => null,
            ]);
        } catch (Throwable $falha) {
            $this->registrarFalha($nota, $falha, 'reprocessar');
        }
    }

    private function autorizar(
        ServiceInvoice $nota,
        ProvedorDeNfse $provedor,
        FiscalConfig $configuracao,
        RespostaDeNfse $resposta,
    ): void {
        $base = "fiscal/empresa-{$nota->company_id}/nota-{$nota->id}";
        $pdf = $provedor->baixarPdf($configuracao, $nota->provedor_id);
        $xml = $provedor->baixarXml($configuracao, $nota->provedor_id);
        $pdfPath = "{$base}/nfse.pdf";
        $xmlPath = "{$base}/nfse.xml";

        if (! Storage::disk('local')->put($pdfPath, $pdf)
            || ! Storage::disk('local')->put($xmlPath, $xml)) {
            throw new ArmazenamentoFiscalIndisponivelException;
        }

        [$autorizada, $anterior, $podeTerRecebido] = DB::transaction(function () use ($nota, $resposta, $pdfPath, $xmlPath): array {
            $autorizada = ServiceInvoice::query()->whereKey($nota->id)->lockForUpdate()->firstOrFail();
            $anterior = ServiceInvoice::query()
                ->where('substituida_por_id', $autorizada->id)
                ->lockForUpdate()
                ->first();
            $podeTerRecebido = $anterior instanceof ServiceInvoice
                && $this->protegerAvisosDuranteSubstituicao($anterior);

            $autorizada->update([
                'situacao' => 'emitida',
                'numero' => $resposta->numero,
                'codigo_verificacao' => $resposta->codigoVerificacao,
                'emitida_em' => $resposta->emitidaEm ?? now(),
                'pdf_path' => $pdfPath,
                'xml_path' => $xmlPath,
                'erro_mensagem' => null,
                'erro_temporario' => null,
                'proxima_tentativa_em' => null,
            ]);

            if ($anterior instanceof ServiceInvoice && $anterior->situacao === 'emitida') {
                $anterior->update(['situacao' => 'substituida']);
            }

            return [$autorizada, $anterior, $podeTerRecebido];
        });

        $this->enfileirarAvisoDaNotaAutorizada($autorizada->refresh(), $podeTerRecebido);
    }

    private function enfileirarAvisoDaNotaAutorizada(ServiceInvoice $nota, ?bool $substituidaPodeTerSidoRecebida = null): void
    {
        try {
            $anterior = $nota->notaSubstituida()->first();
            $evento = EventosDeNotificacao::NFSE_EMITIDA;
            $variaveis = ['nota_numero' => $nota->numero ?: (string) $nota->id];

            if ($anterior instanceof ServiceInvoice
                && ($substituidaPodeTerSidoRecebida ?? $this->clientePodeTerRecebidoNota($anterior))) {
                $evento = EventosDeNotificacao::NFSE_SUBSTITUIDA;
                $variaveis['nota_anterior_numero'] = $anterior->numero ?: (string) $anterior->id;
            }

            $this->notificacoes->enfileirar($evento, $nota, [
                'canal' => EventosDeNotificacao::CANAL_EMAIL,
                'variaveis' => $variaveis,
                'contexto' => ['service_invoice_id' => $nota->id],
            ]);
        } catch (Throwable $falha) {
            Log::error('[fiscal] Falha ao enfileirar o documento fiscal autorizado.', [
                'service_invoice_id' => $nota->id,
                'erro' => $falha->getMessage(),
            ]);
        }
    }

    private function clientePodeTerRecebidoNota(ServiceInvoice $nota): bool
    {
        return NotificationQueue::query()
            ->whereIn('evento', [
                EventosDeNotificacao::NFSE_EMITIDA,
                EventosDeNotificacao::NFSE_SUBSTITUIDA,
            ])
            ->whereIn('situacao', [
                NotificationQueue::SITUACAO_ENVIANDO,
                NotificationQueue::SITUACAO_ENVIADA,
            ])
            ->where('contexto->referencia_tipo', 'service_invoice')
            ->where('contexto->referencia_id', $nota->id)
            ->exists();
    }

    private function protegerAvisosDuranteSubstituicao(ServiceInvoice $nota): bool
    {
        $avisos = NotificationQueue::query()
            ->whereIn('evento', [
                EventosDeNotificacao::NFSE_EMITIDA,
                EventosDeNotificacao::NFSE_SUBSTITUIDA,
            ])
            ->where('contexto->referencia_tipo', 'service_invoice')
            ->where('contexto->referencia_id', $nota->id)
            ->lockForUpdate()
            ->get();
        $podeTerSidoRecebido = $avisos->contains(
            fn (NotificationQueue $aviso): bool => in_array($aviso->situacao, [
                NotificationQueue::SITUACAO_ENVIANDO,
                NotificationQueue::SITUACAO_ENVIADA,
            ], true),
        );

        NotificationQueue::query()
            ->whereKey($avisos->pluck('id'))
            ->whereIn('situacao', [
                NotificationQueue::SITUACAO_PENDENTE,
                NotificationQueue::SITUACAO_ENVIANDO,
            ])
            ->update(['situacao' => NotificationQueue::SITUACAO_CANCELADA]);

        return $podeTerSidoRecebido;
    }

    private function registrarErroDaResposta(ServiceInvoice $nota, RespostaDeNfse $resposta): void
    {
        $mensagens = collect($resposta->mensagens)
            ->flatMap(static fn (array $mensagem): array => array_filter([
                $mensagem['descricao'] ?? null,
                $mensagem['correcao'] ?? null,
            ]))
            ->implode(' ');

        $nota->update([
            'situacao' => 'erro',
            'erro_mensagem' => $mensagens !== '' ? $mensagens : 'O provedor recusou a nota. Confira os dados fiscais e tente uma nova emissão.',
            'erro_temporario' => false,
            'proxima_tentativa_em' => null,
        ]);
    }

    private function registrarFalha(ServiceInvoice $nota, Throwable $falha, string $operacao): void
    {
        $temporaria = $falha instanceof FalhaFiscalException && $falha->ehTemporaria();
        $podeTentar = $temporaria && $nota->tentativas < self::MAXIMO_DE_TENTATIVAS;
        $mensagem = MensagemFiscalPublica::deFalha($falha, [
            'service_invoice_id' => $nota->id,
            'operacao' => $operacao,
        ]);

        $nota->update([
            'situacao' => 'erro',
            'erro_mensagem' => $mensagem,
            'erro_temporario' => $temporaria,
            'proxima_tentativa_em' => $podeTentar
                ? now()->addMinutes($this->esperaEmMinutos($nota->tentativas))
                : null,
        ]);
    }

    private function esperaEmMinutos(int $tentativas): int
    {
        return self::ESPERA_INICIAL_MINUTOS * (2 ** max(0, $tentativas - 1));
    }

    private function validarCliente(Client $cliente, FiscalConfig $configuracao, ?Address $endereco = null): void
    {
        $faltando = $this->validador->validar($cliente, $configuracao, $endereco);

        if ($faltando !== []) {
            throw new DadoFiscalInvalidoException(
                'Corrija os dados fiscais antes de emitir a nota: '.implode('; ', $faltando).'.'
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ServiceInvoice $nota, FiscalConfig $configuracao, Client $cliente, Address $endereco): array
    {
        $empresa = Company::current();
        $prestador = $this->documentoNoPayload((string) $empresa->cnpj);
        $tomador = $this->documentoNoPayload((string) $cliente->cnpj);

        if ($prestador === []) {
            throw new DadoFiscalInvalidoException('Preencha o campo CPF/CNPJ da empresa antes de emitir a nota fiscal.');
        }

        $dadosTomador = array_filter([
            ...$tomador,
            'IM' => $this->textoOuNulo($cliente->inscricao_municipal),
            'IE' => $this->textoOuNulo($cliente->inscricao_estadual),
            'xNome' => trim((string) $cliente->name),
            'end' => [
                'endNac' => [
                    'cMun' => preg_replace('/\D/', '', (string) $endereco->codigo_municipio_ibge),
                    'CEP' => preg_replace('/\D/', '', (string) $endereco->zip),
                ],
                'xLgr' => $endereco->street,
                'nro' => $endereco->number,
                'xBairro' => $endereco->district,
            ],
            'fone' => $this->textoOuNulo(preg_replace('/\D/', '', (string) $cliente->phone)),
            'email' => $this->textoOuNulo($cliente->email_nfe ?: $cliente->email),
        ], static fn (mixed $valor): bool => $valor !== null && $valor !== '');

        return [
            'tpAmb' => $configuracao->ambiente === 'producao' ? 1 : 2,
            'dhEmi' => BusinessDate::agora()->toIso8601String(),
            'verAplic' => 'Certificado-1.0',
            'dCompet' => BusinessDate::diaDe($nota->competencia),
            'prest' => [
                ...$prestador,
                'regTrib' => ['regEspTrib' => $this->regimeEspecial($configuracao->regime_tributario)],
            ],
            'toma' => $dadosTomador,
            'serv' => [
                'locPrest' => ['cLocPrestacao' => preg_replace('/\D/', '', (string) $endereco->codigo_municipio_ibge)],
                'cServ' => array_filter([
                    'cTribNac' => preg_replace('/\D/', '', (string) $configuracao->codigo_servico),
                    'xDescServ' => $nota->descricao_servico,
                    'cNAE' => $this->textoOuNulo(preg_replace('/\D/', '', (string) $configuracao->cnae)),
                ], static fn (mixed $valor): bool => $valor !== null && $valor !== ''),
            ],
            'valores' => [
                'vServPrest' => ['vServ' => $nota->valor_servico],
                'trib' => [
                    'tribMun' => [
                        'tribISSQN' => 1,
                        'tpRetISSQN' => $configuracao->iss_retido ? 2 : 1,
                        'pAliq' => $configuracao->aliquota_iss,
                    ],
                    'totTrib' => ['indTotTrib' => 0],
                ],
            ],
        ];
    }

    /** @return array<string, string> */
    private function documentoNoPayload(string $documento): array
    {
        $digitos = preg_replace('/\D/', '', $documento) ?? '';

        return match (strlen($digitos)) {
            11 => ['CPF' => $digitos],
            14 => ['CNPJ' => $digitos],
            default => [],
        };
    }

    private function regimeEspecial(string $regime): int
    {
        return match ($regime) {
            'mei' => 2,
            'sociedade_profissionais' => 3,
            'cooperativa' => 4,
            default => 0,
        };
    }

    private function calcularIss(int $valorEmCentavos, mixed $aliquota): int
    {
        $texto = trim((string) $aliquota);

        if (preg_match('/^(\d+)(?:[.,](\d{1,2}))?$/', $texto, $partes) !== 1) {
            throw new DadoFiscalInvalidoException('A Alíquota do ISS da configuração fiscal é inválida.');
        }

        $centesimosDePercentual = ((int) $partes[1] * 100)
            + (int) str_pad($partes[2] ?? '', 2, '0');

        return intdiv(($valorEmCentavos * $centesimosDePercentual) + 5000, 10000);
    }

    private function registrarPendenciaDeValidacao(
        Client $cliente,
        ?WorkOrder $os,
        ?Receivable $titulo,
        FiscalConfig $configuracao,
        ?Address $endereco,
        int $valorEmCentavos,
        int $issEmCentavos,
        bool $issRetido,
        string $descricao,
        string $competencia,
        DadoFiscalInvalidoException $falha,
        ?ServiceInvoice $pendenciaReprocessada = null,
    ): ServiceInvoice {
        return DB::transaction(function () use ($cliente, $os, $titulo, $configuracao, $endereco, $valorEmCentavos, $issEmCentavos, $issRetido, $descricao, $competencia, $falha, $pendenciaReprocessada): ServiceInvoice {
            $pendenciaAtual = $this->travarPendenciaDeReprocessamento($pendenciaReprocessada);

            if ($pendenciaAtual instanceof ServiceInvoice && $pendenciaAtual->reprocessada_por_id !== null) {
                return $pendenciaAtual->reprocessadaPor()->firstOrFail();
            }

            $this->travarOrigem($os, $titulo);

            $nova = ServiceInvoice::create([
                'fiscal_config_id' => $configuracao->id,
                'client_id' => $cliente->id,
                'address_id' => $endereco?->id,
                'work_order_id' => $os?->id,
                'receivable_id' => $titulo?->id,
                'situacao' => 'erro',
                'valor_servico' => Dinheiro::paraDecimal($valorEmCentavos),
                'valor_iss' => Dinheiro::paraDecimal($issEmCentavos),
                'valor_liquido' => Dinheiro::paraDecimal(max(
                    0,
                    $valorEmCentavos - ($issRetido ? $issEmCentavos : 0),
                )),
                'descricao_servico' => $descricao,
                'competencia' => $competencia,
                'erro_mensagem' => $falha->getMessage(),
                'erro_temporario' => false,
                'tentativas' => 0,
            ]);

            if ($pendenciaAtual instanceof ServiceInvoice) {
                $this->vincularReprocessamento($pendenciaAtual, $nova);
            }

            return $nova;
        });
    }

    private function travarPendenciaDeReprocessamento(?ServiceInvoice $pendencia): ?ServiceInvoice
    {
        if (! $pendencia instanceof ServiceInvoice) {
            return null;
        }

        $atual = ServiceInvoice::query()
            ->whereKey($pendencia->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($atual->situacao !== 'erro') {
            throw new DadoFiscalInvalidoException('Somente uma pendência fiscal pode ser reprocessada.');
        }

        if ($atual->metadados_substituicao !== null) {
            throw new DadoFiscalInvalidoException(
                'Esta pendência pertence a uma substituição. Abra a nota original e repita a substituição com os dados corrigidos.'
            );
        }

        return $atual;
    }

    private function vincularReprocessamento(ServiceInvoice $pendencia, ServiceInvoice $nova): void
    {
        if ((int) $pendencia->company_id !== (int) $nova->company_id) {
            throw new RuntimeException('As notas do reprocessamento pertencem a empresas diferentes.');
        }

        if ($pendencia->situacao !== 'erro' || $pendencia->reprocessada_por_id !== null) {
            throw new RuntimeException('A pendência fiscal foi alterada durante o reprocessamento.');
        }

        $pendencia->update(['reprocessada_por_id' => $nova->id]);
    }

    private function descricaoDaOs(WorkOrder $os): string
    {
        $servicos = $os->services->map(static function ($servico): string {
            $observacao = trim((string) $servico->pivot?->observations);

            return $observacao === '' ? $servico->name : "{$servico->name}: {$observacao}";
        })->filter()->values();

        if ($servicos->isEmpty() && $os->service !== null) {
            $servicos->push($os->service->name);
        }

        if ($servicos->isEmpty() && filled($os->description)) {
            $servicos->push(trim($os->description));
        }

        return $servicos->isEmpty()
            ? "Serviços executados na OS {$os->order_number}"
            : $servicos->implode('; ');
    }

    private function notaAtivaDaMesmaPrestacao(?WorkOrder $os, ?Receivable $titulo): ?ServiceInvoice
    {
        return ServiceInvoice::query()
            ->whereIn('situacao', self::SITUACOES_QUE_BLOQUEIAM_DUPLICIDADE)
            ->where(function (Builder $consulta) use ($os, $titulo): void {
                if ($os !== null) {
                    $consulta->where('work_order_id', $os->id)
                        ->orWhereHas('receivable', fn (Builder $titulos): Builder => $titulos->where('work_order_id', $os->id));
                }

                if ($titulo !== null) {
                    $consulta->orWhere('receivable_id', $titulo->id);

                    if ($titulo->work_order_id !== null) {
                        $consulta->orWhere('work_order_id', $titulo->work_order_id)
                            ->orWhereHas('receivable', fn (Builder $titulos): Builder => $titulos->where('work_order_id', $titulo->work_order_id));
                    }
                }
            })
            ->orderByDesc('id')
            ->first();
    }

    private function travarOrigem(?WorkOrder $os, ?Receivable $titulo): void
    {
        if ($os !== null) {
            WorkOrder::query()->whereKey($os->id)->lockForUpdate()->firstOrFail();
        }

        if ($titulo !== null) {
            Receivable::query()->whereKey($titulo->id)->lockForUpdate()->firstOrFail();
        }
    }

    private function garantirOrigemDoTenant(WorkOrder|Receivable $origem): void
    {
        if ((int) $origem->company_id !== TenantAtual::exigirId()) {
            throw new RuntimeException('A origem da nota fiscal não pertence à empresa atual.');
        }
    }

    private function garantirMesmoTenant(Client $cliente, FiscalConfig $configuracao, ?WorkOrder $os, ?Receivable $titulo, ?Address $endereco): void
    {
        $empresa = TenantAtual::exigirId();

        foreach (array_filter([$cliente, $configuracao, $os, $titulo, $endereco]) as $registro) {
            if ((int) $registro->company_id !== $empresa) {
                throw new RuntimeException('Os dados da nota fiscal pertencem a empresas diferentes.');
            }
        }

        if ($os !== null && (int) $os->client_id !== (int) $cliente->id) {
            throw new RuntimeException('A ordem de serviço não pertence ao cliente da nota fiscal.');
        }

        if ($titulo !== null && (int) $titulo->client_id !== (int) $cliente->id) {
            throw new RuntimeException('O título não pertence ao cliente da nota fiscal.');
        }

        if ($endereco !== null && (int) $endereco->client_id !== (int) $cliente->id) {
            throw new RuntimeException('O endereço enviado na nota fiscal não pertence ao cliente.');
        }
    }

    private function referencia(ServiceInvoice $nota): string
    {
        if (filled($nota->referencia_provedor)) {
            return (string) $nota->referencia_provedor;
        }

        $referencia = "nfse-{$nota->company_id}-{$nota->id}";
        ServiceInvoice::query()
            ->whereKey($nota->id)
            ->whereNull('referencia_provedor')
            ->update(['referencia_provedor' => $referencia]);

        return (string) $nota->refresh()->referencia_provedor;
    }

    private function configuracaoDaNota(ServiceInvoice $nota): FiscalConfig
    {
        $nota->loadMissing('fiscalConfig');

        if (! $nota->fiscalConfig instanceof FiscalConfig) {
            throw new RuntimeException('A configuração fiscal usada nesta nota não está disponível para concluir o processamento.');
        }

        return $nota->fiscalConfig;
    }

    private function textoOuNulo(mixed $valor): ?string
    {
        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    private function configuracaoDoGatilho(string $gatilho): ?FiscalConfig
    {
        return FiscalConfig::query()
            ->where('ativo', true)
            ->where('emissao_automatica', true)
            ->where('gatilho_emissao_automatica', $gatilho)
            ->first();
    }
}
