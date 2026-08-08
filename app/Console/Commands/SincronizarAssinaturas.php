<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Exceptions\ProvedorDeAssinaturaNaoConfiguradoException;
use App\Models\Company;
use App\Models\SignatureRequest;
use App\Services\Signature\ResolvedorDeProvedor;
use App\Services\SignatureRequestService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Sincronização periódica dos pedidos de assinatura em aberto (Plano 26,
 * Task 26.3), de 6 em 6 horas.
 *
 * ## Por que esta rotina existe mesmo havendo webhook
 *
 * Webhook se perde: o provedor tenta entregar enquanto o servidor está em
 * deploy, a rede engasga, um evento cai. Sem esta rede de segurança, o
 * contrato correspondente fica preso em "em assinatura" para sempre — e o pior
 * é que ninguém recebe erro para perceber, porque do ponto de vista do sistema
 * simplesmente não aconteceu nada. Ela também é o que fecha o caso de um
 * evento que o webhook recebeu mas não conseguiu processar (o processador
 * grava o erro e devolve 200 de propósito).
 *
 * Ela faz duas coisas em cada empresa, nesta ordem:
 *
 * 1. Consulta no provedor cada pedido ainda em aberto e aplica a situação.
 * 2. Para os que continuarem em aberto depois disso, avisa a empresa quando o
 *    pedido já passou de cinco dias sem conclusão — uma vez por semana, não
 *    uma vez por passada.
 *
 * Um pedido que falha (provedor fora do ar, credencial revogada) não derruba
 * os demais: o erro é registrado e o laço segue, mesmo critério de
 * `OperaPorTenant` entre empresas.
 */
class SincronizarAssinaturas extends Command
{
    use OperaPorTenant;

    protected $signature = 'assinaturas:sincronizar';

    protected $description = 'Consulta no provedor os pedidos de assinatura em aberto e avisa os pendentes há dias';

    public function __construct(
        private readonly SignatureRequestService $pedidos,
        private readonly ResolvedorDeProvedor $resolvedorDeProvedor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        return $this->paraCadaTenant(function (Company $empresa): int {
            $emAberto = SignatureRequest::query()
                ->whereIn('situacao', SignatureRequest::SITUACOES_EM_ABERTO)
                ->whereNotNull('provedor_documento_id')
                ->orderBy('id')
                ->get();

            if ($emAberto->isEmpty()) {
                $this->line("Nenhum pedido de assinatura em aberto na empresa #{$empresa->id}.");

                return 0;
            }

            try {
                $configuracao = $this->resolvedorDeProvedor->configuracaoAtiva($empresa);
            } catch (ProvedorDeAssinaturaNaoConfiguradoException $excecao) {
                // A empresa tem pedido em aberto e desligou a integração. Não
                // é erro do laço: sem credencial não há como consultar, e
                // derrubar a rotina inteira por isso deixaria as demais
                // empresas sem sincronização.
                $this->warn("Empresa #{$empresa->id}: {$excecao->getMessage()}");

                return 0;
            }

            $sincronizados = 0;
            $avisados = 0;

            foreach ($emAberto as $pedido) {
                try {
                    $atualizado = $this->pedidos->sincronizar($pedido, $configuracao);
                    $sincronizados++;

                    if ($atualizado->estaEmAberto() && $this->pedidos->avisarPendencia($atualizado)) {
                        $avisados++;
                    }
                } catch (Throwable $erro) {
                    // Um pedido problemático não pode impedir os outros de
                    // serem sincronizados; a próxima passada tenta de novo.
                    report($erro);

                    $this->error("Pedido #{$pedido->id}: {$erro->getMessage()}");
                }
            }

            $this->line(
                "Empresa #{$empresa->id}: {$sincronizados} pedido(s) sincronizado(s), "
                ."{$avisados} aviso(s) de pendência."
            );

            return $sincronizados;
        });
    }
}
