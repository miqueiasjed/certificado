<?php

namespace App\Services;

use App\Models\Device;
use App\Models\User;
use App\Models\WorkOrder;
use App\Support\CodigoDeDispositivo;
use InvalidArgumentException;

/**
 * Leitura de um código de dispositivo: o técnico aponta a câmera para o QR code
 * colado no ponto (ou digita o código da etiqueta) e este Service diz a qual
 * registro aquele código corresponde.
 *
 * Quatro respostas possíveis, e a ordem em que são decididas importa:
 *
 * 1. `nao_encontrado` — o código não corresponde a nada que este usuário possa
 *    ver.
 * 2. `substituido` — a etiqueta lida é de um dispositivo que já saiu do ponto, e
 *    a resposta aponta para quem ocupa o ponto hoje.
 * 3. `fora_da_os` — o dispositivo existe, mas fica em outro endereço, diferente
 *    do endereço da ordem de serviço que o técnico está executando.
 * 4. `ok` — dispositivo do endereço certo.
 *
 * Por que `substituido` vem antes de `fora_da_os`
 * -----------------------------------------------
 * Etiqueta antiga continua colada no local até alguém raspar, então ler uma
 * delas é rotina e não erro. Quem foi substituído pode estar em qualquer
 * endereço, inclusive no da própria ordem, e avisar "fora da OS" quando o
 * problema real é "esta etiqueta está velha" manda o técnico investigar a coisa
 * errada.
 *
 * Por que `fora_da_os` avisa e não bloqueia
 * -----------------------------------------
 * O técnico pode estar conferindo de propósito um ponto do endereço vizinho, ou
 * o cadastro pode estar errado. O que não pode acontecer é ele registrar no
 * lugar errado sem perceber, então a resposta carrega o endereço real do
 * dispositivo lido e o endereço da ordem, lado a lado, e a decisão fica com ele.
 *
 * A regra mais importante do arquivo
 * ----------------------------------
 * Código inexistente e código de OUTRA EMPRESA devolvem exatamente a mesma
 * resposta, byte a byte, com o mesmo status HTTP. Diferenciar as duas permitiria
 * a uma empresa varrer códigos e descobrir quais existem na base de uma
 * concorrente, que é o vazamento mais grave possível neste sistema.
 *
 * A garantia não é uma comparação escrita à mão, é estrutural: a consulta usa
 * `Device::query()`, que carrega o escopo global por empresa, e portanto um
 * código de outro tenant simplesmente não aparece no resultado. Os dois casos
 * caem no mesmo `return` da mesma constante, e não existe caminho de código em
 * que um saiba algo que o outro não sabe.
 *
 * Registrar a leitura (gravar evento) não acontece aqui: este Service só lê.
 */
class DeviceScanService
{
    /**
     * Código malformado ou sem correspondência no escopo da empresa.
     */
    public const SITUACAO_NAO_ENCONTRADO = 'nao_encontrado';

    /**
     * Dispositivo encontrado, porém em endereço diferente do endereço da ordem
     * de serviço informada.
     */
    public const SITUACAO_FORA_DA_OS = 'fora_da_os';

    /**
     * A etiqueta lida é de um dispositivo que já saiu do ponto.
     */
    public const SITUACAO_SUBSTITUIDO = 'substituido';

    /**
     * Leitura sem ressalva.
     */
    public const SITUACAO_OK = 'ok';

    /**
     * A resposta de "não encontrei", em constante e sem nenhum outro dado.
     *
     * Está em constante justamente para que os dois caminhos que a produzem
     * (formato inválido e código ausente no escopo da empresa) devolvam o mesmo
     * valor sem margem para divergirem. Qualquer campo extra aqui — contagem,
     * eco do código recebido, mensagem diferente por caso — reabriria o canal de
     * descoberta de códigos entre empresas.
     *
     * @var array{situacao: string}
     */
    private const RESPOSTA_NAO_ENCONTRADO = ['situacao' => self::SITUACAO_NAO_ENCONTRADO];

    public function __construct(
        private WorkOrderAccessService $workOrderAccessService,
        private DeviceReplacementService $deviceReplacementService,
    ) {}

