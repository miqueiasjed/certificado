<?php

namespace App\Http\Controllers;

use App\Http\Requests\DeviceScanRequest;
use App\Services\DeviceScanService;

/**
 * Leitura do código de um dispositivo, pelo QR code da etiqueta ou digitado à
 * mão.
 *
 * Controller fino de propósito: recebe a STRING do código, entrega ao
 * `DeviceScanService` e devolve o que ele responder. Toda a decisão de o que
 * aquele código significa mora no Service.
 *
 * Por que o código nunca vira Model Binding
 * -----------------------------------------
 * Se a rota fosse `{device}` e o Laravel resolvesse o registro sozinho, um
 * código sem correspondência estouraria o 404 padrão do framework, antes de
 * qualquer linha nossa rodar. Isso é uma resposta DIFERENTE de "código não
 * encontrado" — status diferente, corpo diferente — e a diferença apareceria
 * justamente entre um código que existe em outra empresa e um que não existe em
 * lugar nenhum. As quatro situações da leitura saem todas com 200 e o corpo
 * dizendo qual delas é, inclusive `nao_encontrado`.
 *
 * Resposta sempre JSON
 * --------------------
 * Não existe tela Blade nem página Inertia própria desta rota: quem consome é o
 * leitor de QR do frontend Vue, por fetch, e mais tarde o aplicativo do técnico.
 * A rota sem prefixo e a com `/api` apontam para este mesmo método e respondem a
 * mesma coisa; a segunda existe só para o consumidor programático ter um caminho
 * estável quando a primeira ganhar uma tela.
 */
class DeviceScanController extends Controller
{
    public function __construct(
        private DeviceScanService $deviceScanService
    ) {}

    /**
     * Resolve o código lido, opcionalmente conferindo-o contra a ordem de
     * serviço em execução.
     *
     * Sem try/catch: a `AuthorizationException` do técnico sem vínculo com a
     * ordem e a `ModelNotFoundException` de ordem inexistente ou de outra
     * empresa sobem, e o Laravel as converte em 403 e 404. Capturar aqui para
     * reescrever a resposta só teria como efeito achatar as duas em algo mais
     * frouxo.
     */
    public function ler(DeviceScanRequest $request, string $codigo)
    {
        return response()->json(
            $this->deviceScanService->resolver(
                $codigo,
                $request->ordemDeServicoId(),
                $request->user()
            )
        );
    }
}
