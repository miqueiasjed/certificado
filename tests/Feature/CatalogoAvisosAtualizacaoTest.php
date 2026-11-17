<?php

namespace Tests\Feature;

use App\Services\AvisosAtualizacao\CatalogoAvisosAtualizacao;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CatalogoAvisosAtualizacaoTest extends TestCase
{
    private string $pasta;

    protected function setUp(): void
    {
        parent::setUp();

        // 05/11 às 12:00 em São Paulo: o aviso de 05/11 é de hoje e o limite de futuro é 06/11.
        Carbon::setTestNow(Carbon::parse('2026-11-05 15:00:00', 'UTC'));

        $this->pasta = storage_path('framework/testing/avisos-'.uniqid());
        mkdir($this->pasta, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->pasta.'/*') ?: [] as $arquivo) {
            unlink($arquivo);
        }
        if (is_dir($this->pasta)) {
            rmdir($this->pasta);
        }
        Carbon::setTestNow();

        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function aviso(array $sobrescrever = []): array
    {
        return array_merge([
            'id' => '2026-11-05-casa',
            'publicado_em' => '2026-11-05',
            'publicos' => ['gestao'],
            'titulo' => '  Novidades da semana  ',
            'itens' => ['  Primeiro item  ', 'Segundo item'],
        ], $sobrescrever);
    }

    private function gravar(string $nome, array|string $conteudo): string
    {
        $caminho = $this->pasta.'/'.$nome;
        file_put_contents($caminho, is_array($conteudo) ? json_encode($conteudo, JSON_UNESCAPED_UNICODE) : $conteudo);

        return $caminho;
    }

    public function test_arquivo_valido_e_lido_e_normalizado(): void
    {
        $caminho = $this->gravar('2026-11-05-casa.json', $this->aviso());

        $aviso = (new CatalogoAvisosAtualizacao($this->pasta))->lerArquivo($caminho);

        $this->assertSame([
            'id' => '2026-11-05-casa',
            'publicado_em' => '2026-11-05',
            'publicos' => ['gestao'],
            'titulo' => 'Novidades da semana',
            'itens' => ['Primeiro item', 'Segundo item'],
            'tenants' => null,
            'permissao' => null,
        ], $aviso);
    }

    public function test_permissao_opcional_e_lida(): void
    {
        $caminho = $this->gravar('2026-11-05-casa.json', $this->aviso(['permissao' => 'espaco_clinico.view']));

        $aviso = (new CatalogoAvisosAtualizacao($this->pasta))->lerArquivo($caminho);

        $this->assertSame('espaco_clinico.view', $aviso['permissao']);
    }

    public function test_permissao_vazia_ou_fora_do_padrao_e_recusada(): void
    {
        $catalogo = new CatalogoAvisosAtualizacao($this->pasta);

        foreach (['', ' ', 'Espaco.View', 'espaco clinico', 'a/b', str_repeat('a', 101), 1, null, ['x']] as $permissao) {
            $caminho = $this->gravar('2026-11-05-casa.json', $this->aviso(['permissao' => $permissao]));
            try {
                $catalogo->lerArquivo($caminho);
                $this->fail('Deveria recusar permissao '.json_encode($permissao));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('2026-11-05-casa.json', $e->getMessage());
                $this->assertStringContainsString('permissao', $e->getMessage());
            }
        }
    }

    public function test_permissao_com_cem_caracteres_e_aceita(): void
    {
        $permissao = str_repeat('a', 100);
        $caminho = $this->gravar('2026-11-05-casa.json', $this->aviso(['permissao' => $permissao]));

        $this->assertSame($permissao, (new CatalogoAvisosAtualizacao($this->pasta))->lerArquivo($caminho)['permissao']);
    }

    public function test_slug_de_tenant_fora_do_padrao_e_recusado(): void
    {
        $catalogo = new CatalogoAvisosAtualizacao($this->pasta);

        foreach (['Surreal', 'sur real', 'sur_real', 'surreal.com', str_repeat('a', 64)] as $slug) {
            $caminho = $this->gravar('2026-11-05-casa.json', $this->aviso(['tenants' => [$slug]]));
            try {
                $catalogo->lerArquivo($caminho);
                $this->fail('Deveria recusar o slug '.$slug);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('2026-11-05-casa.json', $e->getMessage());
                $this->assertStringContainsString('slug inválido', $e->getMessage());
            }
        }

        $caminho = $this->gravar('2026-11-05-casa.json', $this->aviso(['tenants' => ['jbps-2', str_repeat('a', 63)]]));
        $this->assertSame(['jbps-2', str_repeat('a', 63)], $catalogo->lerArquivo($caminho)['tenants']);
    }

    public function test_publicado_em_ate_amanha_em_sao_paulo_e_aceito(): void
    {
        // 02:30 UTC do dia 06 ainda é 05/11 em São Paulo: amanhã é 06/11.
        Carbon::setTestNow(Carbon::parse('2026-11-06 02:30:00', 'UTC'));
        $caminho = $this->gravar('2026-11-06-casa.json', $this->aviso(['id' => '2026-11-06-casa', 'publicado_em' => '2026-11-06']));

        $this->assertSame('2026-11-06', (new CatalogoAvisosAtualizacao($this->pasta))->lerArquivo($caminho)['publicado_em']);
    }

    public function test_publicado_em_mais_de_um_dia_no_futuro_e_recusado(): void
    {
        // Ainda 05/11 em São Paulo (02:30 UTC do dia 06): 07/11 passa do limite, mesmo sendo amanhã em UTC.
        Carbon::setTestNow(Carbon::parse('2026-11-06 02:30:00', 'UTC'));
        $caminho = $this->gravar('2026-11-07-casa.json', $this->aviso(['id' => '2026-11-07-casa', 'publicado_em' => '2026-11-07']));

        try {
            (new CatalogoAvisosAtualizacao($this->pasta))->lerArquivo($caminho);
            $this->fail('Deveria recusar publicado_em no futuro');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('2026-11-07-casa.json', $e->getMessage());
            $this->assertStringContainsString('publicado_em mais de 1 dia no futuro', $e->getMessage());
        }
    }

    public function test_tenants_opcional_e_lido(): void
    {
        $caminho = $this->gravar('2026-11-05-casa.json', $this->aviso(['tenants' => ['surreal']]));

        $aviso = (new CatalogoAvisosAtualizacao($this->pasta))->lerArquivo($caminho);

        $this->assertSame(['surreal'], $aviso['tenants']);
    }

    public function test_tenants_vazio_ou_repetido_e_recusado(): void
    {
        $catalogo = new CatalogoAvisosAtualizacao($this->pasta);

        foreach ([[], ['a', 'a'], [''], [1]] as $tenants) {
            $caminho = $this->gravar('2026-11-05-casa.json', $this->aviso(['tenants' => $tenants]));
            try {
                $catalogo->lerArquivo($caminho);
                $this->fail('Deveria recusar tenants '.json_encode($tenants));
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('2026-11-05-casa.json', $e->getMessage());
                $this->assertStringContainsString('tenants', $e->getMessage());
            }
        }
    }

    public function test_dois_arquivos_saem_em_ordem_de_data_e_de_id(): void
    {
        $this->gravar('2026-11-06-b.json', $this->aviso(['id' => '2026-11-06-b', 'publicado_em' => '2026-11-06']));
        $this->gravar('2026-11-05-z.json', $this->aviso(['id' => '2026-11-05-z']));
        $this->gravar('2026-11-05-a.json', $this->aviso(['id' => '2026-11-05-a']));

        $ids = array_column((new CatalogoAvisosAtualizacao($this->pasta))->validarTodos(), 'id');

        $this->assertSame(['2026-11-05-a', '2026-11-05-z', '2026-11-06-b'], $ids);
    }

    /** @return array<string, array{0: string, 1: array|string, 2: string}> */
    public static function casosInvalidos(): array
    {
        $base = [
            'id' => '2026-11-05-casa',
            'publicado_em' => '2026-11-05',
            'publicos' => ['gestao'],
            'titulo' => 'Titulo',
            'itens' => ['Item'],
        ];

        return [
            'nome fora do padrão' => ['Aviso.json', $base, 'nome fora do padrão'],
            'chave a mais' => ['2026-11-05-casa.json', $base + ['publico' => 'gestao'], 'chave desconhecida publico'],
            'id diferente do nome' => ['2026-11-05-casa.json', array_merge($base, ['id' => '2026-11-05-outro']), 'id'],
            'data inválida' => ['2026-02-30-casa.json', array_merge($base, ['id' => '2026-02-30-casa', 'publicado_em' => '2026-02-30']), 'publicado_em'],
            'data diferente do id' => ['2026-11-05-casa.json', array_merge($base, ['publicado_em' => '2026-11-06']), 'publicado_em'],
            'público desconhecido' => ['2026-11-05-casa.json', array_merge($base, ['publicos' => ['gestão']]), "publicos tem valor desconhecido 'gestão'"],
            'todos junto de outro' => ['2026-11-05-casa.json', array_merge($base, ['publicos' => ['todos', 'tecnico']]), 'todos'],
            'título vazio' => ['2026-11-05-casa.json', array_merge($base, ['titulo' => '   ']), 'titulo'],
            'nove itens' => ['2026-11-05-casa.json', array_merge($base, ['itens' => array_fill(0, 9, 'Item')]), 'itens'],
            'item com travessão' => ['2026-11-05-casa.json', array_merge($base, ['itens' => ["Ruim \u{2014} aqui"]]), 'itens[0]'],
            'JSON quebrado' => ['2026-11-05-casa.json', '{nao é json', 'JSON inválido'],
        ];
    }

    #[DataProvider('casosInvalidos')]
    public function test_regra_invalida_lanca_com_o_nome_do_arquivo(string $nome, array|string $conteudo, string $trecho): void
    {
        $caminho = $this->gravar($nome, $conteudo);

        try {
            (new CatalogoAvisosAtualizacao($this->pasta))->lerArquivo($caminho);
            $this->fail('Deveria lançar InvalidArgumentException');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($nome, $e->getMessage());
            $this->assertStringContainsString($trecho, $e->getMessage());
        }
    }

    public function test_todos_pula_o_invalido_e_mantem_o_valido(): void
    {
        $this->gravar('2026-11-05-casa.json', $this->aviso());
        $this->gravar('2026-11-06-ruim.json', ['id' => '2026-11-06-ruim']);

        Log::shouldReceive('warning')->once()->with(\Mockery::on(fn ($m) => str_contains($m, '2026-11-06-ruim.json')));

        $ids = array_column((new CatalogoAvisosAtualizacao($this->pasta))->todos(), 'id');

        $this->assertSame(['2026-11-05-casa'], $ids);
    }

    public function test_todos_guarda_o_resultado_na_instancia(): void
    {
        $this->gravar('2026-11-05-casa.json', $this->aviso());
        $catalogo = new CatalogoAvisosAtualizacao($this->pasta);

        $primeira = $catalogo->todos();
        unlink($this->pasta.'/2026-11-05-casa.json');

        $this->assertSame($primeira, $catalogo->todos());
    }

    public function test_pasta_inexistente_devolve_lista_vazia(): void
    {
        $catalogo = new CatalogoAvisosAtualizacao($this->pasta.'/nao-existe');

        $this->assertSame([], $catalogo->validarTodos());
        $this->assertSame([], $catalogo->todos());
    }

    public function test_arquivos_reais_do_repositorio_sao_validos(): void
    {
        // Relógio real: os avisos da pasta são conferidos contra o dia de hoje.
        Carbon::setTestNow();

        $this->assertIsArray((new CatalogoAvisosAtualizacao)->validarTodos());
    }
}
