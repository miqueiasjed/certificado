<?php

namespace App\Services\Payments;

use App\Models\Charge;
use App\Models\Client;
use App\Models\CompanyBillingSetting;
use App\Models\ReceivableInstallment;
use App\Services\NotificationService;
use App\Support\BusinessDate;
use App\Support\Dinheiro;
use App\Support\EventosDeNotificacao;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Régua de cobrança de parcela de contrato (Plano 19, Task 19.5): avisa o
 * cliente antes, no dia e depois do vencimento da cobrança ativa de uma
 * parcela, cinco marcos ao todo, sem nunca repetir um marco já enfileirado
 * e sem nunca avisar quem já pagou.
 *
 * ## Cinco marcos, três eventos
 *
 * A especificação fala em "três momentos, cada um com evento próprio e
 * template editável" e em "cinco marcos". As duas contagens convivem porque
 * o momento **depois** do vencimento reúne três marcos (3, 7 e 15 dias) sob
 * um único evento (`EventosDeNotificacao::COBRANCA_EM_ATRASO`), o mesmo
 * critério já usado por `CERTIFICADO_A_VENCER` (Task 14.4) para os marcos de
 * 30/15/7 dias: um evento, um template editável pelo tenant, e a opção
 * `marco` de `NotificationService::enfileirar()` a diferenciar as chamadas
 * na chave de idempotência. Ver `EVENTOS_POR_MARCO`.
 *
 * ## Sem cobrança ativa, sem aviso
 *
 * A régua só fala de uma cobrança que existe: sem `Charge` ativa (`pendente`,
 * `emitida` ou `vencida`, mesmo critério de
 * `ChargeService::SITUACOES_ATIVAS`) vinculada à parcela, o marco é pulado
 * em silêncio (registrado no resumo como `sem_cobranca`). Mandar um lembrete
 * de vencimento sem link de pagamento nenhum, porque a emissão recorrente
 * (`App\Console\Commands\EmitirCobrancasRecorrentes`) ainda não rodou ou
 * falhou, confundiria o cliente mais do que ajudaria.
 *
 * ## A régua nunca avisa quem já pagou
 *
 * Regra inegociável do plano. Duas checagens independentes:
 *
 * 1. **No enfileiramento** (aqui): a consulta de `parcelasNoMarco()` já
 *    exclui parcela `paga`/`cancelada`, e `enfileirarMarco()` recarrega a
 *    parcela do banco antes de decidir, porque uma execução que percorre
 *    muitas empresas e milhares de parcelas pode levar minutos, e uma
 *    parcela paga entre o início da consulta e este ponto não pode escapar
 *    pela foto antiga carregada em memória.
 * 2. **No envio**, dias ou minutos depois (a fila pode não ser despachada na
 *    hora, e um item de WhatsApp fica pendente até alguém clicar o link
 *    manualmente): `App\Services\Notification\DriverDeEmail` confere de novo
 *    a situação da parcela, pelo `receivable_installment_id` gravado no
 *    `contexto` do item (ver `enfileirarMarco()` abaixo), e recusa o envio se
 *    ela já estiver paga ou cancelada. Cobrar quem já pagou é o erro mais
 *    caro deste fluxo, e por isso a checagem não confia só no que valia no
 *    instante do enfileiramento.
 *
 * ## Configuração por tenant
 *
 * `CompanyBillingSetting::atual()` decide se a régua está ligada, quais
 * marcos estão ativos e (para `EmitirCobrancasRecorrentes`) a antecedência da
 * emissão. Tenant sem linha configurada usa os padrões do sistema: régua
 * ligada, todos os marcos ativos.
 *
 * ## Escopo por empresa
 *
 * Diferente de `ResolvedorDeGateway`/`ChargeService::emitir()` em lote, este
 * Service não recebe `Company` por parâmetro: opera sempre dentro do tenant
 * corrente, mesmo critério de `NotificationService` e de
 * `EnfileirarAvisosDiarios`. Quem percorre os tenants é o comando
 * `cobrancas:regua`, com `OperaPorTenant`.
 */
class ReguaDeCobrancaService
{
    /** 3 dias antes do vencimento, com o link de pagamento. */
    public const MARCO_ANTES_3 = 'antes_3';

    /** No dia do vencimento. */
    public const MARCO_NO_DIA = 'no_dia';

    /** 3 dias depois do vencimento. */
    public const MARCO_DEPOIS_3 = 'depois_3';

    /** 7 dias depois do vencimento. */
    public const MARCO_DEPOIS_7 = 'depois_7';

    /** 15 dias depois do vencimento. */
    public const MARCO_DEPOIS_15 = 'depois_15';

