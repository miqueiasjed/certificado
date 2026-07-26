<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Budget;
use App\Models\Certificate;
use App\Models\Client;
use App\Models\Contract;
use App\Models\FinancialEntry;
use App\Models\PaymentDetail;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\BusinessDate;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Consulta o histórico de auditoria (tabela audit_logs) já formatado para
 * exibição.
 *
 * O apelido recebido na URL nunca vira nome de classe direto: só os apelidos
 * declarados em self::MODELS_AUDITADOS são aceitos, cada um apontando para uma
 * classe fixa, escrita em código. Isso evita que a requisição enumere ou
 * instancie qualquer model do sistema por FQCN.
 */
class AuditoriaService
{
    /**
     * Teto de registros por página do histórico.
     */
    private const MAXIMO_POR_PAGINA = 100;

    /**
     * Lista fechada de apelidos de URL para a classe do model auditado.
     *
     * @var array<string, class-string>
     */
    private const MODELS_AUDITADOS = [
        'cliente' => Client::class,
        'ordem-servico' => WorkOrder::class,
        'certificado' => Certificate::class,
        'contrato' => Contract::class,
        'orcamento' => Budget::class,
        'produto' => Product::class,
        'parcela' => PaymentDetail::class,
        'lancamento-financeiro' => FinancialEntry::class,
        'servico-agendado' => ServiceOrder::class,
        'usuario' => User::class,
    ];

    /**
     * Rótulo em português por nome de coluna, compartilhado entre todos os
     * models auditados. Campo sem entrada aqui cai no nome bruto da coluna:
     * histórico incompleto vale menos que histórico feio.
     *
     * @var array<string, string>
     */
    private const ROTULOS = [
        'client_id' => 'Cliente',
        'address_id' => 'Endereço',
        'technician_id' => 'Técnico',
        'service_id' => 'Serviço',
        'work_order_id' => 'Ordem de serviço',
        'payment_detail_id' => 'Parcela',
        'created_by' => 'Criado por',
        'order_number' => 'Número da ordem',
        'order_date' => 'Data da ordem',
        'contract_number' => 'Número do contrato',
        'certificate_number' => 'Número do certificado',
        'priority_level' => 'Prioridade',
        'scheduled_date' => 'Data agendada',
        'start_time' => 'Início',
        'end_time' => 'Término',
        'status' => 'Status',
        'description' => 'Descrição',
        'observations' => 'Observações',
        'special_instructions' => 'Instruções especiais',
        'materials_used' => 'Materiais utilizados',
        'total_cost' => 'Custo total',
        'discount_amount' => 'Desconto',
        'final_amount' => 'Valor final',
        'payment_due_date' => 'Vencimento',
        'payment_status' => 'Status do pagamento',
        'payment_date' => 'Data de pagamento',
        'payment_method' => 'Forma de pagamento',
        'payment_notes' => 'Observações de pagamento',
        'amount_paid' => 'Valor pago',
        'is_partial_payment' => 'Pagamento parcial',
        'completion_notes' => 'Notas de conclusão',
        'active' => 'Ativo',
        'is_active' => 'Ativo',
        'execution_date' => 'Data de execução',
        'warranty' => 'Garantia',
        'notes' => 'Observações',
        'procedure_used' => 'Procedimento utilizado',
        'start_date' => 'Início da vigência',
        'end_date' => 'Fim da vigência',
        'service_value' => 'Valor do serviço',
        'service_type' => 'Tipo de serviço',
        'visit_frequency' => 'Frequência de visitas',
        'visit_count' => 'Quantidade de visitas',
        'pest_target' => 'Praga alvo',
        'payment_details' => 'Detalhes de pagamento',
        'additional_clause' => 'Cláusula adicional',
        'jurisdiction' => 'Foro',
        'source' => 'Origem',
        'amount' => 'Valor',
        'entry_date' => 'Data de lançamento',
        'reference_number' => 'Número de referência',
        'target_pests' => 'Pragas alvo',
        'areas_to_treat' => 'Áreas a tratar',
        'date' => 'Data',
        'validity_date' => 'Validade',
        'discount' => 'Desconto',
        'name' => 'Nome',
        'email' => 'E-mail',
        'phone' => 'Telefone',
        'cnpj' => 'CNPJ',
        'cpf' => 'CPF',
        'address' => 'Endereço',
        'specialty' => 'Especialidade',
        'active_ingredient_id' => 'Princípio ativo',
        'chemical_group_id' => 'Grupo químico',
        'antidote_id' => 'Antídoto',
        'organ_registration_id' => 'Registro no órgão',
    ];

    /**
     * Verdadeiro quando o apelido informado está na lista fechada de models
     * auditados. O controller usa este método para decidir o 404 de tipo
     * desconhecido antes de chamar historicoDe().
     */
    public function tipoValido(string $tipo): bool
    {
        return array_key_exists($tipo, self::MODELS_AUDITADOS);
    }

