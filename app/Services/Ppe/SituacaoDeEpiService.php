<?php

namespace App\Services\Ppe;

use App\Models\PersonalProtectiveEquipment;
use App\Models\PpeDelivery;
use App\Models\Technician;
use App\Support\BusinessDate;
use App\Support\TenantAtual;
use RuntimeException;

/**
 * Situação de EPI de um técnico (Plano 29, Task 29.2): o que ele deveria ter na
 * mão hoje, o que ele tem e o que falta.
 *
 * Leitura pura. Não grava nada, não avisa ninguém e **não impede nada**: quem
 * consome é a tela do técnico, o checklist da RDC 622/2022 (Task 29.4) e o
 * endpoint da Task 29.3. Pendência de EPI é problema de escritório, e travar a
 * operação por causa dela tira a empresa do ar — decisão registrada do plano,
 * a mesma já tomada no checklist do Plano 24.
 *
 * As quatro situações
 * -------------------
 * Para cada modelo de EPI marcado como obrigatório no cadastro:
 *
 * | Situação | Critério |
 * |---|---|
 * | `nunca_recebeu` | não há entrega válida daquele EPI para aquele técnico |
 * | `troca_vencida` | há entrega, e a troca programada dela já passou |
 * | `sem_assinatura` | há entrega em dia, e ela nunca foi assinada |
 * | `em_dia` | há entrega válida, assinada, com a troca no futuro ou sem troca programada |
 *
 * `sem_assinatura` é situação própria, e não um caso de `em_dia`
 * -------------------------------------------------------------
 * É a decisão do plano que mais muda o resultado prático desta classe. A entrega
 * existe, o técnico está com o equipamento, e ainda assim falta a única coisa
 * que a NR-6 aceita como prova do fornecimento (item 6.5.1): a confirmação de
 * recebimento. Agrupar as duas em "em dia" produziria uma tela verde para o
 * único caso em que a empresa perde a reclamatória trabalhista, porque não tem
 * como provar que entregou.
 *
 * Uma só regra de troca vencida
 * -----------------------------
 * O vencimento da troca **não é calculado aqui**. Ele vem de
 * `AlertaDeEpiService::trocaVencida()`, o mesmo predicado que decide o alerta
 * semanal da Task 28.3. Decisão registrada do Plano 29: duas implementações do
 * mesmo vencimento divergem, e a divergência apareceria como um alerta semanal
 * cobrando a troca de um respirador que a ficha do técnico mostra em dia.
 *
 * Qual entrega responde pela situação
 * -----------------------------------
 * A **mais recente ainda em vigor** de cada modelo de EPI: nem devolvida (o item
 * voltou ao estoque) nem estornada (a entrega foi declarada inexistente). É o
 * mesmo critério de substituição de `AlertaDeEpiService::trocasVencidas()`,
 * inclusive no desempate por `id` para as duas entregas do mesmo dia — o caso
 * comum do item danificado e reposto na mesma manhã. A entrega antiga continua
 * na ficha do Plano 28; ela só não é quem responde pela situação de hoje.
 *
 * Técnico inativo sai do cálculo
 * ------------------------------
 * Desligado não vai a campo, e cobrar EPI de quem não trabalha mais na empresa é
 * ruído garantido — mesmo critério do filtro de `is_active` em
 * `AlertaDeEpiService::trocasVencidas()`. O retorno traz `avaliado = false` e
 * nenhum item, em vez de uma lista de pendências que ninguém vai resolver. A
 * ficha dele continua existindo e extraível (`FichaDeEpiService`), de propósito:
 * é justamente a ficha de quem já saiu que costuma ser pedida depois.
 *
 * Datas
 * -----
 * Toda comparação é por dia no fuso do negócio, via `App\Support\BusinessDate`,
 * nunca contra `now()` em UTC.
 */
class SituacaoDeEpiService
{
    /**
     * Entrega válida, assinada e com a troca no futuro (ou sem troca
     * programada).
     */
    public const EM_DIA = 'em_dia';

    /**
     * Há entrega, mas a troca programada dela já passou.
     */
    public const TROCA_VENCIDA = 'troca_vencida';

