<?php

namespace App\Services;

use App\Models\AppointmentRequest;
use App\Models\Client;
use App\Models\Company;
use App\Models\SatisfactionSurvey;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

/**
 * Regras da pesquisa de satisfação (Plano 16, Task 16.5): criar a pesquisa da
 * visita concluída, resolver o token da página pública, gravar a resposta e
 * apurar os indicadores do painel.
 *
 * ## O token é a única barreira, e por isso é ele que resolve a empresa
 *
 * `GET /pesquisa/{token}` é rota pública, sem login (`routes/publico.php`).
 * Nessa rota `TenantAtual::id()` devolve `null`, então o escopo global de
 * `BelongsToCompany` não filtra nada, e é `resolverToken()` que estabelece o
 * tenant: ele acha a linha pelo token, e todo o resto do fluxo (marca da
 * empresa, gravação da resposta, aviso de nota baixa) roda dentro de
 * `TenantAtual::comTenant($pesquisa->company_id, ...)`. Mesmo papel que o slug
 * cumpre em `PublicSchedulingService`.
 *
 * A busca pelo token é a única consulta daqui que sai explicitamente do escopo
 * por empresa (`deTodasAsEmpresas()`), e o motivo está no método: sem isso, a
 * resolução passaria a depender de quem está autenticado no navegador, porque a
 * página pública pode ser aberta na mesma sessão de um funcionário logado no
 * painel de outra empresa. Um token só alcança a própria linha: o valor é único
 * na tabela inteira e tem 64 caracteres sorteados.
 *
 * ## Um envio por visita, e no máximo um por cliente a cada 30 dias
 *
 * `work_order_id` é unique na tabela, o que já garante uma pesquisa por visita.
 * O limite de 30 dias por cliente é regra de negócio conferida aqui: cliente com
 * contrato de visita semanal receberia quatro pesquisas por mês, pararia de
 * responder, e o indicador morreria junto.
 *
 * ## Nota baixa nunca gera resposta automática ao cliente
 *
 * Nota 1 ou 2 marca `pendencia_de_contato` e enfileira `nota_baixa_recebida`
 * para a **empresa**, com o comentário. Quem resolve insatisfação é pessoa.
 *
 * ## Média com menos de 3 respostas é omitida
 *
 * Vale para todo corte de `indicadores()`, inclusive a média geral. Técnico
 * avaliado por uma nota isolada é injustiça, e um indicador assim perde a
 * credibilidade na primeira conversa difícil.
 */
class SatisfactionSurveyService
{
    /**
     * Caracteres do token. Precisa ser grande: ele é a única credencial da
     * resposta, e token curto seria adivinhável por varredura.
     */
    public const TAMANHO_DO_TOKEN = 64;

    /**
     * Dias de validade do link, contados do dia da criação. `expira_em` é o
     * último dia em que a pesquisa aceita resposta, mesma convenção de
     * vencimento do resto do sistema (o que vence hoje não está vencido, ver
     * `BusinessDate::estaVencido()`).
     */
    public const DIAS_DE_VALIDADE = 30;

    /**
     * Janela mínima entre duas pesquisas do mesmo cliente, em dias corridos.
     */
    public const DIAS_ENTRE_PESQUISAS = 30;

    public const NOTA_MINIMA = 1;

    public const NOTA_MAXIMA = 5;

    /**
     * Até esta nota a resposta vira pendência de contato para a empresa.
     */
    public const NOTA_MAXIMA_DE_PENDENCIA = 2;

    /**
     * Teto do comentário. Campo `text` no banco; o limite existe para a página
     * pública não virar depósito de texto.
     */
    public const TAMANHO_MAXIMO_DO_COMENTARIO = 1000;

    /**
     * Respostas necessárias para o corte exibir média.
     */
    public const MINIMO_DE_RESPOSTAS_PARA_MEDIA = 3;

    /**
     * Janela padrão de `indicadores()` quando nenhuma data é informada: o mês
     * corrente e os onze anteriores, que é o que a evolução por mês da tela
     * mostra sem virar consulta de histórico inteiro.
     */
    public const MESES_PADRAO_DOS_INDICADORES = 12;

    /**
     * Status de `work_orders` que representa visita concluída.
     */
    public const STATUS_DE_OS_CONCLUIDA = 'completed';

    /** Token com formato válido, dentro do prazo e sem resposta: pode responder. */
    public const ESTADO_DISPONIVEL = 'disponivel';

    /** Token com formato errado ou que não existe. */
    public const ESTADO_INVALIDA = 'invalida';

    /** Passou de `expira_em`. */
    public const ESTADO_EXPIRADA = 'expirada';

    /** Já tem resposta gravada. */
    public const ESTADO_RESPONDIDA = 'respondida';

