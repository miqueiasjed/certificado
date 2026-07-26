<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Consulta dos técnicos sem conflito para uma data e um horário.
 *
 * Leitura pura, sem model na rota: a autorização é a permissão da rota
 * (`ordem-servico-editar`, porque a lista existe para atribuir técnico) e o
 * isolamento por empresa vem do escopo global do model `Technician`.
 *
 * Formato do horário
 * ------------------
 * `inicio` e `fim` aceitam os dois formatos que o `DisponibilidadeService`
 * entende, e a diferença entre eles importa:
 *
 * - `HH:MM`: hora de parede no fuso do negócio, ancorada em `data`. É o que o
 *   calendário já tem em mãos (a agenda devolve `hora_inicio` e `hora_fim`
 *   assim) e o formato recomendado aqui, porque não passa por conversão
 *   nenhuma no caminho.
 * - `Y-m-d\TH:i`: instante no fuso da aplicação (UTC), a mesma convenção do
 *   reagendamento e do formulário de ordem de serviço.
 *
 * Sem horário, nenhum técnico é bloqueado: a lista volta inteira, com o aviso
 * das visitas que cada um já tem no dia.
 */
class TecnicosDisponiveisRequest extends FormRequest
{
    /**
     * Hora de parede no fuso do negócio.
     */
    public const FORMATO_DE_HORA = 'H:i';

    /**
     * Instante no fuso da aplicação, já convertido pelo frontend.
     */
    public const FORMATO_DO_INSTANTE = 'Y-m-d\TH:i';

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $formatos = 'date_format:'.self::FORMATO_DE_HORA.','.self::FORMATO_DO_INSTANTE;

        return [
            'data' => ['required', 'date_format:Y-m-d'],
            'inicio' => ['nullable', 'string', $formatos],
            'fim' => ['nullable', 'string', $formatos],
        ];
    }

    public function messages(): array
    {
        return [
            'data.required' => 'Informe a data no formato AAAA-MM-DD.',
            'data.date_format' => 'A data deve estar no formato AAAA-MM-DD.',
            'inicio.date_format' => 'O horário de início deve estar em HH:MM ou em AAAA-MM-DDTHH:MM.',
            'fim.date_format' => 'O horário de término deve estar em HH:MM ou em AAAA-MM-DDTHH:MM.',
            'fim.after' => 'O horário de término deve ser posterior ao de início.',
        ];
    }

    /**
     * @param  \Illuminate\Validation\Validator  $validator
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($validator): void {
            $inicio = $this->input('inicio');
            $fim = $this->input('fim');

            if (! is_string($inicio) || ! is_string($fim) || $inicio === '' || $fim === '') {
                return;
            }

            // Os dois formatos aceitos são ordenáveis como texto, então a
            // comparação direta resolve sem parse nem conversão de fuso. Com
            // formatos diferentes nas duas pontas a comparação não faria
            // sentido, e o próprio serviço já trata intervalo que não fecha
            // como "sem horário".
            if (strlen($inicio) !== strlen($fim)) {
                return;
            }

            if ($fim <= $inicio) {
                $validator->errors()->add('fim', 'O horário de término deve ser posterior ao de início.');
            }
        });
    }
}
