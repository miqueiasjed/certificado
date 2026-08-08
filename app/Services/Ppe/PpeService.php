<?php

namespace App\Services\Ppe;

use App\Exceptions\EpiComEntregaException;
use App\Models\PersonalProtectiveEquipment;
use App\Support\BusinessDate;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Cadastro do modelo de EPI que a empresa compra (Plano 28, Task 28.2).
 *
 * É o cadastro, não a ficha: aqui vivem nome, tipo, fabricante, o Certificado de
 * Aprovação do fabricante e a vida útil padrão. A entrega ao técnico é
 * `PpeDeliveryService`.
 *
 * Renovar o CA aqui é operação normal e esperada
 * ----------------------------------------------
 * `atualizar()` altera `numero_ca` e `validade_ca` à vontade, e isso **não**
 * mexe em nenhuma entrega já registrada: a ficha guarda a própria cópia do CA,
 * feita no ato da entrega. É por isso que renovar o certificado aqui é seguro —
 * a decisão de copiar em vez de referenciar existe exatamente para tornar esta
 * operação inofensiva sobre o histórico.
 *
 * Inativar, nunca excluir
 * -----------------------
 * Modelo de EPI com entrega registrada não se exclui: a ficha antiga aponta para
 * ele, e é dele que saem nome, tipo e fabricante do que o técnico recebeu. O
 * caminho é `inativar()`, que tira o modelo da escolha de novas entregas
 * (`PpeDeliveryService` recusa EPI inativo) e deixa as fichas emitidas intactas.
 * A mesma lógica pela qual a entrega se estorna em vez de se apagar.
 *
 * `excluir()` existe só para o cadastro que nunca foi usado — erro de digitação
 * no dia em que foi criado. Com entrega, ele recusa em português
 * (`EpiComEntregaException`) em vez de deixar o `restrictOnDelete` do banco
 * subir como erro de integridade e virar um 500 sem explicação.
 */
class PpeService
{
    /**
     * Cadastra um modelo de EPI.
     *
     * `$dados` aceita: `nome` (obrigatório), `tipo`, `fabricante`, `numero_ca`,
     * `validade_ca`, `vida_util_dias`, `obrigatorio`, `ativo` e `observacoes`.
     *
     * CA em branco é aceito de propósito: o tenant cadastra o EPI antes de ter o
     * certificado em mãos, e campo não preenchido é estado neutro, nunca
     * irregularidade. O EPI sem CA fica fora da varredura de vencimento
     * (Task 28.3) em vez de virar alerta falso — só não pode ser entregue com o
     * CA **vencido**, que é outra coisa.
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws ValidationException Quando o nome vem vazio, o tipo não é um dos
     *                             aceitos ou a vida útil é negativa.
     */
    public function criar(array $dados): PersonalProtectiveEquipment
    {
        $campos = $this->camposDoCadastro($dados);

        $this->garantirNome($campos['nome'] ?? null);

        return DB::transaction(fn (): PersonalProtectiveEquipment => PersonalProtectiveEquipment::create($campos));
    }

    /**
     * Atualiza o cadastro do modelo de EPI.
     *
     * Só os campos informados são tocados, e nenhum deles alcança entrega já
     * registrada. Ver o cabeçalho da classe: a ficha guarda a própria cópia do
     * CA.
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws ValidationException Quando o nome informado vem vazio, o tipo não é
     *                             um dos aceitos ou a vida útil é negativa.
     */
    public function atualizar(PersonalProtectiveEquipment $epi, array $dados): PersonalProtectiveEquipment
    {
        $campos = $this->camposDoCadastro($dados);

        if (array_key_exists('nome', $campos)) {
            $this->garantirNome($campos['nome']);
        }

        return DB::transaction(function () use ($epi, $campos): PersonalProtectiveEquipment {
            $epi->update($campos);

            return $epi->refresh();
        });
    }

    /**
     * Tira o modelo da escolha de novas entregas, preservando as fichas já
     * emitidas.
     *
     * É o substituto da exclusão neste cadastro. Idempotente: inativar o que já
     * está inativo não é erro, e recusar seria transformar um clique repetido em
     * mensagem de falha sem nenhum ganho.
     */
    public function inativar(PersonalProtectiveEquipment $epi): PersonalProtectiveEquipment
    {
        $epi->update(['ativo' => false]);

        return $epi->refresh();
    }

