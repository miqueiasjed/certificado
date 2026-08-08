# Tasks do Plano 26 - Assinatura eletrônica de contratos

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 26.1 | Migrations e models de pedido de assinatura | backend-estrutura | ✅ | média |
| 26.2 | Interface do provedor de assinatura | backend-logica | ✅ | média |
| 26.3 | Envio, acompanhamento e webhook de situação | backend-endpoint | ✅ | alta |
| 26.4 | Endpoints e contrato assinado no portal | backend-endpoint | ✅ | média |
| 26.5 | Telas de envio e acompanhamento | frontend-pagina | ✅ | alta |
| 26.6 | Testes de assinatura, webhook e isolamento | teste | ✅ | alta |

## Ordem de execução

```
Lote 1:             26.1
Lote 2:             26.2
Lote 3:             26.3
Lote 4:             26.4
Lote 5:             26.5
Lote 6:             26.6
```

## Dependências internas

- 26.2 depende de 26.1
- 26.3 depende de 26.2 e do Plano 14 (avisos)
- 26.4 depende de 26.3 e do Plano 15 (portal)
- 26.5 depende de 26.4
- 26.6 depende de 26.3 e 26.4

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/web.php` e `routes/portal.php` | 26.4 | Task única |
| `routes/webhooks.php` | 26.3 | Já criado no Plano 19; acrescentar |
| `app/Services/PortalService.php` | 26.4 | Acrescenta contrato assinado à lista de campos visíveis |

## Decisões registradas

- **Isto é diferente da coleta em tela do Plano 13.** A OS em campo comprova recebimento do serviço com o cliente presente. Contrato precisa de trilha de auditoria, comprovação de autoria e aceitação a distância, e é por isso que IP, navegador e instante de cada signatário são guardados.
- **Credencial por tenant, cifrada.** A conta com o provedor é da empresa; a plataforma não assina contrato de ninguém.
- **O contrato só vira assinado quando todos assinaram.** Assinatura parcial não é contrato.
- **Contrato em assinatura é imutável.** Alterar o texto enquanto o cliente lê o PDF enviado significa assinar uma versão diferente da aceita.
- **Reenviar não cria pedido novo.** Dois pedidos abertos do mesmo contrato geram duas assinaturas válidas de textos possivelmente diferentes.
- **Sincronização periódica além do webhook.** Webhook se perde, e sem essa rede o contrato fica preso em "em assinatura" para sempre.
- **O arquivo assinado é baixado e guardado no ato.** Link do provedor expira, e o documento precisa continuar acessível anos depois.
- **Pré-visualização antes de enviar.** O PDF vai direto ao cliente e não há como recolher.

## Ordem de aplicação em produção

1. **Deploy 1** (26.1 a 26.4): estrutura, provedor, webhook e endpoints, com o módulo `assinatura_eletronica` **desligado** para todos.
2. Configurar o tenant 1 em **sandbox** e percorrer o ciclo inteiro: enviar, assinar pelos dois lados, conferir o arquivo arquivado e a trilha de auditoria.
3. **Deploy 2** (26.5): telas.
4. Trocar para produção e enviar **um** contrato real, com um cliente combinado, conferindo o documento assinado antes de liberar o uso geral.

## Observações

- O plano estimava ~6 tasks, e a decomposição chegou a 6.
- A assinatura de orçamento pode reaproveitar o mesmo mecanismo depois: a interface e o pedido já são genéricos o suficiente, faltando apenas o vínculo com a origem.
- A assinatura de OS em campo continua sendo o Plano 13 e não muda aqui.
