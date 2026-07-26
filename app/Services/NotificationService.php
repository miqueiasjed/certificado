<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Budget;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\Company;
use App\Models\Contract;
use App\Models\NotificationQueue;
use App\Models\NotificationTemplate;
use App\Models\PaymentDetail;
use App\Models\User;
use App\Models\WorkOrder;
use App\Services\Notification\RenderizadorDeTemplate;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use App\Support\TenantAtual;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Porta de entrada da central de notificações: monta a mensagem e coloca na
 * fila. Nenhum envio acontece aqui, e isso é proposital. Quem envia é o
 * despachante da Task 14.3, que roda em rotina própria; assim, o disparo do
 * evento (que acontece dentro do fluxo do usuário) nunca fica esperando
 * provedor de e-mail responder.
 *
 * As três regras que este Service existe para garantir:
 *
 * 1. **Não duplicar.** A `chave_idempotencia` é montada como
 *    `{evento}:{destinatario_tipo}:{destinatario_id}:{referencia_tipo}:{referencia_id}`
 *    e nunca leva o instante. É por isso que a rotina diária pode rodar duas
 *    vezes no mesmo dia, ou ser reprocessada depois de uma falha, sem o cliente
 *    receber o mesmo lembrete de novo. A garantia final é o unique da tabela: a
 *    consulta prévia daqui resolve o caso comum, e a violação de unique, que é o
 *    que sobra quando dois processos enfileiram no mesmo instante, é tratada
 *    como sucesso silencioso.
 *
 * 2. **Respeitar a recusa do cliente.** Canal desligado no cadastro devolve
 *    `recusada` sem gravar nada. A decisão de não gravar uma linha `recusada` na
 *    fila está no plano: a fila é o que vai sair, e enchê-la de linhas que nunca
 *    vão sair confunde a leitura do histórico e o painel de pendências.
 *
 * 3. **Nunca ficar sem texto.** Sem template do tenant, vale o texto padrão do
 *    catálogo (`EventosDeNotificacao`). Template do tenant desativado também cai
 *    no padrão: desligar o template significa "voltar ao texto do sistema", não
 *    "parar de avisar", que é o que a preferência do cliente faz.
 *
 * O que este Service devolve, em todos os caminhos, é um array com `resultado`,
 * `criado`, `item`, `canal`, `chave_idempotencia` e `mensagem`. Quem chama
 * decide o que fazer com cada resultado, sem precisar distinguir exceção de
 * fluxo normal: recusa de canal e aviso já enfileirado são situações esperadas,
 * não erro.
 */
class NotificationService
{
    /** Item novo gravado na fila. */
    public const RESULTADO_ENFILEIRADA = 'enfileirada';

    /** Já existia um item com a mesma chave. Sucesso silencioso: nada a fazer. */
    public const RESULTADO_DUPLICADA = 'duplicada';

    /** O cliente desligou este canal. Nenhuma linha é criada. */
    public const RESULTADO_RECUSADA = 'recusada';

    /** Sem e-mail, sem telefone ou sem destinatário resolvido. Nada é criado. */
    public const RESULTADO_SEM_DESTINO = 'sem_destino';

    /** Evento sem template do tenant e sem texto padrão. Erro de configuração. */
    public const RESULTADO_SEM_TEMPLATE = 'sem_template';

    /**
     * Hora do envio quando o agendamento é informado como dia, sem hora.
     *
     * Aviso agendado para um dia sai de manhã, no fuso do negócio, e não à
     * meia-noite: e-mail que chega às 00h07 é lido junto com o spam da
     * madrugada, e mensagem de WhatsApp nesse horário incomoda o cliente final
     * da empresa que contratou o sistema.
     */
    public const HORA_PADRAO_DE_ENVIO = '08:00';

    /**
     * Limite da coluna `chave_idempotencia`. Chave maior que isto é encurtada
     * com um resumo no fim, para continuar única sem estourar o campo.
     */
    private const TAMANHO_MAXIMO_DA_CHAVE = 191;

    /**
     * Empresa do tenant, memorizada por execução.
     *
     * A rotina diária enfileira dezenas de avisos seguidos do mesmo tenant, e
     * sem isso cada um deles faria a mesma consulta em `companies`.
     *
     * @var array<int, Company>
     */
    private array $empresasPorTenant = [];

