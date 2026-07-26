<?php

namespace App\Services\Notification;

use App\Support\BusinessDate;
use DateTimeInterface;
use Illuminate\Support\Facades\Log;
use Stringable;

/**
 * Troca as variáveis `{{...}}` do template pelo valor real.
 *
 * Duas decisões governam esta classe:
 *
 * 1. **Renderização nunca quebra o envio.** Variável que o template usa e o
 *    disparo não forneceu vira string vazia e um `Log::warning` com o nome dela
 *    e a lista do que estava disponível. O template é editado pelo tenant, em
 *    campo livre, e um erro de digitação lá não pode derrubar a fila inteira de
 *    avisos. O aviso em log é o que permite descobrir o erro sem esperar o
 *    cliente reclamar. A validação que recusa variável inexistente acontece na
 *    hora de salvar o template (Task 14.6), que é onde dá para mostrar o erro
 *    para quem o cometeu.
 *
 * 2. **Data nunca chega crua ao texto.** Todo valor de data e hora passa por
 *    `BusinessDate`, e sai em `dd/mm/aaaa` (dia) ou `dd/mm/aaaa HH:mm`
 *    (instante), sempre no fuso do negócio. Deixar o valor bruto vazar
 *    imprimiria `2026-07-26T00:00:00.000000Z` no e-mail do cliente, ou pior, a
 *    data de ontem para quem lê à noite.
 *
 * Utilitário sem estado: só transforma texto, sem acesso a banco.
 */
final class RenderizadorDeTemplate
{
    /**
     * `{{nome_da_variavel}}`, com espaço opcional dentro das chaves.
     *
     * Só letras, números e sublinhado no nome: assim um `{{` solto no texto, ou
     * uma chave com conteúdo estranho, é deixado como está em vez de virar
     * substituição silenciosa.
     */
    private const PADRAO_VARIAVEL = '/\{\{\s*([A-Za-z0-9_]+)\s*\}\}/';

    /**
     * Substitui as variáveis do corpo pelos valores informados.
     *
     * @param  array<string, mixed>  $variaveis
     */
    public static function render(string $corpo, array $variaveis): string
    {
        $renderizado = preg_replace_callback(
            self::PADRAO_VARIAVEL,
            static function (array $ocorrencia) use ($variaveis): string {
                $nome = $ocorrencia[1];

                if (! array_key_exists($nome, $variaveis)) {
                    Log::warning('Variável desconhecida em template de notificação.', [
                        'variavel' => $nome,
                        'disponiveis' => array_keys($variaveis),
                    ]);

                    return '';
                }

                return self::formatar($nome, $variaveis[$nome]);
            },
            $corpo
        );

        // `preg_replace_callback` devolve null só em erro de execução da regex
        // (estouro de backtracking, por exemplo). Nesse caso o texto original é
        // melhor que uma mensagem vazia saindo para o cliente.
        return $renderizado ?? $corpo;
    }

    /**
     * Nomes das variáveis usadas no texto, sem repetição.
     *
     * Serve à validação de template da Task 14.6, que compara esta lista com as
     * variáveis do evento no catálogo e recusa o que não existe.
     *
     * @return array<int, string>
     */
    public static function variaveisUsadas(string $corpo): array
    {
        preg_match_all(self::PADRAO_VARIAVEL, $corpo, $encontradas);

        return array_values(array_unique($encontradas[1] ?? []));
    }

    /**
     * Converte o valor da variável em texto pronto para a mensagem.
     *
     * String que representa dia (`aaaa-mm-dd`) ou instante (`aaaa-mm-dd HH:mm`)
     * é tratada como data e formatada. A leitura é a de que a string veio do
     * próprio sistema já no fuso do negócio: quem tem o instante em mãos deve
     * passar o `Carbon` do cast, que carrega o fuso e não deixa dúvida.
     */
    private static function formatar(string $nome, mixed $valor): string
    {
        if ($valor === null) {
            return '';
        }

        if (is_bool($valor)) {
            return $valor ? 'sim' : 'não';
        }

        if ($valor instanceof DateTimeInterface) {
            return self::formatarData($valor);
        }

        if (is_string($valor) && self::pareceData($valor)) {
            return self::formatarData($valor);
        }

        if (is_string($valor) || is_int($valor) || is_float($valor)) {
            return (string) $valor;
        }

        if ($valor instanceof Stringable || (is_object($valor) && method_exists($valor, '__toString'))) {
            return (string) $valor;
        }

        // Array ou objeto sem representação em texto: converter aqui só geraria
        // "Array" no meio da mensagem do cliente.
        Log::warning('Valor não convertível para texto em template de notificação.', [
            'variavel' => $nome,
            'tipo' => get_debug_type($valor),
        ]);

        return '';
    }

    /**
     * Dia no fuso do negócio sai `dd/mm/aaaa`; instante sai com hora e minuto.
     */
    private static function formatarData(DateTimeInterface|string $valor): string
    {
        $data = BusinessDate::paraFusoNegocio($valor);

        if ($data === null) {
            return '';
        }

        $ehDiaPuro = $data->hour === 0 && $data->minute === 0 && $data->second === 0;

        return $data->format($ehDiaPuro ? 'd/m/Y' : 'd/m/Y H:i');
    }

    /**
     * A string é uma data no formato que o sistema grava e trafega?
     */
    private static function pareceData(string $valor): bool
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}([ T]\d{2}:\d{2}(:\d{2})?)?$/', trim($valor)) === 1;
    }
}
