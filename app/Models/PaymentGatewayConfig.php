<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Credencial de gateway de pagamento de um tenant (Plano 19, Task 19.1), para
 * a cobrança do tenant ao cliente final dele.
 *
 * `credenciais` e `webhook_token` são cifrados pelo próprio Eloquent, com o
 * cast `encrypted:array`/`encrypted` (AES-256-CBC, chave em `APP_KEY`, ver
 * `config/app.php`): o valor grava cifrado e chega em texto puro só depois de
 * passar pelo model, nunca em uma consulta SQL direta. É a defesa contra dump
 * de banco virar acesso à conta bancária do cliente do tenant. `credenciais`
 * guarda o array inteiro que o gateway exigir (client_id, secret, token...),
 * sem esquema fixo de campo por gateway; `webhook_token` é o que identifica
 * de qual tenant é um webhook recebido, sem confiar em nada do corpo da
 * mensagem.
 *
 * ## Localizar o tenant pelo `webhook_token` da URL (Task 19.4)
 *
 * `POST /webhooks/cobranca/{webhookToken}` chega sem sessão e sem usuário: o
 * único jeito de saber de qual tenant é o webhook é o token que a própria
 * URL carrega, em texto puro. O problema é que `webhook_token` é cifrado com
 * `encrypted`, cujo IV é aleatório a cada gravação — o texto cifrado nunca
 * repete, nem para o mesmo valor em claro — então `WHERE webhook_token = ?`
 * nunca bate com nada gravado, e decifrar e comparar tenant a tenant em
 * memória não escala (e é o tipo de solução que quebra silenciosamente
 * quando a base de tenants cresce).
 *
 * A saída é `webhook_token_hash` (migration da Task 19.4): o HMAC-SHA256 do
 * token em claro, calculado com `APP_KEY` como chave e gravado sempre que
 * `webhook_token` muda (`booted()` abaixo). HMAC é determinístico — o mesmo
 * token sempre produz o mesmo hash — e por isso admite índice único e busca
 * por igualdade; ao mesmo tempo não é reversível, então esta coluna não
 * reabre o vazamento que a cifragem do `webhook_token` existe para fechar.
 * `webhook_token` continua sendo a fonte de verdade gravada; o hash é só um
 * índice de busca. `paraToken()` é o único ponto de leitura desse índice.
 *
 * Diferente de `Subscription`/`Invoice`/`GatewayEvent` (Plano 7, cobrança da
 * plataforma ao tenant), que ficam fora do escopo por empresa porque o super
 * admin precisa ver todos os tenants na mesma tela, este model é dado que o
 * próprio tenant produz e só ele pode ver: leva `BelongsToCompany` e está
 * classificado em `App\Support\DominioMultiempresa::MODELS_DE_DOMINIO`.
 */
class PaymentGatewayConfig extends Model
{
    use BelongsToCompany;

    /**
     * Ambientes aceitos, iguais ao enum da coluna `ambiente`.
     *
     * @var array<int, string>
     */
    public const AMBIENTES = ['sandbox', 'producao'];

    protected $fillable = [
        'gateway',
        'ambiente',
        'credenciais',
        'webhook_token',
        'ativo',
        'verificado_em',
    ];

    protected $casts = [
        // Cifrados: nunca ficam legíveis fora do model. Ver o cabeçalho.
        'credenciais' => 'encrypted:array',
        'webhook_token' => 'encrypted',
        'ativo' => 'boolean',
        'verificado_em' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Nunca serializa a credencial nem o token do webhook, mesmo
     * decifrados em memória: `toArray()`/`toJson()` do model não pode ser o
     * vazamento que a cifragem no banco existe para evitar. `webhook_token_hash`
     * entra na mesma lista por prudência: não é reversível, mas também não
     * tem por que aparecer numa resposta.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'credenciais',
        'webhook_token',
        'webhook_token_hash',
    ];

    /**
     * Recalcula `webhook_token_hash` sempre que `webhook_token` é gravado.
     *
     * `isDirty()` aqui compara o texto cifrado (IV aleatório a cada
     * atribuição), então uma regravação do mesmo valor em claro recalcula o
     * hash à toa — inofensivo, porque o HMAC é determinístico e o resultado é
     * o mesmo. O que importa é o inverso nunca acontecer: `webhook_token`
     * mudar sem `webhook_token_hash` acompanhar, o que deixaria o token novo
     * sem como ser localizado por `paraToken()`.
     */
    protected static function booted(): void
    {
        static::saving(function (self $configuracao): void {
            if (! $configuracao->isDirty('webhook_token')) {
                return;
            }

            $configuracao->webhook_token_hash = blank($configuracao->webhook_token)
                ? null
                : self::hashDoWebhookToken($configuracao->webhook_token);
        });
    }

    /**
     * HMAC-SHA256 do token em claro, com `APP_KEY` como chave. Determinístico
     * (mesma entrada, mesma saída) e não reversível: serve para localizar o
     * tenant por igualdade, nunca para reconstruir o token a partir do hash.
     */
    public static function hashDoWebhookToken(string $tokenEmClaro): string
    {
        return hash_hmac('sha256', $tokenEmClaro, (string) config('app.key'));
    }

    /**
     * Configuração cujo `webhook_token` em claro é o recebido, ou `null`
     * quando nenhuma bate.
     *
     * `deTodasAsEmpresas()` é deliberado, e não um descuido: é exatamente o
     * ponto desta consulta enxergar todos os tenants, porque é ela quem
     * decide a qual tenant o webhook pertence — o mesmo raciocínio já
     * registrado em `ResolvedorDeGateway::configuracaoAtiva()` para a rotina
     * em lote. Sem filtro por `ativo` de propósito: desligar a emissão de
     * cobranças novas não pode impedir a conciliação de uma cobrança emitida
     * enquanto o gateway ainda estava ativo.
     */
    public static function paraToken(string $tokenEmClaro): ?self
    {
        if ($tokenEmClaro === '') {
            return null;
        }

        return static::query()
            ->deTodasAsEmpresas()
            ->where('webhook_token_hash', self::hashDoWebhookToken($tokenEmClaro))
            ->first();
    }
}
