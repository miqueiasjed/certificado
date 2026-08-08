<?php

namespace App\Services\Compliance;

use App\Models\Company;
use App\Models\ComplianceCheck;
use App\Models\NormativeReference;
use App\Models\Technician;
use App\Services\ModuleService;
use App\Services\PendenciasDeContratoService;
use App\Services\Ppe\SituacaoDeEpiService;
use App\Support\BusinessDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Checklist de conformidade com a RDC nº 622/2022 (Plano 24, Task 24.5).
 *
 * Mostra à empresa, item por item, o que o sistema consegue verificar sobre a
 * regularidade dela — e o que fazer a respeito de cada pendência.
 *
 * O checklist informa, não certifica
 * ----------------------------------
 * Toda resposta carrega `ressalva`, e ela é obrigatória. Dizer "sua empresa
 * está regular" seria assumir responsabilidade que não é da plataforma: parte
 * do que a norma exige (veículo de transporte adequado, treinamento da equipe,
 * arquivo físico, letreiro na fachada, licença afixada em local visível) o
 * sistema nem enxerga. O que ele pode afirmar é o que está no cadastro dele.
 *
 * EPI saiu dessa lista no Plano 29 (Task 29.4)
 * --------------------------------------------
 * Até então o EPI era o exemplo declarado do que "o sistema nem enxerga", ao
 * mesmo tempo em que o contrato emitido prometia ao cliente que a equipe usa
 * EPI. Com o cadastro do Plano 28 e a situação do técnico da Task 29.2, o
 * fornecimento passou a ser dado, e `ITEM_EPI_DOS_TECNICOS` o mostra. O que o
 * item comprova continua sendo **o que está registrado** — quem entregou o quê,
 * a quem, com CA e confirmação de recebimento —, e nunca que a empresa está
 * regular em segurança do trabalho: ASO, treinamento, PGR e o uso efetivo em
 * campo seguem fora do alcance do sistema, e por isso a ressalva geral continua
 * obrigatória.
 *
 * "Não informado" nunca é "irregular"
 * -----------------------------------
 * `ComplianceCheck::SITUACAO_NAO_APLICAVEL` cobre dois casos distintos, e
 * `detalhe` é quem os separa em português: o item que não se aplica à empresa
 * (nenhum produto cadastrado, nenhum contrato periódico) e o item cujo dado
 * ainda não foi preenchido. Nenhum dos dois é irregularidade. Acusar de
 * irregular quem apenas não preencheu um campo destruiria a confiança no
 * checklist inteiro, e hoje o cadastro de validades de todo tenant está em
 * branco, porque as colunas nasceram nulas na Task 24.1.
 *
 * Cada item aponta o caminho de correção
 * --------------------------------------
 * Checklist que diz "irregular" sem dizer o que fazer não é usado. Cada item
 * traz `exigencia` (o que a norma pede), `detalhe` (o que está acontecendo
 * aqui) e `acao` (o texto e a rota da tela onde se resolve).
 *
 * Nada aqui bloqueia nada
 * -----------------------
 * Este serviço é leitura pura, com uma única escrita: `verificar()` grava o
 * resultado em `compliance_checks`, que é a mesma tabela que a rotina diária
 * alimenta. Nenhuma situação registrada impede concluir OS, assinar ou emitir
 * documento.
 */
class ChecklistService
{
    /**
     * Meses de OS conferidos no item de documentação da execução: o último
     * trimestre, que é a janela pedida pelo plano. Suficiente para a empresa
     * corrigir o que está fresco na memória de quem executou, sem transformar
     * a abertura do checklist numa varredura do histórico inteiro.
     */
    public const MESES_DE_EXECUCAO_CONFERIDOS = 3;

    public const ITEM_REFERENCIA_NORMATIVA = 'referencia_normativa';

    public const ITEM_DOCUMENTACAO_DA_EXECUCAO = 'documentacao_da_execucao';

    public const ITEM_VISITAS_DE_CONTRATO = 'visitas_de_contrato';

    /**
     * Fornecimento de EPI aos técnicos (Plano 29, Task 29.4).
     *
     * Item condicional: só existe para o tenant com o módulo `epi` ligado, e é
     * por isso que `verificar()` limpa o que saiu do checklist.
     */
    public const ITEM_EPI_DOS_TECNICOS = 'epi_dos_tecnicos';

