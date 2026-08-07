<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação do envio da planta de um endereço (Plano 21, Task 21.4).
 *
 * `arquivo` aceita PNG, JPEG e PDF de uma página, até 10 MB. A regra `mimes`
 * do Laravel valida pelo tipo real do arquivo (Symfony detecta via fileinfo,
 * a partir do conteúdo), não pela extensão que o cliente informou - mesmo
 * cuidado de segurança já usado em `WorkOrderPhotoController`. O PDF de mais
 * de uma página e a conversão para imagem ficam por conta de
 * `FloorPlanService`, que também reconfere o tipo pelo conteúdo: é o Service
 * quem processa o arquivo, e ele precisa da mesma garantia mesmo quando
 * chamado fora de uma requisição HTTP (teste, tinker).
 *
 * `authorize()` confere `planta-gerenciar` diretamente, e não só por
 * middleware de rota: este FormRequest ainda não tem uma rota própria (o
 * controller de plantas é de uma task futura do Plano 21), e a regra de
 * negócio "sem `planta-gerenciar` não envia planta" precisa valer assim que
 * o endpoint existir, sem depender de alguém lembrar de proteger a rota.
 */
class StoreFloorPlanRequest extends FormRequest
{
    /**
     * Tamanho máximo do arquivo da planta, em kilobytes: 10 MB.
     */
    public const TAMANHO_MAXIMO_KB = 10240;

    public function authorize(): bool
    {
        return $this->user()?->can('planta-gerenciar') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'nome' => ['required', 'string', 'max:255'],
            'observacao' => ['nullable', 'string', 'max:1000'],
            'arquivo' => [
                'required',
                'file',
                'mimes:png,jpg,jpeg,pdf',
                'max:'.self::TAMANHO_MAXIMO_KB,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'nome.required' => 'Informe o nome da planta.',
            'nome.max' => 'O nome da planta pode ter no máximo 255 caracteres.',
            'observacao.max' => 'A observação pode ter no máximo 1000 caracteres.',
            'arquivo.required' => 'Envie o arquivo da planta.',
            'arquivo.file' => 'O arquivo da planta é inválido.',
            'arquivo.mimes' => 'Envie a planta em PNG, JPEG ou PDF de uma página.',
            'arquivo.max' => 'O arquivo da planta não pode passar de 10 MB.',
        ];
    }
}
