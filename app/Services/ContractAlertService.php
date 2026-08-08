<?php

namespace App\Services;

use App\Models\CompanyContractAlertSetting;
use App\Models\Contract;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Leituras que sustentam os alertas de contrato a vencer e a pendência de
 * contrato vencido sem tratativa (Plano 23, Task 23.5): "o que avisar", nunca
 * "avisar". Quem enfileira o aviso pela central de notificações do Plano 14,
 * decide o marco, aplica a pausa de `em_negociacao` e marca `pendente` na
 * primeira entrada na janela é `App\Console\Commands\VerificarContratosAVencer`;
 * este Service só devolve os dados, mesmo critério de `StockAlertService`
 * (Plano 17).
 *
 * As duas consultas abaixo são reaproveitadas pela rotina e, futuramente,
 * pelo painel de contratos a vencer (Task 23.8): o dia em que o painel e o
 * e-mail divergissem sobre quais contratos precisam de atenção seria pior do
 * que não ter painel nenhum.
 *
 * Achado desta sessão (Task 23.4/23.5): renovar um contrato **não** apaga o
 * anterior. `ContractRenewalService::renovar()` preserva histórico
 * marcando o antigo como `renovado` e criando uma linha nova em `contracts`;
 * o antigo continua com `end_date` no passado (ou próximo do fim) e, sem
 * filtro, voltaria a aparecer para sempre como "a vencer" ou "vencido sem
 * tratativa". As duas consultas por isso excluem explicitamente
 * `situacao_renovacao` igual a `renovado` ou a `nao_renovado`: são as duas
 * únicas situações com decisão final tomada. `null` (nunca teve tratativa),
 * `pendente` (a própria rotina de alerta atribuiu) e `em_negociacao` (adia,
 * não encerra) continuam precisando de atenção.
 */
class ContractAlertService
{
    /**
     * Situações de renovação com decisão final tomada: fora do radar de
     * alerta em definitivo. Ver o docblock da classe para o motivo.
     *
     * @var array<int, string>
     */
    private const SITUACOES_ENCERRADAS = ['renovado', 'nao_renovado'];

    /**
     * Contratos vigentes a exatamente `$dias` do fim, sem decisão de
     * renovação encerrada.
     *
     * O corte é a igualdade com o dia exato, nunca um intervalo. Com
     * intervalo o contrato apareceria em toda execução entre o marco mais
     * distante e o fim da vigência, e só a chave de idempotência escondia a
     * repetição atrás de um efeito colateral. Mesmo critério de
     * `EnfileirarAvisosDiarios::certificadosAVencer()`.
     *
     * @return Collection<int, Contract>
     */
    public function aVencer(int $dias): Collection
    {
        $vencimento = BusinessDate::hoje()->addDays($dias)->toDateString();

        return Contract::query()
            ->whereDate('end_date', $vencimento)
            ->where($this->semSituacaoEncerrada(...))
            ->with('address.client')
            ->orderBy('id')
            ->get();
    }

    /**
     * Contratos vencidos (fim no passado) sem decisão de renovação
     * encerrada: a pendência que o Plano 23 corrige. Contrato que vence e
     * some da tela é perda de receita recorrente silenciosa.
     *
     * Inclui `null` (nunca teve tratativa), `pendente` e `em_negociacao`:
     * os três estados que ainda esperam uma decisão final. `em_negociacao`
     * continua na lista de propósito: o painel (Task 23.8) precisa mostrar a
     * negociação em andamento, mesmo quando `VerificarContratosAVencer` pausa
     * o e-mail semanal por 30 dias enquanto ela dura.
     *
     * @return Collection<int, Contract>
     */
    public function vencidosSemTratativa(): Collection
    {
        return Contract::query()
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', BusinessDate::hoje()->toDateString())
            ->where($this->semSituacaoEncerrada(...))
            ->with('address.client')
            ->orderBy('end_date')
            ->get();
    }

    /**
     * Restringe a consulta às situações que ainda pedem atenção: `null`
     * (nunca teve tratativa), `pendente` e `em_negociacao`. Nunca
     * `whereNotIn('situacao_renovacao', self::SITUACOES_ENCERRADAS)` sozinho
     * aqui: em SQL, `coluna NOT IN (...)` avalia para `NULL` (nem verdadeiro
     * nem falso) quando `coluna IS NULL`, e uma cláusula `WHERE` descarta
     * linha com `NULL`, o que excluiria silenciosamente todo contrato que
     * nunca teve tratativa nenhuma, exatamente o caso mais comum das duas
     * consultas.
     */
    private function semSituacaoEncerrada(Builder $consulta): void
    {
        $consulta->whereNull('situacao_renovacao')
            ->orWhereNotIn('situacao_renovacao', self::SITUACOES_ENCERRADAS);
    }

    /**
     * Marcos de antecedência (em dias) do tenant corrente: os configurados
     * em `CompanyContractAlertSetting`, ou os padrão do sistema (60, 30 e 15)
     * quando a empresa não personalizou nada.
     *
     * @return array<int, int>
     */
    public function marcos(): array
    {
        return CompanyContractAlertSetting::atual()->marcos();
    }
}
