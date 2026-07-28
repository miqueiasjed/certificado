# INDEX - Planos do Sistema de Certificados

> Última atualização: 28/07/2026
> Fonte de requisitos: `.claude/prd/` (índice em `.claude/prd/README.md`)

## Legenda

- ✅ Concluído
- 🔄 Em andamento
- ⏳ Pendente
- 🔒 Bloqueado (dependência não concluída)

## Restrição que vale para todo o roteiro

O sistema **roda em produção para um cliente real**, que é a receita atual do
negócio. Nenhum plano pode interromper a operação dele. Plano que toca schema ou
dado declara como é aplicado com o sistema no ar e como se volta atrás. A partir
do Plano 4 esse cliente é o tenant 1, marcado como interno, imune a cobrança e a
bloqueio.

## Planos

### Base e segurança

| # | Nome | Status | Depende de | Tasks |
|---|------|--------|------------|-------|
| 1 | Rotinas agendadas, datas e correções de base | ✅ | - | 18 |
| 2 | Papéis e permissões | ✅ | - | 20 |
| 3 | Auditoria e histórico de alterações | ✅ | 2 | 9 |

### SaaS multiempresa

| # | Nome | Status | Depende de | Tasks |
|---|------|--------|------------|-------|
| 4 | Fundação multiempresa | ✅ | 1, 2 | 12 |
| 5 | Painel do super admin | ✅ | 3, 4 | 12 |
| 6 | Liberação de módulos por plano | ✅ | 5 | 9 |
| 7 | Assinaturas e cobrança dos tenants (PagBank) | ✅ | 6 | 12 |
| 8 | Onboarding e provisionamento de tenant | ✅ | 7 | 10 |

### Operação de campo

| # | Nome | Status | Depende de | Tasks |
|---|------|--------|------------|-------|
| 9 | Geração automática de visitas do contrato | ✅ | 1 | 9 |
| 10 | Agenda em calendário | ✅ | 2, 9 | 8 |
| 11 | QR code e identificação de dispositivos | ✅ | 4 | 9 |
| 12 | App do técnico: fundação offline | ⏳ | 2, 4, 10 | 12 |
| 13 | App do técnico: execução e assinatura em campo | ⏳ | 3, 11, 12 | 10 |

### Relacionamento com o cliente

| # | Nome | Status | Depende de | Tasks |
|---|------|--------|------------|-------|
| 14 | Central de notificações | ✅ | 1, 9 | 9 |
| 15 | Portal do cliente | ⏳ | 6, 14 | 9 |
| 16 | Agendamento online e pesquisa de satisfação | ⏳ | 10, 14, 15 | 8 |

### Financeiro e fiscal

| # | Nome | Status | Depende de | Tasks |
|---|------|--------|------------|-------|
| 17 | Estoque de produtos com lote, validade e custo | ⏳ | 6, 13 | 10 |
| 18 | Contas a receber e a pagar | ⏳ | 6, 17 | 10 |
| 19 | Cobrança recorrente dos clientes finais | ⏳ | 14, 15, 18 | 8 |
| 20 | Nota fiscal de serviço | ⏳ | 18 | 7 |

### Inteligência e gestão

| # | Nome | Status | Depende de | Tasks |
|---|------|--------|------------|-------|
| 21 | Monitoramento CIP: tendência e mapa de pontos | ⏳ | 6, 13, 15 | 9 |
| 22 | Roteirização e rastreamento em campo | ⏳ | 10, 13 | 8 |
| 23 | Comissões, metas e renovação de contratos | ⏳ | 14, 18 | 9 |
| 24 | Conformidade RDC 622/2022 | ⏳ | 14, 17 | 7 |

### Diferenciais

| # | Nome | Status | Depende de | Tasks |
|---|------|--------|------------|-------|
| 25 | Laudo assistido por IA | ⏳ | 6, 21 | 7 |
| 26 | Assinatura eletrônica de contratos | ⏳ | 14, 15 | 6 |
| 27 | Frota e veículos | ⏳ | 17, 22 | 6 |

Total: 262 tasks decompostas nos 27 planos.

A decomposição real ficou ~40% acima da estimativa inicial de ~185 tasks. O
motivo é o limite de dimensionamento: nenhuma task mistura backend com frontend,
e cada etapa de migração em produção vira task própria porque é um deploy
próprio. Os planos que mais cresceram são os que tocam schema com dado existente
(4, 17, 18) e os que entregam duas camadas independentes (12, 13).

## Ordem de execução recomendada

```
1 -> 2 -> 3 -> 4 -> 5 -> 6 -> 7 -> 8
     |
     +-> 9 -> 10 -> 11 -> 12 -> 13
              |
              +-> 14 -> 15 -> 16
                       |
                       +-> 17 -> 18 -> 19 -> 20
                                 |
                                 +-> 21, 22, 23, 24
                                          |
                                          +-> 25, 26, 27
```

Linear até o 8, porque a base multiempresa é pré-requisito de quase tudo. Depois
as trilhas abrem e podem andar em paralelo, respeitadas as dependências da tabela.

## Prioridade recomendada

Se for para escolher por retorno sobre esforço, e não pela ordem completa:

1. **Plano 1** - corrige bug que já afeta o cliente hoje, custa pouco.
2. **Plano 2** - fecha o risco de acesso aberto ao financeiro.
3. **Plano 9** - o dado já está no banco e não é usado. Melhor retorno por
   esforço do roteiro inteiro.
4. **Plano 4** - a partir daqui o produto pode ser vendido para o segundo cliente.
5. **Plano 10** - primeira melhoria que o cliente atual percebe como ganho.

Planos 12 e 13 são o maior salto competitivo e os mais caros. Vêm depois dos
baratos justamente por isso.

Planos 25, 26 e 27 são últimos por escolha, não por esquecimento: nenhum deles
decide venda hoje.
