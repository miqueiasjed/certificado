<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Device;
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Barryvdh\DomPDF\Facade\Pdf as FacadePdf;
use Barryvdh\DomPDF\PDF;
use Illuminate\Database\Eloquent\Collection;

class DeviceLabelService
{
    /**
     * Lado do SVG gerado, em pixels. Resolução alta o bastante para a
     * etiqueta ficar nítida mesmo impressa pequena; o tamanho final na
     * página é controlado pela view, em milímetros.
     */
    private const TAMANHO_QR_PX = 240;

    /**
     * Devolve o SVG do QR code de leitura do dispositivo, pronto para
     * embutir inline no HTML da etiqueta (`{!! $svg !!}`).
     *
     * O conteúdo codificado é a URL de leitura do dispositivo, nunca o
     * código puro: a câmera nativa do celular abre URL sem precisar de
     * aplicativo, e já leva o técnico direto à tela do dispositivo.
     *
     * Correção de erro em nível médio: etiqueta em armadilha de rodapé fica
     * suja com o tempo, e correção baixa deixa de ler nessas condições.
     */
    public function qrCode(Device $device): string
    {
        $renderer = new ImageRenderer(
            new RendererStyle(self::TAMANHO_QR_PX),
            new SvgImageBackEnd()
        );

        $writer = new Writer($renderer);

        $url = route('devices.ler', $device->codigo_publico);

        $svg = $writer->writeString($url, ecLevel: ErrorCorrectionLevel::M());

        return $this->semPrologoXml($svg);
    }

    /**
     * O `XMLWriter` usado por `bacon/bacon-qr-code` sempre abre o documento
     * com `<?xml version="1.0" encoding="UTF-8"?>` antes do `<svg>`. Correto
     * para um arquivo `.svg` isolado, mas aqui o SVG é embutido inline no
     * HTML da etiqueta (`{!! $svg !!}`), e o prólogo XML solto no meio do
     * `<body>` não é markup válido e pode ser exibido como texto pelo
     * navegador ou pelo dompdf. Removido para a string devolvida começar
     * direto em `<svg`.
     */
    private function semPrologoXml(string $svg): string
    {
        return preg_replace('/^<\?xml[^>]*\?>\s*/', '', $svg, 1);
    }

    /**
     * Monta a folha A4 de etiquetas dos dispositivos de um endereço, em
     * grade de 3 colunas, pronta para imprimir, recortar e colar.
     *
     * Sem `$deviceIds`, imprime todos os dispositivos com `situacao =
     * 'ativo'` do endereço. Com `$deviceIds`, restringe aos ids informados,
     * mas o filtro por `situacao = 'ativo'` nunca é dispensado: dispositivo
     * substituído ou removido não vira etiqueta impressa, mesmo que alguém
     * tente selecioná-lo explicitamente na tela.
     *
     * Quem chama já entregou o `Address` resolvido pelo escopo da empresa;
     * este serviço não decide permissão nem multiempresa.
     */
    public function folhaDeEtiquetas(Address $endereco, array $deviceIds = []): PDF
    {
        $dispositivos = $this->dispositivosParaEtiquetas($endereco, $deviceIds);

        $etiquetas = $dispositivos->map(fn (Device $dispositivo): array => [
            'dispositivo' => $dispositivo,
            'qr' => $this->qrCode($dispositivo),
        ]);

        return FacadePdf::loadView('pdf.device-labels', [
            'endereco' => $endereco,
            'etiquetas' => $etiquetas,
        ])->setPaper('a4', 'portrait');
    }

    /**
     * @param  array<int, int>  $deviceIds
     * @return Collection<int, Device>
     */
    private function dispositivosParaEtiquetas(Address $endereco, array $deviceIds): Collection
    {
        return $endereco->devices()
            ->where('situacao', 'ativo')
            ->when(
                filled($deviceIds),
                fn ($consulta) => $consulta->whereIn('id', $deviceIds)
            )
            ->orderBy('number')
            ->orderBy('label')
            ->get();
    }
}
