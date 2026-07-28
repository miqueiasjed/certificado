{{--
    Corpo em HTML do convite de usuário (Plano 8, Task 8.4).

    Estilo em atributo `style`, e não em folha de estilo, pelo mesmo motivo de
    `emails/notificacao.blade.php`: cliente de e-mail ignora `<style>` com
    frequência, e convite que chega sem formatação parece mensagem quebrada.

    O link aparece duas vezes de propósito: como botão e como URL escrita por
    extenso. Cliente de e-mail corporativo que bloqueia botão deixaria o
    convidado sem caminho nenhum, e é justamente ele quem não tem conta para
    pedir ajuda por dentro do sistema.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Convite para acessar o sistema de {{ $empresa }}</title>
</head>
<body style="margin:0; padding:0; background-color:#f3f4f6;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f3f4f6; padding:24px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:600px; background-color:#ffffff; border-radius:8px; border:1px solid #e5e7eb;">
                <tr>
                    <td style="background-color:#166534; border-radius:8px 8px 0 0; padding:20px 24px;">
                        <span style="color:#ffffff; font-family:Arial, Helvetica, sans-serif; font-size:18px; font-weight:bold;">
                            {{ $empresa }}
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="padding:24px; color:#111827; font-family:Arial, Helvetica, sans-serif; font-size:15px; line-height:24px;">
                        <p style="margin:0 0 16px 0;">
                            @if ($convidado)
                                Olá, {{ $convidado }}.
                            @else
                                Olá.
                            @endif
                        </p>
                        <p style="margin:0 0 16px 0;">
                            Você foi convidado a acessar o sistema de {{ $empresa }} com o papel
                            <strong>{{ $papel }}</strong>. Para começar, crie a sua senha pelo link abaixo.
                        </p>
                        <p style="margin:0 0 24px 0;">
                            <a href="{{ $link }}" style="background-color:#166534; border-radius:6px; color:#ffffff; display:inline-block; font-weight:bold; padding:12px 24px; text-decoration:none;">
                                Criar meu acesso
                            </a>
                        </p>
                        <p style="margin:0 0 16px 0; color:#6b7280; font-size:13px; line-height:20px;">
                            Se o botão não funcionar, copie e cole este endereço no navegador:<br>
                            <span style="word-break:break-all;">{{ $link }}</span>
                        </p>
                        <p style="margin:0; color:#6b7280; font-size:13px; line-height:20px;">
                            Este convite vale até {{ $validade }} e pode ser usado uma única vez.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="border-top:1px solid #e5e7eb; padding:16px 24px; color:#6b7280; font-family:Arial, Helvetica, sans-serif; font-size:12px; line-height:18px;">
                        Se você não esperava este convite, ignore esta mensagem: sem criar a senha, nenhum acesso é criado.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