    /**
     * Situações de parcela que interrompem a régua: parcela paga ou
     * cancelada não recebe mais nenhum marco.
     *
     * @var array<int, string>
     */
    public const SITUACOES_QUE_PARAM_A_REGUA = ['paga', 'cancelada'];

    /**
     * Situações de `Charge` que contam como cobrança ativa. Cópia
     * deliberada de `ChargeService::SITUACOES_ATIVAS` (privada lá): as duas
     * listas precisam continuar iguais, porque a régua só pode falar de uma
     * cobrança que `ChargeService::emitir()` ainda reconheceria como ativa.
     *
     * @var array<int, string>
     */
    public const SITUACOES_DE_COBRANCA_ATIVA = ['pendente', 'emitida', 'vencida'];

    /**
     * Marco => deslocamento em dias a partir do vencimento da parcela.
     * Negativo é antes, zero é o próprio dia, positivo é depois.
     *
     * @var array<string, int>
     */
    private const OFFSETS_POR_MARCO = [
        self::MARCO_ANTES_3 => -3,
        self::MARCO_NO_DIA => 0,
        self::MARCO_DEPOIS_3 => 3,
        self::MARCO_DEPOIS_7 => 7,
        self::MARCO_DEPOIS_15 => 15,
    ];

    /**
     * Marco => evento do catálogo. Os três marcos "depois" compartilham o
     * mesmo evento; ver o cabeçalho da classe.
     *
     * @var array<string, string>
     */
    private const EVENTOS_POR_MARCO = [
        self::MARCO_ANTES_3 => EventosDeNotificacao::COBRANCA_A_VENCER,
        self::MARCO_NO_DIA => EventosDeNotificacao::COBRANCA_VENCE_HOJE,
        self::MARCO_DEPOIS_3 => EventosDeNotificacao::COBRANCA_EM_ATRASO,
        self::MARCO_DEPOIS_7 => EventosDeNotificacao::COBRANCA_EM_ATRASO,
        self::MARCO_DEPOIS_15 => EventosDeNotificacao::COBRANCA_EM_ATRASO,
    ];

    public function __construct(private readonly NotificationService $notificationService) {}

    /**
     * Uma passada da régua, no tenant corrente: um marco por vez, cada um
     * só com os que estão ativos na configuração do tenant.
     *
     * @return array<int, array{receivable_installment_id: int, marco: string, resultado: string, mensagem: string}>
     */
    public function executar(): array
    {
        $configuracao = CompanyBillingSetting::atual();

        if (! $configuracao->reguaAtiva()) {
            return [];
        }

        $marcosAtivos = $configuracao->marcosAtivos() ?? array_keys(self::OFFSETS_POR_MARCO);
        $hoje = BusinessDate::hoje();
        $resultados = [];

        foreach (self::OFFSETS_POR_MARCO as $marco => $deslocamento) {
            if (! in_array($marco, $marcosAtivos, true)) {
                continue;
            }

            $vencimentoAlvo = $hoje->subDays($deslocamento)->toDateString();

            foreach ($this->parcelasNoMarco($vencimentoAlvo) as $parcela) {
                $resultados[] = $this->processarParcelaIsolando($parcela, $marco);
            }
        }

        return $resultados;
    }

    /**
     * Marcos válidos do catálogo, na ordem em que a régua os avalia. Usado
     * pela tela de configuração (Task 19.7) para montar a lista de opções.
     *
     * @return array<int, string>
     */
    public static function marcos(): array
    {
        return array_keys(self::OFFSETS_POR_MARCO);
    }

    // -----------------------------------------------------------------
    // Uma volta do laço: uma parcela, em um marco
    // -----------------------------------------------------------------

    /**
     * Parcelas de contrato que vencem exatamente no dia do marco, ainda
     * cobráveis, com a cobrança ativa (se houver) já carregada.
     *
     * "De contrato" é `receivable.contract_id` não nulo: título avulso (de
     * OS ou lançado à mão) não entra na régua desta task, que é sobre a
     * mensalidade do contrato.
     */
    private function parcelasNoMarco(string $vencimento): Collection
    {
        return ReceivableInstallment::query()
            ->whereDate('vencimento', $vencimento)
            ->whereNotIn('situacao', self::SITUACOES_QUE_PARAM_A_REGUA)
            ->whereHas('receivable', fn ($consulta) => $consulta->whereNotNull('contract_id'))
            ->with([
                'receivable.client',
                'charges' => fn ($consulta) => $consulta
                    ->whereIn('situacao', self::SITUACOES_DE_COBRANCA_ATIVA)
                    ->latest('id'),
            ])
            ->orderBy('id')
            ->get();
    }

