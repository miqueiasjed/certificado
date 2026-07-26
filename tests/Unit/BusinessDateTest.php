<?php

namespace Tests\Unit;

use App\Support\BusinessDate;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * BusinessDate é a fronteira entre o relógio da aplicação, que roda em UTC, e o
 * dia do negócio, que é o de Brasília. Todo teste aqui fixa o relógio com
 * Carbon::setTestNow: nenhum deles pode depender da hora real da máquina nem do
 * fuso do sistema operacional.
 *
 * Estende Tests\TestCase porque BusinessDate lê config('app.business_timezone'),
 * o que exige a aplicação de pé. Nenhum teste desta classe toca o banco.
 */
class BusinessDateTest extends TestCase
{
    /**
     * 02:00 UTC do dia 26 ainda é 23:00 do dia 25 em Brasília. É a janela em que
     * UTC e o fuso do negócio discordam sobre que dia é hoje.
     */
    private const MADRUGADA_UTC = '2026-07-26 02:00:00';

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_fuso_do_negocio_e_o_de_sao_paulo(): void
    {
        $this->assertSame('America/Sao_Paulo', BusinessDate::fuso());
    }

    public function test_hoje_devolve_o_dia_de_brasilia_quando_o_relogio_esta_as_duas_da_manha_em_utc(): void
    {
        $this->fixarRelogio(self::MADRUGADA_UTC);

        // Guarda do próprio teste: se estas duas linhas passarem a concordar, o
        // cenário deixou de exercitar a virada de dia e o teste perde o sentido.
        $this->assertSame('2026-07-26', Carbon::now('UTC')->toDateString());
        $this->assertSame('2026-07-25', BusinessDate::hoje()->toDateString());
    }

    public function test_hoje_vem_com_a_hora_zerada(): void
    {
        $this->fixarRelogio(self::MADRUGADA_UTC);

        $hoje = BusinessDate::hoje();

        $this->assertSame('2026-07-25 00:00:00', $hoje->toDateTimeString());
        $this->assertSame('America/Sao_Paulo', $hoje->timezoneName);
    }

    public function test_agora_devolve_o_instante_convertido_para_o_fuso_do_negocio(): void
    {
        $this->fixarRelogio(self::MADRUGADA_UTC);

        $agora = BusinessDate::agora();

        $this->assertSame('2026-07-25 23:00:00', $agora->toDateTimeString());
        $this->assertSame('America/Sao_Paulo', $agora->timezoneName);
    }

    public function test_hoje_acompanha_o_dia_quando_o_relogio_esta_no_meio_do_dia(): void
    {
        $this->fixarRelogio('2026-07-26 12:00:00');

        $this->assertSame('2026-07-26', BusinessDate::hoje()->toDateString());
    }

    public function test_o_que_vence_hoje_nao_esta_vencido(): void
    {
        $this->fixarRelogio(self::MADRUGADA_UTC);

        $this->assertFalse(BusinessDate::estaVencido('2026-07-25'));
    }

    public function test_o_que_venceu_ontem_esta_vencido(): void
    {
        $this->fixarRelogio(self::MADRUGADA_UTC);

        $this->assertTrue(BusinessDate::estaVencido('2026-07-24'));
    }

    public function test_o_que_vence_amanha_nao_esta_vencido(): void
    {
        $this->fixarRelogio(self::MADRUGADA_UTC);

        $this->assertFalse(BusinessDate::estaVencido('2026-07-26'));
    }

    public function test_data_nula_nao_esta_vencida_e_nao_lanca_excecao(): void
    {
        $this->fixarRelogio(self::MADRUGADA_UTC);

        $this->assertFalse(BusinessDate::estaVencido(null));
        $this->assertFalse(BusinessDate::estaVencido(''));
    }

    /**
     * O dia do vencimento chega de três formas no sistema: string do banco,
     * Carbon do cast `date` e CarbonImmutable. As três precisam responder igual,
     * senão a tela e a rotina discordam sobre o mesmo registro.
     */
    public function test_estaVencido_responde_igual_para_string_carbon_e_carbon_imutavel(): void
    {
        $this->fixarRelogio(self::MADRUGADA_UTC);

        $this->assertFalse(BusinessDate::estaVencido('2026-07-25'));
        $this->assertFalse(BusinessDate::estaVencido(Carbon::parse('2026-07-25')));
        $this->assertFalse(BusinessDate::estaVencido(CarbonImmutable::parse('2026-07-25')));

        $this->assertTrue(BusinessDate::estaVencido('2026-07-24'));
        $this->assertTrue(BusinessDate::estaVencido(Carbon::parse('2026-07-24')));
        $this->assertTrue(BusinessDate::estaVencido(CarbonImmutable::parse('2026-07-24')));
    }

    public function test_dia_puro_nao_escorrega_ao_ser_convertido(): void
    {
        $this->fixarRelogio(self::MADRUGADA_UTC);

        $this->assertSame('2026-07-25', BusinessDate::paraFusoNegocio('2026-07-25')->toDateString());
        $this->assertSame('2026-07-25', BusinessDate::diaDe('2026-07-25'));
        $this->assertSame('2026-07-25', BusinessDate::diaDe(Carbon::parse('2026-07-25')));
    }

    public function test_instante_e_convertido_para_o_dia_do_fuso_do_negocio(): void
    {
        $this->fixarRelogio(self::MADRUGADA_UTC);

        // 26/07 às 02:00 em UTC é 25/07 às 23:00 em Brasília.
        $this->assertSame('2026-07-25', BusinessDate::diaDe(Carbon::parse('2026-07-26 02:00:00', 'UTC')));
    }

    public function test_dia_de_valor_nulo_e_nulo(): void
    {
        $this->assertNull(BusinessDate::diaDe(null));
        $this->assertNull(BusinessDate::paraFusoNegocio(null));
        $this->assertNull(BusinessDate::paraFusoNegocio(''));
    }

    public function test_valor_de_tipo_nao_suportado_lanca_excecao(): void
    {
        $this->expectException(InvalidArgumentException::class);

        BusinessDate::paraFusoNegocio(12345);
    }

    /**
     * Fixa o relógio da aplicação em um instante UTC, que é o fuso em que o
     * backend roda. O cenário de virada de dia é produzido pelo tempo fixado,
     * nunca pelo fuso da máquina que executa a suíte.
     */
    private function fixarRelogio(string $instanteUtc): void
    {
        Carbon::setTestNow(Carbon::parse($instanteUtc, 'UTC'));
    }
}
