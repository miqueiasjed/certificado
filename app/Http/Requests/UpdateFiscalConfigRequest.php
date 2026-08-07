<?php

namespace App\Http\Requests;

use App\Models\FiscalConfig;
use App\Services\Fiscal\ProvedorPadrao;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateFiscalConfigRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provedor' => ['sometimes', 'string', Rule::in([ProvedorPadrao::NOME])],
            'ambiente' => ['sometimes', 'string', Rule::in(FiscalConfig::AMBIENTES)],
            'client_id' => ['nullable', 'string', 'max:500'],
            'client_secret' => ['nullable', 'string', 'max:1000'],
            'regime_tributario' => ['sometimes', 'string', 'max:100'],
            'codigo_servico' => ['sometimes', 'string', 'max:100'],
            'cnae' => ['nullable', 'string', 'max:20'],
            'aliquota_iss' => ['sometimes', 'numeric', 'min:0', 'max:100'],
            'iss_retido' => ['sometimes', 'boolean'],
            'natureza_operacao' => ['sometimes', 'string', 'max:100'],
            'serie' => ['nullable', 'string', 'max:30'],
            'proximo_numero' => ['nullable', 'integer', 'min:1'],
            'emissao_automatica' => ['sometimes', 'boolean'],
            'gatilho_emissao_automatica' => ['sometimes', Rule::in(['conclusao_os', 'quitacao_titulo'])],
            'exige_inscricao_municipal_tomador' => ['sometimes', 'boolean'],
        ];
    }
}
