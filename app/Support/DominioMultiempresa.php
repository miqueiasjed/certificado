<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

/**
 * Fonte única da classificação multiempresa do schema.
 *
 * Declara quais tabelas e models são de domínio (recebem `company_id` e o
 * escopo global por empresa) e quais ficam de fora, com o motivo de cada
 * exclusão. As migrations do Plano 4, a trait `BelongsToCompany` e os testes
 * de regressão leem desta mesma lista, para que nunca divirjam entre si.
 *
 * Critério de classificação aplicado:
 *
 * - Tabela raiz de domínio recebe a coluna.
 * - Tabela filha recebe a coluna quando o model dela é consultado direto ou
 *   chega por route-model binding, porque aí o escopo do pai não é atravessado
 *   e o escopo global é a única defesa.
 * - Tabela pivot e tabela filha que só é alcançada pelo relacionamento do pai
 *   não recebem a coluna: o escopo chega pelo pai, e duplicar `company_id`
 *   abriria caminho para divergência entre pai e filho.
 * - Tabela de framework, de fila, de cache e de sessão não é domínio.
 *
 * Utilitário de infraestrutura: sem regra de domínio e sem escrita em banco.
 */
final class DominioMultiempresa
{
    /**
     * Tabelas que recebem `company_id`, índice, chave estrangeira e escopo
     * global por empresa.
     *
     * @var array<int, string>
     */
    public const TABELAS_DE_DOMINIO = [
        'access_logs',
        'active_ingredients',
        'addresses',
        'antidotes',
        'audit_logs',
        'bait_types',
        'budgets',
        'certificates',
        'chemical_groups',
        'client_requests',
        'client_users',
        'clients',
        'contract_visit_justifications',
        'contracts',
        'daily_cash_balances',
        'device_events',
        'device_replacements',
        'devices',
        'event_types',
        'financial_entries',
        'invitations',
        'notification_logs',
        'notification_queue',
        'notification_templates',
        'onboarding_steps',
        'organ_registrations',
        'payment_details',
        'pest_sightings',
        'procedures',
        'products',
        'rooms',
        'service_orders',
        'service_types',
        'services',
        'sync_conflicts',
        'sync_operations',
        'technicians',
        'users',
        'work_order_adequations',
        'work_order_device_events',
        'work_order_photos',
        'work_order_signatures',
        'work_orders',
    ];

