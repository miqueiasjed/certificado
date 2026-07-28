<?php

namespace App\Services;

use App\Models\Company;

/**
 * Situação dos quatro limites quantitativos do plano contratado (Plano 6,
 * Task 6.5): `usuarios`, `clientes`, `os_mes` e `armazenamento_mb`.
 *
 * A regra de negócio central deste Service, repetida em cada método porque é
 * fácil de esquecer: **estourar limite nunca apaga nem esconde dado**. Cliente
 * e OS acima do teto continuam sendo criados e continuam aparecendo em toda
 * consulta; o que este Service produz é só o aviso e a decisão de bloqueio
 * duro, que existe apenas para `usuarios`. Usuário é o único recurso onde
 * "criar" é um ato administrativo consciente (o admin decide dar acesso a
 * mais uma pessoa), diferente de cliente e OS, que nascem do fluxo de
 * trabalho diário e não podem parar por causa de um teto comercial.
 *
 * Limite nulo (campo `Plan::limite_*` nulo) é ilimitado: nenhuma verificação,
 * nenhum aviso, para aquele recurso específico. Quando os quatro campos do
 * plano são nulos ao mesmo tempo (tenant interno, ou empresa sem
 * `plan_id`), `situacao()` nem chega a consultar `TenantUsageService`: não há
 * nada para comparar, e evita o cálculo mais caro do sistema de apuração de
 * uso, o `armazenamento_mb` (um `stat` de disco por foto de OS, ver o
 * cabeçalho de `TenantUsageService`). É assim, e não por checar
 * `is_internal`, que o tenant interno não recebe aviso algum: o plano interno
 * é que nasce com os quatro limites nulos (Task 6.2), sem regra especial
 * neste código.
 */
class LimitesDoPlanoService
{
    /**
     * Recurso (chave usada neste Service) para o campo de teto correspondente
     * em `Plan`.
     *
     * @var array<string, string>
     */
    private const RECURSO_PARA_LIMITE = [
        'usuarios' => 'limite_usuarios',
        'clientes' => 'limite_clientes',
        'os_mes' => 'limite_os_mes',
        'armazenamento_mb' => 'limite_armazenamento_mb',
    ];

    /**
     * Recurso para a chave correspondente em
     * `TenantUsageService::usoAtual()`. Só `os_mes` diverge do próprio nome
     * (a apuração de uso chama a mesma contagem de `ordens_servico`).
     *
     * @var array<string, string>
     */
    private const RECURSO_PARA_USO = [
        'usuarios' => 'usuarios',
        'clientes' => 'clientes',
        'os_mes' => 'ordens_servico',
        'armazenamento_mb' => 'armazenamento_mb',
    ];

    /**
     * Nome do recurso em português, para compor a mensagem de `avisos()`.
     *
     * @var array<string, string>
     */
    private const NOME_DO_RECURSO = [
        'usuarios' => 'usuários',
        'clientes' => 'clientes',
        'os_mes' => 'ordens de serviço no mês',
        'armazenamento_mb' => 'armazenamento',
    ];

    /**
     * Percentual do teto a partir do qual o estado deixa de ser `ok` e passa
     * a `proximo`. `estourado` é qualquer percentual acima de 100.
     */
    private const PERCENTUAL_PROXIMO = 80;

    public function __construct(
        private readonly TenantUsageService $tenantUsageService,
    ) {
    }

    /**
     * Situação de cada um dos quatro limites do plano da empresa: valor
     * atual, teto, percentual do teto e estado (`ok`, `proximo`,
     * `estourado`).
     *
     * `proximo` a partir de 80% do teto, `estourado` acima de 100%. Teto nulo
     * devolve sempre `ok`, com `teto` e `percentual` nulos: não há o que
     * comparar.
     *
     * Quando os quatro tetos são nulos (empresa sem plano, ou plano com todos
     * os limites liberados), a apuração de uso nem é chamada: sem teto algum
     * para comparar, o resultado seria `ok` para os quatro de qualquer forma,
     * e a chamada evitada é exatamente a mais cara do sistema (ver o
     * cabeçalho da classe). Havendo ao menos um teto definido, a apuração
     * roda inteira, porque `TenantUsageService::usoAtual()` calcula as cinco
     * contagens juntas, sem meio-termo de "calcular só o que tem teto".
     *
     * @return array<string, array{atual: int|null, teto: int|null, percentual: int|null, estado: string}>
     */
    public function situacao(Company $empresa): array
    {
        $empresa->loadMissing('plan');
        $plano = $empresa->plan;

        $tetos = [];

        foreach (self::RECURSO_PARA_LIMITE as $recurso => $campoDoTeto) {
            $tetos[$recurso] = $plano?->{$campoDoTeto};
        }

        if (array_filter($tetos, fn (?int $teto): bool => $teto !== null) === []) {
            return array_map(fn (): array => $this->situacaoSemTeto(), $tetos);
        }

        $uso = $this->tenantUsageService->usoAtual($empresa);

        $situacao = [];

        foreach ($tetos as $recurso => $teto) {
            $atual = $uso[self::RECURSO_PARA_USO[$recurso]];
            $situacao[$recurso] = $this->calcularSituacaoDoRecurso($atual, $teto);
        }

        return $situacao;
    }

