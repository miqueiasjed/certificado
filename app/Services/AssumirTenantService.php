<?php

namespace App\Services;

use App\Models\AccessLog;
use App\Models\Company;
use App\Models\User;
use App\Support\TenantAtual;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;

/**
 * Entrada e saída do super admin em um tenant, para suporte.
 *
 * É a funcionalidade que permite alguém de fora da empresa abrir os dados de um
 * cliente real: cadastro, ordens de serviço, certificados e financeiro. Duas
 * regras sustentam isso, e nenhuma das duas é negociável.
 *
 * 1. Toda entrada e toda saída ficam em `access_logs`. A gravação vem antes da
 *    escrita na sessão, e a falha dela derruba a operação. Ver `assumir()`.
 * 2. Quem assume é só quem tem `is_platform_admin`. Este Service não checa isso:
 *    a checagem vive nas duas camadas que realmente decidem, o middleware
 *    `EnsurePlatformAdmin` (que guarda a rota) e `TenantAtual` (que só respeita
 *    a chave de sessão para super admin). Repetir a checagem aqui daria a
 *    impressão de uma terceira defesa que na prática seria a mesma.
 *
 * O que fica na sessão, e não em banco: o modo suporte morre junto com a sessão,
 * o que é o comportamento desejado. Fechar o navegador, expirar a sessão ou sair
 * do sistema libera o tenant sozinho, sem deixar ninguém "dentro" de um cliente
 * por esquecimento.
 */
class AssumirTenantService
{
    /**
     * Tetos de caracteres gravados em ip e user_agent, iguais aos do
     * `RegistraAcesso` e do `EnsurePlatformAdmin`.
     */
    private const LIMITE_IP = 45;

    private const LIMITE_USER_AGENT = 255;

    /**
     * Coloca o super admin dentro do tenant.
     *
     * Ordem deliberada: auditoria primeiro, sessão depois. Enquanto a linha de
     * `access_logs` não estiver comitada, ninguém entrou em lugar nenhum. Se o
     * insert falhar, a exceção sobe, o super admin vê um erro e a sessão continua
     * intacta. É o oposto do `RegistraAcesso`, que engole a falha porque lá a
     * auditoria é secundária ao login; aqui ela é a razão de a funcionalidade
     * poder existir, e "assumiu mas não registrou" é um estado que não pode
     * acontecer.
     *
     * A escrita na sessão fica fora da transação de propósito: sessão não faz
     * rollback. Dentro dela, uma falha no commit deixaria o super admin dentro do
     * tenant com o registro desfeito, que é exatamente o estado proibido. Fora
     * dela, o pior caso é um registro de entrada sem entrada, que é ruído inócuo
     * em auditoria.
     *
     * O `AccessLog` é gravado dentro de `comTenant()` da empresa assumida: o
     * registro pertence à empresa cujos dados foram acessados, e é lá que ele
     * precisa aparecer quando alguém for conferir quem entrou. Sem isso a linha
     * nasceria na empresa do próprio super admin, longe de quem ela interessa.
     */
    public function assumir(User $superAdmin, Company $empresa): void
    {
        $nome = (string) $empresa->name;
        $id = (int) $empresa->getKey();

        DB::transaction(function () use ($superAdmin, $id, $nome): void {
            $this->registrar($superAdmin, 'tenant_assumido', $id, [
                'company_id' => $id,
                'company_nome' => $nome,
            ]);
        });

        $sessao = $this->sessao();
        $sessao->put(TenantAtual::CHAVE_SESSAO_TENANT_ASSUMIDO, $id);

        // O nome é fotografia do instante da entrada, guardado só para a faixa
        // de aviso não precisar de uma consulta por requisição. Renomear a
        // empresa depois disso não muda a faixa, e não muda nada que importe: o
        // que decide isolamento é o id.
        $sessao->put(TenantAtual::CHAVE_SESSAO_TENANT_ASSUMIDO_NOME, $nome);
    }

    /**
     * Tira o super admin do tenant e o devolve à plataforma.
     *
     * Mesma ordem do `assumir()`: registra e só então limpa a sessão. A empresa
     * gravada em `detalhes` é lida antes da limpeza, senão o registro de saída
     * não diria de onde a pessoa saiu.
     *
     * Sem tenant assumido, não faz nada. O botão de sair do modo suporte é
     * clicável em qualquer tela, e clique repetido, ou requisição refeita pelo
     * navegador, não pode gerar registro de saída de lugar nenhum.
     */
    public function liberar(User $superAdmin): void
    {
        $assumido = $this->assumidoAtualmente();

        if ($assumido === null) {
            return;
        }

        DB::transaction(function () use ($superAdmin, $assumido): void {
            $this->registrar($superAdmin, 'tenant_liberado', $assumido['id'], [
                'company_id' => $assumido['id'],
                'company_nome' => $assumido['nome'],
            ]);
        });

        $this->sessao()->forget([
            TenantAtual::CHAVE_SESSAO_TENANT_ASSUMIDO,
            TenantAtual::CHAVE_SESSAO_TENANT_ASSUMIDO_NOME,
        ]);
    }

    /**
     * Tenant assumido nesta sessão, ou `null`.
     *
     * Leitura pura da sessão, sem checar quem é o usuário: quem faz esse
     * julgamento é quem chama. `TenantAtual` só respeita a chave para super
     * admin, e o `HandleInertiaRequests` repete a mesma condição antes de dizer
     * ao frontend que o modo suporte está ativo.
     *
     * @return array{id: int, nome: string}|null
     */
    public function assumidoAtualmente(): ?array
    {
        $id = $this->sessao()->get(TenantAtual::CHAVE_SESSAO_TENANT_ASSUMIDO);

        if (! is_numeric($id) || (int) $id < 1) {
            return null;
        }

        return [
            'id' => (int) $id,
            'nome' => (string) $this->sessao()->get(TenantAtual::CHAVE_SESSAO_TENANT_ASSUMIDO_NOME, ''),
        ];
    }

    /**
     * Grava a linha em `access_logs`, na empresa informada.
     *
     * Sem try/catch: qualquer falha sobe para quem chamou. Ver o docblock de
     * `assumir()`.
     *
     * @param  array<string, mixed>  $detalhes
     */
    private function registrar(User $superAdmin, string $evento, int $companyId, array $detalhes): void
    {
        TenantAtual::comTenant($companyId, function () use ($superAdmin, $evento, $detalhes): void {
            AccessLog::create([
                'user_id' => $superAdmin->getAuthIdentifier(),
                'email' => $superAdmin->email,
                'evento' => $evento,
                'ip' => substr((string) request()->ip(), 0, self::LIMITE_IP),
                'user_agent' => substr((string) request()->userAgent(), 0, self::LIMITE_USER_AGENT),
                'detalhes' => $detalhes,
                'created_at' => now(),
            ]);
        });
    }

    private function sessao(): Session
    {
        return app('session.store');
    }
}
