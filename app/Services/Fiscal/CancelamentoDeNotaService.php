<?php

namespace App\Services\Fiscal;

use App\Exceptions\CancelamentoNaoEncontradoException;
use App\Exceptions\DadoFiscalInvalidoException;
use App\Exceptions\FalhaFiscalException;
use App\Exceptions\NotaJaCanceladaException;
use App\Exceptions\PrazoDeCancelamentoExpiradoException;
use App\Exceptions\RecusaFiscalException;
use App\Models\Address;
use App\Models\FiscalConfig;
use App\Models\NotificationQueue;
use App\Models\ServiceInvoice;
use App\Models\User;
use App\Services\NotificationService;
use App\Services\ServiceInvoiceService;
use App\Support\BusinessDate;
use App\Support\Dinheiro;
use App\Support\EventosDeNotificacao;
use App\Support\Fiscal\MensagemFiscalPublica;
use App\Support\Fiscal\RespostaDeCancelamento;
use App\Support\TenantAtual;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class CancelamentoDeNotaService
{
    public const PERMISSAO = 'fiscal-cancelar';

    private const MOTIVO_MINIMO = 15;

    private const DURACAO_CLAIM_MINUTOS = 5;

    public function __construct(
        private readonly ResolvedorDeProvedor $resolvedor,
        private readonly ServiceInvoiceService $notas,
        private readonly NotificationService $notificacoes,
    ) {}

    public function cancelar(ServiceInvoice $nota, string $motivo): ServiceInvoice
    {
        $this->garantirAcesso($nota);
        $motivo = $this->motivoValido($motivo);
        [$reservada, $token, $recuperacao] = $this->reservarCancelamento($nota, $motivo);

        return $this->executarCancelamento($reservada, $token, $recuperacao, true);
    }

    public function processarCancelamentosPendentes(): int
    {
        $processados = 0;

        ServiceInvoice::query()
            ->where('situacao', 'cancelamento_pendente')
            ->where(function (Builder $consulta): void {
                $consulta->whereNull('processamento_bloqueado_ate')
                    ->orWhere('processamento_bloqueado_ate', '<=', now());
            })
            ->orderBy('id')
            ->chunkById(100, function ($notas) use (&$processados): void {
                foreach ($notas as $nota) {
                    $reserva = $this->reservarPollingDeCancelamento($nota);

                    if ($reserva === null) {
                        continue;
                    }

                    [$reservada, $token] = $reserva;

                    try {
                        $this->executarCancelamento($reservada, $token, true, false);
                    } catch (Throwable $falha) {
                        Log::warning('[fiscal] Falha ao consultar cancelamento pendente.', [
                            'service_invoice_id' => $reservada->id,
                            'operacao' => 'consultar_cancelamento',
                            'exception' => $falha,
                        ]);
                    }

                    $processados++;
                }
            });

        return $processados;
    }

    /** @param array<string, mixed> $dados */
    public function substituir(ServiceInvoice $nota, array $dados): ServiceInvoice
    {
        $this->garantirAcesso($nota);
        $motivo = $this->motivoValido((string) ($dados['motivo'] ?? ''));
        $codigo = trim((string) ($dados['codigo_motivo'] ?? '1'));

        if ($codigo === '') {
            throw ValidationException::withMessages(['codigo_motivo' => 'Informe o código do motivo da substituição.']);
        }

        [$substituta, $token] = $this->reservarSubstituta($nota, $dados, $codigo, $motivo);

        if ($token === null) {
            return $substituta;
        }

        try {
            $nota->refresh()->loadMissing('fiscalConfig');
            $configuracao = $this->configuracaoOriginal($nota);
            $metadados = (array) $substituta->metadados_substituicao;
            $id = $this->resolvedor->paraConfiguracao($configuracao)->substituir(
                $configuracao,
                (string) $metadados['id_provedor_nota_substituida'],
                (string) $metadados['codigo_motivo'],
                (string) $metadados['motivo'],
                (array) $substituta->payload_dps,
                (string) $substituta->referencia_provedor,
            );
        } catch (Throwable $falha) {
            $ambigua = $falha instanceof FalhaFiscalException && $falha->ehTemporaria();
            $mensagem = $this->mensagemDaFalha($substituta, $falha, 'substituir');

            ServiceInvoice::query()
                ->whereKey($substituta->id)
                ->where('processamento_token', $token)
                ->update([
                    'situacao' => 'erro',
                    'erro_mensagem' => $mensagem,
                    'erro_temporario' => $ambigua,
                    'proxima_tentativa_em' => null,
                    'processamento_token' => null,
                    'processamento_bloqueado_ate' => null,
                ]);

            throw $falha;
        }

        DB::transaction(function () use ($nota, $substituta, $id, $token): void {
            $antiga = ServiceInvoice::query()->whereKey($nota->id)->lockForUpdate()->firstOrFail();
            $nova = ServiceInvoice::query()->whereKey($substituta->id)->lockForUpdate()->firstOrFail();

            if ((int) $antiga->substituida_por_id !== (int) $nova->id
                || ! hash_equals((string) $nova->processamento_token, $token)) {
                throw new RuntimeException('A reserva da substituição foi alterada durante o processamento.');
            }

            $nova->update([
                'situacao' => 'processando',
                'provedor_id' => $id,
                'erro_mensagem' => null,
                'erro_temporario' => null,
                'processamento_token' => null,
                'processamento_bloqueado_ate' => null,
            ]);
        });

        return $substituta->refresh();
    }

    private function executarCancelamento(
        ServiceInvoice $nota,
        string $token,
        bool $recuperacao,
        bool $propagarFalha,
    ): ServiceInvoice {
        try {
            $nota->loadMissing('fiscalConfig');
            $configuracao = $this->configuracaoOriginal($nota);
            $provedor = $this->resolvedor->paraConfiguracao($configuracao);

            if ($recuperacao) {
                try {
                    $resposta = $provedor->consultarCancelamento($configuracao, $this->idNoProvedor($nota));
                } catch (CancelamentoNaoEncontradoException) {
                    $resposta = $provedor->cancelar(
                        $configuracao,
                        $this->idNoProvedor($nota),
                        (string) $nota->motivo_cancelamento,
                    );
                }
            } else {
                $resposta = $provedor->cancelar(
                    $configuracao,
                    $this->idNoProvedor($nota),
                    (string) $nota->motivo_cancelamento,
                );
            }

            if ($this->cancelamentoAutorizado($resposta)) {
                return $this->concluirCancelamento($nota, $token, $resposta);
            }

            if ($this->cancelamentoPendente($resposta)) {
                return $this->registrarCancelamentoPendente($nota, $token, $resposta);
            }

            throw $this->falhaDaResposta($resposta);
        } catch (NotaJaCanceladaException $falha) {
            if ($recuperacao) {
                return $this->concluirCancelamento(
                    $nota,
                    $token,
                    new RespostaDeCancelamento(
                        id: (string) ($nota->cancelamento_provedor_id ?: 'cancelamento-recuperado'),
                        situacao: 'autorizado',
                    ),
                );
            }

            $this->registrarRecusa($nota, $token, $falha);
            ServiceInvoice::query()->whereKey($nota->id)->update([
                'cancelamento_solicitado_em' => null,
                'cancelamento_provedor_id' => null,
                'motivo_cancelamento' => null,
            ]);

            throw $falha;
        } catch (Throwable $falha) {
            $operacao = $recuperacao ? 'consultar_cancelamento' : 'cancelar';

            if ($falha instanceof FalhaFiscalException && $falha->ehTemporaria()) {
                $this->registrarFalhaTemporaria($nota, $token, $falha, $operacao);
            } else {
                $this->registrarRecusa($nota, $token, $falha, $operacao);
            }

            if ($propagarFalha) {
                throw $falha;
            }

            throw $falha;
        }
    }

    private function concluirCancelamento(
        ServiceInvoice $nota,
        string $token,
        RespostaDeCancelamento $resposta,
    ): ServiceInvoice {
        [$cancelada, $jaRecebeu] = DB::transaction(function () use ($nota, $token, $resposta): array {
            $atual = ServiceInvoice::query()->whereKey($nota->id)->lockForUpdate()->firstOrFail();
            $this->garantirToken($atual, $token, 'cancelamento');
            $avisos = $this->avisosDoDocumento($atual)->lockForUpdate()->get();
            $jaRecebeu = $avisos->contains(
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

            $atual->update([
                'situacao' => 'cancelada',
                'cancelada_em' => now(),
                'cancelamento_provedor_id' => $resposta->id,
                'erro_mensagem' => null,
                'processamento_token' => null,
                'processamento_bloqueado_ate' => null,
            ]);

            return [$atual, $jaRecebeu];
        });

        if ($jaRecebeu) {
            try {
                $this->notificacoes->enfileirar(EventosDeNotificacao::NFSE_CANCELADA, $cancelada, [
                    'canal' => EventosDeNotificacao::CANAL_EMAIL,
                    'variaveis' => [
                        'nota_numero' => $cancelada->numero ?: (string) $cancelada->id,
                        'motivo_fiscal' => $cancelada->motivo_cancelamento,
                    ],
                    'contexto' => ['service_invoice_id' => $cancelada->id],
                ]);
            } catch (Throwable $falha) {
                Log::error('[fiscal] Falha ao enfileirar aviso de cancelamento.', [
                    'service_invoice_id' => $cancelada->id,
                    'erro' => $falha->getMessage(),
                ]);
            }
        }

        return $cancelada->refresh();
    }

    private function registrarCancelamentoPendente(
        ServiceInvoice $nota,
        string $token,
        RespostaDeCancelamento $resposta,
    ): ServiceInvoice {
        ServiceInvoice::query()
            ->whereKey($nota->id)
            ->where('processamento_token', $token)
            ->update([
                'situacao' => 'cancelamento_pendente',
                'cancelamento_provedor_id' => $resposta->id,
                'erro_mensagem' => null,
                'processamento_token' => null,
                'processamento_bloqueado_ate' => null,
            ]);

        return $nota->refresh();
    }

    private function registrarFalhaTemporaria(
        ServiceInvoice $nota,
        string $token,
        Throwable $falha,
        string $operacao = 'cancelar',
    ): void {
        $mensagem = $this->mensagemDaFalha($nota, $falha, $operacao);

        ServiceInvoice::query()
            ->whereKey($nota->id)
            ->where('processamento_token', $token)
            ->update([
                'situacao' => 'cancelamento_pendente',
                'erro_mensagem' => $mensagem,
                'processamento_token' => null,
                'processamento_bloqueado_ate' => null,
            ]);
    }

    private function registrarRecusa(
        ServiceInvoice $nota,
        string $token,
        Throwable $falha,
        string $operacao = 'cancelar',
    ): void {
        $mensagem = $this->mensagemDaFalha($nota, $falha, $operacao);

        ServiceInvoice::query()
            ->whereKey($nota->id)
            ->where('processamento_token', $token)
            ->update([
                'situacao' => 'emitida',
                'erro_mensagem' => $mensagem,
                'cancelamento_solicitado_em' => null,
                'cancelamento_provedor_id' => null,
                'processamento_token' => null,
                'processamento_bloqueado_ate' => null,
            ]);
    }

    private function mensagemDaFalha(ServiceInvoice $nota, Throwable $falha, string $operacao): string
    {
        return MensagemFiscalPublica::deFalha($falha, [
            'service_invoice_id' => $nota->id,
            'operacao' => $operacao,
        ]);
    }

    /** @return array{ServiceInvoice, string, bool} */
    private function reservarCancelamento(ServiceInvoice $nota, string $motivo): array
    {
        return DB::transaction(function () use ($nota, $motivo): array {
            $atual = ServiceInvoice::query()->whereKey($nota->id)->lockForUpdate()->firstOrFail();
            $this->garantirPodeCancelar($atual);
            $this->garantirSemClaimVigente($atual);
            $recuperacao = $atual->cancelamento_solicitado_em !== null;

            if ($recuperacao && ! hash_equals((string) $atual->motivo_cancelamento, $motivo)) {
                throw ValidationException::withMessages([
                    'motivo' => 'Já existe uma tentativa de cancelamento com outro motivo. Repita o motivo original para consultar o resultado.',
                ]);
            }

            $token = (string) Str::uuid();
            $atual->update([
                'processamento_token' => $token,
                'processamento_bloqueado_ate' => now()->addMinutes(self::DURACAO_CLAIM_MINUTOS),
                'cancelamento_solicitado_em' => $atual->cancelamento_solicitado_em ?? now(),
                'motivo_cancelamento' => $motivo,
            ]);

            return [$atual->refresh(), $token, $recuperacao];
        });
    }

    /** @return array{ServiceInvoice, string}|null */
    private function reservarPollingDeCancelamento(ServiceInvoice $nota): ?array
    {
        return DB::transaction(function () use ($nota): ?array {
            $atual = ServiceInvoice::query()->whereKey($nota->id)->lockForUpdate()->first();

            if (! $atual instanceof ServiceInvoice || $atual->situacao !== 'cancelamento_pendente') {
                return null;
            }

            try {
                $this->garantirSemClaimVigente($atual);
            } catch (RuntimeException) {
                return null;
            }

            $token = (string) Str::uuid();
            $atual->update([
                'processamento_token' => $token,
                'processamento_bloqueado_ate' => now()->addMinutes(self::DURACAO_CLAIM_MINUTOS),
            ]);

            return [$atual->refresh(), $token];
        });
    }

    /** @param array<string, mixed> $dados
     * @return array{ServiceInvoice, ?string}
     */
    private function reservarSubstituta(
        ServiceInvoice $nota,
        array $dados,
        string $codigo,
        string $motivo,
    ): array {
        return DB::transaction(function () use ($nota, $dados, $codigo, $motivo): array {
            $antiga = ServiceInvoice::query()->whereKey($nota->id)->lockForUpdate()->firstOrFail();

            if ($antiga->substituida_por_id !== null) {
                $existente = ServiceInvoice::query()
                    ->whereKey($antiga->substituida_por_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                if (in_array($existente->situacao, ['processando', 'emitida'], true)) {
                    return [$existente, null];
                }

                if ($existente->situacao !== 'erro') {
                    throw ValidationException::withMessages([
                        'nota' => 'A nota substituta já possui uma situação que impede novo envio.',
                    ]);
                }

                if ($this->claimVigente($existente)) {
                    return [$existente, null];
                }

                if (filled($existente->processamento_token) || $existente->erro_temporario) {
                    $token = $this->aplicarClaim($existente);

                    return [$existente->refresh(), $token];
                }

                $this->aplicarCorrecoes($existente, $dados);
                $this->congelarSubstituicao($existente, $antiga, $codigo, $motivo, true);
                $token = $this->aplicarClaim($existente);
                $antiga->update(['motivo_substituicao' => $motivo]);

                return [$existente->refresh(), $token];
            }

            $this->garantirEmitida($antiga);
            $nova = ServiceInvoice::create([
                'fiscal_config_id' => $antiga->fiscal_config_id,
                'client_id' => $antiga->client_id,
                'address_id' => $antiga->address_id,
                'work_order_id' => $antiga->work_order_id,
                'receivable_id' => $antiga->receivable_id,
                'situacao' => 'erro',
                'valor_servico' => $antiga->valor_servico,
                'valor_iss' => $antiga->valor_iss,
                'valor_liquido' => $antiga->valor_liquido,
                'descricao_servico' => $antiga->descricao_servico,
                'competencia' => $antiga->competencia,
                'referencia_provedor' => 'nfse-subst-'.$antiga->company_id.'-'.Str::uuid(),
                'erro_mensagem' => 'Substituição fiscal reservada e aguardando envio ao provedor.',
                'erro_temporario' => false,
            ]);
            $this->aplicarCorrecoes($nova, $dados);
            $this->congelarSubstituicao($nova, $antiga, $codigo, $motivo);
            $token = $this->aplicarClaim($nova);
            $antiga->update([
                'substituida_por_id' => $nova->id,
                'motivo_substituicao' => $motivo,
            ]);

            return [$nova->refresh(), $token];
        });
    }

    private function congelarSubstituicao(
        ServiceInvoice $substituta,
        ServiceInvoice $substituida,
        string $codigo,
        string $motivo,
        bool $substituirSnapshot = false,
    ): void {
        if ($substituirSnapshot) {
            $substituta->update([
                'payload_dps' => null,
                'metadados_substituicao' => null,
            ]);
        }

        $payload = $this->notas->dadosDaDps($substituta->refresh());
        $substituta->update([
            'payload_dps' => $payload,
            'metadados_substituicao' => [
                'id_provedor_nota_substituida' => $this->idNoProvedor($substituida),
                'codigo_motivo' => $codigo,
                'motivo' => $motivo,
            ],
        ]);
    }

    /** @param array<string, mixed> $dados */
    private function aplicarCorrecoes(ServiceInvoice $nota, array $dados): void
    {
        $alteracoes = [];

        if (array_key_exists('descricao_servico', $dados)) {
            $descricao = trim((string) $dados['descricao_servico']);

            if ($descricao === '') {
                throw ValidationException::withMessages(['descricao_servico' => 'Informe a descrição do serviço.']);
            }

            $alteracoes['descricao_servico'] = $descricao;
        }

        if (array_key_exists('valor_servico', $dados)) {
            $valor = Dinheiro::centavos($dados['valor_servico']);

            if ($valor <= 0) {
                throw ValidationException::withMessages(['valor_servico' => 'Informe um valor fiscal maior que zero.']);
            }

            $configuracao = $nota->fiscalConfig()->firstOrFail();
            $iss = $this->calcularIss($valor, $configuracao->aliquota_iss);
            $liquido = max(0, $valor - ($configuracao->iss_retido ? $iss : 0));
            $alteracoes['valor_servico'] = Dinheiro::paraDecimal($valor);
            $alteracoes['valor_iss'] = Dinheiro::paraDecimal($iss);
            $alteracoes['valor_liquido'] = Dinheiro::paraDecimal($liquido);
        }

        if (array_key_exists('competencia', $dados)) {
            $competencia = BusinessDate::diaDe($dados['competencia']);

            if ($competencia === null) {
                throw ValidationException::withMessages(['competencia' => 'Informe uma competência válida.']);
            }

            $alteracoes['competencia'] = $competencia;
        }

        if (array_key_exists('address_id', $dados)) {
            $endereco = Address::query()->find($dados['address_id']);

            if (! $endereco instanceof Address || (int) $endereco->client_id !== (int) $nota->client_id) {
                throw ValidationException::withMessages(['address_id' => 'O endereço fiscal informado não pertence ao cliente da nota.']);
            }

            $alteracoes['address_id'] = $endereco->id;
        }

        if ($alteracoes !== []) {
            $nota->update($alteracoes);
        }
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

    private function aplicarClaim(ServiceInvoice $nota): string
    {
        $token = (string) Str::uuid();
        $nota->update([
            'processamento_token' => $token,
            'processamento_bloqueado_ate' => now()->addMinutes(self::DURACAO_CLAIM_MINUTOS),
        ]);

        return $token;
    }

    private function garantirAcesso(ServiceInvoice $nota): User
    {
        if ((int) $nota->company_id !== TenantAtual::exigirId()) {
            throw new RuntimeException('A nota fiscal não pertence à empresa atual.');
        }

        $usuario = auth()->user();

        if (! $usuario instanceof User
            || (int) $usuario->company_id !== (int) $nota->company_id
            || ! $usuario->hasRole('administrador')
            || ! $usuario->can(self::PERMISSAO)) {
            throw new AuthorizationException('Você não tem permissão para cancelar ou substituir nota fiscal.');
        }

        return $usuario;
    }

    private function garantirPodeCancelar(ServiceInvoice $nota): void
    {
        if ($nota->substituida_por_id !== null) {
            throw ValidationException::withMessages([
                'nota' => 'A nota possui uma substituição em andamento e não pode ser cancelada agora.',
            ]);
        }

        if (! in_array($nota->situacao, ['emitida', 'cancelamento_pendente'], true)) {
            throw ValidationException::withMessages(['nota' => 'Somente uma nota emitida pode ser cancelada ou substituída.']);
        }
    }

    private function garantirEmitida(ServiceInvoice $nota): void
    {
        if ($nota->situacao !== 'emitida') {
            throw ValidationException::withMessages(['nota' => 'Somente uma nota emitida pode ser cancelada ou substituída.']);
        }
    }

    private function garantirSemClaimVigente(ServiceInvoice $nota): void
    {
        if ($this->claimVigente($nota)) {
            throw new RuntimeException('Esta nota já possui uma operação fiscal em andamento.');
        }
    }

    private function claimVigente(ServiceInvoice $nota): bool
    {
        return filled($nota->processamento_token)
            && ($nota->processamento_bloqueado_ate === null || $nota->processamento_bloqueado_ate->isFuture());
    }

    private function garantirToken(ServiceInvoice $nota, string $token, string $operacao): void
    {
        if (! hash_equals((string) $nota->processamento_token, $token)) {
            throw new RuntimeException("O {$operacao} perdeu a reserva de processamento. Atualize a nota antes de tentar novamente.");
        }
    }

    private function motivoValido(string $motivo): string
    {
        $motivo = trim($motivo);

        if (mb_strlen($motivo) < self::MOTIVO_MINIMO) {
            throw ValidationException::withMessages([
                'motivo' => 'O motivo precisa ter pelo menos 15 caracteres.',
            ]);
        }

        return $motivo;
    }

    private function configuracaoOriginal(ServiceInvoice $nota): FiscalConfig
    {
        if (! $nota->fiscalConfig instanceof FiscalConfig) {
            throw new RuntimeException('A configuração fiscal original da nota não está disponível.');
        }

        return $nota->fiscalConfig;
    }

    private function idNoProvedor(ServiceInvoice $nota): string
    {
        $id = trim((string) $nota->provedor_id);

        if ($id === '') {
            throw ValidationException::withMessages(['nota' => 'A nota emitida está sem identificador do provedor fiscal.']);
        }

        return $id;
    }

    private function cancelamentoAutorizado(RespostaDeCancelamento $resposta): bool
    {
        $situacao = $this->normalizar($resposta->situacao);

        return Str::contains($situacao, ['autoriz', 'cancelad', 'concluid']);
    }

    private function cancelamentoPendente(RespostaDeCancelamento $resposta): bool
    {
        $situacao = $this->normalizar($resposta->situacao);

        return Str::contains($situacao, ['pendent', 'process']);
    }

    private function falhaDaResposta(RespostaDeCancelamento $resposta): FalhaFiscalException
    {
        $mensagem = collect($resposta->mensagens)
            ->flatMap(static fn (array $item): array => array_filter([
                $item['descricao'] ?? null,
                $item['correcao'] ?? null,
            ]))
            ->push($resposta->motivo)
            ->filter()
            ->implode(' ');
        $normalizada = $this->normalizar($mensagem);

        if (str_contains($normalizada, 'prazo')
            && (str_contains($normalizada, 'cancel') || str_contains($normalizada, 'extemporan'))) {
            return new PrazoDeCancelamentoExpiradoException;
        }

        return new RecusaFiscalException;
    }

    private function normalizar(string $texto): string
    {
        return mb_strtolower(Str::ascii($texto), 'UTF-8');
    }

    private function avisosDoDocumento(ServiceInvoice $nota): Builder
    {
        return NotificationQueue::query()
            ->whereIn('evento', [
                EventosDeNotificacao::NFSE_EMITIDA,
                EventosDeNotificacao::NFSE_SUBSTITUIDA,
            ])
            ->where('contexto->referencia_tipo', 'service_invoice')
            ->where('contexto->referencia_id', $nota->id);
    }
}
