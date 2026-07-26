<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Registra em audit_logs a criação, a alteração e a exclusão do model.
 *
 * Duas garantias que não podem ser afrouxadas:
 *
 * 1. Campo sensível (senha, token, segredo) nunca chega ao log. Além dos campos
 *    de config('auditoria.campos_ignorados') e da propriedade $naoAuditar do
 *    model, a trait aplica um piso próprio, que vale mesmo com a configuração
 *    cacheada em uma versão antiga ou alterada por engano.
 * 2. Falha ao auditar nunca derruba a operação de negócio. Todo o caminho da
 *    auditoria roda dentro de try/catch e o erro vai para o log da aplicação.
 *
 * Fora de requisição HTTP (comando artisan, fila, seeder) o autor é 'Sistema' e
 * o IP fica nulo, sem exceção por ausência de usuário autenticado.
 *
 * O created_at do registro é gravado pelo Eloquent no fuso da aplicação (UTC),
 * como todo instante do projeto. A conversão para America/Sao_Paulo acontece na
 * exibição, via BusinessDate.
 */
trait Auditavel
{
    /**
     * Piso de segurança: campo cujo nome contém um destes trechos nunca entra no
     * log, em nenhuma circunstância. Cobre password, remember_token, api_token e
     * two_factor_secret sem depender de configuração.
     */
    private const TRECHOS_SENSIVEIS = ['password', 'senha', 'token', 'secret'];

    /**
     * Liga os eventos do Eloquent assim que o model é inicializado.
     */
    public static function bootAuditavel(): void
    {
        static::created(function ($model) {
            $model->auditarEvento('criado');
        });

        static::updated(function ($model) {
            $model->auditarEvento('alterado');
        });

        static::deleted(function ($model) {
            $model->auditarEvento('excluido');
        });
    }

    /**
     * Histórico do registro, do mais recente para o mais antigo.
     *
     * O id desempata registros gravados no mesmo segundo.
     */
    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    /**
     * Atributos do model que podem ir para o log, já sem os campos ignorados.
     */
    public function atributosAuditaveis(): array
    {
        return $this->filtrarCamposAuditaveis($this->getAttributes());
    }

    /**
     * Campos fora da auditoria: os da configuração global mais os declarados na
     * propriedade opcional $naoAuditar do model.
     */
    public function camposIgnoradosNaAuditoria(): array
    {
        $daConfiguracao = config('auditoria.campos_ignorados', []);
        $doModel = property_exists($this, 'naoAuditar') ? $this->naoAuditar : [];

        return array_values(array_unique(array_merge(
            is_array($daConfiguracao) ? $daConfiguracao : [],
            is_array($doModel) ? $doModel : []
        )));
    }

    /**
     * Monta e grava o registro do evento.
     *
     * Tudo aqui dentro é acessório à operação principal: qualquer falha vira
     * log de erro, nunca exceção propagada para quem salvou o registro.
     */
    private function auditarEvento(string $acao): void
    {
        try {
            $valores = match ($acao) {
                'criado' => ['antes' => null, 'depois' => $this->atributosAuditaveis()],
                'excluido' => ['antes' => $this->atributosAuditaveis(), 'depois' => null],
                default => $this->valoresDaAlteracao(),
            };

            // Update sem campo auditável alterado não gera registro: salvar um
            // formulário sem mudar nada não pode poluir o histórico.
            if ($valores === null) {
                return;
            }

            $requisicao = $this->requisicaoAtual();
            $navegador = $requisicao?->userAgent();

            AuditLog::create([
                'auditable_type' => $this->getMorphClass(),
                'auditable_id' => $this->getKey(),
                'acao' => $acao,
                'user_id' => auth()->id(),
                'autor_nome' => $this->nomeDoAutor(auth()->user()),
                'valores_antes' => $valores['antes'],
                'valores_depois' => $valores['depois'],
                'ip' => $requisicao?->ip(),
                // A coluna tem 255 caracteres e user agent longo existe.
                'user_agent' => $navegador === null ? null : mb_substr($navegador, 0, 255),
            ]);
        } catch (Throwable $erro) {
            Log::error('Falha ao registrar auditoria.', [
                'model' => static::class,
                'id' => $this->getKey(),
                'acao' => $acao,
                'erro' => $erro->getMessage(),
            ]);
        }
    }

    /**
     * Valores antes e depois de um update, apenas com os campos auditáveis que
     * mudaram de fato. Devolve null quando não sobrou nada relevante.
     */
    private function valoresDaAlteracao(): ?array
    {
        $depois = $this->filtrarCamposAuditaveis($this->getChanges());

        if ($depois === []) {
            return null;
        }

        $antes = [];

        foreach (array_keys($depois) as $campo) {
            $antes[$campo] = $this->getRawOriginal($campo);
        }

        return ['antes' => $antes, 'depois' => $depois];
    }

    /**
     * Remove da lista os campos ignorados e os sensíveis.
     */
    private function filtrarCamposAuditaveis(array $atributos): array
    {
        $ignorados = $this->camposIgnoradosNaAuditoria();

        return array_filter(
            $atributos,
            fn ($campo) => ! in_array((string) $campo, $ignorados, true)
                && ! $this->campoEhSensivel((string) $campo),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Campo que guarda senha, token ou segredo, independente de configuração.
     */
    private function campoEhSensivel(string $campo): bool
    {
        $nome = mb_strtolower($campo);

        foreach (self::TRECHOS_SENSIVEIS as $trecho) {
            if (str_contains($nome, $trecho)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nome congelado do autor. Sem usuário autenticado (comando, fila, seeder) o
     * autor é 'Sistema'. Usuário sem nome cai no identificador, para o log nunca
     * ficar sem autor.
     */
    private function nomeDoAutor(?Authenticatable $autor): string
    {
        if ($autor === null) {
            return 'Sistema';
        }

        $nome = trim((string) ($autor->name ?? ''));

        return $nome !== '' ? $nome : 'Usuário #' . $autor->getAuthIdentifier();
    }

    /**
     * Requisição HTTP atual, ou null quando a execução não veio de uma.
     *
     * Em comando artisan, fila, seeder e na suíte de testes o Laravel monta um
     * Request falso a partir de APP_URL, com REMOTE_ADDR 127.0.0.1. Gravar esse
     * IP seria inventar origem, então a checagem de console vem primeiro.
     */
    private function requisicaoAtual(): ?Request
    {
        if (app()->runningInConsole() || ! app()->bound('request')) {
            return null;
        }

        $requisicao = request();

        if (! $requisicao instanceof Request || $requisicao->server('REMOTE_ADDR') === null) {
            return null;
        }

        return $requisicao;
    }
}
