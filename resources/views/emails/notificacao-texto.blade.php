{{--
    Versão em texto puro dos avisos da central de notificações.

    Sai sem escape (`{!! !!}`) de propósito: este bloco vira o corpo
    `text/plain` da mensagem, onde não existe marcação para interpretar. Com o
    escape do Blade, o apóstrofo de um endereço como "São Murilo d'Oeste"
    chegaria ao cliente como "d&#039;Oeste", que é lixo visível no lugar de
    texto. O conteúdo é o corpo já renderizado do item da fila, e o risco de
    injeção que o escape existe para evitar é de HTML, não de texto simples.
--}}
{!! $corpo !!}
