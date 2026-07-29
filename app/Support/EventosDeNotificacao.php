<?php

namespace App\Support;

use App\Models\NotificationQueue;
use InvalidArgumentException;

/**
 * Catálogo dos eventos da central de notificações (Plano 14).
 *
 * Fonte única de quatro coisas por evento: o rótulo em português que aparece na
 * tela, quem recebe o aviso por padrão, quais canais o evento aceita e quais
 * variáveis o template pode usar. Consultado pelo `NotificationService`
 * (Task 14.2), pelos disparos (Task 14.4), pela validação de template
 * (Task 14.6), pela tela de templates (Task 14.7) e pelo seeder.
 *
 * O texto padrão de cada evento mora aqui, junto da lista de variáveis, e não em
 * `config/` nem em arquivo de idioma. O motivo é que as duas informações só
 * fazem sentido juntas: o texto padrão só pode usar variável declarada no mesmo
 * bloco, e um teste consegue afirmar isso lendo uma estrutura só. Separado em
 * dois arquivos, a primeira variável renomeada deixaria o texto padrão apontando
 * para nome que não existe mais, e o erro só apareceria no e-mail do cliente
 * final.
 *
 * Regra que a lista de variáveis segue: só entra variável que alguém preenche de
 * fato, seja pela derivação do `NotificationService` a partir da referência,
 * seja pelo disparo da Task 14.4. Variável declarada e nunca alimentada renderiza
 * vazio e ainda gera aviso em log a cada envio, então declarar "por precaução"
 * cobra o preço depois.
 *
 * Canal por evento não é uniforme de propósito. Os quatro eventos internos
 * (contrato a vencer, orçamento a expirar, visita periódica não gerada e rotina
 * agendada falhou) aceitam apenas e-mail: eles vão para a própria empresa, e o
 * fluxo de WhatsApp desta entrega é o link `wa.me` que alguém da empresa abre
 * para falar com o cliente, o que não faz sentido apontando para o número dela
 * mesma.
 *
 * Catálogo: sem acesso a banco e sem regra de negócio além da própria tabela.
 */
final class EventosDeNotificacao
{
    public const CANAL_EMAIL = 'email';

    public const CANAL_WHATSAPP = 'whatsapp';

    /**
     * Canais existentes, na mesma ordem do enum da tabela.
     *
     * @var array<int, string>
     */
    public const CANAIS = [
        self::CANAL_EMAIL,
        self::CANAL_WHATSAPP,
    ];

    public const VISITA_AGENDADA = 'visita_agendada';

    public const LEMBRETE_VESPERA = 'lembrete_vespera';

    public const TECNICO_A_CAMINHO = 'tecnico_a_caminho';

    public const OS_CONCLUIDA = 'os_concluida';

    public const CERTIFICADO_A_VENCER = 'certificado_a_vencer';

    public const CONTRATO_A_VENCER = 'contrato_a_vencer';

    public const PAGAMENTO_VENCIDO = 'pagamento_vencido';

    public const ORCAMENTO_A_EXPIRAR = 'orcamento_a_expirar';

    public const VISITA_PERIODICA_NAO_GERADA = 'visita_periodica_nao_gerada';

    public const ROTINA_AGENDADA_FALHOU = 'rotina_agendada_falhou';

    /**
     * Convite de acesso ao portal do cliente (Plano 15, Task 15.2).
     */
    public const CONVITE_PORTAL = 'convite_portal';

    /**
     * Recuperação de senha do portal do cliente (Plano 15, Task 15.2).
     *
     * Evento distinto de `CONVITE_PORTAL`, embora os dois consumam o mesmo par
     * de colunas em `client_users` (`convite_token`/`convite_expira_em`) e o
     * mesmo `ClientUserService::definirSenha()`: o texto de um é acolhimento
     * ("a empresa liberou seu acesso"), o do outro é segurança ("alguém pediu
     * para redefinir sua senha"), e misturar os dois num evento só impediria o
     * tenant de customizar cada template pela tela de notificações.
     */
    public const RECUPERACAO_SENHA_PORTAL = 'recuperacao_senha_portal';

