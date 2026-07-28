<?php

namespace App\Support;

/**
 * Fonte única do catálogo de módulos controláveis do produto (Plano 6).
 *
 * `ModulesSeeder`, o middleware que bloqueia acesso por módulo (Task 6.4) e os
 * testes leem este mesmo método, no critério já estabelecido por
 * `SyncPermissions::catalogo()` para permissões: nenhum outro lugar do código
 * redeclara chave, nome ou a marca de `sempre_ativo` de um módulo.
 *
 * A ordem de declaração é a ordem de exibição (o seeder grava em `ordem` pela
 * posição no array) e começa pelos três módulos `sempre_ativo`: clientes,
 * ordens de serviço e certificados são o produto mínimo, não um extra que um
 * plano possa bloquear.
 */
final class CatalogoDeModulos
{
    /**
     * @return array<int, array{chave: string, nome: string, descricao: string, sempre_ativo: bool}>
     */
    public static function catalogo(): array
    {
        return [
            ['chave' => 'clientes', 'nome' => 'Clientes e endereços', 'descricao' => 'Cadastro de clientes, endereços e cômodos.', 'sempre_ativo' => true],
            ['chave' => 'ordens_servico', 'nome' => 'Ordens de serviço', 'descricao' => 'Abertura e acompanhamento das ordens de serviço.', 'sempre_ativo' => true],
            ['chave' => 'certificados', 'nome' => 'Certificados', 'descricao' => 'Emissão dos certificados de controle de pragas.', 'sempre_ativo' => true],
            ['chave' => 'financeiro', 'nome' => 'Financeiro', 'descricao' => 'Contas a receber, pagamentos e fluxo de caixa da empresa.', 'sempre_ativo' => false],
            ['chave' => 'estoque', 'nome' => 'Estoque', 'descricao' => 'Controle de produtos, insumos e movimentações de estoque.', 'sempre_ativo' => false],
            ['chave' => 'contratos', 'nome' => 'Contratos', 'descricao' => 'Geração e gestão de contratos de prestação de serviço.', 'sempre_ativo' => false],
            ['chave' => 'portal_cliente', 'nome' => 'Portal do cliente', 'descricao' => 'Um espaço onde o seu cliente acompanha ordens de serviço e certificados.', 'sempre_ativo' => false],
            ['chave' => 'app_tecnico', 'nome' => 'App do técnico', 'descricao' => 'Aplicativo para o técnico registrar visitas e serviços em campo.', 'sempre_ativo' => false],
            ['chave' => 'notificacoes', 'nome' => 'Notificações', 'descricao' => 'Central de avisos automáticos por e-mail e WhatsApp para os clientes.', 'sempre_ativo' => false],
            ['chave' => 'roteirizacao', 'nome' => 'Roteirização', 'descricao' => 'Organização automática das rotas de visita dos técnicos.', 'sempre_ativo' => false],
            ['chave' => 'nfse', 'nome' => 'Nota fiscal de serviço', 'descricao' => 'Emissão de nota fiscal de serviço integrada às ordens de serviço.', 'sempre_ativo' => false],
            ['chave' => 'laudo_ia', 'nome' => 'Laudo por IA', 'descricao' => 'Geração assistida por inteligência artificial de laudos técnicos.', 'sempre_ativo' => false],
            ['chave' => 'monitoramento', 'nome' => 'Relatórios de monitoramento', 'descricao' => 'Relatórios consolidados de monitoramento de pragas por período.', 'sempre_ativo' => false],
        ];
    }
}