    /**
     * Quantos técnicos com pendência de EPI são nomeados no `detalhe`.
     *
     * Mesmo limite dos exemplos dos outros itens: o suficiente para a empresa
     * saber por onde começar, sem transformar uma linha do checklist na lista
     * inteira da equipe.
     */
    private const TECNICOS_NOMEADOS_NO_DETALHE = 10;

    public function __construct(
        private readonly ValidadeService $validades,
        private readonly ConformidadeDaExecucaoService $execucao,
        private readonly PendenciasDeContratoService $pendenciasDeContrato,
        private readonly ModuleService $modulos,
        private readonly SituacaoDeEpiService $situacaoDeEpi,
    ) {}

    /**
     * Monta o checklist da empresa a partir do estado atual do cadastro.
     *
     * Não grava nada: é a leitura que a tela abre. Quem grava é `verificar()`.
     *
     * @return array{
     *     ressalva: string,
     *     verificado_em: ?string,
     *     resumo: array{total: int, regular: int, atencao: int, irregular: int, nao_aplicavel: int},
     *     itens: array<int, array{item: string, rotulo: string, situacao: string, detalhe: string, exigencia: string, acao: array{texto: string, rota: ?string}}>
     * }
     */
    public function montar(Company $empresa): array
    {
        // `array_filter` porque o item de EPI é o primeiro condicional do
        // checklist: ele devolve `null` para o tenant sem o módulo `epi`, em
        // vez de ocupar uma linha falando de uma funcionalidade que a empresa
        // não contratou.
        $itens = array_values(array_filter(array_merge(
            $this->itensDeValidade($empresa),
            [
                $this->itemDosRegistrosDeProduto(),
                $this->itemDaReferenciaNormativa($empresa),
                $this->itemDaDocumentacaoDaExecucao(),
                $this->itemDasVisitasDeContrato(),
                $this->itemDoEpiDosTecnicos($empresa),
            ]
        )));

        return [
            'ressalva' => $this->ressalva(),
            'verificado_em' => $this->ultimaVerificacao(),
            'resumo' => $this->resumir($itens),
            'itens' => $itens,
        ];
    }

    /**
     * Recalcula e grava o estado de cada item em `compliance_checks`.
     *
     * Existe além da rotina diária porque quem acaba de preencher a validade
     * de uma licença quer ver o checklist mudar agora, e não amanhã de manhã.
     * `updateOrCreate` porque a tabela guarda o estado atual, não o histórico
     * (o histórico de conformidade é a auditoria do Plano 3).
     *
     * @return array<string, mixed> o checklist já recalculado
     */
    public function verificar(Company $empresa): array
    {
        $checklist = $this->montar($empresa);
        $agora = BusinessDate::agora();

        foreach ($checklist['itens'] as $item) {
            ComplianceCheck::query()->updateOrCreate(
                ['item' => $item['item']],
                [
                    'situacao' => $item['situacao'],
                    'detalhe' => $item['detalhe'],
                    'verificado_em' => $agora,
                ]
            );
        }

        // Item que saiu do checklist não pode deixar estado velho para trás.
        // A tabela guarda o **estado atual**, e a partir da Task 29.4 existe
        // item condicional: desligar o módulo `epi` tira `epi_dos_tecnicos` de
        // `montar()`, e sem esta limpeza a linha antiga continuaria contando
        // como irregular no resumo do painel, sem tela nenhuma onde resolvê-la.
        // O `company_id` explícito não confia só no escopo global: apagar por
        // engano a linha de outro tenant seria pior que a sobra.
        ComplianceCheck::query()
            ->where('company_id', $empresa->getKey())
            ->whereNotIn('item', array_column($checklist['itens'], 'item'))
            ->delete();

        $checklist['verificado_em'] = $agora->toIso8601String();

        return $checklist;
    }

