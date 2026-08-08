<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\OperaPorTenant;
use App\Models\Company;
use App\Models\ComplianceCheck;
use App\Models\OrganRegistration;
use App\Services\Compliance\ValidadeService;
use App\Services\ModuleService;
use App\Services\NotificationService;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rotina diária de conformidade com a RDC nº 622/2022 (Plano 24, Task 24.3):
 * avisa antes de vencer o registro do responsável técnico no conselho, as
 * licenças da empresa e o registro do produto na Anvisa, e alimenta o
 * checklist de conformidade.
 *
 * Três avisos, três cadências
 * ---------------------------
 * - `documento_regulatorio_a_vencer`: um por documento e por marco (60, 30 e 7
 *   dias). O marco é a janela, não o dia exato, e por isso o mesmo documento
 *   gera até três avisos ao longo do tempo sem repetir dentro da mesma janela.
 * - `documento_regulatorio_vencido`: reenviado **semanalmente** enquanto
 *   continuar vencido. É a exceção de "um aviso por marco", pelo mesmo motivo
 *   do lote vencido do Plano 17: documento regulatório vencido tem
 *   consequência perante fiscalização, e o silêncio depois do primeiro aviso
 *   não resolve.
 * - `cadastro_regulatorio_incompleto`: **um por mês**, com a lista do que está
 *   sem validade cadastrada. Documento sem data **não** entra nos avisos de
 *   vencimento: "não informado" e "vencido" são estados diferentes, e acusar de
 *   irregular quem apenas não preencheu destruiria a confiança no checklist.
 *
 * O que ela faz além de avisar
 * ----------------------------
 * 1. Alinha `organ_registrations.situacao` com a validade cadastrada (registro
 *    `cancelado` nunca é tocado: o cancelamento vem do órgão, não de data).
 * 2. Grava o resultado de cada item em `compliance_checks`, que é o estado
 *    atual lido pelo checklist da Task 24.5 e pela tela da Task 24.6.
 *
 * O que ela **não** faz
 * ---------------------
 * Não bloqueia nada. Nenhuma verificação desta entrega impede concluir OS,
 * assinar, emitir documento ou aplicar produto. Bloqueio, quando existir, é
 * plano novo, com o cliente ciente.
 *
 * Isolamento por tenant e por registro
 * ------------------------------------
 * Mesmo padrão de `VerificarEstoque` (Task 17.6): laço por empresa
 * (`OperaPorTenant`, que restaura o tenant anterior e isola o erro de uma
 * empresa das demais) e cada disparo com o próprio try/catch, para que um
 * registro com dado inconsistente não cale os avisos dos outros.
 */
class VerificarValidadesRegulatorias extends Command
{
    use OperaPorTenant;

    protected $signature = 'conformidade:verificar-validades';

    protected $description = 'Classifica as validades regulatórias (conselho, licenças e registro Anvisa), enfileira os avisos e atualiza o checklist de conformidade (Plano 24).';

    /**
     * Resolvidos no `handle()`, e não no construtor, para não custarem em toda
     * chamada de artisan do sistema.
     */
    private ?NotificationService $notificacoes = null;

    private ?ValidadeService $validades = null;

    private ?ModuleService $modulos = null;

    /**
     * Contagem da empresa corrente, zerada a cada volta do laço por tenant.
     *
     * @var array<string, int>
     */
    private array $contagem = [];

    /**
     * Algum registro, em alguma empresa, terminou com erro.
     *
     * Mesmo critério de `VerificarEstoque::$algumRegistroFalhou`: sem este
     * sinal a rotina terminaria com código de sucesso mesmo com avisos que
     * deixaram de sair, e a verificação de rotinas (Task 14.5) não teria como
     * saber.
     */
    private bool $algumRegistroFalhou = false;

    public function handle(NotificationService $notificacoes, ValidadeService $validades, ModuleService $modulos): int
    {
        $this->notificacoes = $notificacoes;
        $this->validades = $validades;
        $this->modulos = $modulos;

        $this->info('Verificando validades regulatórias (RDC 622/2022)...');

        $codigo = $this->paraCadaTenant(fn (Company $empresa): int => $this->verificarDaEmpresa($empresa));

        return $codigo === Command::SUCCESS && $this->algumRegistroFalhou ? Command::FAILURE : $codigo;
    }

