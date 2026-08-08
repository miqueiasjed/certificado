<?php

namespace App\Services\Compliance;

use App\Models\OrganRegistration;
use App\Models\Product;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Conferência da documentação da execução, à luz da RDC nº 622/2022
 * (Plano 24, Task 24.4).
 *
 * O que a norma pede do comprovante de serviço
 * --------------------------------------------
 * O art. 19 da RDC 622/2022 lista o conteúdo mínimo do comprovante entregue ao
 * cliente: nome do cliente, endereço do imóvel, praga(s) alvo, data de
 * execução, prazo de assistência técnica, grupo(s) químico(s), nome e
 * concentração de uso do(s) produto(s), orientações, nome e registro do
 * responsável técnico, telefone do Centro de Informação Toxicológica e
 * identificação completa da empresa.
 *
 * Boa parte disso o sistema já garante estruturalmente e não precisa ser
 * conferido OS a OS: cliente e endereço são chaves obrigatórias da própria OS;
 * grupo químico, antídoto e registro na Anvisa saem do cadastro do produto no
 * PDF; identificação da empresa, telefone do CIT e responsável técnico saem do
 * cadastro da empresa no cabeçalho do documento. O que **varia por execução**,
 * e por isso é o que esta conferência olha, são os cinco itens abaixo.
 *
 * Isto **não bloqueia nada**
 * --------------------------
 * Nenhuma verificação daqui impede concluir OS, assinar, emitir certificado ou
 * aplicar produto. Ela informa o que falta, para a empresa completar antes de
 * uma fiscalização. Travar a operação por interpretação de norma é pior que o
 * problema que se quer resolver; bloqueio, quando existir, é plano novo, com o
 * cliente ciente. Este serviço não tem um único caminho de escrita em banco —
 * é só leitura e classificação.
 *
 * A data que vale é a da aplicação
 * --------------------------------
 * Para decidir se o registro do produto estava válido, vale a data em que o
 * serviço foi executado (`end_time` do fechamento em campo, e na falta dela
 * `scheduled_date`), nunca a data em que o aplicativo conseguiu sincronizar.
 * Um técnico que aplica na sexta e sincroniza na segunda não pode ver a
 * aplicação classificada pela segunda-feira. Mesma regra do lote vencido do
 * Plano 17.
 *
 * Por que a irregularidade do registro é derivada, e não gravada
 * --------------------------------------------------------------
 * A marcação "este produto estava com registro vencido na data da aplicação"
 * não ganhou coluna própria: ela é calculada, sempre que perguntada, a partir
 * de dois dados que o sistema já guarda de forma definitiva — a data de
 * execução da OS e a `validade`/`situacao` do registro. O resultado é
 * reproduzível e auditável sem estrutura nova, e a estrutura desta entrega
 * inteira coube no Deploy 1 (Task 24.1), antes de qualquer aviso existir.
 *
 * A contrapartida, registrada aqui de propósito: se o tenant corrigir depois
 * uma `validade` que estava errada no cadastro, as marcações passadas mudam
 * junto. É o comportamento desejado — a marcação segue o dado verdadeiro do
 * registro, não uma foto de um cadastro que estava errado —, mas quem for
 * transformar isto em bloqueio precisará congelar a informação no momento da
 * aplicação.
 */
class ConformidadeDaExecucaoService
{
    /**
     * Itens conferidos por OS, na ordem em que aparecem na tela.
     */
    public const ITEM_PRODUTO = 'produto_aplicado';

    public const ITEM_QUANTIDADE = 'quantidade_aplicada';

    public const ITEM_PRAGA_ALVO = 'praga_alvo';

    public const ITEM_RESPONSAVEL = 'responsavel_pela_execucao';

    public const ITEM_DATA = 'data_de_execucao';

    /**
     * Item que não é falta de documentação, e sim irregularidade do registro
     * do produto aplicado. Fica separado dos cinco acima porque a providência
     * é outra: os cinco se resolvem completando a OS, este se resolve
     * renovando o registro (ou trocando o produto).
     */
    public const ITEM_REGISTRO_DO_PRODUTO = 'registro_do_produto';

