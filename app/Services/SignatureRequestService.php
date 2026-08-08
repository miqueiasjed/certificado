<?php

namespace App\Services;

use App\Exceptions\ContratoEmAssinaturaException;
use App\Models\Company;
use App\Models\Contract;
use App\Models\SignatureProviderConfig;
use App\Models\SignatureRequest;
use App\Models\SignatureSigner;
use App\Models\User;
use App\Services\Signature\ResolvedorDeProvedor;
use App\Support\BusinessDate;
use App\Support\EventosDeNotificacao;
use App\Support\Signature\DocumentoNoProvedor;
use App\Support\Signature\SignatarioNoProvedor;
use App\Support\Signature\SignatarioParaEnvio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Ciclo de vida do pedido de assinatura eletrônica de um contrato (Plano 26,
 * Task 26.3): enviar, acompanhar, arquivar o documento assinado, reenviar e
 * cancelar.
 *
 * ## As quatro invariantes que este Service protege
 *
 * 1. **Um pedido em aberto por contrato.** Dois pedidos abertos do mesmo
 *    contrato produziriam duas assinaturas válidas de textos possivelmente
 *    diferentes, e nada saberia dizer qual vale. `enviar()` recusa; `reenviar()`
 *    notifica o **mesmo** documento no provedor, nunca cria outro.
 * 2. **Contrato em assinatura é imutável.** Quem recusa a edição é
 *    `ContractService::atualizar()`/`::excluir()`, a partir de
 *    `situacao_assinatura` que este Service grava.
 * 3. **O contrato só vira assinado quando todos assinaram.** Assinatura
 *    parcial não é contrato. A apuração é
 *    `DocumentoNoProvedor::todosAssinaram()`, feita sobre a lista de
 *    signatários, e não sobre o estado agregado do provedor.
 * 4. **O arquivo assinado é baixado e guardado no ato.** O link do provedor
 *    expira em minutos, e o documento precisa continuar acessível anos
 *    depois. Guardar o link seria guardar um documento que some.
 *
 * ## O arquivo original também é guardado
 *
 * `arquivo_original_path` prova o que foi enviado; `arquivo_assinado_path` é o
 * documento que vale. Os dois em disco **privado** (`local`): contrato carrega
 * valor, endereço e dados do cliente, e disco público serviria isso por URL
 * adivinhável. Quem entrega o arquivo é sempre um endpoint que confere o dono
 * (Task 26.4).
 *
 * ## Por que `aplicarDocumento()` é o único ponto de escrita de situação
 *
 * Webhook e sincronização periódica chegam pelo mesmo lugar. O webhook não
 * decide nada a partir do corpo da requisição: ele só diz "algo mudou neste
 * documento", e é `consultar()` — autenticado com a credencial do tenant — que
 * diz o que mudou. Isso torna o processamento naturalmente idempotente (o
 * mesmo evento duas vezes converge para o mesmo estado) e imune a corpo
 * forjado.
 */
class SignatureRequestService
{
    /**
     * Disco dos arquivos. Privado de propósito — ver o cabeçalho da classe.
     */
    public const DISCO = 'local';

    /**
     * Prazo padrão de assinatura, em dias, quando quem chama não informa.
     */
    public const DIAS_PARA_EXPIRAR_PADRAO = 15;

    /**
     * Dias sem conclusão a partir dos quais o pedido vira pendência avisada à
     * empresa (`contrato_pendente_de_assinatura`).
     */
    public const DIAS_PARA_AVISAR_PENDENCIA = 5;

    public function __construct(
        private readonly ResolvedorDeProvedor $resolvedorDeProvedor,
        private readonly ContractService $contractService,
        private readonly NotificationService $notificationService,
    ) {}

    // -----------------------------------------------------------------
    // Envio
    // -----------------------------------------------------------------

