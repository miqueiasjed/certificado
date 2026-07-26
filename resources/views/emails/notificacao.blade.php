{{--
    Corpo em HTML dos avisos da central de notificações.

    Estilo em atributo `style`, e não em folha de estilo: cliente de e-mail
    ignora `<style>` com frequência (o Gmail em conta corporativa é o caso mais
    comum), e aviso que chega sem formatação nenhuma parece mensagem quebrada.

    O texto vem pronto do item da fila, em texto simples. `e()` escapa o que o
    template do tenant escreveu antes de o `nl2br` recolocar as quebras de
    linha: sem essa ordem, um `<` digitado no template sairia como marcação.
--}}
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $assunto }}</title>
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
                        {!! nl2br(e($corpo)) !!}
                    </td>
                </tr>
                <tr>
                    <td style="border-top:1px solid #e5e7eb; padding:16px 24px; color:#6b7280; font-family:Arial, Helvetica, sans-serif; font-size:12px; line-height:18px;">
                        Mensagem automática enviada por {{ $empresa }}. Para falar com a equipe, responda a este e-mail.
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
