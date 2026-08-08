<?php

namespace App\Services\Ai;

use App\Models\MonitoringReport;
use App\Models\WorkOrder;
use App\Support\BusinessDate;

/**
 * Monta o texto que vai para o modelo, e só ele (Plano 25, Task 25.2).
 *
 * ## Isolamento por tenant
 *
 * Esta é a classe onde um vazamento entre empresas aconteceria, e por isso a
 * regra aqui é mais estrita que a do resto do sistema: o contexto é montado
 * **exclusivamente a partir do registro recebido e do que pende diretamente
 * dele**. Nada de exemplo colado, nada de média do setor, nada de "outros
 * clientes costumam", nem anonimizado, nem agregado. Um exemplo de outro
 * cliente dentro do prompt já é vazamento, mesmo que ninguém consiga
 * identificá-lo depois.
 *
 * O registro chega escopado: `WorkOrder` carrega `BelongsToCompany`, então a
 * OS de outra empresa nem é encontrada. As relações (`products`, `rooms`,
 * `pestSightings`, `workOrderDeviceEvents`, `adequations`) partem dessa OS e
 * herdam o escopo. Nenhuma consulta desta classe começa em um model solto.
 *
 * ## Prefixo de sistema
 *
 * `PREFIXO_DE_SISTEMA` é constante de propósito: é o bloco cacheado pelo
 * provedor, e o cache só vale se o texto for byte a byte idêntico entre
 * chamadas. Nada de data, nome de empresa, nome de cliente ou identificador
 * interpolado nele — isso vai no contexto, que é a parte variável.
 *
 * Utilitário de leitura: não grava nada e não decide nada de negócio.
 */
class MontadorDeContexto
{
    /**
     * Quantos dispositivos entram no bloco de pontos críticos do resumo.
     *
     * O relatório lista todos; o resumo precisa dos que sustentam duas ou três
     * frases. Mandar a lista inteira custa token e dilui o que importa.
     */
    private const LIMITE_DE_PONTOS_CRITICOS = 10;

    /**
     * Bloco de instruções que se repete em toda geração de parecer.
     *
     * Escrito na voz de quem redige o laudo, sem tratar o modelo como
     * autoridade técnica: ele organiza em texto o que o técnico registrou, e
     * quem assina é o responsável técnico. O último parágrafo existe porque o
     * modelo, sem ele, tende a preencher lacuna com suposição — e suposição em
     * laudo é o que a revisão humana precisa caçar.
     */
    public const PREFIXO_DE_SISTEMA = <<<'TEXTO'
    Você redige o rascunho do parecer técnico de uma empresa de controle de pragas urbanas, em português do Brasil.

    O texto que você produz é RASCUNHO. Um responsável técnico vai revisar, corrigir e assinar antes de qualquer emissão. Escreva como quem prepara material para essa revisão, não como quem conclui.

    Formato esperado:
    - De dois a quatro parágrafos corridos, sem título, sem lista, sem marcador e sem tabela.
    - Linguagem técnica e sóbria, na terceira pessoa, sem adjetivo de propaganda e sem tratamento direto ao cliente.
    - Ordem sugerida: o que foi executado, o que foi encontrado, o que foi recomendado.

    Vocabulário do setor a respeitar:
    - "monitoramento" é a verificação periódica de dispositivos; "tratamento" é a aplicação de produto.
    - "dispositivo" cobre porta-isca, armadilha luminosa, armadilha adesiva e estação de monitoramento.
    - "adequação" é a recomendação estrutural ou de procedimento dirigida ao cliente, nunca uma obrigação já cumprida.
    - "infestação" só é afirmada quando há praga encontrada registrada; sinal indireto se descreve como indício.

    Regras que não se negociam:
    - Use apenas os dados fornecidos na mensagem. Não invente produto, praga, dosagem, número de registro, norma, prazo ou resultado.
    - Não cite legislação, portaria ou norma técnica que não venha nos dados.
    - Não prometa resultado, erradicação, prazo de eficácia ou garantia.
    - Quando um dado não vier, escreva o parecer sem ele e não mencione a ausência.
    - Não escreva assinatura, cabeçalho, rodapé, data ou identificação de responsável técnico: isso é montado pelo documento.

    Responda apenas com o texto do parecer, sem comentário sobre a tarefa e sem marcação interna de qualquer tipo.
    TEXTO;