    /**
     * Tabelas que não recebem `company_id`, cada uma com o motivo.
     *
     * @var array<string, string>
     */
    public const TABELAS_FORA_DO_ESCOPO = [
        // A própria tabela de tenants.
        'companies' => 'É a tabela de tenants: a empresa não pertence a uma empresa.',

        // Pivots e filhas alcançadas apenas pelo pai: herdam o escopo dele.
        'budget_products' => 'Pivot de budgets x products: escopo herdado de budgets.',
        'budget_services' => 'Pivot de budgets x services: escopo herdado de budgets.',
        'certificate_procedure' => 'Pivot de certificates x procedures: escopo herdado de certificates.',
        'certificate_product' => 'Pivot de certificates x products: escopo herdado de certificates.',
        'certificate_service' => 'Pivot de certificates x services: escopo herdado de certificates.',
        'pest_sighting_items' => 'Filha de pest_sightings, alcançada só pelo relacionamento do pai: escopo herdado.',
        'room_service_order' => 'Pivot de rooms x service_orders: escopo herdado de service_orders.',
        'service_order_product' => 'Pivot de service_orders x products: escopo herdado de service_orders.',
        'work_order_device' => 'Pivot de work_orders x devices: escopo herdado de work_orders.',
        'work_order_product' => 'Pivot de work_orders x products: escopo herdado de work_orders.',
        'work_order_room' => 'Pivot de work_orders x rooms: escopo herdado de work_orders.',
        'work_order_services' => 'Pivot de work_orders x services: escopo herdado de work_orders.',
        'work_order_technicians' => 'Pivot de work_orders x technicians: escopo herdado de work_orders.',

        // Spatie Permission: catálogo de papéis e permissões compartilhado
        // entre tenants, sem o recurso de teams.
        'model_has_permissions' => 'Spatie Permission: vínculo de permissão, escopo pelo usuário.',
        'model_has_roles' => 'Spatie Permission: vínculo de papel, escopo pelo usuário.',
        'permissions' => 'Spatie Permission: catálogo de permissões, igual para todos os tenants.',
        'role_has_permissions' => 'Spatie Permission: vínculo de papel x permissão, catálogo compartilhado.',
        'roles' => 'Spatie Permission: catálogo de papéis, igual para todos os tenants.',

        // Infraestrutura do framework e da plataforma.
        'cache' => 'Cache do framework: chave já carrega o contexto de quem gravou.',
        'cache_locks' => 'Cache do framework.',
        'failed_jobs' => 'Fila do framework: o payload do job é que carrega o company_id.',
        'job_batches' => 'Fila do framework.',
        'jobs' => 'Fila do framework: o payload do job é que carrega o company_id.',
        'migrations' => 'Controle de versão do schema.',
        'password_reset_tokens' => 'Autenticação: chaveada por e-mail, que é global.',
        'plans' => 'Catálogo de planos comerciais da plataforma, compartilhado entre tenants.',
        'modules' => 'Catálogo de módulos controláveis da plataforma, compartilhado entre tenants.',
        'plan_module' => 'Pivot de plans x modules: catálogo de plataforma, sem tenant a herdar.',
        'company_modules' => 'Liberação pontual de módulo por tenant, gerida pelo super admin, que precisa ver todos os tenants na mesma tela.',
        'gateway_events' => 'Evento recebido do gateway de pagamento, sem company_id: quem identifica o tenant é o conteúdo do payload, processado pela plataforma.',
        'invoices' => 'Fatura de assinatura administrada pela plataforma; tem company_id mas não usa o escopo global. O tenant vê as próprias faturas por filtro explícito.',
        'scheduled_task_runs' => 'Diagnóstico das rotinas agendadas da plataforma, roda fora de tenant.',
        'subscriptions' => 'Assinatura do tenant administrada pela plataforma; tem company_id mas não usa o escopo global. O tenant vê a própria assinatura por filtro explícito.',
        'sessions' => 'Sessão do framework: vínculo por usuário.',
        'personal_access_tokens' => 'Sanctum: token do aplicativo do técnico (Plano 12, Task 12.2), vínculo polimórfico por usuário (tokenable), que já é escopado por empresa.',
        'tenant_usages' => 'Apuração de uso lida pelo super admin entre todos os tenants; o tenant que quiser ver o próprio uso filtra explicitamente por company_id.',
    ];

    /**
     * Models que precisam da trait `BelongsToCompany`.
     *
     * `User` entra na lista pelo preenchimento automático de `company_id`, mas
     * o escopo global de leitura dele é a exceção declarada do sistema: quem
     * resolve o tenant lê o usuário autenticado, então filtrar `users` por
     * empresa filtraria pela empresa que ainda está sendo descoberta. O opt-out
     * fica em `User::aplicaEscopoDeEmpresaNaLeitura()`, com o motivo escrito
     * lá, e a contrapartida é o scope `daEmpresaAtual()` nas consultas que
     * listam usuários.
     *
     * @var array<int, class-string>
     */
    public const MODELS_DE_DOMINIO = [
        \App\Models\AccessLog::class,
        \App\Models\ActiveIngredient::class,
        \App\Models\Address::class,
        \App\Models\Antidote::class,
        \App\Models\AuditLog::class,
        \App\Models\BaitType::class,
        \App\Models\Budget::class,
        \App\Models\Certificate::class,
        \App\Models\ChemicalGroup::class,
        \App\Models\Client::class,
        \App\Models\ClientRequest::class,
        \App\Models\ClientUser::class,
        \App\Models\Contract::class,
        \App\Models\ContractVisitJustification::class,
        \App\Models\DailyCashBalance::class,
        \App\Models\Device::class,
        \App\Models\DeviceEvent::class,
        \App\Models\DeviceReplacement::class,
        \App\Models\EventType::class,
        \App\Models\FinancialEntry::class,
        \App\Models\Invitation::class,
        \App\Models\NotificationLog::class,
        \App\Models\NotificationQueue::class,
        \App\Models\NotificationTemplate::class,
        \App\Models\OnboardingStep::class,
        \App\Models\OrganRegistration::class,
        \App\Models\PaymentDetail::class,
        \App\Models\PestSighting::class,
        \App\Models\Procedure::class,
        \App\Models\Product::class,
        \App\Models\Room::class,
        \App\Models\Service::class,
        \App\Models\ServiceOrder::class,
        \App\Models\ServiceType::class,
        \App\Models\SyncConflict::class,
        \App\Models\SyncOperation::class,
        \App\Models\Technician::class,
        \App\Models\User::class,
        \App\Models\WorkOrder::class,
        \App\Models\WorkOrderAdequation::class,
        \App\Models\WorkOrderDeviceEvent::class,
        \App\Models\WorkOrderPhoto::class,
        \App\Models\WorkOrderSignature::class,
    ];