    /**
     * A empresa pode criar mais um registro do recurso informado agora?
     *
     * Só `usuarios` tem bloqueio duro: os demais (`clientes`, `os_mes`,
     * `armazenamento_mb`) sempre devolvem `true` aqui, porque estourar o
     * teto deles gera aviso, nunca recusa. É a regra mais importante deste
     * Service, e por isso vem antes de qualquer consulta.
     *
     * A checagem de `usuarios` compara a contagem atual de usuários ativos
     * contra o teto do plano diretamente, e não contra o estado
     * `proximo`/`estourado` de `situacao()`. Os dois cálculos respondem a
     * perguntas diferentes: `situacao()` descreve o presente para avisar (a
     * banda de 80%/100% é sobre o uso já existente), enquanto `podeCriar()`
     * decide se AINDA CABE mais um. No teto exato (`atual == teto`, 100% de
     * uso, portanto `proximo` em `situacao()`, não `estourado`), a empresa já
     * não tem vaga: criar mais um usuário estouraria o teto, e é isso que
     * `podeCriar()` tem que impedir antes de acontecer, não depois.
     *
     * Sem empresa informada, usa `Company::current()` (o tenant da requisição
     * corrente), no mesmo padrão de `ModuleService::ativo()`.
     */
    public function podeCriar(string $recurso, ?Company $empresa = null): bool
    {
        if ($recurso !== 'usuarios') {
            return true;
        }

        $empresa ??= Company::current();
        $empresa->loadMissing('plan');

        $teto = $empresa->plan?->limite_usuarios;

        if ($teto === null) {
            return true;
        }

        $atual = $this->tenantUsageService->usoAtual($empresa)['usuarios'];

        return $atual < $teto;
    }

    /**
     * Mensagens prontas para exibir, uma por limite em estado `proximo` ou
     * `estourado`. Vazio quando todos os limites estão `ok`, inclusive
     * quando são todos nulos (tenant interno, empresa sem plano): nesse caso
     * `situacao()` nem consulta o uso, então nada aqui percorre nada além dos
     * quatro estados já calculados como `ok`.
     *
     * @return array<int, string>
     */
    public function avisos(Company $empresa): array
    {
        $situacao = $this->situacao($empresa);

        $empresa->loadMissing('plan');
        $nomeDoPlano = $empresa->plan?->nome ?? 'atual';

        $avisos = [];

        foreach ($situacao as $recurso => $dados) {
            if (! in_array($dados['estado'], ['proximo', 'estourado'], true)) {
                continue;
            }

            $avisos[] = $this->mensagemDeAviso($recurso, $dados, $nomeDoPlano);
        }

        return $avisos;
    }

    /**
     * @return array{atual: null, teto: null, percentual: null, estado: string}
     */
    private function situacaoSemTeto(): array
    {
        return ['atual' => null, 'teto' => null, 'percentual' => null, 'estado' => 'ok'];
    }

    /**
     * @return array{atual: int, teto: int, percentual: int, estado: string}
     */
    private function calcularSituacaoDoRecurso(int $atual, ?int $teto): array
    {
        if ($teto === null) {
            return ['atual' => $atual, 'teto' => null, 'percentual' => null, 'estado' => 'ok'];
        }

        // `max($teto, 1)` só protege contra teto gravado como 0, que não
        // deveria acontecer (a coluna é unsigned e o cadastro de plano não
        // libera zero), mas dividir por zero não pode derrubar a página só
        // por um dado de cadastro incomum.
        $percentual = (int) round(($atual / max($teto, 1)) * 100);

        $estado = match (true) {
            $percentual > 100 => 'estourado',
            $percentual >= self::PERCENTUAL_PROXIMO => 'proximo',
            default => 'ok',
        };

        return ['atual' => $atual, 'teto' => $teto, 'percentual' => $percentual, 'estado' => $estado];
    }

    /**
     * @param  array{atual: int|null, teto: int|null, percentual: int|null, estado: string}  $dados
     */
    private function mensagemDeAviso(string $recurso, array $dados, string $nomeDoPlano): string
    {
        $nome = self::NOME_DO_RECURSO[$recurso] ?? $recurso;

        if ($dados['estado'] === 'estourado') {
            return sprintf(
                'O limite de %s do plano %s foi ultrapassado: %d de %d. Fale com o suporte para ampliar o plano.',
                $nome,
                $nomeDoPlano,
                $dados['atual'],
                $dados['teto']
            );
        }

        return sprintf(
            'O limite de %s do plano %s está perto do teto: %d de %d (%d%%).',
            $nome,
            $nomeDoPlano,
            $dados['atual'],
            $dados['teto'],
            $dados['percentual']
        );
    }
}
