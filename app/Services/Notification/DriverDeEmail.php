<?php

namespace App\Services\Notification;

use App\Mail\NotificacaoDaFila;
use App\Models\Company;
use App\Models\NotificationQueue;
use App\Models\WorkOrder;
use App\Services\WorkOrderService;
use App\Support\EventosDeNotificacao;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Mail\Mailer;
use Throwable;

/**
 * Entrega por e-mail os itens da fila.
 *
 * Três decisões concentradas aqui:
 *
 * 1. **O remetente é do tenant.** Nome e endereço saem de `companies`, pela
 *    empresa dona do item (`company_id` da linha), nunca de `Company::current()`
 *    nem do remetente da plataforma. O despachante roda dentro do tenant, então
 *    os dois valores coincidem hoje; ler do item é o que garante que continuem
 *    coincidindo no dia em que alguém reprocessar a fila por fora do laço por
 *    empresa. Aviso saindo com o nome da plataforma confundiria o cliente final,
 *    que só conhece a dedetizadora.
 *
 * 2. **O anexo é gerado na hora.** Não existe PDF salvo em disco neste projeto:
 *    a OS é montada por `WorkOrderService::preparePdfData()` e renderizada pelo
 *    DomPDF a cada pedido. O driver faz o mesmo e anexa os bytes, sem gravar
 *    arquivo nenhum. O contexto do item traz `work_order_id`, colocado lá pelo
 *    disparo do evento `os_concluida`.
 *
 * 3. **Nenhuma exceção escapa.** Tudo que o transporte lançar vira
 *    `ResultadoDeEnvio` pelo `ClassificadorDeFalhaDeEnvio`, que é quem decide se
 *    o despachante repete ou desiste.
 *
 * O `Mailer` chega pelo construtor, e não pela facade `Mail`, para que a Task
 * 14.9 consiga testar falha de provedor sem rede: basta injetar um mailer que
 * lança a exceção do cenário. `Mail::fake()` continua funcionando, porque a
 * facade e o contrato apontam para o mesmo registro do container.
 */