    /**
     * Resumo do painel principal, lido de `compliance_checks`.
     *
     * Do que está **gravado**, e não de `montar()`: montar o checklist
     * percorre as OS concluídas do último trimestre e as pendências de
     * contrato, o que é aceitável ao abrir a tela de conformidade e caro
     * demais para acontecer em toda abertura do painel principal. É
     * exatamente para isso que `compliance_checks` guarda o estado atual.
     *
     * A contrapartida é que o número do painel é o da última verificação (a
     * rotina diária, ou o botão de recalcular), e não o do instante. Por isso
     * `verificado_em` vai junto: o painel mostra de quando é a foto, em vez de
     * fingir que ela é de agora.
     *
     * Devolve tudo zerado, com `verificado_em` nulo, quando a verificação
     * nunca rodou nesta empresa — o que é o caso de todo tenant enquanto o
     * módulo `conformidade` estiver desligado.
     *
     * @return array{total: int, regular: int, atencao: int, irregular: int, nao_aplicavel: int, verificado_em: ?string}
     */
    public function resumoParaPainel(): array
    {
        $porSituacao = ComplianceCheck::query()
            ->selectRaw('situacao, count(*) as total')
            ->groupBy('situacao')
            ->pluck('total', 'situacao');

        return [
            'total' => (int) $porSituacao->sum(),
            'regular' => (int) $porSituacao->get(ComplianceCheck::SITUACAO_REGULAR, 0),
            'atencao' => (int) $porSituacao->get(ComplianceCheck::SITUACAO_ATENCAO, 0),
            'irregular' => (int) $porSituacao->get(ComplianceCheck::SITUACAO_IRREGULAR, 0),
            'nao_aplicavel' => (int) $porSituacao->get(ComplianceCheck::SITUACAO_NAO_APLICAVEL, 0),
            'verificado_em' => $this->ultimaVerificacao(),
        ];
    }

    /**
     * Ressalva obrigatória, presente em toda resposta do checklist.
     *
     * EPI saiu da lista na Task 29.4, porque virou dado do sistema. O que
     * continua verdadeiro, e por isso a ressalva continua obrigatória: o item
     * de EPI comprova o **fornecimento registrado**, e não que a empresa esteja
     * regular em segurança do trabalho.
     */
    public function ressalva(): string
    {
        return 'Este checklist lista o que o sistema consegue verificar a partir do seu cadastro. '
            .'Ele não atesta que a empresa está regular perante a RDC 622/2022: parte do que a norma '
            .'exige (transporte dos produtos, treinamento da equipe, licença afixada em local '
            .'visível, letreiro na fachada, arquivo físico) acontece fora do sistema.';
    }

    // -----------------------------------------------------------------
    // Itens
    // -----------------------------------------------------------------

    /**
     * Registro do responsável técnico no conselho e as três licenças.
     *
     * @return array<int, array<string, mixed>>
     */
    private function itensDeValidade(Company $empresa): array
    {
        $exigencias = [
            'registro_conselho' => 'A empresa especializada precisa ter responsável técnico habilitado, '
                .'com registro válido no conselho profissional (CRQ, CREA, CRBio ou CRMV, conforme a '
                .'habilitação), e o nome e o registro dele saem impressos no comprovante de serviço.',
            'licenca_sanitaria' => 'A empresa precisa de licença sanitária válida, emitida pela vigilância '
                .'sanitária competente, afixada em local visível ao público.',
            'licenca_ambiental' => 'A empresa precisa de licença ambiental válida, emitida pelo órgão '
                .'ambiental competente.',
            'licenca_funcionamento' => 'A empresa precisa de alvará de funcionamento válido.',
        ];

        return collect($this->validades->documentosDaEmpresa($empresa))
            ->map(fn (array $documento): array => [
                'item' => (string) $documento['item'],
                'rotulo' => (string) $documento['rotulo'],
                'situacao' => $this->traduzirSituacao((string) $documento['situacao']),
                'detalhe' => (string) $documento['detalhe'],
                'exigencia' => $exigencias[$documento['item']] ?? '',
                'acao' => $this->acao('Preencher em Configurações > Validades', 'settings.validades.edit'),
            ])
            ->all();
    }