    /**
     * Uma empresa, já dentro do tenant dela.
     *
     * @return int quantidade de avisos novos enfileirados nesta empresa
     */
    private function verificarDaEmpresa(Company $empresa): int
    {
        // Interruptor da rotina: o módulo `conformidade` nasce desligado
        // (`CatalogoDeModulos`, `sempre_ativo => false`). É isso que permite
        // subir esta entrega sem que nenhum aviso saia, preencher as validades
        // reais do tenant com calma, rodar a verificação à mão e conferir o
        // checklist, e só então ligar — a ordem de aplicação registrada no
        // INDEX do plano. Sem o gate, o primeiro deploy dispararia, para todo
        // tenant, o aviso mensal de cadastro incompleto de quatro documentos
        // que ninguém teve ainda a chance de preencher.
        if (! $this->modulos->ativo('conformidade', $empresa)) {
            $this->line('  Módulo de conformidade desligado nesta empresa: nada a verificar.');

            return 0;
        }

        $this->contagem = ['enfileirados' => 0, 'duplicados' => 0, 'sem_envio' => 0, 'erros' => 0];

        $situacoesAlteradas = $this->validades->sincronizarSituacaoDosRegistros();
        $this->line("  Registros de produto com situação atualizada: {$situacoesAlteradas}");

        // Recarrega a empresa dentro do tenant: `OperaPorTenant` entrega a
        // instância lida antes do laço, e a validade pode ter sido alterada
        // entre a leitura e esta passada (a rotina roda de madrugada, mas o
        // comando também é rodado à mão depois de o tenant preencher o
        // cadastro).
        $empresa->refresh();

        $resultado = $this->validades->verificar($empresa);

        $this->avisarDocumentosDaEmpresa($empresa, $resultado['documentos']);
        $this->avisarRegistrosDeProduto($resultado['registros']);
        $this->avisarCadastroIncompleto($empresa, $resultado);
        $this->gravarChecklist($resultado);

        $this->line("  Avisos novos: {$this->contagem['enfileirados']}");
        $this->line("  Já estavam na fila: {$this->contagem['duplicados']}");
        $this->line('  Sem envio (falta de e-mail ou de template): '.$this->contagem['sem_envio']);
        $this->line("  Registros com erro: {$this->contagem['erros']}");

        return $this->contagem['enfileirados'];
    }

