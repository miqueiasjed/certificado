<?php

namespace App\Support\Fiscal;

use App\Models\ServiceInvoice;

final class NotaFiscalPublica
{
    /** @return array<string, mixed> */
    public static function de(ServiceInvoice $nota): array
    {
        $nota->loadMissing(['client', 'substituidaPor', 'notaSubstituida', 'reprocessadaPor', 'pendenciasReprocessadas']);

        return [
            'id' => (int) $nota->id,
            'client_id' => (int) $nota->client_id,
            'cliente' => $nota->client?->name,
            'work_order_id' => $nota->work_order_id === null ? null : (int) $nota->work_order_id,
            'receivable_id' => $nota->receivable_id === null ? null : (int) $nota->receivable_id,
            'numero' => $nota->numero,
            'codigo_verificacao' => $nota->codigo_verificacao,
            'situacao' => $nota->situacao,
            'cancelamento_pendente' => $nota->situacao === 'cancelamento_pendente',
            'valor_servico' => $nota->valor_servico,
            'valor_iss' => $nota->valor_iss,
            'valor_liquido' => $nota->valor_liquido,
            'descricao_servico' => $nota->descricao_servico,
            'competencia' => $nota->competencia?->toDateString(),
            'emitida_em' => $nota->emitida_em?->toIso8601String(),
            'cancelada_em' => $nota->cancelada_em?->toIso8601String(),
            'motivo_cancelamento' => $nota->motivo_cancelamento,
            'motivo_substituicao' => $nota->motivo_substituicao,
            'erro_mensagem' => MensagemFiscalPublica::deTextoPersistido($nota->erro_mensagem),
            'tentativas' => (int) $nota->tentativas,
            'arquivos' => [
                'pdf_disponivel' => filled($nota->pdf_path),
                'xml_disponivel' => filled($nota->xml_path),
            ],
            'cadeia' => [
                'substitui' => $nota->notaSubstituida?->id,
                'substituida_por' => $nota->substituidaPor?->id,
                'reprocessada_por' => $nota->reprocessadaPor?->id,
                'reprocessa' => $nota->pendenciasReprocessadas->pluck('id')->map(fn ($id): int => (int) $id)->values()->all(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    public static function paraPortal(ServiceInvoice $nota): array
    {
        $nota->loadMissing(['substituidaPor', 'notaSubstituida']);

        return [
            'id' => (int) $nota->id,
            'numero' => $nota->numero,
            'codigo_verificacao' => $nota->codigo_verificacao,
            'situacao' => $nota->situacao,
            'cancelamento_pendente' => $nota->situacao === 'cancelamento_pendente',
            'valor_servico' => $nota->valor_servico,
            'valor_iss' => $nota->valor_iss,
            'valor_liquido' => $nota->valor_liquido,
            'descricao_servico' => $nota->descricao_servico,
            'competencia' => $nota->competencia?->toDateString(),
            'emitida_em' => $nota->emitida_em?->toIso8601String(),
            'cancelada_em' => $nota->cancelada_em?->toIso8601String(),
            'motivo_cancelamento' => $nota->motivo_cancelamento,
            'motivo_substituicao' => $nota->motivo_substituicao,
            'arquivos' => [
                'pdf_disponivel' => filled($nota->pdf_path),
                'xml_disponivel' => filled($nota->xml_path),
            ],
            'cadeia' => [
                'substitui' => $nota->notaSubstituida?->id,
                'substituida_por' => $nota->substituidaPor?->id,
            ],
        ];
    }
}
