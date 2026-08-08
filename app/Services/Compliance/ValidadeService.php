<?php

namespace App\Services\Compliance;

use App\Models\Company;
use App\Models\NotificationQueue;
use App\Models\OrganRegistration;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Validade dos documentos regulatórios exigidos pela RDC nº 622/2022
 * (Plano 24, Task 24.3).
 *
 * A norma exige da empresa especializada, para funcionar: licença sanitária,
 * licença ambiental, alvará de funcionamento e responsável técnico habilitado
 * com registro no conselho — todos **válidos**. E, na execução, só produto
 * saneante desinfestante **registrado** na Anvisa. Este serviço lê essas cinco
 * frentes, classifica cada uma e devolve o resultado. Quem transforma isso em
 * aviso é a rotina `conformidade:verificar-validades`; quem transforma em
 * checklist é a Task 24.5.
 *
 * "Não informado" nunca é "irregular"
 * -----------------------------------
 * As quatro situações são estados distintos e nunca se confundem:
 *
 * - `regular`: validade preenchida e a mais de 60 dias de vencer.
 * - `atencao`: validade preenchida e vencendo em até 60 dias (inclusive hoje).
 * - `irregular`: validade preenchida e já vencida.
 * - `nao_informado`: validade em branco.
 *
 * `nao_informado` **não** vira `irregular`. Hoje o cadastro inteiro de todo
 * tenant está em branco, porque as colunas nasceram nulas na Task 24.1; dizer
 * a essas empresas que elas estão irregulares seria informação falsa e
 * destruiria a confiança no checklist inteiro. O que `nao_informado` gera é um
 * aviso diferente, de cadastro incompleto, mensal, com a lista do que falta.
 *
 * Nada aqui bloqueia nada
 * -----------------------
 * Este serviço classifica e informa. Não impede concluir OS, assinar, emitir
 * certificado nem aplicar produto. Travar a operação do cliente por
 * interpretação de norma é pior que o problema que se quer resolver; quando
 * algum destes avisos virar bloqueio, será plano novo, com o cliente ciente.
 *
 * Comparação por dia, no fuso do negócio
 * --------------------------------------
 * `validade` é campo `date`: representa um dia, sem hora. A comparação usa
 * `BusinessDate::hoje()` (America/Sao_Paulo), e não `now()` em UTC. Uma licença
 * que vence hoje **não** está vencida às 21h de Brasília, e é exatamente isso
 * que aconteceria comparando com UTC.
 */
class ValidadeService
{
    public const SITUACAO_REGULAR = 'regular';

    public const SITUACAO_ATENCAO = 'atencao';

    public const SITUACAO_IRREGULAR = 'irregular';

    public const SITUACAO_NAO_INFORMADO = 'nao_informado';

    /**
     * Dias de antecedência a partir dos quais o documento entra em `atencao`.
     *
     * Sessenta dias é o maior dos marcos de aviso (60, 30 e 7): o documento
     * passa a "atenção" no mesmo instante em que o primeiro aviso sai, para
     * que a tela e o e-mail nunca contem histórias diferentes.
     */
    public const DIAS_DE_ATENCAO = 60;

    /**
     * Marcos de aviso antes do vencimento, do mais distante ao mais próximo.
     *
     * Mesma escada de `StockAlertService::lotesVencendo()` (Plano 17): 60 para
     * dar tempo de protocolar a renovação no órgão, 30 para cobrar o
     * andamento, 7 porque a partir dali já é urgência.
     *
     * @var array<int, int>
     */
    public const MARCOS = [60, 30, 7];

