<?php

namespace App\Services\Notification;

use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

/**
 * Decide se um erro de envio vale ser repetido.
 *
 * Fica separado do driver de propósito: é a única regra deste plano que
 * precisa ser testada contra dezenas de formas de o provedor falhar, e nenhuma
 * delas dá para reproduzir com rede de verdade em suíte de teste. Aqui a
 * entrada é um `Throwable` construído à mão e a saída é um `ResultadoDeEnvio`,
 * sem banco, sem container e sem SMTP no meio.
 *
 * A regra da especificação
 * ------------------------
 * "4xx do provedor, endereço inválido e caixa inexistente são permanentes;
 * tempo limite, 5xx e limite de taxa são temporários."
 *
 * Esses números são de API HTTP (Mailgun, Postmark, SES, Resend), que é como o
 * envio sai em produção quando o transporte não é SMTP puro. Em SMTP os
 * mesmos dois algarismos significam o contrário: 4yz é falha transitória
 * ("tente de novo") e 5yz é falha permanente ("não insista"), inclusive o 550
 * de caixa inexistente. Aplicar a leitura de HTTP ao SMTP faria exatamente o
 * que a regra de negócio existe para impedir: repetir quatro vezes um envio
 * para endereço que não existe.
 *
 * Por isso a classificação é por significado, e o número só é lido depois de
 * saber de qual protocolo ele veio:
 *
 * 1. Endereço fora do formato (`RfcComplianceException`) -> permanente.
 * 2. Limite de taxa -> temporária, mesmo sendo 429, que é 4xx. A especificação
 *    trata limite de taxa como temporário, e é o que faz sentido: o provedor
 *    está dizendo "agora não", não "nunca".
 * 3. Texto de caixa inexistente ou destinatário recusado -> permanente,
 *    independente do código.
 * 4. `HttpTransportException` -> código HTTP do provedor: 4xx permanente,
 *    5xx temporária.
 * 5. `UnexpectedResponseException` -> código SMTP: 5yz permanente,
 *    4yz temporária.
 * 6. Tempo limite, conexão recusada, DNS, TLS -> temporária.
 * 7. Qualquer outra coisa -> temporária.
 *
 * O passo 7 é a escolha consciente do lado seguro. Erro que este classificador
 * não reconhece costuma ser problema nosso (configuração, falta de memória ao
 * gerar o PDF, provedor mudando de formato de resposta), e problema nosso é
 * corrigido enquanto as quatro tentativas acontecem. O teto de tentativas
 * garante que o item não fica preso repetindo para sempre.
 *
 * Utilitário determinístico: sem estado, sem banco e sem rede.
 */
final class ClassificadorDeFalhaDeEnvio
{
    /**
     * Sinais de que o destino não existe ou foi recusado de vez.
     *
     * Comparados em minúsculas contra a mensagem do erro. É a rede que pega o
     * provedor que devolve 200 com erro no corpo, ou que embrulha a resposta
     * SMTP em uma exceção genérica.
     *
     * @var array<int, string>
     */
    private const SINAIS_DE_DESTINO_INVALIDO = [
        'user unknown',
        'unknown user',
        'no such user',
        'no such recipient',
        'recipient rejected',
        'recipient address rejected',
        'invalid recipient',
        'address rejected',
        'mailbox unavailable',
        'mailbox not found',
        'mailbox does not exist',
        'does not exist',
        'user not found',
        'invalid email',
        'invalid address',
        'email address is not valid',
        'not a valid rfc',
        'blocked',
        'suppressed',
        'unsubscribed',
    ];

    /**
     * Sinais de limite de taxa. Vêm antes da leitura do código justamente
     * porque o 429 cairia na faixa 4xx e seria tratado como permanente.
     *
     * @var array<int, string>
     */
    private const SINAIS_DE_LIMITE_DE_TAXA = [
        'rate limit',
        'ratelimit',
        'too many requests',
        'too many messages',
        'throttl',
        'quota exceeded',
        'sending quota',
        'try again later',
        'temporarily deferred',
    ];

    /**
     * Sinais de problema de rede ou de servidor. Só são consultados quando não
     * há código utilizável, porque o texto do erro de conexão varia por
     * transporte.
     *
     * @var array<int, string>
     */
    private const SINAIS_DE_INDISPONIBILIDADE = [
        'timed out',
        'timeout',
        'time out',
        'connection could not be established',
        'could not connect',
        'connection refused',
        'connection reset',
        'connection closed',
        'network is unreachable',
        'temporary failure',
        'try again',
        'service not available',
        'service unavailable',
        'server busy',
        'unable to connect',
        'ssl',
        'tls',
        'certificate',
        'name or service not known',
        'getaddrinfo',
    ];

