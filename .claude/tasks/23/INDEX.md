# Tasks do Plano 23 - Comissões, metas e renovação de contratos

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 23.1 | Migrations e models de comissão, meta e renovação | backend-estrutura | ✅ | média |
| 23.2 | Apuração de comissão, fechamento e histórico | backend-logica | ✅ | alta |
| 23.3 | Metas e indicadores comerciais | backend-logica | ✅ | média |
| 23.4 | Renovação de contrato com reajuste e histórico | backend-logica | ✅ | alta |
| 23.5 | Alertas de contrato a vencer e pendência | backend-logica | ✅ | média |
| 23.6 | Endpoints de comissão, meta e renovação | backend-endpoint | ✅ | alta |
| 23.7 | Telas de comissão e de metas | frontend-pagina | ✅ | alta |
| 23.8 | Painel comercial e painel de contratos a vencer | frontend-pagina | ✅ | alta |
| 23.9 | Testes de comissão, meta e renovação | teste | ✅ | alta |

## Ordem de execução

```
Lote 1:             23.1
Lote 2 (paralelo):  23.2  |  23.3  |  23.4
Lote 3:             23.5
Lote 4:             23.6
Lote 5 (paralelo):  23.7  |  23.8
Lote 6:             23.9
```

## Dependências internas

- 23.2, 23.3 e 23.4 dependem de 23.1
- 23.2 depende do Plano 18 (base `recebido` vem da parcela baixada)
- 23.4 depende do Plano 9 (geração de visitas do novo contrato)
- 23.5 depende de 23.4 e do Plano 14
- 23.6 depende de 23.2 a 23.5
- 23.7 e 23.8 dependem de 23.6
- 23.9 depende de 23.2, 23.4 e 23.6

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/web.php` | 23.6 | Task única |
| `app/Services/ContractService.php` | 23.4 | Task única |
| `app/Support/EventosDeNotificacao.php` | 23.5 | Acrescenta evento |

## Decisões registradas

- **Base padrão da comissão é o recebido.** Comissão paga sobre venda que o cliente nunca pagou é problema conhecido do setor, e o padrão do sistema protege a empresa. Fica configurável para quem preferir o contrário.
- **A regra vigente é a da data do fato.** Alterar percentual em março não pode reescrever a comissão de janeiro, ou a equipe deixa de confiar no cálculo.
- **O percentual aplicado é gravado no item.** É o que torna a comissão auditável anos depois sem depender do histórico da regra.
- **Competência fechada é imutável.** Ajuste vira item negativo na competência atual, com referência ao que o originou.
- **Cada pessoa vê apenas a própria comissão**, salvo permissão explícita. Vendedor ver a comissão do colega é conflito interno criado pelo sistema.
- **Renovação gera contrato novo.** Estender a data do anterior apagaria o histórico de valores, que é o que responde quanto o cliente pagava antes.
- **Índice de reajuste informado pelo usuário.** Buscar série econômica externa travaria a renovação por causa de uma API fora do ar, e o usuário tem o número na negociação.
- **Contrato vencido sem decisão continua avisando semanalmente.** É perda de receita recorrente silenciosa, e a insistência aqui é o comportamento certo.
- **Projeção de meta só a partir do quinto dia útil.** Projeção com dois dias de dado é ruído e leva à conversa errada com a equipe.

## Ordem de aplicação em produção

1. **Deploy 1** (23.1): tabelas e colunas novas. Sem efeito.
2. **Deploy 2** (23.2 a 23.5): lógica, com as rotinas de apuração e de alerta **desligadas**.
3. Cadastrar as regras de comissão reais do tenant e apurar **uma competência já encerrada**, conferindo item a item com quem paga a comissão hoje. Só depois de bater é que a apuração passa a valer.
4. **Deploy 3** (23.6 a 23.8): endpoints e telas.
5. Ligar a rotina de alerta de contrato. Ligar a apuração mensal por último.

## Observações

- O plano estimava ~8 tasks. A decomposição chegou a 9 porque comissão, meta e renovação são três temas independentes e cada um tem lógica própria.
- O pagamento da comissão como despesa usa o título a pagar do Plano 18, e o vínculo já está previsto em `commissions.payable_id`.
- O aviso automático de vencimento ao responsável usa a central do Plano 14.