    /**
     * Há entrega, mas ela nunca foi assinada: falta a prova de recebimento que a
     * NR-6 cobra.
     */
    public const SEM_ASSINATURA = 'sem_assinatura';

    /**
     * Não há entrega daquele EPI para aquele técnico.
     */
    public const NUNCA_RECEBEU = 'nunca_recebeu';

    /**
     * As quatro situações, da melhor para a pior.
     *
     * @var array<int, string>
     */
    public const SITUACOES = [
        self::EM_DIA,
        self::SEM_ASSINATURA,
        self::TROCA_VENCIDA,
        self::NUNCA_RECEBEU,
    ];

    /**
     * Rótulo legível de cada situação, colado no enum que descreve — mesmo
     * critério de `PersonalProtectiveEquipment::ROTULOS_DE_TIPO`. A tela, o
     * checklist e qualquer aviso falam o mesmo texto, porque leem daqui.
     *
     * @var array<string, string>
     */
    public const ROTULOS_DE_SITUACAO = [
        self::EM_DIA => 'Em dia',
        self::SEM_ASSINATURA => 'Entrega sem assinatura',
        self::TROCA_VENCIDA => 'Troca vencida',
        self::NUNCA_RECEBEU => 'Nunca recebeu',
    ];

    /**
     * As situações que são pendência. Tudo que não é `em_dia`.
     *
     * @var array<int, string>
     */
    public const SITUACOES_PENDENTES = [
        self::SEM_ASSINATURA,
        self::TROCA_VENCIDA,
        self::NUNCA_RECEBEU,
    ];

    /**
     * O cálculo do vencimento da troca é do `AlertaDeEpiService`, e é o mesmo do
     * alerta semanal da Task 28.3. Ver o cabeçalho da classe.
     */
    public function __construct(private readonly AlertaDeEpiService $alertas) {}

