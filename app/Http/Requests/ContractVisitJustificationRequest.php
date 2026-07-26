<?php

namespace App\Http\Requests;

use App\Services\JustificativaDeVisitaService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do registro de justificativa de visita não realizada.
 *
 * O motivo é obrigatório e é o ponto da funcionalidade inteira: ele é o
 * documento que responde à fiscalização por que uma data prevista de visita
 * não virou Ordem de Serviço. Justificativa vazia tira a pendência do painel e
 * não deixa nada no lugar, que é o pior dos dois mundos.
 *
 * O tamanho mínimo existe para barrar o "ok" e o ".": não garante qualidade,
 * mas custa nada e impede o caso mais óbvio de burla do campo obrigatório. O
 * máximo acompanha a coluna `reason` (500).
 *
 * As datas chegam como lista de 'Y-m-d' e são conferidas de novo em
 * `JustificativaDeVisitaService::justificar`, contra o calendário real do
 * contrato: aqui só se valida o formato, porque saber se uma data é pendência
 * daquele contrato é regra de negócio, não validação de input.
 *
 * `authorize()` devolve true pelo mesmo motivo de `ContractEncerrarRequest`: a
 * rota é inteiramente protegida por permissão do Spatie (`contrato-editar`,
 * ver routes/web.php) e não é rota de autoatendimento nem do portal do
 * cliente.
 */
class ContractVisitJustificationRequest extends FormRequest
{
    /**
     * Tamanho mínimo do motivo, em caracteres.
     */
    private const MINIMO_DO_MOTIVO = 5;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'datas' => 'required|array|min:1|max:'.JustificativaDeVisitaService::MAXIMO_DE_DATAS_POR_CHAMADA,
            'datas.*' => 'required|date_format:Y-m-d',
            'motivo' => 'required|string|min:'.self::MINIMO_DO_MOTIVO.'|max:500',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'datas.required' => 'Selecione pelo menos uma data prevista para justificar.',
            'datas.array' => 'As datas precisam vir em uma lista.',
            'datas.min' => 'Selecione pelo menos uma data prevista para justificar.',
            'datas.max' => 'São no máximo '.JustificativaDeVisitaService::MAXIMO_DE_DATAS_POR_CHAMADA.' datas por vez.',
            'datas.*.required' => 'Data inválida na lista.',
            'datas.*.date_format' => 'Data inválida na lista: use o formato AAAA-MM-DD.',
            'motivo.required' => 'O motivo é obrigatório: é ele que responde à fiscalização por que a visita não aconteceu.',
            'motivo.string' => 'O motivo deve ser um texto.',
            'motivo.min' => 'Escreva o motivo com pelo menos '.self::MINIMO_DO_MOTIVO.' caracteres.',
            'motivo.max' => 'O motivo pode ter no máximo 500 caracteres.',
        ];
    }
}