    /**
     * Gera o PDF, guarda o original, envia ao provedor, cria o pedido e os
     * signatários, marca o contrato como `em_assinatura` e enfileira o aviso.
     *
     * A ordem importa e não é arbitrária: o pedido é criado **antes** da
     * chamada ao provedor, em `rascunho`, para que uma resposta perdida no
     * meio do caminho deixe rastro em vez de sumir. Só depois de o provedor
     * confirmar é que o pedido ganha `provedor_documento_id` e o contrato muda
     * de situação — se a chamada falhar, o contrato continua editável e o
     * rascunho é descartado na mesma transação.
     *
     * @param  array<int, array{nome: string, email: string, papel: string, ordem?: int, documento?: string|null}>  $signatarios
     *
     * @throws ContratoEmAssinaturaException Contrato com pedido em aberto.
     * @throws \App\Exceptions\ProvedorDeAssinaturaNaoConfiguradoException
     * @throws \App\Exceptions\AssinaturaEletronicaRecusouException
     * @throws \App\Exceptions\AssinaturaEletronicaIndisponivelException
     */
    public function enviar(
        Contract $contrato,
        array $signatarios,
        int $diasParaExpirar = self::DIAS_PARA_EXPIRAR_PADRAO,
        ?User $autor = null,
        ?string $mensagem = null,
    ): SignatureRequest {
        if ($this->pedidoEmAberto($contrato) !== null) {
            throw ContratoEmAssinaturaException::jaTemPedidoEmAberto($contrato);
        }

        $empresa = $this->empresaDoContrato($contrato);
        $configuracao = $this->resolvedorDeProvedor->configuracaoAtiva($empresa);
        $provedor = $this->resolvedorDeProvedor->paraConfiguracao($configuracao);

        // Fora da transação: gerar o PDF é caro e não toca o banco.
        $pdf = $this->contractService->renderizarPdf($contrato);
        $paraEnvio = $this->signatariosParaEnvio($signatarios);
        $expiraEm = BusinessDate::hoje()->addDays(max(1, $diasParaExpirar));

        $pedido = DB::transaction(fn (): SignatureRequest => SignatureRequest::create([
            'contract_id' => $contrato->getKey(),
            'provedor' => $configuracao->provedor,
            'situacao' => 'rascunho',
            'expira_em' => $expiraEm,
            'criado_por' => $autor?->getKey(),
        ]));

        $caminhoOriginal = $this->caminhoDoArquivo($pedido, 'original');
        Storage::disk(self::DISCO)->put($caminhoOriginal, $pdf);

        try {
            $documento = $provedor->enviar(
                $configuracao,
                $this->nomeDoDocumento($contrato),
                $pdf,
                $paraEnvio,
                $expiraEm,
                $mensagem,
                'signature-request-'.$pedido->getKey(),
            );
        } catch (Throwable $excecao) {
            // O provedor recusou ou não respondeu: nada de contrato em
            // assinatura, nada de rascunho órfão bloqueando um envio futuro.
            // O arquivo original também sai, porque não prova envio nenhum.
            Storage::disk(self::DISCO)->delete($caminhoOriginal);
            $pedido->delete();

            throw $excecao;
        }

        return DB::transaction(function () use ($pedido, $documento, $contrato, $caminhoOriginal, $signatarios): SignatureRequest {
            $pedido->forceFill([
                'provedor_documento_id' => $documento->idNoProvedor,
                'situacao' => 'enviado',
                'enviado_em' => BusinessDate::agora(),
                'arquivo_original_path' => $caminhoOriginal,
            ])->save();

            $this->criarSignatarios($pedido, $signatarios, $documento);

            $contrato->forceFill(['situacao_assinatura' => 'em_assinatura'])->save();

            $this->avisarEnvio($contrato, $pedido);

            return $pedido->refresh();
        });
    }

    // -----------------------------------------------------------------
    // Acompanhamento
    // -----------------------------------------------------------------

