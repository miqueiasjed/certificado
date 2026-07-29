<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\Client;
use App\Models\Company;
use App\Models\CompanyAvailabilitySetting;
use App\Models\ServiceType;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\RateLimiter;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Regras da página pública de agendamento (Plano 16, Task 16.3): resolver o
 * tenant pelo slug, montar o que a página mostra e registrar o pedido de
 * horário.
 *
 * ## O tenant vem do slug, e de mais nada
 *
 * Esta é a primeira rota do sistema sem usuário autenticado, e por isso
 * `TenantAtual::id()` devolve `null` aqui - o que, pela regra da trait
 * `BelongsToCompany`, significa consulta **sem filtro por empresa**. Um
 * descuido nesse ponto mostraria os tipos de serviço, os técnicos e a agenda de
 * todas as empresas do sistema na página de uma delas.
 *
 * Por isso todo método que lê ou grava dado de domínio roda dentro de
 * `TenantAtual::comTenant($empresa->id, ...)`, e a empresa sempre chega por
 * parâmetro, resolvida a partir do slug da URL em `empresaDoSlug()`. Nenhum
 * método aqui pergunta quem está autenticado, e nenhum aceita `company_id` do
 * corpo da requisição. Mesmo padrão de `AvailabilityService` e
 * `TenantUsageService`.
 *
 * ## 404 único, sem confirmar a existência da empresa
 *
 * `empresaDoSlug()` responde o mesmo 404 para slug que não existe e para
 * empresa que existe com o agendamento online desligado. Diferenciar os dois
 * transformaria a rota em um confirmador de cadastro: bastaria varrer slugs para
 * descobrir quem é cliente do sistema, e depois quem tem ou não a página
 * ligada.
 *
 * ## O pedido nunca agenda nada
 *
 * `registrar()` cria uma linha `pendente` em `appointment_requests` e avisa a
 * empresa. Não cria ordem de serviço, não cria cliente e não reserva vaga na
 * agenda: só a empresa sabe se tem técnico na região naquele dia, e é ela quem
 * confirma (Task 16.4).
 *
 * ## Limite de pedidos
 *
 * 3 pedidos por hora por IP e 1 por hora por telefone, contados por empresa e
 * contando **só pedido gravado**, nunca requisição recusada na validação. O
 * motivo dessa distinção, e de o limite não ser um `throttle:` de rota, está em
 * `recusarAcimaDoLimite()`.
 */
class PublicSchedulingService
{
    /**
     * Segundos mínimos entre carregar o formulário e enviá-lo.
     *
     * Preenchimento humano de nome, e-mail, telefone e endereço não acontece em
     * menos de três segundos. Robô que faz POST direto no endpoint, sem abrir a
     * página, nem tem o carimbo para mandar.
     */
    public const SEGUNDOS_MINIMOS_DE_PREENCHIMENTO = 3;

    /**
     * Teto de dias que a grade calcula em uma chamada.
     *
     * A grade é pedida por mês (`?mes=YYYY-MM`) e o cálculo sempre parte de
     * hoje, então um mês distante viraria uma varredura de dias sem fim. Mês
     * além disto devolve grade vazia, e não erro: a página pública não precisa
     * explicar a um visitante (ou a um robô testando parâmetro) qual é o limite
     * interno.
     */
    public const DIAS_MAXIMOS_DA_GRADE = 370;

    /**
     * Horizonte aceito em `data_preferida` no formulário, em dias corridos.
     * Casado com {@see self::DIAS_MAXIMOS_DA_GRADE}: não faz sentido aceitar
     * pedido para um dia que a grade nunca mostrou.
     */
    public const DIAS_MAXIMOS_DE_ANTECEDENCIA = 370;

    /**
     * Pedidos gravados que um mesmo IP pode abrir na mesma empresa em uma hora.
     */
    public const PEDIDOS_POR_HORA_POR_IP = 3;