    /**
     * Texto de cada estado do token, em português e sem tom de erro de sistema:
     * quem lê é o cliente final da empresa, que só clicou em um link.
     *
     * Nenhum texto revela dado do cliente nem da visita, porque dois deles
     * aparecem antes de o sistema ter certeza de quem abriu o link.
     *
     * @var array<string, array{titulo: string, mensagem: string}>
     */
    public const TEXTOS_DOS_ESTADOS = [
        self::ESTADO_DISPONIVEL => [
            'titulo' => 'Como foi o atendimento?',
            'mensagem' => 'Sua nota ajuda a empresa a melhorar o serviço. Leva menos de um minuto.',
        ],
        self::ESTADO_INVALIDA => [
            'titulo' => 'Link não encontrado',
            'mensagem' => 'Este link de pesquisa não existe. Confira se ele foi copiado inteiro, '
                .'ou fale com a empresa que fez o atendimento.',
        ],
        self::ESTADO_EXPIRADA => [
            'titulo' => 'Prazo encerrado',
            'mensagem' => 'O prazo para responder esta pesquisa terminou. Se quiser falar sobre o '
                .'atendimento, entre em contato com a empresa.',
        ],
        self::ESTADO_RESPONDIDA => [
            'titulo' => 'Resposta já registrada',
            'mensagem' => 'Esta pesquisa já foi respondida. Obrigado pela avaliação.',
        ],
    ];

    /** Pesquisa criada e convite enfileirado. */
    public const RESULTADO_CRIADA = 'criada';

    /** Esta visita já tinha pesquisa. Nada a fazer, e a rotina pode repetir. */
    public const RESULTADO_JA_EXISTIA = 'ja_existia';

    /** A visita não está concluída. */
    public const RESULTADO_OS_NAO_CONCLUIDA = 'os_nao_concluida';

    /** A visita não tem cliente, ou o cliente não tem e-mail nem telefone. */
    public const RESULTADO_SEM_CONTATO = 'sem_contato';

    /** O cliente desligou os canais pelos quais o convite poderia sair. */
    public const RESULTADO_CANAL_RECUSADO = 'canal_recusado';

    /** O cliente respondeu, ou recebeu, uma pesquisa nos últimos 30 dias. */
    public const RESULTADO_PESQUISA_RECENTE = 'pesquisa_recente';

    /** A central de notificações não aceitou o convite. Nada é gravado. */
    public const RESULTADO_SEM_ENVIO = 'sem_envio';

    public function __construct(
        private readonly NotificationService $notificacoes,
    ) {}

    /**
     * Cria a pesquisa da visita e enfileira o convite ao cliente.
     *
     * Roda dentro do tenant da própria OS, e não do contexto de quem chamou: a
     * rotina já entra em `comTenant()` por empresa (`OperaPorTenant`), mas o
     * Service não pode depender disso para gravar no lugar certo.
     *
     * Não cria pesquisa quando:
     *
     * - a visita não está concluída;
     * - a visita já tem pesquisa (uma por visita, garantido também pelo unique);
     * - a visita não tem cliente, ou o cliente não tem e-mail nem telefone;
     * - o cliente desligou os canais que poderiam levar o convite;
     * - o cliente respondeu ou recebeu pesquisa nos últimos 30 dias.
     *
     * A criação e o convite são atômicos: convite recusado pela central desfaz a
     * pesquisa, porque token que ninguém recebeu só serviria para ocupar a cota
     * de 30 dias do cliente e nunca ser respondido.
     *
     * @return array{resultado: string, criada: bool, pesquisa: SatisfactionSurvey|null, mensagem: string}
     */
    public function criarParaVisita(WorkOrder $os): array
    {
        return TenantAtual::comTenant((int) $os->company_id, function () use ($os): array {
            if ($os->status !== self::STATUS_DE_OS_CONCLUIDA) {
                return $this->resultadoDaCriacao(
                    self::RESULTADO_OS_NAO_CONCLUIDA,
                    mensagem: 'A ordem de serviço não está concluída.'
                );
            }

            $existente = SatisfactionSurvey::query()->where('work_order_id', $os->id)->first();

            if ($existente !== null) {
                return $this->resultadoDaCriacao(
                    self::RESULTADO_JA_EXISTIA,
                    pesquisa: $existente,
                    mensagem: 'Esta visita já tem pesquisa.'
                );
            }

            $cliente = $os->client;

            if ($cliente === null || ! $this->temContato($cliente)) {
                return $this->resultadoDaCriacao(
                    self::RESULTADO_SEM_CONTATO,
                    mensagem: 'A visita não tem cliente com e-mail ou telefone para receber a pesquisa.'
                );
            }

            $canal = $this->canalDoConvite($cliente);

            if ($canal === null) {
                return $this->resultadoDaCriacao(
                    self::RESULTADO_CANAL_RECUSADO,
                    mensagem: "O cliente {$cliente->name} não aceita receber avisos pelos canais disponíveis."
                );
            }

            if ($this->recebeuPesquisaRecente($cliente)) {
                return $this->resultadoDaCriacao(
                    self::RESULTADO_PESQUISA_RECENTE,
                    mensagem: 'O cliente já teve pesquisa nos últimos '.self::DIAS_ENTRE_PESQUISAS.' dias.'
                );
            }

            return DB::transaction(function () use ($os, $cliente, $canal): array {
                $pesquisa = SatisfactionSurvey::create([
                    'work_order_id' => $os->id,
                    'client_id' => $cliente->id,
                    'technician_id' => $os->technician_id,
                    'service_type_id' => $this->tipoDeServicoDaVisita($os),
                    'token' => $this->tokenUnico(),
                    'enviada_em' => now(),
                    'expira_em' => BusinessDate::hoje()->addDays(self::DIAS_DE_VALIDADE)->toDateString(),
                    'pendencia_de_contato' => false,
                ]);

                $aviso = $this->convidar($pesquisa, $os, $canal);

                if (! in_array($aviso['resultado'], [
                    NotificationService::RESULTADO_ENFILEIRADA,
                    NotificationService::RESULTADO_DUPLICADA,
                ], true)) {
                    // Sem convite não existe pesquisa: o token ficaria ocupando a
                    // cota de 30 dias do cliente sem ninguém para respondê-lo.
                    // Desfeita agora, a visita volta a ser candidata em uma
                    // execução futura da rotina.
                    $pesquisa->delete();

                    Log::warning('Pesquisa de satisfação desfeita porque o convite não foi enfileirado.', [
                        'work_order_id' => $os->id,
                        'canal' => $canal,
                        'resultado' => $aviso['resultado'],
                        'mensagem' => $aviso['mensagem'],
                    ]);

                    return $this->resultadoDaCriacao(
                        self::RESULTADO_SEM_ENVIO,
                        mensagem: 'O convite da pesquisa não pôde ser enfileirado: '.$aviso['mensagem']
                    );
                }

                return $this->resultadoDaCriacao(
                    self::RESULTADO_CRIADA,
                    criada: true,
                    pesquisa: $pesquisa,
                    mensagem: 'Pesquisa criada e convite enfileirado por '.$canal.'.'
                );
            });
        });
    }