    /**
     * Solicitação de atendimento aberta pelo cliente no portal (Plano 15,
     * Task 15.5): a empresa recebe o aviso de que uma pendência nova chegou.
     */
    public const SOLICITACAO_RECEBIDA = 'solicitacao_recebida';

    /**
     * Resposta da empresa a uma solicitação, avisando o cliente (Plano 15,
     * Task 15.5). A resposta em si fica registrada e visível no portal (ver
     * `ClientRequestService::responder()`); este evento é só o aviso de que
     * ela existe.
     */
    public const SOLICITACAO_RESPONDIDA = 'solicitacao_respondida';

    /**
     * Os catorze eventos do plano.
     *
     * Estrutura de cada entrada:
     * - `rotulo`: nome em português, para tela e para relatório.
     * - `destinatario`: quem recebe por padrão. Quem dispara pode apontar outro
     *   (certificado a vencer e pagamento vencido vão para o cliente e também
     *   para a empresa, em dois enfileiramentos).
     * - `canais`: canais aceitos, o primeiro sendo o preferido do sistema.
     * - `variaveis`: o que o template pode usar.
     * - `padrao`: texto do sistema por canal, usado quando o tenant não tem
     *   template próprio ativo.
     *
     * @var array<string, array{
     *     rotulo: string,
     *     destinatario: string,
     *     canais: array<int, string>,
     *     variaveis: array<int, string>,
     *     padrao: array<string, array{assunto: ?string, corpo: string}>
     * }>
     */
    public const EVENTOS = [
        self::VISITA_AGENDADA => [
            'rotulo' => 'Visita agendada',
            'destinatario' => NotificationQueue::DESTINATARIO_CLIENTE,
            'canais' => [self::CANAL_EMAIL, self::CANAL_WHATSAPP],
            'variaveis' => [
                'cliente_nome',
                'empresa_nome',
                'empresa_telefone',
                'os_numero',
                'data_visita',
                'endereco',
                'tecnico_nome',
            ],
            'padrao' => [
                self::CANAL_EMAIL => [
                    'assunto' => 'Visita agendada para {{data_visita}}',
                    'corpo' => "Olá, {{cliente_nome}}.\n\n"
                        ."Sua visita técnica está agendada para {{data_visita}}, no endereço {{endereco}}.\n"
                        ."Ordem de serviço: {{os_numero}}.\n\n"
                        ."Se precisar remarcar, fale com a gente pelo telefone {{empresa_telefone}}.\n\n"
                        .'{{empresa_nome}}',
                ],
                self::CANAL_WHATSAPP => [
                    'assunto' => null,
                    'corpo' => 'Olá, {{cliente_nome}}. Sua visita técnica está agendada para {{data_visita}}, '
                        .'no endereço {{endereco}} (ordem de serviço {{os_numero}}). '
                        .'Qualquer necessidade de remarcação, é só responder. {{empresa_nome}}',
                ],
            ],
        ],

        self::LEMBRETE_VESPERA => [
            'rotulo' => 'Lembrete na véspera da visita',
            'destinatario' => NotificationQueue::DESTINATARIO_CLIENTE,
            'canais' => [self::CANAL_EMAIL, self::CANAL_WHATSAPP],
            'variaveis' => [
                'cliente_nome',
                'empresa_nome',
                'empresa_telefone',
                'os_numero',
                'data_visita',
                'endereco',
                'tecnico_nome',
            ],
            'padrao' => [
                self::CANAL_EMAIL => [
                    'assunto' => 'Lembrete: sua visita é amanhã, {{data_visita}}',
                    'corpo' => "Olá, {{cliente_nome}}.\n\n"
                        .'Passando para lembrar da visita técnica de amanhã, {{data_visita}}, '
                        ."no endereço {{endereco}} (ordem de serviço {{os_numero}}).\n\n"
                        ."Deixe o local acessível para a equipe. Para remarcar, ligue para {{empresa_telefone}}.\n\n"
                        .'{{empresa_nome}}',
                ],
                self::CANAL_WHATSAPP => [
                    'assunto' => null,
                    'corpo' => 'Olá, {{cliente_nome}}. Lembrando que sua visita técnica é amanhã, {{data_visita}}, '
                        .'no endereço {{endereco}}. Precisando remarcar, é só responder. {{empresa_nome}}',
                ],
            ],
        ],

        self::TECNICO_A_CAMINHO => [
            'rotulo' => 'Técnico a caminho',
            'destinatario' => NotificationQueue::DESTINATARIO_CLIENTE,
            'canais' => [self::CANAL_EMAIL, self::CANAL_WHATSAPP],
            'variaveis' => [
                'cliente_nome',
                'empresa_nome',
                'empresa_telefone',
                'os_numero',
                'endereco',
                'tecnico_nome',
            ],
            'padrao' => [
                self::CANAL_EMAIL => [
                    'assunto' => 'O técnico está a caminho',
                    'corpo' => "Olá, {{cliente_nome}}.\n\n"
                        .'Nosso técnico {{tecnico_nome}} saiu para o atendimento da ordem de serviço {{os_numero}}, '
                        ."no endereço {{endereco}}.\n\n"
                        ."Se precisar falar com a equipe, o telefone é {{empresa_telefone}}.\n\n"
                        .'{{empresa_nome}}',
                ],
                self::CANAL_WHATSAPP => [
                    'assunto' => null,
                    'corpo' => 'Olá, {{cliente_nome}}. Nosso técnico {{tecnico_nome}} está a caminho do endereço '
                        .'{{endereco}} para o atendimento {{os_numero}}. {{empresa_nome}}',
                ],
            ],
        ],

        self::OS_CONCLUIDA => [
            'rotulo' => 'Ordem de serviço concluída',
            'destinatario' => NotificationQueue::DESTINATARIO_CLIENTE,
            'canais' => [self::CANAL_EMAIL, self::CANAL_WHATSAPP],
            'variaveis' => [
                'cliente_nome',
                'empresa_nome',
                'empresa_telefone',
                'os_numero',
                'data_execucao',
                'endereco',
                'tecnico_nome',
            ],
            'padrao' => [
                self::CANAL_EMAIL => [
                    'assunto' => 'Atendimento concluído: ordem de serviço {{os_numero}}',
                    'corpo' => "Olá, {{cliente_nome}}.\n\n"
                        .'O atendimento da ordem de serviço {{os_numero}}, no endereço {{endereco}}, '
                        ."foi concluído em {{data_execucao}}.\n\n"
                        .'O documento do atendimento segue em anexo. Guarde-o: ele é o comprovante '
                        ."apresentado à fiscalização.\n\n"
                        .'{{empresa_nome}}',
                ],
                self::CANAL_WHATSAPP => [
                    'assunto' => null,
                    'corpo' => 'Olá, {{cliente_nome}}. O atendimento da ordem de serviço {{os_numero}} foi concluído '
                        .'em {{data_execucao}}. O documento está disponível com a gente. {{empresa_nome}}',
                ],
            ],
        ],

        self::CERTIFICADO_A_VENCER => [
            'rotulo' => 'Certificado próximo do vencimento',
            'destinatario' => NotificationQueue::DESTINATARIO_CLIENTE,
            'canais' => [self::CANAL_EMAIL, self::CANAL_WHATSAPP],
            'variaveis' => [
                'cliente_nome',
                'empresa_nome',
                'empresa_telefone',
                'certificado_numero',
                'data_vencimento',
                'dias_para_vencer',
                'endereco',
            ],
            'padrao' => [
                self::CANAL_EMAIL => [
                    'assunto' => 'Certificado {{certificado_numero}} vence em {{data_vencimento}}',
                    'corpo' => "Olá, {{cliente_nome}}.\n\n"
                        .'O certificado {{certificado_numero}}, do endereço {{endereco}}, '
                        ."vence em {{data_vencimento}}, daqui a {{dias_para_vencer}} dias.\n\n"
                        .'Certificado vencido deixa o local irregular perante a fiscalização. '
                        ."Agende a renovação pelo telefone {{empresa_telefone}}.\n\n"
                        .'{{empresa_nome}}',
                ],
                self::CANAL_WHATSAPP => [
                    'assunto' => null,
                    'corpo' => 'Olá, {{cliente_nome}}. O certificado {{certificado_numero}} do endereço {{endereco}} '
                        .'vence em {{data_vencimento}}, daqui a {{dias_para_vencer}} dias. '
                        .'Vamos agendar a renovação? {{empresa_nome}}',
                ],
            ],
        ],

        self::CONTRATO_A_VENCER => [
            'rotulo' => 'Contrato próximo do vencimento',
            'destinatario' => NotificationQueue::DESTINATARIO_EMPRESA,
            'canais' => [self::CANAL_EMAIL],
            'variaveis' => [
                'cliente_nome',
                'empresa_nome',
                'contrato_numero',
                'data_vencimento',
                'dias_para_vencer',
                'endereco',
            ],
            'padrao' => [
                self::CANAL_EMAIL => [
                    'assunto' => 'Contrato {{contrato_numero}} vence em {{data_vencimento}}',
                    'corpo' => 'O contrato {{contrato_numero}}, do cliente {{cliente_nome}}, '
                        ."vence em {{data_vencimento}}, daqui a {{dias_para_vencer}} dias.\n"
                        ."Endereço: {{endereco}}.\n\n"
                        .'Combine a renovação antes do fim da vigência para não interromper as visitas periódicas.',
                ],
            ],
        ],

        self::PAGAMENTO_VENCIDO => [
            'rotulo' => 'Pagamento vencido',
            'destinatario' => NotificationQueue::DESTINATARIO_CLIENTE,
            'canais' => [self::CANAL_EMAIL, self::CANAL_WHATSAPP],
            'variaveis' => [
                'cliente_nome',
                'empresa_nome',
                'empresa_telefone',
                'os_numero',
                'valor',
                'data_vencimento',
                'dias_em_atraso',
            ],
            'padrao' => [
                self::CANAL_EMAIL => [
                    'assunto' => 'Pagamento em aberto desde {{data_vencimento}}',
                    'corpo' => "Olá, {{cliente_nome}}.\n\n"
                        .'Consta em aberto o pagamento de {{valor}}, referente à ordem de serviço {{os_numero}}, '
                        ."com vencimento em {{data_vencimento}} ({{dias_em_atraso}} dias de atraso).\n\n"
                        .'Se o pagamento já foi feito, desconsidere este aviso. '
                        ."Para acertar ou tirar dúvida, ligue para {{empresa_telefone}}.\n\n"
                        .'{{empresa_nome}}',
                ],
                self::CANAL_WHATSAPP => [
                    'assunto' => null,
                    'corpo' => 'Olá, {{cliente_nome}}. Consta em aberto o pagamento de {{valor}} da ordem de serviço '
                        .'{{os_numero}}, vencido em {{data_vencimento}}. Se já pagou, desconsidere. {{empresa_nome}}',
                ],
            ],
        ],

        self::ORCAMENTO_A_EXPIRAR => [
            'rotulo' => 'Orçamento perto de expirar sem resposta',
            'destinatario' => NotificationQueue::DESTINATARIO_EMPRESA,
            'canais' => [self::CANAL_EMAIL],
            'variaveis' => [
                'cliente_nome',
                'empresa_nome',
                'orcamento_numero',
                'data_validade',
                'dias_para_expirar',
            ],
            'padrao' => [
                self::CANAL_EMAIL => [
                    'assunto' => 'Orçamento {{orcamento_numero}} expira em {{data_validade}}',
                    'corpo' => 'O orçamento {{orcamento_numero}}, do cliente {{cliente_nome}}, '
                        .'expira em {{data_validade}}, daqui a {{dias_para_expirar}} dias, '
                        ."e ainda não teve resposta.\n\n"
                        .'Vale um contato antes de o prazo terminar.',
                ],
            ],
        ],

        self::VISITA_PERIODICA_NAO_GERADA => [
            'rotulo' => 'Visita periódica prevista e não gerada',
            'destinatario' => NotificationQueue::DESTINATARIO_EMPRESA,
            'canais' => [self::CANAL_EMAIL],
            'variaveis' => [
                'cliente_nome',
                'empresa_nome',
                'contrato_numero',
                'data_prevista',
                'endereco',
            ],
            'padrao' => [
                self::CANAL_EMAIL => [
                    'assunto' => 'Visita prevista sem ordem de serviço: contrato {{contrato_numero}}',
                    'corpo' => 'O contrato {{contrato_numero}}, do cliente {{cliente_nome}}, '
                        ."tem visita prevista para {{data_prevista}} sem ordem de serviço correspondente.\n"
                        ."Endereço: {{endereco}}.\n\n"
                        .'Gere a visita ou registre a justificativa, porque a lacuna aparece em auditoria.',
                ],
            ],
        ],

        self::ROTINA_AGENDADA_FALHOU => [
            'rotulo' => 'Rotina automática com problema',
            'destinatario' => NotificationQueue::DESTINATARIO_USUARIO,
            'canais' => [self::CANAL_EMAIL],
            'variaveis' => [
                'empresa_nome',
                'rotina_nome',
                'horario_esperado',
                'ultima_execucao',
                'mensagem_erro',
            ],
            'padrao' => [
                self::CANAL_EMAIL => [
                    'assunto' => 'Rotina automática com problema: {{rotina_nome}}',
                    'corpo' => 'A rotina {{rotina_nome}}, esperada para {{horario_esperado}}, não está rodando '
                        ."como deveria.\n\n"
                        ."Última execução bem-sucedida: {{ultima_execucao}}.\n"
                        ."Mensagem registrada: {{mensagem_erro}}\n\n"
                        .'Enquanto isso não for resolvido, os dados que dependem dela deixam de ser atualizados.',
                ],
            ],
        ],

        self::CONVITE_PORTAL => [
            'rotulo' => 'Convite de acesso ao portal do cliente',
            // DESTINATARIO_USUARIO, e não DESTINATARIO_CLIENTE, de propósito:
            // quem recebe é um ClientUser específico, com e-mail próprio
            // (informado em `destino`/`destinatario_id` no disparo), e não o
            // e-mail de notificação cadastrado no Client. Com
            // DESTINATARIO_CLIENTE, `NotificationService::enfileirar()`
            // aplicaria a preferência de canal do cliente
            // (`aceita_email`/`aceita_whatsapp`) a este envio, e o convite -
            // que é a única forma de o cliente entrar no portal - ficaria
            // bloqueado por uma preferência pensada para lembrete de visita e
            // aviso de cobrança, não para o próprio acesso.
            'destinatario' => NotificationQueue::DESTINATARIO_USUARIO,
            'canais' => [self::CANAL_EMAIL],
            'variaveis' => [
                'cliente_nome',
                'empresa_nome',
                'empresa_telefone',
                'link_convite',
                'dias_de_validade',
            ],
            'padrao' => [
                self::CANAL_EMAIL => [
                    'assunto' => 'Acesso ao portal de {{empresa_nome}}',
                    'corpo' => "Olá, {{cliente_nome}}.\n\n"
                        .'A {{empresa_nome}} liberou um acesso para você acompanhar visitas, certificados e '
                        ."contratos pelo portal do cliente.\n\n"
                        ."Para definir sua senha e entrar, acesse: {{link_convite}}\n"
                        ."Este link vale por {{dias_de_validade}} dias.\n\n"
                        ."Dúvidas? Fale com a gente pelo telefone {{empresa_telefone}}.\n\n"
                        .'{{empresa_nome}}',
                ],
            ],
        ],

        self::RECUPERACAO_SENHA_PORTAL => [
            'rotulo' => 'Recuperação de senha do portal do cliente',
            // Mesmo motivo do evento acima: o destino é o e-mail deste
            // ClientUser específico, forçado no disparo, não o e-mail (nem a
            // preferência de canal) do Client.
            'destinatario' => NotificationQueue::DESTINATARIO_USUARIO,
            'canais' => [self::CANAL_EMAIL],
            'variaveis' => [
                'cliente_nome',
                'empresa_nome',
                'empresa_telefone',
                'link_redefinir_senha',
                'dias_de_validade',
            ],
            'padrao' => [
                self::CANAL_EMAIL => [
                    'assunto' => 'Redefinição de senha do portal',
                    'corpo' => "Olá, {{cliente_nome}}.\n\n"
                        .'Recebemos um pedido para redefinir a senha do seu acesso ao portal de '
                        ."{{empresa_nome}}.\n\n"
                        ."Para escolher uma senha nova, acesse: {{link_redefinir_senha}}\n"
                        ."Este link vale por {{dias_de_validade}} dias.\n\n"
                        ."Se você não pediu essa redefinição, ignore este e-mail: sua senha continua a mesma.\n\n"
                        .'{{empresa_nome}}',
                ],
            ],
        ],

        self::SOLICITACAO_RECEBIDA => [
            'rotulo' => 'Nova solicitação de atendimento do cliente',
            // DESTINATARIO_EMPRESA, mesmo critério de CONTRATO_A_VENCER e
            // ORCAMENTO_A_EXPIRAR: o aviso é interno, só para a empresa saber
            // que uma pendência nova chegou pelo portal, e por isso só aceita
            // e-mail (o fluxo de WhatsApp desta entrega é o link `wa.me` para
            // falar com o cliente, sem sentido apontando para o número da
            // própria empresa).
            'destinatario' => NotificationQueue::DESTINATARIO_EMPRESA,
            'canais' => [self::CANAL_EMAIL],
            'variaveis' => [
                'cliente_nome',
                'empresa_nome',
                'solicitacao_numero',
                'solicitacao_assunto',
                'solicitacao_descricao',
            ],
            'padrao' => [
                self::CANAL_EMAIL => [
                    'assunto' => 'Nova solicitação de {{cliente_nome}}: {{solicitacao_assunto}}',
                    'corpo' => 'O cliente {{cliente_nome}} abriu a solicitação {{solicitacao_numero}} '
                        ."pelo portal.\n\n"
                        ."Assunto: {{solicitacao_assunto}}\n"
                        ."Descrição: {{solicitacao_descricao}}\n\n"
                        .'Acesse o painel para responder.',
                ],
            ],
        ],

        self::SOLICITACAO_RESPONDIDA => [
            'rotulo' => 'Resposta da empresa a uma solicitação de atendimento',
            'destinatario' => NotificationQueue::DESTINATARIO_CLIENTE,
            'canais' => [self::CANAL_EMAIL, self::CANAL_WHATSAPP],
            'variaveis' => [
                'cliente_nome',
                'empresa_nome',
                'empresa_telefone',
                'solicitacao_numero',
                'solicitacao_assunto',
                'solicitacao_resposta',
            ],
            'padrao' => [
                self::CANAL_EMAIL => [
                    'assunto' => 'Resposta da {{empresa_nome}} à sua solicitação',
                    'corpo' => "Olá, {{cliente_nome}}.\n\n"
                        .'Sua solicitação {{solicitacao_numero}} ("{{solicitacao_assunto}}") '
                        ."recebeu uma resposta:\n\n"
                        ."{{solicitacao_resposta}}\n\n"
                        .'Acompanhe pelo portal. Qualquer dúvida, ligue para {{empresa_telefone}}.'
                        ."\n\n"
                        .'{{empresa_nome}}',
                ],
                self::CANAL_WHATSAPP => [
                    'assunto' => null,
                    'corpo' => 'Olá, {{cliente_nome}}. Sua solicitação {{solicitacao_numero}} '
                        .'("{{solicitacao_assunto}}") recebeu uma resposta: {{solicitacao_resposta}} '
                        .'Acompanhe pelo portal. {{empresa_nome}}',
                ],
            ],
        ],
    ];

