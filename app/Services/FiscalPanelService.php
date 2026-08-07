<?php

namespace App\Services;

use App\Exceptions\ArquivoFiscalIndisponivelException;
use App\Models\Client;
use App\Models\FiscalConfig;
use App\Models\ServiceInvoice;
use App\Support\Fiscal\MensagemFiscalPublica;
use App\Support\Fiscal\NotaFiscalPublica;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class FiscalPanelService
{
    public function __construct(
        private readonly ServiceInvoiceService $notas,
    ) {}

    public function ambienteAtual(): ?string
    {
        return FiscalConfig::query()
            ->where('ativo', true)
            ->value('ambiente');
    }

    /**
     * @param  array{situacao?: string|null, de?: string|null, ate?: string|null, client_id?: int|null, por_pagina?: int|null}  $filtros
     */
    public function listar(array $filtros): LengthAwarePaginator
    {
        $paginacao = ServiceInvoice::query()
            ->with(['client', 'substituidaPor', 'notaSubstituida', 'reprocessadaPor', 'pendenciasReprocessadas'])
            ->when($filtros['situacao'] ?? null, fn (Builder $q, string $situacao) => $q->where('situacao', $situacao))
            ->when($filtros['de'] ?? null, fn (Builder $q, string $de) => $q->whereDate('competencia', '>=', $de))
            ->when($filtros['ate'] ?? null, fn (Builder $q, string $ate) => $q->whereDate('competencia', '<=', $ate))
            ->when($filtros['client_id'] ?? null, fn (Builder $q, int $cliente) => $q->where('client_id', $cliente))
            ->orderByDesc('competencia')
            ->orderByDesc('id')
            ->paginate((int) ($filtros['por_pagina'] ?? 20))
            ->withQueryString();

        $paginacao->through(fn (ServiceInvoice $nota): array => NotaFiscalPublica::de($nota));

        return $paginacao;
    }

    /** @return array<int, array{id: int, nome: string}> */
    public function clientesParaFiltro(): array
    {
        return Client::query()
            ->whereHas('serviceInvoices')
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn (Client $cliente): array => ['id' => (int) $cliente->id, 'nome' => $cliente->name])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function pendencias(): array
    {
        return ServiceInvoice::query()
            ->with('client:id,name')
            ->where('situacao', 'erro')
            ->whereNull('reprocessada_por_id')
            ->orderBy('erro_mensagem')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (ServiceInvoice $nota): string => MensagemFiscalPublica::deTextoPersistido($nota->erro_mensagem) ?: 'Falha fiscal sem motivo informado.')
            ->map(function ($notas, string $motivo): array {
                return [
                    'motivo' => $motivo,
                    'contagem' => $notas->count(),
                    'nota_ids' => $notas->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                    'reprocessaveis_em_lote' => $notas->whereNull('metadados_substituicao')->count(),
                    'clientes' => $notas
                        ->filter(fn (ServiceInvoice $nota): bool => $nota->client !== null)
                        ->groupBy('client_id')
                        ->map(function ($notasDoCliente): array {
                            /** @var ServiceInvoice $primeira */
                            $primeira = $notasDoCliente->first();

                            $reprocessaveis = $notasDoCliente->whereNull('metadados_substituicao');

                            return [
                                'id' => (int) $primeira->client_id,
                                'nome' => $primeira->client->name,
                                'url_edicao' => route('clients.edit', $primeira->client_id),
                                'nota_ids' => $reprocessaveis->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
                                'origens' => $reprocessaveis->map(fn (ServiceInvoice $nota): array => [
                                    'nota_id' => (int) $nota->id,
                                    'work_order_id' => $nota->work_order_id === null ? null : (int) $nota->work_order_id,
                                    'receivable_id' => $nota->receivable_id === null ? null : (int) $nota->receivable_id,
                                    'address_id' => $nota->address_id === null ? null : (int) $nota->address_id,
                                ])->values()->all(),
                            ];
                        })
                        ->values()
                        ->all(),
                ];
            })
            ->values()
            ->all();
    }

    public function reprocessar(ServiceInvoice $nota): ServiceInvoice
    {
        return $this->notas->reemitir($nota);
    }

    /**
     * @param  array<int, int>  $ids
     * @return array{processadas: array<int, array<string, mixed>>, falhas: array<int, array<string, mixed>>}
     */
    public function reprocessarEmLote(array $ids, ?string $motivo): array
    {
        $consulta = ServiceInvoice::query()
            ->where('situacao', 'erro')
            ->whereNull('reprocessada_por_id')
            ->whereNull('metadados_substituicao');

        if ($ids !== []) {
            $consulta->whereKey($ids);
        } else {
            $consulta->where('erro_mensagem', $motivo);
        }

        $processadas = [];
        $falhas = [];

        foreach ($consulta->orderBy('id')->get() as $nota) {
            try {
                $nova = $this->reprocessar($nota);
                if ($nova->situacao === 'erro') {
                    $falhas[] = [
                        'pendencia_id' => (int) $nota->id,
                        'nota' => NotaFiscalPublica::de($nova),
                        'message' => MensagemFiscalPublica::deTextoPersistido($nova->erro_mensagem)
                            ?: 'A nova tentativa continua com pendência fiscal.',
                    ];
                } else {
                    $processadas[] = [
                        'pendencia_id' => (int) $nota->id,
                        'resultado_fiscal' => $this->resultadoFiscal($nova),
                        'nota' => NotaFiscalPublica::de($nova),
                    ];
                }
            } catch (Throwable $falha) {
                $falhas[] = [
                    'pendencia_id' => (int) $nota->id,
                    'message' => MensagemFiscalPublica::deFalha($falha, [
                        'service_invoice_id' => $nota->id,
                        'operacao' => 'reprocessar_lote',
                    ]),
                ];
            }
        }

        return compact('processadas', 'falhas');
    }

    public function resultadoFiscal(ServiceInvoice $nota): string
    {
        return match ($nota->situacao) {
            'erro' => 'erro',
            'pendente', 'processando', 'cancelamento_pendente' => 'pendente',
            default => 'concluido',
        };
    }

    /** @return array{caminho: string, nome: string, mime: string} */
    public function arquivo(ServiceInvoice $nota, string $tipo): array
    {
        $extensao = $tipo === 'pdf' ? 'pdf' : 'xml';
        $campo = $extensao.'_path';
        $caminhoEsperado = "fiscal/empresa-{$nota->company_id}/nota-{$nota->id}/nfse.{$extensao}";
        $caminho = (string) $nota->{$campo};

        if ($caminho === '' || ! hash_equals($caminhoEsperado, $caminho) || ! Storage::disk('local')->exists($caminho)) {
            throw new ArquivoFiscalIndisponivelException;
        }

        $identificador = Str::slug(str_replace(['/', '\\'], '-', (string) ($nota->numero ?: $nota->id)));

        return [
            'caminho' => Storage::disk('local')->path($caminho),
            'nome' => "NFS-e-{$identificador}.{$extensao}",
            'mime' => $extensao === 'pdf' ? 'application/pdf' : 'application/xml',
        ];
    }
}
