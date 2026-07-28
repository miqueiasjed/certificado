<?php

namespace App\Support;

use App\Models\Client;
use App\Models\Company;
use App\Models\Invitation;
use App\Models\Technician;
use App\Models\WorkOrder;
use App\Services\SubscriptionService;

/**
 * Fonte única da trilha de primeiros passos do tenant novo (Plano 8, Task 8.5).
 *
 * Cada item carrega a `condicao`: uma closure que recebe a empresa avaliada e
 * devolve se aquele passo já está satisfeito. É `OnboardingService::avaliar()`
 * quem chama essas closures, sempre dentro do tenant da empresa avaliada
 * (`TenantAtual::comTenant()`), nunca clique manual de "concluir" — trilha que
 * depende disso nunca fecha.
 *
 * `ProvisionamentoService` (Task 8.3) lê só as `chave` deste catálogo para
 * criar os oito `OnboardingStep` pendentes de um tenant novo; nenhum outro
 * lugar do código redeclara essa lista, no mesmo critério já usado por
 * `CatalogoDeModulos::catalogo()` para módulos.
 *
 * A ordem de declaração é a ordem de exibição da trilha.
 */
final class PassosDeOnboarding
{
    /**
     * @return array<int, array{chave: string, titulo: string, descricao: string, rota: string, condicao: \Closure(Company): bool}>
     */
    public static function catalogo(): array
    {
        return [
            [
                'chave' => 'dados_empresa',
                'titulo' => 'Complete os dados da empresa',
                'descricao' => 'CNPJ, endereço e telefone aparecem no cabeçalho dos documentos emitidos.',
                'rota' => 'settings.company.edit',
                'condicao' => static fn (Company $empresa): bool => self::preenchido($empresa->cnpj)
                    && self::preenchido($empresa->phone)
                    && self::preenchido($empresa->street)
                    && self::preenchido($empresa->city)
                    && self::preenchido($empresa->state)
                    && self::preenchido($empresa->zip),
            ],
            [
                'chave' => 'responsavel_tecnico',
                'titulo' => 'Informe o responsável técnico',
                'descricao' => 'Nome e título do responsável técnico, exigidos em certificado e ordem de serviço.',
                'rota' => 'settings.company.edit',
                'condicao' => static fn (Company $empresa): bool => self::preenchido($empresa->technical_responsible_name)
                    && self::preenchido($empresa->technical_responsible_title),
            ],
            [
                'chave' => 'logo_assinatura',
                'titulo' => 'Envie logo e assinatura',
                'descricao' => 'Logo da empresa e assinatura do responsável, usadas no PDF do certificado.',
                'rota' => 'settings.company.edit',
                'condicao' => static fn (Company $empresa): bool => self::preenchido($empresa->logo_path)
                    && self::preenchido($empresa->signature_responsible_path),
            ],
            [
                'chave' => 'primeiro_cliente',
                'titulo' => 'Cadastre o primeiro cliente',
                'descricao' => 'Todo endereço, ordem de serviço e certificado parte de um cliente cadastrado.',
                'rota' => 'clients.create',
                'condicao' => static fn (Company $empresa): bool => Client::query()->exists(),
            ],
            [
                'chave' => 'primeiro_tecnico',
                'titulo' => 'Cadastre um técnico',
                'descricao' => 'Quem executa e assina a ordem de serviço em campo.',
                'rota' => 'technicians.create',
                'condicao' => static fn (Company $empresa): bool => Technician::query()->exists(),
            ],
            [
                'chave' => 'primeira_os',
                'titulo' => 'Emita a primeira ordem de serviço',
                'descricao' => 'O fluxo completo: do agendamento ao certificado emitido.',
                'rota' => 'work-orders.create',
                'condicao' => static fn (Company $empresa): bool => WorkOrder::query()->exists(),
            ],
            [
                'chave' => 'equipe',
                'titulo' => 'Convide a equipe',
                'descricao' => 'Traga quem mais vai operar o sistema com você.',
                'rota' => 'settings.convites.index',
                'condicao' => static fn (Company $empresa): bool => Invitation::query()->exists(),
            ],
            [
                'chave' => 'assinatura',
                'titulo' => 'Escolha o plano',
                'descricao' => 'Sem plano ativo, o acesso é bloqueado ao fim do período de avaliação.',
                'rota' => 'assinatura.faturas.index',
                'condicao' => static fn (Company $empresa): bool => (bool) $empresa->is_internal
                    || in_array(
                        app(SubscriptionService::class)->assinaturaDe($empresa)?->situacao,
                        SubscriptionService::SITUACOES_EM_VIGOR,
                        true
                    ),
            ],
        ];
    }

    /**
     * Campo de texto tratado como preenchido: não nulo e não só espaço.
     */
    private static function preenchido(?string $valor): bool
    {
        return $valor !== null && trim($valor) !== '';
    }
}
