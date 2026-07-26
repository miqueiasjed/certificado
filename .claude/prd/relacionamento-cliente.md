# Relacionamento com o cliente

## Central de notificações

Nada existe hoje. `whatsapp` aparece no código apenas como opção de canal de
origem de orçamento.

Arquitetura: uma fila de notificações com template por evento e driver de envio
plugável. E-mail entra primeiro; WhatsApp entra depois como driver, sem refazer
template nem regra de disparo.

Eventos que disparam notificação:

| Evento | Destinatário |
|---|---|
| Visita agendada e lembrete na véspera | Cliente |
| Técnico a caminho | Cliente |
| OS concluída, com documento anexo | Cliente |
| Certificado próximo do vencimento | Cliente e empresa |
| Contrato próximo do vencimento | Empresa |
| Pagamento vencido | Cliente e empresa |
| Orçamento perto de expirar sem resposta | Empresa |
| Visita periódica prevista e não gerada | Empresa |

Regras:

- Template editável pelo tenant, com variáveis do cliente, da OS e da empresa.
- Toda tentativa de envio é registrada, com sucesso ou falha e o motivo.
- Retentativa automática em falha temporária, com limite.
- Preferência de canal por cliente, incluindo recusa de recebimento.
- Nenhuma notificação sai duplicada para o mesmo evento.
- Enquanto o WhatsApp não estiver integrado, o sistema monta link `wa.me` com a
  mensagem pronta, para envio manual em um clique.

## Portal do cliente

Todo concorrente relevante tem ("Área do Cliente"). Acesso do próprio cliente,
separado do acesso dos funcionários do tenant.

- Login próprio, sem acesso a nada de outro cliente nem do tenant.
- Histórico de visitas com documento de cada uma.
- Certificados vigentes e vencidos, com download.
- Contratos e próximas visitas previstas.
- Faturas com situação e meio de pagamento.
- Adequações recomendadas em aberto, que é o que o cliente precisa resolver para
  passar em auditoria.
- Relatório de monitoramento do período (ver `monitoramento-cip.md`).
- Abertura de solicitação de atendimento, que chega ao tenant como pendência.

Regras:

- O portal é módulo liberável por plano.
- Documento em rascunho nunca aparece ao cliente.
- Nenhum dado financeiro interno do tenant (custo, margem, comissão) é visível.

## Agendamento online

Página pública do tenant onde o cliente pede horário, sem ligar.

- Grade de horários que respeita a capacidade dos técnicos.
- Pedido entra como solicitação a confirmar, nunca agenda direto no calendário.
- Confirmação dispara notificação.

## Pesquisa de satisfação

- Disparo automático após a OS concluída.
- Nota e comentário, com página de resposta sem exigir login.
- Painel com média por período, por técnico e por tipo de serviço.
- Nota baixa gera pendência de contato para a empresa.
