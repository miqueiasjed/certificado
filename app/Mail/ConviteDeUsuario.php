<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * O e-mail que leva o link do convite (Plano 8, Task 8.4).
 *
 * Mailable simples e provisório: a central de notificações do Plano 14 é quem
 * vai cuidar de assunto, template por tenant e histórico de envio. Enquanto ela
 * não existe, o convite sai por aqui. O ponto de troca está anotado em
 * `InvitationService::enviarEmail()`.
 *
 * Duas escolhas de embalagem, as mesmas de `NotificacaoDaFila`:
 *
 * - **Remetente do tenant**: quem recebe o convite conhece a dedetizadora, não
 *   a plataforma. E-mail chegando com nome de sistema desconhecido é lido como
 *   golpe.
 * - **Versão em texto puro junto da versão em HTML**: mensagem só em HTML perde
 *   pontos em filtro de spam, e convite que cai no spam é convite que não
 *   aconteceu.
 *
 * Sem `ShouldQueue`: o envio acontece dentro da requisição que criou o convite,
 * e a falha dele já é tratada (o convite fica gravado e o link é copiável da
 * tela). Uma fila aqui só criaria um segundo lugar para a mensagem sumir.
 */
class ConviteDeUsuario extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $nomeDaEmpresa  Empresa que convidou, exibida no corpo.
     * @param  string  $papel  Papel que o convidado recebe ao aceitar.
     * @param  string  $link  URL pública de aceite, com o token.
     * @param  string  $validade  Último dia de validade, já formatado no fuso do negócio (dd/mm/aaaa).
     * @param  string  $remetenteEmail  Endereço de quem envia.
     * @param  string  $remetenteNome  Nome exibido na caixa do destinatário.
     * @param  string|null  $responderPara  Endereço de resposta, quando a empresa tem e-mail próprio.
     * @param  string|null  $nomeDoConvidado  Nome informado no convite, quando houver.
     */
    public function __construct(
        private readonly string $nomeDaEmpresa,
        private readonly string $papel,
        private readonly string $link,
        private readonly string $validade,
        private readonly string $remetenteEmail,
        private readonly string $remetenteNome,
        private readonly ?string $responderPara = null,
        private readonly ?string $nomeDoConvidado = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address($this->remetenteEmail, $this->remetenteNome),
            replyTo: $this->responderPara === null
                ? []
                : [new Address($this->responderPara, $this->remetenteNome)],
            subject: "Convite para acessar o sistema de {$this->nomeDaEmpresa}",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.convite',
            text: 'emails.convite-texto',
            with: [
                'empresa' => $this->nomeDaEmpresa,
                'papel' => $this->papel,
                'link' => $this->link,
                'validade' => $this->validade,
                'convidado' => $this->nomeDoConvidado,
            ],
        );
    }
}