    /**
     * Models de `app/Models/` que não levam a trait, com o motivo.
     *
     * O teste da Task 4.10 percorre o diretório e exige que todo model esteja
     * em uma das duas listas, para que model novo não passe despercebido.
     *
     * @var array<class-string, string>
     */
    public const MODELS_FORA_DO_ESCOPO = [
        \App\Models\Company::class => 'É o próprio tenant.',
        \App\Models\CompanyModule::class => 'Liberação pontual de módulo por tenant, gerida pelo super admin, que precisa ver todos os tenants na mesma tela; tem company_id mas não usa o escopo global.',
        \App\Models\GatewayEvent::class => 'Evento recebido do gateway de pagamento, sem company_id: quem identifica o tenant é o conteúdo do payload, processado pela plataforma.',
        \App\Models\Invoice::class => 'Fatura de assinatura administrada pela plataforma; tem company_id mas não usa o escopo global. O tenant vê as próprias faturas por filtro explícito.',
        \App\Models\Module::class => 'Catálogo de módulos controláveis da plataforma, compartilhado entre tenants.',
        \App\Models\PestSightingItem::class => 'Filha de PestSighting, alcançada só pelo relacionamento: escopo herdado.',
        \App\Models\Plan::class => 'Catálogo de planos comerciais da plataforma, compartilhado entre tenants.',
        \App\Models\ScheduledTaskRun::class => 'Diagnóstico de rotina agendada da plataforma, roda fora de tenant.',
        \App\Models\Subscription::class => 'Assinatura do tenant administrada pela plataforma; tem company_id mas não usa o escopo global. O tenant vê a própria assinatura por filtro explícito.',
        \App\Models\TenantUsage::class => 'Apuração de uso lida pelo super admin entre todos os tenants; o tenant que quiser ver o próprio uso filtra explicitamente por company_id.',
    ];

    /**
     * Uniques globais que precisam virar compostas com `company_id` na
     * Task 4.5. Sem isso o segundo tenant colide com o primeiro.
     *
     * @var array<string, array{indice: string, colunas: array<int, string>, motivo: string}>
     */
    public const UNIQUES_A_COMPOR = [
        'daily_cash_balances' => [
            'indice' => 'daily_cash_balances_balance_date_unique',
            'colunas' => ['balance_date'],
            'motivo' => 'Segundo tenant não consegue criar o saldo do dia, e o financeiro dele falha sem erro visível.',
        ],
        'service_orders' => [
            'indice' => 'service_orders_order_number_unique',
            'colunas' => ['order_number'],
            'motivo' => 'Colisão de numeração entre empresas.',
        ],
        'service_types' => [
            'indice' => 'service_types_slug_unique',
            'colunas' => ['slug'],
            'motivo' => 'Cada empresa precisa dos próprios tipos de serviço.',
        ],
        'technicians' => [
            'indice' => 'technicians_email_unique',
            'colunas' => ['email'],
            'motivo' => 'O mesmo técnico não poderia existir em duas empresas.',
        ],
        'work_orders' => [
            'indice' => 'work_orders_order_number_unique',
            'colunas' => ['order_number'],
            'motivo' => 'Colisão de numeração entre empresas.',
        ],
    ];

