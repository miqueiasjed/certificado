<?php

namespace App\Http\Requests;

use App\Support\DominioMultiempresa;
use App\Support\TenantAtual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Entrada do cadastro de EPI exigido por serviço (Plano 29, Task 29.3).
 *
 * Um FormRequest para as três operações que recebem corpo (listar com filtro,
 * cadastrar e alternar obrigatório/recomendado), com as regras escolhidas pelo
 * nome da rota — mesmo formato de `PpeDeliveryRequest`. A remoção não tem corpo:
 * a exigência chega por route-model binding.
 *
 * O escopo do tenant mora nas regras, não no controller
 * ----------------------------------------------------
 * `service_id` e `personal_protective_equipment_id` chegam pelo **corpo** da
 * requisição, e ali o escopo global do Eloquent não alcança: `exists:` cru roda
 * direto no query builder, aceita id de outra empresa e ainda responde se aquele
 * id existe em algum tenant — o que já é vazamento, mesmo quando a operação
 * seguinte falha. Por isso as duas regras são compostas com
 * `where(company_id, TenantAtual::exigirId())`. É exatamente o defeito corrigido
 * no commit `d9a3a9c`, e o mesmo cuidado de `PpeDeliveryRequest`,
 * `VehicleRequest` e `RefuelingRequest`.
 *
 * `exigirId()` em vez de `id()`, pelo mesmo motivo daqueles três: a rota é
 * autenticada e `users.company_id` é NOT NULL, então requisição sem empresa
 * resolvida é bug, e falhar alto é melhor que validar contra o banco inteiro.
 *
 * O `ExigenciaDeEpiService` ainda reconsulta os dois pelo model escopado antes de
 * gravar. A regra daqui não substitui aquela consulta: adianta o erro para o
 * formulário, com mensagem de campo, em vez de deixar o usuário receber um 404
 * seco por ter escolhido um item que a tela não deveria ter oferecido.
 *
 * O que **não** está aqui
 * -----------------------
 * EPI inativo, serviço e EPI de empresas diferentes e a exigência repetida do
 * mesmo par são regra de negócio e ficam no `ExigenciaDeEpiService`, que também
 * roda fora de requisição HTTP. Repeti-las aqui criaria duas versões da mesma
 * regra, e a que valeria em comando artisan seria a outra.
 */
class ServicePpeRequirementRequest extends FormRequest
{
    /**
     * Todas as rotas que usam este request estão sob `module:epi` mais
     * `permission:epi-gerenciar`, e a `{requirement}` chega por route-model
     * binding escopado: exigência de outra empresa não é encontrada e sai 404.
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
            $this->routeIs('epi.exigencias.index') => $this->regrasDaListagem(),
            $this->routeIs('epi.exigencias.update') => $this->regrasDaAtualizacao(),
            default => $this->regrasDoRegistro(),
        };
    }

    /**
     * Listagem, opcionalmente filtrada por serviço.
     *
     * O filtro é opcional porque a tela de cadastro do serviço pede as exigências
     * de um serviço só, e a conferência do escritório ("o que já foi cadastrado?")
     * pede todas. Mesmo com filtro opcional, quando ele vem é escopado: sem a
     * regra, `?service_id=` de outra empresa devolveria lista vazia — inofensivo —
     * mas a rota de cadastro logo abaixo aceitaria o mesmo id.
     *
     * @return array<string, mixed>
     */
    private function regrasDaListagem(): array
    {
        return [
            'service_id' => ['nullable', 'integer', $this->regraDeServicoDaEmpresa()],
        ];
    }

    /**
     * Cadastro da exigência.
     *
     * `obrigatorio` é opcional: o Service aplica o padrão `true`, o mesmo default
     * da coluna. Quem cadastra uma exigência está dizendo que aquele EPI é
     * exigido; o apenas recomendado é o caso raro e pede marcação explícita.
     *
     * @return array<string, mixed>
     */
    private function regrasDoRegistro(): array
    {
        return [
            'service_id' => ['required', 'integer', $this->regraDeServicoDaEmpresa()],
            'personal_protective_equipment_id' => ['required', 'integer', $this->regraDeEpiDaEmpresa()],
            'obrigatorio' => 'nullable|boolean',
        ];
    }

    /**
     * Alternar entre obrigatório e recomendado é a única edição possível: trocar o
     * serviço ou o EPI seria outra exigência, e a forma de fazer isso é remover
     * esta e cadastrar aquela. Por isso os dois ids não aparecem aqui.
     *
     * @return array<string, mixed>
     */
    private function regrasDaAtualizacao(): array
    {
        return [
            'obrigatorio' => 'required|boolean',
        ];
    }

    /**
     * Serviço existente E da empresa corrente.
     */
    private function regraDeServicoDaEmpresa(): Exists
    {
        return Rule::exists('services', 'id')
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
            'service_id.required' => 'Informe o serviço que exige o EPI.',
            'service_id.integer' => 'Serviço inválido.',
            'service_id.exists' => 'O serviço selecionado não existe.',
            'personal_protective_equipment_id.required' => 'Informe qual EPI o serviço exige.',
            'personal_protective_equipment_id.integer' => 'EPI inválido.',
            'personal_protective_equipment_id.exists' => 'O EPI selecionado não existe.',
            'obrigatorio.required' => 'Informe se o EPI é obrigatório ou apenas recomendado neste serviço.',
            'obrigatorio.boolean' => 'A exigência precisa ser obrigatória ou recomendada.',
        ];
    }
}