    /**
     * Chaves dos eventos, na ordem do catálogo.
     *
     * @return array<int, string>
     */
    public static function chaves(): array
    {
        return array_keys(self::EVENTOS);
    }

    /**
     * O evento existe no catálogo?
     */
    public static function existe(string $evento): bool
    {
        return array_key_exists($evento, self::EVENTOS);
    }

    /**
     * Definição completa do evento.
     *
     * Evento fora do catálogo é erro de programação, não entrada de usuário:
     * quem chama escreveu uma chave que não existe. Por isso lança em vez de
     * devolver null, e a mensagem traz as chaves válidas.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public static function definicao(string $evento): array
    {
        if (! self::existe($evento)) {
            throw new InvalidArgumentException(
                "Evento de notificação desconhecido: \"{$evento}\". "
                .'Eventos válidos: '.implode(', ', self::chaves()).'.'
            );
        }

        return self::EVENTOS[$evento];
    }

    /**
     * Rótulo em português do evento.
     */
    public static function rotulo(string $evento): string
    {
        return self::definicao($evento)['rotulo'];
    }

    /**
     * Quem recebe o aviso por padrão: `cliente`, `empresa` ou `usuario`.
     */
    public static function destinatarioPadrao(string $evento): string
    {
        return self::definicao($evento)['destinatario'];
    }

