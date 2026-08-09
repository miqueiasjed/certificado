<?php

namespace App\Services\Ppe;

use App\Models\PersonalProtectiveEquipment;
use App\Models\PpeDelivery;
use App\Models\Technician;
use App\Support\BusinessDate;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Leituras que sustentam os alertas de EPI (Plano 28, Task 28.3): "o que
 * avisar", nunca "avisar".
 *
 * Quem enfileira o aviso pela central de notificações do Plano 14, decide o
 * marco e a cadência de reenvio é `App\Console\Commands\VerificarEpi`. Este
 * Service só devolve os dados, no mesmo critério de `AlertaDeFrotaService`
 * (Plano 27, Task 27.3) e de `StockAlertService` (Plano 17, Task 17.6).
 *
 * As duas validades são perguntas diferentes
 * ------------------------------------------
 * É a decisão central do plano, e o motivo de este Service ter dois caminhos
 * que nunca se cruzam:
 *
 * - `personal_protective_equipments.validade_ca` é a validade **do certificado
 *   do fabricante**. Vencida, o modelo não pode mais ser entregue (regra da
 *   Task 28.2) — mas nada acontece com o respirador que o técnico já tem na
 *   mão.
 * - `ppe_deliveries.trocar_ate` é a troca **daquele item, na mão daquele
 *   técnico**, calculada de `vida_util_dias` no ato da entrega. Renovar o
 *   certificado no cadastro não adia a troca de nada que já foi entregue.
 *
 * Tratar as duas como uma só produz um sistema que ora alerta o mundo inteiro
 * por causa de um certificado renovado, ora nunca alerta a troca do respirador
 * usado há dois anos. Note que `ppe_deliveries.validade_ca` — a cópia do CA no
 * ato da entrega — **não** é lida aqui: ela é a fotografia do certificado
 * naquele dia, serve à ficha, e alertar por ela avisaria sobre um certificado
 * que talvez já tenha sido renovado no cadastro.
 *
 * Campo em branco é estado neutro, nunca irregularidade
 * ----------------------------------------------------
 * EPI sem `numero_ca` ou sem `validade_ca` fica inteiramente fora da varredura
 * de vencimento, e entrega sem `trocar_ate` também. É a mesma regra do cadastro
 * regulatório do Plano 24 (`CADASTRO_REGULATORIO_INCOMPLETO`): acusar de
 * irregular quem apenas não preencheu é informação falsa, e o lugar de cobrar o
 * preenchimento é o checklist do Plano 29, não o alerta de vencimento.
 * `vida_util_dias` nulo significa "sem troca programada", nunca um prazo padrão
 * inventado.
 *
 * Todo alerta tem critério de parada
 * ----------------------------------
 * Aviso que se repete para sempre é aviso que o usuário aprende a ignorar, e
 * quando isso acontece ele passa a ignorar junto os avisos que importam. Foi o
 * defeito corrigido no commit `d9a3a9c` (documento de veículo renovado por
 * linha nova reenviava aviso semanal eternamente), e é o que esta classe evita
 * de propósito:
 *
 * - CA a vencer e CA vencido param quando a `validade_ca` do cadastro é
 *   atualizada, ou quando o modelo de EPI é marcado como inativo — que é como
 *   se aposenta um modelo substituído por outro cadastro.
 * - Troca vencida para quando a entrega é devolvida, estornada, **substituída
 *   por uma entrega mais recente e ainda em vigor do mesmo EPI ao mesmo
 *   técnico** ou quando o técnico é desligado (`technicians.is_active`). A
 *   substituição é o caso equivalente ao do `d9a3a9c`: ninguém edita a entrega
 *   antiga (ela é imutável, por ser documento oponível), registra-se a entrega
 *   do substituto, e sem este critério a linha velha avisaria toda semana para
 *   sempre.
 *
 * Critério de parada, porém, nunca pode virar silêncio indevido: quem para o
 * aviso é o fato que resolve a pendência, não qualquer fato posterior. Um
 * substituto que foi **devolvido** não resolve nada — o técnico continua sem o
 * equipamento —, e por isso ele não cala a entrega anterior (ver
 * `entregaMaisRecentePorTecnicoEEpi()`).
 *
 * A regra de troca vencida mora aqui, e só aqui
 * ---------------------------------------------
 * `trocaVencida()` e `recorteDeTrocaVencida()` são públicos de propósito: a
 * situação de EPI do técnico (`SituacaoDeEpiService`, Plano 29, Task 29.2)
 * responde a mesma pergunta e **reusa** estes dois, em vez de recalcular. É
 * decisão registrada do Plano 29, e o motivo é simples: duas implementações do
 * mesmo vencimento divergem, e divergência de vencimento é bug que ninguém
 * encontra depois.
 *
 * Datas
 * -----
 * Todas as comparações são por dia no fuso do negócio, via
 * `App\Support\BusinessDate`, nunca contra `now()` em UTC: um CA que vence hoje
 * não está vencido às 21h de Brasília.
 *
 * Empresa
 * -------
 * Sem consulta própria de tenant: o escopo global dos models resolve pelo
 * tenant corrente, e a rotina itera com `OperaPorTenant`.
 */