    /**
     * Registro dos produtos na Anvisa, agregado num item só.
     *
     * Um item, e não um por registro, pelo mesmo motivo já registrado em
     * `ValidadeService::ITEM_REGISTROS_DE_PRODUTO`: a unique de
     * `compliance_checks` é `[company_id, item]` com `item` em `varchar(60)`,
     * e um checklist com dezenas de linhas iguais não é lido por ninguém.
     *
     * Vale a **pior** situação encontrada: um único registro vencido já é o que
     * a fiscalização enxerga. Sem registro nenhum cadastrado o item é
     * `nao_aplicavel`, e não `irregular` — empresa que ainda não cadastrou
     * produto não está descumprindo nada.
     *
     * @return array<string, mixed>
     */
    private function itemDosRegistrosDeProduto(): array
    {
        $registros = $this->validades->registrosDeProduto();

        $exigencia = 'Na prestação do serviço só podem ser usados produtos saneantes desinfestantes '
            .'registrados na Anvisa e dentro da validade.';

        if ($registros === []) {
            return [
                'item' => ValidadeService::ITEM_REGISTROS_DE_PRODUTO,
                'rotulo' => 'Registro dos produtos na Anvisa',
                'situacao' => ComplianceCheck::SITUACAO_NAO_APLICAVEL,
                'detalhe' => 'Nenhum registro de produto na Anvisa cadastrado. Não há o que verificar aqui '
                    .'enquanto a empresa não cadastrar os produtos que aplica.',
                'exigencia' => $exigencia,
                'acao' => $this->acao('Cadastrar em Registros no órgão', 'organ-registrations.index'),
            ];
        }

        $problemas = collect($registros)
            ->filter(fn (array $linha): bool => $linha['situacao'] !== ValidadeService::SITUACAO_REGULAR)
            ->map(fn (array $linha): string => (string) $linha['detalhe'])
            ->take(20);

        $pior = collect([
            ValidadeService::SITUACAO_IRREGULAR,
            ValidadeService::SITUACAO_ATENCAO,
            ValidadeService::SITUACAO_NAO_INFORMADO,
            ValidadeService::SITUACAO_REGULAR,
        ])->first(
            fn (string $situacao): bool => collect($registros)->contains('situacao', $situacao)
        ) ?? ValidadeService::SITUACAO_REGULAR;

        return [
            'item' => ValidadeService::ITEM_REGISTROS_DE_PRODUTO,
            'rotulo' => 'Registro dos produtos na Anvisa',
            'situacao' => $this->traduzirSituacao($pior),
            'detalhe' => $problemas->isEmpty()
                ? 'Os '.count($registros).' registro(s) cadastrados estão com registro válido na Anvisa.'
                : $problemas->implode(' '),
            'exigencia' => $exigencia,
            'acao' => $this->acao('Revisar em Registros no órgão', 'organ-registrations.index'),
        ];
    }

    /**
     * Referência normativa configurada.
     *
     * `atencao`, e nunca `irregular`, quando só existe a da plataforma: o
     * tenant não está irregular por usar o padrão do sistema — que hoje é
     * justamente a RDC 622/2022 correta. O aviso existe para o dia em que a
     * Anvisa publicar a substituta e o tenant precisar decidir se espera a
     * plataforma atualizar ou cadastra a dele.
     *
     * @return array<string, mixed>
     */
    private function itemDaReferenciaNormativa(Company $empresa): array
    {
        $exigencia = 'Os documentos emitidos (certificado, ordem de serviço, contrato e recibo) precisam '
            .'citar a resolução vigente. Documento com resolução revogada é documento desatualizado na '
            .'mão do fiscal.';

        $doTenant = NormativeReference::query()
            ->where('company_id', $empresa->getKey())
            ->where('chave', NormativeReference::CHAVE_PRINCIPAL)
            ->where('ativo', true)
            ->first();

        $vigente = NormativeReference::resolver((int) $empresa->getKey());

        if ($doTenant !== null) {
            return [
                'item' => self::ITEM_REFERENCIA_NORMATIVA,
                'rotulo' => 'Referência normativa dos documentos',
                'situacao' => ComplianceCheck::SITUACAO_REGULAR,
                'detalhe' => "Os documentos citam a referência cadastrada pela empresa: {$doTenant->texto}.",
                'exigencia' => $exigencia,
                'acao' => $this->acao('Revisar em Conformidade > Referência normativa', 'conformidade.referencias.index'),
            ];
        }

        if ($vigente !== null) {
            return [
                'item' => self::ITEM_REFERENCIA_NORMATIVA,
                'rotulo' => 'Referência normativa dos documentos',
                'situacao' => ComplianceCheck::SITUACAO_ATENCAO,
                'detalhe' => "Os documentos citam a referência padrão do sistema: {$vigente->texto}. "
                    .'Confirme que é a resolução vigente para a sua atividade ou cadastre a da empresa.',
                'exigencia' => $exigencia,
                'acao' => $this->acao('Cadastrar em Conformidade > Referência normativa', 'conformidade.referencias.index'),
            ];
        }

        return [
            'item' => self::ITEM_REFERENCIA_NORMATIVA,
            'rotulo' => 'Referência normativa dos documentos',
            'situacao' => ComplianceCheck::SITUACAO_IRREGULAR,
            'detalhe' => 'Nenhuma referência normativa cadastrada: os documentos emitidos estão saindo sem '
                .'citar a resolução.',
            'exigencia' => $exigencia,
            'acao' => $this->acao('Cadastrar em Conformidade > Referência normativa', 'conformidade.referencias.index'),
        ];
    }