    /**
     * Estado do token da página pública.
     *
     * Devolve sempre um dos quatro estados, nunca 404 e nunca exceção: cada um
     * tem página e texto próprios (Task 16.5), porque quem cai aqui é o cliente
     * final que clicou em um link, e uma tela crua de erro não diz o que fazer.
     *
     * Ordem da decisão: resposta já gravada vence prazo vencido. Pesquisa
     * respondida há meses continua sendo "já respondida", que é a informação
     * útil para quem clicou de novo no link antigo.
     *
     * @return array{estado: string, pesquisa: SatisfactionSurvey|null, empresa: Company|null}
     */
    public function resolverToken(mixed $token): array
    {
        $token = is_string($token) ? trim($token) : '';

        // Formato conferido antes de qualquer consulta: token com outro tamanho
        // ou com caractere fora do alfabeto de `Str::random()` não pode existir na
        // tabela, e varredura de URL não precisa custar uma consulta cada.
        if (preg_match('/^[A-Za-z0-9]{'.self::TAMANHO_DO_TOKEN.'}$/', $token) !== 1) {
            return $this->resolucao(self::ESTADO_INVALIDA);
        }

        // `deTodasAsEmpresas()`: saída explícita do escopo por empresa, e o único
        // ponto deste Service que faz isso. Na rota pública não há tenant
        // resolvido, então o escopo não filtraria nada de qualquer forma; o que a
        // saída explícita garante é que a resolução **nunca** dependa de quem
        // está autenticado no navegador. Sem ela, o mesmo link aberto na aba ao
        // lado do painel de outra empresa deixaria de abrir, e a empresa da
        // pesquisa passaria a vir da sessão em vez do token. O token é único na
        // tabela inteira, então esta consulta alcança uma linha ou nenhuma.
        $pesquisa = SatisfactionSurvey::query()
            ->deTodasAsEmpresas()
            ->where('token', $token)
            ->first();

        if ($pesquisa === null) {
            return $this->resolucao(self::ESTADO_INVALIDA);
        }

        // Daqui para baixo, tudo dentro do tenant da pesquisa: as relações
        // carregadas abaixo têm escopo global próprio, e fora do contexto certo
        // voltariam nulas para a pesquisa de outra empresa.
        return TenantAtual::comTenant((int) $pesquisa->company_id, function () use ($pesquisa): array {
            $pesquisa->load(['serviceType', 'workOrder.service']);

            $empresa = Company::query()->find($pesquisa->company_id);

            if ($pesquisa->respondida_em !== null) {
                return $this->resolucao(self::ESTADO_RESPONDIDA, $pesquisa, $empresa);
            }

            if (BusinessDate::estaVencido($pesquisa->expira_em)) {
                return $this->resolucao(self::ESTADO_EXPIRADA, $pesquisa, $empresa);
            }

            return $this->resolucao(self::ESTADO_DISPONIVEL, $pesquisa, $empresa);
        });
    }

