<?php

namespace App\Http\Controllers;

use App\Http\Requests\AtualizarConfiguracaoDeAssinaturaRequest;
use App\Models\SignatureProviderConfig;
use App\Services\Signature\ProvedorPadrao;
use App\Services\Signature\ResolvedorDeProvedor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Credencial do provedor de assinatura eletrônica do tenant (Plano 26,
 * Task 26.4).
 *
 * Gêmeo dos métodos de configuração de `ChargeController` (Plano 19,
 * Task 19.6), com a mesma regra que é o ponto principal desta tela: **a
 * credencial nunca volta na resposta**. Nem parcial, nem mascarada. O que a
 * tela recebe é se existe credencial, qual provedor, qual ambiente, se está
 * ativa e quando foi verificada pela última vez — o suficiente para o usuário
 * saber o que está configurado sem que um `console.log` no navegador do
 * cliente vire o token de assinatura da empresa.
 *
 * O `webhook_token` também não volta: ele compõe a URL que o provedor chama, e
 * quem precisa dela é quem configura o painel do provedor. `webhookUrl()`
 * devolve a URL montada, que é o que a pessoa copia — o token aparece ali
 * porque é justamente o que ela precisa colar, mas atrás da mesma permissão de
 * administrador e nunca junto do restante dos dados da tela.
 */
class SignatureProviderConfigController extends Controller
{
    public function __construct(
        private readonly ResolvedorDeProvedor $resolvedorDeProvedor,
    ) {}

    public function index(Request $request): Response|JsonResponse
    {
        $configuracao = SignatureProviderConfig::query()->orderByDesc('updated_at')->first();

        $dados = [
            'configuracao' => [
                'provedor' => $configuracao?->provedor,
                'ambiente' => $configuracao?->ambiente,
                'possui_credencial' => $configuracao !== null,
                'ativo' => $configuracao?->ativo ?? false,
                'verificado_em' => $configuracao?->verificado_em?->toIso8601String(),
            ],
            'provedores' => ResolvedorDeProvedor::provedoresConhecidos(),
            'ambientes' => SignatureProviderConfig::AMBIENTES,
        ];

        if ($request->expectsJson()) {
            return response()->json($dados);
        }

        return Inertia::render('Assinaturas/Configuracao', $dados);
    }

    /**
     * Salva a credencial e, quando ela mudou, tenta validá-la na hora.
     *
     * A validação acontece no salvamento de propósito: é assim que o tenant
     * descobre um token errado agora, na tela de configuração, e não no
     * primeiro contrato real — problema que aconteceria na frente do cliente
     * dele. Falha de validação **não** impede o salvamento: a credencial pode
     * estar certa e o provedor fora do ar, e recusar o salvamento nesse caso
     * obrigaria a pessoa a digitar o token de novo mais tarde.
     */
    public function update(AtualizarConfiguracaoDeAssinaturaRequest $request): JsonResponse
    {
        $provedor = $request->validated('provedor')
            ?: (SignatureProviderConfig::query()->value('provedor') ?: ProvedorPadrao::NOME);

        $configuracao = SignatureProviderConfig::query()->where('provedor', $provedor)->first()
            ?? new SignatureProviderConfig(['provedor' => $provedor, 'ambiente' => 'sandbox']);

        if ($request->has('ambiente') && $request->input('ambiente') !== null) {
            $configuracao->ambiente = $request->validated('ambiente');
        }

        $credencialMudou = false;

        if ($request->has('credenciais') && $request->input('credenciais') !== []) {
            $configuracao->credenciais = $request->validated('credenciais');
            // Credencial nova: a verificação anterior não vale para o token
            // que acabou de entrar.
            $configuracao->verificado_em = null;
            $credencialMudou = true;
        }

        if ($request->has('ativo')) {
            $configuracao->ativo = $request->boolean('ativo');
        }

        // O token do webhook nasce com a configuração e nunca é regravado
        // depois: ele compõe uma URL já cadastrada no painel do provedor, e
        // trocá-lo silenciosamente deixaria de chegar todo aviso de assinatura
        // — sem nenhum erro visível, que é o pior modo de falhar aqui.
        if (blank($configuracao->webhook_token)) {
            $configuracao->webhook_token = Str::random(40);
        }

        $configuracao->save();

        $validacao = null;

        if ($credencialMudou) {
            $validacao = $this->validarSemDerrubar($configuracao);
        }

        return response()->json([
            'message' => 'Configuração de assinatura eletrônica salva.',
            'credencial_valida' => $validacao,
            'verificado_em' => $configuracao->fresh()?->verificado_em?->toIso8601String(),
        ]);
    }

    /**
     * Botão "validar credencial": resposta imediata, sem esperar o primeiro
     * envio real para descobrir um token errado.
     */
    public function validar(): JsonResponse
    {
        $configuracao = SignatureProviderConfig::query()->orderByDesc('updated_at')->first();

        if (! $configuracao instanceof SignatureProviderConfig) {
            return response()->json(['message' => 'Nenhuma credencial configurada ainda.'], 422);
        }

        try {
            $valida = $this->resolvedorDeProvedor->validar($configuracao);
        } catch (Throwable $erro) {
            return response()->json([
                'message' => 'Não foi possível validar a credencial agora: '.$erro->getMessage(),
            ], 503);
        }

        return response()->json([
            'valida' => $valida,
            'message' => $valida
                ? 'Credencial válida.'
                : 'O provedor recusou a credencial. Confira o token e tente de novo.',
            'verificado_em' => $configuracao->fresh()?->verificado_em?->toIso8601String(),
        ]);
    }

    /**
     * URL que o tenant cadastra no painel do provedor para receber os avisos
     * de assinatura.
     *
     * Endpoint separado, e não um campo de `index()`, porque ele revela o
     * `webhook_token` em claro: quem abre a tela de configuração para conferir
     * o ambiente não precisa carregar o segredo junto. Aqui a pessoa pede
     * explicitamente, e a permissão de administrador continua valendo.
     */
    public function webhookUrl(): JsonResponse
    {
        $configuracao = SignatureProviderConfig::query()->orderByDesc('updated_at')->first();

        if (! $configuracao instanceof SignatureProviderConfig || blank($configuracao->webhook_token)) {
            return response()->json(['message' => 'Salve a credencial primeiro para gerar o endereço do webhook.'], 422);
        }

        return response()->json([
            'url' => route('webhooks.assinatura', ['webhookToken' => $configuracao->webhook_token]),
        ]);
    }

    /**
     * Valida sem deixar o provedor fora do ar derrubar o salvamento. Devolve
     * `null` quando não deu para saber.
     */
    private function validarSemDerrubar(SignatureProviderConfig $configuracao): ?bool
    {
        try {
            return $this->resolvedorDeProvedor->validar($configuracao);
        } catch (Throwable) {
            return null;
        }
    }
}
