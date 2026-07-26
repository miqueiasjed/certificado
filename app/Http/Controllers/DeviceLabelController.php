<?php

namespace App\Http\Controllers;

use App\Models\Address;
use App\Services\DeviceLabelService;
use Illuminate\Http\Request;

/**
 * Impressão da folha de etiquetas dos dispositivos de um endereço.
 *
 * Não previsto nos arquivos da Task 11.3 (que só criou o Service e a view) nem
 * de nenhuma outra task do Plano 11: a Task 11.8 pressupõe uma URL para abrir a
 * folha em nova aba, e ela precisa existir em algum lugar. Fica aqui, como
 * controller próprio, em vez de dentro de `DeviceController`, porque a ação não
 * é sobre um dispositivo e sim sobre a impressão de vários de uma vez.
 *
 * Controller fino: toda a montagem do PDF vive em `DeviceLabelService`.
 */
class DeviceLabelController extends Controller
{
    public function __construct(
        private readonly DeviceLabelService $etiquetas,
    ) {}

    /**
     * Folha A4 dos dispositivos do endereço.
     *
     * `device_ids` opcional na query string restringe a folha aos dispositivos
     * informados (ainda dentro do endereço e só os `ativo`, ver
     * `DeviceLabelService::folhaDeEtiquetas`); sem o parâmetro, imprime todos os
     * dispositivos ativos do endereço. Isso cobre os dois usos da Task 11.8: a
     * seleção múltipla da listagem e o botão de etiqueta avulsa da tela do
     * dispositivo (chamando com um único id).
     *
     * `Address` chega por Model Binding, e o escopo global por empresa garante
     * que não é de outro tenant. `stream()`, e não `download()`, porque a Task
     * 11.8 pede abrir "em nova aba", não baixar um arquivo.
     */
    public function folha(Request $request, Address $address)
    {
        $deviceIds = collect($request->query('device_ids', []))
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $pdf = $this->etiquetas->folhaDeEtiquetas($address, $deviceIds);

        $nomeDoArquivo = 'etiquetas-'.str($address->nickname ?: $address->id)->slug().'.pdf';

        return $pdf->stream($nomeDoArquivo);
    }
}
