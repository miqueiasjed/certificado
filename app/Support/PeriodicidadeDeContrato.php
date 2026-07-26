<?php

namespace App\Support;

/**
 * Fonte única do mapeamento de periodicidade de contrato: texto livre (ou
 * literal do formulário) gravado em `visit_frequency` para
 * [valor, unidade], as duas colunas que `CalendarioDeVisitasService` lê para
 * calcular as datas de visita.
 *
 * Nasceu dentro de `BackfillPeriodicidade` (Task 9.2) e foi extraída para cá
 * porque a gravação do contrato (criação e edição, Task 9.7) precisa do
 * mesmo mapeamento: sem uma fonte única, as duas rotinas podiam divergir
 * sobre o que "mensal" significa, e a data de visita de um cliente real
 * dependeria de qual dos dois caminhos gravou o contrato.
 *
 * Nota sobre "biweekly": no rótulo já usado em Contracts/Create.vue e
 * Edit.vue ("Quinzenal"), biweekly significa a cada 15 dias, não a cada duas
 * semanas. O mapeamento aqui segue esse significado já estabelecido na
 * tela, não o sentido literal da palavra em inglês. Coberto por teste em
 * tests/Feature/BackfillPeriodicidadeTest.php.
 *
 * Utilitário de infraestrutura: sem acesso a banco e sem regra de domínio
 * além da tradução do texto.
 */
final class PeriodicidadeDeContrato
{
    /**
     * Mapa de valores literais conhecidos (normalizados: minúsculo, sem
     * acento, sem espaço nas pontas) para [valor, unidade]. Cobre o domínio
     * provável levantado na Task 9.1 (weekly/biweekly/monthly, o que o
     * formulário emite hoje) e o mapeamento mínimo exigido pela Task 9.2
     * (termos em português, possivelmente de importação antiga ou de uma
     * versão anterior da tela).
     *
     * @var array<string, array{0: int, 1: string}>
     */
    public const MAPA_LITERAL = [
        'semanal' => [1, 'semanas'],
        'weekly' => [1, 'semanas'],
        'quinzenal' => [15, 'dias'],
        'biweekly' => [15, 'dias'],
        'mensal' => [1, 'meses'],
        'monthly' => [1, 'meses'],
        'bimestral' => [2, 'meses'],
        'trimestral' => [3, 'meses'],
        'semestral' => [6, 'meses'],
        'anual' => [12, 'meses'],
    ];

    /**
     * Mapeia o texto livre (ou literal do formulário) para [valor, unidade].
     * Devolve null quando o valor está vazio, nulo ou não é reconhecido por
     * nenhuma das regras: nunca chuta.
     *
     * Regras, na ordem: literal conhecido (MAPA_LITERAL) -> número puro
     * (significado histórico: meses) -> número seguido de palavra de unidade
     * ("3 meses", "15 dias", "2 semanas", "1 ano").
     *
     * @return array{valor: int, unidade: string}|null
     */
    public static function mapear(?string $bruto): ?array
    {
        $texto = self::normalizar($bruto);

        if ($texto === '') {
            return null;
        }

        if (array_key_exists($texto, self::MAPA_LITERAL)) {
            [$valor, $unidade] = self::MAPA_LITERAL[$texto];

            return ['valor' => $valor, 'unidade' => $unidade];
        }

        if (preg_match('/^(\d+)$/', $texto, $numeroPuro) === 1) {
            return self::valorValido((int) $numeroPuro[1], 'meses');
        }

        if (preg_match('/^(\d+)\s*(dias?|semanas?|mes(?:es)?|anos?)$/', $texto, $numeroComUnidade) === 1) {
            $quantidade = (int) $numeroComUnidade[1];
            $unidadeTexto = $numeroComUnidade[2];

            return match (true) {
                str_starts_with($unidadeTexto, 'dia') => self::valorValido($quantidade, 'dias'),
                str_starts_with($unidadeTexto, 'semana') => self::valorValido($quantidade, 'semanas'),
                str_starts_with($unidadeTexto, 'mes') => self::valorValido($quantidade, 'meses'),
                str_starts_with($unidadeTexto, 'ano') => self::valorValido($quantidade * 12, 'meses'),
                default => null,
            };
        }

        return null;
    }

    /**
     * Periodicidade zero não tem significado (geraria visita todo dia ou não
     * geraria nenhuma, a depender de quem lê): tratada como não reconhecida
     * em vez de convertida, para virar pendência de conferência manual em
     * vez de comportamento indefinido em quem consome o valor.
     *
     * @return array{valor: int, unidade: string}|null
     */
    private static function valorValido(int $valor, string $unidade): ?array
    {
        if ($valor < 1) {
            return null;
        }

        return ['valor' => $valor, 'unidade' => $unidade];
    }

    /**
     * Normaliza para comparação: minúsculo, sem acento, sem espaço nas
     * pontas. Nulo e string vazia (inclusive só espaço) viram string vazia.
     */
    private static function normalizar(?string $bruto): string
    {
        $texto = mb_strtolower(trim((string) $bruto));

        return strtr($texto, [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a',
            'é' => 'e', 'ê' => 'e',
            'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o',
            'ú' => 'u',
            'ç' => 'c',
        ]);
    }
}
