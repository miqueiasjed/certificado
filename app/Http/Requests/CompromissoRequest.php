<?php

namespace App\Http\Requests;

use App\Models\Compromisso;
use App\Support\DominioMultiempresa;
use App\Support\TenantAtual;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

/**
 * Entrada das operações do compromisso avulso da Agenda (Plano 30, Task 30.3):
 * criar, atualizar e promover para ordem de serviço.
 *
 * `cancelar()` e `concluir()` não passam por aqui: não têm corpo nenhum a
 * validar, então o `CompromissoController` recebe só o `Compromisso` da rota
 * nesses dois métodos, sem `FormRequest`.
 *
 * O escopo do tenant mora nas regras, não no controller
 * ----------------------------------------------------
 * `client_id`, `address_id`, `technician_id`, `work_order_id` (aqui) e
 * `service_id` (na promoção) chegam pelo **corpo** da requisição, e ali o
 * escopo global do Eloquent não alcança: `exists:` cru roda direto no query
 * builder, aceita id de outra empresa e ainda responde se aquele id existe em
 * algum tenant, o que já é vazamento mesmo quando a operação seguinte falha.
 * Por isso todas são compostas com `where(company_id, TenantAtual::exigirId())`,
 * mesmo critério de `PpeDeliveryRequest` e o defeito corrigido no commit
 * `d9a3a9c`.
 *
 * `exigirId()` em vez de `id()`: a rota é autenticada e `users.company_id` é
 * NOT NULL, então requisição sem empresa resolvida é bug, e falhar alto é
 * melhor que validar contra o banco inteiro.
 *
 * `work_order_id` no formulário de criação/edição
 * ------------------------------------------------
 * Mesmo validado aqui, `CompromissoService::criar()` descarta `work_order_id`
 * do array antes de gravar, e `atualizar()` só grava os campos de
 * `CAMPOS_EDITAVEIS`, que não incluem `work_order_id`: a única porta de
 * promoção é `promoverParaOs()`. Validar o campo aqui não abre brecha, porque
 * o Service o ignora de qualquer forma; é apenas a regra pedida pela
 * especificação da task, para o formulário nunca aceitar um id de outra
 * empresa nesse campo, ainda que ele não seja usado no fim.
 */
class CompromissoRequest extends FormRequest
{
    /**
     * Todas as rotas que usam este request estão sob
     * `permission:compromisso-gerenciar`, e o `{compromisso}` chega por
     * route-model binding escopado: compromisso de outra empresa não é
     * encontrado e sai 404.
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
            $this->routeIs('compromissos.promover-os') => $this->regrasDaPromocao(),
            default => $this->regrasDoFormulario(),
        };
    }

    /**
     * Criação e atualização usam as mesmas regras: as duas são o mesmo
     * formulário, e o `CompromissoService` decide o que cada operação grava
     * de fato (`atualizar()` restringe aos campos editáveis).
     *
     * @return array<string, mixed>
     */
    private function regrasDoFormulario(): array
    {
        return [
            'tipo' => ['required', Rule::in(Compromisso::TIPOS)],
            'titulo' => 'required|string|max:255',
            'client_id' => ['nullable', 'integer', $this->regraDeClienteDaEmpresa()],
            'address_id' => ['nullable', 'integer', $this->regraDeEnderecoDaEmpresa()],
            'technician_id' => ['nullable', 'integer', $this->regraDeTecnicoDaEmpresa()],
            'work_order_id' => ['nullable', 'integer', $this->regraDeOrdemDeServicoDaEmpresa()],
            'endereco_texto' => 'nullable|string|max:255',
            'data' => 'required|date',
            'hora_inicio' => 'nullable|date_format:H:i',
            'hora_fim' => ['nullable', 'date_format:H:i', 'after:hora_inicio'],
            'observacoes' => 'nullable|string',
        ];
    }

    /**
     * Promoção para ordem de serviço.
     *
     * Sem corpo obrigatório: `CompromissoService::promoverParaOs()` monta a
     * OS a partir dos próprios dados do compromisso. O único campo aceito
     * aqui é `service_id`, opcional, porque `work_orders.service_id` é
     * nullable no banco e o compromisso não guarda serviço nenhum; quem
     * promove pode informá-lo, mas nada aqui obriga.
     *
     * @return array<string, mixed>
     */
    private function regrasDaPromocao(): array
    {
        return [
            'service_id' => ['nullable', 'integer', $this->regraDeServicoDaEmpresa()],
        ];
    }

    /**
     * Cliente existente E da empresa corrente.
     */
    private function regraDeClienteDaEmpresa(): Exists
    {
        return Rule::exists('clients', 'id')
            ->where(DominioMultiempresa::COLUNA_TENANT, TenantAtual::exigirId());
    }

    /**
     * Endereço existente E da empresa corrente.
     */
    private function regraDeEnderecoDaEmpresa(): Exists
    {
        return Rule::exists('addresses', 'id')
            ->where(DominioMultiempresa::COLUNA_TENANT, TenantAtual::exigirId());
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
     * Ordem de serviço existente E da empresa corrente.
     */
    private function regraDeOrdemDeServicoDaEmpresa(): Exists
    {
        return Rule::exists('work_orders', 'id')
            ->where(DominioMultiempresa::COLUNA_TENANT, TenantAtual::exigirId());
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
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'tipo.required' => 'Informe o tipo do compromisso.',
            'tipo.in' => 'Tipo de compromisso inválido.',
            'titulo.required' => 'Informe o título do compromisso.',
            'titulo.max' => 'O título não pode ter mais de 255 caracteres.',
            'client_id.integer' => 'Cliente inválido.',
            'client_id.exists' => 'O cliente selecionado não existe.',
            'address_id.integer' => 'Endereço inválido.',
            'address_id.exists' => 'O endereço selecionado não existe.',
            'technician_id.integer' => 'Técnico inválido.',
            'technician_id.exists' => 'O técnico selecionado não existe.',
            'work_order_id.integer' => 'Ordem de serviço inválida.',
            'work_order_id.exists' => 'A ordem de serviço selecionada não existe.',
            'endereco_texto.max' => 'O endereço não pode ter mais de 255 caracteres.',
            'data.required' => 'Informe a data do compromisso.',
            'data.date' => 'Informe uma data válida.',
            'hora_inicio.date_format' => 'Informe um horário de início válido (HH:MM).',
            'hora_fim.date_format' => 'Informe um horário de término válido (HH:MM).',
            'hora_fim.after' => 'O horário de término deve ser posterior ao horário de início.',
            'service_id.integer' => 'Serviço inválido.',
            'service_id.exists' => 'O serviço selecionado não existe.',
        ];
    }
}