    /**
     * O que a página pública recebe, para cada estado do token.
     *
     * O recorte é a regra mais importante desta task no lado da exibição: sai a
     * marca da empresa (que ela divulga por vontade própria) e o serviço
     * avaliado, e **nada mais**. Nem nome do cliente, nem endereço, nem número
     * de OS, nem data, nem histórico, nem qualquer outra visita. O token dá
     * acesso a uma pesquisa, e a nada além dela.
     *
     * `token` só sai no estado disponível, porque é o único em que o formulário
     * existe.
     *
     * @param  array{estado: string, pesquisa: SatisfactionSurvey|null, empresa: Company|null}  $resolucao
     * @return array<string, mixed>
     */
    public function dadosDaPagina(array $resolucao): array
    {
        $estado = $resolucao['estado'];
        $pesquisa = $resolucao['pesquisa'];
        $empresa = $resolucao['empresa'];
        $textos = self::TEXTOS_DOS_ESTADOS[$estado] ?? self::TEXTOS_DOS_ESTADOS[self::ESTADO_INVALIDA];

        return [
            'estado' => $estado,
            'titulo' => $textos['titulo'],
            'mensagem' => $textos['mensagem'],
            // Mesmo recorte de marca do portal do cliente (Task 15.6) e da página
            // de agendamento (Task 16.3): nome, logo, cores e contato.
            'empresa' => $empresa?->brandingDoPortal(),
            'servico_avaliado' => $pesquisa === null ? null : $this->nomeDoServicoAvaliado($pesquisa),
            'token' => $estado === self::ESTADO_DISPONIVEL ? $pesquisa?->token : null,
            'nota_minima' => self::NOTA_MINIMA,
            'nota_maxima' => self::NOTA_MAXIMA,
            'nota_maxima_de_contato' => self::NOTA_MAXIMA_DE_PENDENCIA,
            'limite_do_comentario' => self::TAMANHO_MAXIMO_DO_COMENTARIO,
        ];
    }