    /**
     * Consulta o provedor e aplica no domínio o que ele responder.
     *
     * Ponto único de entrada do webhook e da rotina periódica. Pedido já
     * encerrado não é consultado de novo: ele não muda mais sozinho, e uma
     * consulta a mais por evento repetido seria chamada de rede à toa.
     *
     * @throws \App\Exceptions\AssinaturaEletronicaRecusouException
     * @throws \App\Exceptions\AssinaturaEletronicaIndisponivelException
     */
    public function sincronizar(SignatureRequest $pedido, ?SignatureProviderConfig $configuracao = null): SignatureRequest
    {
        if (! $pedido->estaEmAberto() || blank($pedido->provedor_documento_id)) {
            return $pedido;
        }

        $configuracao ??= $this->resolvedorDeProvedor->configuracaoAtiva($this->empresaDoPedido($pedido));
        $provedor = $this->resolvedorDeProvedor->paraConfiguracao($configuracao);

        $documento = $provedor->consultar($configuracao, (string) $pedido->provedor_documento_id);

        return $this->aplicarDocumento($pedido, $documento, $configuracao);
    }

    /**
     * Aplica no domínio a situação que o provedor informou.
     *
     * Idempotente por construção: rodar duas vezes com o mesmo documento
     * converge para o mesmo estado, e o download do arquivo assinado só
     * acontece uma vez, porque a segunda passada encontra
     * `arquivo_assinado_path` já preenchido.
     */
    public function aplicarDocumento(
        SignatureRequest $pedido,
        DocumentoNoProvedor $documento,
        SignatureProviderConfig $configuracao,
    ): SignatureRequest {
        $this->atualizarSignatarios($pedido, $documento);

        return match ($documento->situacao) {
            DocumentoNoProvedor::SITUACAO_ASSINADO => $this->concluirComoAssinado($pedido, $documento, $configuracao),
            DocumentoNoProvedor::SITUACAO_RECUSADO => $this->concluirComoRecusado($pedido, $documento),
            DocumentoNoProvedor::SITUACAO_EXPIRADO => $this->concluirComoExpirado($pedido),
            DocumentoNoProvedor::SITUACAO_CANCELADO => $this->concluirComoCancelado($pedido),
            default => $this->registrarAndamento($pedido, $documento),
        };
    }

    /**
     * Manda o provedor notificar de novo quem ainda não assinou. **Não cria
     * pedido novo** — ver a invariante 1 no cabeçalho da classe.
     *
     * @throws ContratoEmAssinaturaException Pedido já encerrado.
     */
    public function reenviar(SignatureRequest $pedido): SignatureRequest
    {
        if (! $pedido->estaEmAberto() || blank($pedido->provedor_documento_id)) {
            throw ContratoEmAssinaturaException::pedidoJaEncerrado((string) $pedido->situacao);
        }

        $configuracao = $this->resolvedorDeProvedor->configuracaoAtiva($this->empresaDoPedido($pedido));
        $provedor = $this->resolvedorDeProvedor->paraConfiguracao($configuracao);

        $documento = $provedor->reenviar($configuracao, (string) $pedido->provedor_documento_id);

        return $this->aplicarDocumento($pedido, $documento, $configuracao);
    }

    /**
     * Cancela o pedido no provedor e localmente, e devolve o contrato ao
     * estado editável.
     *
     * O contrato volta para `nao_enviado`, e não para `recusado`: cancelamento
     * é decisão da empresa, não recusa do cliente, e confundir os dois faria a
     * tela mostrar "o cliente recusou" para um contrato que ninguém recusou.
     *
     * @throws ContratoEmAssinaturaException Pedido já encerrado.
     */
    public function cancelar(SignatureRequest $pedido, ?string $motivo = null): SignatureRequest
    {
        if (! $pedido->estaEmAberto()) {
            throw ContratoEmAssinaturaException::pedidoJaEncerrado((string) $pedido->situacao);
        }

        if (filled($pedido->provedor_documento_id)) {
            $configuracao = $this->resolvedorDeProvedor->configuracaoAtiva($this->empresaDoPedido($pedido));
            $this->resolvedorDeProvedor
                ->paraConfiguracao($configuracao)
                ->cancelar($configuracao, (string) $pedido->provedor_documento_id, $motivo);
        }

        return DB::transaction(function () use ($pedido, $motivo): SignatureRequest {
            $pedido->forceFill([
                'situacao' => 'cancelado',
                'concluido_em' => BusinessDate::agora(),
                'motivo_recusa' => $motivo,
            ])->save();

            $this->marcarContrato($pedido, 'nao_enviado');

            return $pedido->refresh();
        });
    }