    /**
     * Classifica o erro lançado pelo transporte de e-mail.
     */
    public static function classificar(Throwable $erro): ResultadoDeEnvio
    {
        $mensagem = $erro->getMessage();
        $texto = mb_strtolower($mensagem);
        $resposta = self::respostaBruta($erro);

        // 1. Endereço que nem chega a ser um endereço. O Symfony lança isto
        // antes de abrir conexão, e nenhuma repetição conserta um "@" faltando.
        if ($erro instanceof RfcComplianceException) {
            return ResultadoDeEnvio::falhaPermanente(
                'Endereço de destino inválido: '.$mensagem
                .' Corrija o e-mail no cadastro do destinatário.',
                $resposta
            );
        }

        // 2. Limite de taxa antes de qualquer código: 429 é 4xx, e a regra do
        // plano manda tratar limite de taxa como temporário.
        if (self::contemAlgum($texto, self::SINAIS_DE_LIMITE_DE_TAXA)) {
            return ResultadoDeEnvio::falhaTemporaria(
                'O provedor de e-mail recusou por limite de envio no momento: '.$mensagem,
                $resposta
            );
        }

        // 3. Caixa inexistente ou destinatário recusado, dito com palavras.
        if (self::contemAlgum($texto, self::SINAIS_DE_DESTINO_INVALIDO)) {
            return ResultadoDeEnvio::falhaPermanente(
                'O provedor recusou o destinatário de forma definitiva: '.$mensagem
                .' Confira o e-mail no cadastro antes de tentar de novo.',
                $resposta
            );
        }

        // 4. API HTTP do provedor: aqui vale a leitura literal da
        // especificação, 4xx permanente e 5xx temporária.
        if ($erro instanceof HttpTransportException) {
            return self::porCodigoHttp(self::codigoHttp($erro) ?? $erro->getCode(), $mensagem, $resposta);
        }

        // 5. Resposta de SMTP: 5yz é recusa definitiva do servidor de e-mail,
        // 4yz é "tente de novo". Semântica do protocolo, invertida em relação
        // ao HTTP.
        if ($erro instanceof UnexpectedResponseException) {
            return self::porCodigoSmtp((int) $erro->getCode(), $mensagem, $resposta);
        }

        // 6. Rede, DNS, TLS, servidor fora do ar.
        if (self::contemAlgum($texto, self::SINAIS_DE_INDISPONIBILIDADE)) {
            return ResultadoDeEnvio::falhaTemporaria(
                'Falha temporária ao falar com o provedor de e-mail: '.$mensagem,
                $resposta
            );
        }

        // 7. Desconhecido. Repetir é o lado seguro, e o teto de tentativas
        // impede que o item fique repetindo para sempre.
        return ResultadoDeEnvio::falhaTemporaria(
            'Falha ao enviar o e-mail: '.$mensagem,
            $resposta
        );
    }

    /**
     * @param  array<string, mixed>  $resposta
     */
    private static function porCodigoHttp(int $codigo, string $mensagem, array $resposta): ResultadoDeEnvio
    {
        if ($codigo === 429) {
            return ResultadoDeEnvio::falhaTemporaria(
                'O provedor de e-mail respondeu 429 (limite de envio): '.$mensagem,
                $resposta
            );
        }

        if ($codigo >= 400 && $codigo <= 499) {
            return ResultadoDeEnvio::falhaPermanente(
                "O provedor de e-mail recusou a mensagem com o código {$codigo}: {$mensagem}"
                .' Erro de conteúdo, de endereço ou de credencial, que repetir não resolve.',
                $resposta
            );
        }

        if ($codigo >= 500 && $codigo <= 599) {
            return ResultadoDeEnvio::falhaTemporaria(
                "O provedor de e-mail respondeu {$codigo} (erro no lado dele): {$mensagem}",
                $resposta
            );
        }

        return ResultadoDeEnvio::falhaTemporaria(
            'Falha temporária ao falar com o provedor de e-mail: '.$mensagem,
            $resposta
        );
    }

    /**
     * @param  array<string, mixed>  $resposta
     */
    private static function porCodigoSmtp(int $codigo, string $mensagem, array $resposta): ResultadoDeEnvio
    {
        if ($codigo >= 500 && $codigo <= 599) {
            return ResultadoDeEnvio::falhaPermanente(
                "O servidor de e-mail recusou a mensagem em definitivo (SMTP {$codigo}): {$mensagem}"
                .' Confira o endereço do destinatário e o remetente da empresa.',
                $resposta
            );
        }

        if ($codigo >= 400 && $codigo <= 499) {
            return ResultadoDeEnvio::falhaTemporaria(
                "O servidor de e-mail pediu para tentar de novo (SMTP {$codigo}): {$mensagem}",
                $resposta
            );
        }

        return ResultadoDeEnvio::falhaTemporaria(
            'Resposta inesperada do servidor de e-mail: '.$mensagem,
            $resposta
        );
    }

    /**
     * Código HTTP da resposta guardada na exceção.
     *
     * `getStatusCode()` pode estourar quando a requisição nem chegou a ter
     * resposta, e nesse caso quem responde é o `getCode()` da exceção.
     */
    private static function codigoHttp(HttpTransportException $erro): ?int
    {
        try {
            return $erro->getResponse()->getStatusCode();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * O que fica gravado em `notification_logs.resposta_bruta`: a classe da
     * exceção, o código e a mensagem, que é o suficiente para diagnosticar
     * depois do fato sem guardar o corpo inteiro da resposta do provedor.
     *
     * @return array<string, mixed>
     */
    private static function respostaBruta(Throwable $erro): array
    {
        $resposta = [
            'excecao' => $erro::class,
            'codigo' => $erro->getCode(),
            'mensagem' => $erro->getMessage(),
        ];

        if ($erro->getPrevious() !== null) {
            $resposta['anterior'] = $erro->getPrevious()::class.': '.$erro->getPrevious()->getMessage();
        }

        return $resposta;
    }

    /**
     * @param  array<int, string>  $sinais
     */
    private static function contemAlgum(string $texto, array $sinais): bool
    {
        foreach ($sinais as $sinal) {
            if (str_contains($texto, $sinal)) {
                return true;
            }
        }

        return false;
    }
}
