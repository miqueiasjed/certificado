# Gestão comercial e operacional

## Comissões e metas

`budgets.user_id` já guarda quem vendeu, e nada é calculado a partir disso.

- Regra de comissão por vendedor, com percentual ou valor fixo.
- Base de cálculo configurável: valor do orçamento aprovado ou valor
  efetivamente recebido. Comissão sobre venda não paga é problema conhecido, e o
  padrão deve ser sobre o recebido.
- Apuração por período, com fechamento e histórico do que já foi pago.
- Meta por vendedor e por período, com acompanhamento do atingimento.
- Comissão do técnico por serviço executado, que existe em parte do mercado.
- Painel de desempenho: orçamento enviado, taxa de conversão, ticket médio e
  tempo até o fechamento.

## Renovação de contrato

`contracts.end_date` existe sem nenhum alerta ou processo.

- Painel de contratos por proximidade de vencimento.
- Alerta antecipado, com prazo configurável.
- Renovação que gera novo contrato preservando o histórico do anterior.
- Reajuste de valor na renovação, com índice ou percentual.
- Motivo de não renovação, para saber por que se perde cliente.
- Contrato vencido sem tratativa aparece como pendência, não desaparece.

## Roteirização e rastreamento

Depende de geolocalização, que não existe: `addresses` não tem `latitude` nem
`longitude`.

- Geocodificação dos endereços, com correção manual quando a busca errar.
- Roteiro do dia por técnico, ordenado por proximidade.
- Mapa das visitas do dia.
- Estimativa de deslocamento entre visitas, para o agendamento ser realista.
- Registro do local de início e de fim da execução, para comprovar atendimento.
- Rastreamento contínuo do técnico é monitoramento de pessoa. Fica opcional, com
  ciência do funcionário, e desligado por padrão.
- Roteiro do dia aceita parada de compromisso avulso, sem OS por trás, junto
  das paradas de OS (ver "Compromisso avulso, sem OS" em `operacao-campo.md`).

## Frota

Loop oferece controle de frota. Prioridade baixa, valor real para empresa com
várias equipes.

- Cadastro de veículo, com vínculo a técnico ou equipe.
- Abastecimento, quilometragem e custo por quilômetro.
- Manutenção preventiva com alerta por data ou quilometragem.
- Vínculo do veículo à OS, para ratear custo de deslocamento.
- Estoque do veículo, integrado ao módulo de estoque.

## Laudo assistido por IA

PestWase já anuncia geração por IA. Diferencial de venda com custo baixo de
implementação.

- Rascunho do parecer técnico a partir do que foi registrado na OS: produto
  aplicado, praga encontrada, dispositivo com ocorrência, adequação recomendada.
- O texto nasce como rascunho e exige revisão humana antes de emitir. Laudo
  técnico tem responsabilidade profissional, e o responsável técnico assina.
- Sugestão de preço de orçamento com base no histórico de serviço parecido.
- Nenhum dado de um tenant alimenta sugestão de outro.
- Módulo liberável por plano, com custo de uso mensurado por tenant.

## Assinatura eletrônica de contrato

Coleta em tela cobre a OS em campo (ver `operacao-campo.md`). Contrato pede
validade jurídica, com trilha de auditoria e comprovação de autoria.

- Envio do contrato para assinatura por e-mail, com acompanhamento da situação.
- Assinatura de ambas as partes, com registro de IP, data e hora.
- Contrato assinado arquivado e disponível no portal do cliente.
- Integração com provedor de assinatura, atrás de interface própria.
