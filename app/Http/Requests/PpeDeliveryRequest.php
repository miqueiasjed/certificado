<?php

namespace App\Http\Requests;

use App\Models\PpeDelivery;
use App\Support\DominioMultiempresa;
use App\Support\TenantAtual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Entrada das quatro operações da ficha de entrega de EPI (Plano 28, Task 28.4):
 * registrar, assinar, devolver e estornar.
 *
 * Um FormRequest para as quatro, com as regras escolhidas pelo nome da rota. São
 * quatro formas do **mesmo** documento, e mantê-las no mesmo arquivo é o que
 * garante que ninguém acrescente uma quinta operação sem passar pelas mesmas
 * decisões de escopo de tenant registradas aqui.
 *
 * Não existe forma de edição nem de exclusão, e não pode passar a existir: a
 * entrega é documento oponível, com arquivamento recomendado de 20 anos, e a
 * correção de erro é o estorno com motivo (ver `PpeDelivery` e
 * `EntregaDeEpiEstornadaException`).
 *
 * O escopo do tenant mora nas regras, não no controller
 * ----------------------------------------------------
 * `technician_id` e `personal_protective_equipment_id` chegam pelo **corpo** da
 * requisição, e ali o escopo global do Eloquent não alcança: `exists:` cru roda
 * direto no query builder, aceita id de outra empresa e ainda responde se aquele
 * id existe em algum tenant — o que já é vazamento, mesmo quando a operação
 * seguinte falha. Por isso as duas regras são compostas com
 * `where(company_id, TenantAtual::exigirId())`. É exatamente o defeito corrigido
 * no commit `d9a3a9c`, e o mesmo cuidado de `VehicleRequest` e
 * `RefuelingRequest`.
 *
 * `exigirId()` em vez de `id()`, pelo mesmo motivo daqueles dois: a rota é
 * autenticada e `users.company_id` é NOT NULL, então requisição sem empresa
 * resolvida é bug, e falhar alto é melhor que validar contra o banco inteiro.
 *
 * O `PpeDeliveryService` ainda reconsulta os dois pelo model escopado antes de
 * gravar. A regra daqui não substitui aquela consulta: adianta o erro para o
 * formulário, com mensagem de campo, em vez de deixar o usuário receber um 404
 * seco por ter escolhido um item que a tela não deveria ter oferecido.
 *
 * O que **não** está aqui
 * -----------------------
 * Data futura, EPI inativo, CA vencido, entrega já estornada e devolução
 * anterior à entrega são regra de negócio e ficam no `PpeDeliveryService`, que
 * também roda fora de requisição HTTP. Repeti-las aqui criaria duas versões da
 * mesma regra, e a que valeria em comando artisan seria a outra.
 */
class PpeDeliveryRequest extends FormRequest
{
    /**
     * Todas as rotas que usam este request estão sob `module:epi` mais
     * `permission:epi-entregar` ou `permission:epi-estornar`, e o `{delivery}`
     * chega por route-model binding escopado: entrega de outra empresa não é
     * encontrada e sai 404.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return match (true) {
            $this->routeIs('epi.entregas.assinar') => $this->regrasDaAssinatura(),
            $this->routeIs('epi.entregas.devolver') => $this->regrasDaDevolucao(),
            $this->routeIs('epi.entregas.estornar') => $this->regrasDoEstorno(),
            $this->routeIs('epi.extracao') => $this->regrasDaExtracao(),
            default => $this->regrasDoRegistro(),
        };
    }

    /**
     * Registro da entrega.
     *
     * `quantidade`, `entregue_em` e `motivo` são opcionais: o Service aplica os
     * padrões (1, hoje no fuso do negócio, `primeira_entrega`). A recusa de data
     * futura fica lá, e não em `before_or_equal:today`, porque "hoje" precisa
     * ser o dia no fuso do negócio, nunca o dia em UTC.
     *
     * @return array<string, mixed>
     */
    private function regrasDoRegistro(): array
    {
        return [
            'technician_id' => ['required', 'integer', $this->regraDeTecnicoDaEmpresa()],
            'personal_protective_equipment_id' => ['required', 'integer', $this->regraDeEpiDaEmpresa()],
            'quantidade' => 'nullable|integer|min:1|max:1000',
            'entregue_em' => 'nullable|date',
            'motivo' => ['nullable', Rule::in(PpeDelivery::MOTIVOS)],
        ];
    }