    /**
     * Pedido ainda vivo deste contrato, ou `null`.
     */
    public function pedidoEmAberto(Contract $contrato): ?SignatureRequest
    {
        return SignatureRequest::query()
            ->where('contract_id', $contrato->getKey())
            ->whereIn('situacao', SignatureRequest::SITUACOES_EM_ABERTO)
            ->latest('id')
            ->first();
    }

    // -----------------------------------------------------------------
    // Transições de situação
    // -----------------------------------------------------------------

    /**
     * Todos assinaram: baixa o arquivo, arquiva, marca o contrato e avisa as
     * duas pontas.
     *
     * O download é a parte que pode falhar sem que nada esteja errado no
     * domínio (link expirado, rede). Quando falha, o pedido **não** é marcado
     * assinado: fica em aberto para a rotina periódica tentar de novo, o que é
     * melhor que um contrato marcado assinado sem o documento que prova a
     * assinatura.
     */
    private function concluirComoAssinado(
        SignatureRequest $pedido,
        DocumentoNoProvedor $documento,
        SignatureProviderConfig $configuracao,
    ): SignatureRequest {
        if ($pedido->situacao === 'assinado' && filled($pedido->arquivo_assinado_path)) {
            return $pedido;
        }

        $caminho = $pedido->arquivo_assinado_path;

        if (blank($caminho)) {
            $conteudo = $this->resolvedorDeProvedor
                ->paraConfiguracao($configuracao)
                ->baixarAssinado($configuracao, $documento->idNoProvedor);

            $caminho = $this->caminhoDoArquivo($pedido, 'assinado');
            Storage::disk(self::DISCO)->put($caminho, $conteudo);
        }

        return DB::transaction(function () use ($pedido, $caminho): SignatureRequest {
            $agora = BusinessDate::agora();

            $pedido->forceFill([
                'situacao' => 'assinado',
                'concluido_em' => $agora,
                'arquivo_assinado_path' => $caminho,
            ])->save();

            $contrato = $this->marcarContrato($pedido, 'assinado', $agora);

            if ($contrato !== null) {
                $this->avisarAssinatura($contrato, $pedido);
            }

            return $pedido->refresh();
        });
    }

    private function concluirComoRecusado(SignatureRequest $pedido, DocumentoNoProvedor $documento): SignatureRequest
    {
        if ($pedido->situacao === 'recusado') {
            return $pedido;
        }

        return DB::transaction(function () use ($pedido, $documento): SignatureRequest {
            $motivo = $documento->motivoRecusa ?? $this->motivoDoSignatarioQueRecusou($pedido);

            $pedido->forceFill([
                'situacao' => 'recusado',
                'concluido_em' => BusinessDate::agora(),
                'motivo_recusa' => $motivo,
            ])->save();

            $contrato = $this->marcarContrato($pedido, 'recusado');

            if ($contrato !== null) {
                $this->avisarRecusa($contrato, $pedido, $motivo);
            }

            return $pedido->refresh();
        });
    }

    private function concluirComoExpirado(SignatureRequest $pedido): SignatureRequest
    {
        if ($pedido->situacao === 'expirado') {
            return $pedido;
        }

        return DB::transaction(function () use ($pedido): SignatureRequest {
            $pedido->forceFill([
                'situacao' => 'expirado',
                'concluido_em' => BusinessDate::agora(),
            ])->save();

            // Volta a `nao_enviado`, e não `recusado`: prazo vencido não é
            // recusa do cliente, e o contrato precisa voltar a ser editável
            // para ser reenviado.
            $this->marcarContrato($pedido, 'nao_enviado');

            return $pedido->refresh();
        });
    }

