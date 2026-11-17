<?php

namespace App\Services\AvisosAtualizacao;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Lê os avisos de atualização versionados em `resources/avisos-atualizacao/*.json`.
 *
 * Um arquivo por aviso. O formato é validado de forma estrita (`lerArquivo`, `validarTodos`),
 * que o teste do repositório usa para barrar aviso mal escrito antes do commit. Em produção,
 * `todos()` é tolerante: arquivo inválido sai da lista e vai para o log, para que um erro de
 * digitação nunca derrube a entrada de ninguém. Sem dependência de banco.
 *
 * `publicado_em` é data de negócio no Brasil: só `Y-m-d`, sem hora e sem fuso. Aceita no máximo
 * amanhã (hoje em São Paulo mais um dia), para o aviso de um deploy que passa da meia-noite.
 *
 * Chaves opcionais: `tenants` (`slug_publico` das empresas que recebem; sem a chave, todas) e `permissao` (nome de uma
 * permissão Spatie que a pessoa precisa ter; sem a chave, o público inteiro recebe).
 */
final class CatalogoAvisosAtualizacao
{
    private const PADRAO_NOME = '/^\d{4}-\d{2}-\d{2}-[a-z0-9]+(-[a-z0-9]+)*\.json$/';

    private const CHAVES_EXATAS = ['id', 'publicado_em', 'publicos', 'titulo', 'itens'];

    private const CHAVES_OPCIONAIS = ['tenants', 'permissao'];

    private const FUSO_NEGOCIO = 'America/Sao_Paulo';

    private const PADRAO_SLUG = '/^[a-z0-9-]{1,63}$/';

    private const PADRAO_PERMISSAO = '/^[a-z0-9_.\-]+$/';

    private const PERMISSAO_MAX = 100;

    private const TITULO_MAX = 120;

    private const ITEM_MAX = 300;

    private const ITENS_MAX = 8;

    private string $diretorio;

    /** @var list<array<string, mixed>>|null */
    private ?array $cache = null;

    public function __construct(?string $diretorio = null)
    {
        $this->diretorio = rtrim($diretorio ?? resource_path('avisos-atualizacao'), '/');
    }

    /**
     * Lê e valida um arquivo. Qualquer falha lança InvalidArgumentException com o nome do
     * arquivo e o campo.
     *
     * @return array{id: string, publicado_em: string, publicos: list<string>, titulo: string, itens: list<string>, tenants: list<string>|null, permissao: string|null}
     */
    public function lerArquivo(string $caminho): array
    {
        $nome = basename($caminho);

        if (preg_match(self::PADRAO_NOME, $nome) !== 1) {
            throw new InvalidArgumentException("{$nome}: nome fora do padrão AAAA-MM-DD-slug.json");
        }

        $conteudo = is_file($caminho) ? file_get_contents($caminho) : false;
        if ($conteudo === false) {
            throw new InvalidArgumentException("{$nome}: arquivo não pôde ser lido");
        }

        $dados = json_decode($conteudo, true);
        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($dados) || array_is_list($dados)) {
            throw new InvalidArgumentException("{$nome}: JSON inválido ou não é um objeto");
        }

        $chaves = array_keys($dados);
        $permitidas = [...self::CHAVES_EXATAS, ...self::CHAVES_OPCIONAIS];
        $faltando = array_diff(self::CHAVES_EXATAS, $chaves);
        $sobrando = array_diff($chaves, $permitidas);
        if ($faltando !== [] || $sobrando !== []) {
            $detalhe = [];
            if ($faltando !== []) {
                $detalhe[] = 'faltam '.implode(', ', $faltando);
            }
            if ($sobrando !== []) {
                $detalhe[] = 'chave desconhecida '.implode(', ', $sobrando);
            }

            throw new InvalidArgumentException("{$nome}: chaves inválidas (".implode('; ', $detalhe).')');
        }

        $id = $dados['id'];
        if (! is_string($id) || $id !== substr($nome, 0, -5)) {
            throw new InvalidArgumentException("{$nome}: id deve ser igual ao nome do arquivo sem .json");
        }

        $publicadoEm = $dados['publicado_em'];
        if (! is_string($publicadoEm) || ! $this->dataValida($publicadoEm)) {
            throw new InvalidArgumentException("{$nome}: publicado_em deve ser uma data válida no formato AAAA-MM-DD");
        }
        if ($publicadoEm !== substr($id, 0, 10)) {
            throw new InvalidArgumentException("{$nome}: publicado_em difere da data do id");
        }
        $limite = Carbon::now(self::FUSO_NEGOCIO)->addDay()->toDateString();
        if ($publicadoEm > $limite) {
            throw new InvalidArgumentException("{$nome}: publicado_em mais de 1 dia no futuro (limite {$limite})");
        }

        $publicos = $this->validarPublicos($nome, $dados['publicos']);
        $titulo = $this->validarTexto($nome, 'titulo', $dados['titulo'], self::TITULO_MAX);

        $itens = $dados['itens'];
        if (! is_array($itens) || ! array_is_list($itens) || count($itens) < 1 || count($itens) > self::ITENS_MAX) {
            throw new InvalidArgumentException("{$nome}: itens deve ser uma lista de 1 a ".self::ITENS_MAX.' textos');
        }
        $itens = array_map(
            fn ($item, $i) => $this->validarTexto($nome, 'itens['.$i.']', $item, self::ITEM_MAX),
            $itens,
            array_keys($itens),
        );