    /**
     * Pedidos gravados que um mesmo telefone pode abrir na mesma empresa em uma
     * hora. Um pedido por hora basta: quem precisa mudar algo do pedido feito
     * agora fala com a empresa, que já foi avisada.
     */
    public const PEDIDOS_POR_HORA_POR_TELEFONE = 1;

    /**
     * Janela dos dois limites acima, em segundos.
     */
    private const SEGUNDOS_DA_JANELA_DE_LIMITE = 3600;

    /**
     * Rótulo de cada período, para o texto do aviso à empresa e para a página.
     *
     * @var array<string, string>
     */
    public const ROTULOS_DE_PERIODO = [
        AppointmentRequest::PERIODO_MANHA => 'manhã',
        AppointmentRequest::PERIODO_TARDE => 'tarde',
    ];

    public function __construct(
        private readonly AvailabilityService $disponibilidade,
        private readonly NotificationService $notificacoes,
    ) {}

    /**
     * Empresa dona do slug público, desde que ela aceite agendamento online.
     *
     * Slug vazio, slug inexistente e empresa com `aceita_agendamento_online`
     * desligado caem no mesmo `NotFoundHttpException` sem mensagem: em produção
     * o texto nem apareceria (`APP_DEBUG=false`), mas mensagem vazia garante que
     * não há nada para ler nem no ambiente de desenvolvimento.
     *
     * `companies` não usa `BelongsToCompany` (é a tabela do próprio tenant),
     * então esta consulta não precisa de contexto de empresa - ela é o que
     * *estabelece* o contexto para todo o resto do fluxo.
     *
     * @throws NotFoundHttpException
     */
    public function empresaDoSlug(mixed $slug): Company
    {
        $slug = is_string($slug) ? trim($slug) : '';

        if ($slug === '') {
            throw new NotFoundHttpException;
        }

        $empresa = Company::query()->where('slug_publico', $slug)->first();

        if ($empresa === null || ! $this->aceitaAgendamentoOnline($empresa)) {
            throw new NotFoundHttpException;
        }

        return $empresa;
    }

    /**
     * Tudo que a página pública mostra.
     *
     * O que **não** sai daqui, por decisão do plano: lista de técnicos,
     * quantidade de vagas, valores, contagem de pedidos e qualquer dado de
     * cliente. A grade devolve só `disponivel` por período, e quem garante isso
     * é o próprio `AvailabilityService`.
     *
     * @return array<string, mixed>
     */
    public function dadosDaPagina(Company $empresa, mixed $mes = null): array
    {
        $mes = $this->mesNormalizado($mes);

        return [
            'slug' => $empresa->slug_publico,
            // Mesmo recorte de marca já usado no portal do cliente (Task
            // 15.6): nome, logo, cores e contato, tudo informação que a
            // empresa divulga por vontade própria.
            'empresa' => $empresa->brandingDoPortal(),
            'tipos_de_servico' => $this->tiposDeServico($empresa),
            'periodos' => $this->periodos(),
            'mes' => $mes,
            'grade' => $this->gradeDoMes($empresa, $mes),
            'minimo_de_segundos' => self::SEGUNDOS_MINIMOS_DE_PREENCHIMENTO,
            'carregado_em' => $this->carimboDeCarregamento(),
        ];
    }

