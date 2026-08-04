<?php

namespace App\Services\Payments;

use App\Exceptions\GatewayNaoConfiguradoException;
use App\Models\Company;
use App\Models\PaymentGatewayConfig;
use RuntimeException;

/**
 * Resolve o gateway de cobrança configurado por um tenant, a partir da
 * `PaymentGatewayConfig` ativa da empresa (Plano 19, Task 19.2).
 *
 * Ponto único por onde o domínio chega em uma implementação de
 * `GatewayDeCobranca`: `ChargeService` (Task 19.3) e a rotina de cobrança
 * recorrente (Task 19.5) não instanciam `GatewayPagBank` diretamente, para
 * que acrescentar um segundo provedor não exija tocar em quem cobra.
 *
 * ## Por que a consulta ignora o escopo global de empresa
 *
 * `PaymentGatewayConfig` usa `BelongsToCompany`, cujo escopo global filtra
 * pelo tenant **corrente** (`TenantAtual::id()`), resolvido da sessão
 * autenticada. Este resolvedor recebe a empresa **explicitamente** por
 * parâmetro, porque também precisa funcionar fora de uma requisição HTTP:
 * a rotina de cobrança recorrente (Task 19.5) percorre todas as empresas em
 * lote, uma de cada vez, sem que exista sessão nem "tenant corrente" no
 * meio da fila.
 *
 * Se a consulta aqui dependesse do escopo global, duas situações quebrariam:
 * chamado de dentro de um contexto sem tenant resolvido, o escopo não
 * filtraria nada e a consulta enxergaria configuração de qualquer empresa;
 * chamado para a empresa B enquanto o tenant corrente da sessão é a empresa
 * A, o escopo global somaria `company_id = A` à condição explícita
 * `company_id = B` desta consulta, e a combinação nunca bateria — a rotina
 * relataria "sem gateway configurado" para uma empresa que tem, sim, um
 * configurado. Por isso a consulta usa `deTodasAsEmpresas()` e filtra pelo
 * `$empresa` recebido, e só por ele.
 */
class ResolvedorDeGateway
{
    /**
     * Nome do gateway (`payment_gateway_configs.gateway`) para a classe que
     * implementa `GatewayDeCobranca` para ele. Gateway novo entra aqui.
     *
     * @var array<string, class-string<GatewayDeCobranca>>
     */
    private const IMPLEMENTACOES = [
        GatewayPagBank::NOME => GatewayPagBank::class,
    ];

    /**
     * Implementação de `GatewayDeCobranca` para o gateway ativo da empresa.
     *
     * @throws GatewayNaoConfiguradoException Empresa sem `PaymentGatewayConfig` ativa.
     * @throws RuntimeException Gateway configurado sem implementação registrada.
     */
    public function para(Company $empresa): GatewayDeCobranca
    {
        return $this->implementacao($this->configuracaoAtiva($empresa)->gateway);
    }

    /**
     * Implementação de `GatewayDeCobranca` para uma configuração já
     * resolvida, sem passar de novo por `configuracaoAtiva()`.
     *
     * Usado por `CobrancaWebhookController` (Task 19.4): o webhook chega sem
     * `Company` nenhuma em mãos, só o `webhookToken` da URL, e
     * `PaymentGatewayConfig::paraToken()` já devolve a configuração
     * encontrada — inclusive quando `ativo` é falso (ver o porquê no
     * cabeçalho de `paraToken()`), o que `configuracaoAtiva()` recusaria.
     *
     * @throws RuntimeException Gateway configurado sem implementação registrada.
     */
    public function paraConfiguracao(PaymentGatewayConfig $configuracao): GatewayDeCobranca
    {
        return $this->implementacao($configuracao->gateway);
    }

    /**
     * Confirma a credencial da configuração junto ao provedor e, quando
     * válida, grava `verificado_em`. É assim que um tenant descobre uma
     * credencial errada na tela de configuração, e não na primeira cobrança
     * real — problema na frente do cliente final dele.
     *
     * Devolve o mesmo booleano de `GatewayDeCobranca::validarCredenciais()`,
     * para quem chama poder mostrar sucesso ou falha sem precisar recarregar
     * a configuração.
     */
    public function validar(PaymentGatewayConfig $configuracao): bool
    {
        $valida = $this->implementacao($configuracao->gateway)->validarCredenciais($configuracao);

        if ($valida) {
            $configuracao->forceFill(['verificado_em' => now()])->save();
        }

        return $valida;
    }

    /**
     * Ver o cabeçalho da classe sobre `deTodasAsEmpresas()`.
     *
     * Pública porque `ChargeService` (Task 19.3) precisa da configuração em
     * si, e não só da implementação: todo método de `GatewayDeCobranca`
     * recebe a `PaymentGatewayConfig` como primeiro parâmetro (ver o
     * cabeçalho de `GatewayDeCobranca`), então quem chama emite, cancela e
     * reemite passando a mesma configuração que `para()` usou para escolher a
     * implementação.
     *
     * @throws GatewayNaoConfiguradoException
     */
    public function configuracaoAtiva(Company $empresa): PaymentGatewayConfig
    {
        $configuracao = PaymentGatewayConfig::deTodasAsEmpresas()
            ->where('company_id', $empresa->getKey())
            ->where('ativo', true)
            ->first();

        if (! $configuracao instanceof PaymentGatewayConfig) {
            throw GatewayNaoConfiguradoException::paraEmpresa($empresa);
        }

        return $configuracao;
    }

    /**
     * @throws RuntimeException Gateway sem implementação registrada.
     */
    private function implementacao(string $gateway): GatewayDeCobranca
    {
        $classe = self::IMPLEMENTACOES[$gateway] ?? null;

        if ($classe === null) {
            throw new RuntimeException(sprintf(
                'Gateway "%s" não tem implementação de GatewayDeCobranca registrada em ResolvedorDeGateway::IMPLEMENTACOES.',
                $gateway
            ));
        }

        return app($classe);
    }
}
