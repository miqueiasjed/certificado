// Esquema local do aplicativo do técnico (Plano 12, Task 12.7).
//
// Dexie envolve o IndexedDB com uma API que fecha a transação sozinha quando
// uma promise dentro dela escapa sem `await`, em vez de deixar a transação
// aberta até o navegador decidir o que fazer com ela. É esse comportamento
// solto do IndexedDB puro que corrompe base local em Safari, e é a razão de
// não usar o IndexedDB direto aqui.
//
// Tabelas (todas sem dado financeiro: a carga do backend já não traz isso,
// ver AppDayLoadService):
//
// - `ordens`: a ordem de serviço em si (cliente, endereço, serviços e
//   produtos previstos ficam embutidos no próprio registro, como a carga
//   entrega). Chave primária: o id da ordem.
// - `enderecos`: cópia normalizada do endereço de cada ordem, uma linha por
//   endereço (várias ordens do mesmo cliente compartilham o mesmo endereço).
// - `comodos`: um cômodo dentro de uma ordem específica. O mesmo cômodo
//   físico aparece em ordens diferentes com observação e evento de visita
//   diferentes a cada vez (é dado de pivot, não do cadastro do cômodo), por
//   isso a chave primária é o par `[work_order_id+id]`, e não só `id`.
// - `dispositivos`: mesma lógica de `comodos`, um dispositivo dentro de uma
//   ordem específica. Índice em `codigo_publico` porque o leitor de código
//   do Plano 11 precisa achar o dispositivo offline a partir do código lido,
//   sem saber de antemão a qual ordem ele pertence.
// - `tipos_de_evento`, `tipos_de_isca`, `pragas`, `produtos`: catálogos de
//   apoio, cada um com o id do próprio registro como chave primária.
// - `meta`: par chave/valor genérico. Guarda `ultima_carga` (instante da
//   última carga aplicada com sucesso, ver `repositorio.js`), `versao_schema`
//   (escrita abaixo, sempre que o banco abre), `empresa`, `periodo_carregado`
//   e `ordens_tem_mais` (os dois últimos vêm da carga). A Task 12.9 usa a
//   mesma tabela para `usuario_logado` e para o token Sanctum (chave
//   `token`); nenhuma tabela nova é necessária para isso.
//
// - `fila`: a fila de sincronização da Task 12.8, criada nesta versão 2 do
//   schema. Chave primária `uuid` (o mesmo uuid gerado no celular no momento
//   do registro, nunca muda entre tentativas - é o que torna o reenvio seguro
//   no servidor, ver `sync/fila.js`). Índices em `work_order_id`, `situacao`
//   (para listar pendentes/em conflito/com falha) e `registrada_em` (para
//   enviar em lotes na ordem em que o técnico registrou).
// - `fotos`: fila própria da foto (Task 12.11, versão 3 do schema), separada
//   de `fila` de propósito: a foto nunca entra no lote de 50 operações (o
//   arquivo binário viajaria em base64 e aumentaria o tamanho em um terço, ver
//   `.claude/tasks/12/12.5.md`), então tem ciclo de envio próprio, um registro
//   de cada vez, em `sync/foto.js`. Chave primária `uuid` (gerado no momento
//   da captura, mesma razão do uuid de `fila`). Guarda o `Blob` já comprimido
//   (nunca o arquivo original, descartado depois da compressão), o `contexto`
//   (`{ entity_type, entity_id, room_id }`, o mesmo formato aceito por
//   `POST /api/app/fotos`) e a `legenda`, editável offline enquanto a foto não
//   foi enviada. Índices em `work_order_id` (listar as fotos de uma ordem) e
//   `situacao` (varrer pendentes para envio e contar o total para o aviso de
//   200 fotos pendentes).
//
// Migração de esquema é sempre versionada pelo Dexie: uma tabela nova é uma
// versão nova que repete as tabelas da anterior inalteradas e acrescenta a
// própria (é o padrão usado abaixo, para as versões 2 e 3). Este arquivo nunca
// apaga o banco para "resolver" uma versão incompatível: apagar a base local
// só acontece no logout explícito, com a fila vazia (ver `limparBaseLocal()`
// em `repositorio.js`), nunca como reação a um erro de schema.

import Dexie from 'dexie';

export const NOME_DO_BANCO = 'app-tecnico';

export const db = new Dexie(NOME_DO_BANCO);

db.version(1).stores({
    ordens: 'id, data_agendada, status, updated_at',
    enderecos: 'id',
    comodos: '[work_order_id+id], work_order_id, address_id',
    dispositivos: '[work_order_id+id], work_order_id, address_id, codigo_publico',
    tipos_de_evento: 'id, updated_at',
    tipos_de_isca: 'id, updated_at',
    pragas: 'id',
    produtos: 'id, updated_at',
    meta: 'chave',
});

// Versão 2 (Task 12.8): acrescenta a fila de sincronização. Todas as tabelas
// da versão 1 são repetidas aqui, inalteradas - é assim que o Dexie exige a
// migração aditiva, e é o padrão que este arquivo já documentava antes de a
// tabela existir de fato.
db.version(2).stores({
    ordens: 'id, data_agendada, status, updated_at',
    enderecos: 'id',
    comodos: '[work_order_id+id], work_order_id, address_id',
    dispositivos: '[work_order_id+id], work_order_id, address_id, codigo_publico',
    tipos_de_evento: 'id, updated_at',
    tipos_de_isca: 'id, updated_at',
    pragas: 'id',
    produtos: 'id, updated_at',
    meta: 'chave',
    fila: 'uuid, work_order_id, situacao, registrada_em',
});

// Versão 3 (Task 12.11): acrescenta a fila própria de fotos. Todas as tabelas
// das versões 1 e 2 são repetidas aqui, inalteradas, pelo mesmo motivo da
// versão 2 acima.
db.version(3).stores({
    ordens: 'id, data_agendada, status, updated_at',
    enderecos: 'id',
    comodos: '[work_order_id+id], work_order_id, address_id',
    dispositivos: '[work_order_id+id], work_order_id, address_id, codigo_publico',
    tipos_de_evento: 'id, updated_at',
    tipos_de_isca: 'id, updated_at',
    pragas: 'id',
    produtos: 'id, updated_at',
    meta: 'chave',
    fila: 'uuid, work_order_id, situacao, registrada_em',
    fotos: 'uuid, work_order_id, situacao',
});

// Uma aba/aparelho mais novo pode abrir o aplicativo com uma versão maior do
// schema (depois de um deploy). Fechar esta conexão nesse momento é o que o
// próprio Dexie recomenda para evitar o erro "blocked" do IndexedDB, e é
// inofensivo: a aba antiga só volta a abrir o banco quando o técnico recarregar
// a página, já com o código novo.
db.on('versionchange', () => {
    db.close();
});

// Grava a versão do schema em `meta` toda vez que o banco abre, para a Task
// 12.9 (ou uma futura tela de diagnóstico) conseguir mostrar isso sem precisar
// duplicar o número aqui.
db.on('ready', async () => {
    await db.meta.put({ chave: 'versao_schema', valor: db.verno });
});

export default db;
