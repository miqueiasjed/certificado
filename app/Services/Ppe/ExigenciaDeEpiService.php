<?php

namespace App\Services\Ppe;

use App\Models\PersonalProtectiveEquipment;
use App\Models\Service;
use App\Models\ServicePpeRequirement;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * EPI exigido por serviço, do lado do escritório (Plano 29, Task 29.3).
 *
 * **Nenhuma escrita em `service_ppe_requirements` acontece fora daqui.** É esta
 * tabela que decide o que o aplicativo do técnico mostra na etapa de confirmação
 * (`AppDayLoadService`), o que a `ConfirmacaoDeEpiService` aceita gravar como
 * confirmação e o que o checklist da RDC 622/2022 cobra. Gravar a linha por
 * Eloquent direto pula as três garantias abaixo.
 *
 * O que este Service garante
 * --------------------------
 * 1. **Serviço e EPI da mesma empresa, sempre reconsultados pelo model
 *    escopado.** O id chega pelo corpo da requisição, onde o escopo global do
 *    Eloquent não alcança: `ServicePpeRequirementRequest` recusa antes, com 422,
 *    e aqui vem a segunda tranca do mesmo portão — a mesma dupla proteção de
 *    `PpeDeliveryService`.
 * 2. **Exigir o mesmo EPI duas vezes no mesmo serviço é atualização, não erro.**
 *    A unique composta da Task 29.1 devolveria um 500 de violação de chave para
 *    quem apenas clicou duas vezes no botão, ou reabriu a tela com o item já
 *    cadastrado. Aqui o segundo registro atualiza `obrigatorio` na linha que já
 *    existe.
 * 3. **EPI inativo não vira exigência nova.** O cadastro do Plano 28 inativa em
 *    vez de excluir, e um EPI inativo é o que a empresa deixou de usar. Exigir
 *    em campo o que não se compra mais produziria uma pendência que ninguém
 *    consegue resolver — e a `AppDayLoadService` sequer levaria o item ao
 *    aparelho, então a exigência nasceria invisível.
 *
 * O que este Service **não** faz
 * ------------------------------
 * Não bloqueia nada. Serviço sem nenhuma exigência cadastrada é o estado normal
 * de quem acabou de ligar o módulo, e nunca irregularidade: a etapa de
 * confirmação simplesmente não aparece para aquele serviço. Remover uma
 * exigência também não apaga confirmação nenhuma já registrada — o que o técnico
 * confirmou em campo é fato consumado, e continua na OS.
 */
class ExigenciaDeEpiService
{
    /**
     * Cadastra (ou atualiza) a exigência de um EPI em um serviço.
     *
     * `$dados` traz `service_id`, `personal_protective_equipment_id` e,
     * opcionalmente, `obrigatorio` (padrão `true`: quem cadastra uma exigência
     * está dizendo que aquele EPI é exigido; o apenas recomendado é o caso raro e
     * pede marcação explícita — mesmo critério do default da coluna).
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws ValidationException Quando falta o serviço ou o EPI, quando os dois
     *                             são de empresas diferentes ou quando o EPI está
     *                             inativo.
     */
    public function registrar(array $dados): ServicePpeRequirement
    {
        $servico = $this->servicoDoTenant($dados['service_id'] ?? null);
        $epi = $this->epiDoTenant($dados['personal_protective_equipment_id'] ?? null);

        $this->garantirMesmaEmpresa($servico, $epi);
        $this->garantirEpiAtivo($epi);

        $obrigatorio = $this->obrigatorio($dados['obrigatorio'] ?? null);

        return DB::transaction(function () use ($servico, $epi, $obrigatorio): ServicePpeRequirement {
            $existente = ServicePpeRequirement::query()
                ->where('service_id', $servico->getKey())
                ->where('personal_protective_equipment_id', $epi->getKey())
                ->lockForUpdate()
                ->first();

            // Segundo cadastro do mesmo par: atualiza a linha existente. Ver o
            // item 2 do cabeçalho.
            if ($existente !== null) {
                $existente->update(['obrigatorio' => $obrigatorio]);

                return $existente->refresh();
            }

            return ServicePpeRequirement::create([
                'service_id' => $servico->getKey(),
                'personal_protective_equipment_id' => $epi->getKey(),
                'obrigatorio' => $obrigatorio,
            ]);
        });
    }