    /**
     * Resolve o código lido.
     *
     * `$workOrderId` é opcional: sem ele a leitura é avulsa (consulta de um
     * ponto qualquer) e a situação `fora_da_os` não existe, porque não há
     * endereço com o que comparar.
     *
     * `$usuario` é quem está lendo, e vem do controller: Service não consulta
     * usuário autenticado. Ele é obrigatório sempre que `$workOrderId` vier
     * preenchido, porque é dele que depende a verificação de acesso à ordem.
     *
     * A ordem de serviço é resolvida e autorizada ANTES de o código encostar no
     * banco, de propósito. Se a autorização viesse depois, um técnico não
     * atribuído à ordem distinguiria código existente (403) de código
     * inexistente (200), e ganharia de volta, dentro da própria empresa, o canal
     * de descoberta que esta classe fecha entre empresas.
     *
     * @return array{situacao: string, dispositivo?: Device, endereco?: mixed, tipo_isca?: mixed, ultimo_evento?: mixed, dispositivo_lido?: Device, substituicao?: mixed, endereco_da_ordem?: mixed}
     *
     * @throws \Illuminate\Database\Eloquent\ModelNotFoundException Ordem de serviço inexistente ou de outra empresa.
     * @throws \Illuminate\Auth\Access\AuthorizationException Técnico não atribuído à ordem informada.
     */
    public function resolver(string $codigo, ?int $workOrderId = null, ?User $usuario = null): array
    {
        $ordem = $this->ordemDaLeitura($workOrderId, $usuario);

        $dispositivo = $this->dispositivoDoCodigo($codigo);

        if ($dispositivo === null) {
            return self::RESPOSTA_NAO_ENCONTRADO;
        }

        $atualDoPonto = $this->sucessorNoPonto($dispositivo);

        if ($atualDoPonto !== null) {
            return $this->respostaSubstituido($dispositivo, $atualDoPonto);
        }

        if ($ordem !== null && (int) $ordem->address_id !== (int) $dispositivo->address_id) {
            return $this->respostaForaDaOs($dispositivo, $ordem);
        }

        return $this->respostaOk($dispositivo);
    }

    /**
     * Ordem de serviço da leitura, já autorizada, ou null quando a leitura é
     * avulsa.
     *
     * Ordem inexistente e ordem de outra empresa caem as duas em
     * `ModelNotFoundException`, ou seja, 404 — a consulta passa pelo escopo
     * global por empresa, então a de outro tenant nunca é encontrada e os dois
     * casos são indistinguíveis, pelo mesmo motivo que vale para o código do
     * dispositivo.
     *
     * A alternativa seria ignorar um `work_order_id` que não resolve e seguir
     * como leitura avulsa. Foi descartada: engolir o parâmetro devolveria `ok`
     * silenciosamente para um técnico que acredita estar executando uma ordem, e
     * o aviso de `fora_da_os` — a razão de o parâmetro existir — sumiria
     * exatamente no caso em que o pedido está errado.
     *
     * Já ordem existente na mesma empresa com técnico não atribuído responde
     * 403, e não 404: dentro da empresa a existência da ordem não é segredo.
     * Quem decide isso é `WorkOrderAccessService`, e não este arquivo.
     */
    private function ordemDaLeitura(?int $workOrderId, ?User $usuario): ?WorkOrder
    {
        if ($workOrderId === null) {
            return null;
        }

        if ($usuario === null) {
            throw new InvalidArgumentException(
                'A leitura informou uma ordem de serviço sem informar o usuário que está lendo. '
                .'Sem o usuário não há como verificar se ele tem acesso àquela ordem, e seguir '
                .'assim entregaria a ordem de qualquer técnico a qualquer um.'
            );
        }

        $ordem = WorkOrder::query()->findOrFail($workOrderId);

        $this->workOrderAccessService->garantirAcesso($ordem, $usuario);

        return $ordem;
    }

    /**
     * Dispositivo correspondente ao código, ou null quando não há.
     *
     * Duas recusas, e as duas devolvem null para o chamador não conseguir
     * distinguir uma da outra:
     *
     * 1. Formato inválido. A checagem de `CodigoDeDispositivo::valido()` vem
     *    antes de qualquer consulta, e é o único lugar em que isso é garantido:
     *    código com tamanho errado ou com caractere fora do alfabeto nunca foi
     *    gerado, então não há o que procurar, e uma consulta ao banco por
     *    entrada arbitrária só serviria para transformar o endpoint em
     *    ferramenta de varredura barata.
     * 2. Nenhuma linha no escopo da empresa corrente. `Device::query()` já
     *    carrega o escopo global de `BelongsToCompany`, então "não existe" e
     *    "existe em outra empresa" são o mesmo resultado aqui dentro — o
     *    dispositivo do outro tenant não chega sequer a ser lido.
     *
     * `normalizar()` antes de `valido()` porque o código pode ter sido digitado
     * na mão a partir da etiqueta, com minúsculas, espaço ou hífen. Normalizar
     * não corrige caractere ambíguo, apenas a forma.
     */
    private function dispositivoDoCodigo(string $codigo): ?Device
    {
        $normalizado = CodigoDeDispositivo::normalizar($codigo);

        if (! CodigoDeDispositivo::valido($normalizado)) {
            return null;
        }

        return Device::query()
            ->where('codigo_publico', $normalizado)
            ->first();
    }

