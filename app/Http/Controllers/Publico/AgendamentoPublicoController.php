<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Http\Requests\Publico\StoreAppointmentRequestRequest;
use App\Services\PublicSchedulingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * Página pública de pedido de horário (Plano 16, Task 16.3).
 *
 * A primeira área do sistema sem autenticação nenhuma. Três coisas valem em
 * todos os métodos daqui:
 *
 * 1. **O tenant vem do slug da URL**, resolvido em
 *    `PublicSchedulingService::empresaDoSlug()`, que devolve 404 igual para slug
 *    inexistente e para empresa com o agendamento online desligado. Nenhum
 *    método pergunta quem está autenticado, e nenhum aceita `company_id`.
 * 2. **Nenhuma consulta Eloquent aqui.** Toda leitura e gravação passa pelo
 *    Service, que é quem abre `TenantAtual::comTenant()`. Sem esse contexto a
 *    consulta rodaria sem escopo por empresa e enxergaria o sistema inteiro
 *    (ver o cabeçalho de `PublicSchedulingService`).
 * 3. **A resposta ao visitante é sempre a mesma**, tanto para pedido gravado
 *    quanto para pedido descartado pelo campo armadilha: uma confirmação de
 *    recebimento, sem número de protocolo, sem id, sem dizer se o contato já era
 *    cliente da empresa.
 */
class AgendamentoPublicoController extends Controller
{
    /**
     * Único retorno do envio, para pedido gravado e para pedido descartado.
     *
     * Sem protocolo consultável de propósito: um número que o visitante pudesse
     * consultar exigiria outra rota pública, e ela devolveria a situação de um
     * pedido a quem tivesse o número, o que é dado da empresa e do solicitante.
     * O texto também deixa explícito que ainda não é agendamento, porque tela
     * que parece confirmação gera cliente esperando técnico que não vai.
     */
    public const MENSAGEM_DE_RECEBIMENTO = 'Recebemos seu pedido de horário. '
        .'A empresa vai entrar em contato por telefone ou e-mail para confirmar. '
        .'Este pedido ainda não é um agendamento.';

    public function __construct(
        private readonly PublicSchedulingService $agendamento,
    ) {}

    /**
     * Página do tenant: marca, tipos de serviço, períodos e a grade do mês.
     *
     * Os dois nomes de campo do formulário saem daqui, e não do Service: o
     * carimbo e a armadilha são detalhe do formulário HTTP, e o frontend precisa
     * deles para montar os `input` sem repetir string solta (Task 16.6). O valor
     * do carimbo vai na prop `carregado_em`, que já tem o nome do campo.
     */
    public function show(Request $request, string $slug): Response
    {
        $empresa = $this->agendamento->empresaDoSlug($slug);

        return Inertia::render('Publico/Agendar', array_merge(
            $this->agendamento->dadosDaPagina($empresa, $request->query('mes')),
            ['campo_armadilha' => StoreAppointmentRequestRequest::CAMPO_ARMADILHA]
        ));
    }

    /**
     * Grade de um mês, para o calendário trocar de mês sem recarregar a página.
     *
     * JSON, e não Inertia: é consulta de apoio da mesma tela. Devolve só
     * `disponivel` por período, nunca quantidade de vagas nem técnico.
     */
    public function grade(Request $request, string $slug): JsonResponse
    {
        $empresa = $this->agendamento->empresaDoSlug($slug);
        $mes = $this->agendamento->mesNormalizado($request->query('mes'));

        return response()->json([
            'mes' => $mes,
            'grade' => $this->agendamento->gradeDoMes($empresa, $mes),
        ]);
    }

    /**
     * Registra o pedido.
     *
     * Campo armadilha preenchido cai no mesmo retorno sem gravar nada: é o
     * descarte silencioso da Task 16.3, e a única diferença fica no banco, onde o
     * robô não pode olhar.
     *
     * A empresa é avisada dentro do Service (evento
     * `solicitacao_horario_recebida`). Nenhuma ordem de serviço é criada aqui.
     *
     * O único erro de negócio esperado é o limite de pedidos por hora
     * (`RuntimeException`), devolvido como mensagem no formulário, mesmo
     * tratamento de `Portal\ClientRequestController::store()`.
     */
    public function store(StoreAppointmentRequestRequest $request, string $slug): RedirectResponse
    {
        if (! $request->descartarSilenciosamente()) {
            try {
                $this->agendamento->registrar(
                    $request->empresa(),
                    $request->validated(),
                    $request->ip()
                );
            } catch (RuntimeException $erro) {
                return redirect()
                    ->route('publico.agendar.show', ['slug' => $slug])
                    ->withErrors(['limite' => $erro->getMessage()]);
            }
        }

        return redirect()
            ->route('publico.agendar.show', ['slug' => $slug])
            ->with('success', self::MENSAGEM_DE_RECEBIMENTO);
    }
}