class DriverDeEmail implements DriverDeEnvio
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly WorkOrderService $ordensDeServico,
    ) {
    }

    public function canal(): string
    {
        return EventosDeNotificacao::CANAL_EMAIL;
    }

    public function enviar(NotificationQueue $item): ResultadoDeEnvio
    {
        $empresa = $this->empresaDoItem($item);

        if ($empresa === null) {
            return ResultadoDeEnvio::falhaPermanente(
                "A empresa #{$item->company_id}, dona deste aviso, não existe mais. "
                .'Nenhum e-mail pode sair sem remetente.'
            );
        }

        $remetente = $this->remetente($empresa);

        if ($remetente === null) {
            // Configuração, não entrega: nada foi ao provedor e repetir daqui a
            // 5 minutos daria o mesmo resultado. Marcar `falha` na primeira
            // tentativa coloca o item no histórico de falhas agora, que é onde
            // alguém vai ver o que precisa ser corrigido, em vez de deixá-lo
            // parecendo pendente por mais de oito horas.
            return ResultadoDeEnvio::falhaPermanente(
                "A empresa {$empresa->name} está sem e-mail de remetente cadastrado. "
                .'Preencha o e-mail da empresa em Configurações para que os avisos possam sair.'
            );
        }

        $anexos = [];
        $ordem = $this->ordemDeServicoDoContexto($item);

        if ($ordem instanceof ResultadoDeEnvio) {
            return $ordem;
        }

        if ($ordem !== null) {
            try {
                $anexos[] = $this->pdfDaOrdemDeServico($ordem);
            } catch (Throwable $erro) {
                report($erro);

                // Falha ao montar o PDF costuma ser memória ou tempo, que
                // passa. O corpo da mensagem promete o documento em anexo, e
                // mandar o e-mail sem ele seria pior do que atrasar.
                return ResultadoDeEnvio::falhaTemporaria(
                    "Não foi possível gerar o PDF da ordem de serviço {$ordem->order_number} para anexar: "
                    .$erro->getMessage(),
                    ['excecao' => $erro::class, 'mensagem' => $erro->getMessage()]
                );
            }
        }

        $mensagem = new NotificacaoDaFila(
            assuntoDaMensagem: $this->assunto($item, $empresa),
            corpoDaMensagem: (string) $item->corpo,
            remetenteEmail: $remetente['email'],
            remetenteNome: $remetente['nome'],
            responderPara: $remetente['responder_para'],
            documentos: $anexos,
        );

        try {
            $this->mailer->send($mensagem->to($item->destino));
        } catch (Throwable $erro) {
            return ClassificadorDeFalhaDeEnvio::classificar($erro);
        }

        return ResultadoDeEnvio::sucesso(
            "E-mail entregue ao provedor para {$item->destino}.",
            [
                'destino' => $item->destino,
                'remetente' => $remetente['email'],
                'anexos' => count($anexos),
            ]
        );
    }

    /**
     * Empresa dona do item.
     *
     * `Company` não usa o escopo global por empresa (ela é o próprio tenant),
     * então a busca pelo id do item é direta.
     */
    private function empresaDoItem(NotificationQueue $item): ?Company
    {
        return Company::query()->find($item->company_id);
    }

    /**
     * Remetente e endereço de resposta desta empresa.
     *
     * O e-mail da empresa é o caminho normal. Sem ele, o endereço técnico de
     * `config('mail.from.address')` segura o envio, mas o nome exibido continua
     * sendo o da empresa: é o nome que o cliente reconhece. Sem os dois, não há
     * como enviar.
     *
     * O `reply-to` só sai quando a empresa tem e-mail próprio. Apontar a
     * resposta para o endereço técnico da plataforma faria a resposta do
     * cliente cair onde ninguém lê.
     *
     * @return array{email: string, nome: string, responder_para: ?string}|null
     */
    private function remetente(Company $empresa): ?array
    {
        $doTenant = trim((string) $empresa->email);
        $daPlataforma = trim((string) config('mail.from.address'));
        $endereco = $doTenant !== '' ? $doTenant : $daPlataforma;

        if ($endereco === '') {
            return null;
        }

        $nome = trim((string) $empresa->name);

        return [
            'email' => $endereco,
            'nome' => $nome !== '' ? $nome : (string) config('app.name'),
            'responder_para' => $doTenant !== '' ? $doTenant : null,
        ];
    }

    /**
     * Assunto do e-mail.
     *
     * O item já traz o assunto renderizado. O rótulo do evento entra como rede
     * de segurança para o template do tenant salvo com assunto vazio: e-mail
     * sem assunto é tratado como spam por boa parte dos provedores.
     */
    private function assunto(NotificationQueue $item, Company $empresa): string
    {
        if (filled($item->assunto)) {
            return (string) $item->assunto;
        }

        $rotulo = EventosDeNotificacao::existe((string) $item->evento)
            ? EventosDeNotificacao::rotulo((string) $item->evento)
            : 'Aviso';

        return $rotulo.' - '.$empresa->name;
    }

    /**
     * Ordem de serviço apontada pelo contexto do item, quando houver.
     *
     * Devolve `null` quando o aviso não leva documento, a `WorkOrder` quando
     * leva, e um `ResultadoDeEnvio` de falha quando o contexto aponta para uma
     * OS que não existe mais neste tenant. Esse último caso é permanente: OS
     * apagada não volta, e repetir quatro vezes só adia o registro do problema.
     */
    private function ordemDeServicoDoContexto(NotificationQueue $item): WorkOrder|ResultadoDeEnvio|null
    {
        $contexto = is_array($item->contexto) ? $item->contexto : [];
        $identificador = $contexto['work_order_id'] ?? null;

        if (blank($identificador)) {
            return null;
        }

        // Consulta escopada pelo tenant corrente, como toda consulta de
        // domínio: OS de outra empresa simplesmente não é encontrada, que é o
        // resultado desejado se o contexto vier com id errado.
        $ordem = WorkOrder::query()->find((int) $identificador);

        if ($ordem === null) {
            return ResultadoDeEnvio::falhaPermanente(
                "A ordem de serviço #{$identificador}, que este aviso anexa, não foi encontrada. "
                .'O e-mail não foi enviado porque a mensagem promete o documento em anexo.'
            );
        }

        return $ordem;
    }

    /**
     * PDF da OS gerado em memória, no mesmo caminho da tela
     * (`WorkOrderController::generatePDF`), para que o documento anexado seja
     * idêntico ao que o usuário baixa pelo sistema.
     *
     * @return array{nome: string, conteudo: string, mime: string}
     */
    private function pdfDaOrdemDeServico(WorkOrder $ordem): array
    {
        $dados = $this->ordensDeServico->preparePdfData($ordem);

        $pdf = Pdf::loadView('pdf.work-order', $dados);
        $pdf->setPaper('A4', 'portrait');

        return [
            'nome' => 'OS-'.$ordem->order_number.'.pdf',
            'conteudo' => $pdf->output(),
            'mime' => 'application/pdf',
        ];
    }
}
