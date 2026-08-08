<?php

namespace App\Http\Requests;

use App\Services\Compliance\ValidadeService;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validação das validades regulatórias da empresa (Plano 24, Task 24.6).
 *
 * `authorize()` devolve `true` porque a rota inteira já exige
 * `permission:conformidade-gerenciar` e `module:conformidade`, e porque não há
 * identificador de dono no corpo: quem é atualizado é sempre
 * `Company::current()`, resolvido pelo usuário autenticado.
 *
 * Toda validade é **nullable**, de propósito: limpar a data é uma alteração
 * legítima (o documento foi cancelado, o número estava errado, o cadastro foi
 * preenchido por engano), e campo em branco significa "não informado", nunca
 * "vencido".
 *
 * Nenhuma data é obrigada a ser futura. A empresa precisa conseguir cadastrar
 * a licença que já venceu — é justamente esse o caso em que o checklist tem
 * algo útil a dizer. Recusar data passada esconderia o problema em vez de
 * mostrá-lo.
 */
class ValidadesRegulatoriasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $regras = [
            // Números dos documentos, com os mesmos limites já usados em
            // `CompanyController::update()`, para as duas telas não aceitarem
            // coisas diferentes no mesmo campo.
            'register_crea' => ['nullable', 'string', 'max:50'],
            'crq' => ['nullable', 'string', 'max:50'],
            'license_sanitary' => ['nullable', 'string', 'max:50'],
            'license_environmental' => ['nullable', 'string', 'max:50'],
            'license_business' => ['nullable', 'string', 'max:50'],
        ];

        foreach (ValidadeService::DOCUMENTOS_DA_EMPRESA as $definicao) {
            // `date_format:Y-m-d`, e não `date`: o campo representa um dia,
            // sem hora, e é exatamente o formato que `<input type="date">`
            // envia. Aceitar formato livre abriria caminho para o navegador
            // mandar um instante e o dia deslizar na conversão.
            $regras[$definicao['validade']] = ['nullable', 'date_format:Y-m-d'];
        }

        foreach ($this->camposDeArquivo() as $campo) {
            // PDF ou imagem, até 4 MB: o documento digitalizado costuma vir
            // como PDF do órgão ou como foto do papel tirada no celular.
            $regras[$campo] = ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:4096'];
        }

        return $regras;
    }

    /**
     * Colunas de anexo, uma por documento.
     *
     * @return array<int, string>
     */
    public function camposDeArquivo(): array
    {
        return array_map(
            static fn (string $item): string => $item.'_arquivo',
            array_keys(ValidadeService::DOCUMENTOS_DA_EMPRESA)
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        $mensagens = [];

        foreach (ValidadeService::DOCUMENTOS_DA_EMPRESA as $definicao) {
            $mensagens[$definicao['validade'].'.date_format'] =
                "Informe a validade de \"{$definicao['rotulo']}\" no formato AAAA-MM-DD.";
        }

        foreach ($this->camposDeArquivo() as $campo) {
            $mensagens[$campo.'.mimes'] = 'O anexo precisa ser um PDF ou uma imagem (JPG ou PNG).';
            $mensagens[$campo.'.max'] = 'O anexo precisa ter no máximo 4 MB.';
        }

        return $mensagens;
    }
}
