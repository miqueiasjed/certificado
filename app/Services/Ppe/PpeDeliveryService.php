<?php

namespace App\Services\Ppe;

use App\Exceptions\CaVencidoException;
use App\Exceptions\EntregaDeEpiEstornadaException;
use App\Models\PersonalProtectiveEquipment;
use App\Models\PpeDelivery;
use App\Models\Technician;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Entrega de EPI ao técnico: registro, assinatura, devolução e estorno
 * (Plano 28, Task 28.2).
 *
 * **Nenhuma escrita em `ppe_deliveries` acontece fora deste Service.** Criar a
 * linha por Eloquent direto pula o snapshot do CA e o cálculo da troca, e ficha
 * sem CA não serve como prova — que é a única razão de a tabela existir.
 *
 * O CA é copiado, nunca referenciado
 * ----------------------------------
 * `numero_ca` e `validade_ca` da entrega são a fotografia do certificado no dia
 * em que o técnico recebeu o equipamento. A cópia é feita aqui, no ato, e nunca
 * mais relida de `personal_protective_equipments`: renovar o CA no cadastro não
 * pode reescrever a ficha do ano passado, pela mesma razão pela qual documento
 * emitido não se recalcula. Quem exibe a ficha lê as colunas da própria entrega,
 * não a relação.
 *
 * As duas validades são independentes
 * -----------------------------------
 * `validade_ca` é do certificado do fabricante; `trocar_ate` é do item que
 * aquele técnico tem na mão, calculado de `vida_util_dias` no ato da entrega.
 * Renovar o certificado não adia a troca do respirador usado há dois anos, e
 * trocar o respirador não renova certificado nenhum.
 *
 * Vida útil ausente é "sem troca programada"
 * ------------------------------------------
 * `trocar_ate` fica **nulo**, e não com um prazo padrão inventado. Prazo
 * inventado vira alerta falso, e alerta falso faz o usuário desligar o alerta
 * inteiro. Ausente é diferente de zero: `vida_util_dias = 0` é uma escolha do
 * cadastro e produz troca para o próprio dia da entrega.
 *
 * A entrega não se edita
 * ----------------------
 * Não existe (e não pode passar a existir) método de alteração de conteúdo nem
 * de exclusão. Erro vira estorno com motivo, na convenção do razão de estoque do
 * Plano 17. A entrega estornada sai de todo cálculo de situação e de todo
 * alerta, e continua na extração por período marcada como estornada.
 *
 * Assinatura e devolução são exceções deliberadas a essa imutabilidade, e só
 * elas: as duas acrescentam um fato posterior à entrega (o técnico confirmou o
 * recebimento; o técnico devolveu o item), não reescrevem o que foi entregue. O
 * histórico de quem mexeu fica na trait `Auditavel` do model.
 *
 * Datas
 * -----
 * `entregue_em`, `trocar_ate` e `devolvido_em` são dias no fuso do negócio,
 * resolvidos por `BusinessDate`, e nunca sofrem conversão de fuso.
 * `assinado_em` e `estornada_em` são instantes, gravados em UTC como todo
 * instante do projeto e convertidos só na exibição.
 */
class PpeDeliveryService
{
    /**
     * Disco em que a assinatura do técnico é gravada, o mesmo da assinatura do
     * cliente na OS (`WorkOrderSignatureService`).
     */
    private const DISCO_DE_ASSINATURA = 'public';

