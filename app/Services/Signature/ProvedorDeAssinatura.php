<?php

namespace App\Services\Signature;

use App\Models\SignatureProviderConfig;
use App\Support\Signature\DocumentoNoProvedor;
use App\Support\Signature\SignatarioParaEnvio;
use DateTimeInterface;
use Illuminate\Http\Request;

/**
 * Contrato do provedor de assinatura eletrônica de contratos (Plano 26,
 * Task 26.2).
 *
 * Mesmo desenho de `App\Services\Payments\GatewayDeCobranca` (Plano 19), e
 * pelo mesmo motivo de negócio: a conta com o provedor é da **empresa**, não
 * da plataforma. A plataforma não assina contrato de ninguém. Por isso **todo**
 * método recebe a `SignatureProviderConfig` como primeiro parâmetro, em vez de
 * ela vir do construtor ou de configuração fixa: quem implementa esta interface
 * não pode fixar tenant nenhum na instância.
 *
 * ## Sem estado de tenant na instância
 *
 * A implementação concreta é reaproveitada entre chamadas de tenants
 * diferentes — é exatamente o que acontece na sincronização periódica da
 * Task 26.3, que percorre todas as empresas com pedido em aberto. Se a
 * credencial de um tenant ficasse guardada em propriedade da instância entre
 * uma chamada e outra, o contrato de uma empresa sairia para o e-mail do
 * cliente de outra, assinado com a conta errada. Nenhum método desta interface
 * pode ler nada além do `$configuracao` recebido no próprio parâmetro daquela
 * chamada.
 *
 * ## Erros
 *
 * Toda implementação traduz a resposta do provedor para uma exceção de domínio
 * em português, nunca deixa a exceção crua do cliente HTTP escapar:
 *
 * - `App\Exceptions\AssinaturaEletronicaCredencialInvalidaException` — a
 *   credencial do tenant foi recusada, ou não está configurada.
 * - `App\Exceptions\AssinaturaEletronicaSignatarioInvalidoException` — o
 *   provedor recusou por e-mail (ou telefone) de signatário inválido.
 * - `App\Exceptions\AssinaturaEletronicaArquivoGrandeDemaisException` — o PDF
 *   passa do limite aceito pelo provedor.
 * - `App\Exceptions\AssinaturaEletronicaDocumentoJaAssinadoException` — a
 *   operação não cabe porque o documento já está concluído.
 * - `App\Exceptions\AssinaturaEletronicaPrazoExpiradoException` — o prazo de
 *   assinatura já passou.
 * - `App\Exceptions\AssinaturaEletronicaRecusouException` — qualquer outra
 *   recusa 4xx, sem tradução mais específica. As quatro do meio estendem esta,
 *   então um `catch` genérico continua funcionando.
 * - `App\Exceptions\AssinaturaEletronicaIndisponivelException` — falha de rede
 *   ou erro 5xx: não se sabe se a operação aconteceu, e só este caso é seguro
 *   repetir depois.
 *
 * Nenhuma delas carrega a credencial do tenant, nem parcial.
 */
interface ProvedorDeAssinatura
{
    /**
     * Envia o PDF do contrato para assinatura, com a lista de signatários e o
     * prazo.
     *
     * @param  string  $nomeDoDocumento  Título que o signatário vê ao abrir o convite (ex.: "Contrato CONT-000042").
     * @param  string  $conteudoDoPdf  Bytes do PDF, crus. A implementação decide como transportá-los (a ZapSign recebe em base64); quem chama nunca codifica nada.
     * @param  array<int, SignatarioParaEnvio>  $signatarios  Quem assina, com papel e ordem. Lista vazia é recusada antes da rede.
     * @param  DateTimeInterface|string  $expiraEm  Último **dia** em que o documento aceita assinatura. Convertido para o fuso do negócio pela implementação; quem chama não formata data.
     * @param  string|null  $mensagem  Texto de acompanhamento no convite, quando o provedor suportar.
     * @param  string|null  $referenciaExterna  Identificador do pedido no domínio (ex.: "signature-request-42"), devolvido pelo provedor nos eventos.
     *
     * @throws \App\Exceptions\AssinaturaEletronicaCredencialInvalidaException
     * @throws \App\Exceptions\AssinaturaEletronicaSignatarioInvalidoException
     * @throws \App\Exceptions\AssinaturaEletronicaArquivoGrandeDemaisException
     * @throws \App\Exceptions\AssinaturaEletronicaRecusouException
     * @throws \App\Exceptions\AssinaturaEletronicaIndisponivelException
     */
    public function enviar(
        SignatureProviderConfig $configuracao,
        string $nomeDoDocumento,
        string $conteudoDoPdf,
        array $signatarios,
        DateTimeInterface|string $expiraEm,
        ?string $mensagem = null,
        ?string $referenciaExterna = null,
    ): DocumentoNoProvedor;

    /**
     * Situação atual do documento e de cada signatário.
     *
     * É a fonte de verdade do sistema sobre o pedido. O webhook (Task 26.3)
     * **não** decide nada sozinho: ele só avisa que algo mudou, e é esta
     * chamada, autenticada com a credencial do tenant, que diz o que mudou.
     * A sincronização periódica usa o mesmo método, para o contrato não ficar
     * preso em "em assinatura" quando um webhook se perde.
     *
     * @throws \App\Exceptions\AssinaturaEletronicaCredencialInvalidaException
     * @throws \App\Exceptions\AssinaturaEletronicaRecusouException
     * @throws \App\Exceptions\AssinaturaEletronicaIndisponivelException
     */
    public function consultar(SignatureProviderConfig $configuracao, string $idNoProvedor): DocumentoNoProvedor;