    private function concluirComoCancelado(SignatureRequest $pedido): SignatureRequest
    {
        if ($pedido->situacao === 'cancelado') {
            return $pedido;
        }

        return DB::transaction(function () use ($pedido): SignatureRequest {
            $pedido->forceFill([
                'situacao' => 'cancelado',
                'concluido_em' => BusinessDate::agora(),
            ])->save();

            $this->marcarContrato($pedido, 'nao_enviado');

            return $pedido->refresh();
        });
    }

    /**
     * Ainda em andamento: o que muda é só `enviado` -> `visualizado`, quando
     * alguém abriu o documento. Nunca volta de `visualizado` para `enviado`:
     * o fato de alguém ter aberto não deixa de ser verdade depois.
     */
    private function registrarAndamento(SignatureRequest $pedido, DocumentoNoProvedor $documento): SignatureRequest
    {
        if ($pedido->situacao === 'enviado' && $documento->algumVisualizou()) {
            $pedido->forceFill(['situacao' => 'visualizado'])->save();
        }

        return $pedido->refresh();
    }

    /**
     * Grava a situação de assinatura no contrato do pedido.
     *
     * Usa `forceFill` porque `situacao_assinatura` e `assinado_em` estão fora
     * de `$fillable` de propósito (ver o cast em `Contract`): quem decide o
     * estado de assinatura é este Service, nunca um formulário.
     */
    private function marcarContrato(SignatureRequest $pedido, string $situacao, mixed $assinadoEm = null): ?Contract
    {
        $contrato = $pedido->contract;

        if (! $contrato instanceof Contract) {
            return null;
        }

        $contrato->forceFill([
            'situacao_assinatura' => $situacao,
            'assinado_em' => $situacao === 'assinado' ? ($assinadoEm ?? BusinessDate::agora()) : null,
        ])->save();

        return $contrato;
    }

    // -----------------------------------------------------------------
    // Signatários
    // -----------------------------------------------------------------

    /**
     * @param  array<int, array{nome: string, email: string, papel: string, ordem?: int, documento?: string|null}>  $signatarios
     * @return array<int, SignatarioParaEnvio>
     */
    private function signatariosParaEnvio(array $signatarios): array
    {
        return array_map(
            static fn (array $dados): SignatarioParaEnvio => new SignatarioParaEnvio(
                nome: (string) $dados['nome'],
                email: (string) $dados['email'],
                papel: (string) $dados['papel'],
                ordem: (int) ($dados['ordem'] ?? 1),
                documento: $dados['documento'] ?? null,
            ),
            array_values($signatarios)
        );
    }

    /**
     * @param  array<int, array{nome: string, email: string, papel: string, ordem?: int, documento?: string|null}>  $signatarios
     */
    private function criarSignatarios(SignatureRequest $pedido, array $signatarios, DocumentoNoProvedor $documento): void
    {
        foreach (array_values($signatarios) as $dados) {
            $pedido->signers()->create([
                'nome' => $dados['nome'],
                'email' => mb_strtolower((string) $dados['email']),
                'documento' => $dados['documento'] ?? null,
                'papel' => $dados['papel'],
                'ordem' => (int) ($dados['ordem'] ?? 1),
                'situacao' => 'pendente',
            ]);
        }

        $this->atualizarSignatarios($pedido->refresh(), $documento);
    }