class AlertaDeEpiService
{
    /**
     * Janelas de alerta da validade do CA, em dias, da mais distante para a
     * mais urgente.
     *
     * As mesmas 60/30/7 do documento de veículo (Plano 27) e do lote do Plano
     * 17, por instrução da task: CA é prazo legal como os outros, e inventar
     * uma quarta régua para a mesma coisa só criaria mais uma convenção para
     * alguém conferir.
     *
     * @var array<int, int>
     */
    public const JANELAS_DE_CA = [60, 30, 7];

    // -----------------------------------------------------------------
    // Troca vencida: a regra, em um lugar só
    // -----------------------------------------------------------------

    /**
     * A troca programada desta entrega já venceu?
     *
     * **Esta é a origem única da regra de troca vencida do sistema.** O alerta
     * semanal (`trocasVencidas()`, Task 28.3) e a situação de EPI do técnico
     * (`SituacaoDeEpiService`, Plano 29, Task 29.2) respondem a mesma pergunta,
     * e a decisão registrada do Plano 29 é explícita: uma só implementação. Duas
     * divergem, e divergência de vencimento é o tipo de bug que ninguém encontra
     * depois — o alerta diria que a troca do respirador venceu e a ficha do
     * técnico continuaria dizendo "em dia", ou o contrário.
     *
     * Dois estados nunca são vencimento:
     *
     * - entrega nula (o técnico não tem aquele item) não tem troca vencida, tem
     *   ausência, que é outra situação;
     * - `trocar_ate` nulo é "sem troca programada", nunca um prazo padrão
     *   inventado (ver `PpeDeliveryService::trocarAte()`).
     *
     * A comparação é por dia no fuso do negócio, via `BusinessDate`, nunca
     * contra `now()` em UTC: um item que deve ser trocado hoje ainda está em dia
     * às 21h de Brasília.
     */
    public function trocaVencida(?PpeDelivery $entrega): bool
    {
        if ($entrega === null || $entrega->trocar_ate === null) {
            return false;
        }

        return BusinessDate::estaVencido($entrega->trocar_ate);
    }

    /**
     * O mesmo critério de `trocaVencida()`, aplicado como recorte da consulta.
     *
     * Existe porque a rotina diária varre as entregas de todas as empresas e não
     * pode carregar a tabela inteira para decidir em PHP. É a mesma regra escrita
     * na linguagem do banco, e não uma segunda regra: o limite é o dia de hoje no
     * fuso do negócio nos dois lugares, e quem usa este recorte confirma o
     * resultado com `trocaVencida()` depois de carregar as linhas — se um dia os
     * dois discordarem, quem manda é o predicado, e o recorte é apenas mais
     * largo do que precisava ser.
     *
     * `trocar_ate` é coluna `date`, então "anterior a hoje" é exatamente o que
     * `estaVencido()` responde em PHP.
     *
     * @param  Builder<PpeDelivery>  $consulta
     * @return Builder<PpeDelivery>
     */
    public function recorteDeTrocaVencida(Builder $consulta): Builder
    {
        return $consulta
            ->whereNotNull('trocar_ate')
            ->where('trocar_ate', '<', BusinessDate::hoje()->toDateString());
    }