    /**
     * Documentos regulatórios guardados em `companies`.
     *
     * Cada entrada declara o rótulo em português (que vai no e-mail e na
     * tela), a coluna de validade criada na Task 24.1 e a coluna que já
     * guardava o número do documento. `numero` é informativo: um documento sem
     * número cadastrado e sem validade continua sendo `nao_informado`, e não
     * `irregular`.
     *
     * `registro_conselho` cobre o registro do responsável técnico no conselho
     * profissional (CRQ, CREA, CRBio ou CRMV, conforme a habilitação). O
     * sistema guarda dois números para isso (`crq` e `register_crea`), porque
     * o cadastro é anterior a esta entrega; a coluna lida aqui é
     * `register_crea`, com `crq` como alternativa, e quem tiver preenchido
     * qualquer um dos dois tem o número exibido.
     *
     * @var array<string, array{rotulo: string, validade: string, numero: array<int, string>}>
     */
    public const DOCUMENTOS_DA_EMPRESA = [
        'registro_conselho' => [
            'rotulo' => 'Registro do responsável técnico no conselho',
            'validade' => 'registro_conselho_validade',
            'numero' => ['register_crea', 'crq'],
        ],
        'licenca_sanitaria' => [
            'rotulo' => 'Licença sanitária',
            'validade' => 'licenca_sanitaria_validade',
            'numero' => ['license_sanitary'],
        ],
        'licenca_ambiental' => [
            'rotulo' => 'Licença ambiental',
            'validade' => 'licenca_ambiental_validade',
            'numero' => ['license_environmental'],
        ],
        'licenca_funcionamento' => [
            'rotulo' => 'Alvará de funcionamento',
            'validade' => 'licenca_funcionamento_validade',
            'numero' => ['license_business'],
        ],
    ];

    /**
     * Chave do item de checklist que agrupa os registros de produto.
     *
     * Um item só para todos os registros, e não um por registro:
     * `compliance_checks` tem unique `[company_id, item]` e `item` é
     * `varchar(60)`, então uma chave por registro faria a tabela crescer com o
     * catálogo e ainda assim não caberia num checklist legível. O aviso, esse
     * sim, sai por registro, porque quem renova o registro precisa saber qual.
     */
    public const ITEM_REGISTROS_DE_PRODUTO = 'registros_de_produto';

    /**
     * Situação de cada documento regulatório da empresa e de cada registro de
     * produto.
     *
     * Precisa ser chamado dentro do tenant da empresa (a rotina usa
     * `OperaPorTenant`): os registros de produto são lidos com o escopo global
     * de `OrganRegistration`.
     *
     * @return array{
     *     documentos: array<int, array{item: string, rotulo: string, situacao: string, validade: ?string, dias_para_vencer: ?int, numero: ?string, detalhe: string}>,
     *     registros: array<int, array{registro: OrganRegistration, item: string, rotulo: string, situacao: string, validade: ?string, dias_para_vencer: ?int, numero: ?string, detalhe: string}>
     * }
     */
    public function verificar(Company $empresa): array
    {
        return [
            'documentos' => $this->documentosDaEmpresa($empresa),
            'registros' => $this->registrosDeProduto(),
        ];
    }

    /**
     * Os quatro documentos regulatórios guardados em `companies`.
     *
     * @return array<int, array{item: string, rotulo: string, situacao: string, validade: ?string, dias_para_vencer: ?int, numero: ?string, detalhe: string}>
     */
    public function documentosDaEmpresa(Company $empresa): array
    {
        $itens = [];

        foreach (self::DOCUMENTOS_DA_EMPRESA as $item => $definicao) {
            $validade = BusinessDate::paraFusoNegocio($empresa->getAttribute($definicao['validade']));
            $classificacao = $this->classificar($validade);

            $itens[] = [
                'item' => $item,
                'rotulo' => $definicao['rotulo'],
                'situacao' => $classificacao['situacao'],
                'validade' => $validade?->format('Y-m-d'),
                'dias_para_vencer' => $classificacao['dias_para_vencer'],
                'numero' => $this->primeiroNumeroPreenchido($empresa, $definicao['numero']),
                'detalhe' => $this->detalhe($definicao['rotulo'], $classificacao, $validade),
            ];
        }

        return $itens;
    }

    /**
     * Situação de cada registro de produto na Anvisa do tenant corrente.
     *
     * Registro `cancelado` é sempre `irregular`, independentemente da data: o
     * cancelamento é publicado pelo órgão e não tem como ser inferido de
     * validade nenhuma. Um registro cancelado com validade futura continua
     * cancelado.
     *
     * @return array<int, array{registro: OrganRegistration, item: string, rotulo: string, situacao: string, validade: ?string, dias_para_vencer: ?int, numero: ?string, detalhe: string}>
     */
    public function registrosDeProduto(): array
    {
        return OrganRegistration::query()
            ->orderBy('id')
            ->get()
            ->map(function (OrganRegistration $registro): array {
                $validade = BusinessDate::paraFusoNegocio($registro->validade);
                $numero = filled($registro->record) ? (string) $registro->record : null;
                $rotulo = 'Registro na Anvisa'.($numero === null ? '' : " {$numero}");

                if ($registro->situacao === OrganRegistration::SITUACAO_CANCELADO) {
                    return [
                        'registro' => $registro,
                        'item' => self::ITEM_REGISTROS_DE_PRODUTO,
                        'rotulo' => $rotulo,
                        'situacao' => self::SITUACAO_IRREGULAR,
                        'validade' => $validade?->format('Y-m-d'),
                        'dias_para_vencer' => $validade === null ? null : $this->diasPara($validade),
                        'numero' => $numero,
                        'detalhe' => "{$rotulo}: cancelado pelo órgão. Produto com registro cancelado não pode ser aplicado.",
                    ];
                }

                $classificacao = $this->classificar($validade);

                return [
                    'registro' => $registro,
                    'item' => self::ITEM_REGISTROS_DE_PRODUTO,
                    'rotulo' => $rotulo,
                    'situacao' => $classificacao['situacao'],
                    'validade' => $validade?->format('Y-m-d'),
                    'dias_para_vencer' => $classificacao['dias_para_vencer'],
                    'numero' => $numero,
                    'detalhe' => $this->detalhe($rotulo, $classificacao, $validade),
                ];
            })
            ->all();
    }