    /**
     * Situação de EPI do técnico, um item por modelo de EPI obrigatório.
     *
     * Estrutura devolvida:
     *
     * - `tecnico`: id, nome e se está ativo;
     * - `avaliado`: falso para técnico desligado, e nesse caso `itens` vem vazio;
     * - `itens`: um por EPI obrigatório, com a situação e a entrega que a
     *   justifica;
     * - `pendencias`: o recorte de `itens` que não está `em_dia`, já pronto para
     *   o checklist e para a tela;
     * - `resumo`: a contagem por situação;
     * - `regular`: verdadeiro quando não há pendência nenhuma.
     *
     * Empresa sem EPI obrigatório cadastrado devolve `itens` vazio e `regular`
     * verdadeiro: é o estado normal de quem acabou de ligar o módulo, e nunca
     * irregularidade. Mesma regra do cadastro regulatório do Plano 24 — acusar
     * de irregular quem apenas não preencheu destruiria a confiança no aviso.
     *
     * @return array{
     *     tecnico: array{id: int, nome: ?string, ativo: bool},
     *     avaliado: bool,
     *     itens: array<int, array<string, mixed>>,
     *     pendencias: array<int, array<string, mixed>>,
     *     resumo: array<string, int>,
     *     regular: bool
     * }
     */
    public function doTecnico(Technician $tecnico): array
    {
        $this->garantirTecnicoDoTenant($tecnico);

        $identificacao = [
            'id' => (int) $tecnico->getKey(),
            'nome' => $tecnico->name,
            'ativo' => (bool) $tecnico->is_active,
        ];

        if (! $tecnico->is_active) {
            return [
                'tecnico' => $identificacao,
                'avaliado' => false,
                'itens' => [],
                'pendencias' => [],
                'resumo' => $this->resumoZerado(),
                'regular' => true,
            ];
        }

        $obrigatorios = $this->episObrigatorios($tecnico);
        $entregas = $this->entregaEmVigorPorEpi($tecnico, $obrigatorios->modelKeys());

        $itens = $obrigatorios
            ->map(fn (PersonalProtectiveEquipment $epi): array => $this->item(
                $epi,
                $entregas[(int) $epi->getKey()] ?? null
            ))
            ->values()
            ->all();

        $pendencias = array_values(array_filter(
            $itens,
            static fn (array $item): bool => $item['situacao'] !== self::EM_DIA
        ));

        return [
            'tecnico' => $identificacao,
            'avaliado' => true,
            'itens' => $itens,
            'pendencias' => $pendencias,
            'resumo' => $this->resumo($itens),
            'regular' => $pendencias === [],
        ];
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Um item da situação: o EPI, o veredito e a entrega que o justifica.
     *
     * A ordem dos testes é a ordem da urgência, e ela importa porque a entrega
     * pode estar vencida **e** sem assinatura ao mesmo tempo:
     *
     * 1. sem entrega em vigor -> `nunca_recebeu`, a falta completa;
     * 2. troca vencida -> `troca_vencida`, porque equipamento vencido em campo
     *    não protege ninguém hoje, enquanto a assinatura que falta é prova de um
     *    fato do passado;
     * 3. sem assinatura -> `sem_assinatura`;
     * 4. o resto -> `em_dia`.
     *
     * A precedência não esconde nada: `assinada` sai como campo próprio em todo
     * item, e uma entrega vencida e não assinada aparece com
     * `situacao = troca_vencida` e `assinada = false`.
     *
     * @return array<string, mixed>
     */
    private function item(PersonalProtectiveEquipment $epi, ?PpeDelivery $entrega): array
    {
        $assinada = $entrega !== null
            && ($entrega->assinado_em !== null || filled($entrega->assinatura_path));

        // A regra do vencimento é do AlertaDeEpiService, nunca recalculada aqui.
        $trocaVencida = $this->alertas->trocaVencida($entrega);

        $situacao = match (true) {
            $entrega === null => self::NUNCA_RECEBEU,
            $trocaVencida => self::TROCA_VENCIDA,
            ! $assinada => self::SEM_ASSINATURA,
            default => self::EM_DIA,
        };

        $trocarAte = BusinessDate::diaDe($entrega?->trocar_ate);

        return [
            'epi' => [
                'id' => (int) $epi->getKey(),
                'nome' => $epi->nome,
                'tipo' => $epi->tipo,
                'tipo_rotulo' => PersonalProtectiveEquipment::ROTULOS_DE_TIPO[$epi->tipo] ?? (string) $epi->tipo,
            ],
            'situacao' => $situacao,
            'situacao_rotulo' => self::ROTULOS_DE_SITUACAO[$situacao],
            'pendente' => $situacao !== self::EM_DIA,
            'entrega_id' => $entrega?->getKey() !== null ? (int) $entrega->getKey() : null,
            'entregue_em' => BusinessDate::diaDe($entrega?->entregue_em),
            'assinada' => $assinada,
            // Nulo é "sem troca programada", nunca prazo padrão inventado (ver
            // `PpeDeliveryService::trocarAte()`). A marca separada existe para a
            // tela não comparar texto para decidir a cor.
            'tem_troca_programada' => $trocarAte !== null,
            'trocar_ate' => $trocarAte,
            'dias_vencido' => $trocaVencida && $trocarAte !== null
                ? (int) BusinessDate::paraFusoNegocio($trocarAte)->diffInDays(BusinessDate::hoje(), false)
                : null,
        ];
    }

    /**
     * Modelos de EPI que a empresa marcou como obrigatórios no cadastro.
     *
     * Só os `ativo`, pela mesma razão de `AlertaDeEpiService::certificadosEntre()`
     * e de `PpeDeliveryService::garantirEpiAtivo()`: inativar é como este
     * cadastro aposenta o modelo substituído por outro, e cobrar "nunca recebeu"
     * de um equipamento que a empresa tirou de uso seria uma pendência que
     * ninguém tem como resolver — o próprio sistema recusa a entrega dele. A
     * task não previa este recorte; sem ele, desativar um EPI obrigatório
     * encheria a tela de toda a equipe de pendências permanentes.
     *
     * `obrigatorio` aqui é o do cadastro do modelo, e não o de
     * `ServicePpeRequirement`, que é a exigência daquele serviço: a situação do
     * técnico é sobre o que ele precisa ter na mão para trabalhar, não sobre uma
     * OS específica.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, PersonalProtectiveEquipment>
     */
    private function episObrigatorios(Technician $tecnico)
    {
        return PersonalProtectiveEquipment::query()
            ->where('company_id', $tecnico->company_id)
            ->where('obrigatorio', true)
            ->where('ativo', true)
            ->orderBy('nome')
            ->orderBy('id')
            ->get();
    }

    /**
     * Entrega em vigor de cada modelo de EPI, indexada pelo id do EPI.
     *
     * "Em vigor" é a mais recente que não foi devolvida nem estornada. Devolvida
     * é item que voltou ao estoque, e estornada é entrega declarada inexistente:
     * nenhuma das duas está na mão do técnico, e é o que ele tem na mão que a
     * situação descreve. Mesmo critério de
     * `AlertaDeEpiService::entregaMaisRecentePorTecnicoEEpi()`.
     *
     * Uma consulta só para todos os EPIs obrigatórios, em vez de uma por EPI: a
     * tela do técnico e o checklist da Task 29.4 chamam este método em laço. A
     * ordem é `entregue_em` e depois `id`, a mesma de
     * `AlertaDeEpiService::ehPosterior()` — `entregue_em` é coluna `date`, que
     * ordena sem conversão de fuso, e o desempate por `id` é o que resolve as
     * duas entregas do mesmo dia, o caso do item danificado e reposto na mesma
     * manhã.
     *
     * @param  array<int, mixed>  $epis
     * @return array<int, PpeDelivery>
     */
    private function entregaEmVigorPorEpi(Technician $tecnico, array $epis): array
    {
        if ($epis === []) {
            return [];
        }

        $emVigor = [];

        PpeDelivery::query()
            ->where('company_id', $tecnico->company_id)
            ->where('technician_id', $tecnico->getKey())
            ->whereIn('personal_protective_equipment_id', $epis)
            ->whereNull('devolvido_em')
            ->whereNull('estornada_em')
            ->orderBy('entregue_em')
            ->orderBy('id')
            ->get()
            ->each(static function (PpeDelivery $entrega) use (&$emVigor): void {
                // A consulta vem em ordem crescente de dia e de registro: a
                // última que passa por aqui é a que está na mão do técnico.
                $emVigor[(int) $entrega->personal_protective_equipment_id] = $entrega;
            });

        return $emVigor;
    }

    /**
     * @param  array<int, array<string, mixed>>  $itens
     * @return array<string, int>
     */
    private function resumo(array $itens): array
    {
        $resumo = $this->resumoZerado();

        foreach ($itens as $item) {
            $resumo['total']++;
            $resumo[$item['situacao']]++;
        }

        return $resumo;
    }

    /**
     * @return array<string, int>
     */
    private function resumoZerado(): array
    {
        $resumo = ['total' => 0];

        foreach (self::SITUACOES as $situacao) {
            $resumo[$situacao] = 0;
        }

        return $resumo;
    }

    /**
     * O técnico é do tenant corrente.
     *
     * O escopo global de `BelongsToCompany` já impede alcançar o técnico de
     * outra empresa por consulta, e as duas consultas desta classe são filtradas
     * também pelo `company_id` do próprio técnico — o que mantém o resultado
     * correto fora da requisição HTTP (comando artisan, job, seeder), onde não há
     * tenant resolvido e o tenant precisa ser informado, nunca inferido.
     *
     * Esta é a segunda tranca do mesmo portão, no formato de
     * `FichaDeEpiService::empresaEmitente()`: com tenant resolvido e técnico de
     * outra empresa, as consultas devolveriam vazio e a resposta seria "nunca
     * recebeu" para tudo — uma afirmação falsa sobre o trabalhador de um
     * concorrente. Falhar alto é o comportamento desejado.
     */
    private function garantirTecnicoDoTenant(Technician $tecnico): void
    {
        $tenant = TenantAtual::id();

        if ($tenant === null || (int) $tecnico->company_id === $tenant) {
            return;
        }

        throw new RuntimeException(
            "O técnico {$tecnico->getKey()} não pertence à empresa {$tenant}, "
            .'e a situação de EPI dele não pode ser calculada neste contexto.'
        );
    }
}