    /**
     * Uma parcela do laço de `executar()`, com o erro dela contido: parcela
     * com dado inconsistente não pode impedir o aviso das demais.
     *
     * @return array{receivable_installment_id: int, marco: string, resultado: string, mensagem: string}
     */
    private function processarParcelaIsolando(ReceivableInstallment $parcela, string $marco): array
    {
        try {
            return $this->enfileirarMarco($parcela, $marco);
        } catch (Throwable $erro) {
            report($erro);

            Log::error('Falha ao enfileirar aviso da régua de cobrança.', [
                'receivable_installment_id' => $parcela->getKey(),
                'marco' => $marco,
                'mensagem' => $erro->getMessage(),
            ]);

            return $this->linhaDoResumo($parcela, $marco, 'erro', $erro->getMessage());
        }
    }

    /**
     * A decisão em si: confere de novo a situação da parcela (checagem
     * dupla de enfileiramento, ver o cabeçalho da classe), localiza a
     * cobrança ativa e enfileira pela central do Plano 14, com `marco` na
     * chave de idempotência e o id da parcela no `contexto`, para a segunda
     * checagem (no envio) conseguir recarregá-la.
     *
     * @return array{receivable_installment_id: int, marco: string, resultado: string, mensagem: string}
     */
    private function enfileirarMarco(ReceivableInstallment $parcela, string $marco): array
    {
        $parcela = $parcela->fresh() ?? $parcela;

        if (in_array($parcela->situacao, self::SITUACOES_QUE_PARAM_A_REGUA, true)) {
            return $this->linhaDoResumo(
                $parcela,
                $marco,
                'parada',
                'A parcela foi paga ou cancelada entre a consulta e o enfileiramento: régua interrompida.'
            );
        }

        $cliente = $parcela->receivable?->client;

        if (! $cliente instanceof Client) {
            return $this->linhaDoResumo($parcela, $marco, 'sem_cliente', 'Parcela sem cliente vinculado.');
        }

        $cobranca = $this->cobrancaAtivaDaParcela($parcela);

        if ($cobranca === null) {
            return $this->linhaDoResumo(
                $parcela,
                $marco,
                'sem_cobranca',
                'Nenhuma cobrança ativa para esta parcela: a régua não avisa sem cobrança emitida.'
            );
        }

        $deslocamento = self::OFFSETS_POR_MARCO[$marco];

        $resultado = $this->notificationService->enfileirar(
            self::EVENTOS_POR_MARCO[$marco],
            $cobranca,
            [
                'destinatario' => $cliente,
                'marco' => $marco,
                'contexto' => ['receivable_installment_id' => $parcela->getKey()],
                'variaveis' => [
                    'tipo_cobranca' => $cobranca->tipo === 'pix' ? 'Pix' : 'Boleto',
                    'valor' => Dinheiro::formatar(Dinheiro::centavos($cobranca->valor)),
                    'data_vencimento' => $parcela->vencimento,
                    'dias_para_vencer' => $deslocamento < 0 ? abs($deslocamento) : 0,
                    'dias_em_atraso' => $deslocamento > 0 ? $deslocamento : 0,
                    'link_pagamento' => $cobranca->link_pagamento ?? '',
                    'linha_digitavel' => $cobranca->linha_digitavel ?? '',
                    'qr_code_pix' => $cobranca->qr_code_pix ?? '',
                ],
            ]
        );

        return $this->linhaDoResumo($parcela, $marco, $resultado['resultado'], $resultado['mensagem']);
    }

    /**
     * Cobrança ativa da parcela, a mesma que `ChargeService::emitir()`
     * reconheceria como impedimento para uma nova emissão. Usa o eager load
     * de `parcelasNoMarco()` quando disponível; sem ele (parcela recarregada
     * por `fresh()`, que não repete relação alguma), consulta direto.
     */
    private function cobrancaAtivaDaParcela(ReceivableInstallment $parcela): ?Charge
    {
        if ($parcela->relationLoaded('charges')) {
            return $parcela->charges->first();
        }

        return $parcela->charges()
            ->whereIn('situacao', self::SITUACOES_DE_COBRANCA_ATIVA)
            ->latest('id')
            ->first();
    }

    /**
     * @return array{receivable_installment_id: int, marco: string, resultado: string, mensagem: string}
     */
    private function linhaDoResumo(ReceivableInstallment $parcela, string $marco, string $resultado, string $mensagem): array
    {
        return [
            'receivable_installment_id' => $parcela->getKey(),
            'marco' => $marco,
            'resultado' => $resultado,
            'mensagem' => $mensagem,
        ];
    }
}