    /**
     * Uniques globais que continuam globais de propósito.
     *
     * Toda unique global encontrada na varredura precisa estar aqui ou em
     * `UNIQUES_A_COMPOR`. Unique global nova em tabela de domínio aparece em
     * `conferir()` como divergência, em vez de passar batida.
     *
     * @var array<string, array<int, array{indice: string, colunas: array<int, string>, motivo: string}>>
     */
    public const UNIQUES_GLOBAIS_MANTIDOS = [
        'users' => [
            [
                'indice' => 'users_email_unique',
                'colunas' => ['email'],
                'motivo' => 'Decisão registrada no PRD: o login é por e-mail, então um e-mail pertence a uma empresa só.',
            ],
        ],
        'technicians' => [
            [
                'indice' => 'technicians_user_id_unique',
                'colunas' => ['user_id'],
                'motivo' => 'Vínculo 1:1 com users. Como o usuário pertence a uma empresa só, o unique global não bloqueia o segundo tenant.',
            ],
        ],
        'work_order_signatures' => [
            [
                'indice' => 'work_order_signatures_work_order_id_unique',
                'colunas' => ['work_order_id'],
                'motivo' => 'Vínculo 1:1 com work_orders (Plano 13): uma assinatura válida por OS. Como a OS já pertence a uma empresa só, o unique global não bloqueia o segundo tenant, mesmo raciocínio já registrado para technicians.user_id_unique.',
            ],
        ],
        'notification_queue' => [
            [
                'indice' => 'notification_queue_chave_idempotencia_unique',
                'colunas' => ['chave_idempotencia'],
                'motivo' => 'Chave de idempotência do Plano 14, montada como {evento}:{destinatario_tipo}:{destinatario_id}:{referencia_tipo}:{referencia_id}. Os ids são os do banco compartilhado e já pertencem a uma empresa só, então compor com company_id não separaria tenant nenhum e só enfraqueceria a restrição que impede o mesmo aviso sair duas vezes.',
            ],
        ],
        'invitations' => [
            [
                'indice' => 'invitations_token_unique',
                'colunas' => ['token'],
                'motivo' => 'Token de convite (Plano 8) é a chave de busca no link de cadastro, resolvida antes de existir tenant no contexto da requisição: quem clica no link ainda não está autenticado, então não há empresa por onde compor. Os 64 caracteres aleatórios de Str::random(64) já tornam a colisão entre tenants desprezível, e é esse espaço grande, e não o company_id, que garante o token de uso único.',
            ],
        ],
        'sync_operations' => [
            [
                'indice' => 'sync_operations_operacao_uuid_unique',
                'colunas' => ['operacao_uuid'],
                'motivo' => 'UUID gerado no celular no momento do registro (Plano 12): é a chave que torna a sincronização idempotente, reconhecendo o reenvio de uma operação cuja resposta se perdeu na rede. Compor com company_id não separaria tenant nenhum, porque o dispositivo já pertence a uma empresa só; é o espaço grande de um uuid v4, e não o company_id, que torna a colisão entre tenants desprezível, o mesmo raciocínio já registrado para invitations.token.',
            ],
        ],
        // As demais vivem em tabelas fora do escopo e ficam aqui só como registro.
        'permissions' => [
            [
                'indice' => 'permissions_name_guard_name_unique',
                'colunas' => ['name', 'guard_name'],
                'motivo' => 'Catálogo do Spatie compartilhado entre tenants.',
            ],
        ],
        'roles' => [
            [
                'indice' => 'roles_name_guard_name_unique',
                'colunas' => ['name', 'guard_name'],
                'motivo' => 'Catálogo do Spatie compartilhado entre tenants.',
            ],
        ],
        'failed_jobs' => [
            [
                'indice' => 'failed_jobs_uuid_unique',
                'colunas' => ['uuid'],
                'motivo' => 'Infraestrutura de fila do framework.',
            ],
        ],
        'plans' => [
            [
                'indice' => 'plans_slug_unique',
                'colunas' => ['slug'],
                'motivo' => 'Catálogo comercial da plataforma: o slug do plano é único para todos os tenants, de propósito.',
            ],
        ],
        'certificate_service' => [
            [
                'indice' => 'certificate_service_certificate_id_service_id_unique',
                'colunas' => ['certificate_id', 'service_id'],
                'motivo' => 'Unique de pivot sobre chaves estrangeiras: escopo herdado do pai.',
            ],
        ],
        'room_service_order' => [
            [
                'indice' => 'room_service_order_room_id_service_order_id_unique',
                'colunas' => ['room_id', 'service_order_id'],
                'motivo' => 'Unique de pivot sobre chaves estrangeiras: escopo herdado do pai.',
            ],
        ],
        'work_order_device' => [
            [
                'indice' => 'work_order_device_work_order_id_device_id_unique',
                'colunas' => ['work_order_id', 'device_id'],
                'motivo' => 'Unique de pivot sobre chaves estrangeiras: escopo herdado do pai.',
            ],
        ],
        'work_order_room' => [
            [
                'indice' => 'work_order_room_work_order_id_room_id_unique',
                'colunas' => ['work_order_id', 'room_id'],
                'motivo' => 'Unique de pivot sobre chaves estrangeiras: escopo herdado do pai.',
            ],
        ],
        'work_order_services' => [
            [
                'indice' => 'work_order_services_work_order_id_service_id_unique',
                'colunas' => ['work_order_id', 'service_id'],
                'motivo' => 'Unique de pivot sobre chaves estrangeiras: escopo herdado do pai.',
            ],
        ],
        'work_order_technicians' => [
            [
                'indice' => 'work_order_technicians_work_order_id_technician_id_unique',
                'colunas' => ['work_order_id', 'technician_id'],
                'motivo' => 'Unique de pivot sobre chaves estrangeiras: escopo herdado do pai.',
            ],
        ],
    ];

