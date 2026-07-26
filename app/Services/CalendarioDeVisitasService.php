<?php

namespace App\Services;

use App\Models\Contract;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Calcula as datas previstas de visita de um contrato periódico a partir da
 * periodicidade preenchida pela Task 9.2 (visit_frequency_valor +
 * visit_frequency_unidade).
 *
 * Serviço de cálculo puro: não lê nada além do Contract recebido por
 * parâmetro e não grava nada. A materialização das visitas como Ordem de
 * Serviço é responsabilidade da Task 9.4.
 *
 * Toda data é tratada como dia sem hora no fuso do negócio (BusinessDate),
 * nunca como instante em UTC: start_date e end_date são campos `date`, e
 * comparar sem passar por BusinessDate é o que produz o erro de um dia
 * descrito na skill de datas e timezone do projeto.
 */
class CalendarioDeVisitasService
{
    /**
     * Tipo de serviço que não gera calendário de visitas.
     */
    private const TIPO_PONTUAL = 'pontual';

    /**
     * Únicas unidades gravadas pelo backfill da Task 9.2. Qualquer outro
     * valor em visit_frequency_unidade é tratado como contrato não
     * configurado, nunca como um chute de unidade.
     */
    private const UNIDADES_VALIDAS = ['dias', 'semanas', 'meses'];

    /**
     * Rede de segurança contra loop infinito quando o método é chamado sem
     * `$ate` e o contrato não tem `end_date`. Nenhuma chamada esperada do
     * sistema cai neste caso (a Task 9.4 sempre informa uma janela, e
     * contrato ativo tende a ter vigência), mas o cálculo não pode travar o
     * processo se cair.
     */
    private const LIMITE_ANOS_SEM_VIGENCIA = 10;

    /**
     * Lista as datas previstas de visita do contrato, a partir de
     * `start_date`, avançando pela periodicidade configurada, até
     * `end_date` do contrato ou até `$ate`, o que vier primeiro.
     *
     * `start_date` é sempre a primeira visita (número 1). Contrato pontual
     * ou sem periodicidade reconhecida devolve array vazio, sem lançar
     * exceção: é a pendência do backfill, não um erro de cálculo.
     *
     * A visita de número N é sempre `start_date` avançada em N-1 vezes a
     * periodicidade, calculada direto a partir de `start_date` (ancorada),
     * nunca a partir da visita anterior (em cascata). Ver o porquê no
     * método `avancar`.
     *
     * @param  string|null  $ate  Data limite no formato aceito por
     *                            BusinessDate (ex.: 'Y-m-d'). Quando
     *                            informada junto com `end_date`, prevalece a
     *                            que for mais cedo.
     * @return array<int, array{numero: int, data: string}>
     */
    public function datasPrevistas(Contract $contrato, ?string $ate = null): array
    {
        if (! $this->periodicidadeValida($contrato)) {
            return [];
        }

        $inicio = BusinessDate::paraFusoNegocio($contrato->start_date);

        if ($inicio === null) {
            return [];
        }

        $limite = $this->calcularLimite($contrato, $ate)
            ?? $inicio->addYears(self::LIMITE_ANOS_SEM_VIGENCIA);

        $valor = (int) $contrato->visit_frequency_valor;
        $unidade = $contrato->visit_frequency_unidade;

        $datas = [];
        $numero = 1;

        while (true) {
            // Ancorado em $inicio: a visita N é sempre start_date + (N-1)
            // periodicidades, nunca a visita N-1 + 1 periodicidade.
            $dataVisita = $this->avancar($inicio, $valor * ($numero - 1), $unidade);

            if ($dataVisita->greaterThan($limite)) {
                break;
            }

            $datas[] = ['numero' => $numero, 'data' => $dataVisita->toDateString()];
            $numero++;
        }

        return $datas;
    }

    /**
     * Datas previstas que ainda estão por vir, dentro de uma janela de
     * `$meses` a partir de hoje (fuso do negócio). Data de hoje conta como
     * futura: a visita marcada para hoje ainda não aconteceu.
     *
     * @return array<int, array{numero: int, data: string}>
     */
    public function proximasDatas(Contract $contrato, int $meses = 6): array
    {
        $hoje = BusinessDate::hoje();
        $limiteJanela = $hoje->addMonthsNoOverflow($meses)->toDateString();

        return array_values(array_filter(
            $this->datasPrevistas($contrato, $limiteJanela),
            fn (array $visita) => ! BusinessDate::paraFusoNegocio($visita['data'])->lessThan($hoje)
        ));
    }

