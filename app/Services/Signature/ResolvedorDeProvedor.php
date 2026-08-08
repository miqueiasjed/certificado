<?php

namespace App\Services\Signature;

use App\Exceptions\ProvedorDeAssinaturaNaoConfiguradoException;
use App\Models\Company;
use App\Models\SignatureProviderConfig;
use RuntimeException;

/**
 * Resolve o provedor de assinatura eletrônica configurado por um tenant, a
 * partir da `SignatureProviderConfig` ativa da empresa (Plano 26, Task 26.2).
 *
 * Ponto único por onde o domínio chega em uma implementação de
 * `ProvedorDeAssinatura`: `SignatureRequestService` (Task 26.3) e a rotina de
 * sincronização não instanciam `ProvedorPadrao` diretamente, para que
 * acrescentar um segundo provedor não exija tocar em quem envia contrato.
 *
 * Gêmeo de `App\Services\Payments\ResolvedorDeGateway` (Plano 19, Task 19.2),
 * inclusive nas decisões abaixo.
 *
 * ## Por que a consulta ignora o escopo global de empresa
 *
 * `SignatureProviderConfig` usa `BelongsToCompany`, cujo escopo global filtra
 * pelo tenant **corrente** (`TenantAtual::id()`), resolvido da sessão
 * autenticada. Este resolvedor recebe a empresa **explicitamente** por
 * parâmetro, porque também precisa funcionar fora de uma requisição HTTP: a
 * sincronização periódica (Task 26.3) percorre as empresas com pedido em
 * aberto, uma de cada vez, sem que exista sessão nem "tenant corrente" no meio
 * da fila.
 *
 * Se a consulta dependesse do escopo global, duas situações quebrariam:
 * chamada de dentro de um contexto sem tenant resolvido, o escopo não
 * filtraria nada e a consulta enxergaria configuração de qualquer empresa;
 * chamada para a empresa B enquanto o tenant corrente da sessão é a empresa A,
 * o escopo somaria `company_id = A` à condição explícita `company_id = B`, e a
 * combinação nunca bateria — a rotina relataria "sem provedor configurado"
 * para uma empresa que tem, sim, um configurado. Por isso a consulta usa
 * `deTodasAsEmpresas()` e filtra pelo `$empresa` recebido, e só por ele.
 */
class ResolvedorDeProvedor
{
    /**
     * Nome do provedor (`signature_provider_configs.provedor`) para a classe
     * que implementa `ProvedorDeAssinatura` para ele. Provedor novo entra
     * aqui, e é tudo o que precisa mudar fora da classe nova.
     *
     * @var array<string, class-string<ProvedorDeAssinatura>>
     */
    public const IMPLEMENTACOES = [
        ProvedorPadrao::NOME => ProvedorPadrao::class,
    ];

    /**
     * Nomes de provedor aceitos, para validação de formulário (Task 26.4).
     *
     * @return array<int, string>
     */
    public static function provedoresConhecidos(): array
    {
        return array_keys(self::IMPLEMENTACOES);
    }

    /**
     * Implementação de `ProvedorDeAssinatura` para o provedor ativo da
     * empresa.
     *
     * @throws ProvedorDeAssinaturaNaoConfiguradoException Empresa sem `SignatureProviderConfig` ativa.
     * @throws RuntimeException Provedor configurado sem implementação registrada.
     */
    public function para(Company $empresa): ProvedorDeAssinatura
    {
        return $this->implementacao($this->configuracaoAtiva($empresa)->provedor);
    }

    /**
     * Implementação para uma configuração já resolvida, sem passar de novo por
     * `configuracaoAtiva()`.
     *
     * Usado pelo webhook (Task 26.3): ele chega sem `Company` nenhuma em mãos,
     * só o `webhookToken` da URL, e `SignatureProviderConfig::paraToken()` já
     * devolve a configuração encontrada — inclusive quando `ativo` é falso
     * (ver o porquê no cabeçalho de `paraToken()`), o que `configuracaoAtiva()`
     * recusaria.
     *
     * @throws RuntimeException Provedor configurado sem implementação registrada.
     */
    public function paraConfiguracao(SignatureProviderConfig $configuracao): ProvedorDeAssinatura
    {
        return $this->implementacao($configuracao->provedor);
    }

    /**
     * Confirma a credencial junto ao provedor e, quando válida, grava
     * `verificado_em`. É assim que um tenant descobre uma credencial errada na
     * tela de configuração, e não no primeiro contrato real — problema na
     * frente do cliente dele.
     *
     * Devolve o mesmo booleano de
     * `ProvedorDeAssinatura::validarCredenciais()`, para quem chama poder
     * mostrar sucesso ou falha sem recarregar a configuração.
     */
    public function validar(SignatureProviderConfig $configuracao): bool
    {
        $valida = $this->implementacao($configuracao->provedor)->validarCredenciais($configuracao);

        if ($valida) {
            $configuracao->forceFill(['verificado_em' => now()])->save();
        }

        return $valida;
    }

    /**
     * Ver o cabeçalho da classe sobre `deTodasAsEmpresas()`.
     *
     * Pública porque `SignatureRequestService` (Task 26.3) precisa da
     * configuração em si, e não só da implementação: todo método de
     * `ProvedorDeAssinatura` recebe a `SignatureProviderConfig` como primeiro
     * parâmetro, então quem envia, cancela e sincroniza passa a mesma
     * configuração que `para()` usou para escolher a implementação.
     *
     * @throws ProvedorDeAssinaturaNaoConfiguradoException
     */
    public function configuracaoAtiva(Company $empresa): SignatureProviderConfig
    {
        $configuracao = SignatureProviderConfig::deTodasAsEmpresas()
            ->where('company_id', $empresa->getKey())
            ->where('ativo', true)
            ->first();

        if (! $configuracao instanceof SignatureProviderConfig) {
            throw ProvedorDeAssinaturaNaoConfiguradoException::paraEmpresa($empresa);
        }

        return $configuracao;
    }

    /**
     * @throws RuntimeException Provedor sem implementação registrada.
     */
    private function implementacao(string $provedor): ProvedorDeAssinatura
    {
        $classe = self::IMPLEMENTACOES[$provedor] ?? null;

        if ($classe === null) {
            throw new RuntimeException(sprintf(
                'Provedor "%s" não tem implementação de ProvedorDeAssinatura registrada em ResolvedorDeProvedor::IMPLEMENTACOES.',
                $provedor
            ));
        }

        return app($classe);
    }
}
