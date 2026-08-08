<?php

namespace App\Services\Routing;

use App\Models\Technician;
use App\Models\TechnicianTrackingSetting;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\Geo\ResultadoGeo;
use Carbon\Carbon;

/**
 * Onde a execução da OS começou e terminou de verdade, e se esse local bate
 * com o endereço do cliente (Plano 22, Task 22.4).
 *
 * Duas responsabilidades, propositalmente no mesmo Service porque uma
 * alimenta a outra: registrar o local de início/fim (`registrarInicio()`,
 * `registrarFim()`) e comparar o local de fim com o endereço da OS
 * (`calcularDivergencia()`). Uma terceira, sem relação de dado com as duas
 * primeiras mas do mesmo domínio de "onde está o técnico": ligar o
 * rastreamento contínuo com consentimento (`ligarRastreamentoContinuo()`).
 *
 * Regras de negócio inegociáveis deste Service:
 *
 * - **Sem autorização de localização não é erro.** O técnico pode recusar a
 *   permissão do aparelho a qualquer momento; `registrarInicio()` e
 *   `registrarFim()` gravam o instante sempre, e a coordenada só quando os
 *   dois valores (latitude e longitude) vierem preenchidos. Metade de um par
 *   de coordenada (só latitude, ou só longitude) é tratada como "sem
 *   coordenada", nunca como erro de validação: um payload truncado no meio da
 *   sincronização não pode derrubar o registro do instante.
 * - **Divergência nunca bloqueia.** `calcularDivergencia()` não lança
 *   exceção; na pior hipótese (dado incompleto) devolve `null`.
 * - **Dado ruim não gera alarme falso.** Endereço sem coordenada, ou com
 *   `precisao_geocodificacao = cidade`, não é comparado: `null`.
 * - **Rastreamento contínuo nasce desligado e só liga com consentimento
 *   registrado.** `TechnicianTrackingSetting` garante isto no próprio ponto
 *   de gravação (`static::saving`), e `ligarRastreamentoContinuo()` é o único
 *   caminho deste Service que liga a chave, sempre com quem autorizou.
 *
 * Colunas de local (`inicio_latitude`, `inicio_longitude`,
 * `inicio_registrado_em`, `fim_latitude`, `fim_longitude`,
 * `fim_registrado_em`, `divergencia_local_metros`) ficam fora de
 * `WorkOrder::$fillable` de propósito (Task 22.1) e continuam fora: mesmo
 * critério já registrado em `Address` para `latitude`/`longitude`
 * (`GeocodificacaoService` é o único escritor, via `forceFill()`). Aqui,
 * `LocalDeExecucaoService` é o único escritor destas sete colunas, também via
 * `forceFill()`. Um `WorkOrderRequest` futuro que juntasse um campo de
 * coordenada ao formulário de edição, ou um `$workOrder->update($dados)`
 * descuidado, não tem como gravar nelas por acidente.
 */
class LocalDeExecucaoService
{
    /**
     * Raio médio da Terra, em metros, usado na fórmula de Haversine.
     */
    private const RAIO_DA_TERRA_METROS = 6371000;

    /**
     * Limiar padrão de divergência, em metros, acima do qual
     * `calcularDivergencia()` sinaliza. Ver o docblock de
     * `limiarDivergenciaMetros()` para a decisão de design sobre "configurável
     * por tenant".
     */
    private const LIMIAR_DIVERGENCIA_METROS_PADRAO = 300;

    /**
     * Intervalo padrão, em minutos, entre pontos do rastreamento contínuo.
     * Espelha o `default(15)` de `technician_tracking_settings.intervalo_minutos`
     * para quando uma linha nova nasce por `ligarRastreamentoContinuo()` sem
     * um intervalo explícito.
     */
    private const INTERVALO_PADRAO_MINUTOS = 15;

    // -----------------------------------------------------------------
    // Registro do local de início e fim
    // -----------------------------------------------------------------

    /**
     * Grava o início da execução: o instante sempre, a coordenada só quando o
     * técnico autorizou (os dois valores vieram preenchidos).
     *
     * `$instante` é o instante do aparelho do técnico (mesmo que alimenta
     * `WorkOrder::start_time` em `AplicadorDeExecucao::iniciar()`), não o do
     * servidor: é o que o docblock da migration de Task 22.1 já declarava
     * como o valor esperado destas colunas. Sem informar, cai no instante do
     * servidor (`now()`), para este método continuar utilizável fora do
     * fluxo de sincronização (ex. um teste, ou uma chamada futura do painel
     * web) sem exigir um Carbon explícito.
     */
    public function registrarInicio(WorkOrder $os, ?float $latitude, ?float $longitude, ?Carbon $instante = null): WorkOrder
    {
        return $this->registrarLocal($os, 'inicio', $latitude, $longitude, $instante);
    }

    /**
     * Grava o fim da execução (mesma regra de `registrarInicio()`) e, na
     * sequência, recalcula `divergencia_local_metros` a partir do local
     * recém-gravado. A divergência nunca impede o retorno deste método: pior
     * caso, fica `null`.
     */
    public function registrarFim(WorkOrder $os, ?float $latitude, ?float $longitude, ?Carbon $instante = null): WorkOrder
    {
        $os = $this->registrarLocal($os, 'fim', $latitude, $longitude, $instante);

        $os->forceFill([
            'divergencia_local_metros' => $this->calcularDivergencia($os),
        ])->save();

        return $os->fresh();
    }

