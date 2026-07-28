<?php

namespace Tests\Unit;

use App\Contracts\GatewayAssinatura;
use App\Models\Company;
use App\Models\Invoice;
use App\Models\Plan;
use App\Support\Gateway\EventoDeGateway;
use App\Support\Gateway\RespostaDeAssinatura;
use App\Support\Gateway\RespostaDeCobranca;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Task 7.2 do Plano 7: o contrato `GatewayAssinatura` e os objetos de
 * transporte de `App\Support\Gateway`.
 *
 * Sem implementação concreta de gateway aqui (isso é a Task 7.3), então este
 * arquivo cobre só o que esta task entrega: os DTOs recusam vocabulário fora
 * das constantes normalizadas, e o binding em `AppServiceProvider` resolve
 * pela configuração, não por uma classe fixa no código. A prova disso é o
 * `GatewayAssinaturaDeMentira` declarado no fim deste arquivo: ele não é
 * mencionado em lugar nenhum além de `config('services.gateway_assinatura')`
 * dentro do próprio teste, e ainda assim o container devolve uma instância
 * dele.
 */
class GatewayAssinaturaContractTest extends TestCase
{
    // -----------------------------------------------------------------
    // RespostaDeAssinatura
    // -----------------------------------------------------------------

    public function test_resposta_de_assinatura_aceita_situacao_valida(): void
    {
        $resposta = new RespostaDeAssinatura('sub_123', RespostaDeAssinatura::SITUACAO_ATIVA);

        $this->assertSame('sub_123', $resposta->idNoGateway);
        $this->assertSame('ativa', $resposta->situacao);
    }

    public function test_resposta_de_assinatura_recusa_situacao_fora_do_vocabulario(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RespostaDeAssinatura('sub_123', 'situacao_inventada');
    }

    // -----------------------------------------------------------------
    // RespostaDeCobranca
    // -----------------------------------------------------------------

    public function test_resposta_de_cobranca_aceita_situacao_valida_com_pago_em(): void
    {
        $pagoEm = CarbonImmutable::parse('2026-07-28');

        $resposta = new RespostaDeCobranca(
            idNoGateway: 'cob_123',
            situacao: RespostaDeCobranca::SITUACAO_PAGA,
            qrCodePix: '000201...copia-e-cola',
            pagoEm: $pagoEm,
        );

        $this->assertSame('paga', $resposta->situacao);
        $this->assertSame('000201...copia-e-cola', $resposta->qrCodePix);
        $this->assertTrue($pagoEm->equalTo($resposta->pagoEm));
        $this->assertNull($resposta->linkPagamento);
        $this->assertNull($resposta->linhaDigitavel);
    }

    public function test_resposta_de_cobranca_recusa_situacao_fora_do_vocabulario(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new RespostaDeCobranca('cob_123', 'situacao_inventada');
    }

    // -----------------------------------------------------------------
    // EventoDeGateway
    // -----------------------------------------------------------------

    public function test_evento_de_gateway_aceita_tipo_desconhecido_sem_lancar(): void
    {
        $evento = new EventoDeGateway('evt_1', EventoDeGateway::TIPO_DESCONHECIDO);

        $this->assertSame(EventoDeGateway::TIPO_DESCONHECIDO, $evento->tipo);
        $this->assertNull($evento->faturaIdNoGateway);
        $this->assertNull($evento->situacao);
        $this->assertNull($evento->pagoEm);
    }

    public function test_evento_de_gateway_aceita_cobranca_paga_com_situacao_e_pago_em(): void
    {
        $pagoEm = CarbonImmutable::parse('2026-07-28');

        $evento = new EventoDeGateway(
            eventoId: 'evt_2',
            tipo: EventoDeGateway::TIPO_COBRANCA_PAGA,
            faturaIdNoGateway: 'cob_123',
            situacao: RespostaDeCobranca::SITUACAO_PAGA,
            pagoEm: $pagoEm,
        );

        $this->assertSame('cob_123', $evento->faturaIdNoGateway);
        $this->assertSame('paga', $evento->situacao);
        $this->assertTrue($pagoEm->equalTo($evento->pagoEm));
    }

    public function test_evento_de_gateway_recusa_tipo_nao_mapeado(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EventoDeGateway('evt_1', 'tipo_que_nao_existe');
    }

    public function test_evento_de_gateway_recusa_situacao_fora_do_vocabulario_de_cobranca(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EventoDeGateway('evt_1', EventoDeGateway::TIPO_COBRANCA_PAGA, 'fat_1', 'situacao_inventada');
    }

    // -----------------------------------------------------------------
    // Binding: resolve pela configuração, não por classe fixa
    // -----------------------------------------------------------------

    public function test_binding_resolve_a_implementacao_apontada_pela_configuracao(): void
    {
        config(['services.gateway_assinatura' => GatewayAssinaturaDeMentira::class]);

        $this->assertInstanceOf(GatewayAssinaturaDeMentira::class, app(GatewayAssinatura::class));
    }
}

/**
 * Dublê mínimo de `GatewayAssinatura`, só para provar que o binding de
 * `AppServiceProvider::registrarGatewayDeAssinatura()` lê
 * `config('services.gateway_assinatura')` de verdade, em vez de apontar para
 * uma classe fixa. Nenhum método faz nada além de devolver um DTO válido: a
 * implementação de verdade é a Task 7.3.
 *
 * Fica neste arquivo, e não em `tests/Support/`, porque só este teste o usa.
 */
class GatewayAssinaturaDeMentira implements GatewayAssinatura
{
    public function registrarMeioDePagamento(Company $empresa, string $cartaoCifrado): void
    {
        //
    }

    public function criarAssinatura(Company $empresa, Plan $plano, string $formaPagamento): RespostaDeAssinatura
    {
        return new RespostaDeAssinatura('fake_sub', RespostaDeAssinatura::SITUACAO_ATIVA);
    }

    public function cancelarAssinatura(string $idNoGateway): void
    {
        //
    }

    public function trocarPlano(string $idNoGateway, Plan $novoPlano): RespostaDeAssinatura
    {
        return new RespostaDeAssinatura('fake_sub', RespostaDeAssinatura::SITUACAO_ATIVA);
    }

    public function gerarCobranca(Invoice $fatura): RespostaDeCobranca
    {
        return new RespostaDeCobranca('fake_cob', RespostaDeCobranca::SITUACAO_ABERTA);
    }

    public function consultarCobranca(string $idNoGateway): RespostaDeCobranca
    {
        return new RespostaDeCobranca('fake_cob', RespostaDeCobranca::SITUACAO_ABERTA);
    }

    public function validarWebhook(Request $requisicao): bool
    {
        return true;
    }

    public function interpretarWebhook(array $payload): EventoDeGateway
    {
        return new EventoDeGateway('fake_evt', EventoDeGateway::TIPO_DESCONHECIDO);
    }
}
