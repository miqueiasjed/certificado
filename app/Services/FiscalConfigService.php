<?php

namespace App\Services;

use App\Models\FiscalConfig;
use App\Services\Fiscal\ResolvedorDeProvedor;
use App\Support\TenantAtual;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FiscalConfigService
{
    private const CAMPOS_FISCAIS = [
        'provedor',
        'ambiente',
        'regime_tributario',
        'codigo_servico',
        'cnae',
        'aliquota_iss',
        'iss_retido',
        'natureza_operacao',
        'serie',
        'proximo_numero',
        'exige_inscricao_municipal_tomador',
    ];

    private const CAMPOS_OPERACIONAIS = [
        'emissao_automatica',
        'gatilho_emissao_automatica',
    ];

    public function __construct(
        private readonly ResolvedorDeProvedor $provedores,
    ) {}

    public function atual(): ?FiscalConfig
    {
        return FiscalConfig::query()->where('ativo', true)->first();
    }

    /** @param array<string, mixed> $dados */
    public function salvar(array $dados): FiscalConfig
    {
        $companyId = TenantAtual::exigirId();
        $atual = $this->atual();
        $atributos = $this->comporAtributos($dados, $atual);

        if ($atual instanceof FiscalConfig && ! $this->mudouCampoFiscal($atual, $atributos)) {
            $this->validar($this->configuracaoParaValidacao($atual, $atributos));

            return DB::transaction(function () use ($atual, $dados, $atributos): FiscalConfig {
                $ativaTravada = FiscalConfig::query()
                    ->where('ativo', true)
                    ->lockForUpdate()
                    ->first();

                if (($ativaTravada?->id) !== $atual->id) {
                    throw ValidationException::withMessages([
                        'configuracao' => 'A configuração fiscal ativa mudou durante a validação. Atualize a página e tente novamente.',
                    ]);
                }

                $camposInformados = array_values(array_filter(
                    self::CAMPOS_OPERACIONAIS,
                    fn (string $campo): bool => array_key_exists($campo, $dados),
                ));

                if ($camposInformados !== []) {
                    $ativaTravada->update(Arr::only($atributos, $camposInformados));
                }

                return $ativaTravada->refresh();
            });
        }

        $nova = new FiscalConfig($atributos + ['ativo' => false]);
        $nova->forceFill(['company_id' => $companyId]);
        $this->validar($nova);

        return DB::transaction(function () use ($nova, $atual, $dados): FiscalConfig {
            $ativaTravada = FiscalConfig::query()
                ->where('ativo', true)
                ->lockForUpdate()
                ->first();

            if (($ativaTravada?->id) !== ($atual?->id)) {
                throw ValidationException::withMessages([
                    'configuracao' => 'A configuração fiscal ativa mudou durante a validação. Atualize a página e tente novamente.',
                ]);
            }

            if ($ativaTravada instanceof FiscalConfig) {
                $operacionais = [];

                foreach (self::CAMPOS_OPERACIONAIS as $campo) {
                    $operacionais[$campo] = array_key_exists($campo, $dados)
                        ? $nova->{$campo}
                        : $ativaTravada->{$campo};
                }

                $nova->forceFill($operacionais);
            }

            $nova->save();
            $ativaTravada?->update(['ativo' => false]);
            $nova->update(['ativo' => true]);

            return $nova->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function comporAtributos(array $dados, ?FiscalConfig $atual): array
    {
        $base = $atual === null
            ? []
            : collect([...self::CAMPOS_FISCAIS, ...self::CAMPOS_OPERACIONAIS])
                ->mapWithKeys(fn (string $campo): array => [$campo => $atual->{$campo}])
                ->all();
        $atributos = array_replace($base, Arr::only($dados, [
            ...self::CAMPOS_FISCAIS,
            ...self::CAMPOS_OPERACIONAIS,
        ]));

        $credencialInformada = filled($dados['client_id'] ?? null) || filled($dados['client_secret'] ?? null);
        $mesmoContrato = $atual instanceof FiscalConfig
            && ($atributos['provedor'] ?? null) === $atual->provedor
            && ($atributos['ambiente'] ?? null) === $atual->ambiente;

        if ($credencialInformada) {
            if (! filled($dados['client_id'] ?? null) || ! filled($dados['client_secret'] ?? null)) {
                throw ValidationException::withMessages([
                    'credenciais' => 'Informe o identificador e o segredo da credencial fiscal.',
                ]);
            }

            $atributos['credenciais'] = [
                'client_id' => trim((string) $dados['client_id']),
                'client_secret' => trim((string) $dados['client_secret']),
            ];
        } elseif ($mesmoContrato) {
            $atributos['credenciais'] = $atual->credenciais;
        } else {
            throw ValidationException::withMessages([
                'credenciais' => 'Informe uma nova credencial ao trocar o provedor ou o ambiente fiscal.',
            ]);
        }

        $obrigatorios = ['provedor', 'ambiente', 'regime_tributario', 'codigo_servico', 'aliquota_iss', 'natureza_operacao'];

        foreach ($obrigatorios as $campo) {
            if (! filled($atributos[$campo] ?? null)) {
                throw ValidationException::withMessages([$campo => "Preencha o campo {$campo}."]);
            }
        }

        $atributos['emissao_automatica'] = (bool) ($atributos['emissao_automatica'] ?? false);
        $atributos['gatilho_emissao_automatica'] = $atributos['gatilho_emissao_automatica'] ?? 'conclusao_os';
        $atributos['exige_inscricao_municipal_tomador'] = (bool) ($atributos['exige_inscricao_municipal_tomador'] ?? false);
        $atributos['iss_retido'] = (bool) ($atributos['iss_retido'] ?? false);

        return $atributos;
    }

    /** @param array<string, mixed> $atributos */
    private function mudouCampoFiscal(FiscalConfig $atual, array $atributos): bool
    {
        foreach (self::CAMPOS_FISCAIS as $campo) {
            if ((string) $atual->{$campo} !== (string) ($atributos[$campo] ?? null)) {
                return true;
            }
        }

        return $atual->credenciais !== $atributos['credenciais'];
    }

    /** @param array<string, mixed> $atributos */
    private function configuracaoParaValidacao(FiscalConfig $atual, array $atributos): FiscalConfig
    {
        $configuracao = $atual->replicate();
        $configuracao->forceFill($atributos);

        return $configuracao;
    }

    private function validar(FiscalConfig $configuracao): void
    {
        if (! $this->provedores->validar($configuracao)) {
            throw ValidationException::withMessages([
                'credenciais' => 'O provedor fiscal recusou as credenciais informadas.',
            ]);
        }
    }
}