    /**
     * @param  'inicio'|'fim'  $momento
     */
    private function registrarLocal(WorkOrder $os, string $momento, ?float $latitude, ?float $longitude, ?Carbon $instante): WorkOrder
    {
        $dados = [
            "{$momento}_registrado_em" => $instante ?? Carbon::now(),
        ];

        if ($latitude !== null && $longitude !== null) {
            $dados["{$momento}_latitude"] = $latitude;
            $dados["{$momento}_longitude"] = $longitude;
        }

        $os->forceFill($dados)->save();

        return $os->fresh();
    }

    // -----------------------------------------------------------------
    // Divergência entre o local registrado e o endereço da OS
    // -----------------------------------------------------------------

    /**
     * Distância, em metros, entre o local de fim registrado e a coordenada do
     * endereço da OS, quando essa comparação faz sentido.
     *
     * Devolve `null` só quando a comparação em si não é possível ou não é
     * confiável:
     *
     * - Falta a coordenada do local registrado (`fim_latitude`/`fim_longitude`
     *   nulos: técnico não autorizou, ou ainda não concluiu).
     * - Falta a coordenada do endereço (nunca geocodificado).
     * - O endereço tem `precisao_geocodificacao = cidade`: comparar contra
     *   esse dado gera alarme falso (o ponto não está no endereço, só na
     *   cidade) e ensina todo mundo a ignorar o aviso.
     *
     * Fora desses três casos, o valor é sempre gravado, mesmo abaixo do
     * limiar de sinalização (`divergenciaSinalizada()`, 300 m por padrão):
     * "sinalizar" é uma decisão de QUEM LÊ o dado (alertar ou não a empresa),
     * não do que fica gravado. Zerar o campo abaixo do limiar apagaria
     * exatamente o dado que esta task existe para preservar - "registro de
     * início e fim é suficiente para... resolver disputa com cliente" (PRD):
     * numa disputa, saber que o técnico ficou a 250 m (perto, mas não
     * gravado) é diferente de não ter dado nenhum, e o valor bruto continua
     * útil para quem revisa, mesmo sem acender o alerta padrão de 300 m.
     */
    public function calcularDivergencia(WorkOrder $os): ?int
    {
        if ($os->fim_latitude === null || $os->fim_longitude === null) {
            return null;
        }

        $endereco = $os->address;

        if ($endereco === null || $endereco->latitude === null || $endereco->longitude === null) {
            return null;
        }

        if ($endereco->precisao_geocodificacao === ResultadoGeo::PRECISAO_CIDADE) {
            return null;
        }

        $distanciaMetros = $this->distanciaHaversine(
            (float) $os->fim_latitude,
            (float) $os->fim_longitude,
            (float) $endereco->latitude,
            (float) $endereco->longitude,
        );

        return (int) round($distanciaMetros);
    }

    /**
     * A divergência gravada em `$os->divergencia_local_metros` passa do
     * limiar que vale alerta? Quem consome este Service (endpoint da Task
     * 22.5, tela da Task 22.6) usa este método para decidir se mostra o
     * aviso, sem duplicar o valor do limiar.
     */
    public function divergenciaSinalizada(WorkOrder $os): bool
    {
        return $os->divergencia_local_metros !== null
            && $os->divergencia_local_metros > $this->limiarDivergenciaMetros($os);
    }

    /**
     * Limiar de divergência, em metros, acima do qual `calcularDivergencia()`
     * sinaliza. Hoje, 300 m para todo tenant.
     *
     * DECISÃO DE DESIGN (ambiguidade documentada): a especificação pede um
     * limiar "configurável por tenant". A Task 22.3, que estima deslocamento
     * (`EstimadorDeDeslocamento`) e roda em paralelo a esta, precisa do mesmo
     * tipo de configuração para "velocidade média por tenant" - as duas
     * tasks batem no mesmo buraco do schema. Nenhuma tabela de configuração
     * já existente serve: `CompanyAvailabilitySetting` é sobre grade de
     * agendamento online, `CompanyBillingSetting` e `FiscalConfig` são sobre
     * cobrança e nota fiscal, `PaymentGatewayConfig` é sobre gateway de
     * pagamento. Nenhuma tem relação com roteirização.
     *
     * Criar uma tabela nova (`company_routing_settings` ou semelhante) está
     * fora do escopo desta task, que pede exatamente três arquivos (a
     * migration de `technician_tracking_settings`, este Service e o ajuste em
     * `AplicadorDeExecucao`) - nenhum deles uma segunda migration. Também não
     * é este método que deveria decidir, sozinho, o formato de uma tabela que
     * a Task 22.3 também precisa; e como as duas tasks rodam em paralelo, não
     * dá para esperar a 22.3 nem editar o arquivo dela daqui.
     *
     * Por isso o limiar fica como constante, com este método como o único
     * ponto de leitura do sistema. Quando existir uma tabela de configuração
     * de roteirização por tenant (compartilhada com a "velocidade média" da
     * Task 22.3 é o desenho mais provável), a leitura passa a vir dela; a
     * mudança fica inteira aqui dentro, sem tocar em `calcularDivergencia()`
     * nem em quem chama este Service. `$os` já vem no parâmetro exatamente
     * para isso: no dia em que a leitura for por `$os->company_id`, a
     * assinatura deste método não muda.
     */
    private function limiarDivergenciaMetros(WorkOrder $os): int
    {
        return self::LIMIAR_DIVERGENCIA_METROS_PADRAO;
    }

