<?php

namespace App\Services\Ai;

use App\Exceptions\ParecerNaoRevisadoException;
use App\Exceptions\RascunhoJaExisteException;
use App\Models\AiDraft;
use App\Models\MonitoringReport;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Rascunho de parecer técnico e resumo de período (Plano 25, Task 25.3).
 *
 * ## O texto nasce rascunho
 *
 * `gerar*` sempre grava com `situacao = gerado`. Só uma pessoa move para
 * `revisado`, por `revisar()`, e é isso que a guarda de emissão confere. Não
 * existe caminho neste Service que produza um rascunho já revisado.
 *
 * ## `conteudo_gerado` nunca é sobrescrito
 *
 * A revisão grava em `conteudo_revisado`. Comparar as duas colunas é o que
 * prova, numa auditoria sobre a autoria do laudo, que houve leitura humana —
 * e é por isso que o gerado sobrevive mesmo quando a pessoa reescreve tudo.
 * `AiDraft` leva a trait `Auditavel`, então quem revisou, quando e o que mudou
 * ficam em `audit_logs` sem nada a mais aqui.
 *
 * ## Uma origem, um rascunho em aberto
 *
 * Gerar duas vezes para a mesma origem criaria dois textos concorrentes e
 * nenhuma resposta para "qual deles é o parecer". A segunda geração é recusada
 * apontando o rascunho existente; para começar de novo, descarte o anterior.
 *
 * ## Isolamento
 *
 * Nenhum método recebe `company_id` e nenhum consulta empresa. A origem chega
 * escopada (`WorkOrder` e `MonitoringReport` carregam `BelongsToCompany`), o
 * `AiDraft` nasce no tenant corrente pela mesma trait, e o contexto é montado
 * por `MontadorDeContexto`, que só lê a própria origem.
 */
class ParecerService
{
    public function __construct(
        private readonly ProvedorDeTexto $provedor,
        private readonly MontadorDeContexto $montador,
    ) {}

    /**
     * Situações em que a origem já tem um parecer em andamento e não aceita
     * uma segunda geração.
     */
    private const SITUACOES_EM_ABERTO = [
        AiDraft::SITUACAO_GERADO,
        AiDraft::SITUACAO_EM_REVISAO,
        AiDraft::SITUACAO_REVISADO,
    ];

    /**
     * Rascunho de parecer técnico a partir de uma ordem de serviço.
     */
    public function gerarParaOs(WorkOrder $os, User $usuario): AiDraft
    {
        return $this->gerar(
            origem: $os,
            tipo: AiDraft::TIPO_PARECER_OS,
            sistema: MontadorDeContexto::PREFIXO_DE_SISTEMA,
            entrada: $this->montador->paraOs($os),
            usuario: $usuario,
        );
    }

    /**
     * Rascunho do resumo do período que abre o relatório de monitoramento
     * (Plano 21), com a mesma exigência de revisão antes de publicar.
     */
    public function resumoDoPeriodo(MonitoringReport $relatorio, User $usuario): AiDraft
    {
        return $this->gerar(
            origem: $relatorio,
            tipo: AiDraft::TIPO_RESUMO_MONITORAMENTO,
            sistema: MontadorDeContexto::PREFIXO_DE_SISTEMA_DO_RESUMO,
            entrada: $this->montador->paraRelatorioDeMonitoramento($relatorio),
            usuario: $usuario,
        );
    }

    /**
     * Grava o texto aprovado por uma pessoa.
     *
     * Só o revisado muda: o gerado fica intacto, e é a comparação entre os
     * dois que responde a uma auditoria sobre a autoria do laudo.
     */
    public function revisar(AiDraft $rascunho, string $texto, User $usuario): AiDraft
    {
        $texto = trim($texto);

        if ($texto === '') {
            throw new InvalidArgumentException('O texto revisado não pode ficar em branco.');
        }

        if ($rascunho->situacao === AiDraft::SITUACAO_DESCARTADO) {
            throw new InvalidArgumentException('Este rascunho foi descartado e não pode mais ser revisado.');
        }

        $rascunho->forceFill([
            'conteudo_revisado' => $texto,
            'situacao' => AiDraft::SITUACAO_REVISADO,
            'revisado_por' => $usuario->id,
            'revisado_em' => BusinessDate::agora(),
        ])->save();

        return $rascunho->refresh();
    }

