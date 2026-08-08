<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;

/**
 * Credencial do provedor de assinatura eletrônica de um tenant (Plano 26,
 * Task 26.1).
 *
 * Irmão direto de `PaymentGatewayConfig` (Plano 19, Task 19.1), e por um
 * motivo de negócio, não de conveniência: a conta com o provedor é da empresa,
 * porque é a empresa que assina contrato com o cliente dela. A plataforma não
 * assina contrato de ninguém e não pode ter uma credencial única
 * compartilhada.
 *
 * `credenciais` e `webhook_token` são cifrados pelo próprio Eloquent, com o
 * cast `encrypted:array`/`encrypted` (AES-256-CBC, chave em `APP_KEY`, ver
 * `config/app.php`): o valor grava cifrado e chega em texto puro só depois de
 * passar pelo model, nunca em uma consulta SQL direta. `credenciais` guarda o
 * array inteiro que o provedor exigir (token, chave, id da conta...), sem
 * esquema fixo de campo por provedor.
 *
 * ## Localizar o tenant pelo `webhook_token` da URL
 *
 * `POST /webhooks/assinatura/{webhookToken}` (Task 26.3) chega sem sessão e
 * sem usuário: o único jeito de saber de qual tenant é o webhook é o token que
 * a própria URL carrega, em texto puro. Como `webhook_token` é cifrado com IV
 * aleatório — o texto cifrado nunca repete, nem para o mesmo valor em claro —
 * `WHERE webhook_token = ?` não casa com nada gravado, e decifrar tenant a
 * tenant em memória não escala.
 *
 * A saída é `webhook_token_hash`: o HMAC-SHA256 do token em claro, calculado
 * com `APP_KEY` como chave e regravado sempre que `webhook_token` muda
 * (`booted()` abaixo). HMAC é determinístico — mesma entrada, mesma saída — e
 * por isso admite índice único e busca por igualdade; ao mesmo tempo não é
 * reversível, então esta coluna não reabre o vazamento que a cifragem existe
 * para fechar. `paraToken()` é o único ponto de leitura desse índice. Decisão
 * idêntica à de `PaymentGatewayConfig`, com a diferença de a coluna já nascer
 * na migration de criação da tabela.
 *
 * Dado que o próprio tenant produz e só ele pode ver: leva `BelongsToCompany`
 * e está classificado em
 * `App\Support\DominioMultiempresa::MODELS_DE_DOMINIO`.
 */
class SignatureProviderConfig extends Model
{
    use BelongsToCompany;

    /**
     * Ambientes aceitos, iguais ao enum da coluna `ambiente`.
     *
     * @var array<int, string>
     */
    public const AMBIENTES = ['sandbox', 'producao'];

    protected $fillable = [
        'provedor',
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
     * Nunca serializa a credencial nem o token do webhook, mesmo decifrados em
     * memória: `toArray()`/`toJson()` do model não pode ser o vazamento que a
     * cifragem no banco existe para evitar. `webhook_token_hash` entra na
     * mesma lista por prudência: não é reversível, mas também não tem por que
     * aparecer numa resposta.
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
     * atribuição), então regravar o mesmo valor em claro recalcula o hash à
     * toa — inofensivo, porque o HMAC é determinístico. O que importa é o
     * inverso nunca acontecer: `webhook_token` mudar sem o hash acompanhar
     * deixaria o token novo sem como ser localizado por `paraToken()`.
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
     * e não reversível: serve para localizar o tenant por igualdade, nunca
     * para reconstruir o token a partir do hash.
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
     * ponto desta consulta enxergar todos os tenants, porque é ela quem decide
     * a qual tenant o webhook pertence — mesmo raciocínio já registrado em
     * `PaymentGatewayConfig::paraToken()`. Sem filtro por `ativo` de
     * propósito: desligar o envio de pedidos novos não pode impedir a
     * conclusão de um pedido enviado enquanto o provedor ainda estava ativo,
     * o que deixaria o contrato preso em "em assinatura" para sempre.
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
