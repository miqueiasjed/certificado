<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * O e-mail de um item da central de notificações.
 *
 * Mailable único para os dez eventos do plano, e não um por evento, porque o
 * texto já vem pronto do item da fila: assunto e corpo foram renderizados pelo
 * `NotificationService` a partir do template do tenant ou do texto padrão do
 * catálogo. Um Mailable por evento só duplicaria o mesmo envelope dez vezes e
 * criaria a chance de o texto da tela divergir do texto que sai.
 *
 * O que este Mailable decide, portanto, é só a embalagem:
 *
 * - **Remetente do tenant**, recebido pronto do driver. O cliente final conhece
 *   a dedetizadora, não a plataforma, e e-mail chegando com nome de sistema
 *   desconhecido é lido como golpe ou vai direto para spam.
 * - **Reply-to da empresa**, para que a resposta do cliente chegue a quem pode
 *   responder. Sem isto a resposta cai no endereço técnico de envio, onde
 *   ninguém lê.
 * - **Anexo em memória**: o PDF da OS é gerado na hora pelo driver e entra como
 *   bytes. Nada é gravado em disco, e por isso não existe arquivo temporário
 *   para limpar depois nem documento do cliente esquecido no servidor.
 *
 * Sem `ShouldQueue` de propósito: a fila deste plano é a tabela
 * `notification_queue`, varrida pelo despachante a cada 5 minutos. Colocar o
 * Mailable também na fila do Laravel criaria uma segunda fila em cima da
 * primeira, com dois lugares para uma mensagem ficar presa e nenhum histórico
 * no segundo.
 */
class NotificacaoDaFila extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $assuntoDaMensagem  Assunto já renderizado.
     * @param  string  $corpoDaMensagem  Corpo já renderizado, em texto simples com quebras de linha.
     * @param  string  $remetenteEmail  Endereço de quem envia (empresa dona do item).
     * @param  string  $remetenteNome  Nome de quem envia, exibido na caixa do destinatário.
     * @param  string|null  $responderPara  Endereço de resposta, quando a empresa tem e-mail próprio.
     * @param  array<int, array{nome: string, conteudo: string, mime: string}>  $documentos  Anexos em memória.
     */
    public function __construct(
        private readonly string $assuntoDaMensagem,
        private readonly string $corpoDaMensagem,
        private readonly string $remetenteEmail,
        private readonly string $remetenteNome,
        private readonly ?string $responderPara = null,
        private readonly array $documentos = [],
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->remetenteEmail, $this->remetenteNome),
            replyTo: $this->responderPara === null
                ? []
                : [new Address($this->responderPara, $this->remetenteNome)],
            subject: $this->assuntoDaMensagem,
        );
    }

    /**
     * Versão em HTML e versão em texto puro da mesma mensagem.
     *
     * As duas juntas não são capricho: mensagem só em HTML perde pontos em
     * filtro de spam, e aviso de visita que cai no spam é aviso que não
     * aconteceu.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.notificacao',
            text: 'emails.notificacao-texto',
            with: [
                'corpo' => $this->corpoDaMensagem,
                'assunto' => $this->assuntoDaMensagem,
                'empresa' => $this->remetenteNome,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return array_map(
            fn (array $documento): Attachment => Attachment::fromData(
                fn (): string => $documento['conteudo'],
                $documento['nome']
            )->withMime($documento['mime']),
            $this->documentos
        );
    }
}