    /**
     * Dispositivo que ocupa o ponto hoje, quando o lido já saiu dele; null
     * quando o lido continua sendo o atual.
     *
     * Percorre a cadeia inteira, e não apenas um elo: um ponto trocado duas
     * vezes tem A -> B -> C, e a etiqueta de A precisa levar a C, que é o que
     * está instalado, não a B, que também já saiu. A varredura é a de
     * `DeviceReplacementService::historicoDoPonto()`, reaproveitada em vez de
     * repetida, porque é lá que moram a trava contra ciclo e o teto de elos.
     *
     * Dispositivo marcado como `substituido` na coluna mas sem linha de
     * substituição (correção manual no banco, importação incompleta) não tem
     * sucessor e cai fora daqui: a leitura segue o fluxo normal e o estado dele
     * chega ao chamador pelo próprio campo `situacao` do dispositivo. Apontar
     * para um sucessor inexistente seria pior do que mostrar o registro como
     * ele está.
     */
    private function sucessorNoPonto(Device $dispositivo): ?Device
    {
        $atual = $this->deviceReplacementService->historicoDoPonto($dispositivo)->last();

        if ($atual === null || (int) $atual->getKey() === (int) $dispositivo->getKey()) {
            return null;
        }

        return $atual;
    }

    /**
     * Leitura sem ressalva.
     *
     * @return array<string, mixed>
     */
    private function respostaOk(Device $dispositivo): array
    {
        return array_merge(
            ['situacao' => self::SITUACAO_OK],
            $this->dadosDoDispositivo($dispositivo)
        );
    }

    /**
     * Etiqueta velha: a resposta descreve o dispositivo que está no ponto hoje,
     * no mesmo formato de uma leitura normal, e traz o dispositivo lido em
     * `dispositivo_lido` para a tela poder dizer de onde veio o aviso.
     *
     * O corpo principal apontar para o sucessor é deliberado: o técnico quer
     * registrar o que encontrou no ponto, e quem está no ponto é o novo. Deixar
     * o antigo no lugar de honra convidaria a tela a registrar no dispositivo
     * que já saiu de circulação.
     *
     * @return array<string, mixed>
     */
    private function respostaSubstituido(Device $lido, Device $atual): array
    {
        return array_merge(
            ['situacao' => self::SITUACAO_SUBSTITUIDO],
            $this->dadosDoDispositivo($atual),
            [
                'dispositivo_lido' => $lido,
                // Primeiro elo da cadeia a partir da etiqueta lida: o motivo e o
                // dia em que ESTE dispositivo saiu do ponto, que é o que
                // responde "por que a etiqueta não vale mais".
                'substituicao' => $lido->substituicaoDeDestino()->first(),
            ]
        );
    }

    /**
     * Dispositivo de outro endereço.
     *
     * Devolve os dois endereços, o real e o da ordem, porque o aviso só é útil
     * se o técnico conseguir comparar os dois na tela e decidir. Nada é
     * bloqueado aqui.
     *
     * @return array<string, mixed>
     */
    private function respostaForaDaOs(Device $dispositivo, WorkOrder $ordem): array
    {
        return array_merge(
            ['situacao' => self::SITUACAO_FORA_DA_OS],
            $this->dadosDoDispositivo($dispositivo),
            ['endereco_da_ordem' => $ordem->address()->with('client')->first()]
        );
    }

    /**
     * Os quatro dados que a tela do técnico precisa para trabalhar o ponto.
     *
     * As relações são consultadas pelo relacionamento e não carregadas no
     * model (`load`) de propósito: relação carregada é serializada junto do
     * dispositivo no JSON, e o mesmo endereço e o mesmo tipo de isca sairiam
     * duas vezes no corpo da resposta, uma aninhada e outra na chave de
     * primeiro nível. Como o contrato desta resposta são as chaves de primeiro
     * nível, `dispositivo` fica sendo só as colunas dele.
     *
     * O endereço vem com o cliente porque o técnico identifica o local pelo
     * nome do cliente, não pelo id do endereço.
     *
     * @return array<string, mixed>
     */
    private function dadosDoDispositivo(Device $dispositivo): array
    {
        return [
            'dispositivo' => $dispositivo,
            'endereco' => $dispositivo->address()->with('client')->first(),
            'tipo_isca' => $dispositivo->baitType()->first(),
            'ultimo_evento' => $dispositivo->deviceEvents()
                ->orderBy('event_date', 'desc')
                ->first(),
        ];
    }
}