    /**
     * Grade de disponibilidade do mês informado (`YYYY-MM`).
     *
     * `AvailabilityService::gradeDisponivel()` sempre conta a partir de hoje, e
     * é assim de propósito: antecedência mínima e janela máxima da empresa são
     * contadas do dia de hoje, no fuso do negócio. Aqui a janela pedida é
     * esticada até o último dia do mês solicitado e o resultado é recortado só
     * com os dias daquele mês.
     *
     * Mês inteiramente no passado, ou tão distante que estouraria
     * {@see self::DIAS_MAXIMOS_DA_GRADE}, devolve lista vazia.
     *
     * `$tipo` não é repassado ao cálculo porque hoje nenhum técnico tem vínculo
     * de especialização com `ServiceType` no modelo de dados, e o próprio
     * `AvailabilityService` documenta que o parâmetro ainda não filtra técnico
     * nenhum. Passar o tipo daria a impressão falsa de uma grade por serviço.
     *
     * @return array<int, array{data: string, manha: array{disponivel: bool}, tarde: array{disponivel: bool}}>
     */
    public function gradeDoMes(Company $empresa, mixed $mes = null): array
    {
        $mes = $this->mesNormalizado($mes);
        $hoje = BusinessDate::hoje();
        $ultimoDiaDoMes = CarbonImmutable::createFromFormat('Y-m-d', $mes.'-01', BusinessDate::fuso())
            ->startOfDay()
            ->endOfMonth()
            ->startOfDay();

        if ($ultimoDiaDoMes->lessThan($hoje)) {
            return [];
        }

        $dias = (int) $hoje->diffInDays($ultimoDiaDoMes) + 1;

        if ($dias > self::DIAS_MAXIMOS_DA_GRADE) {
            return [];
        }

        $grade = $this->disponibilidade->gradeDisponivel($empresa, null, $dias);

        return array_values(array_filter(
            $grade,
            static fn (array $dia): bool => str_starts_with($dia['data'], $mes.'-')
        ));
    }