    /**
     * Conferência da documentação de uma execução.
     *
     * Devolve sempre a mesma estrutura, com a lista do que falta. Lista vazia
     * significa documentação completa **no que o sistema consegue verificar** —
     * e é por isso que a resposta carrega `ressalva`: conformidade regulatória
     * depende de fatores que o sistema não enxerga, e afirmar o contrário seria
     * assumir responsabilidade que não é da plataforma.
     *
     * @return array{
     *     work_order_id: int,
     *     order_number: ?string,
     *     data_da_execucao: ?string,
     *     completa: bool,
     *     pendencias: array<int, array{item: string, rotulo: string, detalhe: string, exigencia: string}>,
     *     avisos: array<int, array{item: string, rotulo: string, detalhe: string, exigencia: string}>,
     *     ressalva: string
     * }
     */
    public function conferir(WorkOrder $os): array
    {
        $os->loadMissing(['products', 'pestSightings', 'technician', 'technicians', 'signature', 'rooms']);

        $data = $this->dataDaExecucao($os);

        $pendencias = array_values(array_filter([
            $this->conferirProduto($os),
            $this->conferirQuantidade($os),
            $this->conferirPragaAlvo($os),
            $this->conferirResponsavel($os),
            $this->conferirData($data),
        ]));

        return [
            'work_order_id' => (int) $os->id,
            'order_number' => $os->order_number,
            'data_da_execucao' => $data?->format('Y-m-d'),
            'completa' => $pendencias === [],
            'pendencias' => $pendencias,
            'avisos' => $this->avisosDeRegistroDaOs($os, $data),
            'ressalva' => $this->ressalva(),
        ];
    }

    /**
     * OS concluídas do período com documentação incompleta ou com aviso de
     * registro irregular.
     *
     * Para a empresa completar o que falta **antes** de uma fiscalização, em
     * vez de descobrir a lacuna com o fiscal na frente. Roda no tenant
     * corrente: `WorkOrder` é model de domínio e o escopo global já filtra.
     *
     * As datas chegam como `Y-m-d` e são interpretadas no fuso do negócio,
     * porque `scheduled_date` é campo `date`: converter fuso em campo sem hora
     * é o que produz o erro de um dia numa janela de período.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendenciasDoPeriodo(string $de, string $ate): array
    {
        $inicio = BusinessDate::paraFusoNegocio($de);
        $fim = BusinessDate::paraFusoNegocio($ate);

        if ($inicio === null || $fim === null || $inicio->greaterThan($fim)) {
            return [];
        }

        return WorkOrder::query()
            ->where('status', 'completed')
            ->whereBetween('scheduled_date', [$inicio->format('Y-m-d'), $fim->format('Y-m-d')])
            ->with(['products', 'pestSightings', 'technician', 'technicians', 'signature', 'rooms'])
            ->orderBy('scheduled_date')
            ->get()
            ->map(fn (WorkOrder $os): array => $this->conferir($os))
            ->filter(fn (array $linha): bool => $linha['pendencias'] !== [] || $linha['avisos'] !== [])
            ->values()
            ->all();
    }

    /**
     * Data em que o serviço foi executado, no fuso do negócio.
     *
     * `end_time` primeiro: é o instante que o aplicativo do técnico manda do
     * celular no fechamento, ou seja, quando a visita de fato terminou. Sem
     * ele, `scheduled_date`, que é a data prevista e a melhor aproximação
     * disponível para a OS fechada pelo escritório.
     */
    public function dataDaExecucao(WorkOrder $os): ?CarbonImmutable
    {
        return BusinessDate::paraFusoNegocio($os->end_time)
            ?? BusinessDate::paraFusoNegocio($os->scheduled_date);
    }

    // -----------------------------------------------------------------
    // Registro do produto aplicado
    // -----------------------------------------------------------------