    /**
     * Classifica uma validade em `regular`, `atencao`, `irregular` ou
     * `nao_informado`.
     *
     * `dias_para_vencer` é positivo antes do vencimento, `0` no próprio dia e
     * negativo depois — o mesmo sinal já usado por `LOTE_PROXIMO_DO_VENCIMENTO`
     * e `CONTRATO_A_VENCER`, para que quem lê os avisos do sistema não precise
     * aprender uma convenção nova por módulo. É `null` quando a validade não
     * foi informada, e nunca `0`: zero significaria "vence hoje".
     *
     * @return array{situacao: string, dias_para_vencer: ?int}
     */
    public function classificar(mixed $validade): array
    {
        $dia = BusinessDate::paraFusoNegocio($validade);

        if ($dia === null) {
            return ['situacao' => self::SITUACAO_NAO_INFORMADO, 'dias_para_vencer' => null];
        }

        $dias = $this->diasPara($dia);

        if ($dias < 0) {
            return ['situacao' => self::SITUACAO_IRREGULAR, 'dias_para_vencer' => $dias];
        }

        if ($dias <= self::DIAS_DE_ATENCAO) {
            return ['situacao' => self::SITUACAO_ATENCAO, 'dias_para_vencer' => $dias];
        }

        return ['situacao' => self::SITUACAO_REGULAR, 'dias_para_vencer' => $dias];
    }

    /**
     * Marco de aviso que este documento atingiu hoje, ou `null` quando ele não
     * caiu exatamente em nenhum.
     *
     * O marco é a **janela** (60, 30 ou 7), e não o `dias_para_vencer` exato:
     * é isso que permite ao mesmo documento gerar até três avisos ao longo do
     * tempo, um por marco alcançado, sem repetir enquanto continuar dentro da
     * mesma janela. Mesmo critério de `VerificarEstoque::lotesProximosDoVencimento()`.
     *
     * Um documento cadastrado já dentro da janela de 7 dias atinge os três
     * marcos de uma vez, e é isso mesmo que se quer: o marco mais apertado é o
     * que descreve a urgência real.
     */
    public function marcoAtingido(?int $diasParaVencer): ?int
    {
        if ($diasParaVencer === null || $diasParaVencer < 0) {
            return null;
        }

        // Do marco mais apertado para o mais largo: um documento a 30 dias de
        // vencer atinge o marco 30, e não o 60. Percorrer na ordem declarada
        // devolveria sempre o primeiro que couber (o 60), e aí o documento
        // repetiria o mesmo marco em toda a janela e nunca chegaria aos avisos
        // de 30 e de 7 — que são justamente os que a empresa precisa receber
        // quando o prazo aperta.
        foreach (array_reverse(self::MARCOS) as $marco) {
            if ($diasParaVencer <= $marco) {
                return $marco;
            }
        }

        return null;
    }

