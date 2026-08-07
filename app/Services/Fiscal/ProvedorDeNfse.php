<?php

namespace App\Services\Fiscal;

use App\Models\FiscalConfig;
use App\Support\Fiscal\RespostaDeCancelamento;
use App\Support\Fiscal\RespostaDeNfse;

/**
 * Contrato do provedor municipal de NFS-e.
 *
 * A configuração acompanha cada chamada. Nenhuma implementação pode guardar
 * credencial ou outro estado de tenant na instância.
 */
interface ProvedorDeNfse
{
    /**
     * @param  array<string, mixed>  $dadosDaDps
     * @return string Identificador assíncrono devolvido pelo provedor.
     */
    public function emitir(FiscalConfig $configuracao, array $dadosDaDps, string $referencia): string;

    public function consultar(FiscalConfig $configuracao, string $idNoProvedor): RespostaDeNfse;

    public function cancelar(
        FiscalConfig $configuracao,
        string $idNoProvedor,
        string $motivo,
        ?string $codigo = null,
    ): RespostaDeCancelamento;

    public function consultarCancelamento(
        FiscalConfig $configuracao,
        string $idNoProvedor,
    ): RespostaDeCancelamento;

    /**
     * @param  array<string, mixed>  $dadosDaDps
     * @return string Identificador assíncrono da nota substituta.
     */
    public function substituir(
        FiscalConfig $configuracao,
        string $idNoProvedorDaNotaSubstituida,
        string $codigoMotivo,
        string $motivo,
        array $dadosDaDps,
        string $referencia,
    ): string;

    public function baixarPdf(FiscalConfig $configuracao, string $idNoProvedor): string;

    public function baixarXml(FiscalConfig $configuracao, string $idNoProvedor): string;

    public function validarCredenciais(FiscalConfig $configuracao): bool;
}
