# Tasks do Plano 17 - Estoque de produtos com lote, validade e custo

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 17.1 | Migrations e models de estoque, lote e local | backend-estrutura | ✅ | média |
| 17.2 | Service de movimentação e saldo | backend-logica | ✅ | alta |
| 17.3 | Regras de lote: validade, FEFO e bloqueio | backend-logica | ✅ | média |
| 17.4 | Baixa automática pelo campo e custo da OS | backend-logica | ✅ | alta |
| 17.5 | Inventário com ajuste justificado | backend-logica | ✅ | alta |
| 17.6 | Alertas de estoque mínimo e de validade | backend-logica | ✅ | média |
| 17.7 | Endpoints de estoque, lote e rastreabilidade | backend-endpoint | ✅ | alta |
| 17.8 | Telas de saldo, lote e movimentação | frontend-pagina | ✅ | alta |
| 17.9 | Telas de inventário e rastreabilidade | frontend-pagina | ✅ | alta |
| 17.10 | Testes de saldo, lote vencido e rastreabilidade | teste | ✅ | alta |

## Ordem de execução

```
Lote 1:             17.1
Lote 2:             17.2
Lote 3:             17.3
Lote 4 (paralelo):  17.4  |  17.5  |  17.6
Lote 5:             17.7
Lote 6 (paralelo):  17.8  |  17.9
Lote 7:             17.10
```

## Dependências internas

- 17.2 depende de 17.1
- 17.3 depende de 17.2
- 17.4 depende de 17.3 e do Plano 13 (produto aplicado chega do campo)
- 17.5 e 17.6 dependem de 17.2 e 17.3
- 17.7 depende de 17.2, 17.3, 17.5
- 17.8 e 17.9 dependem de 17.7
- 17.10 depende de 17.2 a 17.5

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `app/Services/StockService.php` | 17.2, 17.3 | 17.2 cria; 17.3 acrescenta o bloqueio de vencido |
| `routes/web.php` | 17.7 | Task única |
| `app/Support/EventosDeNotificacao.php` | 17.6 | Acrescenta 2 eventos |
| `app/Support/DominioMultiempresa.php` | 17.1, 17.5 | 17.1 primeiro |

## Decisões registradas

- **Saldo em tabela própria, além do razão.** Somar movimentos a cada consulta fica lento com o volume de um ano. As duas fontes são mantidas na mesma transação, e a Task 17.10 prova que não divergem.
- **FEFO, não FIFO.** Em saneante o que importa é a validade, e lote comprado depois pode vencer antes.
- **Custo congelado no momento da baixa.** Custo que acompanha o lote faria o relatório de margem de um mês fechado mudar sozinho.
- **Saldo insuficiente vira pendência, não bloqueio.** O sistema não pode impedir o registro de um serviço que já aconteceu no mundo real.
- **`controla_estoque` nasce false.** Os produtos atuais do cliente continuam como ficha técnica, e o tenant liga produto por produto.
- **Movimento nunca é apagado.** Correção é ajuste com motivo, e é isso que dá rastreabilidade perante fiscalização.
- **Saldo do sistema oculto na contagem.** Contagem que confirma o número esperado não é contagem.
- **A operação não para durante o inventário.** O saldo é congelado na abertura e os movimentos do período entram no fechamento.
- **Lote vencido continua visível com saldo** até o descarte registrado, e o aviso reenvia semanalmente.

## Ordem de aplicação em produção

1. **Deploy 1** (17.1): tabelas novas e colunas com padrão. `controla_estoque = false` em tudo, então nada muda.
2. **Deploy 2** (17.2 a 17.7): lógica e endpoints, com o módulo `estoque` **desligado** para todos os tenants.
3. **Deploy 3** (17.8, 17.9): telas.
4. Ligar o módulo para o tenant 1. Cadastrar os lotes reais, conferir os saldos iniciais por contagem física e só então ligar `controla_estoque` nos produtos, **um por vez**.
5. Ligar `controla_estoque` em um produto de baixo giro primeiro, acompanhar uma semana de baixas automáticas pelas OS, e só depois os demais.

## Observações

- O plano estimava ~8 tasks. A decomposição chegou a 10 porque o inventário, os alertas e a baixa automática são lógicas independentes, e porque a rastreabilidade tem tela própria por ser o entregável que vai à fiscalização.
- A conta a pagar do fornecedor na compra é o Plano 18. Aqui a entrada registra fornecedor e nota fiscal como texto, e o vínculo com o título vem depois.
- O estoque do veículo integrado à frota é o Plano 27. `stock_locations.tipo = veiculo` já existe e fica sem uso até lá.
- O bloqueio por registro de produto vencido na Anvisa é o Plano 24, e é diferente da validade do lote tratada aqui.