    /**
     * Marco semanal do reenvio do documento já vencido.
     *
     * Muda a cada sete dias corridos, contados no fuso do negócio a partir de
     * uma época fixa, e não a cada virada de semana do calendário: um documento
     * descoberto vencido numa sexta reenvia sete dias depois, e não já na
     * segunda seguinte.
     *
     * Existe pelo mesmo motivo do lote vencido do Plano 17: sem o marco variar,
     * a chave de idempotência do primeiro aviso valeria para sempre e o
     * documento vencido ficaria esquecido depois de um único e-mail. Documento
     * regulatório vencido tem consequência perante fiscalização, e o silêncio
     * depois do primeiro aviso não resolve.
     *
     * Idêntico, de propósito, a `VerificarEstoque::marcoSemanal()`. Não foi
     * extraído para um utilitário comum porque são duas rotinas que podem
     * divergir de cadência sem uma arrastar a outra, e a duplicação são cinco
     * linhas sem regra de negócio.
     */
    public function marcoSemanal(): string
    {
        $epoca = CarbonImmutable::createFromDate(1970, 1, 1, BusinessDate::fuso())->startOfDay();
        $diasDesdeAEpoca = (int) $epoca->diffInDays(BusinessDate::hoje());

        return 'semana-'.intdiv($diasDesdeAEpoca, 7);
    }

    /**
     * Marco mensal do aviso de cadastro incompleto: `YYYY-MM` no fuso do
     * negócio.
     *
     * Cadastro em branco não é urgência: é pendência administrativa que a
     * empresa resolve quando puder abrir o sistema com os documentos em mãos.
     * Um aviso por mês lembra sem virar ruído — e ruído, aqui, faria a empresa
     * filtrar também os avisos de vencimento, que importam.
     */
    public function marcoMensal(): string
    {
        return BusinessDate::hoje()->format('Y-m');
    }

    /**
     * Alinha `organ_registrations.situacao` com a validade cadastrada, no
     * tenant corrente.
     *
     * Registro com validade no passado vira `vencido`; registro com validade
     * futura, ou sem validade informada, volta a `ativo`. `cancelado` **nunca**
     * é tocado: o cancelamento é publicado pelo órgão, não é derivável de data
     * nenhuma, e voltar sozinho para `ativo` faria o sistema afirmar que um
     * produto proibido está liberado.
     *
     * Sem validade informada a situação é `ativo`, e não `vencido`, pela mesma
     * regra de sempre: "não informado" nunca é "irregular".
     *
     * @return int quantidade de registros que mudaram de situação
     */
    public function sincronizarSituacaoDosRegistros(): int
    {
        $alterados = 0;

        OrganRegistration::query()
            ->where('situacao', '!=', OrganRegistration::SITUACAO_CANCELADO)
            ->orderBy('id')
            ->each(function (OrganRegistration $registro) use (&$alterados): void {
                $situacao = $this->situacaoDerivadaDaValidade($registro);

                if ($registro->situacao === $situacao) {
                    return;
                }

                $registro->situacao = $situacao;
                $registro->save();
                $alterados++;
            });

        return $alterados;
    }

    /**
     * Situação que a validade do registro impõe: `vencido` quando a data já
     * passou, `ativo` no resto. Nunca devolve `cancelado`, que só vem de fora.
     */
    public function situacaoDerivadaDaValidade(OrganRegistration $registro): string
    {
        $classificacao = $this->classificar($registro->validade);

        return $classificacao['situacao'] === self::SITUACAO_IRREGULAR
            ? OrganRegistration::SITUACAO_VENCIDO
            : OrganRegistration::SITUACAO_ATIVO;
    }

    // -----------------------------------------------------------------
    // Encerramento dos avisos quando a validade é atualizada
    // -----------------------------------------------------------------

    /**
     * Cancela os avisos ainda pendentes de um documento da empresa.
     *
     * Chamado quando o tenant atualiza a validade (ver
     * `App\Observers\ValidadeRegulatoriaObserver`), para que o aviso pare **na
     * hora**, e não só na próxima execução da rotina. Sem isso, quem renovasse
     * a licença de manhã continuaria recebendo, no despacho das 08:00, o
     * e-mail dizendo que ela vence — e um aviso que mente é um aviso que a
     * empresa aprende a ignorar.
     *
     * Só toca em `pendente`: aviso já enviado é histórico e não se reescreve, e
     * aviso `enviando` está com o despachante no meio do caminho.
     *
     * A referência dos dois eventos é a própria `Company`, então o que separa
     * um documento do outro dentro da chave de idempotência é o marco, que
     * `marcoDoDocumento()` escreve como `{item}-{marco}`. O `LIKE` abaixo casa
     * exatamente esse prefixo, com os dois-pontos nas pontas para que
     * `company:1:` nunca alcance `company:11:`.
     *
     * @return int quantidade de avisos cancelados
     */
    public function encerrarAvisosDoDocumento(Company $empresa, string $item): int
    {
        return $this->cancelarPendentes(
            [EventosDeNotificacao::DOCUMENTO_REGULATORIO_A_VENCER, EventosDeNotificacao::DOCUMENTO_REGULATORIO_VENCIDO],
            '%:company:'.$empresa->getKey().':'.$item.'-%'
        );
    }

