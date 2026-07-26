<?php

namespace App\Services\Notification;

use App\Models\NotificationLog;
use InvalidArgumentException;

/**
 * O que aconteceu com uma tentativa de envio, do jeito que o despachante
 * precisa saber: deu certo, deu errado e vale repetir, ou deu errado e repetir
 * só piora.
 *
 * Existe como classe própria, e não como string solta devolvida pelo driver,
 * por dois motivos:
 *
 * 1. A distinção entre falha temporária e permanente é a decisão mais cara
 *    deste plano. Repetir e-mail para caixa inexistente quatro vezes derruba a
 *    reputação do remetente do tenant e leva junto a entrega de todos os outros
 *    avisos dele. Um tipo fechado impede que um driver novo invente um terceiro
 *    valor sem passar pelo despachante.
 * 2. A linha de `notification_logs` sai daqui inteira: resultado, mensagem em
 *    português e resposta do provedor como veio. O driver não escreve no banco,
 *    ele só descreve o que aconteceu.
 *
 * Imutável e sem dependência de framework: dá para montar e afirmar sobre ele
 * em teste sem banco, sem rede e sem container.
 */
final class ResultadoDeEnvio
{
    /**
     * @param  string  $resultado  Um dos `NotificationLog::RESULTADO_*`.
     * @param  string  $mensagem  Motivo em português, que é o que a tela mostra.
     * @param  array<string, mixed>  $respostaBruta  Resposta do provedor, para diagnóstico depois do fato.
     */
    private function __construct(
        public readonly string $resultado,
        public readonly string $mensagem,
        public readonly array $respostaBruta,
    ) {
        if (! in_array($resultado, NotificationLog::RESULTADOS, true)) {
            throw new InvalidArgumentException(
                "Resultado de envio desconhecido: \"{$resultado}\". "
                .'Resultados válidos: '.implode(', ', NotificationLog::RESULTADOS).'.'
            );
        }
    }

    /**
     * O provedor aceitou a mensagem.
     *
     * "Aceitou" é o máximo que o envio sabe afirmar: entrega na caixa do
     * destinatário e leitura não voltam por este caminho, e prometer isso no
     * histórico seria informação errada na tela.
     *
     * @param  array<string, mixed>  $respostaBruta
     */
    public static function sucesso(
        string $mensagem = 'Mensagem entregue ao provedor de envio.',
        array $respostaBruta = []
    ): self {
        return new self(NotificationLog::RESULTADO_SUCESSO, $mensagem, $respostaBruta);
    }

    /**
     * Tempo limite, indisponibilidade do provedor ou limite de taxa: cabe
     * repetir mais tarde, com a espera crescente do despachante.
     *
     * @param  array<string, mixed>  $respostaBruta
     */
    public static function falhaTemporaria(string $mensagem, array $respostaBruta = []): self
    {
        return new self(NotificationLog::RESULTADO_FALHA_TEMPORARIA, $mensagem, $respostaBruta);
    }

    /**
     * Endereço inválido, caixa inexistente ou recusa definitiva do provedor:
     * repetir não muda o resultado e ainda queima a reputação do remetente.
     *
     * @param  array<string, mixed>  $respostaBruta
     */
    public static function falhaPermanente(string $mensagem, array $respostaBruta = []): self
    {
        return new self(NotificationLog::RESULTADO_FALHA_PERMANENTE, $mensagem, $respostaBruta);
    }

    public function ehSucesso(): bool
    {
        return $this->resultado === NotificationLog::RESULTADO_SUCESSO;
    }

    public function ehFalhaTemporaria(): bool
    {
        return $this->resultado === NotificationLog::RESULTADO_FALHA_TEMPORARIA;
    }

    public function ehFalhaPermanente(): bool
    {
        return $this->resultado === NotificationLog::RESULTADO_FALHA_PERMANENTE;
    }
}
