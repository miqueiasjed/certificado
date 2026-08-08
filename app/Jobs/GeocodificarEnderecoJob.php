<?php

namespace App\Jobs;

use App\Jobs\Concerns\CarregaTenant;
use App\Models\Address;
use App\Services\Geo\GeocodificacaoService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Geocodifica um endereço recém-criado, fora da requisição de cadastro
 * (Plano 22, Task 22.2).
 *
 * O provedor de geocodificação é uma chamada HTTP externa que pode levar
 * segundos para responder. O cadastro de um endereço não pode esperar essa
 * resposta: o atendente ou o técnico que acabou de salvar um cliente novo
 * continua a tela sem travar, e a geocodificação acontece depois, no worker
 * da fila. `App\Services\AddressService::createAddress()` despacha este job
 * logo após criar o endereço.
 *
 * `QUEUE_CONNECTION=database` já é o padrão deste projeto (`.env` e
 * `.env.example`), com a tabela `jobs` migrada e `php artisan queue:listen`
 * já ligado ao `composer dev`, então este job roda de verdade fora da
 * requisição em qualquer ambiente que não sobrescreva a variável. Mesmo que
 * algum ambiente pontual rode com `QUEUE_CONNECTION=sync` (o job processando
 * no mesmo processo que o despachou), o resultado continua correto: a
 * estrutura de Job existe justamente para que trocar a conexão da fila não
 * exija mudar nenhuma linha aqui.
 *
 * Usa `App\Jobs\Concerns\CarregaTenant` como todo job novo deste projeto: o
 * id da empresa viaja no payload, capturado no despacho, e é reaplicado
 * automaticamente antes de `handle()` rodar (ver o cabeçalho da trait).
 * `Address::find()` dentro de `handle()`, e não o model inteiro no
 * construtor, para não depender da ordem entre a desserialização do job (que
 * `SerializesModels` faria antes do tenant estar aplicado) e o middleware de
 * tenant.
 */
class GeocodificarEnderecoJob implements ShouldQueue
{
    use CarregaTenant, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public function __construct(public readonly int $addressId)
    {
        $this->capturarTenantAtual();
    }

    public function handle(GeocodificacaoService $geocodificacao): void
    {
        $endereco = Address::query()->find($this->addressId);

        if ($endereco === null) {
            Log::info('geocodificacao.job.endereco_nao_encontrado', [
                'address_id' => $this->addressId,
            ]);

            return;
        }

        $geocodificacao->geocodificar($endereco);
    }
}