    /**
     * Devolve o modelo à escolha de novas entregas.
     *
     * Existe porque inativação não é exclusão: modelo aposentado por engano, ou
     * que a empresa voltou a comprar, precisa de caminho de volta. Sem ele, a
     * única saída do usuário seria cadastrar um modelo duplicado, e a ficha
     * passaria a ter dois cadastros para o mesmo equipamento.
     */
    public function reativar(PersonalProtectiveEquipment $epi): PersonalProtectiveEquipment
    {
        $epi->update(['ativo' => true]);

        return $epi->refresh();
    }

    /**
     * Exclui o cadastro, e **só** quando ele nunca foi entregue.
     *
     * Com entrega registrada, recusa com `EpiComEntregaException` e aponta a
     * inativação. A contagem usa a relação escopada por empresa, como todo o
     * resto do domínio.
     *
     * @throws EpiComEntregaException Quando o modelo já tem entrega registrada.
     */
    public function excluir(PersonalProtectiveEquipment $epi): void
    {
        $entregas = $epi->ppeDeliveries()->count();

        if ($entregas > 0) {
            throw EpiComEntregaException::para($epi, $entregas);
        }

        DB::transaction(function () use ($epi): void {
            $epi->delete();
        });
    }

    // -----------------------------------------------------------------
    // Apoio
    // -----------------------------------------------------------------

    /**
     * Campos que o cadastro escreve, já normalizados.
     *
     * `validade_ca` passa por `BusinessDate` para virar um dia `Y-m-d` no fuso do
     * negócio: é a validade do certificado, um dia sem hora relevante, e
     * converter fuso em campo `date` é justamente o que produz o erro de um dia.
     *
     * `vida_util_dias` distingue ausente de zero. Enviar a chave com `null`
     * apaga a vida útil ("sem troca programada"); não enviar a chave deixa o
     * valor como está; zero é zero.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function camposDoCadastro(array $dados): array
    {
        $campos = array_intersect_key($dados, array_flip([
            'nome',
            'tipo',
            'fabricante',
            'numero_ca',
            'validade_ca',
            'vida_util_dias',
            'obrigatorio',
            'ativo',
            'observacoes',
        ]));

        if (array_key_exists('nome', $campos)) {
            $campos['nome'] = trim((string) $campos['nome']);
        }

        if (array_key_exists('tipo', $campos)) {
            $campos['tipo'] = $this->tipo($campos['tipo']);
        }

        if (array_key_exists('numero_ca', $campos)) {
            $numero = trim((string) $campos['numero_ca']);
            $campos['numero_ca'] = $numero !== '' ? $numero : null;
        }

        if (array_key_exists('validade_ca', $campos)) {
            $campos['validade_ca'] = BusinessDate::diaDe($campos['validade_ca']);
        }

        if (array_key_exists('vida_util_dias', $campos)) {
            $campos['vida_util_dias'] = $this->vidaUtil($campos['vida_util_dias']);
        }

        return $campos;
    }

    private function garantirNome(mixed $nome): void
    {
        if (trim((string) $nome) === '') {
            throw ValidationException::withMessages([
                'nome' => 'Informe o nome do EPI.',
            ]);
        }
    }

    private function tipo(mixed $valor): string
    {
        $tipo = blank($valor) ? 'outro' : (string) $valor;

        if (! in_array($tipo, PersonalProtectiveEquipment::TIPOS, true)) {
            throw ValidationException::withMessages([
                'tipo' => 'Tipo de EPI inválido.',
            ]);
        }

        return $tipo;
    }

    /**
     * Vida útil em dias. `null` continua `null` — "sem troca programada" é um
     * estado legítimo, e diferente de zero.
     */
    private function vidaUtil(mixed $valor): ?int
    {
        if ($valor === null || $valor === '') {
            return null;
        }

        $dias = (int) $valor;

        if ($dias < 0) {
            throw ValidationException::withMessages([
                'vida_util_dias' => 'A vida útil em dias não pode ser negativa. '
                    .'Deixe em branco quando o EPI não tem troca programada.',
            ]);
        }

        return $dias;
    }
}
