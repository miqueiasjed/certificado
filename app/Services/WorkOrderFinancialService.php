<?php

namespace App\Services;

use App\Models\Receivable;
use App\Models\WorkOrder;

/**
 * Regra financeira que depende da própria ordem de serviço, e não só da
 * mecânica de título e parcela (essa fica em `ReceivableService`).
 *
 * Hoje resolve uma única coisa: transformar a condição de pagamento
 * escolhida no fechamento da OS em título a receber, delegando a mecânica de
 * parcelamento e arredondamento para `ReceivableService::gerarDaOs()`. A
 * Task 18.6 (aging, previsão de caixa e margem por OS) estende este service
 * com o custo e a margem da ordem, que também dependem de campos próprios
 * dela (produtos aplicados, técnico, tempo gasto).
 *
 * Quem chama este método a partir do fechamento da OS pela tela (o endpoint
 * de `WorkOrderFinancialController`) é a Task 18.7, que troca a leitura dos
 * painéis financeiros: esta task só entrega a geração em si, pronta para ser
 * acionada.
 */
class WorkOrderFinancialService
{
    public function __construct(private readonly ReceivableService $receivableService) {}

    /**
     * Gera o título a receber da OS a partir da condição de pagamento
     * escolhida no fechamento (à vista ou parcelado, com o vencimento e o
     * intervalo entre parcelas).
     *
     * Idempotente por herança de `ReceivableService::gerarDaOs()`: chamar de
     * novo para a mesma OS devolve o título já existente, sem gerar outro.
     *
     * @param  array<string, mixed>  $condicoesDePagamento
     */
    public function gerarTituloDeRecebimento(WorkOrder $os, array $condicoesDePagamento): Receivable
    {
        return $this->receivableService->gerarDaOs($os, $condicoesDePagamento);
    }
}
