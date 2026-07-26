# Requisitos do Sistema de Controle de Pragas

> Consolidado em 25/07/2026 a partir do levantamento de mercado e da auditoria do código atual.
> Este diretório é a fonte de requisitos dos planos em `.claude/plans/`.

## Contexto

O sistema roda em produção para **uma empresa de controle de pragas**. A meta é
evoluí-lo para SaaS multiempresa sem interromper nem degradar a operação desse
cliente, que passa a ser o tenant 1.

## Estado atual (o que já existe)

Cobertura boa:

- Hierarquia cliente > endereço > cômodo > dispositivo.
- Ordens de serviço com técnicos, produtos, serviços, cômodos, eventos de
  dispositivo, adequações, fotos, PDF e recibo.
- Orçamento com canal de origem, nível de infestação, PDF e conversão em OS.
- Contrato com periodicidade (`visit_frequency`), garantia e PDF.
- Certificados com validade e status.
- Cadastro técnico regulatório: princípio ativo, grupo químico, antídoto,
  registro em órgão, tipo de isca, tipo de evento.
- Financeiro de caixa: entradas, saídas, saldo diário, fluxo de caixa, painel.
- Dados da empresa e assinatura do responsável técnico nos documentos.

## Lacunas frente ao mercado

Levantadas contra iPragas, Loop, PragSystem, VEL, SIS Controladoras, PestWase,
Servicfy, e-pragas (Brasil) e Briostack, FieldRoutes, GorillaDesk, PestPac
(exterior).

Bloqueadores comerciais:

| Lacuna | Plano |
|---|---|
| Papéis e permissões (hoje qualquer usuário abre o financeiro) | 2 |
| Multiempresa real | 4 a 8 |
| Geração automática das visitas do contrato | 9 |
| Agenda visual em calendário | 10 |
| App do técnico offline com assinatura do cliente | 12, 13 |
| QR code nos dispositivos | 11 |
| Notificações automáticas | 14 |
| Portal do cliente | 15 |

Importantes: estoque com lote e custo (17), contas a receber e pagar (18),
cobrança recorrente (19), NFS-e (20), tendência de monitoramento CIP e mapa de
pontos (21), roteirização (22), comissões e metas (23).

Conformidade: a RDC 52/2009 foi substituída pela RDC 622/2022, vigente desde
01/04/2022. Documentos emitidos precisam de revisão (24).

Diferenciais: laudo assistido por IA (25), assinatura eletrônica de contrato
(26), agendamento online e NPS (16), frota (27).

## Decisões de arquitetura já tomadas

1. **Isolamento multi-tenant**: banco único com `company_id` em toda tabela de
   domínio, mais escopo global no Eloquent. Decidido pela migração incremental e
   reversível do cliente atual, e por manter um só backup, deploy e conjunto de
   migrations. Detalhes em `saas-multitenant.md`.
2. **Cobrança das assinaturas dos tenants**: PagBank, atrás de uma interface de
   gateway para permitir troca futura.
3. **Notificações**: e-mail e link `wa.me` clicável agora; o envio de WhatsApp
   entra depois como driver da mesma fila, sem refazer templates.

## Fragmentos

| Arquivo | Domínio |
|---|---|
| `saas-multitenant.md` | Isolamento, migração, planos, módulos, assinaturas |
| `operacao-campo.md` | Contrato recorrente, agenda, app do técnico, QR code |
| `relacionamento-cliente.md` | Notificações, portal, agendamento online, NPS |
| `financeiro-fiscal.md` | Estoque, contas a receber e pagar, cobrança, NFS-e |
| `monitoramento-cip.md` | Tendência de infestação, mapa de pontos, RDC 622/2022 |
| `gestao-comercial.md` | Comissões, metas, renovação de contrato, frota |
| `divida-tecnica.md` | Correções pendentes mapeadas na auditoria |