    // -----------------------------------------------------------------
    // Avisos
    // -----------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $documentos
     */
    private function avisarDocumentosDaEmpresa(Company $empresa, array $documentos): void
    {
        foreach ($documentos as $documento) {
            $marco = $this->marcoDoItem($documento);

            if ($marco === null) {
                continue;
            }

            $this->enfileirar(
                $this->eventoDoItem($documento),
                $empresa,
                [
                    'marco' => $this->validades->marcoDoDocumento((string) $documento['item'], $marco),
                    'variaveis' => $this->variaveis($documento),
                ],
                "documento {$documento['item']} da empresa #{$empresa->id}"
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $registros
     */
    private function avisarRegistrosDeProduto(array $registros): void
    {
        foreach ($registros as $linha) {
            /** @var OrganRegistration $registro */
            $registro = $linha['registro'];

            $marco = $this->marcoDoItem($linha);

            if ($marco === null) {
                continue;
            }

            // A referência aqui é o próprio registro, e não a empresa: cada
            // registro tem número e validade próprios, e quem recebe o e-mail
            // precisa saber qual deles renovar.
            $this->enfileirar(
                $this->eventoDoItem($linha),
                $registro,
                [
                    'marco' => (string) $marco,
                    'variaveis' => $this->variaveis($linha),
                ],
                "registro na Anvisa #{$registro->id}"
            );
        }
    }

    /**
     * Aviso mensal com a lista do que está sem validade cadastrada.
     *
     * Um aviso só para a empresa inteira, e não um por documento: quem abre o
     * cadastro preenche tudo de uma vez, e quatro e-mails no mesmo dia dizendo
     * a mesma coisa é o caminho mais curto para a empresa criar uma regra de
     * caixa de entrada e deixar de ler também os avisos que importam.
     *
     * O registro de produto sem validade **não** entra nesta lista de
     * propósito: o cadastro de registros pode ter dezenas de linhas herdadas do
     * catálogo inicial do tenant, e listar todas transformaria o lembrete num
     * despejo. O que falta ali aparece no checklist da Task 24.5, que é onde a
     * empresa consegue tratar item a item.
     *
     * @param  array{documentos: array<int, array<string, mixed>>, registros: array<int, array<string, mixed>>}  $resultado
     */
    private function avisarCadastroIncompleto(Company $empresa, array $resultado): void
    {
        $pendentes = $this->validades->rotulosNaoInformados($resultado['documentos']);

        if ($pendentes->isEmpty()) {
            return;
        }

        $this->line("  Documentos sem validade cadastrada: {$pendentes->count()}");

        $this->enfileirar(
            EventosDeNotificacao::CADASTRO_REGULATORIO_INCOMPLETO,
            $empresa,
            [
                'marco' => $this->validades->marcoMensal(),
                'variaveis' => [
                    'documentos_pendentes' => $pendentes->map(fn (string $rotulo): string => "- {$rotulo}")->implode("\n"),
                ],
            ],
            "cadastro regulatório da empresa #{$empresa->id}"
        );
    }

    // -----------------------------------------------------------------
    // Checklist
    // -----------------------------------------------------------------

    /**
     * Grava em `compliance_checks` o estado atual de cada item.
     *
     * Os quatro documentos da empresa viram um item cada. Os registros de
     * produto viram **um item só** (`registros_de_produto`), com a situação
     * mais grave encontrada e o detalhe listando os problemáticos: a tabela tem
     * unique `[company_id, item]` com `item` em `varchar(60)`, então uma chave
     * por registro faria a tabela crescer junto com o catálogo sem caber num
     * checklist legível.
     *
     * `updateOrCreate`, e não `insert`: a tabela guarda o estado atual, não o
     * histórico. Histórico de conformidade é a auditoria do Plano 3.
     *
     * @param  array{documentos: array<int, array<string, mixed>>, registros: array<int, array<string, mixed>>}  $resultado
     */
    private function gravarChecklist(array $resultado): void
    {
        $agora = BusinessDate::agora();

        foreach ($resultado['documentos'] as $documento) {
            ComplianceCheck::query()->updateOrCreate(
                ['item' => (string) $documento['item']],
                [
                    'situacao' => $this->situacaoDoChecklist((string) $documento['situacao']),
                    'detalhe' => (string) $documento['detalhe'],
                    'verificado_em' => $agora,
                ]
            );
        }

        ComplianceCheck::query()->updateOrCreate(
            ['item' => ValidadeService::ITEM_REGISTROS_DE_PRODUTO],
            $this->resumoDosRegistros($resultado['registros'], $agora)
        );
    }

    /**
     * Situação agregada dos registros de produto e a frase que a explica.
     *
     * Vale a **pior** situação encontrada, porque um único registro vencido já
     * é o que a fiscalização enxerga. Sem registro nenhum cadastrado o item é
     * `nao_aplicavel`, e não `irregular`: empresa que ainda não cadastrou
     * produto não está descumprindo nada.
     *
     * @param  array<int, array<string, mixed>>  $registros
     * @return array<string, mixed>
     */
    private function resumoDosRegistros(array $registros, mixed $agora): array
    {
        if ($registros === []) {
            return [
                'situacao' => ComplianceCheck::SITUACAO_NAO_APLICAVEL,
                'detalhe' => 'Nenhum registro de produto na Anvisa cadastrado.',
                'verificado_em' => $agora,
            ];
        }

        $porSituacao = collect($registros)->groupBy('situacao');

        $pior = collect([
            ValidadeService::SITUACAO_IRREGULAR,
            ValidadeService::SITUACAO_ATENCAO,
            ValidadeService::SITUACAO_NAO_INFORMADO,
            ValidadeService::SITUACAO_REGULAR,
        ])->first(fn (string $situacao): bool => $porSituacao->has($situacao)) ?? ValidadeService::SITUACAO_REGULAR;

        $problemas = collect($registros)
            ->filter(fn (array $linha): bool => $linha['situacao'] !== ValidadeService::SITUACAO_REGULAR)
            ->map(fn (array $linha): string => (string) $linha['detalhe'])
            ->take(20)
            ->implode(' ');

        $total = count($registros);

        return [
            'situacao' => $this->situacaoDoChecklist($pior),
            'detalhe' => $problemas === ''
                ? "Os {$total} registro(s) de produto cadastrados estão com registro válido na Anvisa."
                : $problemas,
            'verificado_em' => $agora,
        ];
    }

    /**
     * Traduz a situação do `ValidadeService` para a do checklist.
     *
     * `nao_informado` vira `nao_aplicavel`, e **nunca** `irregular`: a coluna
     * `situacao` de `compliance_checks` não tem um quarto estado para "campo em
     * branco", e mapear lacuna de cadastro para irregularidade é exatamente a
     * informação falsa que este plano existe para não produzir. O texto de
     * `detalhe` explica que a validade não foi informada.
     */
    private function situacaoDoChecklist(string $situacao): string
    {
        return match ($situacao) {
            ValidadeService::SITUACAO_REGULAR => ComplianceCheck::SITUACAO_REGULAR,
            ValidadeService::SITUACAO_ATENCAO => ComplianceCheck::SITUACAO_ATENCAO,
            ValidadeService::SITUACAO_IRREGULAR => ComplianceCheck::SITUACAO_IRREGULAR,
            default => ComplianceCheck::SITUACAO_NAO_APLICAVEL,
        };
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Marco do aviso deste item, ou `null` quando ele não gera aviso hoje.
     *
     * Vencido devolve o marco semanal (reenvio enquanto durar). A vencer
     * devolve a janela atingida (60, 30 ou 7), e `null` quando ainda falta
     * mais que 60 dias. `nao_informado` e `regular` nunca geram aviso de
     * vencimento.
     *
     * @param  array<string, mixed>  $item
     */
    private function marcoDoItem(array $item): string|int|null
    {
        if ($item['situacao'] === ValidadeService::SITUACAO_IRREGULAR) {
            return $this->validades->marcoSemanal();
        }

        if ($item['situacao'] !== ValidadeService::SITUACAO_ATENCAO) {
            return null;
        }

        return $this->validades->marcoAtingido($item['dias_para_vencer'] === null ? null : (int) $item['dias_para_vencer']);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function eventoDoItem(array $item): string
    {
        return $item['situacao'] === ValidadeService::SITUACAO_IRREGULAR
            ? EventosDeNotificacao::DOCUMENTO_REGULATORIO_VENCIDO
            : EventosDeNotificacao::DOCUMENTO_REGULATORIO_A_VENCER;
    }

    /**
     * Variáveis do template, iguais para os dois eventos de vencimento.
     *
     * `dias_vencido` sai positivo (o e-mail diz "há 12 dias", e não "há -12
     * dias"), enquanto `dias_para_vencer` mantém o sinal já usado pelos demais
     * avisos do sistema.
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function variaveis(array $item): array
    {
        $dias = $item['dias_para_vencer'] === null ? null : (int) $item['dias_para_vencer'];

        return [
            'documento_nome' => $item['rotulo'],
            'documento_numero' => $item['numero'] ?? 'não informado',
            'data_vencimento' => BusinessDate::paraFusoNegocio($item['validade'])?->format('d/m/Y') ?? 'não informada',
            'dias_para_vencer' => $dias,
            'dias_vencido' => $dias === null ? null : abs($dias),
        ];
    }

    /**
     * Um disparo, com o erro isolado no registro que o causou.
     *
     * Sem este try/catch, um registro com dado inconsistente derrubaria a
     * empresa inteira em `OperaPorTenant` e os demais avisos do dia não
     * sairiam. Mesmo critério de `VerificarEstoque::enfileirar()`.
     *
     * @param  array<string, mixed>  $opcoes
     */
    private function enfileirar(string $evento, Model $referencia, array $opcoes, string $rotulo): void
    {
        try {
            $resultado = $this->notificacoes->enfileirar($evento, $referencia, $opcoes);

            match ($resultado['resultado']) {
                NotificationService::RESULTADO_ENFILEIRADA => $this->contagem['enfileirados']++,
                NotificationService::RESULTADO_DUPLICADA => $this->contagem['duplicados']++,
                default => $this->contagem['sem_envio']++,
            };
        } catch (Throwable $erro) {
            $this->contagem['erros']++;
            $this->algumRegistroFalhou = true;

            report($erro);

            Log::error('Falha ao enfileirar aviso de validade regulatória.', [
                'evento' => $evento,
                'registro' => $rotulo,
                'mensagem' => $erro->getMessage(),
            ]);

            $this->error("  Falha no {$rotulo} ({$evento}): {$erro->getMessage()}");
            $this->line('  Os demais registros continuam sendo processados.');
        }
    }
}