        return [
            'id' => $id,
            'publicado_em' => $publicadoEm,
            'publicos' => $publicos,
            'titulo' => $titulo,
            'itens' => $itens,
            'tenants' => array_key_exists('tenants', $dados) ? $this->validarTenants($nome, $dados['tenants']) : null,
            'permissao' => array_key_exists('permissao', $dados) ? $this->validarPermissao($nome, $dados['permissao']) : null,
        ];
    }

    /**
     * Lê todos os arquivos da pasta, em ordem, e lança na primeira falha. Pasta inexistente
     * devolve lista vazia.
     *
     * @return list<array<string, mixed>>
     */
    public function validarTodos(): array
    {
        $avisos = [];
        foreach ($this->arquivos() as $caminho) {
            $avisos[] = $this->lerArquivo($caminho);
        }

        return $this->ordenar($avisos);
    }

    /**
     * Versão tolerante, usada em produção: arquivo inválido sai da lista e vai para o log. O
     * resultado fica na instância para não reler a pasta na mesma requisição.
     *
     * @return list<array<string, mixed>>
     */
    public function todos(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $avisos = [];
        foreach ($this->arquivos() as $caminho) {
            try {
                $avisos[] = $this->lerArquivo($caminho);
            } catch (InvalidArgumentException $e) {
                Log::warning($e->getMessage());
            }
        }

        return $this->cache = $this->ordenar($avisos);
    }

    /** @return list<string> */
    private function arquivos(): array
    {
        if (! is_dir($this->diretorio)) {
            return [];
        }

        $arquivos = glob($this->diretorio.'/*.json') ?: [];
        sort($arquivos);

        return $arquivos;
    }

    /**
     * @param  list<array<string, mixed>>  $avisos
     * @return list<array<string, mixed>>
     */
    private function ordenar(array $avisos): array
    {
        usort($avisos, fn (array $a, array $b) => [$a['publicado_em'], $a['id']] <=> [$b['publicado_em'], $b['id']]);

        return $avisos;
    }

    private function dataValida(string $data): bool
    {
        $objeto = DateTimeImmutable::createFromFormat('!Y-m-d', $data);

        return $objeto !== false && $objeto->format('Y-m-d') === $data;
    }

    /** @return list<string> */
    private function validarPublicos(string $nome, mixed $publicos): array
    {
        if (! is_array($publicos) || ! array_is_list($publicos) || $publicos === []) {
            throw new InvalidArgumentException("{$nome}: publicos deve ser uma lista não vazia");
        }

        foreach ($publicos as $publico) {
            if (! is_string($publico) || (! in_array($publico, PublicosDoUsuario::VALIDOS, true) && $publico !== PublicosDoUsuario::TODOS)) {
                $mostrado = is_string($publico) ? $publico : gettype($publico);
                throw new InvalidArgumentException("{$nome}: publicos tem valor desconhecido '{$mostrado}'");
            }
        }

        if (count($publicos) !== count(array_unique($publicos))) {
            throw new InvalidArgumentException("{$nome}: publicos tem valor repetido");
        }

        if (in_array(PublicosDoUsuario::TODOS, $publicos, true) && count($publicos) > 1) {
            throw new InvalidArgumentException("{$nome}: publicos 'todos' deve vir sozinho");
        }

        return $publicos;
    }

    /** @return list<string> */
    private function validarTenants(string $nome, mixed $tenants): array
    {
        if (! is_array($tenants) || ! array_is_list($tenants) || $tenants === []) {
            throw new InvalidArgumentException("{$nome}: tenants deve ser uma lista não vazia de slugs");
        }

        foreach ($tenants as $slug) {
            if (! is_string($slug) || trim($slug) === '') {
                throw new InvalidArgumentException("{$nome}: tenants deve ter só textos não vazios");
            }
            if (preg_match(self::PADRAO_SLUG, trim($slug)) !== 1) {
                throw new InvalidArgumentException("{$nome}: tenants tem slug inválido '{$slug}' (só a-z, 0-9 e hífen, até 63 caracteres)");
            }
        }

        $limpos = array_map('trim', $tenants);
        if (count($limpos) !== count(array_unique($limpos))) {
            throw new InvalidArgumentException("{$nome}: tenants tem valor repetido");
        }

        return $limpos;
    }

    private function validarPermissao(string $nome, mixed $permissao): string
    {
        if (! is_string($permissao) || $permissao === '') {
            throw new InvalidArgumentException("{$nome}: permissao deve ser um texto não vazio");
        }

        if (strlen($permissao) > self::PERMISSAO_MAX || preg_match(self::PADRAO_PERMISSAO, $permissao) !== 1) {
            throw new InvalidArgumentException("{$nome}: permissao inválida (só a-z, 0-9, ponto, sublinhado e hífen, até ".self::PERMISSAO_MAX.' caracteres)');
        }

        return $permissao;
    }

    private function validarTexto(string $nome, string $campo, mixed $valor, int $maximo): string
    {
        if (! is_string($valor)) {
            throw new InvalidArgumentException("{$nome}: {$campo} deve ser texto");
        }

        $texto = trim($valor);
        $tamanho = mb_strlen($texto);
        if ($tamanho < 1 || $tamanho > $maximo) {
            throw new InvalidArgumentException("{$nome}: {$campo} deve ter de 1 a {$maximo} caracteres");
        }

        if (str_contains($texto, "\u{2014}") || str_contains($texto, "\u{2013}")) {
            throw new InvalidArgumentException("{$nome}: {$campo} tem travessão ou traço médio (use vírgula, dois-pontos ou hífen)");
        }

        return $texto;
    }
}