    /**
     * Monta a mensagem do evento e coloca na fila.
     *
     * Opções aceitas (todas opcionais):
     *
     * - `canal`: força o canal (`email` ou `whatsapp`). Sem isto, vale o canal
     *   preferido do cliente quando o evento o aceita, e e-mail caso contrário.
     * - `destinatario_tipo`: sobrescreve o destinatário padrão do catálogo. É o
     *   que permite mandar o mesmo evento para o cliente e para a empresa, em
     *   duas chamadas (certificado a vencer e pagamento vencido).
     * - `destinatario`: o `Client` ou o `User` que recebe, quando ele não sai da
     *   referência.
     * - `destino`: e-mail ou telefone específico, acima do que viria do cadastro.
     * - `variaveis`: valores para o template. O que vier aqui vence a derivação
     *   automática feita a partir da referência.
     * - `corpo` e `assunto`: texto fixo desta chamada, no lugar do template. Ver
     *   `textoInformado()` para o motivo de existirem.
     * - `contexto`: dados extras gravados no item, como o caminho do PDF que o
     *   driver de e-mail vai anexar (Task 14.3).
     * - `agendada_para`: quando o aviso deve sair. Sem isto, sai na próxima
     *   passada do despachante. Informado como dia, sai no horário padrão.
     * - `marco`: distingue avisos do mesmo evento e da mesma referência que são
     *   legítimos e diferentes. É o que faz "certificado a vencer" gerar três
     *   avisos (30, 15 e 7 dias) para o mesmo certificado, e o que faz o aviso
     *   de rotina quebrada sair uma vez por dia, com a data no marco. Sem isto,
     *   a chave é exatamente a do plano e o segundo aviso seria duplicata.
     *
     * @param  array<string, mixed>  $opcoes
     * @return array{
     *     resultado: string,
     *     criado: bool,
     *     item: NotificationQueue|null,
     *     canal: string|null,
     *     chave_idempotencia: string|null,
     *     mensagem: string
     * }
     *
     * @throws InvalidArgumentException Evento fora do catálogo ou canal não aceito pelo evento.
     * @throws \RuntimeException Sem tenant resolvido (ver `App\Support\TenantAtual`).
     */
    public function enfileirar(string $evento, Model $referencia, array $opcoes = []): array
    {
        $definicao = EventosDeNotificacao::definicao($evento);
        $empresa = $this->empresaDoTenant();

        $destinatarioTipo = $this->destinatarioTipo($opcoes, $definicao);
        $destinatario = $opcoes['destinatario'] ?? null;

        // O cliente da referência é resolvido sempre, mesmo quando quem recebe é
        // a empresa: o aviso interno fala dele ("o contrato do cliente Fulano
        // vence em"), e é por ele que o histórico é filtrado na tela. O que muda
        // com o tipo de destinatário é só quem manda no canal e para onde a
        // mensagem vai.
        $cliente = $this->resolverCliente($referencia, $destinatario);
        $clienteEhDestinatario = $destinatarioTipo === NotificationQueue::DESTINATARIO_CLIENTE;

        if ($clienteEhDestinatario && $cliente === null) {
            return $this->semDestino(
                $evento,
                'Nenhum cliente foi resolvido a partir da referência '.$this->tipoDaReferencia($referencia).'.'
            );
        }

        $canal = $this->resolverCanal($evento, $opcoes, $clienteEhDestinatario ? $cliente : null);

        if ($clienteEhDestinatario && ! $this->clienteAceita($cliente, $canal)) {
            return $this->resultado(
                self::RESULTADO_RECUSADA,
                mensagem: "O cliente {$cliente->name} não aceita receber avisos por {$canal}.",
                canal: $canal
            );
        }

        $destino = $this->resolverDestino($destinatarioTipo, $canal, $opcoes, $cliente, $empresa, $destinatario);

        if (blank($destino)) {
            return $this->semDestino(
                $evento,
                "Sem endereço de {$canal} para o destinatário do tipo {$destinatarioTipo}."
            );
        }

        $destinatarioId = $this->resolverDestinatarioId($destinatarioTipo, $opcoes, $cliente, $destinatario);
        $chave = $this->chaveDeIdempotencia($evento, $destinatarioTipo, $destinatarioId, $referencia, $opcoes['marco'] ?? null);

        $jaEnfileirado = NotificationQueue::where('chave_idempotencia', $chave)->first();

        if ($jaEnfileirado !== null) {
            return $this->resultado(
                self::RESULTADO_DUPLICADA,
                item: $jaEnfileirado,
                canal: $canal,
                chave: $chave,
                mensagem: 'Este aviso já está na fila.'
            );
        }

        $template = $this->textoInformado($opcoes) ?? $this->template($evento, $canal);

        if ($template === null) {
            // Erro de configuração, não de dado: o catálogo deveria ter texto
            // padrão para todo par de evento e canal aceito. Registrar em log é
            // o caminho para a empresa ser avisada pela verificação de rotinas
            // (Task 14.5) sem que este Service tente notificar sobre a própria
            // falha de notificação.
            Log::error('Evento de notificação sem template do tenant e sem texto padrão.', [
                'evento' => $evento,
                'canal' => $canal,
                'empresa_id' => $empresa->id,
            ]);

            return $this->resultado(
                self::RESULTADO_SEM_TEMPLATE,
                canal: $canal,
                chave: $chave,
                mensagem: "Nenhum template disponível para o evento {$evento} no canal {$canal}."
            );
        }

        $variaveis = array_merge(
            $this->variaveisBase($empresa, $cliente),
            $this->variaveisDaReferencia($referencia),
            is_array($opcoes['variaveis'] ?? null) ? $opcoes['variaveis'] : []
        );

        $assunto = $canal === EventosDeNotificacao::CANAL_EMAIL && filled($template['assunto'])
            ? RenderizadorDeTemplate::render($template['assunto'], $variaveis)
            : null;

        $atributos = [
            'evento' => $evento,
            'canal' => $canal,
            'chave_idempotencia' => $chave,
            'destinatario_tipo' => $destinatarioTipo,
            'destinatario_id' => $destinatarioId,
            'destino' => $destino,
            'assunto' => $assunto,
            'corpo' => RenderizadorDeTemplate::render($template['corpo'], $variaveis),
            'contexto' => $this->contexto($referencia, $cliente, $opcoes),
            'situacao' => NotificationQueue::SITUACAO_PENDENTE,
            'agendada_para' => $this->instanteDeEnvio($opcoes['agendada_para'] ?? null),
            'tentativas' => 0,
        ];

        try {
            $item = NotificationQueue::create($atributos);
        } catch (QueryException $excecao) {
            if (! $this->violacaoDaChaveDeIdempotencia($excecao)) {
                throw $excecao;
            }

            // Outro processo enfileirou o mesmo aviso entre a consulta acima e
            // este insert. É exatamente o que o unique existe para impedir, e
            // aqui isso é sucesso: o aviso está na fila.
            return $this->resultado(
                self::RESULTADO_DUPLICADA,
                item: NotificationQueue::where('chave_idempotencia', $chave)->first(),
                canal: $canal,
                chave: $chave,
                mensagem: 'Este aviso já está na fila.'
            );
        }

        return $this->resultado(
            self::RESULTADO_ENFILEIRADA,
            criado: true,
            item: $item,
            canal: $canal,
            chave: $chave,
            mensagem: 'Aviso enfileirado.'
        );
    }