    /**
     * Mês em `YYYY-MM`, sempre válido.
     *
     * Parâmetro ausente, com formato errado, com mês fora de 01..12 ou vindo
     * como array (`?mes[]=x`) cai no mês corrente, no fuso do negócio. Nenhum
     * erro é devolvido de propósito: `?mes=` é navegação de calendário em página
     * pública, e não formulário.
     */
    public function mesNormalizado(mixed $mes): string
    {
        $texto = is_string($mes) ? trim($mes) : '';

        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $texto) === 1) {
            return $texto;
        }

        return BusinessDate::hoje()->format('Y-m');
    }

    /**
     * Carimbo do instante em que a página foi entregue, para o formulário
     * devolver no envio.
     *
     * Vai cifrado, e não como número solto, porque o valor volta pela mão do
     * visitante: em texto puro bastaria mandar um instante antigo para burlar o
     * tempo mínimo. A criptografia do Laravel é autenticada (AES-256-CBC com
     * MAC), então carimbo adulterado nem decifra.
     *
     * Um robô ainda pode carregar a página, esperar os três segundos e usar o
     * carimbo, ou reaproveitar um carimbo antigo. O tempo mínimo elimina o
     * envio automático imediato, que é o caso comum; o volume é barrado pelo
     * limite de taxa da rota, e o formulário genérico, pelo campo armadilha.
     */
    public function carimboDeCarregamento(): string
    {
        return Crypt::encryptString((string) CarbonImmutable::now()->getTimestamp());
    }

    /**
     * Segundos decorridos desde o carimbo, ou `null` quando ele está ausente,
     * adulterado ou não é um instante.
     *
     * Carimbo com instante no futuro (relógio do servidor alterado, carimbo
     * forjado por sorte) devolve zero, e zero é recusado pelo tempo mínimo.
     */
    public function segundosDesdeOCarregamento(mixed $carimbo): ?int
    {
        if (! is_string($carimbo) || $carimbo === '') {
            return null;
        }

        try {
            $instante = Crypt::decryptString($carimbo);
        } catch (DecryptException) {
            return null;
        }

        if (! ctype_digit($instante)) {
            return null;
        }

        return max(0, CarbonImmutable::now()->getTimestamp() - (int) $instante);
    }

    /**
     * Registra o pedido de horário como `pendente` e avisa a empresa.
     *
     * `$dados` chega já validado por `StoreAppointmentRequestRequest`. Ainda
     * assim, `service_type_id` é reconsultado aqui dentro do tenant (a exigência
     * da skill de multiempresa para id que vem do corpo da requisição): a
     * validação escopada é a primeira barreira, esta é a segunda, e é a que
     * continua valendo no dia em que este Service for chamado de outro lugar.
     *
     * Nenhuma ordem de serviço é criada, por regra do plano. `client_id` pode
     * ser preenchido quando o contato bate com um cliente já cadastrado, e isso
     * nunca aparece na resposta ao visitante (ver `clienteJaCadastrado()`).
     *
     * O limite de pedidos por hora é conferido aqui, e não em middleware, pelo
     * motivo registrado em `recusarAcimaDoLimite()`.
     *
     * @param  array<string, mixed>  $dados
     * @param  string|null  $ip  IP de quem enviou, só para a contagem por hora.
     *
     * @throws RuntimeException Limite de pedidos por hora atingido (IP ou telefone).
     */
    public function registrar(Company $empresa, array $dados, ?string $ip = null): AppointmentRequest
    {
        return TenantAtual::comTenant((int) $empresa->id, function () use ($empresa, $dados, $ip): AppointmentRequest {
            $email = mb_strtolower(trim((string) ($dados['email'] ?? '')));
            $telefone = trim((string) ($dados['telefone'] ?? ''));

            $this->recusarAcimaDoLimite($empresa, $ip, $telefone);

            $pedido = AppointmentRequest::create([
                'client_id' => $this->clienteJaCadastrado($email, $telefone)?->id,
                'nome' => trim((string) ($dados['nome'] ?? '')),
                'email' => $email,
                'telefone' => $telefone,
                'endereco_texto' => trim((string) ($dados['endereco_texto'] ?? '')),
                'service_type_id' => $this->tipoDeServicoDaEmpresa($dados['service_type_id'] ?? null),
                'data_preferida' => $dados['data_preferida'],
                'periodo' => $dados['periodo'],
                'observacao' => filled($dados['observacao'] ?? null)
                    ? trim((string) $dados['observacao'])
                    : null,
                'situacao' => AppointmentRequest::SITUACAO_PENDENTE,
                'origem' => AppointmentRequest::ORIGEM_PAGINA_PUBLICA,
            ]);

            $this->contarPedidoNoLimite($empresa, $ip, $telefone);
            $this->avisarEmpresa($pedido);

            return $pedido;
        });
    }

    /**
     * Recusa o quarto pedido da hora do mesmo IP, e o segundo do mesmo telefone.
     *
     * Por que aqui, e não em `throttle:` na rota: middleware de throttle conta
     * requisição, inclusive a que foi recusada na validação. Com 1 pedido por
     * hora por telefone, o visitante que errasse o e-mail na primeira tentativa
     * ficaria uma hora sem conseguir enviar nada com aquele número, e o
     * formulário viraria uma armadilha para quem está de boa-fé. Contando só
     * pedido que virou linha na tabela, errar o formulário não custa nada e
     * mandar pedido de verdade custa. A rota ainda tem um limite de requisição
     * mais folgado, contra marretada (ver
     * `AppServiceProvider::registrarLimitesDoAgendamentoPublico()`).
     *
     * A contagem é por empresa: o que este limite protege é a fila de pedidos de
     * cada tenant. Chave global por IP também barraria alguém que legitimamente
     * pede horário para duas empresas diferentes no mesmo dia, e o limite de
     * requisição da rota já é global por IP.
     *
     * A mensagem é a mesma nos dois casos, sem dizer se travou por IP ou por
     * telefone. Mensagem específica por telefone contaria a quem digitasse um
     * número qualquer que aquele número pediu horário na última hora, e isso é
     * informação de outra pessoa.
     *
     * @throws RuntimeException
     */
    private function recusarAcimaDoLimite(Company $empresa, ?string $ip, string $telefone): void
    {
        $chaveDoTelefone = $this->chaveDoTelefone($empresa, $telefone);

        $estourou = RateLimiter::tooManyAttempts($this->chaveDoIp($empresa, $ip), self::PEDIDOS_POR_HORA_POR_IP)
            || ($chaveDoTelefone !== null && RateLimiter::tooManyAttempts($chaveDoTelefone, self::PEDIDOS_POR_HORA_POR_TELEFONE));

        if ($estourou) {
            throw new RuntimeException(
                'Já recebemos pedidos demais na última hora. Aguarde um pouco antes de enviar outro, '
                .'ou ligue para a empresa.'
            );
        }
    }

    /**
     * Marca o pedido gravado nas duas contagens da hora.
     */
    private function contarPedidoNoLimite(Company $empresa, ?string $ip, string $telefone): void
    {
        RateLimiter::hit($this->chaveDoIp($empresa, $ip), self::SEGUNDOS_DA_JANELA_DE_LIMITE);

        $chaveDoTelefone = $this->chaveDoTelefone($empresa, $telefone);

        if ($chaveDoTelefone !== null) {
            RateLimiter::hit($chaveDoTelefone, self::SEGUNDOS_DA_JANELA_DE_LIMITE);
        }
    }

    /**
     * Requisição sem IP resolvido (cenário de teste, CLI) cai em uma chave fixa,
     * em vez de ficar sem contagem: sem isso, "sem IP" seria a forma mais fácil
     * de não ter limite nenhum.
     */
    private function chaveDoIp(Company $empresa, ?string $ip): string
    {
        return 'agendamento-publico:'.$empresa->id.':ip:'.(filled($ip) ? $ip : 'sem-ip');
    }

    /**
     * Chave da contagem por telefone, só com os dígitos e só com os 11 últimos,
     * para `+55 (11) 99999-9999` e `11999999999` caírem na mesma contagem.
     *
     * Devolve `null` para o que não tem cara de telefone: aquele envio já vai ser
     * recusado na validação, e criar chave de contagem para lixo só encheria o
     * cache.
     */
    private function chaveDoTelefone(Company $empresa, string $telefone): ?string
    {
        $digitos = preg_replace('/\D+/', '', $telefone) ?? '';

        if (strlen($digitos) < 10) {
            return null;
        }

        return 'agendamento-publico:'.$empresa->id.':telefone:'.substr($digitos, -11);
    }

    /**
     * Tipos de serviço ativos da empresa, só id e nome.
     *
     * `slug`, `description` e `sort_order` ficam de fora: a página precisa
     * montar um seletor, e nada além disso.
     *
     * @return array<int, array{id: int, nome: string}>
     */
    private function tiposDeServico(Company $empresa): array
    {
        return TenantAtual::comTenant((int) $empresa->id, static fn (): array => ServiceType::query()
            ->active()
            ->ordered()
            ->get(['id', 'name'])
            ->map(static fn (ServiceType $tipo): array => [
                'id' => (int) $tipo->id,
                'nome' => $tipo->name,
            ])
            ->all());
    }

    /**
     * Períodos oferecidos, com o rótulo já em português para a página não
     * traduzir valor de enum por conta própria.
     *
     * @return array<int, array{valor: string, rotulo: string}>
     */
    private function periodos(): array
    {
        $periodos = [];

        foreach (AppointmentRequest::PERIODOS as $periodo) {
            $periodos[] = [
                'valor' => $periodo,
                'rotulo' => self::ROTULOS_DE_PERIODO[$periodo] ?? $periodo,
            ];
        }

        return $periodos;
    }

    /**
     * A empresa aceita pedido pela página pública agora?
     *
     * A configuração vive em `company_availability_settings`, uma linha por
     * empresa. Empresa sem linha cai no padrão do sistema, que é
     * `aceita_agendamento_online = false`: página pública é coisa que a empresa
     * liga de propósito, nunca algo que passa a existir sozinho por causa de um
     * deploy.
     */
    private function aceitaAgendamentoOnline(Company $empresa): bool
    {
        return TenantAtual::comTenant((int) $empresa->id, static function (): bool {
            $configuracao = CompanyAvailabilitySetting::query()->first();

            if ($configuracao === null) {
                return (bool) CompanyAvailabilitySetting::padrao()['aceita_agendamento_online'];
            }

            return (bool) $configuracao->aceita_agendamento_online;
        });
    }

    /**
     * Cliente já cadastrado da empresa cujo contato bate com o do pedido.
     *
     * Serve só para o pedido nascer vinculado, poupando a empresa de procurar o
     * cadastro na hora de confirmar. **O resultado nunca vai para a resposta do
     * visitante**: confirmar que um telefone ou um e-mail é cliente de uma
     * empresa específica é vazamento, e ainda serviria de consulta de base para
     * concorrente.
     *
     * E-mail é comparado sem diferenciar maiúsculas. Telefone é comparado por
     * dígitos, porque o cadastro guarda máscara (`(11) 99999-9999`) e o
     * visitante digita como quiser; o `LIKE` pelo fim do número faz `5511...`
     * bater com `11...`. O valor comparado só tem dígitos (a máscara é removida
     * antes), então não há curinga a injetar no padrão.
     *
     * Roda dentro do tenant, então o escopo global de `Client` já garante que a
     * busca não alcança cliente de outra empresa.
     */
    private function clienteJaCadastrado(string $email, string $telefone): ?Client
    {
        if ($email !== '') {
            $porEmail = Client::query()
                ->whereRaw('LOWER(email) = ?', [$email])
                ->first();

            if ($porEmail !== null) {
                return $porEmail;
            }
        }

        $digitos = preg_replace('/\D+/', '', $telefone) ?? '';

        if (strlen($digitos) < 10) {
            return null;
        }

        return Client::query()
            ->whereNotNull('phone')
            ->whereRaw($this->telefoneSemMascara().' LIKE ?', ['%'.substr($digitos, -11)])
            ->first();
    }

    /**
     * Expressão SQL que tira a máscara do telefone gravado, para a comparação
     * por dígitos. `REPLACE` existe igual em MySQL e SQLite, então a mesma
     * expressão serve para produção e para a suíte de testes.
     */
    private function telefoneSemMascara(): string
    {
        $coluna = "COALESCE(clients.phone, '')";

        foreach (['(', ')', '-', ' ', '+', '.'] as $caractere) {
            $coluna = "REPLACE({$coluna}, '{$caractere}', '')";
        }

        return $coluna;
    }

    /**
     * Id do tipo de serviço, reconsultado dentro do tenant. Id de outra
     * empresa, inativo ou inexistente vira `null`, e o pedido segue sem tipo:
     * recusar o pedido inteiro por causa disso puniria o visitante por um dado
     * que a empresa consegue preencher na confirmação.
     */
    private function tipoDeServicoDaEmpresa(mixed $tipoId): ?int
    {
        if (blank($tipoId)) {
            return null;
        }

        return ServiceType::query()->active()->find((int) $tipoId)?->id;
    }

    /**
     * Enfileira o aviso interno de pedido recebido.
     *
     * Só a empresa é avisada. Não existe aviso ao solicitante aqui: o retorno
     * dele é a confirmação de recebimento na própria tela, e a mensagem de
     * verdade sai quando a empresa confirma ou recusa (Task 16.4). Mandar
     * "recebemos seu pedido" por e-mail agora também transformaria a rota em
     * disparador de e-mail para endereço de terceiro.
     */
    private function avisarEmpresa(AppointmentRequest $pedido): void
    {
        $this->notificacoes->enfileirar(EventosDeNotificacao::SOLICITACAO_HORARIO_RECEBIDA, $pedido, [
            'variaveis' => [
                'solicitante_nome' => $pedido->nome,
                'solicitante_telefone' => $pedido->telefone,
                'solicitante_email' => $pedido->email,
                'data_preferida' => $pedido->data_preferida,
                'periodo' => self::ROTULOS_DE_PERIODO[$pedido->periodo] ?? $pedido->periodo,
                'endereco' => $pedido->endereco_texto,
                'tipo_de_servico' => $pedido->serviceType?->name,
                'observacao' => $pedido->observacao,
            ],
        ]);
    }
}