    /**
     * Registros de audit_logs do model informado, mais recentes primeiro, com
     * o usuário responsável carregado.
     *
     * Chamar com um apelido fora de self::MODELS_AUDITADOS lança
     * InvalidArgumentException: o controller deve checar tipoValido() antes.
     *
     * O tamanho da página é limitado a MAXIMO_POR_PAGINA porque o valor chega
     * pela URL: sem teto, um por_pagina absurdo carregaria a tabela inteira em
     * memória e ainda formataria registro por registro.
     */
    public function historicoDe(string $tipo, int $id, int $porPagina = 20): LengthAwarePaginator
    {
        $classe = $this->resolverClasse($tipo);
        $porPagina = max(1, min($porPagina, self::MAXIMO_POR_PAGINA));

        return AuditLog::query()
            ->where('auditable_type', $classe)
            ->where('auditable_id', $id)
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($porPagina);
    }

    /**
     * Um registro de audit_logs pronto para exibição: metadados do evento mais
     * a lista de campos alterados.
     */
    public function formatarRegistro(AuditLog $log): array
    {
        return [
            'id' => $log->id,
            'acao' => $log->acao,
            'autor' => $log->autor_nome,
            'usuario' => $log->user === null ? null : [
                'id' => $log->user->id,
                'nome' => $log->user->name,
            ],
            'criado_em' => BusinessDate::paraFusoNegocio($log->created_at)?->format('d/m/Y H:i'),
            'alteracoes' => $this->descreverAlteracao($log),
        ];
    }

    /**
     * Converte valores_antes/valores_depois em uma lista de campos alterados,
     * com rótulo em português e valor já formatado para exibição (data pelo
     * utilitário do projeto, decimal com 2 casas, booleano como Sim/Não).
     *
     * @return array<int, array{campo: string, rotulo: string, antes: string, depois: string}>
     */
    public function descreverAlteracao(AuditLog $log): array
    {
        $casts = $this->castsDoModel($log->auditable_type);

        $antes = is_array($log->valores_antes) ? $log->valores_antes : [];
        $depois = is_array($log->valores_depois) ? $log->valores_depois : [];

        $campos = array_values(array_unique(array_merge(array_keys($antes), array_keys($depois))));

        return array_map(function (string $campo) use ($antes, $depois, $casts) {
            $cast = $casts[$campo] ?? null;

            return [
                'campo' => $campo,
                'rotulo' => self::ROTULOS[$campo] ?? $campo,
                'antes' => $this->formatarValor($antes[$campo] ?? null, $cast),
                'depois' => $this->formatarValor($depois[$campo] ?? null, $cast),
            ];
        }, $campos);
    }

    /**
     * Resolve o apelido de URL para a classe fixa correspondente. Nunca
     * recebe nem devolve um nome de classe vindo da requisição: o valor final
     * é sempre uma das constantes de classe declaradas em MODELS_AUDITADOS.
     */
    private function resolverClasse(string $tipo): string
    {
        if (! $this->tipoValido($tipo)) {
            throw new \InvalidArgumentException("Tipo de registro auditado desconhecido: {$tipo}.");
        }

        return self::MODELS_AUDITADOS[$tipo];
    }

    /**
     * Casts declarados no model auditado, para saber se um campo é data,
     * decimal ou booleano na hora de formatar. Só instancia classe que está na
     * lista fechada: auditable_type vem do banco, mas o piso de segurança vale
     * mesmo assim.
     *
     * @return array<string, string>
     */
    private function castsDoModel(string $classe): array
    {
        if (! in_array($classe, self::MODELS_AUDITADOS, true)) {
            return [];
        }

        return (new $classe)->getCasts();
    }

    /**
     * Formata um valor bruto de audit_logs de acordo com o cast do campo no
     * model original.
     */
    private function formatarValor(mixed $valor, ?string $cast): string
    {
        if ($valor === null || $valor === '') {
            return '-';
        }

        if (is_array($valor)) {
            return json_encode($valor, JSON_UNESCAPED_UNICODE) ?: '-';
        }

        $castTexto = (string) $cast;
        $ehInstante = $castTexto === 'datetime' || str_starts_with($castTexto, 'datetime:');
        $ehDia = $castTexto === 'date' || str_starts_with($castTexto, 'date:');

        if ($ehInstante || $ehDia) {
            $data = BusinessDate::paraFusoNegocio($valor);

            if ($data === null) {
                return '-';
            }

            return $ehDia ? $data->format('d/m/Y') : $data->format('d/m/Y H:i');
        }

        if (str_starts_with((string) $cast, 'decimal')) {
            return number_format((float) $valor, 2, ',', '.');
        }

        if (in_array($cast, ['boolean', 'bool'], true)) {
            return $this->paraBooleano($valor) ? 'Sim' : 'Não';
        }

        return (string) $valor;
    }

    /**
     * Interpreta o valor bruto (string vinda do banco, "1"/"0" de tinyint,
     * ou já booleano) como verdadeiro ou falso.
     */
    private function paraBooleano(mixed $valor): bool
    {
        if (is_bool($valor)) {
            return $valor;
        }

        if (is_numeric($valor)) {
            return (float) $valor !== 0.0;
        }

        return in_array(mb_strtolower((string) $valor), ['1', 'true', 'sim'], true);
    }
}