    /**
     * Marca o rascunho como em revisão, sem aprovar nada.
     *
     * Existe para a tela poder mostrar "alguém já está mexendo neste texto"
     * sem que isso valha como revisão: só `revisar()` libera a emissão.
     */
    public function marcarEmRevisao(AiDraft $rascunho): AiDraft
    {
        if ($rascunho->situacao !== AiDraft::SITUACAO_GERADO) {
            return $rascunho;
        }

        $rascunho->forceFill(['situacao' => AiDraft::SITUACAO_EM_REVISAO])->save();

        return $rascunho->refresh();
    }

    /**
     * Descarta o rascunho e libera a origem para uma nova geração.
     *
     * Descartar não apaga: a linha continua em `ai_drafts` com o texto gerado,
     * porque a pergunta "o que o modelo escreveu antes de alguém decidir
     * refazer" precisa ter resposta.
     */
    public function descartar(AiDraft $rascunho, User $usuario): AiDraft
    {
        $rascunho->forceFill([
            'situacao' => AiDraft::SITUACAO_DESCARTADO,
            'revisado_por' => $usuario->id,
            'revisado_em' => BusinessDate::agora(),
        ])->save();

        return $rascunho->refresh();
    }

    /**
     * Rascunho em aberto de uma origem, se houver.
     */
    public function rascunhoEmAberto(Model $origem, string $tipo): ?AiDraft
    {
        return AiDraft::query()
            ->where('tipo', $tipo)
            ->where('origem_tipo', $origem::class)
            ->where('origem_id', $origem->getKey())
            ->whereIn('situacao', self::SITUACOES_EM_ABERTO)
            ->latest('id')
            ->first();
    }

    /**
     * Guarda de emissão.
     *
     * Recusa quando a origem tem um rascunho de parecer que ainda não foi
     * revisado por uma pessoa. Origem sem rascunho nenhum passa: o recurso é
     * opcional, e quem escreve o parecer à mão nunca cria `AiDraft`.
     *
     * Estática de propósito: é chamada por `WorkOrderService` e por
     * `CertificateService`, que não dependem deste Service para nada mais e
     * não deveriam ganhar uma dependência de construtor só por causa dela.
     *
     * @param  string  $documento  Nome do documento na mensagem de recusa.
     *
     * @throws ParecerNaoRevisadoException
     */
    public static function garantirParecerRevisado(Model $origem, string $tipo, string $documento): void
    {
        $pendente = AiDraft::query()
            ->where('tipo', $tipo)
            ->where('origem_tipo', $origem::class)
            ->where('origem_id', $origem->getKey())
            ->whereIn('situacao', [AiDraft::SITUACAO_GERADO, AiDraft::SITUACAO_EM_REVISAO])
            ->exists();

        if ($pendente) {
            throw ParecerNaoRevisadoException::paraDocumento($documento);
        }
    }

    /**
     * Caminho comum das duas gerações.
     */
    private function gerar(
        Model $origem,
        string $tipo,
        string $sistema,
        string $entrada,
        User $usuario,
    ): AiDraft {
        $existente = $this->rascunhoEmAberto($origem, $tipo);

        if ($existente !== null) {
            throw new RascunhoJaExisteException($existente);
        }

        $resposta = $this->provedor->gerar($sistema, $entrada, ['tipo' => $tipo]);

        return AiDraft::create([
            'tipo' => $tipo,
            'origem_tipo' => $origem::class,
            'origem_id' => $origem->getKey(),
            'conteudo_gerado' => $resposta->texto,
            'situacao' => AiDraft::SITUACAO_GERADO,
            'modelo' => $resposta->modelo,
            'gerado_por' => $usuario->id,
        ]);
    }
}
