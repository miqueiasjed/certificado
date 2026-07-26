# Financeiro e fiscal

## Estado atual

O financeiro é de caixa: `financial_entries` (entrada e saída),
`daily_cash_balances` (saldo por dia), `payment_details` (parcelas da OS) e
painéis de fluxo de caixa. Funciona para saber quanto entrou, e não responde
quanto a empresa tem a receber, de quem, nem qual o custo do serviço prestado.

## Estoque de produtos

`products` hoje é só ficha técnica: princípio ativo, grupo químico, antídoto e
registro em órgão. Sem quantidade, lote, validade ou custo.

- Saldo por produto, com entrada por compra e saída por aplicação na OS.
- Lote e validade, com bloqueio de aplicação de lote vencido.
- Custo de aquisição, para calcular o custo real de cada OS e a margem.
- Alerta de estoque mínimo e de lote perto de vencer.
- Estoque por técnico ou veículo, porque o produto sai do depósito e vai para a
  van antes de ser aplicado.
- Inventário com ajuste justificado, nunca alteração silenciosa de saldo.
- Rastreabilidade: dado um lote, listar todas as OS que o aplicaram. É o que a
  fiscalização pede em caso de incidente.

## Contas a receber e a pagar

- Título a receber gerado a partir da OS ou do contrato, com vencimento e
  parcelas.
- Baixa de título, total ou parcial, alimentando o caixa que já existe.
- Inadimplência com aging (a vencer, 30, 60, 90, acima de 90 dias).
- Contas a pagar de fornecedor, com vencimento e recorrência.
- Plano de contas por categoria, para saber onde o dinheiro entra e sai.
- Previsão de caixa juntando o que vence a receber e a pagar.
- Migração dos dados atuais sem perda: `payment_details` e `financial_entries`
  existentes precisam continuar consistentes com os novos títulos.

## Cobrança recorrente dos clientes finais

Diferente da assinatura que o tenant paga (ver `saas-multitenant.md`). Aqui é o
tenant cobrando a mensalidade de contrato dos clientes dele.

- Emissão de boleto e PIX a partir do título a receber.
- Cobrança recorrente para contrato periódico.
- Baixa automática por webhook do gateway, sem conferência manual.
- Link de pagamento enviável por e-mail e por WhatsApp.
- Régua de cobrança: aviso antes do vencimento, no vencimento e após.
- Credencial de gateway por tenant, cada empresa recebendo no próprio caixa. A
  plataforma nunca é intermediária do dinheiro do tenant.

## NFS-e

- Emissão de nota fiscal de serviço a partir da OS ou do título.
- A NFS-e é municipal e varia por cidade. Integrar via provedor que abstrai os
  municípios, em vez de implementar prefeitura por prefeitura.
- Configuração fiscal por tenant: regime, código de serviço, alíquota, retenção.
- Cancelamento e substituição de nota, com registro.
- PDF e XML disponíveis ao tenant e ao cliente no portal.
- Falha de emissão é uma pendência visível e reprocessável, nunca um erro
  perdido em log.
