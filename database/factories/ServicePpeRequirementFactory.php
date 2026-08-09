<?php

namespace Database\Factories;

use App\Models\PersonalProtectiveEquipment;
use App\Models\Service;
use App\Models\ServicePpeRequirement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * EPI que um serviço exige (Plano 29, Task 29.1).
 *
 * O model não usa `HasFactory`, como o resto do domínio deste projeto:
 * instanciar sempre por `ServicePpeRequirementFactory::new()`.
 *
 * Esta fábrica não substitui o `ExigenciaDeEpiService`
 * ---------------------------------------------------
 * Em produção, **nenhuma escrita em `service_ppe_requirements` acontece fora do
 * Service**: é ele que recusa EPI inativo, que trata o cadastro repetido do
 * mesmo par como atualização e que confere que serviço e EPI são da mesma
 * empresa. A fábrica existe só para montar o cenário de leitura — a carga do
 * dia, a confirmação da fila offline, o checklist — sem arrastar a tela de
 * cadastro para dentro de cada teste.
 *
 * Todo teste que exercita **regra** (EPI inativo, par repetido, empresas
 * diferentes) chama o Service. Usar a fábrica ali provaria apenas que a fábrica
 * faz o que ela mesma escreveu.
 *
 * `company_id` fica a cargo da trait `BelongsToCompany`; criar fora de
 * `TenantAtual::comTenant()` deixa a coluna NOT NULL sem valor.
 *
 * @extends Factory<ServicePpeRequirement>
 */
class ServicePpeRequirementFactory extends Factory
{
    protected $model = ServicePpeRequirement::class;

    /**
     * `obrigatorio` nasce verdadeiro, o mesmo default da coluna e o mesmo
     * padrão do Service: quem cadastra uma exigência está dizendo que aquele EPI
     * é exigido, e o apenas recomendado é o caso raro, que pede marcação
     * explícita.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'obrigatorio' => true,
        ];
    }

    /**
     * A exigência de um modelo de EPI em um serviço.
     */
    public function exigidoPor(Service $servico, PersonalProtectiveEquipment $epi): static
    {
        return $this->state(fn (): array => [
            'service_id' => $servico->getKey(),
            'personal_protective_equipment_id' => $epi->getKey(),
        ]);
    }

    /**
     * Recomendado, e não exigido.
     *
     * A diferença não é cosmética: só o EPI **obrigatório** marcado como não
     * usado gera o aviso ao gestor
     * (`ConfirmacaoDeEpiService::avisarExecucaoSemEpi()`).
     */
    public function recomendado(): static
    {
        return $this->state(fn (): array => ['obrigatorio' => false]);
    }
}