    /**
     * Modelos de EPI com o CA perto de vencer, por janela, e os já vencidos.
     *
     * Mesmo formato de `AlertaDeFrotaService::documentosAVencer()`, para a
     * rotina tratar os dois do mesmo jeito. `dias_para_vencer` vem negativo nos
     * vencidos: é há quantos dias o certificado perdeu a validade.
     *
     * @return array{
     *     vencendo: array<int, array{dias: int, itens: Collection<int, array<string, mixed>>}>,
     *     vencidos: Collection<int, array<string, mixed>>
     * }
     */
    public function certificadosAVencer(): array
    {
        $hoje = BusinessDate::hoje();

        $vencendo = [];

        foreach (self::JANELAS_DE_CA as $dias) {
            $vencendo[] = [
                'dias' => $dias,
                'itens' => $this->certificadosEntre(
                    $hoje->toDateString(),
                    $hoje->addDays($dias)->toDateString()
                ),
            ];
        }

        return [
            'vencendo' => $vencendo,
            'vencidos' => $this->certificadosEntre(null, $hoje->subDay()->toDateString()),
        ];
    }

    /**
     * Entregas cuja troca programada já passou e que continuam valendo: nem
     * devolvidas, nem estornadas, nem substituídas por uma entrega mais recente
     * do mesmo EPI ao mesmo técnico, e sempre de técnico na ativa.
     *
     * `trocar_ate` nulo é "sem troca programada" e nunca entra aqui.
     *
     * Técnico desligado sai da varredura
     * ----------------------------------
     * Por simetria com o `ativo` do modelo de EPI em `certificadosEntre()`:
     * assim como cobrar a renovação do certificado de um modelo aposentado é
     * ruído garantido, cobrar a troca do respirador de quem não trabalha mais na
     * empresa é um aviso sem critério de parada nenhum. `is_active = false` com
     * entrega antiga sem devolução registrada reenviaria `EPI_COM_TROCA_VENCIDA`
     * toda semana, indefinidamente, sobre alguém que não vai trocar coisa
     * alguma — e é assim que o usuário aprende a ignorar o alerta inteiro.
     *
     * Fica registrado o que este aviso **não** cobre: o item que o técnico levou
     * embora e ninguém deu baixa no desligamento continua sem devolução no
     * sistema, e isso é problema de escritório (acerto de rescisão, conferência
     * da ficha do Plano 28), não pendência de troca. Alertar troca vencida
     * eternamente não recupera equipamento nenhum; quem cobra a devolução é a
     * ficha do técnico, que sobrevive ao desligamento de propósito.
     *
     * O critério não vale para os alertas de CA (`certificadosAVencer()`), que
     * são sobre o cadastro do modelo de EPI e não sobre um técnico específico: o
     * CA vencido de um respirador segue sendo problema real mesmo que ninguém o
     * tenha na mão hoje.
     *
     * @return Collection<int, array{
     *     entrega: PpeDelivery,
     *     tecnico: Technician,
     *     epi: PersonalProtectiveEquipment,
     *     trocar_ate: string,
     *     dias_vencido: int
     * }>
     */
    public function trocasVencidas(): Collection
    {
        $hoje = BusinessDate::hoje();

        $entregas = PpeDelivery::query()
            ->with(['technician', 'personalProtectiveEquipment'])
            // O vencimento da troca vem de `recorteDeTrocaVencida()`, a mesma
            // regra que a situação de EPI do técnico usa (Plano 29, Task 29.2).
            ->tap(fn (Builder $consulta): Builder => $this->recorteDeTrocaVencida($consulta))
            // Devolvida: o item voltou, não há o que trocar. Estornada: a
            // entrega foi registrada por engano e nunca existiu de verdade.
            ->whereNull('devolvido_em')
            ->whereNull('estornada_em')
            // Técnico desligado não troca EPI: ver o bloco no docblock deste
            // método. Mesmo papel do `ativo` do EPI em `certificadosEntre()`.
            ->whereHas('technician', static fn ($consulta) => $consulta->where('is_active', true))
            ->orderBy('trocar_ate')
            ->orderBy('id')
            ->get()
            // O predicado é quem manda sobre o recorte em SQL: ver
            // `recorteDeTrocaVencida()`.
            ->filter(fn (PpeDelivery $entrega): bool => $entrega->technician !== null
                && $entrega->personalProtectiveEquipment !== null
                && $this->trocaVencida($entrega))
            ->values();

        if ($entregas->isEmpty()) {
            return collect();
        }

        $maisRecente = $this->entregaMaisRecentePorTecnicoEEpi($entregas);

        return $entregas
            ->reject(fn (PpeDelivery $entrega): bool => $this->foiSubstituida($entrega, $maisRecente))
            ->map(static function (PpeDelivery $entrega) use ($hoje): array {
                $trocarAte = BusinessDate::paraFusoNegocio($entrega->trocar_ate)->startOfDay();

                return [
                    'entrega' => $entrega,
                    'tecnico' => $entrega->technician,
                    'epi' => $entrega->personalProtectiveEquipment,
                    'trocar_ate' => $trocarAte->toDateString(),
                    'dias_vencido' => (int) $trocarAte->diffInDays($hoje, false),
                ];
            })
            ->values();
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Modelos de EPI com `validade_ca` dentro do intervalo informado, já com os
     * dias até o vencimento calculados.
     *
     * Só EPI `ativo` e só com **número e validade do CA preenchidos**: cadastro
     * em branco é estado neutro (ver o cabeçalho da classe), e modelo inativo é
     * modelo que saiu de uso — cobrar a renovação do certificado dele seria
     * ruído garantido, e desativar é justamente como a empresa aposenta o
     * cadastro que foi substituído por outro.
     *
     * O filtro de técnico ativo que existe em `trocasVencidas()` **não** tem
     * equivalente aqui, de propósito: este alerta é sobre o cadastro do modelo
     * de EPI, e não sobre um técnico específico. O CA vencido de um respirador
     * segue impedindo novas entregas (regra da Task 28.2) mesmo que ninguém o
     * tenha na mão hoje, e o critério de parada dele já é outro — renovar a
     * validade ou inativar o modelo.
     *
     * @return Collection<int, array{
     *     epi: PersonalProtectiveEquipment,
     *     validade: string,
     *     dias_para_vencer: int
     * }>
     */
    private function certificadosEntre(?string $de, string $ate): Collection
    {
        $hoje = BusinessDate::hoje();

        $consulta = PersonalProtectiveEquipment::query()
            ->where('ativo', true)
            ->whereNotNull('numero_ca')
            ->where('numero_ca', '<>', '')
            ->whereNotNull('validade_ca')
            ->where('validade_ca', '<=', $ate)
            ->orderBy('validade_ca')
            ->orderBy('id');

        if ($de !== null) {
            $consulta->where('validade_ca', '>=', $de);
        }

        return $consulta->get()
            ->map(static function (PersonalProtectiveEquipment $epi) use ($hoje): array {
                $validade = BusinessDate::paraFusoNegocio($epi->validade_ca)->startOfDay();

                return [
                    'epi' => $epi,
                    'validade' => $validade->toDateString(),
                    'dias_para_vencer' => (int) $hoje->diffInDays($validade, false),
                ];
            })
            ->values();
    }

    /**
     * Entrega mais recente por técnico e por modelo de EPI, no formato
     * `{technician_id}|{ppe_id} => ['dia' => Y-m-d, 'id' => int]`.
     *
     * Uma consulta só, para os pares que têm alguma entrega com a troca
     * vencida, em vez de um `exists()` por entrega: a rotina roda para todos os
     * técnicos de todas as empresas. A comparação por dia no fuso do negócio é
     * feita aqui, e não no banco, para não depender de como cada banco devolve
     * uma coluna `date` — mesmo critério de
     * `AlertaDeFrotaService::ultimaValidadePorVeiculoETipo()`.
     *
     * Entrega estornada não conta como substituta: estorno é a declaração de
     * que aquela entrega nunca existiu, e deixá-la calar a entrega anterior
     * apagaria justamente o aviso que o estorno faz voltar a valer.
     *
     * Entrega devolvida também não conta — e esta decisão mudou
     * ---------------------------------------------------------
     * A versão original desta classe dizia o contrário, com este argumento: "o
     * item chegou às mãos do técnico e substituiu o anterior, e a devolução do
     * substituto é uma pendência daquela linha, não desta". A premissa era
     * falsa: a pendência daquela linha não era alertada por caminho nenhum,
     * porque `trocasVencidas()` exclui as entregas devolvidas da varredura. As
     * duas exclusões se combinavam num silêncio completo:
     *
     * - entrega em `hoje-200`, com a troca vencida há 170 dias;
     * - substituto entregue em `hoje-10` e devolvido em `hoje-5`, por dano;
     * - o substituto devolvido calava a linha antiga, e ele mesmo estava fora da
     *   varredura por estar devolvido.
     *
     * Resultado: o técnico há cinco dias sem respirador nenhum e o sistema mudo,
     * que é o pior modo de um alerta falhar — pior que o aviso repetido, porque
     * ninguém descobre que ele não veio.
     *
     * A regra correta é a que o critério de parada sempre quis dizer: só cala a
     * entrega anterior o substituto que **está com o técnico**. Devolvido é item
     * que voltou ao estoque; sem nada na mão, a pendência volta a ser a da linha
     * antiga, e é ela que volta a avisar. Estorno e devolução, aqui, levam ao
     * mesmo lugar por motivos diferentes.
     *
     * @param  Collection<int, PpeDelivery>  $candidatas
     * @return array<string, array{dia: string, id: int}>
     */
    private function entregaMaisRecentePorTecnicoEEpi(Collection $candidatas): array
    {
        $tecnicos = $candidatas->map(static fn (PpeDelivery $e): int => (int) $e->technician_id)->unique()->values()->all();
        $epis = $candidatas->map(static fn (PpeDelivery $e): int => (int) $e->personal_protective_equipment_id)->unique()->values()->all();

        $maisRecente = [];

        PpeDelivery::query()
            ->whereNull('estornada_em')
            ->whereNull('devolvido_em')
            ->whereIn('technician_id', $tecnicos)
            ->whereIn('personal_protective_equipment_id', $epis)
            ->get(['id', 'technician_id', 'personal_protective_equipment_id', 'entregue_em'])
            ->each(static function (PpeDelivery $entrega) use (&$maisRecente): void {
                $chave = $entrega->technician_id.'|'.$entrega->personal_protective_equipment_id;
                $dia = BusinessDate::diaDe($entrega->entregue_em);

                if ($dia === null) {
                    return;
                }

                $atual = ['dia' => $dia, 'id' => (int) $entrega->getKey()];

                if (! isset($maisRecente[$chave]) || self::ehPosterior($atual, $maisRecente[$chave])) {
                    $maisRecente[$chave] = $atual;
                }
            });

        return $maisRecente;
    }

    /**
     * A entrega foi substituída por outra mais recente do mesmo EPI ao mesmo
     * técnico?
     *
     * O desempate por `id` existe para a substituição feita no mesmo dia, que é
     * o caso comum do item danificado e reposto na mesma manhã: comparando só
     * `entregue_em`, a linha velha continuaria avisando enquanto o técnico já
     * está com o substituto na mão. `id` é crescente e por isso serve de ordem
     * de registro; a `entregue_em` continua vencendo o `id` quando os dias
     * diferem, porque uma entrega retroativa lançada hoje não substitui a de
     * amanhã.
     *
     * @param  array<string, array{dia: string, id: int}>  $maisRecente
     */
    private function foiSubstituida(PpeDelivery $entrega, array $maisRecente): bool
    {
        $chave = $entrega->technician_id.'|'.$entrega->personal_protective_equipment_id;

        if (! isset($maisRecente[$chave])) {
            return false;
        }

        $dia = BusinessDate::diaDe($entrega->entregue_em);

        if ($dia === null) {
            return false;
        }

        return self::ehPosterior($maisRecente[$chave], ['dia' => $dia, 'id' => (int) $entrega->getKey()]);
    }

    /**
     * `$candidata` é posterior a `$referencia`, comparando primeiro o dia da
     * entrega e depois a ordem de registro.
     *
     * @param  array{dia: string, id: int}  $candidata
     * @param  array{dia: string, id: int}  $referencia
     */
    private static function ehPosterior(array $candidata, array $referencia): bool
    {
        if ($candidata['dia'] !== $referencia['dia']) {
            return $candidata['dia'] > $referencia['dia'];
        }

        return $candidata['id'] > $referencia['id'];
    }
}
