<?php

namespace App\Exceptions;

use App\Models\PersonalProtectiveEquipment;
use App\Support\BusinessDate;
use RuntimeException;
use Throwable;

/**
 * Recusa da entrega de um EPI cujo Certificado de Aprovação está vencido
 * (Plano 28, Task 28.2).
 *
 * Bloqueio de conformidade, não preferência de operação: entregar EPI com CA
 * vencido é a própria infração que a ficha de entrega deveria provar que não
 * aconteceu. Deixar passar produziria um registro que, em fiscalização, funciona
 * como confissão — a empresa teria assinado que entregou equipamento sem
 * certificado válido.
 *
 * A recusa é do escritório, não do campo
 * --------------------------------------
 * Quem corrige o CA é quem cuida do cadastro, e por isso a mensagem precisa
 * dizer **qual** CA, **qual** data de validade e **onde** se corrige. Recusa sem
 * caminho de correção é o que faz o usuário burlar o cadastro: sem saber o que
 * arrumar, ele apaga a validade do EPI (campo em branco é estado neutro e passa)
 * e o sistema perde justamente o dado que existia para proteger o técnico.
 *
 * A comparação é por dia no fuso do negócio
 * -----------------------------------------
 * `BusinessDate::estaVencido()`, nunca `now()` em UTC, que daria o certificado
 * como vencido a partir das 21h de Brasília do dia anterior. CA que vence hoje
 * ainda vale hoje.
 *
 * Por que classe própria, e não erro genérico: os dados brutos ficam em
 * propriedades para quem trata a exceção montar a resposta sem reprocessar a
 * mensagem, mesmo critério de `LoteVencidoException` e
 * `QuilometragemRetroativaException`.
 */
class CaVencidoException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly ?int $personalProtectiveEquipmentId = null,
        public readonly ?string $nomeDoEpi = null,
        public readonly ?string $numeroCa = null,
        /** Validade do CA, em `Y-m-d`, no fuso do negócio. */
        public readonly ?string $validadeCa = null,
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $code, $previous);
    }

    /**
     * Recusa citando o EPI, o número do CA, a validade e onde se corrige.
     */
    public static function para(PersonalProtectiveEquipment $epi): self
    {
        $validade = BusinessDate::paraFusoNegocio($epi->validade_ca)?->startOfDay();
        $numero = trim((string) $epi->numero_ca);

        $mensagem = sprintf(
            'O EPI "%s" está com o Certificado de Aprovação %s vencido desde %s e não pode ser entregue. '
            .'Atualize o número do CA e a validade no cadastro do EPI (Cadastros > EPI) antes de registrar a entrega.',
            $epi->nome,
            $numero !== '' ? 'nº '.$numero : '(número não informado)',
            $validade?->format('d/m/Y') ?? 'data não informada'
        );

        return new self(
            $mensagem,
            $epi->getKey(),
            (string) $epi->nome,
            $numero !== '' ? $numero : null,
            $validade?->toDateString()
        );
    }

    /**
     * Há quantos dias o CA está vencido, contado do dia de hoje no fuso do
     * negócio.
     *
     * Zero quando não há validade registrada. Serve para a tela separar "venceu
     * ontem" de "venceu há dois anos", que são conversas diferentes com o
     * usuário.
     */
    public function diasVencido(): int
    {
        $validade = BusinessDate::paraFusoNegocio($this->validadeCa);

        if ($validade === null) {
            return 0;
        }

        return (int) $validade->startOfDay()->diffInDays(BusinessDate::hoje(), false);
    }
}
