<?php

namespace App\Services;

use App\Jobs\EnviarRelatoParaSistemaJob;
use App\Models\ClientUser;
use App\Models\ProblemReport;
use App\Models\User;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Relatos do botão "Relatar problema" e o repasse ao sistema central de
 * suporte técnico.
 *
 * Quem relatou chega aqui já resolvido pelo controller, a partir do usuário
 * autenticado (funcionário no painel ou usuário do portal), e nunca do corpo
 * da requisição. O tenant do relato vem do contexto corrente, pela trait
 * `BelongsToCompany`.
 */
class ProblemReportService
{
    /**
     * Tamanho do título gerado a partir da descrição, quando a pessoa não
     * digita um. Curto de propósito: é o que aparece na lista e no card da
     * tarefa, e a descrição inteira vai junto.
     */
    private const TAMANHO_DO_TITULO_AUTOMATICO = 80;

    /**
     * Grava o relato e agenda o envio ao sistema central.
     *
     * O envio sai depois da resposta (`afterResponse()`), no mesmo processo:
     * este sistema não tem worker de fila garantido em produção, e um job
     * apenas enfileirado ficaria parado em `jobs` sem ninguém para
     * processá-lo. Depois da resposta, quem relatou não espera a chamada
     * HTTP, e uma queda do sistema central não derruba a criação do relato.
     * Falha passageira na primeira tentativa vira nova tentativa na fila; ver
     * o cabeçalho de `EnviarRelatoParaSistemaJob`.
     *
     * `afterCommit()` segura o envio até a transação que criou o relato
     * confirmar, se houver uma em volta: sem isso o job poderia procurar um
     * relato que sofreu rollback.
     *
     * @param  array{titulo?: ?string, descricao: string, pagina_url?: ?string}  $dados
     */
    public function registrar(array $dados, ?User $usuario, ?ClientUser $usuarioDoPortal, ?string $userAgent): ProblemReport
    {
        if (($usuario === null) === ($usuarioDoPortal === null)) {
            throw new InvalidArgumentException('O relato precisa de exatamente um autor: funcionário ou usuário do portal.');
        }

        $descricao = trim($dados['descricao']);

        $relato = ProblemReport::create([
            'user_id' => $usuario?->id,
            'client_user_id' => $usuarioDoPortal?->id,
            'titulo' => $this->tituloDoRelato($dados['titulo'] ?? null, $descricao),
            'descricao' => $descricao,
            'pagina_url' => $dados['pagina_url'] ?? null,
            'user_agent' => $userAgent !== null && $userAgent !== ''
                ? mb_substr($userAgent, 0, ProblemReport::LIMITE_USER_AGENT)
                : null,
            'situacao' => ProblemReport::SITUACAO_NOVO,
        ]);

        EnviarRelatoParaSistemaJob::dispatch($relato->id)
            ->afterCommit()
            ->afterResponse();

        return $relato;
    }

    /**
     * Troca a situação do relato, na área da plataforma.
     */
    public function alterarSituacao(ProblemReport $relato, string $situacao): ProblemReport
    {
        if (! in_array($situacao, ProblemReport::SITUACOES, true)) {
            throw new InvalidArgumentException("Situação de relato desconhecida: {$situacao}.");
        }

        $relato->update(['situacao' => $situacao]);

        return $relato->fresh();
    }

    /**
     * Grava a tarefa que o sistema central criou (201) ou devolveu (200) para
     * este relato.
     */
    public function marcarComoEnviado(ProblemReport $relato, ?int $tarefaId, ?string $tarefaUrl): ProblemReport
    {
        $relato->update([
            'sistema_task_id' => $tarefaId,
            'sistema_task_url' => $tarefaUrl !== null && $tarefaUrl !== ''
                ? mb_substr($tarefaUrl, 0, ProblemReport::LIMITE_URL)
                : null,
            'enviado_em' => now(),
        ]);

        return $relato;
    }

    /**
     * Título digitado ou, sem ele, o começo da descrição em uma linha só.
     */
    private function tituloDoRelato(?string $titulo, string $descricao): string
    {
        $titulo = Str::squish((string) $titulo);

        if ($titulo !== '') {
            return mb_substr($titulo, 0, ProblemReport::LIMITE_TITULO);
        }

        return Str::limit(Str::squish($descricao), self::TAMANHO_DO_TITULO_AUTOMATICO, '...');
    }
}
