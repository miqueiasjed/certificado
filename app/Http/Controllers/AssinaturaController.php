<?php

namespace App\Http\Controllers;

use App\Exceptions\AssinaturaInvalidaException;
use App\Exceptions\GatewayIndisponivelException;
use App\Exceptions\GatewayRecusouException;
use App\Exceptions\TenantInternoException;
use App\Http\Requests\TrocarFormaDePagamentoRequest;
use App\Models\Company;
use App\Models\Invoice;
use App\Services\InvoiceService;
use App\Services\SubscriptionService;
use App\Support\BusinessDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Response;

/**
 * Tela de assinatura do tenant (Plano 7, Task 7.8): o próprio plano, a
 * situação da cobrança e as faturas dos últimos 12 meses, com o caminho para
 * trocar a forma de pagamento.
 *
 * Fica dentro do grupo `Route::middleware(['auth', 'tenant.ativo'])`, e não
 * fora dele: `EnsureTenantIsActive::ROTAS_LIBERADAS` já libera toda rota
 * nomeada `assinatura.faturas*` (Task 7.7), então o tenant suspenso continua
 * vendo a própria fatura em aberto e o link de pagamento sem precisar de um
 * grupo à parte. É por isso que as três rotas deste controller nascem com
 * esse prefixo no nome (ver routes/web.php).
 *
 * `show()` não exige `assinatura-gerenciar`: qualquer usuário autenticado da
 * empresa pode ver o próprio plano e as próprias faturas, do mesmo jeito que
 * qualquer um vê `/conta-suspensa`. Só `trocarFormaDePagamento()` exige a
 * permissão, porque é a única ação de escrita deste controller.
 *
 * Toda consulta filtra `company_id` explicitamente, nunca pelo escopo global:
 * `Subscription` e `Invoice` ficam fora dele de propósito (Task 7.1), porque a
 * plataforma precisa ver e conciliar a assinatura e a fatura de todos os
 * tenants na mesma tela. Esquecer o filtro aqui mostraria a fatura de um
 * tenant para outro.
 */
class AssinaturaController extends Controller
{
    public function __construct(
        private readonly SubscriptionService $subscriptionService,
        private readonly InvoiceService $invoiceService,
    ) {}

    /**
     * Plano, situação da assinatura e faturas dos últimos 12 meses do tenant
     * corrente.
     *
     * Tenant interno: `situacaoDe()` já devolve `imune = true`, sem dado de
     * cobrança (ver o docblock do Service), e a lista de faturas sai vazia
     * aqui mesmo, sem consultar `invoices`, porque ele não tem fatura nenhuma
     * (ver o cabeçalho de `InvoiceService`). A mensagem exibida nos dois casos
     * é decisão da tela (Task 7.9), não deste controller.
     */
    public function show(): Response
    {
        $empresa = Company::current();

        return inertia('Assinatura/Show', [
            'situacao' => $this->subscriptionService->situacaoDe($empresa),
            'faturas' => $this->faturasDaEmpresa($empresa),
        ]);
    }

    /**
     * Detalhe de uma fatura específica do tenant corrente, para a tela pedir
     * a instrução de pagamento (link, linha digitável, QR Pix) sem recarregar
     * a lista inteira.
     *
     * `{invoice}` é o binding padrão de `App\Models\Invoice`, que não usa
     * `BelongsToCompany` (ver o cabeçalho do model) e por isso encontra
     * fatura de qualquer tenant. A checagem de `company_id` abaixo é o que
     * impede o tenant A de ver a fatura do tenant B só trocando o id na URL;
     * devolve 404, não 403, para não revelar que a fatura existe em outra
     * empresa (mesmo critério de vazamento de dado do resto do projeto).
     */
    public function faturaShow(Invoice $invoice): JsonResponse
    {
        $empresa = Company::current();

        if ((int) $invoice->company_id !== (int) $empresa->getKey()) {
            abort(404);
        }

        return response()->json($this->faturaEmArray($invoice));
    }

    /**
     * Troca a forma de pagamento da assinatura do tenant corrente.
     *
     * `TenantInternoException` e `AssinaturaInvalidaException` são recusa de
     * negócio (tenant interno sem assinatura, forma desconhecida, cartão
     * ausente) e viram mensagem clara na tela, sem 500, no mesmo padrão de
     * `Plataforma\TenantController::suspender()`. As duas exceções de gateway
     * seguem o mesmo caminho: o provedor recusou o cartão ou não respondeu, e
     * o tenant precisa saber que nada mudou, não ver uma tela de erro
     * genérica.
     */
    public function trocarFormaDePagamento(TrocarFormaDePagamentoRequest $request): RedirectResponse
    {
        $empresa = Company::current();
        $assinatura = $this->subscriptionService->assinaturaDe($empresa);

        if ($assinatura === null) {
            return back()->with('error', 'Esta empresa ainda não tem assinatura para alterar a forma de pagamento.');
        }

        try {
            $this->subscriptionService->trocarFormaDePagamento(
                $assinatura,
                (string) $request->validated('forma'),
                $request->validated('cartao_cifrado')
            );
        } catch (TenantInternoException|AssinaturaInvalidaException|GatewayRecusouException|GatewayIndisponivelException $excecao) {
            return back()->with('error', $excecao->getMessage());
        }

        return back()->with('success', 'Forma de pagamento atualizada com sucesso.');
    }

    /**
     * Faturas dos últimos 12 meses da empresa, no formato que a tela consome.
     *
     * Vazia para o tenant interno, sem consultar `invoices`: ele não é
     * cobrado por caminho nenhum (ver o cabeçalho de `InvoiceService`), e
     * mostrar uma lista vazia vinda do banco em vez de "não se aplica" seria
     * uma consulta gasta para o mesmo resultado.
     *
     * @return array<int, array{id: int, referencia: string, valor: float, situacao: string, vencimento: ?string, pago_em: ?string, link_pagamento: ?string, linha_digitavel: ?string, qr_code_pix: ?string}>
     */
    private function faturasDaEmpresa(Company $empresa): array
    {
        if ($empresa->is_internal) {
            return [];
        }

        return $this->invoiceService->historicoDe($empresa)
            ->map(fn (Invoice $fatura) => $this->faturaEmArray($fatura))
            ->values()
            ->all();
    }

    /**
     * Fatura no formato que a tela consome. `vencimento` e `pago_em` saem
     * como `Y-m-d` puro (ou nulo), para o frontend formatar com
     * `formatarData()`: campo `date` não é convertido de fuso, aqui nem lá.
     *
     * @return array{id: int, referencia: string, valor: float, situacao: string, vencimento: ?string, pago_em: ?string, link_pagamento: ?string, linha_digitavel: ?string, qr_code_pix: ?string}
     */
    private function faturaEmArray(Invoice $fatura): array
    {
        return [
            'id' => (int) $fatura->id,
            'referencia' => (string) $fatura->referencia,
            'valor' => (float) $fatura->valor,
            'situacao' => (string) $fatura->situacao,
            'vencimento' => BusinessDate::diaDe($fatura->vencimento),
            'pago_em' => BusinessDate::diaDe($fatura->pago_em),
            'link_pagamento' => $fatura->link_pagamento,
            'linha_digitavel' => $fatura->linha_digitavel,
            'qr_code_pix' => $fatura->qr_code_pix,
        ];
    }
}
