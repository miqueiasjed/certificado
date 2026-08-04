<?php

namespace App\Http\Controllers\Portal;

use App\Http\Controllers\Controller;
use App\Models\ClientUser;
use App\Services\PortalService;
use App\Support\CatalogoDeModulos;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Telas do portal do cliente (Task 15.4): painel, visitas, certificados,
 * contratos e adequações em aberto. Todo dado vem do `PortalService`
 * (Task 15.3), que já resolve o escopo duplo (empresa + cliente) - nenhum
 * método aqui monta consulta Eloquent própria, a regra de ouro desta task,
 * cobrada por revisão e por grep neste arquivo.
 *
 * `faturas()` saiu daqui na Task 19.6 (Plano 19), para
 * `App\Http\Controllers\Portal\PortalPagamentoController`: a listagem passou
 * a decorar cada fatura com a cobrança ativa (boleto/Pix), e reunir isso com
 * a emissão de cobrança pelo cliente no mesmo controller manteve as duas
 * ações (ver a fatura, pagar a fatura) juntas. A rota nomeada continua a
 * mesma (`portal.faturas`), só o controller por trás dela mudou.
 *
 * ## `PortalService` não entra pelo container
 *
 * `PortalService::__construct(ClientUser $clientUser)` recebe o `ClientUser`
 * autenticado diretamente, e nunca lê `Auth::user()`/`Auth::guard()` sozinho
 * (ver o docblock da classe). Como o container não tem como adivinhar QUAL
 * `ClientUser` injetar em tempo de resolução automática, este controller
 * monta a instância à mão no construtor, a partir do guard `cliente` -
 * garantido não nulo aqui porque toda rota deste arquivo já passou por
 * `AutenticarCliente` antes de chegar no controller.
 *
 * ## Paginação: no controller, a partir do array já carregado pelo Service
 *
 * `PortalService::visitas()/certificados()/contratos()/faturas()/
 * adequacoesEmAberto()` devolvem `array` simples (Task 15.3), e
 * `PortalServiceTest` já depende desse contrato (`array_column($visitas,
 * 'id')` etc.), sem paginação nenhuma. A Task 15.4 pede paginação de 20 em 20
 * em toda lista, e a forma mais simples de chegar lá sem reabrir o contrato
 * de retorno do Service - e sem quebrar os testes da 15.3 - é paginar aqui,
 * sobre o array que o Service já devolve pronto, com um `LengthAwarePaginator`
 * montado à mão (o mesmo objeto que o Inertia já sabe serializar em qualquer
 * listagem paginada do sistema). O painel (`index()`) é a exceção
 * deliberada: mostra só uma prévia de cada bloco (próximas visitas,
 * adequações em aberto, faturas vencendo), então não pagina - só as cinco
 * telas de listagem completa paginam.
 */
class PortalController extends Controller
{
    private const ITENS_POR_PAGINA = 20;

    private readonly PortalService $portal;

    public function __construct()
    {
        $this->portal = new PortalService($this->clienteAutenticado());
    }

    /**
     * Painel: prévia do que precisa da atenção do cliente, sem paginação (ver
     * o docblock da classe).
     */
    public function index(): Response
    {
        return Inertia::render('Portal/Dashboard', [
            'proximasVisitas' => $this->portal->proximasVisitas(),
            'adequacoesEmAberto' => $this->portal->adequacoesEmAberto(),
            'faturasVencendo' => $this->portal->faturasVencendo(),
        ]);
    }

    public function visitas(Request $request): Response
    {
        return Inertia::render('Portal/Visitas/Index', [
            'visitas' => $this->paginar($this->portal->visitas(), $request),
        ]);
    }

    /**
     * Visita inexistente ou de outro cliente/empresa: `PortalService::visita()`
     * lança `ModelNotFoundException`, que o Laravel converte em 404 sozinho,
     * sem tratamento nenhum aqui.
     */
    public function visita(int $id): Response
    {
        return Inertia::render('Portal/Visitas/Show', [
            'visita' => $this->portal->visita($id),
        ]);
    }

