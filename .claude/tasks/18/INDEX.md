# Tasks do Plano 18 - Contas a receber e a pagar

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 18.1 | Migrations e models de título, fornecedor e contas | backend-estrutura | ✅ | média |
| 18.2 | Migração do financeiro atual com conferência | backend-logica | ✅ | alta |
| 18.3 | Geração de título a partir de OS e de contrato | backend-logica | ✅ | alta |
| 18.4 | Baixa total e parcial integrada ao caixa | backend-logica | ✅ | alta |
| 18.5 | Contas a pagar com recorrência | backend-logica | ✅ | alta |
| 18.6 | Aging, previsão de caixa e margem por OS | backend-logica | ✅ | alta |
| 18.7 | Endpoints e troca da leitura dos painéis | backend-endpoint | ✅ | alta |
| 18.8 | Telas de contas a receber e inadimplência | frontend-pagina | ✅ | alta |
| 18.9 | Telas de contas a pagar, contas e previsão | frontend-pagina | ✅ | alta |
| 18.10 | Testes financeiros e conferência da migração | teste | ✅ | alta |

## Ordem de execução

```
Lote 1:             18.1
Lote 2:             18.3                  (gera título antes de migrar o histórico)
Lote 3:             18.4
Lote 4:             18.2                  (migração, com 18.3 e 18.4 prontos)
Lote 5 (paralelo):  18.5  |  18.6
Lote 6:             18.7
Lote 7 (paralelo):  18.8  |  18.9
Lote 8:             18.10
```

## Dependências internas

- 18.3, 18.4 e 18.5 dependem de 18.1
- 18.2 depende de 18.1, 18.3 e 18.4 (a migração usa o mesmo caminho de criação e de baixa)
- 18.6 depende de 18.1 e do Plano 17 (custo de produto)
- 18.7 depende de 18.3 a 18.6
- 18.8 e 18.9 dependem de 18.7
- 18.10 depende de 18.2, 18.3, 18.4 e 18.6

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `app/Services/Financial/IntegracaoComCaixa.php` | 18.4, 18.5 | 18.4 cria; 18.5 acrescenta a saída |
| `app/Http/Controllers/FinancialDashboardController.php` | 18.7 | Task única; um painel por vez |
| `routes/web.php` | 18.7 | Task única |
| `app/Services/Financial/ConferenciaDeTotais.php` | 18.2, 18.7 | 18.2 cria; 18.7 reaproveita |

## Decisões registradas

- **Nenhum número que o cliente vê hoje pode mudar.** É o critério de sucesso do plano inteiro, e a conferência automática de totais é o que prova, não a impressão de quem testou.
- **A migração roda depois de 18.3 e 18.4.** Migrar pelo mesmo caminho que o sistema usa no dia a dia evita criar um segundo modo de gerar título, que divergiria depois.
- **Divergência de um centavo aborta e desfaz.** Migração financeira que "quase bate" gera meses de conferência manual.
- **Caso ambíguo não é adivinhado**: vai para o relatório de exceções e é decidido por uma pessoa.
- **Nada é apagado.** `payment_details` e `financial_entries` continuam existindo, e a limpeza é assunto de outro plano.
- **Ponto único de integração com o caixa.** Dois caminhos criando lançamento é como o mesmo dinheiro entra duas vezes.
- **Estorno é lançamento novo**, nunca exclusão. Caixa de dia fechado não muda.
- **Recorrente mantém 3 competências à frente.** Gerar 60 títulos de um contrato de 5 anos cria lixo que ninguém revisa.
- **Margem incompleta é marcada como incompleta.** Número que parece completo e não é leva a decisão errada com confiança.
- **Vencimento não é movido por fim de semana.** Mover confundiria a conferência com o extrato bancário.

## Ordem de aplicação em produção

Este é o módulo que o cliente atual mais usa. A sequência não é negociável.

1. **Deploy 1** (18.1): tabelas novas. Nada muda.
2. **Deploy 2** (18.3, 18.4, 18.5, 18.6): lógica, **sem nenhuma tela nova e sem trocar painel**. O sistema continua operando pelo modelo antigo.
3. **Ensaio da migração em cópia do banco de produção** (18.2): rodar `--dry-run`, conferir o relatório de exceções linha a linha, rodar de verdade, comparar os painéis antes e depois tela por tela. Repetir até o relatório de exceções estar vazio ou inteiramente entendido.
4. **Deploy 3**: migração em produção, em janela de baixo movimento, com backup imediatamente antes.
5. **Deploy 4** (18.7): troca da leitura dos painéis, **um painel por deploy**, cada um com a conferência de totais. Painel que divergir volta atrás na hora.
6. **Deploy 5** (18.8, 18.9): telas novas.

## Observações

- O plano estimava ~8 tasks. A decomposição chegou a 10 porque a migração é task própria com conferência automática, e porque aging, previsão e margem são três cálculos independentes.
- Emissão de boleto e PIX é o Plano 19. Nota fiscal é o Plano 20. Comissão sobre o recebido é o Plano 23, e todos partem do título criado aqui.
- O custo de deslocamento entra como valor fixo por visita. O cálculo por quilômetro depende do Plano 27.