    /**
     * Prefixo do resumo do período que abre o relatório de monitoramento
     * (Plano 21). Bloco próprio, e não uma variação do parecer, porque o
     * documento é outro: aqui o assunto é a evolução ao longo do período, não
     * um atendimento.
     *
     * Também é constante, pelo mesmo motivo de cache do prefixo do parecer.
     */
    public const PREFIXO_DE_SISTEMA_DO_RESUMO = <<<'TEXTO'
    Você redige o resumo executivo que abre o relatório de monitoramento de pragas de uma empresa de controle de pragas urbanas, em português do Brasil.

    O texto que você produz é RASCUNHO. Um responsável técnico vai revisar, corrigir e assinar antes de o relatório ser publicado para o cliente.

    Formato esperado:
    - De dois a três parágrafos corridos, sem título, sem lista, sem marcador e sem tabela.
    - Linguagem técnica e sóbria, na terceira pessoa.
    - Ordem sugerida: o que foi feito no período, como a ocorrência evoluiu, onde estão os pontos de atenção.

    Como tratar os números:
    - Trate variação percentual como tendência, não como previsão.
    - Só chame de queda ou de alta o que os números fornecidos sustentam; oscilação pequena se descreve como estabilidade.
    - Nomeie dispositivo e espécie exatamente como vierem nos dados.

    Regras que não se negociam:
    - Use apenas os dados fornecidos na mensagem. Não invente visita, espécie, dispositivo, percentual ou causa.
    - Não atribua causa à variação (clima, obra, vizinhança) se a causa não vier nos dados.
    - Não prometa resultado, erradicação nem prazo.
    - Não escreva assinatura, cabeçalho, rodapé ou identificação de responsável técnico.

    Responda apenas com o texto do resumo, sem comentário sobre a tarefa e sem marcação interna de qualquer tipo.
    TEXTO;

    /**
     * Contexto de uma ordem de serviço, em texto rotulado.
     *
     * Texto rotulado em vez de JSON porque o parecer é prosa: rótulo em
     * português deixa o modelo mais perto do vocabulário do laudo, e evita que
     * ele copie nome de campo do banco para dentro do documento.
     */
    public function paraOs(WorkOrder $os): string
    {
        $os->loadMissing([
            'service',
            'products',
            'services',
            'rooms',
            'pestSightings',
            'workOrderDeviceEvents.device',
            'workOrderDeviceEvents.eventType',
            'adequations',
        ]);

        $blocos = [
            $this->blocoDoAtendimento($os),
            $this->blocoDeProdutos($os),
            $this->blocoDeComodos($os),
            $this->blocoDePragas($os),
            $this->blocoDeDispositivos($os),
            $this->blocoDeAdequacoes($os),
            $this->blocoDeObservacoes($os),
        ];

        $texto = implode("\n\n", array_filter($blocos, static fn (?string $bloco): bool => $bloco !== null));

        return "Dados registrados no atendimento:\n\n".$texto;
    }