    /**
     * Manda o provedor avisar de novo quem ainda não assinou.
     *
     * **Não cria documento novo.** Dois documentos abertos do mesmo contrato
     * gerariam duas assinaturas válidas de textos possivelmente diferentes;
     * reenviar é sempre uma nova notificação do mesmo documento.
     *
     * @throws \App\Exceptions\AssinaturaEletronicaCredencialInvalidaException
     * @throws \App\Exceptions\AssinaturaEletronicaDocumentoJaAssinadoException
     * @throws \App\Exceptions\AssinaturaEletronicaPrazoExpiradoException
     * @throws \App\Exceptions\AssinaturaEletronicaRecusouException
     * @throws \App\Exceptions\AssinaturaEletronicaIndisponivelException
     */
    public function reenviar(SignatureProviderConfig $configuracao, string $idNoProvedor): DocumentoNoProvedor;

    /**
     * Cancela o documento no provedor, para que ele não aceite mais
     * assinatura.
     *
     * Não decide nada sobre `SignatureRequest` nem sobre `Contract`; quem
     * chama grava o resultado no domínio (Task 26.3). Documento já assinado não
     * é cancelável — o provedor recusa, e a recusa vira
     * `AssinaturaEletronicaDocumentoJaAssinadoException`.
     *
     * @param  string|null  $motivo  Texto registrado no provedor e no PDF marcado como cancelado.
     *
     * @throws \App\Exceptions\AssinaturaEletronicaCredencialInvalidaException
     * @throws \App\Exceptions\AssinaturaEletronicaDocumentoJaAssinadoException
     * @throws \App\Exceptions\AssinaturaEletronicaRecusouException
     * @throws \App\Exceptions\AssinaturaEletronicaIndisponivelException
     */
    public function cancelar(SignatureProviderConfig $configuracao, string $idNoProvedor, ?string $motivo = null): void;

    /**
     * Baixa o PDF assinado e devolve os bytes.
     *
     * Separado de `consultar()` de propósito: o link que o provedor devolve
     * expira (60 minutos na ZapSign), e o documento precisa continuar
     * acessível anos depois. Quem chama arquiva o retorno no ato
     * (`signature_requests.arquivo_assinado_path`, Task 26.3) e nunca guarda o
     * link como se fosse permanente.
     *
     * @return string Bytes do PDF assinado.
     *
     * @throws \App\Exceptions\AssinaturaEletronicaCredencialInvalidaException
     * @throws \App\Exceptions\AssinaturaEletronicaRecusouException
     * @throws \App\Exceptions\AssinaturaEletronicaIndisponivelException
     */
    public function baixarAssinado(SignatureProviderConfig $configuracao, string $idNoProvedor): string;

    /**
     * Confirma junto ao provedor que a credencial da configuração é válida,
     * com uma chamada sem efeito colateral (não cria nem altera nada).
     *
     * Devolve verdadeiro quando o provedor confirma. Devolve **falso**, sem
     * lançar, quando o provedor responde e diz que ela é inválida — o caso
     * comum de um tenant digitando o token errado, que não é uma situação
     * excepcional. Só falha de rede ou erro do lado do provedor vira
     * `AssinaturaEletronicaIndisponivelException`: aí não se sabe se a
     * credencial é válida, e devolver falso apagaria uma credencial certa por
     * causa de uma instabilidade do provedor.
     *
     * Quem chama grava `SignatureProviderConfig.verificado_em` quando o
     * retorno é verdadeiro (`ResolvedorDeProvedor::validar()`).
     *
     * @throws \App\Exceptions\AssinaturaEletronicaIndisponivelException
     */
    public function validarCredenciais(SignatureProviderConfig $configuracao): bool;

    /**
     * Confirma que a requisição de webhook realmente veio do provedor
     * (Task 26.3).
     *
     * Recebe `$configuracao` pelo mesmo motivo do resto da interface: quem
     * resolve qual tenant é este webhook é
     * `SignatureProviderConfig::paraToken()`, a partir do `webhookToken` da
     * URL, **antes** de chamar este método. Não interpreta o payload e não tem
     * efeito colateral.
     *
     * Ver o cabeçalho de `ProvedorPadrao::validarWebhook()` sobre o que
     * sustenta a autenticidade quando o provedor não assina a requisição.
     */
    public function validarWebhook(SignatureProviderConfig $configuracao, Request $requisicao): bool;

    /**
     * Identificador do documento a que o webhook se refere, ou `null` quando
     * o corpo não permite reconhecê-lo (Task 26.3).
     *
     * É a **única** coisa que o sistema lê do corpo de um webhook. Tudo o mais
     * — quem assinou, quando, de qual IP, se o documento está concluído — vem
     * de `consultar()`, autenticado com a credencial do tenant. Corpo de
     * webhook é dado de terceiro; usá-lo para decidir situação de contrato
     * seria deixar um POST alheio marcar contrato como assinado.
     *
     * @param  array<string, mixed>  $payload  Corpo do webhook, já decodificado.
     */
    public function identificarDocumentoNoWebhook(array $payload): ?string;

    /**
     * Nome do tipo do evento, para registro e diagnóstico (Task 26.3).
     * Nenhuma decisão de domínio depende dele.
     *
     * @param  array<string, mixed>  $payload
     */
    public function tipoDoEventoNoWebhook(array $payload): string;
}