    /**
     * Espelha no domínio a situação de cada signatário informada pelo
     * provedor.
     *
     * O casamento é por **e-mail**, e não por identificador do provedor: o
     * token do signatário só existe depois do envio e não é estável entre
     * reenvios, enquanto o e-mail é o que identifica quem assina e é gravado
     * com `lock_email` no provedor exatamente para não mudar.
     *
     * Situação de signatário nunca regride: quem já assinou não volta a
     * pendente porque uma consulta trouxe um estado mais antigo.
     */
    private function atualizarSignatarios(SignatureRequest $pedido, DocumentoNoProvedor $documento): void
    {
        if ($documento->signatarios === []) {
            return;
        }

        $porEmail = $pedido->signers()->get()->keyBy(
            static fn (SignatureSigner $signatario): string => mb_strtolower((string) $signatario->email)
        );

        foreach ($documento->signatarios as $doProvedor) {
            $signatario = $porEmail->get($doProvedor->emailNoProvedor);

            if (! $signatario instanceof SignatureSigner) {
                continue;
            }

            if ($this->pesoDaSituacao($doProvedor->situacao) <= $this->pesoDaSituacao((string) $signatario->situacao)) {
                continue;
            }

            $signatario->forceFill([
                'situacao' => $doProvedor->situacao,
                'assinado_em' => $doProvedor->assinadoEm,
                'ip' => $doProvedor->ip,
                'user_agent' => $doProvedor->userAgent,
            ])->save();
        }
    }

    /**
     * Ordem de avanço das situações de signatário. `recusou` fica no topo
     * junto com `assinou`: os dois são finais, e nenhum evento posterior os
     * desfaz.
     */
    private function pesoDaSituacao(string $situacao): int
    {
        return match ($situacao) {
            SignatarioNoProvedor::SITUACAO_ASSINOU, SignatarioNoProvedor::SITUACAO_RECUSOU => 3,
            SignatarioNoProvedor::SITUACAO_VISUALIZOU => 2,
            default => 1,
        };
    }

    private function motivoDoSignatarioQueRecusou(SignatureRequest $pedido): ?string
    {
        $signatario = $pedido->signers()->where('situacao', 'recusou')->first();

        return $signatario instanceof SignatureSigner
            ? sprintf('Recusado por %s (%s).', $signatario->nome, $signatario->email)
            : null;
    }

    // -----------------------------------------------------------------
    // Avisos (Plano 14)
    // -----------------------------------------------------------------

    private function avisarEnvio(Contract $contrato, SignatureRequest $pedido): void
    {
        $this->enfileirar(EventosDeNotificacao::CONTRATO_ENVIADO_PARA_ASSINATURA, $contrato, [
            'marco' => 'pedido-'.$pedido->getKey(),
            'variaveis' => [
                'data_limite_assinatura' => BusinessDate::diaDe($pedido->expira_em),
            ],
        ]);
    }

    /**
     * Dois enfileiramentos: a via assinada vai para o cliente, e a empresa
     * precisa saber que pode começar a executar o contrato. Mesmo critério já
     * usado em `certificado_a_vencer`.
     *
     * O PDF assinado viaja como anexo por `contexto.signature_request_id`, que
     * é o que `DriverDeEmail::contratoAssinadoDoContexto()` lê para anexar o
     * arquivo do disco privado — o mesmo mecanismo já usado pela nota fiscal.
     * O caminho do arquivo não viaja no contexto de propósito: caminho de
     * arquivo vindo de dado gravado é como se lê arquivo de outro tenant.
     */
    private function avisarAssinatura(Contract $contrato, SignatureRequest $pedido): void
    {
        $variaveis = ['data_assinatura' => BusinessDate::agora()->format('d/m/Y')];
        $marco = 'pedido-'.$pedido->getKey();

        $this->enfileirar(EventosDeNotificacao::CONTRATO_ASSINADO, $contrato, [
            'marco' => $marco,
            'variaveis' => $variaveis,
            'contexto' => ['signature_request_id' => $pedido->getKey()],
        ]);

        $this->enfileirar(EventosDeNotificacao::CONTRATO_ASSINADO, $contrato, [
            'destinatario_tipo' => 'empresa',
            'marco' => $marco.'-empresa',
            'variaveis' => $variaveis,
            'contexto' => ['signature_request_id' => $pedido->getKey()],
        ]);
    }