    /**
     * Distância em linha reta entre duas coordenadas, em metros (fórmula de
     * Haversine). Não considera trajeto viário: é a mesma limitação já
     * assumida por `EstimadorDeDeslocamento` (Task 22.3) para a estimativa de
     * deslocamento, aqui usada só para medir divergência, não para rotear.
     */
    private function distanciaHaversine(float $latitude1, float $longitude1, float $latitude2, float $longitude2): float
    {
        $latitude1Rad = deg2rad($latitude1);
        $latitude2Rad = deg2rad($latitude2);
        $deltaLatitude = deg2rad($latitude2 - $latitude1);
        $deltaLongitude = deg2rad($longitude2 - $longitude1);

        $a = sin($deltaLatitude / 2) ** 2
            + cos($latitude1Rad) * cos($latitude2Rad) * sin($deltaLongitude / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::RAIO_DA_TERRA_METROS * $c;
    }

    // -----------------------------------------------------------------
    // Consentimento de rastreamento contínuo
    // -----------------------------------------------------------------

    /**
     * Liga o rastreamento contínuo para um técnico, com o consentimento dele
     * registrado. Único caminho deste Service que grava
     * `rastreamento_continuo = true`; `$autorizadoPor` não aceita `null`
     * (tipagem do parâmetro), então não existe chamada válida a este método
     * sem quem autorizou - e `TechnicianTrackingSetting::booted()` ainda
     * recusa, no próprio ponto de gravação, qualquer tentativa de ligar sem
     * `consentido_em`/`consentido_por` preenchidos, para quem grava por fora
     * deste método (ex. um `update()` direto).
     *
     * Atua sobre o override do técnico (`technician_id` preenchido), nunca
     * sobre a linha geral do tenant: o consentimento é sempre pessoal, do
     * próprio técnico, nunca herdado ou presumido de uma configuração geral
     * da empresa.
     */
    public function ligarRastreamentoContinuo(
        Technician $tecnico,
        User $autorizadoPor,
        ?int $intervaloMinutos = null
    ): TechnicianTrackingSetting {
        $configuracao = $this->configuracaoDoTecnico($tecnico)
            ?? new TechnicianTrackingSetting(['technician_id' => $tecnico->id]);

        $configuracao->forceFill([
            'rastreamento_continuo' => true,
            'consentido_em' => Carbon::now(),
            'consentido_por' => $autorizadoPor->id,
            'intervalo_minutos' => $intervaloMinutos ?? $configuracao->intervalo_minutos ?? self::INTERVALO_PADRAO_MINUTOS,
        ])->save();

        return $configuracao->fresh();
    }

    /**
     * Desliga o rastreamento contínuo do técnico. Mantém `consentido_em`/
     * `consentido_por` como registro histórico de quando e por quem foi
     * ligado da última vez, em vez de apagar: religar sem consentimento novo
     * (`ligarRastreamentoContinuo()` sempre atualiza os dois) nunca é possível
     * de qualquer forma, então preservar o histórico aqui não abre brecha
     * nenhuma, e serve de auditoria de quando o técnico foi monitorado.
     */
    public function desligarRastreamentoContinuo(Technician $tecnico): TechnicianTrackingSetting
    {
        $configuracao = $this->configuracaoDoTecnico($tecnico)
            ?? new TechnicianTrackingSetting(['technician_id' => $tecnico->id]);

        $configuracao->forceFill(['rastreamento_continuo' => false])->save();

        return $configuracao->fresh();
    }

    /**
     * O rastreamento contínuo está ligado para este técnico agora?
     */
    public function rastreamentoContinuoLigado(Technician $tecnico): bool
    {
        return (bool) ($this->configuracaoDoTecnico($tecnico)?->rastreamento_continuo ?? false);
    }

    /**
     * Configuração de rastreamento válida para o técnico: o override dele,
     * quando existe; senão `null` (a empresa nunca configurou nada para este
     * técnico - equivalente a desligado, mesmo raciocínio de
     * `CompanyAvailabilitySetting::padrao()` para "sem linha = padrão do
     * sistema"). Não cai para a linha geral do tenant aqui de propósito: o
     * consentimento de rastreamento contínuo é sempre por técnico, nunca uma
     * herança automática de uma configuração da empresa.
     */
    private function configuracaoDoTecnico(Technician $tecnico): ?TechnicianTrackingSetting
    {
        return TechnicianTrackingSetting::query()
            ->where('technician_id', $tecnico->id)
            ->first();
    }
}