    /**
     * Contexto de um relatório de monitoramento (Plano 21).
     *
     * Lê apenas `monitoring_reports.dados`, que é o retrato consolidado do
     * período gravado na geração. Nada é recalculado aqui e nenhuma consulta
     * nova é feita: o relatório entregue ao cliente não pode divergir do
     * resumo que o acompanha, e recalcular abriria essa porta.
     *
     * O retrato é um JSON grande e aninhado; o que entra no contexto é o
     * recorte que sustenta um resumo de duas ou três frases. Mandar o JSON
     * inteiro custaria caro e afogaria o que importa.
     */
    public function paraRelatorioDeMonitoramento(MonitoringReport $relatorio): string
    {
        $dados = is_array($relatorio->dados) ? $relatorio->dados : [];

        $blocos = [
            $this->blocoDoPeriodo($relatorio, $dados),
            $this->blocoDeEspecies($dados),
            $this->blocoDePontosCriticos($dados),
        ];

        $texto = implode("\n\n", array_filter($blocos, static fn (?string $bloco): bool => $bloco !== null));

        return "Retrato consolidado do período:\n\n".$texto;
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function blocoDoPeriodo(MonitoringReport $relatorio, array $dados): string
    {
        $linhas = ['PERÍODO'];

        $linhas[] = '- De '.$this->dataPorExtenso($relatorio->periodo_inicio)
            .' a '.$this->dataPorExtenso($relatorio->periodo_fim);

        $linhas[] = '- Visitas concluídas: '.(int) data_get($dados, 'visitas.quantidade', 0);

        return implode("\n", $linhas);
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function blocoDeEspecies(array $dados): ?string
    {
        $linhas = ['OCORRÊNCIA POR ESPÉCIE'];

        foreach (data_get($dados, 'ocorrencia_por_especie', []) as $porEndereco) {
            foreach (data_get($porEndereco, 'evolucao_por_especie', []) as $especie => $evolucao) {
                $anterior = (int) data_get($evolucao, 'de', 0);
                $atual = (int) data_get($evolucao, 'para', 0);
                $percentual = data_get($evolucao, 'percentual');

                $linha = sprintf(
                    '- %s: %d no período anterior, %d neste período',
                    is_string($especie) ? $especie : (string) data_get($evolucao, 'especie', 'espécie não identificada'),
                    $anterior,
                    $atual
                );

                if (is_numeric($percentual)) {
                    $linha .= sprintf(' (variação de %s%%)', rtrim(rtrim(number_format((float) $percentual, 2, ',', ''), '0'), ','));
                }

                $linhas[] = $linha;
            }
        }

        return count($linhas) > 1 ? implode("\n", $linhas) : null;
    }

    /**
     * @param  array<string, mixed>  $dados
     */
    private function blocoDePontosCriticos(array $dados): ?string
    {
        $linhas = ['PONTOS CRÍTICOS'];
        $registrados = 0;

        foreach (data_get($dados, 'ranking_pontos_criticos', []) as $porEndereco) {
            foreach (data_get($porEndereco, 'dispositivos', []) as $dispositivo) {
                if ($registrados >= self::LIMITE_DE_PONTOS_CRITICOS) {
                    break 2;
                }

                $linhas[] = sprintf(
                    '- %s: %d captura(s) em %d visita(s), média de %s por visita, tendência %s',
                    (string) data_get($dispositivo, 'rotulo', 'dispositivo'),
                    (int) data_get($dispositivo, 'capturas_total', 0),
                    (int) data_get($dispositivo, 'visitas', 0),
                    number_format((float) data_get($dispositivo, 'media_por_visita', 0), 2, ',', ''),
                    (string) data_get($dispositivo, 'tendencia.estado', 'não informada')
                );

                $registrados++;
            }
        }

        return $registrados > 0 ? implode("\n", $linhas) : null;
    }

    private function blocoDoAtendimento(WorkOrder $os): string
    {
        $linhas = ['ATENDIMENTO'];

        if ($os->scheduled_date !== null) {
            $linhas[] = '- Data: '.$this->dataPorExtenso($os->scheduled_date);
        }

        if (filled($os->service?->name)) {
            $linhas[] = '- Serviço: '.$os->service->name;
        }

        $servicosExecutados = $os->services
            ->pluck('name')
            ->filter()
            ->unique()
            ->values();

        if ($servicosExecutados->isNotEmpty()) {
            $linhas[] = '- Serviços executados: '.$servicosExecutados->implode(', ');
        }

        if (filled($os->description)) {
            $linhas[] = '- Descrição do serviço: '.$this->limpar($os->description);
        }

        return implode("\n", $linhas);
    }

    private function blocoDeProdutos(WorkOrder $os): ?string
    {
        if ($os->products->isEmpty()) {
            return null;
        }

        $linhas = ['PRODUTOS APLICADOS'];

        foreach ($os->products as $produto) {
            $partes = [$produto->name];

            $quantidade = $produto->pivot->quantity ?? null;
            $unidade = $produto->pivot->unit ?? $produto->unidade ?? null;

            if (filled($quantidade)) {
                $partes[] = 'quantidade '.trim($quantidade.' '.($unidade ?? ''));
            }

            if (filled($produto->pivot->observations ?? null)) {
                $partes[] = $this->limpar($produto->pivot->observations);
            }

            $linhas[] = '- '.implode(' — ', $partes);
        }

        return implode("\n", $linhas);
    }

    private function blocoDeComodos(WorkOrder $os): ?string
    {
        if ($os->rooms->isEmpty()) {
            return null;
        }

        $linhas = ['CÔMODOS ATENDIDOS'];

        foreach ($os->rooms as $comodo) {
            $partes = [$comodo->name];

            if (filled($comodo->pivot->observation ?? null)) {
                $partes[] = $this->limpar($comodo->pivot->observation);
            }

            $linhas[] = '- '.implode(' — ', $partes);
        }

        return implode("\n", $linhas);
    }

    private function blocoDePragas(WorkOrder $os): ?string
    {
        if ($os->pestSightings->isEmpty()) {
            return null;
        }

        $linhas = ['PRAGAS ENCONTRADAS'];

        foreach ($os->pestSightings as $avistamento) {
            $partes = [$avistamento->pest_type ?? 'praga não identificada'];

            if (filled($avistamento->severity_level)) {
                $partes[] = 'nível '.$avistamento->severity_level;
            }

            if (filled($avistamento->estimated_quantity)) {
                $partes[] = 'quantidade estimada '.$avistamento->estimated_quantity;
            }

            if (filled($avistamento->location_description)) {
                $partes[] = 'local: '.$this->limpar($avistamento->location_description);
            }

            if (filled($avistamento->description)) {
                $partes[] = $this->limpar($avistamento->description);
            }

            $linhas[] = '- '.implode(' — ', $partes);
        }

        return implode("\n", $linhas);
    }

    /**
     * Só dispositivo com ocorrência registrada entra: listar os cem
     * dispositivos sem novidade encheria o contexto e diluiria o que importa.
     */
    private function blocoDeDispositivos(WorkOrder $os): ?string
    {
        $eventos = $os->workOrderDeviceEvents->filter(
            fn ($evento): bool => filled($evento->event_type_id)
                || filled($evento->pest_found)
                || filled($evento->bait_consumption_status)
                || filled($evento->event_description)
        );

        if ($eventos->isEmpty()) {
            return null;
        }

        $linhas = ['DISPOSITIVOS COM OCORRÊNCIA'];

        foreach ($eventos as $evento) {
            $identificacao = $evento->device?->label
                ?? $evento->device?->codigo_publico
                ?? ('dispositivo '.$evento->device_id);

            $partes = [$identificacao];

            if (filled($evento->eventType?->name)) {
                $partes[] = $evento->eventType->name;
            }

            if (filled($evento->pest_found)) {
                $partes[] = 'praga encontrada: '.$this->limpar($evento->pest_found);
            }

            if (filled($evento->bait_consumption_status)) {
                $partes[] = 'consumo de isca: '.$this->limpar($evento->bait_consumption_status);
            }

            if (filled($evento->event_description)) {
                $partes[] = $this->limpar($evento->event_description);
            }

            $linhas[] = '- '.implode(' — ', $partes);
        }

        return implode("\n", $linhas);
    }

    private function blocoDeAdequacoes(WorkOrder $os): ?string
    {
        if ($os->adequations->isEmpty()) {
            return null;
        }

        $linhas = ['ADEQUAÇÕES RECOMENDADAS'];

        foreach ($os->adequations as $adequacao) {
            $partes = [];

            if (filled($adequacao->type)) {
                $partes[] = $adequacao->type;
            }

            if (filled($adequacao->description)) {
                $partes[] = $this->limpar($adequacao->description);
            }

            if (filled($adequacao->priority)) {
                $partes[] = 'prioridade '.$adequacao->priority;
            }

            if ($adequacao->deadline !== null) {
                $partes[] = 'prazo sugerido '.$this->dataPorExtenso($adequacao->deadline);
            }

            if ($partes === []) {
                continue;
            }

            $linhas[] = '- '.implode(' — ', $partes);
        }

        return count($linhas) > 1 ? implode("\n", $linhas) : null;
    }

    private function blocoDeObservacoes(WorkOrder $os): ?string
    {
        $linhas = ['OBSERVAÇÕES DO TÉCNICO'];

        if (filled($os->observations)) {
            $linhas[] = '- '.$this->limpar($os->observations);
        }

        if (filled($os->completion_notes)) {
            $linhas[] = '- '.$this->limpar($os->completion_notes);
        }

        return count($linhas) > 1 ? implode("\n", $linhas) : null;
    }

    /**
     * Data no formato do dia, no fuso do negócio.
     *
     * Passa por `BusinessDate` como todo instante do projeto: campo `date`
     * carrega um dia, não um instante, e formatá-lo em UTC escorregaria o dia
     * do parecer.
     */
    private function dataPorExtenso(mixed $valor): string
    {
        return BusinessDate::paraFusoNegocio($valor)?->format('d/m/Y') ?? '';
    }

    /**
     * Achata quebra de linha e espaço repetido.
     *
     * Observação digitada em campo vem com quebra de linha solta, e ela
     * quebraria o formato rotulado de "uma ocorrência por linha".
     */
    private function limpar(?string $texto): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $texto) ?? '');
    }
}