    /**
     * Data da visita de número `$numero`, para uso na renumeração (Task
     * 9.5). Reaproveita `datasPrevistas` para garantir que a data devolvida
     * aqui seja idêntica à que aparece na lista completa: os dois métodos
     * nunca podem divergir sobre a data de uma mesma visita.
     *
     * Devolve null quando o número é menor que 1, quando o contrato não tem
     * periodicidade válida ou quando a visita cairia além da vigência
     * (`end_date`).
     */
    public function dataDaVisita(Contract $contrato, int $numero): ?string
    {
        if ($numero < 1) {
            return null;
        }

        $datas = $this->datasPrevistas($contrato);

        return $datas[$numero - 1]['data'] ?? null;
    }

    /**
     * Verdadeiro quando o contrato tem tudo que o cálculo precisa: não é
     * pontual, tem valor e unidade preenchidos pelo backfill, valor maior
     * que zero e unidade dentre as três reconhecidas.
     */
    private function periodicidadeValida(Contract $contrato): bool
    {
        if ($contrato->service_type === self::TIPO_PONTUAL) {
            return false;
        }

        if (is_null($contrato->visit_frequency_valor) || is_null($contrato->visit_frequency_unidade)) {
            return false;
        }

        if ($contrato->visit_frequency_valor < 1) {
            return false;
        }

        return in_array($contrato->visit_frequency_unidade, self::UNIDADES_VALIDAS, true);
    }

    /**
     * Menor data entre `end_date` do contrato e `$ate`, quando as duas
     * existem. Devolve a que existir quando só uma foi informada, e null
     * quando nenhuma foi.
     */
    private function calcularLimite(Contract $contrato, ?string $ate): ?CarbonImmutable
    {
        $limiteContrato = BusinessDate::paraFusoNegocio($contrato->end_date);
        $limiteInformado = BusinessDate::paraFusoNegocio($ate);

        if ($limiteContrato === null) {
            return $limiteInformado;
        }

        if ($limiteInformado === null) {
            return $limiteContrato;
        }

        return $limiteContrato->lessThan($limiteInformado) ? $limiteContrato : $limiteInformado;
    }

    /**
     * Avança uma data por um múltiplo da periodicidade informada. Recebe
     * sempre `$inicio` (a data base do contrato) e o offset já multiplicado
     * pelo número de passos (`valor × (numero - 1)`), nunca a data da visita
     * anterior: o cálculo é ancorado em `start_date`, não em cascata.
     *
     * `dias` e `semanas` são soma simples, e dão o mesmo resultado ancorado
     * ou em cascata. `meses` usa `addMonthsNoOverflow` obrigatoriamente: a
     * soma de mês padrão do Carbon, em dia 29, 30 ou 31, transborda para o
     * mês seguinte (31/01 + 1 mês = 03/03, porque fevereiro não tem dia 31).
     * Com addMonthsNoOverflow, 31/01 + 1 mês cai em 28/02 (ou 29/02 em ano
     * bissexto).
     *
     * A ancoragem em `$inicio` importa especificamente para `meses`: se o
     * cálculo fosse em cascata (cada visita a partir da anterior já
     * ajustada), um único mês curto travaria o contrato inteiro no dia
     * reduzido pelo resto da vigência (31/01 -> 28/02 -> 28/03 -> 28/04...,
     * quando o cliente contratou dia 31). Ancorado em `start_date`, o desvio
     * existe só no mês que não comporta o dia e se corrige sozinho no mês
     * seguinte (31/01 -> 28/02 -> 31/03 -> 30/04...).
     */
    private function avancar(CarbonImmutable $inicio, int $valor, string $unidade): CarbonImmutable
    {
        return match ($unidade) {
            'dias' => $inicio->addDays($valor),
            'semanas' => $inicio->addWeeks($valor),
            'meses' => $inicio->addMonthsNoOverflow($valor),
            default => throw new InvalidArgumentException("Unidade de periodicidade não suportada: {$unidade}"),
        };
    }
}