    /**
     * Link `wa.me` com a mensagem do item já preenchida, para envio manual em um
     * clique enquanto o WhatsApp não tem driver próprio.
     *
     * O telefone sai normalizado para o formato internacional: número com
     * máscara não abre a conversa. O valor salvo no cadastro não é alterado.
     *
     * Item de e-mail também gera link: o `destino` dele não serve como telefone,
     * então o número vem do cadastro do cliente destinatário. É o que permite à
     * tela oferecer "mandar por WhatsApp" para um aviso que saiu por e-mail.
     *
     * @param  string|null  $telefone  Número específico, acima do que sairia do item.
     *
     * @throws InvalidArgumentException Quando não há número utilizável.
     */
    public function linkWhatsapp(NotificationQueue $item, ?string $telefone = null): string
    {
        $numero = $this->normalizarTelefone($telefone ?? $this->telefoneDoItem($item));

        if ($numero === null) {
            throw new InvalidArgumentException(
                'Sem telefone válido para montar o link de WhatsApp deste aviso. '
                .'Confira o telefone no cadastro do destinatário.'
            );
        }

        $texto = filled($item->assunto)
            ? $item->assunto."\n\n".$item->corpo
            : $item->corpo;

        return 'https://wa.me/'.$numero.'?text='.rawurlencode(trim($texto));
    }

