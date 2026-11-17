<?php

namespace Tests\Support;

use App\Services\AvisosAtualizacao\CatalogoAvisosAtualizacao;

/**
 * Aponta o catálogo de avisos de atualização para uma pasta temporária, para que os testes não
 * dependam dos arquivos reais de `resources/avisos-atualizacao/`.
 *
 * O catálogo guarda a leitura na instância: os avisos precisam estar gravados antes da primeira
 * requisição do teste. Chamar `apagarPastaDeAvisos()` no `tearDown`.
 */
trait CriaAvisosAtualizacao
{
    private ?string $pastaDeAvisos = null;

    protected function usarPastaDeAvisos(): string
    {
        $this->pastaDeAvisos = storage_path('framework/testing/avisos-'.uniqid());
        mkdir($this->pastaDeAvisos, 0777, true);

        $this->app->instance(CatalogoAvisosAtualizacao::class, new CatalogoAvisosAtualizacao($this->pastaDeAvisos));

        return $this->pastaDeAvisos;
    }

    /**
     * Grava `{id}.json` com `publicado_em` igual à data do id. `tenants` e `permissao` nulos deixam
     * a chave de fora.
     *
     * @param  list<string>  $publicos
     * @param  list<string>  $itens
     * @param  list<string>|null  $tenants
     */
    protected function criarAviso(
        string $id,
        array $publicos,
        string $titulo = 'Título',
        array $itens = ['Item'],
        ?array $tenants = null,
        ?string $permissao = null,
    ): void {
        $dados = [
            'id' => $id,
            'publicado_em' => substr($id, 0, 10),
            'publicos' => $publicos,
            'titulo' => $titulo,
            'itens' => $itens,
        ];

        if ($tenants !== null) {
            $dados['tenants'] = $tenants;
        }

        if ($permissao !== null) {
            $dados['permissao'] = $permissao;
        }

        file_put_contents(
            $this->pastaDeAvisos.'/'.$id.'.json',
            json_encode($dados, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        );
    }

    protected function apagarPastaDeAvisos(): void
    {
        if ($this->pastaDeAvisos === null || ! is_dir($this->pastaDeAvisos)) {
            return;
        }

        foreach (glob($this->pastaDeAvisos.'/*') ?: [] as $arquivo) {
            unlink($arquivo);
        }

        rmdir($this->pastaDeAvisos);
        $this->pastaDeAvisos = null;
    }
}