    /**
     * Assinatura de recebimento: o PNG do quadro de assinatura, com ou sem o
     * prefixo `data:image/png;base64,`.
     *
     * A decodificação e a recusa de base64 inválido ficam no Service, que é
     * quem grava o arquivo. Aqui só se garante que veio alguma coisa.
     *
     * @return array<string, mixed>
     */
    private function regrasDaAssinatura(): array
    {
        return [
            'assinatura' => 'required|string',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function regrasDaDevolucao(): array
    {
        return [
            'motivo_devolucao' => 'required|string|max:255',
            'devolvido_em' => 'nullable|date',
        ];
    }

    /**
     * Estorno.
     *
     * Motivo obrigatório: um estorno sem motivo é indistinguível de exclusão
     * disfarçada, e é justamente o motivo que transforma a correção em documento
     * defensável.
     *
     * @return array<string, mixed>
     */
    private function regrasDoEstorno(): array
    {
        return [
            'motivo_estorno' => 'required|string|max:500',
        ];
    }

    /**
     * Extração da relação de entregas por período (Task 28.5).
     *
     * O período é obrigatório dos dois lados: o item 6.5.1.1 da NR-6 pede a
     * relação extraível, e uma extração sem recorte varreria a tabela inteira e
     * montaria o CSV em memória. `fim` depois de `inicio` é conferido aqui para
     * o usuário ver erro de campo; o `FichaDeEpiService` mantém a própria
     * recusa de período invertido como rede de segurança fora do HTTP.
     *
     * @return array<string, mixed>
     */
    private function regrasDaExtracao(): array
    {
        return [
            'inicio' => 'required|date',
            'fim' => 'required|date|after_or_equal:inicio',
        ];
    }

    /**
     * Técnico existente E da empresa corrente.
     */
    private function regraDeTecnicoDaEmpresa(): Exists
    {
        return Rule::exists('technicians', 'id')
            ->where(DominioMultiempresa::COLUNA_TENANT, TenantAtual::exigirId());
    }

    /**
     * Modelo de EPI existente E da empresa corrente.
     */
    private function regraDeEpiDaEmpresa(): Exists
    {
        return Rule::exists('personal_protective_equipments', 'id')
            ->where(DominioMultiempresa::COLUNA_TENANT, TenantAtual::exigirId());
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'technician_id.required' => 'Informe o técnico que está recebendo o EPI.',
            'technician_id.integer' => 'Técnico inválido.',
            'technician_id.exists' => 'O técnico selecionado não existe.',
            'personal_protective_equipment_id.required' => 'Informe qual EPI está sendo entregue.',
            'personal_protective_equipment_id.integer' => 'EPI inválido.',
            'personal_protective_equipment_id.exists' => 'O EPI selecionado não existe.',
            'quantidade.min' => 'A quantidade entregue precisa ser maior que zero.',
            'quantidade.max' => 'Quantidade acima do que cabe em uma única entrega.',
            'entregue_em.date' => 'Informe uma data válida para a entrega.',
            'motivo.in' => 'Motivo de entrega inválido.',
            'assinatura.required' => 'A assinatura do técnico é obrigatória para confirmar o recebimento.',
            'motivo_devolucao.required' => 'Informe o motivo da devolução (troca, desligamento, dano).',
            'devolvido_em.date' => 'Informe uma data válida para a devolução.',
            'motivo_estorno.required' => 'Informe o motivo do estorno: a entrega não é apagada, '
                .'ela fica registrada como estornada e o motivo é o que explica a correção.',
            'inicio.required' => 'Informe a data inicial do período a extrair.',
            'inicio.date' => 'Informe uma data inicial válida.',
            'fim.required' => 'Informe a data final do período a extrair.',
            'fim.date' => 'Informe uma data final válida.',
            'fim.after_or_equal' => 'A data final do período não pode ser anterior à inicial.',
        ];
    }
}