    /**
     * Canais aceitos pelo evento, o primeiro sendo o preferido do sistema.
     *
     * @return array<int, string>
     */
    public static function canais(string $evento): array
    {
        return self::definicao($evento)['canais'];
    }

    /**
     * O evento pode sair por este canal?
     */
    public static function aceitaCanal(string $evento, string $canal): bool
    {
        return in_array($canal, self::canais($evento), true);
    }

    /**
     * Variáveis que o template deste evento pode usar.
     *
     * @return array<int, string>
     */
    public static function variaveis(string $evento): array
    {
        return self::definicao($evento)['variaveis'];
    }

    /**
     * Texto padrão do sistema para o evento no canal, ou null quando o canal
     * não é aceito pelo evento.
     *
     * @return array{assunto: ?string, corpo: string}|null
     */
    public static function templatePadrao(string $evento, string $canal): ?array
    {
        return self::definicao($evento)['padrao'][$canal] ?? null;
    }

    /**
     * Catálogo em formato de lista, para a tela de templates e para o endpoint
     * que informa as variáveis válidas de cada evento.
     *
     * Sai sem o texto padrão: quem monta a tela pede o template com
     * `templatePadrao()` do par evento e canal que está editando.
     *
     * @return array<int, array{
     *     evento: string,
     *     rotulo: string,
     *     destinatario: string,
     *     canais: array<int, string>,
     *     variaveis: array<int, string>
     * }>
     */
    public static function paraTela(): array
    {
        $lista = [];

        foreach (self::EVENTOS as $chave => $definicao) {
            $lista[] = [
                'evento' => $chave,
                'rotulo' => $definicao['rotulo'],
                'destinatario' => $definicao['destinatario'],
                'canais' => $definicao['canais'],
                'variaveis' => $definicao['variaveis'],
            ];
        }

        return $lista;
    }
}