    /**
     * Avisos de registro irregular dos produtos aplicados nesta OS.
     *
     * Percorre o pivot `work_order_product`, que é onde o produto aplicado tem
     * `product_id` de verdade e, por ele, chega ao registro na Anvisa.
     *
     * @return array<int, array{item: string, rotulo: string, detalhe: string, exigencia: string}>
     */
    public function avisosDeRegistroDaOs(WorkOrder $os, ?CarbonImmutable $data = null): array
    {
        $data ??= $this->dataDaExecucao($os);

        return $os->products
            ->map(fn (Product $produto): ?array => $this->avisoDoProduto($produto, $data))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Aviso do registro de um produto na data informada, ou `null` quando não
     * há o que avisar.
     *
     * Três situações geram aviso, e nenhuma delas impede coisa alguma:
     *
     * - registro **não cadastrado** no produto: a norma só admite produto
     *   registrado na Anvisa, e sem o número o sistema não tem como demonstrar
     *   isso no documento;
     * - registro **cancelado**: publicado pelo órgão, nunca inferido de data;
     * - registro **vencido na data da aplicação**.
     *
     * Validade em branco **não** gera aviso: "não informado" nunca é
     * "irregular". O que a validade em branco gera é o item de cadastro
     * incompleto no checklist (Task 24.5).
     *
     * @return array{item: string, rotulo: string, detalhe: string, exigencia: string}|null
     */
    public function avisoDoProduto(Product $produto, ?CarbonImmutable $data): ?array
    {
        $registro = $produto->organRegistration;

        if ($registro === null || blank($registro->record)) {
            return $this->aviso(
                "Produto \"{$produto->name}\" sem registro na Anvisa informado no cadastro.",
                'A RDC 622/2022 admite apenas produto saneante desinfestante registrado na Anvisa. '
                .'Cadastre o número do registro no produto.'
            );
        }

        if ($registro->situacao === OrganRegistration::SITUACAO_CANCELADO) {
            return $this->aviso(
                "Produto \"{$produto->name}\": registro {$registro->record} está cancelado.",
                'Produto com registro cancelado na Anvisa não pode ser aplicado. Troque o produto ou '
                .'confirme a situação do registro junto ao órgão.'
            );
        }

        $validade = BusinessDate::paraFusoNegocio($registro->validade);

        if ($validade === null || $data === null) {
            return null;
        }

        if ($validade->startOfDay()->greaterThanOrEqualTo($data->startOfDay())) {
            return null;
        }

        return $this->aviso(
            sprintf(
                'Produto "%s": o registro %s na Anvisa venceu em %s, antes da aplicação de %s.',
                $produto->name,
                $registro->record,
                $validade->format('d/m/Y'),
                $data->format('d/m/Y')
            ),
            'A RDC 622/2022 admite apenas produto com registro válido na Anvisa. A aplicação foi '
            .'registrada normalmente; regularize o registro e confira se cabe alguma providência com o '
            .'cliente.'
        );
    }

    /**
     * Aviso do produto aplicado informado como **texto livre** pelo aplicativo
     * do técnico (`pest_sightings.applied_product`, Plano 13).
     *
     * Aquela coluna não tem vínculo com `products`, então a única forma de
     * chegar ao registro na Anvisa é casar o nome. O casamento é exato,
     * ignorando maiúsculas e espaços nas pontas: um casamento aproximado
     * poderia apontar o registro do produto errado, e aviso errado é pior que
     * aviso ausente. Nome que não casa com nenhum produto do catálogo devolve
     * `null`, em silêncio — o técnico digitou algo que a empresa não cadastrou,
     * e isso é assunto do cadastro, não desta verificação.
     *
     * Precisa ser chamado dentro do tenant: `Product` é model de domínio.
     *
     * @return array{item: string, rotulo: string, detalhe: string, exigencia: string}|null
     */
    public function avisoDoProdutoPorNome(?string $nome, mixed $dataDaAplicacao): ?array
    {
        $procurado = trim((string) $nome);

        if ($procurado === '') {
            return null;
        }

        $produto = Product::query()
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($procurado)])
            ->with('organRegistration')
            ->first();

        if ($produto === null) {
            return null;
        }

