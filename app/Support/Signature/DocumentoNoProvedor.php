<?php

namespace App\Support\Signature;

use InvalidArgumentException;

/**
 * O que o provedor de assinatura eletrônica devolveu sobre um documento
 * (Plano 26, Task 26.2), no vocabulário do domínio.
 *
 * `ProvedorDeAssinatura::enviar()`, `::consultar()` e `::reenviar()` devolvem
 * este objeto. Os campos espelham as colunas de `signature_requests`
 * (Task 26.1) que recebem o resultado: `idNoProvedor` vai para
 * `provedor_documento_id`, `situacao` para `situacao`, `motivoRecusa` para
 * `motivo_recusa`.
 *
 * ## A situação é do documento, não de um signatário
 *
 * `SITUACAO_ASSINADO` só é usada quando **todos** os signatários assinaram:
 * assinatura parcial não é contrato, e é por isso que a tradução do
 * vocabulário do provedor acontece na implementação, e não em quem lê este
 * objeto. Um documento em que só a contratada assinou continua
 * `SITUACAO_EM_ANDAMENTO`; quem quiser saber quem já assinou olha
 * `$signatarios`.
 *
 * ## `urlDoArquivoAssinado` expira
 *
 * O link que o provedor devolve para o PDF assinado tem validade curta (na
 * ZapSign, 60 minutos). Ele **nunca** é guardado como se fosse permanente: o
 * arquivo é baixado e arquivado no ato (`arquivo_assinado_path`, Task 26.3),
 * porque o documento precisa continuar acessível anos depois de o link morrer.
 *
 * Imutável e sem dependência de framework: dá para montar e afirmar sobre ele
 * em teste sem rede e sem container.
 */
final class DocumentoNoProvedor
{
    public const SITUACAO_EM_ANDAMENTO = 'em_andamento';

    public const SITUACAO_ASSINADO = 'assinado';

    public const SITUACAO_RECUSADO = 'recusado';

    public const SITUACAO_EXPIRADO = 'expirado';

    public const SITUACAO_CANCELADO = 'cancelado';

    /**
     * @var array<int, string>
     */
    public const SITUACOES = [
        self::SITUACAO_EM_ANDAMENTO,
        self::SITUACAO_ASSINADO,
        self::SITUACAO_RECUSADO,
        self::SITUACAO_EXPIRADO,
        self::SITUACAO_CANCELADO,
    ];

    /**
     * @param  string  $idNoProvedor  Identificador do documento no provedor. Vai para `signature_requests.provedor_documento_id`.
     * @param  string  $situacao  Um dos `self::SITUACAO_*`, já traduzido do vocabulário do provedor. `SITUACAO_ASSINADO` só quando todos assinaram.
     * @param  array<int, SignatarioNoProvedor>  $signatarios  Situação de cada signatário, com a trilha de auditoria de quem já assinou.
     * @param  string|null  $urlDoArquivoAssinado  Link temporário do PDF assinado. Ver o cabeçalho: nunca é guardado como permanente.
     * @param  string|null  $urlDoArquivoOriginal  Link temporário do PDF enviado, para conferência.
     * @param  string|null  $motivoRecusa  Texto que o provedor devolveu explicando a recusa, quando `situacao` é `SITUACAO_RECUSADO`.
     */
    public function __construct(
        public readonly string $idNoProvedor,
        public readonly string $situacao,
        public readonly array $signatarios = [],
        public readonly ?string $urlDoArquivoAssinado = null,
        public readonly ?string $urlDoArquivoOriginal = null,
        public readonly ?string $motivoRecusa = null,
    ) {
        if (! in_array($situacao, self::SITUACOES, true)) {
            throw new InvalidArgumentException(
                "Situação de documento desconhecida: \"{$situacao}\". "
                .'Situações válidas: '.implode(', ', self::SITUACOES).'.'
            );
        }

        foreach ($signatarios as $signatario) {
            if (! $signatario instanceof SignatarioNoProvedor) {
                throw new InvalidArgumentException(
                    'A lista de signatários só aceita objetos SignatarioNoProvedor.'
                );
            }
        }
    }

    /**
     * Todos os signatários já assinaram?
     *
     * Não é o mesmo que `situacao === SITUACAO_ASSINADO`, e a diferença é
     * proposital: esta leitura vem da lista de signatários, e serve para a
     * implementação decidir a situação do documento sem depender de o provedor
     * ter um estado agregado confiável. Lista vazia devolve falso — documento
     * sem signatário nenhum não está assinado.
     */
    public function todosAssinaram(): bool
    {
        if ($this->signatarios === []) {
            return false;
        }

        foreach ($this->signatarios as $signatario) {
            if ($signatario->situacao !== SignatarioNoProvedor::SITUACAO_ASSINOU) {
                return false;
            }
        }

        return true;
    }

    /**
     * Algum signatário já abriu o documento?
     *
     * É o que faz o pedido sair de `enviado` para `visualizado` na Task 26.3.
     * Quem já assinou também já visualizou, por definição.
     */
    public function algumVisualizou(): bool
    {
        foreach ($this->signatarios as $signatario) {
            if (in_array(
                $signatario->situacao,
                [SignatarioNoProvedor::SITUACAO_VISUALIZOU, SignatarioNoProvedor::SITUACAO_ASSINOU],
                true
            )) {
                return true;
            }
        }

        return false;
    }
}
