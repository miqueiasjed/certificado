<?php

namespace App\Support;

use RuntimeException;

/**
 * Código público do dispositivo: o texto que vai impresso na etiqueta e dentro
 * do QR code colado no ponto de monitoramento.
 *
 * Por que não é o `id`
 * --------------------
 * Sequência interna exposta conta quantos dispositivos a empresa tem e deixa
 * qualquer um trocar o número na URL para ler o dispositivo do vizinho. O
 * código é sorteado justamente para não ter ordem nem vizinhança.
 *
 * Alfabeto sem caractere ambíguo
 * ------------------------------
 * `I`, `O`, `0` e `1` ficam de fora. Quando a etiqueta está suja, molhada ou
 * amassada e a câmera não lê o QR code, o técnico digita o código na mão, e
 * esses quatro caracteres são exatamente os que ele erra. Tirar os quatro custa
 * pouca entropia e evita o chamado de suporte.
 *
 * Espaço de códigos: 32^10, cerca de 1,1 quatrilhão de combinações. Numa base
 * com centenas de milhares de dispositivos por empresa a chance de colisão em
 * um sorteio ainda é desprezível, e mesmo assim quem grava confere no banco
 * antes (ver `Device::booted()`), porque a garantia real de unicidade é a
 * unique composta `[company_id, codigo_publico]`.
 *
 * Imutabilidade
 * -------------
 * Código gerado nunca é trocado: a etiqueta já foi impressa e colada no local.
 * Reetiquetar um ponto é substituição de dispositivo, com registro próprio, e
 * não alteração do código. Por isso nada aqui sobrescreve código já preenchido.
 *
 * Utilitário de infraestrutura: sem acesso a banco e sem regra de domínio além
 * do formato do código.
 */
final class CodigoDeDispositivo
{
    /**
     * Alfabeto do código, sem os caracteres que o técnico confunde ao digitar
     * (`I`, `O`, `0`, `1`). São 32 símbolos: 24 letras e 8 dígitos.
     */
    public const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Tamanho fixo do código. Dez caracteres cabem na etiqueta e são legíveis a
     * olho nu; a coluna `devices.codigo_publico` tem 12 de folga.
     */
    public const TAMANHO = 10;

    /**
     * Tentativas de sorteio antes de desistir, quando quem chama exige um
     * código inédito. Com o espaço de códigos disponível, cinco sorteios
     * seguidos batendo em código já usado não é colisão: é sinal de que a
     * checagem recebida está errada (consultando a empresa errada, por
     * exemplo). Falhar alto é melhor do que girar em laço infinito no meio de
     * uma criação de dispositivo.
     */
    public const TENTATIVAS = 5;

    /**
     * Sorteia um código novo, sem consultar nada.
     *
     * `random_int` e não `rand`/`mt_rand`: o código é o que dá acesso à leitura
     * pública do dispositivo, então precisa ser imprevisível, e não apenas
     * variado.
     */
    public static function gerar(): string
    {
        $ultimoIndice = strlen(self::ALFABETO) - 1;
        $codigo = '';

        for ($posicao = 0; $posicao < self::TAMANHO; $posicao++) {
            $codigo .= self::ALFABETO[random_int(0, $ultimoIndice)];
        }

        return $codigo;
    }

    /**
     * Sorteia até obter um código que a checagem informada considere inédito.
     *
     * A checagem fica de fora de propósito: quem sabe consultar o banco é o
     * model, e este utilitário continua sem tocar em banco. O laço, o limite de
     * tentativas e a mensagem de erro vivem aqui para não se repetirem em cada
     * chamador.
     *
     * @param  callable(string): bool  $jaExiste  Recebe o código sorteado e devolve `true` se ele já estiver em uso.
     *
     * @throws RuntimeException Quando todas as tentativas caíram em código já usado.
     */
    public static function gerarInedito(callable $jaExiste, int $tentativas = self::TENTATIVAS): string
    {
        for ($tentativa = 1; $tentativa <= $tentativas; $tentativa++) {
            $codigo = self::gerar();

            if (! $jaExiste($codigo)) {
                return $codigo;
            }
        }

        throw new RuntimeException(sprintf(
            'Não foi possível gerar um código público inédito para o dispositivo em %d tentativa(s). '
            .'Com %s combinações possíveis isso não é colisão por acaso: confira se a checagem de '
            .'unicidade está consultando a empresa certa.',
            $tentativas,
            number_format(strlen(self::ALFABETO) ** self::TAMANHO, 0, ',', '.')
        ));
    }

    /**
     * O código está no formato esperado?
     *
     * Serve à leitura pública do QR code para recusar entrada malformada antes
     * de consultar o banco: código com tamanho errado, com caractere fora do
     * alfabeto ou com um dos ambíguos (`I`, `O`, `0`, `1`) nunca existiu, então
     * não há o que procurar.
     *
     * Compara a forma canônica, em maiúsculas e sem separador. Entrada digitada
     * por gente passa antes por `normalizar()`.
     */
    public static function valido(string $codigo): bool
    {
        if (strlen($codigo) !== self::TAMANHO) {
            return false;
        }

        return strspn($codigo, self::ALFABETO) === self::TAMANHO;
    }

    /**
     * Forma canônica do que foi digitado: maiúsculas, sem espaço e sem os
     * separadores que a pessoa costuma inserir ao copiar da etiqueta (hífen,
     * ponto, barra).
     *
     * Não corrige caractere ambíguo: `0` não vira `O`, porque nenhum código tem
     * `O`. Trocar caractere aqui esconderia um erro de digitação e devolveria o
     * dispositivo errado, que é pior do que dizer que o código não existe.
     */
    public static function normalizar(?string $codigo): string
    {
        return str_replace([' ', '-', '.', '/', '_'], '', mb_strtoupper(trim((string) $codigo)));
    }
}
