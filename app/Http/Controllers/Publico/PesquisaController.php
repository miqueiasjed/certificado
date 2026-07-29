<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Http\Requests\Publico\ResponderPesquisaRequest;
use App\Services\SatisfactionSurveyService;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Página pública de resposta da pesquisa de satisfação (Plano 16, Task 16.5).
 *
 * Segunda área do sistema sem autenticação nenhuma, depois do pedido de horário
 * (Task 16.3), e as mesmas três regras valem:
 *
 * 1. **A empresa vem do token da URL**, resolvida em
 *    `SatisfactionSurveyService::resolverToken()`, nunca de quem está autenticado
 *    no navegador. Nenhum método daqui pergunta pelo usuário ou aceita
 *    `company_id`.
 * 2. **Nenhuma consulta Eloquent aqui.** Toda leitura e gravação passa pelo
 *    Service, que é quem abre `TenantAtual::comTenant()`.
 * 3. **A resposta ao visitante mostra a marca da empresa e o serviço avaliado, e
 *    nada mais** do cliente ou da visita. O recorte é montado em
 *    `SatisfactionSurveyService::dadosDaPagina()`.
 *
 * Os quatro estados do token (disponível, inválido, expirado e já respondido) são
 * o mesmo componente Inertia com a prop `estado`, e cada um tem título e texto
 * próprios, sem tom de erro de sistema: quem cai aqui é o cliente final que
 * clicou em um link. Nenhum estado responde 404 de propósito, porque uma tela
 * crua de erro não diz à pessoa o que fazer.
 */
class PesquisaController extends Controller
{
    /**
     * Retorno de uma resposta gravada.
     */
    public const MENSAGEM_DE_AGRADECIMENTO = 'Obrigado pela sua avaliação.';

    /**
     * Retorno de nota 1 ou 2. A frase é verdade pelo que o Service faz: a
     * pesquisa fica marcada como pendência de contato e a empresa é avisada.
     */
    public const MENSAGEM_DE_NOTA_BAIXA = 'Obrigado pela sua avaliação. A empresa vai entrar em contato.';

    public function __construct(
        private readonly SatisfactionSurveyService $pesquisas,
    ) {}

    /**
     * Página de resposta, ou a página do estado em que o token está.
     */
    public function show(string $token): Response
    {
        return Inertia::render(
            'Publico/Pesquisa',
            $this->pesquisas->dadosDaPagina($this->pesquisas->resolverToken($token))
        );
    }

    /**
     * Grava a nota e o comentário.
     *
     * O estado do token é reconferido aqui, e não confiado ao que a página
     * mostrou: entre carregar a tela e enviar a resposta a pesquisa pode ter sido
     * respondida em outra aba ou ter vencido. Token que não está disponível volta
     * para o `show`, que explica o estado, sem gravar nada.
     *
     * A segunda resposta do mesmo token cai exatamente nesse caminho: a primeira
     * já preencheu `respondida_em`, então o estado passa a ser "já respondida" e a
     * nota gravada não é sobrescrita.
     */
    public function store(ResponderPesquisaRequest $request, string $token): RedirectResponse
    {
        $resolucao = $this->pesquisas->resolverToken($token);

        $paraAPagina = redirect()->route('publico.pesquisa.show', ['token' => $token]);

        if ($resolucao['estado'] !== SatisfactionSurveyService::ESTADO_DISPONIVEL) {
            return $paraAPagina;
        }

        $pesquisa = $this->pesquisas->responder(
            $resolucao['pesquisa'],
            (int) $request->validated('nota'),
            $request->validated('comentario')
        );

        return $paraAPagina->with(
            'success',
            $pesquisa->pendencia_de_contato ? self::MENSAGEM_DE_NOTA_BAIXA : self::MENSAGEM_DE_AGRADECIMENTO
        );
    }
}