    /**
     * Telefone em formato internacional, só dígitos, pronto para o link `wa.me`.
     *
     * Regras, nesta ordem:
     * - fica só o que é dígito, então `(11) 99999-9999` vira `11999999999`;
     * - 10 ou 11 dígitos é número brasileiro sem o código do país, e ganha `55`;
     * - 12 ou 13 dígitos começando com `55` já está pronto;
     * - 12 dígitos ou mais com outro começo é tratado como número de outro país
     *   e sai como veio, porque inventar `55` na frente quebraria a conversa;
     * - menos de 10 dígitos não é telefone utilizável e devolve null.
     */
    public function normalizarTelefone(?string $telefone): ?string
    {
        $digitos = preg_replace('/\D+/', '', (string) $telefone) ?? '';
        $tamanho = strlen($digitos);

        if ($tamanho < 10) {
            return null;
        }

        if ($tamanho <= 11) {
            return '55'.$digitos;
        }

        return $digitos;
    }

    /**
     * Tipo de destinatário desta chamada, validado contra os tipos da tabela.
     *
     * @param  array<string, mixed>  $opcoes
     * @param  array<string, mixed>  $definicao
     */
    private function destinatarioTipo(array $opcoes, array $definicao): string
    {
        $tipo = $opcoes['destinatario_tipo'] ?? $definicao['destinatario'];

        if (! in_array($tipo, NotificationQueue::DESTINATARIOS, true)) {
            throw new InvalidArgumentException(
                "Tipo de destinatário desconhecido: \"{$tipo}\". "
                .'Tipos válidos: '.implode(', ', NotificationQueue::DESTINATARIOS).'.'
            );
        }

        return $tipo;
    }

    /**
     * Canal do aviso.
     *
     * Ordem: canal forçado por quem chama, canal preferido do cliente (quando o
     * evento o aceita), e-mail, primeiro canal do catálogo. O canal preferido
     * entra antes do e-mail porque é a escolha do cliente final; item de
     * WhatsApp fica na fila esperando o clique no link, que é o fluxo manual
     * desta entrega.
     *
     * @param  array<string, mixed>  $opcoes
     */
    private function resolverCanal(string $evento, array $opcoes, ?Client $cliente): string
    {
        $aceitos = EventosDeNotificacao::canais($evento);
        $forcado = $opcoes['canal'] ?? null;

        if (filled($forcado)) {
            if (! in_array($forcado, $aceitos, true)) {
                throw new InvalidArgumentException(
                    "O evento \"{$evento}\" não aceita o canal \"{$forcado}\". "
                    .'Canais aceitos: '.implode(', ', $aceitos).'.'
                );
            }

            return $forcado;
        }

        $preferido = $cliente?->canal_preferido;

        if (filled($preferido) && in_array($preferido, $aceitos, true)) {
            return $preferido;
        }

        if (in_array(EventosDeNotificacao::CANAL_EMAIL, $aceitos, true)) {
            return EventosDeNotificacao::CANAL_EMAIL;
        }

        return $aceitos[0];
    }

    /**
     * O cliente aceita receber por este canal?
     *
     * Atributo ausente é lido como aceite, no mesmo critério do padrão da
     * coluna: cliente sem preferência declarada recebe, e a recusa é registrada
     * quando ele pedir.
     */
    private function clienteAceita(Client $cliente, string $canal): bool
    {
        return match ($canal) {
            EventosDeNotificacao::CANAL_EMAIL => (bool) ($cliente->aceita_email ?? true),
            EventosDeNotificacao::CANAL_WHATSAPP => (bool) ($cliente->aceita_whatsapp ?? true),
            default => true,
        };
    }