    /**
     * Registra a entrega de um EPI a um técnico.
     *
     * `$dados` aceita: `technician_id` e `personal_protective_equipment_id`
     * (obrigatórios), `quantidade` (opcional, 1 quando ausente), `entregue_em`
     * (opcional, hoje no fuso do negócio quando ausente), `motivo` (opcional,
     * `primeira_entrega` quando ausente) e `user_id` (opcional, quem registrou).
     *
     * O que este método garante, e por isso ninguém escreve em `ppe_deliveries`
     * por fora:
     *
     * 1. Técnico e EPI pertencem ao tenant corrente. Os dois são resolvidos pelo
     *    model escopado, nunca pelo id cru vindo do formulário: id de outra
     *    empresa não encontra registro e sai como 404, que é a resposta certa
     *    quando revelar a existência já vazaria informação.
     * 2. EPI com CA vencido é recusado (`CaVencidoException`), com o caminho de
     *    correção na mensagem.
     * 3. `numero_ca` e `validade_ca` são **copiados** do cadastro para a linha da
     *    entrega.
     * 4. `trocar_ate` = `entregue_em` + `vida_util_dias`, ou nulo quando o
     *    cadastro não tem vida útil.
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws CaVencidoException Quando o CA do EPI está vencido hoje.
     * @throws ValidationException Quando falta técnico ou EPI, quando o EPI está
     *                             inativo, quando a quantidade não é positiva,
     *                             quando o motivo não é um dos aceitos ou quando
     *                             `entregue_em` é futura.
     */
    public function registrar(array $dados): PpeDelivery
    {
        $tecnico = $this->tecnicoDoTenant($dados['technician_id'] ?? null);
        $epi = $this->epiDoTenant($dados['personal_protective_equipment_id'] ?? null);

        $this->garantirMesmaEmpresa($tecnico, $epi);
        $this->garantirEpiAtivo($epi);
        $this->garantirCaValido($epi);

        $entregueEm = $this->diaDaEntrega($dados['entregue_em'] ?? null);
        $quantidade = $this->quantidade($dados['quantidade'] ?? null);
        $motivo = $this->motivo($dados['motivo'] ?? null);

        return DB::transaction(function () use ($tecnico, $epi, $entregueEm, $quantidade, $motivo, $dados): PpeDelivery {
            return PpeDelivery::create([
                'technician_id' => $tecnico->getKey(),
                'personal_protective_equipment_id' => $epi->getKey(),
                'quantidade' => $quantidade,
                'entregue_em' => $entregueEm->toDateString(),
                'motivo' => $motivo,
                // Cópia do CA no ato. Ver o cabeçalho da classe: a ficha não
                // relê o cadastro depois de gravada.
                'numero_ca' => $epi->numero_ca,
                'validade_ca' => BusinessDate::diaDe($epi->validade_ca),
                'trocar_ate' => $this->trocarAte($entregueEm, $epi->vida_util_dias),
                'user_id' => $dados['user_id'] ?? null,
            ]);
        });
    }

    /**
     * Grava a assinatura do técnico e o instante em que ela foi coletada.
     *
     * A assinatura é a prova do recebimento exigida pela NR-6: entrega sem ela é
     * pendência, não ficha válida.
     *
     * Reassinar uma entrega já assinada é **correção**, e sobrescreve a mesma
     * linha. Nunca uma segunda entrega: duplicar a linha faria o técnico
     * aparecer com dois respiradores que ele nunca recebeu, e a ficha passaria a
     * mentir sobre a quantidade entregue. O antes e o depois ficam registrados
     * pela trait `Auditavel` do model. O arquivo da assinatura anterior é
     * preservado no disco de propósito: o caminho antigo continua no histórico
     * de auditoria, e apagar a imagem deixaria aquele registro apontando para o
     * nada.
     *
     * `$imagemBase64` é o PNG do quadro de assinatura, com ou sem o prefixo
     * `data:image/png;base64,`. Só o caminho do arquivo vai para a coluna,
     * nunca o base64.
     *
     * @throws EntregaDeEpiEstornadaException Quando a entrega já foi estornada.
     * @throws ValidationException Quando a imagem vem vazia ou não é base64.
     */
    public function assinar(PpeDelivery $entrega, string $imagemBase64): void
    {
        $this->garantirNaoEstornada($entrega, 'assinar');

        $caminho = $this->gravarAssinatura($entrega, $imagemBase64);

        $entrega->update([
            'assinatura_path' => $caminho,
            // Instante, não dia: duas assinaturas no mesmo dia precisam ficar em
            // sequência. Gravado em UTC como todo instante do projeto.
            'assinado_em' => now(),
        ]);
    }

    /**
     * Estorna a entrega, com motivo obrigatório.
     *
     * É a única forma de corrigir uma entrega errada. A linha continua no banco
     * e na extração por período, marcada como estornada, e sai de todo cálculo
     * de situação e de todo alerta — sumir com o registro é o que a norma não
     * permite, e é também o que apagaria o rastro do próprio erro.
     *
     * Motivo vazio é recusado: um estorno sem motivo é indistinguível de
     * exclusão disfarçada, e é justamente o motivo que transforma a correção em
     * documento defensável. Mesma exigência do ajuste e do descarte de estoque
     * (`StockService`).
     *
     * @throws EntregaDeEpiEstornadaException Quando a entrega já está estornada.
     * @throws ValidationException Quando o motivo vem vazio.
     */
    public function estornar(PpeDelivery $entrega, string $motivo): void
    {
        $this->garantirNaoEstornada($entrega, 'um novo estorno');

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw ValidationException::withMessages([
                'motivo_estorno' => 'Informe o motivo do estorno: a entrega não é apagada, '
                    .'ela fica registrada como estornada e o motivo é o que explica a correção.',
            ]);
        }

