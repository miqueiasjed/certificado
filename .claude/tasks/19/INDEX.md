# Tasks do Plano 19 - Cobrança recorrente dos clientes finais

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 19.1 | Migrations e models de credencial e cobrança | backend-estrutura | ⏳ | média |
| 19.2 | Interface de gateway e implementação por tenant | backend-logica | ⏳ | alta |
| 19.3 | Emissão de boleto e PIX a partir do título | backend-logica | ⏳ | alta |
| 19.4 | Webhook idempotente e baixa automática | backend-endpoint | ⏳ | alta |
| 19.5 | Cobrança recorrente e régua de cobrança | backend-logica | ⏳ | alta |
| 19.6 | Endpoints, conciliação e pagamento no portal | backend-endpoint | ⏳ | alta |
| 19.7 | Telas de cobrança, configuração e conciliação | frontend-pagina | ⏳ | alta |
| 19.8 | Testes de webhook, idempotência e isolamento | teste | ⏳ | alta |

## Ordem de execução

```
Lote 1:             19.1
Lote 2:             19.2
Lote 3:             19.3
Lote 4 (paralelo):  19.4  |  19.5
Lote 5:             19.6
Lote 6:             19.7
Lote 7:             19.8
```

## Dependências internas

- 19.2 depende de 19.1
- 19.3 depende de 19.2 e do Plano 18 (parcela a receber)
- 19.4 depende de 19.3 e do `SettlementService` do Plano 18
- 19.5 depende de 19.3 e do Plano 14
- 19.6 depende de 19.3, 19.4 e do Plano 15 (portal)
- 19.7 depende de 19.6
- 19.8 depende de 19.3, 19.4 e 19.5

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/web.php` | 19.6 | Task única |
| `routes/portal.php` | 19.6 | Task única |
| `routes/webhooks.php` | 19.4 | Task única |
| `app/Support/EventosDeNotificacao.php` | 19.3, 19.5 | Uma por vez |

## Decisões registradas

- **Credencial por tenant, dinheiro na conta do tenant.** A plataforma nunca é intermediária do dinheiro do cliente: isso mudaria a natureza do negócio e traria obrigação regulatória fora do produto.
- **Credencial cifrada no banco.** Dump de banco com credencial em texto puro é acesso à conta bancária do cliente.
- **Nenhum estado de tenant na instância do gateway.** É a falha que faz a cobrança de um cair na conta de outro, e não tem conserto depois do dinheiro entrar.
- **Gravar o evento antes de processar.** Permite reprocessar depois de um bug sem depender do gateway reenviar.
- **A cobrança é localizada dentro do tenant do token do webhook.** Busca global por `gateway_charge_id` permitiria webhook forjado baixar título de outra empresa.
- **Uma cobrança ativa por parcela.** Dois boletos abertos geram pagamento em duplicidade, e devolver dinheiro ao cliente final é problema da empresa.
- **Validar o dado do cliente antes de chamar o gateway.** Reduz a falha mais comum e dá mensagem útil em vez do erro cru do provedor.
- **A régua para no pagamento**, com checagem no enfileiramento e de novo no envio. Cobrar quem já pagou é o erro mais caro em relacionamento.
- **Baixa manual sem evento aparece na conciliação como informação**, não como falha: recebimento em dinheiro é legítimo.

## Ordem de aplicação em produção

1. **Deploy 1** (19.1 a 19.4): estrutura, gateway e webhook, com o módulo `cobranca_recorrente` **desligado** para todos.
2. Configurar o tenant 1 em **sandbox** e percorrer o ciclo inteiro: emitir, pagar no simulador, conferir a baixa e a conciliação.
3. **Deploy 2** (19.5, 19.6, 19.7): régua, endpoints e telas, com a régua desligada.
4. Trocar o tenant 1 para produção e emitir **uma** cobrança real, para um cliente combinado, conferindo a baixa.
5. Ligar a emissão recorrente. Ligar a régua por último, depois de conferir a fila de avisos gerada com o despacho ainda parado.

## Observações

- O plano estimava ~7 tasks. A decomposição chegou a 8 porque o webhook e a régua são responsabilidades separadas, e nenhuma cabe junto com a emissão.
- O `GatewayEvent` é reaproveitado do Plano 7, que trata da assinatura que o tenant paga à plataforma. São dois fluxos distintos na mesma tabela, separados por `company_id` nulo ou preenchido.
- A nota fiscal do serviço cobrado é o Plano 20 e parte do mesmo título.