    /**
     * Cliente dono do aviso, a partir do destinatário informado ou da referência.
     *
     * A referência pode ser o próprio cliente, algo que aponta para ele
     * (`client_id`), algo que aponta para uma OS que aponta para ele (título a
     * receber) ou algo que aponta para um endereço que aponta para ele
     * (contrato).
     */
    private function resolverCliente(Model $referencia, mixed $destinatario): ?Client
    {
        if ($destinatario instanceof Client) {
            return $destinatario;
        }

        if ($referencia instanceof Client) {
            return $referencia;
        }

        $clienteId = $referencia->getAttribute('client_id');

        if (filled($clienteId)) {
            return Client::find($clienteId);
        }

        if (method_exists($referencia, 'workOrder')) {
            $cliente = $referencia->workOrder?->client;

            if ($cliente instanceof Client) {
                return $cliente;
            }
        }

        if (method_exists($referencia, 'address')) {
            $cliente = $referencia->address?->client;

            if ($cliente instanceof Client) {
                return $cliente;
            }
        }

        return null;
    }

    /**
     * Para onde a mensagem vai, já congelado no item da fila.
     *
     * @param  array<string, mixed>  $opcoes
     */
    private function resolverDestino(
        string $destinatarioTipo,
        string $canal,
        array $opcoes,
        ?Client $cliente,
        Company $empresa,
        mixed $destinatario
    ): ?string {
        $informado = $opcoes['destino'] ?? null;

        if (filled($informado)) {
            return trim((string) $informado);
        }

        $ehEmail = $canal === EventosDeNotificacao::CANAL_EMAIL;

        $destino = match ($destinatarioTipo) {
            NotificationQueue::DESTINATARIO_CLIENTE => $ehEmail
                // O e-mail de notificação existe para o caso comum em pessoa
                // jurídica: o e-mail cadastral é o da nota fiscal, e quem recebe
                // aviso de visita é outra pessoa.
                ? ($cliente?->email_notificacao ?: $cliente?->email)
                : $cliente?->phone,
            NotificationQueue::DESTINATARIO_EMPRESA => $ehEmail ? $empresa->email : $empresa->phone,
            NotificationQueue::DESTINATARIO_USUARIO => $destinatario instanceof User && $ehEmail
                ? $destinatario->email
                : null,
            default => null,
        };

        return filled($destino) ? trim((string) $destino) : null;
    }

    /**
     * Id do destinatário gravado no item.
     *
     * Aviso para a própria empresa não tem id: o dono já é o `company_id` da
     * linha, e repetir isso em `destinatario_id` só criaria duas fontes para o
     * mesmo dado.
     *
     * @param  array<string, mixed>  $opcoes
     */
    private function resolverDestinatarioId(
        string $destinatarioTipo,
        array $opcoes,
        ?Client $cliente,
        mixed $destinatario
    ): ?int {
        if (filled($opcoes['destinatario_id'] ?? null)) {
            return (int) $opcoes['destinatario_id'];
        }

        return match ($destinatarioTipo) {
            NotificationQueue::DESTINATARIO_CLIENTE => $cliente?->id,
            NotificationQueue::DESTINATARIO_USUARIO => $destinatario instanceof Model
                ? (int) $destinatario->getKey()
                : null,
            default => null,
        };
    }

    /**
     * Chave que impede o aviso repetido.
     *
     * Nunca leva instante: fosse pelo horário, cada execução da rotina geraria
     * uma chave nova e o cliente receberia o mesmo lembrete todo dia. O `marco`
     * é o único acréscimo permitido ao formato do plano, e existe para os avisos
     * que são legitimamente vários sobre a mesma referência: os três avisos de
     * certificado a vencer (30, 15 e 7 dias) e o aviso diário de rotina
     * quebrada, que leva a data do dia no marco.
     */
    private function chaveDeIdempotencia(
        string $evento,
        string $destinatarioTipo,
        ?int $destinatarioId,
        Model $referencia,
        mixed $marco
    ): string {
        $partes = [
            $evento,
            $destinatarioTipo,
            $destinatarioId === null ? '' : (string) $destinatarioId,
            $this->tipoDaReferencia($referencia),
            (string) $referencia->getKey(),
        ];

        if (filled($marco)) {
            $partes[] = $this->normalizarMarco($marco);
        }

        return $this->limitarChave(implode(':', $partes));
    }

