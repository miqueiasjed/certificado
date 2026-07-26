<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Resolve "hoje" e "agora" no fuso do negócio (America/Sao_Paulo).
 *
 * A aplicação continua rodando em UTC (config('app.timezone')), porque todo o
 * histórico de created_at/updated_at foi gravado assim. Esta classe existe para
 * que comparação de vencimento e rotina agendada usem o dia de Brasília, e não
 * o dia em UTC.
 *
 * Utilitário de infraestrutura: sem regra de domínio e sem acesso a banco.
 */
final class BusinessDate
{
    /**
     * Rede de segurança para quando a chave de configuração não estiver
     * disponível, por exemplo com config cacheado antes desta versão.
     */
    private const FUSO_PADRAO = 'America/Sao_Paulo';

    /**
     * Fuso oficial do negócio.
     */
    public static function fuso(): string
    {
        $fuso = config('app.business_timezone');

        return is_string($fuso) && $fuso !== '' ? $fuso : self::FUSO_PADRAO;
    }

    /**
     * Dia de hoje no fuso do negócio, com a hora zerada.
     */
    public static function hoje(): CarbonImmutable
    {
        return self::agora()->startOfDay();
    }

    /**
     * Instante atual convertido para o fuso do negócio.
     */
    public static function agora(): CarbonImmutable
    {
        return CarbonImmutable::now(self::fuso());
    }

    /**
     * Converte um valor para o fuso do negócio.
     *
     * Valor que representa apenas um dia, seja a string 'Y-m-d' ou o Carbon à
     * meia-noite que vem de um campo `date`, é reconstruído no fuso do negócio
     * sem conversão: converter é justamente o que produz o erro de um dia.
     * A contrapartida é que um instante gravado exatamente às 00:00:00 também
     * é lido como dia puro, o que é aceitável perto do risco de deslocar data
     * de validade.
     *
     * Devolve null para null e para string vazia.
     */
    public static function paraFusoNegocio(mixed $valor): ?CarbonImmutable
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        if ($valor instanceof DateTimeInterface) {
            $instante = CarbonImmutable::instance($valor);

            if (self::ehMeiaNoite($instante)) {
                return self::diaPuro($instante->format('Y-m-d'));
            }

            return $instante->setTimezone(self::fuso());
        }

        if (is_string($valor)) {
            $texto = trim($valor);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $texto) === 1) {
                return self::diaPuro($texto);
            }

            return CarbonImmutable::parse($texto, self::fuso())->setTimezone(self::fuso());
        }

        throw new InvalidArgumentException('Valor de data não suportado: ' . get_debug_type($valor));
    }

    /**
     * Verdadeiro quando a data informada é anterior ao dia de hoje no fuso do
     * negócio. A comparação é dia contra dia, nunca instante contra instante:
     * o que vence hoje não está vencido, mesmo às 21h de Brasília.
     *
     * Valor nulo não está vencido, porque não há data para comparar.
     */
    public static function estaVencido(mixed $data): bool
    {
        $dia = self::paraFusoNegocio($data);

        if ($dia === null) {
            return false;
        }

        return $dia->startOfDay()->lessThan(self::hoje());
    }

    /**
     * Dia do valor informado, no fuso do negócio, no formato Y-m-d.
     */
    public static function diaDe(mixed $valor): ?string
    {
        return self::paraFusoNegocio($valor)?->toDateString();
    }

    /**
     * Monta a meia-noite do dia informado já no fuso do negócio, sem partir de
     * outro fuso, para que a data não escorregue.
     */
    private static function diaPuro(string $dia): CarbonImmutable
    {
        return CarbonImmutable::parse($dia, self::fuso())->startOfDay();
    }

    /**
     * Campo `date` chega como meia-noite exata: o valor carrega um dia, não um
     * instante.
     */
    private static function ehMeiaNoite(CarbonImmutable $instante): bool
    {
        return $instante->hour === 0
            && $instante->minute === 0
            && $instante->second === 0
            && $instante->micro === 0;
    }
}