    private function avisarRecusa(Contract $contrato, SignatureRequest $pedido, ?string $motivo): void
    {
        $this->enfileirar(EventosDeNotificacao::CONTRATO_RECUSADO, $contrato, [
            'marco' => 'pedido-'.$pedido->getKey(),
            'variaveis' => [
                'motivo_recusa' => $motivo ?? 'não informado pelo signatário.',
            ],
        ]);
    }

    /**
     * Aviso semanal de pendência, disparado pela rotina `assinaturas:sincronizar`.
     *
     * O marco leva a semana, e não só o pedido: é isso que faz o aviso voltar
     * toda semana enquanto a pendência existir, sem virar duplicata dentro da
     * mesma semana. Mesmo critério do reenvio semanal de
     * `lote_proximo_do_vencimento` (Plano 17).
     */
    public function avisarPendencia(SignatureRequest $pedido): bool
    {
        $enviadoEm = $pedido->enviado_em;

        if ($enviadoEm === null || ! $pedido->estaEmAberto()) {
            return false;
        }

        $dias = BusinessDate::hoje()->diffInDays(BusinessDate::paraFusoNegocio($enviadoEm)->startOfDay());
        $dias = (int) abs($dias);

        if ($dias < self::DIAS_PARA_AVISAR_PENDENCIA) {
            return false;
        }

        $contrato = $pedido->contract;

        if (! $contrato instanceof Contract) {
            return false;
        }

        $pendentes = $pedido->signers()
            ->whereIn('situacao', ['pendente', 'visualizou'])
            ->pluck('nome')
            ->all();

        $resultado = $this->enfileirar(EventosDeNotificacao::CONTRATO_PENDENTE_DE_ASSINATURA, $contrato, [
            'marco' => 'pedido-'.$pedido->getKey().'-semana-'.BusinessDate::hoje()->format('o-W'),
            'variaveis' => [
                'dias_pendente' => (string) $dias,
                'data_limite_assinatura' => BusinessDate::diaDe($pedido->expira_em),
                'signatarios_pendentes' => $pendentes === [] ? 'ninguém identificado' : implode(', ', $pendentes),
            ],
        ]);

        return (bool) ($resultado['criado'] ?? false);
    }

    /**
     * Enfileira sem deixar falha de aviso derrubar a transição de situação.
     *
     * Um e-mail que não sai é problema; um contrato que não fica marcado como
     * assinado porque o e-mail não saiu é problema maior. Mesmo critério já
     * aplicado nos disparos do Plano 19.
     *
     * @param  array<string, mixed>  $opcoes
     * @return array<string, mixed>
     */
    private function enfileirar(string $evento, Contract $contrato, array $opcoes): array
    {
        try {
            return $this->notificationService->enfileirar($evento, $contrato, $opcoes);
        } catch (Throwable $excecao) {
            Log::warning('assinatura.aviso_nao_enfileirado', [
                'evento' => $evento,
                'contract_id' => $contrato->getKey(),
                'company_id' => $contrato->company_id,
                'erro' => $excecao->getMessage(),
            ]);

            return ['criado' => false];
        }
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Caminho do arquivo no disco privado, sempre dentro da pasta do tenant:
     * é o que impede um id de pedido de outra empresa colidir com este.
     */
    private function caminhoDoArquivo(SignatureRequest $pedido, string $tipo): string
    {
        return sprintf(
            'contratos/assinatura/%d/%d-%s.pdf',
            $pedido->company_id,
            $pedido->getKey(),
            $tipo
        );
    }

    private function nomeDoDocumento(Contract $contrato): string
    {
        return 'Contrato '.($contrato->contract_number ?? '#'.$contrato->getKey());
    }

    private function empresaDoContrato(Contract $contrato): Company
    {
        return Company::findOrFail($contrato->company_id);
    }

    private function empresaDoPedido(SignatureRequest $pedido): Company
    {
        return Company::findOrFail($pedido->company_id);
    }
}