    /**
     * Nome da referência na chave: `App\Models\WorkOrder` vira `work_order`.
     */
    private function tipoDaReferencia(Model $referencia): string
    {
        return Str::snake(class_basename($referencia));
    }

    /**
     * Marco em texto seguro para a chave.
     *
     * Tudo que não é letra, número, sublinhado ou hífen vira hífen, inclusive os
     * dois-pontos: o marco pode ser a assinatura de uma rotina
     * (`certificates:update-status`), e um dois-pontos ali quebraria a leitura
     * do formato da chave.
     */
    private function normalizarMarco(mixed $marco): string
    {
        $texto = preg_replace('/[^A-Za-z0-9_\-]+/', '-', (string) $marco) ?? '';

        return trim($texto, '-');
    }

    /**
     * Encurta a chave que não cabe na coluna, preservando a unicidade com um
     * resumo do valor inteiro no fim. Caso raro, mas silencioso quando acontece:
     * o banco truncaria a chave e duas mensagens diferentes virariam duplicata
     * uma da outra.
     */
    private function limitarChave(string $chave): string
    {
        if (strlen($chave) <= self::TAMANHO_MAXIMO_DA_CHAVE) {
            return $chave;
        }

        return substr($chave, 0, self::TAMANHO_MAXIMO_DA_CHAVE - 9).'-'.substr(sha1($chave), 0, 8);
    }

    /**
     * A exceção é a violação da unique de `chave_idempotencia` (SQLSTATE 23000),
     * e não outro erro de integridade, como chave estrangeira inválida?
     *
     * Mesmo critério de `WorkOrderService::criarComNumeroDeOrdemUnico`: código
     * do SQLSTATE mais o nome da coluna no texto, que aparece tanto na mensagem
     * do MySQL quanto na do SQLite.
     */
    private function violacaoDaChaveDeIdempotencia(QueryException $excecao): bool
    {
        return $excecao->getCode() === '23000'
            && str_contains($excecao->getMessage(), 'chave_idempotencia');
    }

    /**
     * Texto fixo informado por quem dispara, no lugar de qualquer template.
     *
     * Extensão aditiva: sem `corpo` nas opções, nada muda e a busca de template
     * continua sendo o único caminho. Com ele, o template (do tenant e o padrão)
     * é ignorado e o texto informado é renderizado com as mesmas variáveis.
     *
     * Existe por causa da cópia interna de `certificado_a_vencer` e
     * `pagamento_vencido`, que vão para o cliente e também para a empresa.
     * `notification_templates` tem unique em (empresa, evento, canal), sem
     * dimensão de destinatário: existe uma linha editável por evento e canal, e
     * ela é escrita para o cliente ("Olá, {{cliente_nome}}..."). Mandar esse
     * mesmo texto para a caixa de entrada da própria empresa entregaria uma
     * mensagem endereçada a quem não é o leitor. O aviso interno é operacional,
     * fixo, e de propósito não editável pelo tenant: quem edita o texto do
     * cliente não está editando o alerta da própria equipe.
     *
     * @param  array<string, mixed>  $opcoes
     * @return array{assunto: ?string, corpo: string}|null
     */
    private function textoInformado(array $opcoes): ?array
    {
        $corpo = $opcoes['corpo'] ?? null;

        if (blank($corpo)) {
            return null;
        }

        $assunto = $opcoes['assunto'] ?? null;

        return [
            'assunto' => filled($assunto) ? (string) $assunto : null,
            'corpo' => (string) $corpo,
        ];
    }

    /**
     * Texto do tenant para o evento e canal, ou o padrão do catálogo.
     *
     * Template desativado não é considerado, e cai no padrão: desligar o
     * template do tenant significa voltar ao texto do sistema.
     *
     * @return array{assunto: ?string, corpo: string}|null
     */
    private function template(string $evento, string $canal): ?array
    {
        $doTenant = NotificationTemplate::ativos()
            ->where('evento', $evento)
            ->where('canal', $canal)
            ->first();

        if ($doTenant !== null) {
            return ['assunto' => $doTenant->assunto, 'corpo' => $doTenant->corpo];
        }

        return EventosDeNotificacao::templatePadrao($evento, $canal);
    }

