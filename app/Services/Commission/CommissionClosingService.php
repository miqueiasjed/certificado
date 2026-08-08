<?php

namespace App\Services\Commission;

use App\Models\AuditLog;
use App\Models\Commission;
use App\Models\Payable;
use App\Models\User;
use App\Services\PayableService;
use App\Support\BusinessDate;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fechamento, reabertura e pagamento de uma comissão apurada (Plano 23,
 * Task 23.2).
 *
 * `fechar()` torna a comissão imutável: a partir daí, `CommissionCalculationService::apurar()`
 * nunca mais toca nos itens dela. `reabrir()` desfaz isso, mas exige permissão
 * própria (`PERMISSAO_REABRIR`) e fica registrado em `audit_logs` com a
 * justificativa: mexer numa comissão fechada é o que faz o vendedor perder a
 * confiança no cálculo, e por isso precisa deixar rastro de quem e por quê.
 *
 * `marcarComoPaga()` marca `paga` e, só quando pedido, gera o título a pagar
 * do Plano 18 (`Payable`) vinculado por `commissions.payable_id`.
 *
 * Limitação conhecida: `payables.supplier_id` é obrigatório no schema atual
 * (Plano 18), e o Plano 18 não modela "pagar um vendedor/técnico" como
 * `Supplier` nem tem nenhuma outra tabela de beneficiário. Por isso, gerar o
 * título aqui exige que quem chama informe qual `Supplier` já cadastrado
 * representa aquela pessoa para fins de contas a pagar (uma prática comum
 * fora deste sistema: cadastrar o próprio vendedor/técnico como fornecedor
 * para poder pagá-lo pelo mesmo fluxo). Não é uma decisão de modelagem desta
 * classe, é o que o schema atual permite sem migração nova.
 */
class CommissionClosingService
{
    /**
     * Permissão exigida para reabrir uma comissão fechada ou paga. Segue o
     * mesmo padrão "recurso-acao" do catálogo (`SyncPermissions::catalogo()`),
     * mesmo critério de `pagamento-reabrir`. Sem prefixo/sufixo reconhecido
     * por nenhum papel em `RolesAndPermissionsSeeder`, então só o
     * administrador a recebe (que recebe o catálogo inteiro), mesmo critério
     * de `pagamento-reabrir`.
     */
    public const PERMISSAO_REABRIR = 'comissoes-reabrir';

    private const SITUACAO_ABERTA = 'aberta';

    private const SITUACAO_FECHADA = 'fechada';

    private const SITUACAO_PAGA = 'paga';

    /**
     * Tamanho mínimo da justificativa de reabertura, mesmo critério de
     * `SettlementService::MOTIVO_MINIMO`: "erro" não explica nada a quem for
     * auditar meses depois.
     */
    private const JUSTIFICATIVA_MINIMA = 10;

    public function __construct(private readonly PayableService $titulosAPagar) {}

    /**
     * Fecha a comissão: exige que a competência já tenha terminado (não dá
     * para fechar o mês corrente antes dele acabar) e que a comissão esteja
     * `aberta`. A partir daqui ela é imutável para `CommissionCalculationService`.
     *
     * @throws ValidationException Comissão que não está aberta, ou
     *                              competência ainda em andamento.
     */
    public function fechar(Commission $comissao, User $usuario): Commission
    {
        if ($comissao->situacao !== self::SITUACAO_ABERTA) {
            throw ValidationException::withMessages([
                'comissao' => 'Só é possível fechar uma comissão que está aberta.',
            ]);
        }

        if (! $this->competenciaJaTerminou($comissao->competencia)) {
            throw ValidationException::withMessages([
                'comissao' => 'A competência ainda não terminou: aguarde o fim do período para fechar esta comissão.',
            ]);
        }

        $comissao->update([
            'situacao' => self::SITUACAO_FECHADA,
            'fechada_em' => now(),
            'fechada_por' => $usuario->id,
        ]);

        return $comissao->fresh();
    }

    /**
     * Reabre uma comissão fechada ou paga, exigindo a permissão própria e uma
     * justificativa de verdade, registrada em `audit_logs` (a comissão em si
     * não usa a trait `Auditavel`, então o registro é gravado a mão aqui, no
     * mesmo formato que ela produziria).
     *
     * @throws AuthorizationException Usuário sem `PERMISSAO_REABRIR` (403).
     * @throws ValidationException Comissão já aberta, ou justificativa
     *                              ausente/curta demais.
     */
    public function reabrir(Commission $comissao, User $usuario, string $justificativa): Commission
    {
        $this->garantirPermissaoDeReabrir($usuario);
        $justificativa = $this->justificativaValida($justificativa);

        if ($comissao->situacao === self::SITUACAO_ABERTA) {
            throw ValidationException::withMessages([
                'comissao' => 'Esta comissão já está aberta.',
            ]);
        }

        $situacaoAnterior = $comissao->situacao;

        return DB::transaction(function () use ($comissao, $usuario, $justificativa, $situacaoAnterior) {
            $comissao->update([
                'situacao' => self::SITUACAO_ABERTA,
                'fechada_em' => null,
                'fechada_por' => null,
            ]);

            AuditLog::create([
                'auditable_type' => Commission::class,
                'auditable_id' => $comissao->id,
                'acao' => 'reaberta',
                'user_id' => $usuario->id,
                'autor_nome' => $this->nomeDoAutor($usuario),
                'valores_antes' => ['situacao' => $situacaoAnterior],
                'valores_depois' => ['situacao' => self::SITUACAO_ABERTA, 'justificativa' => $justificativa],
            ]);

            return $comissao->fresh();
        });
    }

