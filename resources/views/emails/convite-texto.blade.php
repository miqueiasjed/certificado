{{--
    Versão em texto puro do convite de usuário (Plano 8, Task 8.4).

    Acompanha `emails/convite.blade.php` na mesma mensagem: e-mail só em HTML
    perde pontos em filtro de spam, e convite que cai no spam é convite que não
    aconteceu.
--}}
@if ($convidado)
Olá, {{ $convidado }}.
@else
Olá.
@endif

Você foi convidado a acessar o sistema de {{ $empresa }} com o papel {{ $papel }}.

Para criar a sua senha e começar, abra este endereço:
{{ $link }}

Este convite vale até {{ $validade }} e pode ser usado uma única vez.

Se você não esperava este convite, ignore esta mensagem: sem criar a senha, nenhum acesso é criado.