        return $this->avisoDoProduto($produto, BusinessDate::paraFusoNegocio($dataDaAplicacao));
    }

    // -----------------------------------------------------------------
    // Itens da conferência
    // -----------------------------------------------------------------

    /**
     * @return array{item: string, rotulo: string, detalhe: string, exigencia: string}|null
     */
    private function conferirProduto(WorkOrder $os): ?array
    {
        $temNoPivot = $os->products->isNotEmpty();
        $temNoAvistamento = $os->pestSightings->contains(fn ($avistamento): bool => filled($avistamento->applied_product));

        if ($temNoPivot || $temNoAvistamento) {
            return null;
        }

        return $this->pendencia(
            self::ITEM_PRODUTO,
            'Produto aplicado',
            'Nenhum produto aplicado foi registrado nesta ordem de serviço.',
            'O comprovante de serviço precisa informar o nome do produto utilizado (RDC 622/2022, art. 19).'
        );
    }

    /**
     * A quantidade é conferida só nos produtos do pivot, que é onde ela existe
     * como número. No avistamento ela é texto vindo do campo
     * (`applied_product_quantity`), e cobrar formato ali transformaria uma
     * anotação de campo em erro de sistema.
     *
     * @return array{item: string, rotulo: string, detalhe: string, exigencia: string}|null
     */
    private function conferirQuantidade(WorkOrder $os): ?array
    {
        $semQuantidade = $os->products
            ->filter(fn (Product $produto): bool => blank($produto->pivot->quantity ?? null) || (float) $produto->pivot->quantity <= 0)
            ->map(fn (Product $produto): string => $produto->name)
            ->values();

        if ($semQuantidade->isEmpty()) {
            return null;
        }

        return $this->pendencia(
            self::ITEM_QUANTIDADE,
            'Quantidade aplicada',
            'Sem quantidade informada: '.$semQuantidade->implode(', ').'.',
            'O comprovante precisa informar a concentração e a quantidade de uso do produto '
            .'(RDC 622/2022, art. 19).'
        );
    }

    /**
     * Praga-alvo: aceita o avistamento registrado em campo, o `pest_type` do
     * cômodo atendido (formulário do escritório) ou a descrição do serviço da
     * OS. Três caminhos porque são três formas legítimas de a informação já
     * estar registrada hoje, e cobrar um formato único inventaria exigência
     * que a norma não faz.
     *
     * @return array{item: string, rotulo: string, detalhe: string, exigencia: string}|null
     */
    private function conferirPragaAlvo(WorkOrder $os): ?array
    {
        $noAvistamento = $os->pestSightings->contains(fn ($avistamento): bool => filled($avistamento->pest_type));
        $noComodo = $os->rooms->contains(fn ($comodo): bool => filled($comodo->pivot->pest_type ?? null));

        if ($noAvistamento || $noComodo) {
            return null;
        }

        return $this->pendencia(
            self::ITEM_PRAGA_ALVO,
            'Praga-alvo',
            'Nenhuma praga-alvo foi registrada nesta ordem de serviço.',
            'O comprovante de serviço precisa informar a praga ou as pragas alvo do controle '
            .'(RDC 622/2022, art. 19).'
        );
    }

    /**
     * Responsável pela execução: técnico titular, algum técnico da equipe, ou
     * o usuário que coletou a assinatura em campo.
     *
     * @return array{item: string, rotulo: string, detalhe: string, exigencia: string}|null
     */
    private function conferirResponsavel(WorkOrder $os): ?array
    {
        $identificado = $os->technician !== null
            || $os->technicians->isNotEmpty()
            || $os->signature?->user_id !== null;

        if ($identificado) {
            return null;
        }

        return $this->pendencia(
            self::ITEM_RESPONSAVEL,
            'Responsável pela execução',
            'Nenhum técnico está vinculado a esta ordem de serviço.',
            'A execução precisa ter responsável identificado, e o comprovante precisa trazer o nome e o '
            .'registro do responsável técnico (RDC 622/2022, art. 19).'
        );
    }

    /**
     * @return array{item: string, rotulo: string, detalhe: string, exigencia: string}|null
     */
    private function conferirData(?CarbonImmutable $data): ?array
    {
        if ($data !== null) {
            return null;
        }

        return $this->pendencia(
            self::ITEM_DATA,
            'Data de execução',
            'A ordem de serviço não tem data de execução nem data agendada.',
            'O comprovante de serviço precisa informar a data de execução (RDC 622/2022, art. 19).'
        );
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * @return array{item: string, rotulo: string, detalhe: string, exigencia: string}
     */
    private function pendencia(string $item, string $rotulo, string $detalhe, string $exigencia): array
    {
        return ['item' => $item, 'rotulo' => $rotulo, 'detalhe' => $detalhe, 'exigencia' => $exigencia];
    }

    /**
     * @return array{item: string, rotulo: string, detalhe: string, exigencia: string}
     */
    private function aviso(string $detalhe, string $exigencia): array
    {
        return $this->pendencia(
            self::ITEM_REGISTRO_DO_PRODUTO,
            'Registro do produto na Anvisa',
            $detalhe,
            $exigencia
        );
    }

    /**
     * Ressalva obrigatória em toda resposta desta conferência.
     *
     * O sistema informa, não certifica. Dizer "esta OS está em conformidade"
     * seria assumir responsabilidade que não é da plataforma: parte do que a
     * norma exige (veículo, treinamento, arquivo físico) o sistema nem enxerga.
     *
     * EPI saiu dessa lista no Plano 29 (Task 29.4): o fornecimento passou a ser
     * dado do sistema, com item próprio no checklist da RDC 622/2022
     * (`ChecklistService::ITEM_EPI_DOS_TECNICOS`) e confirmação de uso na
     * execução. O que o sistema comprova é o que está registrado; a ressalva
     * continua obrigatória porque conformidade em segurança do trabalho não se
     * esgota no fornecimento.
     */
    public function ressalva(): string
    {
        return 'Esta conferência cobre apenas o que o sistema consegue verificar no registro da execução. '
            .'Ela não atesta conformidade com a RDC 622/2022, que depende também de fatores fora do sistema.';
    }

    /**
     * Resumo das pendências de uma coleção de conferências, para o checklist
     * da Task 24.5.
     *
     * @param  array<int, array<string, mixed>>  $conferencias
     * @return array{total: int, com_pendencia: int, com_aviso: int, detalhes: Collection<int, string>}
     */
    public function resumir(array $conferencias): array
    {
        $lista = collect($conferencias);

        return [
            'total' => $lista->count(),
            'com_pendencia' => $lista->filter(fn (array $linha): bool => $linha['pendencias'] !== [])->count(),
            'com_aviso' => $lista->filter(fn (array $linha): bool => $linha['avisos'] !== [])->count(),
            'detalhes' => $lista
                ->map(fn (array $linha): string => sprintf(
                    'OS %s: %s',
                    $linha['order_number'] ?? $linha['work_order_id'],
                    collect($linha['pendencias'])->merge($linha['avisos'])->pluck('rotulo')->unique()->implode(', ')
                ))
                ->values(),
        ];
    }
}