    /**
     * Marca a comissão como paga. Só a partir de `fechada`: pagar algo que
     * ainda pode ser recalculado (`aberta`) é a mesma falha que fechar
     * protege.
     *
     * Formato esperado de `$dados`:
     * - `gerar_titulo` (opcional, padrão `false`): quando `true`, gera o
     *   título a pagar do Plano 18 vinculado.
     * - `supplier_id` (obrigatório se `gerar_titulo`): o fornecedor já
     *   cadastrado que representa esta pessoa para fins de contas a pagar
     *   (ver a limitação no cabeçalho da classe).
     * - `vencimento` (obrigatório se `gerar_titulo`): vencimento do título
     *   (`Y-m-d`).
     * - `descricao`, `chart_of_account_id` (opcionais).
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws ValidationException Comissão que não está fechada, ou dado
     *                              obrigatório do título ausente.
     */
    public function marcarComoPaga(Commission $comissao, array $dados): Commission
    {
        if ($comissao->situacao !== self::SITUACAO_FECHADA) {
            throw ValidationException::withMessages([
                'comissao' => 'Só é possível marcar como paga uma comissão fechada.',
            ]);
        }

        return DB::transaction(function () use ($comissao, $dados) {
            $payableId = null;

            if (($dados['gerar_titulo'] ?? false) === true) {
                $payableId = $this->gerarTituloAPagar($comissao, $dados)->id;
            }

            $comissao->update([
                'situacao' => self::SITUACAO_PAGA,
                'paga_em' => now(),
                'payable_id' => $payableId,
            ]);

            return $comissao->fresh();
        });
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * A competência (`YYYY-MM`) já terminou? Verdadeiro a partir do primeiro
     * dia do mês seguinte, no fuso do negócio.
     */
    private function competenciaJaTerminou(string $competencia): bool
    {
        [$ano, $mes] = $this->partesDaCompetencia($competencia);

        $primeiroDiaSeguinte = CarbonImmutable::create($ano, $mes, 1, 0, 0, 0, BusinessDate::fuso())
            ->addMonthNoOverflow();

        return ! BusinessDate::hoje()->lessThan($primeiroDiaSeguinte);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function partesDaCompetencia(string $competencia): array
    {
        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $competencia, $partes) !== 1) {
            throw ValidationException::withMessages([
                'comissao' => "Competência inválida: \"{$competencia}\".",
            ]);
        }

        return [(int) $partes[1], (int) $partes[2]];
    }

    private function garantirPermissaoDeReabrir(User $usuario): void
    {
        if (! $usuario->can(self::PERMISSAO_REABRIR)) {
            throw new AuthorizationException(
                'Você não tem permissão para reabrir uma comissão já fechada ou paga.'
            );
        }
    }

    private function justificativaValida(string $justificativa): string
    {
        $justificativa = trim($justificativa);

        if (mb_strlen($justificativa) < self::JUSTIFICATIVA_MINIMA) {
            throw ValidationException::withMessages([
                'justificativa' => sprintf(
                    'A justificativa da reabertura precisa ter pelo menos %d caracteres, para explicar de '
                    .'verdade por que a comissão fechada foi reaberta.',
                    self::JUSTIFICATIVA_MINIMA
                ),
            ]);
        }

        return $justificativa;
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function gerarTituloAPagar(Commission $comissao, array $dados): Payable
    {
        $supplierId = $dados['supplier_id'] ?? null;

        if ($supplierId === null || $supplierId === '') {
            throw ValidationException::withMessages([
                'supplier_id' => 'Informe o fornecedor cadastrado que representa esta pessoa, para gerar o título a pagar.',
            ]);
        }

        $vencimento = $dados['vencimento'] ?? null;

        if (! is_string($vencimento) || $vencimento === '') {
            throw ValidationException::withMessages([
                'vencimento' => 'Informe o vencimento do título a pagar.',
            ]);
        }

        $pessoa = $comissao->user_id !== null ? "vendedor #{$comissao->user_id}" : "técnico #{$comissao->technician_id}";

        return $this->titulosAPagar->criar([
            'supplier_id' => $supplierId,
            'descricao' => $dados['descricao'] ?? "Comissão do {$pessoa} - competência {$comissao->competencia}",
            'valor' => $comissao->valor_total,
            'vencimento' => $vencimento,
            'chart_of_account_id' => $dados['chart_of_account_id'] ?? null,
            'emitido_em' => BusinessDate::hoje()->toDateString(),
            'recorrencia' => PayableService::RECORRENCIA_NENHUMA,
        ]);
    }

    private function nomeDoAutor(User $usuario): string
    {
        $nome = trim((string) ($usuario->name ?? ''));

        return $nome !== '' ? $nome : "Usuário #{$usuario->id}";
    }
}