    /**
     * Grava a resposta e, se a nota for baixa, abre a pendência de contato.
     *
     * A pesquisa é relida com `lockForUpdate()` dentro da transação antes de
     * qualquer decisão: dois cliques no mesmo botão, ou o link aberto em duas
     * abas, poderiam passar os dois pela checagem de "sem resposta" antes de
     * qualquer um gravar, e a segunda gravação sobrescreveria a nota da primeira.
     * Com o lock, a segunda enxerga a resposta já gravada e **não sobrescreve
     * nada**: a primeira resposta é a que vale.
     *
     * @throws InvalidArgumentException Nota fora de 1 a 5.
     */
    public function responder(SatisfactionSurvey $pesquisa, int $nota, ?string $comentario = null): SatisfactionSurvey
    {
        if ($nota < self::NOTA_MINIMA || $nota > self::NOTA_MAXIMA) {
            throw new InvalidArgumentException(
                'A nota precisa estar entre '.self::NOTA_MINIMA.' e '.self::NOTA_MAXIMA.". Recebida: {$nota}."
            );
        }

        return TenantAtual::comTenant(
            (int) $pesquisa->company_id,
            fn (): SatisfactionSurvey => DB::transaction(function () use ($pesquisa, $nota, $comentario): SatisfactionSurvey {
                $atual = SatisfactionSurvey::query()
                    ->whereKey($pesquisa->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($atual->respondida_em !== null) {
                    return $atual;
                }

                $notaBaixa = $nota <= self::NOTA_MAXIMA_DE_PENDENCIA;

                $atual->update([
                    'nota' => $nota,
                    'comentario' => filled($comentario) ? trim((string) $comentario) : null,
                    'respondida_em' => now(),
                    'pendencia_de_contato' => $notaBaixa,
                ]);

                $atual = $atual->fresh();

                if ($notaBaixa) {
                    $this->avisarNotaBaixa($atual);
                }

                return $atual;
            })
        );
    }

    /**
     * Marca que a empresa já entrou em contato por causa da nota baixa (Task
     * 16.7): fecha a pendência (`pendencia_de_contato = false`) e grava
     * `contato_feito_em`. Idempotente - chamar de novo numa pesquisa sem
     * pendência não faz nada, para dois cliques (ou duas abas abertas) não
     * sobrescreverem o instante do primeiro contato.
     *
     * @throws InvalidArgumentException Pesquisa ainda sem resposta: não existe nota baixa nenhuma para dar baixa de contato.
     */
    public function marcarContatoFeito(SatisfactionSurvey $pesquisa): SatisfactionSurvey
    {
        if ($pesquisa->respondida_em === null) {
            throw new InvalidArgumentException('Esta pesquisa ainda não foi respondida.');
        }

        if (! $pesquisa->pendencia_de_contato) {
            return $pesquisa;
        }

        $pesquisa->update([
            'pendencia_de_contato' => false,
            'contato_feito_em' => now(),
        ]);

        return $pesquisa->fresh();
    }

    /**
     * Notas baixas (1 ou 2) com pendência de contato ainda aberta, na empresa
     * corrente, mais recentes primeiro: a lista de trabalho do painel de
     * satisfação (Task 16.7).
     *
     * @return Collection<int, SatisfactionSurvey>
     */
    public function pendenciasDeContato(): Collection
    {
        return SatisfactionSurvey::query()
            ->where('pendencia_de_contato', true)
            ->with(['client', 'technician', 'serviceType', 'workOrder'])
            ->latest('respondida_em')
            ->get();
    }

    /**
     * Indicadores do painel de satisfação, na empresa corrente.
     *
     * Diferente dos métodos da página pública, este roda no tenant de quem
     * chamou: quem consome é a tela interna, autenticada (Task 16.7), e o escopo
     * global de `SatisfactionSurvey` já isola a empresa. Nenhum `company_id`
     * é aceito em `$filtros`.
     *
     * Filtros aceitos, todos opcionais:
     *
     * - `de` e `ate` (`Y-m-d`): recorte de dias, no fuso do negócio. Sem eles,
     *   vale o mês corrente e os onze anteriores.
     * - `technician_id` e `service_type_id`: restringem a apuração inteira.
     *
     * Só resposta gravada entra na conta. Pesquisa enviada e não respondida não
     * é nota zero: ela simplesmente não é informação sobre satisfação.
     *
     * Todo corte devolve `respostas` e, quando há pelo menos
     * {@see self::MINIMO_DE_RESPOSTAS_PARA_MEDIA}, também `media`. Abaixo disso a
     * média sai `null` com `media_omitida = true`, e a contagem continua visível.
     *
     * @param  array<string, mixed>  $filtros
     * @return array<string, mixed>
     */
    public function indicadores(array $filtros = []): array
    {
        [$de, $ate] = $this->janelaDosIndicadores($filtros);

        $fusoDaAplicacao = $this->fusoDaAplicacao();

        $consulta = SatisfactionSurvey::query()
            ->whereNotNull('respondida_em')
            ->whereNotNull('nota')
            ->whereBetween('respondida_em', [
                $de->startOfDay()->setTimezone($fusoDaAplicacao),
                $ate->endOfDay()->setTimezone($fusoDaAplicacao),
            ])
            ->with(['technician', 'serviceType']);

        if (filled($filtros['technician_id'] ?? null)) {
            $consulta->where('technician_id', (int) $filtros['technician_id']);
        }

        if (filled($filtros['service_type_id'] ?? null)) {
            $consulta->where('service_type_id', (int) $filtros['service_type_id']);
        }

        $respostas = $consulta->get([
            'id',
            'company_id',
            'technician_id',
            'service_type_id',
            'nota',
            'respondida_em',
        ]);

        return [
            'periodo' => ['de' => $de->toDateString(), 'ate' => $ate->toDateString()],
            'minimo_de_respostas' => self::MINIMO_DE_RESPOSTAS_PARA_MEDIA,
            'geral' => $this->corte($respostas->pluck('nota')->all()),
            'por_periodo' => $this->porPeriodo($respostas),
            'por_tecnico' => $this->porTecnico($respostas),
            'por_tipo_de_servico' => $this->porTipoDeServico($respostas),
        ];
    }

    /**
     * Endereço absoluto da página de resposta. Absoluto porque vai para dentro de
     * um e-mail ou de uma mensagem de WhatsApp.
     */
    public function linkDaPesquisa(SatisfactionSurvey $pesquisa): string
    {
        return route('publico.pesquisa.show', ['token' => $pesquisa->token]);
    }

    // -----------------------------------------------------------------
    // Criação: contato, canal e janela de 30 dias
    // -----------------------------------------------------------------

    /**
     * O cliente tem algum endereço para receber o convite?
     *
     * `email_notificacao` antes de `email` pelo mesmo motivo do
     * `NotificationService`: em pessoa jurídica o e-mail cadastral costuma ser o
     * da nota fiscal, e quem lê aviso é outra pessoa.
     */
    private function temContato(Client $cliente): bool
    {
        return filled($this->emailDoCliente($cliente)) || filled($cliente->phone);
    }

    private function emailDoCliente(Client $cliente): ?string
    {
        return $cliente->email_notificacao ?: $cliente->email;
    }

    /**
     * Canal pelo qual o convite pode sair, ou `null` quando o cliente recusou
     * todos os que teriam endereço.
     *
     * Ordem: canal preferido do cliente, e-mail, WhatsApp. O canal é resolvido
     * aqui e informado ao `NotificationService`, em vez de deixar que ele escolha:
     * um cliente que desligou e-mail e aceita WhatsApp receberia `recusada` se o
     * canal fosse decidido lá, porque o padrão de lá é e-mail.
     *
     * Preferência ausente é lida como aceite, mesmo critério do padrão da coluna
     * e do `NotificationService`.
     */
    private function canalDoConvite(Client $cliente): ?string
    {
        $disponiveis = [];

        if (($cliente->aceita_email ?? true) && filled($this->emailDoCliente($cliente))) {
            $disponiveis[] = EventosDeNotificacao::CANAL_EMAIL;
        }

        if (($cliente->aceita_whatsapp ?? true) && filled($cliente->phone)) {
            $disponiveis[] = EventosDeNotificacao::CANAL_WHATSAPP;
        }

        if ($disponiveis === []) {
            return null;
        }

        $preferido = $cliente->canal_preferido;

        if (filled($preferido) && in_array($preferido, $disponiveis, true)) {
            return $preferido;
        }

        return in_array(EventosDeNotificacao::CANAL_EMAIL, $disponiveis, true)
            ? EventosDeNotificacao::CANAL_EMAIL
            : $disponiveis[0];
    }

    /**
     * O cliente respondeu, ou recebeu, pesquisa nos últimos 30 dias?
     *
     * As duas datas contam. A regra do plano é "no máximo uma a cada 30 dias por
     * cliente": olhar só a resposta deixaria o cliente que nunca responde
     * recebendo uma por visita, que é exatamente o excesso que a regra evita.
     *
     * A comparação é instante contra instante, sem passar pelo dia do negócio:
     * `respondida_em` e `enviada_em` são instantes gravados em UTC, e 30 dias
     * corridos são os mesmos 30 dias em qualquer fuso. Converter para dia aqui
     * só criaria oportunidade de erro sem mudar o resultado.
     */
    private function recebeuPesquisaRecente(Client $cliente): bool
    {
        $limite = CarbonImmutable::now($this->fusoDaAplicacao())->subDays(self::DIAS_ENTRE_PESQUISAS);

        return SatisfactionSurvey::query()
            ->where('client_id', $cliente->id)
            ->where(function ($consulta) use ($limite): void {
                $consulta->where('respondida_em', '>=', $limite)
                    ->orWhere('enviada_em', '>=', $limite);
            })
            ->exists();
    }

    /**
     * Tipo de serviço da visita, quando existe vínculo estruturado.
     *
     * `work_orders` não tem coluna de tipo de serviço (`service_type_id` foi
     * removida da tabela em 2025_10_14), então o único vínculo confiável hoje é o
     * do pedido de horário que gerou a OS (Tasks 16.3 e 16.4). Visita sem esse
     * vínculo fica sem tipo, e o corte por tipo de serviço do painel a agrupa
     * como "sem tipo informado" em vez de inventar uma classificação.
     */
    private function tipoDeServicoDaVisita(WorkOrder $os): ?int
    {
        $tipo = AppointmentRequest::query()
            ->where('work_order_id', $os->id)
            ->whereNotNull('service_type_id')
            ->value('service_type_id');

        return $tipo === null ? null : (int) $tipo;
    }

    /**
     * Token de 64 caracteres que ainda não existe na tabela.
     *
     * A colisão de `Str::random(64)` é desprezível, e a conferência existe porque
     * o custo dela é uma consulta indexada e o custo de errar é o cliente
     * respondendo a pesquisa de outra pessoa. A busca sai do escopo por empresa
     * de propósito: o unique da coluna é da tabela inteira, e conferir só dentro
     * do tenant deixaria passar a única colisão que importa, a que cruza empresas.
     *
     * @throws RuntimeException Cinco sorteios seguidos colidindo, o que na
     *                          prática significa gerador de aleatoriedade quebrado.
     */
    private function tokenUnico(): string
    {
        for ($tentativa = 1; $tentativa <= 5; $tentativa++) {
            $token = Str::random(self::TAMANHO_DO_TOKEN);

            $existe = SatisfactionSurvey::query()
                ->deTodasAsEmpresas()
                ->where('token', $token)
                ->exists();

            if (! $existe) {
                return $token;
            }
        }

        throw new RuntimeException(
            'Não foi possível sortear um token livre para a pesquisa de satisfação depois de cinco tentativas.'
        );
    }

    /**
     * Enfileira o convite ao cliente, com o link da página pública.
     *
     * `agendada_para` é o dia de hoje, sem hora: a central entende dia como
     * "manhã no fuso do negócio" (`NotificationService::HORA_PADRAO_DE_ENVIO`).
     * A rotina roda de madrugada ou no começo da manhã, e pesquisa que chega às
     * 05h no WhatsApp do cliente incomoda em vez de ser respondida.
     *
     * @return array<string, mixed>
     */
    private function convidar(SatisfactionSurvey $pesquisa, WorkOrder $os, string $canal): array
    {
        return $this->notificacoes->enfileirar(EventosDeNotificacao::PESQUISA_SATISFACAO, $pesquisa, [
            'canal' => $canal,
            'agendada_para' => BusinessDate::hoje()->toDateString(),
            'variaveis' => [
                'os_numero' => $os->order_number,
                'data_execucao' => $os->end_time ?? $os->scheduled_date,
                'tecnico_nome' => $os->technician?->name,
                'link_pesquisa' => $this->linkDaPesquisa($pesquisa),
                'dias_de_validade' => self::DIAS_DE_VALIDADE,
            ],
        ]);
    }

    /**
     * Avisa a empresa da nota baixa, com o comentário.
     *
     * Nada é enviado ao cliente: ele já disse o que tinha para dizer, e a
     * resposta que importa é o telefonema de uma pessoa. Ver o docblock do evento.
     *
     * Comentário vazio vira um texto explícito em vez de linha em branco no
     * e-mail: quem lê precisa saber que o cliente não escreveu nada, e não achar
     * que a mensagem chegou truncada.
     */
    private function avisarNotaBaixa(SatisfactionSurvey $pesquisa): void
    {
        $os = $pesquisa->workOrder;

        $this->notificacoes->enfileirar(EventosDeNotificacao::NOTA_BAIXA_RECEBIDA, $pesquisa, [
            'variaveis' => [
                'nota' => $pesquisa->nota,
                'comentario' => filled($pesquisa->comentario)
                    ? $pesquisa->comentario
                    : 'o cliente não deixou comentário.',
                'os_numero' => $os?->order_number,
                'tecnico_nome' => $pesquisa->technician?->name ?? 'não informado',
                'tipo_de_servico' => $this->nomeDoServicoAvaliado($pesquisa) ?? 'não informado',
            ],
        ]);
    }

    /**
     * Nome do serviço avaliado, para a página pública e para o aviso interno.
     *
     * Tipo de serviço do pedido de horário primeiro, serviço da OS depois. Sem
     * nenhum dos dois devolve `null`, e a página fala do "atendimento" sem
     * inventar nome de serviço.
     */
    private function nomeDoServicoAvaliado(SatisfactionSurvey $pesquisa): ?string
    {
        $nome = $pesquisa->serviceType?->name ?? $pesquisa->workOrder?->service?->name;

        return filled($nome) ? (string) $nome : null;
    }

    // -----------------------------------------------------------------
    // Indicadores
    // -----------------------------------------------------------------

    /**
     * Primeiro e último dia da apuração, no fuso do negócio.
     *
     * Datas invertidas são trocadas em vez de devolverem apuração vazia: o filtro
     * vem de tela, e campo preenchido na ordem errada é engano de quem digitou,
     * não pedido de "nenhum resultado".
     *
     * @param  array<string, mixed>  $filtros
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function janelaDosIndicadores(array $filtros): array
    {
        $hoje = BusinessDate::hoje();

        $de = BusinessDate::paraFusoNegocio($filtros['de'] ?? null)
            ?? $hoje->startOfMonth()->subMonths(self::MESES_PADRAO_DOS_INDICADORES - 1);

        $ate = BusinessDate::paraFusoNegocio($filtros['ate'] ?? null) ?? $hoje;

        return $de->greaterThan($ate) ? [$ate, $de] : [$de, $ate];
    }

    /**
     * Um corte da apuração: contagem sempre, média só com o mínimo de respostas.
     *
     * @param  array<int, int|null>  $notas
     * @return array{respostas: int, media: float|null, media_omitida: bool}
     */
    private function corte(array $notas): array
    {
        $notas = array_values(array_map('intval', array_filter($notas, static fn ($nota): bool => $nota !== null)));
        $respostas = count($notas);
        $temMedia = $respostas >= self::MINIMO_DE_RESPOSTAS_PARA_MEDIA;

        return [
            'respostas' => $respostas,
            'media' => $temMedia ? round(array_sum($notas) / $respostas, 2) : null,
            'media_omitida' => ! $temMedia,
        ];
    }

    /**
     * Evolução por mês, do mais antigo para o mais recente.
     *
     * Mês sem resposta não aparece: linha vazia na tabela não é informação, e o
     * gráfico da tela liga os pontos que existem.
     *
     * @param  Collection<int, SatisfactionSurvey>  $respostas
     * @return array<int, array<string, mixed>>
     */
    private function porPeriodo(Collection $respostas): array
    {
        $grupos = [];

        foreach ($respostas as $resposta) {
            $mes = $this->mesDaResposta($resposta->respondida_em);
            $grupos[$mes][] = $resposta->nota;
        }

        ksort($grupos);

        $linhas = [];

        foreach ($grupos as $mes => $notas) {
            [$ano, $numeroDoMes] = explode('-', (string) $mes);

            $linhas[] = array_merge([
                'periodo' => $mes,
                'rotulo' => $numeroDoMes.'/'.$ano,
            ], $this->corte($notas));
        }

        return $linhas;
    }

    /**
     * Mês da resposta no fuso do negócio, em `Y-m`.
     *
     * `setTimezone` direto, e não `BusinessDate::paraFusoNegocio()`: aquele
     * método trata instante à meia-noite exata como dia puro, e uma resposta
     * gravada exatamente às 00:00:00 em UTC (21h de Brasília do dia anterior)
     * cairia no mês seguinte na virada. Aqui o valor é sempre instante, então a
     * conversão é `setTimezone` puro, o mesmo critério documentado em
     * `DisponibilidadeService`.
     */
    private function mesDaResposta(?DateTimeInterface $instante): string
    {
        if ($instante === null) {
            return '';
        }

        return CarbonImmutable::instance($instante)->setTimezone(BusinessDate::fuso())->format('Y-m');
    }

    /**
     * Corte por técnico, do mais avaliado para o menos avaliado.
     *
     * @param  Collection<int, SatisfactionSurvey>  $respostas
     * @return array<int, array<string, mixed>>
     */
    private function porTecnico(Collection $respostas): array
    {
        $grupos = [];

        foreach ($respostas as $resposta) {
            $id = $resposta->technician_id === null ? 0 : (int) $resposta->technician_id;

            $grupos[$id]['rotulo'] ??= $this->rotuloDoTecnico($resposta);
            $grupos[$id]['notas'][] = $resposta->nota;
        }

        return $this->linhasDoCorte($grupos, 'technician_id', 'tecnico');
    }

    /**
     * Corte por tipo de serviço.
     *
     * A visita sem tipo vinculado entra como "sem tipo informado", e não fora da
     * apuração: omitir faria a soma dos cortes não fechar com a média geral, e a
     * empresa concluiria que o painel está errado.
     *
     * @param  Collection<int, SatisfactionSurvey>  $respostas
     * @return array<int, array<string, mixed>>
     */
    private function porTipoDeServico(Collection $respostas): array
    {
        $grupos = [];

        foreach ($respostas as $resposta) {
            $id = $resposta->service_type_id === null ? 0 : (int) $resposta->service_type_id;

            $grupos[$id]['rotulo'] ??= $resposta->serviceType?->name ?? 'Sem tipo informado';
            $grupos[$id]['notas'][] = $resposta->nota;
        }

        return $this->linhasDoCorte($grupos, 'service_type_id', 'tipo_de_servico');
    }

    /**
     * Técnico removido do cadastro continua aparecendo, sem nome: a resposta já
     * dada não deixa de existir porque o profissional saiu, e apagá-la do corte
     * mudaria a média histórica da empresa.
     */
    private function rotuloDoTecnico(SatisfactionSurvey $resposta): string
    {
        if ($resposta->technician_id === null) {
            return 'Sem técnico informado';
        }

        return $resposta->technician?->name ?? 'Técnico removido';
    }

    /**
     * Monta as linhas de um corte agrupado, ordenadas por quantidade de respostas
     * e, no empate, pelo rótulo. Ordenar por média deixaria o corte sem média
     * (menos de 3 respostas) em posição arbitrária.
     *
     * @param  array<int, array{rotulo?: string, notas?: array<int, int|null>}>  $grupos
     * @return array<int, array<string, mixed>>
     */
    private function linhasDoCorte(array $grupos, string $chaveDoId, string $chaveDoRotulo): array
    {
        $linhas = [];

        foreach ($grupos as $id => $grupo) {
            $linhas[] = array_merge([
                $chaveDoId => $id === 0 ? null : $id,
                $chaveDoRotulo => $grupo['rotulo'] ?? '',
            ], $this->corte($grupo['notas'] ?? []));
        }

        usort($linhas, static function (array $a, array $b) use ($chaveDoRotulo): int {
            return $b['respostas'] <=> $a['respostas']
                ?: strcmp((string) $a[$chaveDoRotulo], (string) $b[$chaveDoRotulo]);
        });

        return $linhas;
    }

    // -----------------------------------------------------------------
    // Formatos de retorno
    // -----------------------------------------------------------------

    /**
     * Fuso em que a aplicação grava `datetime`, que é como as colunas de instante
     * precisam ser comparadas na consulta.
     */
    private function fusoDaAplicacao(): string
    {
        return config('app.timezone') ?: 'UTC';
    }

    /**
     * @return array{estado: string, pesquisa: SatisfactionSurvey|null, empresa: Company|null}
     */
    private function resolucao(
        string $estado,
        ?SatisfactionSurvey $pesquisa = null,
        ?Company $empresa = null
    ): array {
        return ['estado' => $estado, 'pesquisa' => $pesquisa, 'empresa' => $empresa];
    }

    /**
     * @return array{resultado: string, criada: bool, pesquisa: SatisfactionSurvey|null, mensagem: string}
     */
    private function resultadoDaCriacao(
        string $resultado,
        bool $criada = false,
        ?SatisfactionSurvey $pesquisa = null,
        string $mensagem = ''
    ): array {
        return [
            'resultado' => $resultado,
            'criada' => $criada,
            'pesquisa' => $pesquisa,
            'mensagem' => $mensagem,
        ];
    }
}