    /**
     * Documentação das OS do último trimestre.
     *
     * @return array<string, mixed>
     */
    private function itemDaDocumentacaoDaExecucao(): array
    {
        $hoje = BusinessDate::hoje();
        $de = $hoje->subMonths(self::MESES_DE_EXECUCAO_CONFERIDOS)->format('Y-m-d');
        $ate = $hoje->format('Y-m-d');

        $exigencia = 'O comprovante entregue ao cliente precisa informar praga-alvo, produto utilizado, '
            .'quantidade, data de execução e responsável técnico (RDC 622/2022, art. 19).';

        $pendencias = $this->execucao->pendenciasDoPeriodo($de, $ate);

        if ($pendencias === []) {
            return [
                'item' => self::ITEM_DOCUMENTACAO_DA_EXECUCAO,
                'rotulo' => 'Documentação das execuções do último trimestre',
                'situacao' => ComplianceCheck::SITUACAO_REGULAR,
                'detalhe' => 'Nenhuma ordem de serviço concluída nos últimos '
                    .self::MESES_DE_EXECUCAO_CONFERIDOS.' meses está com documentação incompleta.',
                'exigencia' => $exigencia,
                'acao' => $this->acao('Ver ordens de serviço', 'work-orders.index'),
            ];
        }

        $resumo = $this->execucao->resumir($pendencias);

        return [
            'item' => self::ITEM_DOCUMENTACAO_DA_EXECUCAO,
            'rotulo' => 'Documentação das execuções do último trimestre',
            'situacao' => ComplianceCheck::SITUACAO_ATENCAO,
            'detalhe' => "{$resumo['com_pendencia']} ordem(ns) de serviço com documentação incompleta e "
                ."{$resumo['com_aviso']} com aviso de registro de produto. "
                .$resumo['detalhes']->take(10)->implode('; ').'.',
            'exigencia' => $exigencia,
            'acao' => $this->acao('Completar em Conformidade > Pendências de execução', 'conformidade.pendencias-de-execucao'),
        ];
    }

    /**
     * Visitas periódicas previstas em contrato e não geradas (pendência do
     * Plano 9).
     *
     * Item de leitura: quem gera a visita ou registra a justificativa é o
     * painel de pendências de contrato, que já existe. Aqui ele aparece porque
     * visita periódica prevista e não executada é lacuna que aparece em
     * auditoria, e o checklist é onde a empresa olha antes de uma
     * fiscalização.
     *
     * `nao_aplicavel` quando a empresa não tem contrato periódico nenhum: não
     * ter contrato não é irregularidade.
     *
     * @return array<string, mixed>
     */
    private function itemDasVisitasDeContrato(): array
    {
        $exigencia = 'Visita periódica prevista em contrato e não executada é lacuna no histórico do '
            .'cliente, e é o que a fiscalização compara com o certificado vigente.';

        $acao = $this->acao('Ver pendências de contrato', 'contracts.pendencias');

        try {
            $pendencias = $this->pendenciasDeContrato->listar();
        } catch (Throwable) {
            // Módulo de contratos indisponível, ou configuração de alerta
            // ausente: o checklist inteiro não pode quebrar por causa de um
            // item. Sai como não aplicável, que é a leitura honesta de "não
            // deu para verificar", e nunca como irregular.
            return [
                'item' => self::ITEM_VISITAS_DE_CONTRATO,
                'rotulo' => 'Visitas periódicas previstas em contrato',
                'situacao' => ComplianceCheck::SITUACAO_NAO_APLICAVEL,
                'detalhe' => 'Não foi possível verificar as visitas periódicas de contrato neste momento.',
                'exigencia' => $exigencia,
                'acao' => $acao,
            ];
        }

        if ($pendencias === []) {
            return [
                'item' => self::ITEM_VISITAS_DE_CONTRATO,
                'rotulo' => 'Visitas periódicas previstas em contrato',
                'situacao' => ComplianceCheck::SITUACAO_NAO_APLICAVEL,
                'detalhe' => 'Nenhuma pendência de visita periódica. Contrato periódico sem lacuna, ou '
                    .'nenhum contrato periódico vigente.',
                'exigencia' => $exigencia,
                'acao' => $acao,
            ];
        }

        $exemplos = collect($pendencias)
            ->take(10)
            ->map(fn (array $linha): string => sprintf(
                'contrato %s (%s)',
                $linha['contract_number'] ?? $linha['contrato_id'],
                implode(', ', $linha['motivos'])
            ))
            ->implode('; ');

        return [
            'item' => self::ITEM_VISITAS_DE_CONTRATO,
            'rotulo' => 'Visitas periódicas previstas em contrato',
            'situacao' => ComplianceCheck::SITUACAO_ATENCAO,
            'detalhe' => count($pendencias).' contrato(s) periódico(s) com pendência de visita: '.$exemplos.'.',
            'exigencia' => $exigencia,
            'acao' => $acao,
        ];
    }