    /**
     * Variáveis que valem para qualquer evento.
     *
     * @return array<string, mixed>
     */
    private function variaveisBase(Company $empresa, ?Client $cliente): array
    {
        return [
            'empresa_nome' => $empresa->name,
            'empresa_telefone' => $empresa->phone,
            'cliente_nome' => $cliente?->name,
        ];
    }

    /**
     * Variáveis derivadas da referência.
     *
     * Ficam aqui, e não em cada disparo, para que número de OS, endereço e data
     * saiam iguais em todos os eventos, e para que nenhuma data chegue crua ao
     * template. O que o disparo mandar em `variaveis` vence o que sai daqui.
     *
     * @return array<string, mixed>
     */
    private function variaveisDaReferencia(Model $referencia): array
    {
        return match (true) {
            $referencia instanceof WorkOrder => [
                'os_numero' => $referencia->order_number,
                'data_visita' => $referencia->scheduled_date,
                'data_execucao' => $referencia->end_time ?? $referencia->scheduled_date,
                'endereco' => $this->enderecoEmTexto($referencia->address),
                'tecnico_nome' => $referencia->technician?->name,
                'valor' => $this->dinheiro($referencia->final_amount ?? $referencia->total_cost),
            ],
            $referencia instanceof Certificate => [
                'certificado_numero' => $referencia->certificate_number,
                'data_vencimento' => $referencia->warranty,
                'dias_para_vencer' => $this->diasAte($referencia->warranty),
                'endereco' => $this->enderecoEmTexto($referencia->address),
            ],
            $referencia instanceof Contract => [
                'contrato_numero' => $referencia->contract_number,
                'data_vencimento' => $referencia->end_date,
                'dias_para_vencer' => $this->diasAte($referencia->end_date),
                'endereco' => $this->enderecoEmTexto($referencia->address),
            ],
            $referencia instanceof PaymentDetail => [
                'os_numero' => $referencia->workOrder?->order_number,
                'valor' => $this->dinheiro($referencia->final_amount),
                'data_vencimento' => $referencia->payment_due_date,
                'dias_em_atraso' => $this->diasDesde($referencia->payment_due_date),
            ],
            $referencia instanceof Budget => [
                // Orçamento não tem numeração própria: a tela o identifica pelo
                // id, e o aviso segue a mesma identificação para o usuário achar
                // o registro.
                'orcamento_numero' => '#'.$referencia->getKey(),
                'data_validade' => $referencia->validity_date,
                'dias_para_expirar' => $this->diasAte($referencia->validity_date),
            ],
            default => [],
        };
    }

    /**
     * Endereço em uma linha, para caber na mensagem.
     */
    private function enderecoEmTexto(?Address $endereco): ?string
    {
        if ($endereco === null) {
            return null;
        }

        $partes = array_filter([
            $endereco->street,
            $endereco->number,
            $endereco->district,
            filled($endereco->city) && filled($endereco->state)
                ? $endereco->city.'/'.$endereco->state
                : $endereco->city,
        ], static fn ($parte): bool => filled($parte));

        return $partes === [] ? null : implode(', ', $partes);
    }