        $entrega->update([
            'estornada_em' => now(),
            'motivo_estorno' => $motivo,
        ]);
    }

    /**
     * Registra a devolução do item pelo técnico.
     *
     * Item devolvido para de contar como EPI em uso do técnico, mas continua na
     * ficha: a entrega aconteceu, e é dela que sai a prova de que o equipamento
     * foi fornecido no período em que o técnico trabalhou com ele.
     *
     * Devolução não é estorno. O estorno diz "esta entrega está errada"; a
     * devolução diz "esta entrega aconteceu e terminou". Confundir os dois
     * apagaria da ficha um fornecimento que de fato existiu.
     *
     * `$data` é opcional e cai em hoje, no fuso do negócio. Não pode ser futura
     * nem anterior à entrega: devolver antes de receber é erro de digitação, e
     * data futura em ficha de EPI vira prova ruim em fiscalização.
     *
     * @throws EntregaDeEpiEstornadaException Quando a entrega está estornada.
     * @throws ValidationException Quando o motivo vem vazio ou a data é inválida.
     */
    public function devolver(
        PpeDelivery $entrega,
        string $motivo,
        DateTimeInterface|string|null $data = null
    ): void {
        $this->garantirNaoEstornada($entrega, 'registrar a devolução');

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw ValidationException::withMessages([
                'motivo_devolucao' => 'Informe o motivo da devolução (troca, desligamento, dano).',
            ]);
        }

        $dia = BusinessDate::paraFusoNegocio($data)?->startOfDay() ?? BusinessDate::hoje();

        if ($dia->greaterThan(BusinessDate::hoje())) {
            throw ValidationException::withMessages([
                'devolvido_em' => 'A data da devolução não pode ser futura.',
            ]);
        }

        $entregueEm = BusinessDate::paraFusoNegocio($entrega->entregue_em)?->startOfDay();

        if ($entregueEm !== null && $dia->lessThan($entregueEm)) {
            throw ValidationException::withMessages([
                'devolvido_em' => sprintf(
                    'A devolução não pode ser anterior à entrega, registrada em %s.',
                    $entregueEm->format('d/m/Y')
                ),
            ]);
        }

        $entrega->update([
            'devolvido_em' => $dia->toDateString(),
            'motivo_devolucao' => $motivo,
        ]);
    }

    // -----------------------------------------------------------------
    // registrar(): passos internos
    // -----------------------------------------------------------------

    /**
     * Técnico do tenant corrente, pelo model escopado.
     *
     * Nunca pelo id cru: `technician_id` chega do formulário, e o escopo global
     * por empresa só protege quem passa por uma consulta. Técnico de outra
     * empresa simplesmente não é encontrado.
     */
    private function tecnicoDoTenant(mixed $id): Technician
    {
        if (blank($id)) {
            throw ValidationException::withMessages([
                'technician_id' => 'Informe o técnico que está recebendo o EPI.',
            ]);
        }

        return Technician::query()->findOrFail((int) $id);
    }

    /**
     * Modelo de EPI do tenant corrente, pelo model escopado. Mesmo cuidado do
     * técnico.
     */
    private function epiDoTenant(mixed $id): PersonalProtectiveEquipment
    {
        if (blank($id)) {
            throw ValidationException::withMessages([
                'personal_protective_equipment_id' => 'Informe qual EPI está sendo entregue.',
            ]);
        }

        return PersonalProtectiveEquipment::query()->findOrFail((int) $id);
    }

    /**
     * Rede de segurança contra mistura de empresas.
     *
     * Dentro de uma requisição o escopo global já garante isto, e as duas
     * consultas acima nem encontrariam o registro alheio. A verificação existe
     * para os contextos em que o escopo não filtra — comando artisan, seeder,
     * job de fila e teste, onde `TenantAtual::id()` pode ser nulo. Ali, dois ids
     * de empresas diferentes passariam, e o resultado seria a pior falha
     * possível deste sistema: a ficha de um técnico apontando para o cadastro de
     * um concorrente.
     */
    private function garantirMesmaEmpresa(Technician $tecnico, PersonalProtectiveEquipment $epi): void
    {
        if ($tecnico->company_id === null || $epi->company_id === null) {
            return;
        }

        if ((int) $tecnico->company_id !== (int) $epi->company_id) {
            throw ValidationException::withMessages([
                'personal_protective_equipment_id' => 'O técnico e o EPI informados pertencem a empresas diferentes.',
            ]);
        }
    }

    /**
     * EPI inativo não é entregue.
     *
     * Inativar é o substituto da exclusão neste cadastro (ver `PpeService`): se
     * o modelo inativo continuasse podendo ser entregue, inativar não
     * significaria nada, e o cadastro que a empresa "aposentou" voltaria à ficha
     * pela porta dos fundos.
     */
    private function garantirEpiAtivo(PersonalProtectiveEquipment $epi): void
    {
        if ((bool) $epi->ativo) {
            return;
        }

        throw ValidationException::withMessages([
            'personal_protective_equipment_id' => sprintf(
                'O EPI "%s" está inativo e não pode ser entregue. Reative o cadastro ou escolha outro modelo.',
                $epi->nome
            ),
        ]);
    }

    /**
     * CA vencido recusa a entrega.
     *
     * A comparação é por dia no fuso do negócio (`BusinessDate::estaVencido()`),
     * nunca contra `now()` em UTC, que daria o certificado como vencido a partir
     * das 21h de Brasília do dia anterior. CA que vence hoje ainda vale hoje.
     *
     * EPI sem validade cadastrada passa: campo em branco é estado neutro, nunca
     * irregularidade — mesma regra do checklist do Plano 24. Acusar de irregular
     * quem apenas não preencheu destruiria a confiança no aviso.
     */
    private function garantirCaValido(PersonalProtectiveEquipment $epi): void
    {
        if ($epi->validade_ca === null) {
            return;
        }

        if (BusinessDate::estaVencido($epi->validade_ca)) {
            throw CaVencidoException::para($epi);
        }
    }

    /**
     * Dia da entrega, no fuso do negócio. Hoje quando não informado.
     *
     * Data futura é recusada: em ficha de EPI ela é erro de digitação que vira
     * prova ruim em fiscalização — o documento passaria a afirmar uma entrega
     * que ainda não aconteceu.
     */
    private function diaDaEntrega(mixed $valor): CarbonImmutable
    {
        $dia = BusinessDate::paraFusoNegocio($valor)?->startOfDay() ?? BusinessDate::hoje();

        if ($dia->greaterThan(BusinessDate::hoje())) {
            throw ValidationException::withMessages([
                'entregue_em' => 'A data da entrega não pode ser futura.',
            ]);
        }

        return $dia;
    }

    /**
     * Troca programada do item na mão do técnico.
     *
     * Nula quando o cadastro não tem `vida_util_dias`: "sem troca programada" é
     * um estado legítimo, e inventar um prazo padrão produziria alerta falso.
     * Zero é diferente de ausente — é uma escolha do cadastro, e devolve o
     * próprio dia da entrega.
     */
    private function trocarAte(CarbonImmutable $entregueEm, ?int $vidaUtilDias): ?string
    {
        if ($vidaUtilDias === null) {
            return null;
        }

        return $entregueEm->addDays($vidaUtilDias)->toDateString();
    }

    private function quantidade(mixed $valor): int
    {
        $quantidade = blank($valor) ? 1 : (int) $valor;

        if ($quantidade < 1) {
            throw ValidationException::withMessages([
                'quantidade' => 'A quantidade entregue precisa ser maior que zero.',
            ]);
        }

        return $quantidade;
    }

    private function motivo(mixed $valor): string
    {
        $motivo = blank($valor) ? 'primeira_entrega' : (string) $valor;

        if (! in_array($motivo, PpeDelivery::MOTIVOS, true)) {
            throw ValidationException::withMessages([
                'motivo' => 'Motivo de entrega inválido.',
            ]);
        }

        return $motivo;
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Entrega estornada não recebe mais nada.
     *
     * Ver `EntregaDeEpiEstornadaException`: a linha estornada foi declarada
     * errada, e quem precisa do registro certo lança uma entrega nova.
     */
    private function garantirNaoEstornada(PpeDelivery $entrega, string $operacao): void
    {
        if ($entrega->estornada_em !== null) {
            throw EntregaDeEpiEstornadaException::para($entrega, $operacao);
        }
    }

    /**
     * Decodifica o PNG do quadro de assinatura e grava em disco. Nunca guarda
     * base64 em coluna: só o caminho do arquivo vai para `assinatura_path`.
     */
    private function gravarAssinatura(PpeDelivery $entrega, string $imagemBase64): string
    {
        $conteudo = trim($imagemBase64);

        if ($conteudo === '') {
            throw ValidationException::withMessages([
                'assinatura' => 'A assinatura do técnico é obrigatória para confirmar o recebimento.',
            ]);
        }

        if (str_starts_with($conteudo, 'data:') && str_contains($conteudo, ',')) {
            [, $conteudo] = explode(',', $conteudo, 2);
        }

        $binario = base64_decode($conteudo, true);

        if ($binario === false || $binario === '') {
            throw ValidationException::withMessages([
                'assinatura' => 'A imagem da assinatura não é um base64 válido.',
            ]);
        }

        $caminho = 'ppe-deliveries/'.$entrega->getKey().'/assinaturas/'.(string) Str::uuid().'.png';

        Storage::disk(self::DISCO_DE_ASSINATURA)->put($caminho, $binario);

        return $caminho;
    }
}