    /**
     * Alterna a exigência entre obrigatória e recomendada.
     *
     * É a única coisa editável de uma exigência: trocar o serviço ou o EPI seria
     * outra exigência, e a forma de fazer isso é remover esta e cadastrar aquela.
     *
     * A diferença entre os dois valores não é cosmética: só o EPI **obrigatório**
     * marcado como não usado gera o aviso ao gestor
     * (`ConfirmacaoDeEpiService::avisarExecucaoSemEpi()`). Transformar
     * recomendação em alarme faria o gestor desligar o evento inteiro, e junto
     * com ele o aviso que importa.
     */
    public function atualizar(ServicePpeRequirement $exigencia, bool $obrigatorio): ServicePpeRequirement
    {
        $exigencia->update(['obrigatorio' => $obrigatorio]);

        return $exigencia->refresh();
    }

    /**
     * Remove a exigência do serviço.
     *
     * Exclusão de verdade, e não inativação: diferente da entrega de EPI (que é
     * documento oponível e por isso só admite estorno), a exigência é cadastro de
     * configuração — declarar que um serviço exige respirador e depois voltar
     * atrás não é fato a preservar, é o cadastro sendo corrigido.
     *
     * O que **não** é apagado junto: as confirmações já registradas nas ordens de
     * serviço (`WorkOrderPpeConfirmation`). O que o técnico confirmou vestir em
     * campo aconteceu, e continua valendo como registro mesmo depois de a
     * exigência sair do cadastro — inclusive continua aceitando reenvio da fila
     * offline, garantia que a `ConfirmacaoDeEpiService` mantém de propósito.
     */
    public function remover(ServicePpeRequirement $exigencia): void
    {
        $exigencia->delete();
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Serviço da empresa corrente.
     *
     * `findOrFail` no model escopado: serviço de outra empresa não é encontrado
     * pelo escopo global e sai como 404, que é a resposta certa quando revelar a
     * existência do registro já vaza informação.
     */
    private function servicoDoTenant(mixed $id): Service
    {
        if (blank($id)) {
            throw ValidationException::withMessages([
                'service_id' => 'Informe o serviço que exige o EPI.',
            ]);
        }

        return Service::query()->findOrFail((int) $id);
    }

    /**
     * Modelo de EPI da empresa corrente.
     */
    private function epiDoTenant(mixed $id): PersonalProtectiveEquipment
    {
        if (blank($id)) {
            throw ValidationException::withMessages([
                'personal_protective_equipment_id' => 'Informe qual EPI o serviço exige.',
            ]);
        }

        return PersonalProtectiveEquipment::query()->findOrFail((int) $id);
    }

    /**
     * Rede de segurança contra mistura de empresas, no mesmo formato de
     * `PpeDeliveryService::garantirMesmaEmpresa()`.
     *
     * Dentro de uma requisição o escopo global já garante isto, e as duas
     * consultas acima nem encontrariam o registro alheio. A verificação existe
     * para os contextos em que o escopo não filtra — comando artisan, seeder, job
     * de fila e teste, onde `TenantAtual::id()` pode ser nulo. Ali, dois ids de
     * empresas diferentes passariam, e o serviço de uma empresa passaria a exigir
     * o EPI cadastrado por um concorrente.
     */
    private function garantirMesmaEmpresa(Service $servico, PersonalProtectiveEquipment $epi): void
    {
        if ($servico->company_id === null || $epi->company_id === null) {
            return;
        }

        if ((int) $servico->company_id !== (int) $epi->company_id) {
            throw ValidationException::withMessages([
                'personal_protective_equipment_id' => 'O serviço e o EPI informados pertencem a empresas diferentes.',
            ]);
        }
    }

    /**
     * EPI inativo não vira exigência nova. Ver o item 3 do cabeçalho.
     *
     * A checagem vale só para o cadastro da exigência. A linha que já existe
     * continua no banco depois de o EPI ser inativado, e some sozinha da carga do
     * dia (`AppDayLoadService` só embarca EPI ativo): inativar um modelo de EPI
     * não pode disparar exclusão em cascata de cadastro nenhum.
     */
    private function garantirEpiAtivo(PersonalProtectiveEquipment $epi): void
    {
        if ((bool) $epi->ativo) {
            return;
        }

        throw ValidationException::withMessages([
            'personal_protective_equipment_id' => sprintf(
                'O EPI "%s" está inativo e não pode ser exigido por um serviço. '
                .'Reative o cadastro ou escolha outro modelo.',
                $epi->nome
            ),
        ]);
    }

    /**
     * Ausente é `true`, o mesmo default da coluna.
     */
    private function obrigatorio(mixed $valor): bool
    {
        if ($valor === null || $valor === '') {
            return true;
        }

        return filter_var($valor, FILTER_VALIDATE_BOOLEAN);
    }
}