    /**
     * Fornecimento de EPI aos técnicos ativos (Plano 29, Task 29.4).
     *
     * É o item que fecha o buraco que este próprio serviço declarava por
     * escrito. Ele responde uma pergunta só: **o fornecimento de EPI aos
     * técnicos que estão em campo está registrado como a NR-6 exige?**
     *
     * O item é condicional
     * --------------------
     * Devolve `null` — some do checklist — para o tenant sem o módulo `epi`.
     * No Deploy 1 deste plano o módulo nasce desligado em todo mundo, e uma
     * linha "não aplicável" falando de uma funcionalidade que a empresa não
     * contratou é ruído em cima do item que ela precisa ler. Quem limpa o
     * estado gravado quando o módulo é desligado depois é `verificar()`.
     *
     * "Não informado" nunca é "irregular"
     * -----------------------------------
     * A regra mais importante desta task, e a mesma do resto do serviço. Dois
     * estados caem em `SITUACAO_NAO_APLICAVEL`, e `detalhe` é quem os explica
     * em português:
     *
     * - nenhum técnico ativo cadastrado — não há a quem fornecer EPI;
     * - nenhum EPI marcado como obrigatório no cadastro — o dado ainda não foi
     *   preenchido, que é exatamente o estado de quem acabou de ligar o módulo.
     *
     * Nenhum dos dois é irregularidade. Acusar de irregular quem apenas não
     * preencheu destruiria a confiança no checklist inteiro, e aqui o estrago
     * seria maior que nos demais itens: "irregular em EPI" é a acusação que
     * ninguém quer ver por engano numa tela de conformidade.
     *
     * O que **é** irregular
     * ---------------------
     * Técnico ativo com troca vencida, com entrega sem assinatura ou que nunca
     * recebeu um EPI obrigatório. Os três entram juntos de propósito: a entrega
     * sem assinatura é justamente a prova que falta (NR-6, item 6.5.1), e sem
     * ela a empresa fornece o equipamento e continua sem como demonstrar isso.
     *
     * O cálculo não é feito aqui
     * --------------------------
     * Quem decide a situação de cada técnico é `SituacaoDeEpiService`
     * (Task 29.2), que por sua vez tira a regra de troca vencida de
     * `AlertaDeEpiService::trocaVencida()` — a origem única do sistema. Este
     * item só conta e redige: uma segunda comparação de data aqui divergiria do
     * alerta semanal do Plano 28, e divergência de vencimento é bug que ninguém
     * encontra depois.
     *
     * Técnico inativo sai da conta: a ficha dele continua existindo (e é
     * justamente a de quem saiu que costuma ser pedida depois), mas ele não
     * executa mais, e cobrar EPI de quem não vai a campo é ruído garantido —
     * mesmo critério de `AlertaDeEpiService::trocasVencidas()`.
     *
     * @return array<string, mixed>|null
     */
    private function itemDoEpiDosTecnicos(Company $empresa): ?array
    {
        if (! $this->modulos->ativo('epi', $empresa)) {
            return null;
        }

        $exigencia = 'O fornecimento de EPI ao trabalhador precisa ser registrado, com o equipamento, o '
            .'número do CA, a data e a confirmação de recebimento (NR-6, item 6.5.1), e o registro '
            .'eletrônico precisa permitir a extração de relatórios (item 6.5.1.1). O uso e a manutenção '
            .'dos equipamentos também precisam estar descritos no POP da empresa especializada '
            .'(RDC 622/2022).';

        $cadastrar = $this->acao('Cadastrar em Controle de EPI', 'epi.index');
        $ficha = $this->acao('Abrir a ficha de EPI do técnico em Técnicos', 'technicians.index');

        $tecnicos = Technician::query()
            ->where('company_id', $empresa->getKey())
            ->where('is_active', true)
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        if ($tecnicos->isEmpty()) {
            return $this->itemDeEpi(
                ComplianceCheck::SITUACAO_NAO_APLICAVEL,
                'Nenhum técnico ativo cadastrado. Não há a quem fornecer EPI, e portanto não há o que '
                .'verificar aqui.',
                $exigencia,
                $cadastrar
            );
        }

        $situacoes = $tecnicos->map(fn (Technician $tecnico): array => $this->situacaoDeEpi->doTecnico($tecnico));

        // Quantos modelos de EPI obrigatório o cadastro tem hoje, lido do
        // próprio `SituacaoDeEpiService` (é o `total` do resumo dele), em vez
        // de uma segunda consulta com o critério repetido aqui.
        $obrigatorios = (int) $situacoes->max(fn (array $situacao): int => (int) $situacao['resumo']['total']);

        if ($obrigatorios === 0) {
            return $this->itemDeEpi(
                ComplianceCheck::SITUACAO_NAO_APLICAVEL,
                'Nenhum equipamento está marcado como obrigatório no cadastro de EPI: o dado ainda não '
                .'foi preenchido, e isso não é irregularidade. Marque como obrigatórios os equipamentos '
                .'que a operação exige para o checklist passar a conferir o fornecimento a cada técnico.',
                $exigencia,
                $cadastrar
            );
        }

        $contagem = $this->contarSituacoesDeEpi($situacoes);

        if ($contagem['pendentes'] === 0) {
            return $this->itemDeEpi(
                ComplianceCheck::SITUACAO_REGULAR,
                sprintf(
                    'Os %d técnico(s) ativo(s) estão em dia com os %d EPI(s) obrigatório(s) do cadastro: '
                    .'entrega registrada, dentro da troca programada e com a confirmação de recebimento '
                    .'assinada.',
                    $tecnicos->count(),
                    $obrigatorios
                ),
                $exigencia,
                $ficha
            );
        }

        return $this->itemDeEpi(
            ComplianceCheck::SITUACAO_IRREGULAR,
            sprintf(
                '%d de %d técnico(s) ativo(s) em dia com os %d EPI(s) obrigatório(s). '
                .'%d com troca vencida, %d com entrega sem assinatura e %d que nunca recebeu EPI '
                .'obrigatório — um mesmo técnico pode aparecer em mais de uma pendência: %s.',
                $contagem[SituacaoDeEpiService::EM_DIA],
                $tecnicos->count(),
                $obrigatorios,
                $contagem[SituacaoDeEpiService::TROCA_VENCIDA],
                $contagem[SituacaoDeEpiService::SEM_ASSINATURA],
                $contagem[SituacaoDeEpiService::NUNCA_RECEBEU],
                implode('; ', $contagem['exemplos'])
            ),
            $exigencia,
            $ficha
        );
    }