    /**
     * Cancela os avisos ainda pendentes de um registro de produto.
     *
     * Aqui a referência da chave é o próprio `OrganRegistration`, então o
     * padrão termina no id: qualquer marco (60, 30, 7 ou a semana do reenvio)
     * daquele registro é alcançado.
     *
     * @return int quantidade de avisos cancelados
     */
    public function encerrarAvisosDoRegistro(OrganRegistration $registro): int
    {
        return $this->cancelarPendentes(
            [EventosDeNotificacao::DOCUMENTO_REGULATORIO_A_VENCER, EventosDeNotificacao::DOCUMENTO_REGULATORIO_VENCIDO],
            '%:organ_registration:'.$registro->getKey().':%'
        );
    }

    /**
     * Marco da chave de idempotência de um documento da empresa.
     *
     * Formato `{item}-{marco}`, por exemplo `licenca_sanitaria-30` ou
     * `licenca_sanitaria-semana-2934`. Precisa carregar o item porque os quatro
     * documentos compartilham a mesma referência (`Company`): sem ele, avisar
     * da licença sanitária calaria o aviso da ambiental no mesmo marco.
     */
    public function marcoDoDocumento(string $item, string|int $marco): string
    {
        return $item.'-'.$marco;
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * @param  array<int, string>  $eventos
     */
    private function cancelarPendentes(array $eventos, string $padrao): int
    {
        return NotificationQueue::query()
            ->whereIn('evento', $eventos)
            ->where('situacao', NotificationQueue::SITUACAO_PENDENTE)
            ->where('chave_idempotencia', 'like', $padrao)
            ->update(['situacao' => NotificationQueue::SITUACAO_CANCELADA]);
    }

    /**
     * Dias entre hoje e o dia informado, no fuso do negócio. Positivo no
     * futuro, `0` hoje, negativo no passado.
     */
    private function diasPara(CarbonImmutable $dia): int
    {
        return (int) BusinessDate::hoje()->diffInDays($dia->startOfDay(), false);
    }

    /**
     * Primeiro valor preenchido entre as colunas candidatas.
     *
     * @param  array<int, string>  $colunas
     */
    private function primeiroNumeroPreenchido(Company $empresa, array $colunas): ?string
    {
        foreach ($colunas as $coluna) {
            $valor = $empresa->getAttribute($coluna);

            if (filled($valor)) {
                return (string) $valor;
            }
        }

        return null;
    }

    /**
     * Frase em português explicando a situação, que vai para `detalhe` em
     * `compliance_checks` e para a tela da Task 24.6.
     *
     * O texto de `nao_informado` é deliberadamente neutro: ele descreve uma
     * lacuna de cadastro, e não uma acusação de irregularidade.
     *
     * @param  array{situacao: string, dias_para_vencer: ?int}  $classificacao
     */
    private function detalhe(string $rotulo, array $classificacao, ?CarbonImmutable $validade): string
    {
        $data = $validade?->format('d/m/Y');
        $dias = $classificacao['dias_para_vencer'];

        return match ($classificacao['situacao']) {
            self::SITUACAO_NAO_INFORMADO => "{$rotulo}: validade não informada no cadastro. "
                .'Preencha para o sistema poder avisar antes do vencimento.',
            self::SITUACAO_IRREGULAR => "{$rotulo}: vencido em {$data}, há ".abs((int) $dias).' dia(s).',
            self::SITUACAO_ATENCAO => $dias === 0
                ? "{$rotulo}: vence hoje, {$data}."
                : "{$rotulo}: vence em {$data}, daqui a {$dias} dia(s).",
            default => "{$rotulo}: válido até {$data}.",
        };
    }

    /**
     * Itens em `nao_informado`, para o aviso mensal de cadastro incompleto.
     *
     * @param  array<int, array{situacao: string, rotulo: string}>  $itens
     * @return Collection<int, string>
     */
    public function rotulosNaoInformados(array $itens): Collection
    {
        return collect($itens)
            ->filter(fn (array $item): bool => $item['situacao'] === self::SITUACAO_NAO_INFORMADO)
            ->map(fn (array $item): string => $item['rotulo'])
            ->values();
    }
}
