<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\Invoice;
use App\Services\InadimplenciaService;
use App\Services\TenantService;
use App\Support\BusinessDate;
use Inertia\Response;

/**
 * Página de conta suspensa (Plano 7, Task 7.7): o destino do redirecionamento de
 * `EnsureTenantIsActive` quando o tenant está com o acesso bloqueado por falta
 * de pagamento.
 *
 * A rota fica fora do middleware que redireciona para cá (ver
 * `EnsureTenantIsActive::ROTAS_LIBERADAS`), senão o redirecionamento apontaria
 * para si mesmo em laço.
 *
 * Abrir a página com a conta em dia não é erro: quem chegar aqui por link antigo
 * ou pelo histórico do navegador vê a mensagem de que está tudo certo, e não uma
 * tela de erro. É por isso que o componente recebe `suspensa` como prop, em vez
 * de assumir que só tenant bloqueado chega aqui.
 */
class ContaSuspensaController extends Controller
{
    public function show(InadimplenciaService $inadimplenciaService): Response
    {
        $empresa = Company::current();
        $fatura = $inadimplenciaService->faturaEmAbertoDe($empresa);

        return inertia('ContaSuspensa', [
            'empresa' => $empresa->name,
            'suspensa' => $empresa->situacao === TenantService::SITUACAO_SUSPENSA,
            'fatura' => $this->dadosDaFatura($fatura),
        ]);
    }

    /**
     * O que a tela precisa para o cliente conseguir pagar, e nada além disso.
     *
     * As três instruções de pagamento vão juntas porque a fatura só tem a da
     * forma dela: PIX preenche `qr_code_pix`, boleto preenche `linha_digitavel`,
     * cartão preenche `link_pagamento`. A tela mostra o que existir, e nenhuma
     * delas é obrigatória: fatura cuja emissão falhou no provedor fica sem as
     * três (ver `InvoiceService::registrarCobrancaNaoEmitida()`), e nesse caso o
     * cliente precisa falar com o suporte, que é o que a tela diz.
     *
     * `vencimento` sai como `Y-m-d` puro, para o frontend formatar com
     * `formatarData()`. Campo `date` não é convertido de fuso, aqui nem lá:
     * converter dia puro é o que produz o erro de um dia.
     *
     * `valor` vai como número, e a formatação em real acontece na tela.
     *
     * @return array{referencia: string, valor: float, vencimento: ?string, situacao: string, link_pagamento: ?string, linha_digitavel: ?string, qr_code_pix: ?string}|null
     */
    private function dadosDaFatura(?Invoice $fatura): ?array
    {
        if (! $fatura instanceof Invoice) {
            return null;
        }

        return [
            'referencia' => (string) $fatura->referencia,
            'valor' => (float) $fatura->valor,
            'vencimento' => BusinessDate::diaDe($fatura->vencimento),
            'situacao' => (string) $fatura->situacao,
            'link_pagamento' => $fatura->link_pagamento,
            'linha_digitavel' => $fatura->linha_digitavel,
            'qr_code_pix' => $fatura->qr_code_pix,
        ];
    }
}