    /**
     * Contagem de técnicos por situação de EPI, mais os nomes que vão no
     * `detalhe`.
     *
     * Conta **técnicos**, e não itens de EPI: a pergunta do checklist é quantas
     * pessoas estão em campo com pendência, e um técnico com três luvas
     * vencidas é uma pessoa, não três. Pelo mesmo motivo ele pode aparecer em
     * mais de uma coluna — quem tem uma troca vencida e outra entrega sem
     * assinatura conta nas duas —, e o `detalhe` diz isso em português para
     * ninguém somar as parcelas e estranhar o total.
     *
     * @param  Collection<int, array<string, mixed>>  $situacoes
     * @return array<string, mixed>
     */
    private function contarSituacoesDeEpi(Collection $situacoes): array
    {
        $contagem = [
            SituacaoDeEpiService::EM_DIA => 0,
            SituacaoDeEpiService::TROCA_VENCIDA => 0,
            SituacaoDeEpiService::SEM_ASSINATURA => 0,
            SituacaoDeEpiService::NUNCA_RECEBEU => 0,
            'pendentes' => 0,
            'exemplos' => [],
        ];

        foreach ($situacoes as $situacao) {
            if ($situacao['regular']) {
                $contagem[SituacaoDeEpiService::EM_DIA]++;

                continue;
            }

            $contagem['pendentes']++;

            $pendentes = array_column($situacao['pendencias'], 'situacao');

            // Na ordem declarada de `SITUACOES_PENDENTES`, e não na ordem em
            // que as pendências apareceram: o texto do checklist precisa sair
            // igual para o mesmo estado, senão a linha "muda" a cada
            // verificação sem nada ter mudado.
            $rotulos = [];

            foreach (SituacaoDeEpiService::SITUACOES_PENDENTES as $pendencia) {
                if (! in_array($pendencia, $pendentes, true)) {
                    continue;
                }

                $contagem[$pendencia]++;
                $rotulos[] = SituacaoDeEpiService::ROTULOS_DE_SITUACAO[$pendencia];
            }

            if (count($contagem['exemplos']) < self::TECNICOS_NOMEADOS_NO_DETALHE) {
                $contagem['exemplos'][] = sprintf(
                    '%s (%s)',
                    $situacao['tecnico']['nome'] ?? 'técnico '.$situacao['tecnico']['id'],
                    implode(', ', $rotulos)
                );
            }
        }

        return $contagem;
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Uma linha do item de EPI, com o rótulo e a chave sempre iguais.
     *
     * @param  array{texto: string, rota: ?string}  $acao
     * @return array<string, mixed>
     */
    private function itemDeEpi(string $situacao, string $detalhe, string $exigencia, array $acao): array
    {
        return [
            'item' => self::ITEM_EPI_DOS_TECNICOS,
            'rotulo' => 'Fornecimento de EPI aos técnicos',
            'situacao' => $situacao,
            'detalhe' => $detalhe,
            'exigencia' => $exigencia,
            'acao' => $acao,
        ];
    }

    /**
     * Traduz a situação do `ValidadeService` para a do checklist.
     *
     * `nao_informado` vira `nao_aplicavel`, e **nunca** `irregular`: a coluna
     * `situacao` de `compliance_checks` não tem estado próprio para "campo em
     * branco", e mapear lacuna de cadastro para irregularidade é exatamente a
     * informação falsa que este plano existe para não produzir. É `detalhe`
     * quem diz, em português, que a validade não foi informada.
     */
    private function traduzirSituacao(string $situacao): string
    {
        return match ($situacao) {
            ValidadeService::SITUACAO_REGULAR => ComplianceCheck::SITUACAO_REGULAR,
            ValidadeService::SITUACAO_ATENCAO => ComplianceCheck::SITUACAO_ATENCAO,
            ValidadeService::SITUACAO_IRREGULAR => ComplianceCheck::SITUACAO_IRREGULAR,
            default => ComplianceCheck::SITUACAO_NAO_APLICAVEL,
        };
    }

    /**
     * Ação de correção do item.
     *
     * A rota é resolvida por nome e devolve `null` quando ela não existe (por
     * exemplo, o módulo de contratos desligado no tenant). A tela mostra o
     * texto sem link nesse caso, em vez de estourar ao montar a resposta.
     *
     * @return array{texto: string, rota: ?string}
     */
    private function acao(string $texto, string $nomeDaRota): array
    {
        return [
            'texto' => $texto,
            'rota' => Route::has($nomeDaRota) ? $nomeDaRota : null,
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $itens
     * @return array{total: int, regular: int, atencao: int, irregular: int, nao_aplicavel: int}
     */
    private function resumir(array $itens): array
    {
        $lista = collect($itens);

        return [
            'total' => $lista->count(),
            'regular' => $lista->where('situacao', ComplianceCheck::SITUACAO_REGULAR)->count(),
            'atencao' => $lista->where('situacao', ComplianceCheck::SITUACAO_ATENCAO)->count(),
            'irregular' => $lista->where('situacao', ComplianceCheck::SITUACAO_IRREGULAR)->count(),
            'nao_aplicavel' => $lista->where('situacao', ComplianceCheck::SITUACAO_NAO_APLICAVEL)->count(),
        ];
    }

    /**
     * Instante da última verificação gravada, em ISO 8601, ou `null` quando a
     * rotina nunca rodou nesta empresa.
     */
    private function ultimaVerificacao(): ?string
    {
        return ComplianceCheck::query()
            ->orderByDesc('verificado_em')
            ->value('verificado_em')?->toIso8601String();
    }
}