    /**
     * Nome da coluna de tenant, para as migrations e a trait não repetirem
     * a string.
     */
    public const COLUNA_TENANT = 'company_id';

    /**
     * Nomes das tabelas que ficam fora do escopo.
     *
     * @return array<int, string>
     */
    public static function tabelasForaDoEscopo(): array
    {
        return array_keys(self::TABELAS_FORA_DO_ESCOPO);
    }

    /**
     * Nomes das classes de model que ficam fora do escopo.
     *
     * @return array<int, class-string>
     */
    public static function modelsForaDoEscopo(): array
    {
        return array_keys(self::MODELS_FORA_DO_ESCOPO);
    }

    /**
     * Todas as tabelas classificadas, de domínio ou fora do escopo.
     *
     * @return array<int, string>
     */
    public static function tabelasClassificadas(): array
    {
        return array_merge(self::TABELAS_DE_DOMINIO, self::tabelasForaDoEscopo());
    }

    /**
     * A tabela existe no banco conectado?
     */
    public static function tabelaExiste(string $tabela): bool
    {
        return Schema::hasTable($tabela);
    }

    /**
     * A tabela já tem a coluna de tenant?
     */
    public static function tabelaTemColunaTenant(string $tabela): bool
    {
        return Schema::hasColumn($tabela, self::COLUNA_TENANT);
    }

