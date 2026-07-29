<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use Illuminate\Validation\ValidationException;

/**
 * Plano de contas (Plano 18, Task 18.7): as regras da árvore de categorias de
 * receita e de despesa.
 *
 * Três regras, e as três existem para o relatório por categoria continuar
 * fazendo sentido seis meses depois:
 *
 * 1. **Categoria com uso não é excluída, é desativada.** Apagar categoria com
 *    título vinculado deixaria o título órfão e quebraria todo relatório por
 *    categoria. A exclusão só passa para categoria sem nenhum uso e sem
 *    subcategoria; para o resto existe `ativo = false`, que a tira dos
 *    seletores sem apagar o histórico. O banco já recusa a exclusão de
 *    categoria com subcategoria (`restrictOnDelete` em `parent_id`); aqui a
 *    recusa vira mensagem em português em vez de erro de integridade.
 *
 * 2. **Subcategoria tem o tipo da mãe.** "Despesas > Aluguel" classificado
 *    como receita é o tipo de incoerência que ninguém percebe até o
 *    demonstrativo sair com a despesa somando na receita.
 *
 * 3. **A hierarquia não fecha em ciclo.** Categoria que é mãe da própria mãe
 *    trava qualquer leitura recursiva da árvore, inclusive a da tela.
 *
 * Este Service não consulta `Auth` nem resolve empresa: quem chama entrega os
 * models já escopados. O isolamento entre empresas vem do escopo global de
 * `ChartOfAccount` (`BelongsToCompany`).
 */
class ChartOfAccountService
{
    /**
     * Teto de saltos ao subir a hierarquia procurando ciclo. Nada de negócio:
     * é a trava que impede um dado já inconsistente (ciclo criado por fora,
     * em importação) de virar laço infinito aqui dentro.
     */
    private const PROFUNDIDADE_MAXIMA = 20;

    /**
     * Cria a categoria.
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws ValidationException Mãe de tipo diferente.
     */
    public function criar(array $dados, ?ChartOfAccount $mae = null): ChartOfAccount
    {
        $this->garantirMesmoTipo($dados['tipo'], $mae);

        return ChartOfAccount::create([
            'codigo' => $dados['codigo'],
            'nome' => $dados['nome'],
            'tipo' => $dados['tipo'],
            'parent_id' => $mae?->getKey(),
            // Categoria nasce ativa: quem cadastra está cadastrando para usar.
            'ativo' => array_key_exists('ativo', $dados) ? (bool) $dados['ativo'] : true,
        ]);
    }

    /**
     * Atualiza a categoria, inclusive a posição dela na árvore.
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws ValidationException Mãe de tipo diferente, mãe sendo a própria
     *                             categoria ou uma descendente dela.
     */
    public function atualizar(ChartOfAccount $conta, array $dados, ?ChartOfAccount $mae = null): ChartOfAccount
    {
        $this->garantirMesmoTipo($dados['tipo'], $mae);
        $this->garantirHierarquiaSemCiclo($conta, $mae);

        $conta->update([
            'codigo' => $dados['codigo'],
            'nome' => $dados['nome'],
            'tipo' => $dados['tipo'],
            'parent_id' => $mae?->getKey(),
            'ativo' => array_key_exists('ativo', $dados) ? (bool) $dados['ativo'] : $conta->ativo,
        ]);

        return $conta->fresh();
    }

    /**
     * Exclui a categoria, se ela não tiver nenhum uso.
     *
     * @throws ValidationException Categoria com subcategoria ou com título
     *                             vinculado, com a instrução de desativar.
     */
    public function excluir(ChartOfAccount $conta): void
    {
        if ($conta->children()->exists()) {
            throw ValidationException::withMessages([
                'conta' => 'Esta categoria tem subcategorias. Mova ou exclua as subcategorias antes, '
                    .'ou desative a categoria para tirá-la dos seletores sem apagar o histórico.',
            ]);
        }

        $emUso = $conta->receivables()->exists() || $conta->payables()->exists();

        if ($emUso) {
            throw ValidationException::withMessages([
                'conta' => 'Esta categoria já classifica títulos e por isso não pode ser excluída. '
                    .'Desative-a: ela sai dos seletores e o histórico continua classificado.',
            ]);
        }

        $conta->delete();
    }

    /**
     * @throws ValidationException
     */
    private function garantirMesmoTipo(mixed $tipo, ?ChartOfAccount $mae): void
    {
        if ($mae === null || $mae->tipo === $tipo) {
            return;
        }

        throw ValidationException::withMessages([
            'parent_id' => sprintf(
                'A categoria-mãe é do tipo "%s" e esta é do tipo "%s". Subcategoria tem sempre o mesmo tipo da mãe.',
                $mae->tipo,
                (string) $tipo
            ),
        ]);
    }

    /**
     * @throws ValidationException
     */
    private function garantirHierarquiaSemCiclo(ChartOfAccount $conta, ?ChartOfAccount $mae): void
    {
        if ($mae === null) {
            return;
        }

        if ((int) $mae->getKey() === (int) $conta->getKey()) {
            throw ValidationException::withMessages([
                'parent_id' => 'Uma categoria não pode ser a própria categoria-mãe.',
            ]);
        }

        $ancestral = $mae;

        for ($salto = 0; $salto < self::PROFUNDIDADE_MAXIMA; $salto++) {
            if ($ancestral->parent_id === null) {
                return;
            }

            if ((int) $ancestral->parent_id === (int) $conta->getKey()) {
                throw ValidationException::withMessages([
                    'parent_id' => 'A categoria-mãe escolhida está abaixo desta categoria na árvore. '
                        .'Isso fecharia a hierarquia em ciclo.',
                ]);
            }

            $ancestral = $ancestral->parent()->first();

            if ($ancestral === null) {
                return;
            }
        }

        throw ValidationException::withMessages([
            'parent_id' => 'A hierarquia do plano de contas está profunda demais para ser conferida. '
                .'Reveja as categorias-mãe antes de continuar.',
        ]);
    }
}
