<?php

namespace App\Observers;

use App\Models\Company;
use App\Models\OrganRegistration;
use App\Services\Compliance\ValidadeService;
use App\Support\TenantAtual;

/**
 * Atualizar a validade encerra os avisos na hora (Plano 24, Task 24.3).
 *
 * Por que um observer, e não uma chamada no controller
 * ----------------------------------------------------
 * A validade é gravada de mais de um lugar: a tela de configurações da empresa
 * (Task 24.6), a tela de registros em órgão, e amanhã qualquer importação ou
 * rotina de correção. Amarrar o encerramento a um controller deixaria os
 * outros caminhos avisando de um vencimento que já foi resolvido — e um aviso
 * que mente é um aviso que a empresa aprende a ignorar, inclusive os que estão
 * certos.
 *
 * Sem isso, quem renovasse a licença de manhã continuaria recebendo, no
 * despacho das 08:00, o e-mail dizendo que ela vence hoje.
 *
 * O que ele faz
 * -------------
 * - `Company`: para cada uma das quatro colunas de validade que **mudou**,
 *   cancela os avisos pendentes daquele documento. Só do documento alterado:
 *   renovar a licença sanitária não pode calar o aviso da ambiental, que
 *   continua vencendo.
 * - `OrganRegistration`: quando `validade` muda, realinha `situacao`
 *   (`vencido`/`ativo`) e cancela os avisos pendentes daquele registro.
 *   `cancelado` nunca é sobrescrito: o cancelamento é publicado pelo órgão e
 *   não é derivável de data nenhuma.
 *
 * Cuidados
 * --------
 * - Roda em `updated`, depois de a gravação ter dado certo. Cancelar aviso de
 *   uma alteração que a transação depois desfizesse deixaria a empresa sem
 *   aviso nenhum sobre um documento ainda vencido.
 * - A alteração de `situacao` de `OrganRegistration` usa `saveQuietly()`, para
 *   não reentrar neste mesmo observer.
 * - `Company` **não** é model de domínio (é o próprio tenant, sem escopo
 *   global), então o cancelamento precisa rodar dentro do tenant da empresa
 *   alterada: `NotificationQueue` é escopado, e sem o tenant certo o `update`
 *   não alcançaria linha nenhuma. `TenantAtual::comTenant()` restaura o tenant
 *   anterior no `finally`, inclusive em caso de erro.
 */
class ValidadeRegulatoriaObserver
{
    public function __construct(private readonly ValidadeService $validades) {}

    public function updated(Company|OrganRegistration $model): void
    {
        if ($model instanceof Company) {
            $this->empresaAtualizada($model);

            return;
        }

        $this->registroAtualizado($model);
    }

    private function empresaAtualizada(Company $empresa): void
    {
        $itensAlterados = [];

        foreach (ValidadeService::DOCUMENTOS_DA_EMPRESA as $item => $definicao) {
            if ($empresa->wasChanged($definicao['validade'])) {
                $itensAlterados[] = $item;
            }
        }

        if ($itensAlterados === []) {
            return;
        }

        TenantAtual::comTenant((int) $empresa->getKey(), function () use ($empresa, $itensAlterados): void {
            foreach ($itensAlterados as $item) {
                $this->validades->encerrarAvisosDoDocumento($empresa, $item);
            }
        });
    }

    private function registroAtualizado(OrganRegistration $registro): void
    {
        if (! $registro->wasChanged('validade')) {
            return;
        }

        if ($registro->situacao !== OrganRegistration::SITUACAO_CANCELADO) {
            $situacao = $this->validades->situacaoDerivadaDaValidade($registro);

            if ($registro->situacao !== $situacao) {
                $registro->situacao = $situacao;
                $registro->saveQuietly();
            }
        }

        // `OrganRegistration` é model de domínio: o escopo global já resolve o
        // tenant a partir do contexto corrente, que é o da requisição que
        // acabou de gravar. Não há tenant a assumir aqui.
        $this->validades->encerrarAvisosDoRegistro($registro);
    }
}