    /**
     * Divergências entre a classificação declarada e o schema real.
     *
     * Devolve array vazio quando está tudo coerente. Cada chave presente é um
     * problema a resolver antes de seguir com as etapas da migração.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function conferir(): array
    {
        $divergencias = [];
        $reais = self::tabelasDoBanco();
        $declaradas = self::tabelasClassificadas();

        $emDuasListas = array_values(array_intersect(self::TABELAS_DE_DOMINIO, self::tabelasForaDoEscopo()));
        if ($emDuasListas !== []) {
            $divergencias['tabelas_em_duas_listas'] = $emDuasListas;
        }

        $declaradasInexistentes = array_values(array_diff($declaradas, $reais));
        if ($declaradasInexistentes !== []) {
            $divergencias['tabelas_declaradas_que_nao_existem'] = $declaradasInexistentes;
        }

        $naoClassificadas = array_values(array_diff($reais, $declaradas));
        if ($naoClassificadas !== []) {
            $divergencias['tabelas_do_banco_nao_classificadas'] = $naoClassificadas;
        }

        $modelsInexistentes = array_values(array_filter(
            array_merge(self::MODELS_DE_DOMINIO, self::modelsForaDoEscopo()),
            static fn (string $model): bool => ! class_exists($model)
        ));
        if ($modelsInexistentes !== []) {
            $divergencias['models_declarados_que_nao_existem'] = $modelsInexistentes;
        }

        $uniques = self::conferirUniques();
        if ($uniques !== []) {
            $divergencias = array_merge($divergencias, $uniques);
        }

        return $divergencias;
    }

    /**
     * Estado das uniques declaradas e caça a unique global não declarada.
     *
     * Uma unique de `UNIQUES_A_COMPOR` é aceita em dois estados: ainda global,
     * antes da Task 4.5, ou já composta com `company_id`, depois dela. Some,
     * mudou de nome ou perdeu coluna, vira divergência.
     *
     * @return array<string, array<int, mixed>>
     */
    public static function conferirUniques(): array
    {
        $divergencias = [];
        $naoEncontradas = [];
        $naoDeclaradas = [];

        foreach (self::UNIQUES_A_COMPOR as $tabela => $esperada) {
            if (! self::tabelaExiste($tabela)) {
                $naoEncontradas[] = "{$tabela}: tabela não existe";

                continue;
            }

            $global = $esperada['colunas'];
            $composta = array_merge([self::COLUNA_TENANT], $esperada['colunas']);

            $encontrada = false;
            foreach (self::uniquesDaTabela($tabela) as $indice) {
                if (self::mesmasColunas($indice['columns'], $global) || self::mesmasColunas($indice['columns'], $composta)) {
                    $encontrada = true;
                    break;
                }
            }

            if (! $encontrada) {
                $naoEncontradas[] = "{$tabela}: unique em (".implode(', ', $global).') não encontrada';
            }
        }

        if ($naoEncontradas !== []) {
            $divergencias['uniques_a_compor_nao_encontradas'] = $naoEncontradas;
        }

        foreach (self::TABELAS_DE_DOMINIO as $tabela) {
            if (! self::tabelaExiste($tabela)) {
                continue;
            }

            foreach (self::uniquesDaTabela($tabela) as $indice) {
                if (in_array(self::COLUNA_TENANT, $indice['columns'], true)) {
                    continue;
                }

                if (self::uniqueEstaDeclarada($tabela, $indice['columns'])) {
                    continue;
                }

                $naoDeclaradas[] = "{$tabela}.{$indice['name']} (".implode(', ', $indice['columns']).')';
            }
        }

        if ($naoDeclaradas !== []) {
            $divergencias['uniques_globais_nao_declaradas'] = $naoDeclaradas;
        }

        return $divergencias;
    }

    /**
     * Tabelas do banco conectado, sem o prefixo de schema.
     *
     * @return array<int, string>
     */
    private static function tabelasDoBanco(): array
    {
        return array_map(
            static fn (string $tabela): string => str_contains($tabela, '.')
                ? substr($tabela, strrpos($tabela, '.') + 1)
                : $tabela,
            Schema::getTableListing()
        );
    }

    /**
     * Índices unique da tabela, sem a chave primária.
     *
     * @return array<int, array{name: string, columns: array<int, string>}>
     */
    private static function uniquesDaTabela(string $tabela): array
    {
        $uniques = [];

        foreach (Schema::getIndexes($tabela) as $indice) {
            if (! ($indice['unique'] ?? false) || ($indice['primary'] ?? false)) {
                continue;
            }

            $uniques[] = ['name' => $indice['name'], 'columns' => $indice['columns']];
        }

        return $uniques;
    }

    /**
     * A unique já está declarada em uma das duas listas?
     *
     * @param  array<int, string>  $colunas
     */
    private static function uniqueEstaDeclarada(string $tabela, array $colunas): bool
    {
        $aCompor = self::UNIQUES_A_COMPOR[$tabela] ?? null;
        if ($aCompor !== null && self::mesmasColunas($colunas, $aCompor['colunas'])) {
            return true;
        }

        foreach (self::UNIQUES_GLOBAIS_MANTIDOS[$tabela] ?? [] as $mantida) {
            if (self::mesmasColunas($colunas, $mantida['colunas'])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Os dois conjuntos de colunas são o mesmo, ignorando a ordem?
     *
     * @param  array<int, string>  $primeiro
     * @param  array<int, string>  $segundo
     */
    private static function mesmasColunas(array $primeiro, array $segundo): bool
    {
        sort($primeiro);
        sort($segundo);

        return $primeiro === $segundo;
    }
}
