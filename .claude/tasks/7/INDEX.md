# Tasks do Plano 7 - Assinaturas e cobrança dos tenants (PagBank)

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 7.1 | Migrations e models de assinatura, fatura e evento | backend-estrutura | ✅ | média |
| 7.2 | Interface GatewayAssinatura e objetos de transporte | backend-estrutura | ✅ | média |
| 7.2b | Fix: registrar meio de pagamento (cartão) no contrato do gateway | backend-estrutura | ✅ | baixa |
| 7.3 | Implementação PagBank do gateway | backend-logica | ✅ | alta |
| 7.4 | Service de assinatura do tenant | backend-logica | ✅ | média |
| 7.5 | Geração de faturas por período | backend-logica | ✅ | média |
| 7.6 | Webhook idempotente do gateway | backend-endpoint | ✅ | alta |
| 7.7 | Régua de inadimplência com bloqueio reversível | backend-logica | ✅ | alta |
| 7.8 | Endpoints do plano do tenant e do painel de receita | backend-endpoint | ✅ | média |
| 7.9 | Tela de plano e faturas do tenant | frontend-pagina | ✅ | alta |
| 7.10 | Painel de receita da plataforma | frontend-pagina | ✅ | média |
| 7.11 | Testes de assinatura, webhook e imunidade do interno | teste | ✅ | alta |

## Ordem de execução

```
Lote 1 (paralelo):  7.1  |  7.2
Lote 2:             7.3
Lote 3:             7.4
Lote 4:             7.5
Lote 5:             7.6                  (toca routes/web.php)
Lote 6:             7.7
Lote 7:             7.8                  (toca routes/web.php, depois de 7.6)
Lote 8 (paralelo):  7.9  |  7.10
Lote 9:             7.11
```

## Dependências internas

- 7.3 depende de 7.2 (contrato)
- 7.4 depende de 7.1 e 7.2
- 7.5 depende de 7.4
- 7.6 depende de 7.5 (`marcarComoPaga`)
- 7.7 depende de 7.5 e do `TenantService` do Plano 5
- 7.8 depende de 7.4, 7.5 e 7.7
- 7.9 depende de 7.8 e de 7.7 (página de conta suspensa)
- 7.10 depende de 7.8
- 7.11 depende de 7.4, 7.5, 7.6 e 7.7

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/web.php` | 7.6, 7.8 | 7.6 antes de 7.8 |
| `app/Support/RotinasAgendadas.php` | 7.5, 7.7 | Uma por vez; ambas acrescentam rotina |
| `app/Support/DominioMultiempresa.php` | 7.1 | Acrescenta 3 tabelas fora do escopo |
| `bootstrap/app.php` | 7.7 | Registro do middleware de conta ativa |

## Decisões registradas

- **Tabelas de assinatura ficam fora do escopo global por empresa.** Elas são da plataforma. O tenant vê as próprias faturas por filtro explícito no controller, e o teste do caso 2 da Task 7.8 é o que prova que o filtro está lá.
- **Webhook devolve 200 mesmo quando o processamento falha.** O evento já está guardado e há comando de reprocessamento. Devolver 500 faz o provedor reenviar em laço.
- **A tela de faturas continua acessível com a conta suspensa.** Bloquear o caminho do pagamento é o erro mais caro possível numa régua de cobrança.
- **Aviso de vencimento fica em log até o Plano 14 existir.** A central de notificações é quem envia de fato; o ponto de integração está anotado na Task 7.7.
- **Dado de cartão não passa pelo servidor.** Tokenização no frontend, servidor recebe só o token. Se a API do PagBank exigir outro fluxo, isso precisa ser reportado antes de implementar.

## Ordem de aplicação em produção

1. Deploy das migrations (7.1). Tabelas novas, sem efeito.
2. Deploy do código com o gateway em **sandbox**. Conferir uma assinatura e uma cobrança de ponta a ponta com um tenant de teste.
3. Conferir, antes de qualquer coisa, que o tenant 1 continua com `is_internal = true` e **sem** assinatura: `select * from subscriptions where company_id = 1` precisa voltar vazio.
4. Só então trocar a configuração para produção e cadastrar o webhook no painel do PagBank.
5. Nas primeiras semanas, conferir `gateway_events` com `erro` preenchido diariamente.

## Observações

- O plano estimava ~8 tasks. A decomposição chegou a 11: gateway (contrato e implementação), assinatura, fatura, webhook e régua são responsabilidades distintas, e nenhuma cabe agrupada com outra sem virar um arquivo grande demais para um subagente.
- `TenantInternoImuneTest` é o portão de subida deste plano. Ele existe porque um erro na régua custa a receita atual do negócio.
- A permissão `assinatura-gerenciar` precisa entrar no catálogo do comando `permissions:sync` (Plano 2).

## Encerramento (28/07/2026)

Plano concluído: as 11 tasks mais uma task extra (7.2b), aberta durante a
revisão da 7.3 para fechar uma lacuna de contrato que a pesquisa da API real
do PagBank revelou (assinatura no cartão exige cadastrar o meio de pagamento
antes de criar a assinatura, e a interface da Task 7.2 não tinha método para
isso). Suíte de testes foi de 448 para 570; `npm run build` limpo.
Aprendizados completos, incluindo o achado de que o PagBank não avisa boleto
vencido por webhook (a régua da Task 7.7 é quem decide isso na prática) e a
dependência circular evitada entre `InvoiceService` e `InadimplenciaService`,
estão em `.claude/progress.txt`.

Antes de configurar credencial de produção, revisar os pontos que a Task 7.3
marcou como inferência (não confirmados contra sandbox real): formato exato
do corpo de `PUT /customers/{id}/billing_info` e o mecanismo de assinatura do
webhook de pedidos (`/orders`), que pode não trazer `x-authenticity-token`.