    public function certificados(Request $request): Response
    {
        return Inertia::render('Portal/Certificados/Index', [
            'certificados' => $this->paginar($this->portal->certificados(), $request),
        ]);
    }

    public function contratos(Request $request): Response
    {
        return Inertia::render('Portal/Contratos/Index', [
            'contratos' => $this->paginar($this->comProximasVisitas($this->portal->contratos()), $request),
        ]);
    }

    /**
     * Acrescenta, a cada contrato, as próprias visitas futuras (Task 15.7:
     * "próximas visitas previstas" na tela de contratos).
     *
     * `PortalService::contratos()`/`::proximasVisitas()` não expõem
     * `address_id` (a lista de permissão de `CamposVisiveisAoCliente` só
     * devolve o endereço já formatado em texto, nunca o id interno) - reabrir
     * esse contrato de retorno quebraria os testes da Task 15.3, que já
     * fixam o formato exato do array. Em vez disso, o relacionamento é
     * refeito aqui, comparando o texto do endereço: como as duas listas vêm
     * do mesmo `Address::full_address` para o mesmo registro, a mesma string
     * exata só se repete quando é de fato o mesmo endereço.
     *
     * @param  array<int, array<string, mixed>>  $contratos
     * @return array<int, array<string, mixed>>
     */
    private function comProximasVisitas(array $contratos): array
    {
        $visitasPorEndereco = collect($this->portal->proximasVisitas())
            ->filter(fn (array $visita): bool => filled($visita['address']))
            ->groupBy('address');

        return array_map(function (array $contrato) use ($visitasPorEndereco): array {
            $contrato['proximas_visitas'] = $visitasPorEndereco->get($contrato['address'] ?? null, collect())->values()->all();

            return $contrato;
        }, $contratos);
    }

    public function adequacoes(Request $request): Response
    {
        return Inertia::render('Portal/Adequacoes/Index', [
            'adequacoes' => $this->paginar($this->portal->adequacoesEmAberto(), $request),
        ]);
    }

    /**
     * Destino de `EnsurePortalModuleIsActive` quando a empresa não tem o
     * módulo `portal_cliente` ativo (ver `routes/portal.php`, fora de
     * propósito do bloqueio de módulo). Texto endereçado ao cliente final,
     * nunca ao funcionário da empresa: a descrição do catálogo, escrita para
     * quem contrata o módulo ("Um espaço onde o seu cliente acompanha..."),
     * soaria estranha lida pelo próprio cliente, então não é reaproveitada
     * aqui, só o nome do módulo.
     *
     * Sem consulta Eloquent: o nome vem do array estático de
     * `CatalogoDeModulos`, a mesma fonte que `EnsureModuleIsActive` usa para
     * a mensagem de JSON, nunca de uma consulta a `Module`.
     */
    public function moduloIndisponivel(): Response
    {
        $modulo = collect(CatalogoDeModulos::catalogo())->firstWhere('chave', 'portal_cliente');

        return Inertia::render('Portal/ModuloIndisponivel', [
            'nome' => $modulo['nome'] ?? 'Portal do cliente',
            'mensagem' => 'Este espaço ainda não foi liberado para você. Fale com a empresa '
                .'responsável pelo seu atendimento para mais informações.',
        ]);
    }

    private function clienteAutenticado(): ClientUser
    {
        /** @var ClientUser $clientUser */
        $clientUser = Auth::guard('cliente')->user();

        return $clientUser;
    }

    /**
     * Pagina, com {@see self::ITENS_POR_PAGINA} itens por página, um array já
     * carregado por inteiro do `PortalService`. Ver o docblock da classe
     * para o porquê de paginar aqui, e não dentro do Service.
     *
     * @param  array<int, array<string, mixed>>  $itens
     */
    private function paginar(array $itens, Request $request): LengthAwarePaginator
    {
        $colecao = collect($itens);
        $pagina = LengthAwarePaginator::resolveCurrentPage();

        return new LengthAwarePaginator(
            $colecao->forPage($pagina, self::ITENS_POR_PAGINA)->values(),
            $colecao->count(),
            self::ITENS_POR_PAGINA,
            $pagina,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );
    }
}
