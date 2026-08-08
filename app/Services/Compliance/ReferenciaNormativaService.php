<?php

namespace App\Services\Compliance;

use App\Models\Company;
use App\Models\NormativeReference;
use App\Support\TenantAtual;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Referência normativa citada nos documentos emitidos (Plano 24, Task 24.2).
 *
 * O que este serviço resolve
 * --------------------------
 * A RDC nº 52/2009 foi substituída pela RDC nº 622/2022, e a próxima resolução
 * virá. Deixar o texto legal escrito na view do PDF significaria alteração de
 * sistema (e deploy) a cada publicação da Anvisa, em documento que vai para a
 * mão de fiscal. Aqui o texto é dado: a plataforma mantém a referência padrão
 * e o tenant pode sobrescrever a dele, sem passar por desenvolvimento.
 *
 * Levantamento feito antes de alterar os documentos
 * ------------------------------------------------
 * Varredura por `rdc|resolu|anvisa|legisla|portaria|norma|sanit` nas cinco
 * views de `resources/views/pdf/`. **Nenhuma delas citava número de resolução**
 * — nem a RDC 52/2009, nem qualquer outra. O que existia eram remissões
 * genéricas, que continuam intactas e apenas ganharam a resolução ao lado:
 *
 * - `contract.blade.php`: "pela legislação aplicável" (cláusula de abertura),
 *   "pela legislação vigente" e "produtos ... autorizados pela ANVISA"
 *   (cláusula 2ª), "as informações exigidas pela legislação sanitária
 *   aplicável" (cláusula 2ª) e "conforme previsto na legislação sanitária"
 *   (cláusula 5ª).
 * - `certificate.blade.php`: bloco "INFORMAÇÕES LEGAIS E DE SEGURANÇA", que
 *   listava licenças e registros sem citar a norma que os exige.
 * - `work-order.blade.php`, `service-order.blade.php`, `receipt.blade.php`:
 *   nenhuma remissão normativa.
 *
 * A única citação da RDC 52/2009 no repositório está no docblock de
 * `App\Support\CatalogoInicialDoTenant`, que é comentário de código e não sai
 * em documento nenhum. O registro completo do que mudou em cada documento está
 * em `docs/conformidade-rdc-622.md`.
 *
 * Nunca quebra a geração do PDF
 * -----------------------------
 * `obter()` devolve string vazia em qualquer situação de falta ou falha:
 * tenant sem referência, plataforma sem referência, tabela ainda não migrada,
 * banco fora do ar no meio da renderização. Documento que **falha de gerar** é
 * pior que documento com a linha da referência ausente, e é o técnico em campo
 * que paga a diferença. A falha é registrada em log para não sumir sem rastro.
 *
 * Documento antigo não é reprocessado
 * -----------------------------------
 * Os PDFs deste sistema são gerados sob demanda e transmitidos ao usuário; não
 * existe arquivo emitido guardado em disco nem texto legal copiado para dentro
 * de `certificates`/`contracts`. Esta entrega, portanto, **não reescreve
 * documento nenhum já entregue**: não há job de reprocessamento, migration de
 * backfill nem alteração de registro histórico. O certificado que o cliente
 * arquivou continua exatamente como está, que é o exemplar que o fiscal
 * compara.
 */
class ReferenciaNormativaService
{
    /**
     * Texto da referência normativa de uma empresa, ou string vazia.
     *
     * `$empresa` nula é aceita de propósito: o PDF pode ser gerado em contexto
     * sem tenant resolvido (comando artisan, envio por e-mail em fila que não
     * carregou a empresa), e nesse caso vale a referência padrão da
     * plataforma, que é justamente a linha de `company_id` nulo.
     */
    public function obter(?Company $empresa = null, string $chave = NormativeReference::CHAVE_PRINCIPAL): string
    {
        return $this->obterParaEmpresaId($empresa?->id, $chave);
    }

    /**
     * Mesmo que `obter()`, a partir do id da empresa.
     *
     * Existe para o view composer, que muitas vezes tem só o tenant corrente e
     * não precisa consultar `companies` para montar uma linha de texto.
     */
    public function obterParaEmpresaId(?int $empresaId, string $chave = NormativeReference::CHAVE_PRINCIPAL): string
    {
        try {
            $referencia = NormativeReference::resolver($empresaId, $chave);

            return $referencia?->texto ?? '';
        } catch (Throwable $erro) {
            // Degradação deliberada: o documento sai sem a linha da
            // referência, e nunca deixa de sair.
            Log::warning('Não foi possível resolver a referência normativa do documento.', [
                'chave' => $chave,
                'company_id' => $empresaId,
                'erro' => $erro->getMessage(),
            ]);

            return '';
        }
    }

    /**
     * Referência do tenant corrente, ou a da plataforma.
     */
    public function obterDoTenantAtual(string $chave = NormativeReference::CHAVE_PRINCIPAL): string
    {
        return $this->obterParaEmpresaId(TenantAtual::id(), $chave);
    }
}