    /**
     * Valor em reais no formato brasileiro. Null continua null, para a variável
     * sair vazia em vez de "R$ 0,00", que seria informação errada.
     */
    private function dinheiro(mixed $valor): ?string
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        return 'R$ '.number_format((float) $valor, 2, ',', '.');
    }

    /**
     * Dias que faltam até a data, contados por dia no fuso do negócio.
     *
     * Comparar instante com instante faria o certificado que vence hoje aparecer
     * como vencido às 21h de Brasília.
     */
    private function diasAte(mixed $data): ?int
    {
        $dia = BusinessDate::paraFusoNegocio($data);

        if ($dia === null) {
            return null;
        }

        return (int) BusinessDate::hoje()->diffInDays($dia->startOfDay(), false);
    }

    /**
     * Dias corridos desde a data, nunca negativo: o que ainda não venceu está
     * com zero dia de atraso.
     */
    private function diasDesde(mixed $data): ?int
    {
        $dias = $this->diasAte($data);

        return $dias === null ? null : max(0, -$dias);
    }

    /**
     * Dados de origem gravados no item, para a tela de histórico e para o driver
     * de envio (o anexo do PDF da OS concluída chega por aqui, na Task 14.3).
     *
     * @param  array<string, mixed>  $opcoes
     * @return array<string, mixed>
     */
    private function contexto(Model $referencia, ?Client $cliente, array $opcoes): array
    {
        $contexto = [
            'referencia_tipo' => $this->tipoDaReferencia($referencia),
            'referencia_id' => $referencia->getKey(),
        ];

        if ($cliente !== null) {
            $contexto['cliente_id'] = $cliente->id;
        }

        if (is_array($opcoes['contexto'] ?? null)) {
            $contexto = array_merge($contexto, $opcoes['contexto']);
        }

        return $contexto;
    }

    /**
     * Instante em que o aviso pode sair, já no fuso da aplicação, que é como a
     * coluna é comparada pelo despachante.
     *
     * Valor informado como dia (sem hora) ganha a hora padrão de envio no fuso
     * do negócio. É o caso do lembrete agendado com antecedência: a data é
     * calculada em Brasília, e a conversão para o fuso da aplicação acontece
     * aqui, uma vez só.
     */
    private function instanteDeEnvio(mixed $valor): CarbonImmutable
    {
        $fusoDaAplicacao = config('app.timezone') ?: 'UTC';

        if (blank($valor)) {
            return CarbonImmutable::now($fusoDaAplicacao);
        }

        $instante = BusinessDate::paraFusoNegocio($valor);

        if ($instante === null) {
            return CarbonImmutable::now($fusoDaAplicacao);
        }

        if ($instante->hour === 0 && $instante->minute === 0 && $instante->second === 0) {
            $instante = $instante->setTimeFromTimeString(self::HORA_PADRAO_DE_ENVIO);
        }

        return $instante->setTimezone($fusoDaAplicacao);
    }

    /**
     * Empresa do tenant corrente. Sem tenant resolvido, `Company::current()`
     * lança com a instrução do que fazer, e falhar aqui é melhor que enfileirar
     * um aviso com o cabeçalho da empresa errada.
     */
    private function empresaDoTenant(): Company
    {
        $tenant = TenantAtual::exigirId();

        return $this->empresasPorTenant[$tenant] ??= Company::current();
    }

    /**
     * Telefone utilizável do item: o próprio destino, quando ele é telefone, ou
     * o telefone do cliente destinatário.
     */
    private function telefoneDoItem(NotificationQueue $item): ?string
    {
        if ($this->normalizarTelefone($item->destino) !== null && ! str_contains((string) $item->destino, '@')) {
            return $item->destino;
        }

        if ($item->destinatario_tipo === NotificationQueue::DESTINATARIO_CLIENTE && filled($item->destinatario_id)) {
            return Client::find($item->destinatario_id)?->phone;
        }

        return null;
    }

    /**
     * Resultado de "nada a enfileirar por falta de endereço", com o motivo em
     * log: aviso que não sai por cadastro incompleto precisa deixar rastro, ou
     * a empresa acha que o cliente foi avisado.
     *
     * @return array{resultado: string, criado: bool, item: NotificationQueue|null, canal: string|null, chave_idempotencia: string|null, mensagem: string}
     */
    private function semDestino(string $evento, string $motivo): array
    {
        Log::warning('Aviso não enfileirado por falta de destino.', [
            'evento' => $evento,
            'motivo' => $motivo,
        ]);

        return $this->resultado(self::RESULTADO_SEM_DESTINO, mensagem: $motivo);
    }

    /**
     * Formato único de retorno de `enfileirar`.
     *
     * @return array{resultado: string, criado: bool, item: NotificationQueue|null, canal: string|null, chave_idempotencia: string|null, mensagem: string}
     */
    private function resultado(
        string $resultado,
        bool $criado = false,
        ?NotificationQueue $item = null,
        ?string $canal = null,
        ?string $chave = null,
        string $mensagem = ''
    ): array {
        return [
            'resultado' => $resultado,
            'criado' => $criado,
            'item' => $item,
            'canal' => $canal,
            'chave_idempotencia' => $chave,
            'mensagem' => $mensagem,
        ];
    }
}
