# Tasks do Plano 20 - Nota fiscal de serviço

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 20.1 | Migrations e models de configuração fiscal e nota | backend-estrutura | ⏳ | média |
| 20.2 | Interface do provedor de NFS-e | backend-logica | ⏳ | alta |
| 20.3 | Validação fiscal e emissão da OS ou do título | backend-logica | ⏳ | alta |
| 20.4 | Cancelamento e substituição de nota | backend-logica | ⏳ | média |
| 20.5 | Endpoints de nota e disponibilização no portal | backend-endpoint | ⏳ | alta |
| 20.6 | Telas de nota, pendências e configuração | frontend-pagina | ⏳ | alta |
| 20.7 | Testes de emissão, falha e cancelamento | teste | ⏳ | alta |

## Ordem de execução

```
Lote 1:             20.1
Lote 2:             20.2
Lote 3:             20.3
Lote 4:             20.4
Lote 5:             20.5
Lote 6:             20.6
Lote 7:             20.7
```

## Dependências internas

- 20.2 depende de 20.1
- 20.3 depende de 20.2 e do Plano 18 (título a receber)
- 20.4 depende de 20.3
- 20.5 depende de 20.3, 20.4 e do Plano 15 (portal)
- 20.6 depende de 20.5
- 20.7 depende de 20.3 e 20.4

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `app/Services/ServiceInvoiceService.php` | 20.3, 20.4 | 20.3 cria; 20.4 acrescenta |
| `routes/web.php` e `routes/portal.php` | 20.5 | Task única |
| `app/Support/EventosDeNotificacao.php` | 20.4 | Acrescenta eventos |

## Decisões registradas

- **Provedor que abstrai os municípios, atrás de interface própria.** Implementar prefeitura por prefeitura cresceria para sempre conforme a plataforma ganha tenants de novas cidades, e custaria mais em manutenção do que se economiza em taxa por nota.
- **Emissão é assíncrona por natureza.** O provedor aceita e responde depois. Tratar como síncrono penduraria a requisição do usuário em uma prefeitura fora do ar.
- **Falha é pendência visível e reprocessável, agrupada por motivo.** Erro fiscal perdido em log vira nota não emitida descoberta no fechamento do mês, quando já passou a competência.
- **Validar o dado do cliente antes de chamar o provedor**, com o campo nomeado como aparece na tela. É a falha mais comum e a mais fácil de evitar.
- **O sistema não presume prazo municipal de cancelamento.** Presumir errado impediria cancelamento legítimo ou prometeria o impossível. Quem sabe é a prefeitura.
- **Nota nunca é apagada**, em nenhuma situação. Cancelada e substituída são situações, com motivo, e o PDF continua acessível.
- **`emissao_automatica` nasce desligada.** Emissão automática tem consequência tributária e é decisão do contador do tenant.
- **XML no portal é obrigatório.** É o arquivo que a contabilidade do cliente precisa; o PDF sozinho não resolve.

## Ordem de aplicação em produção

1. **Deploy 1** (20.1 a 20.5): estrutura, provedor e endpoints, com o módulo `nfse` **desligado** para todos.
2. Configurar o tenant 1 em **homologação** e emitir uma nota de teste com um cliente real, conferindo o PDF e o XML com o contador do tenant.
3. **Deploy 2** (20.6): telas.
4. Trocar para produção e emitir **uma** nota real, conferida pelo contador antes de qualquer emissão em volume.
5. Ligar a emissão automática só depois de o tenant confirmar com o contador dele.

## Observações

- O plano estimava ~6 tasks. A decomposição chegou a 7 porque cancelamento e substituição têm regra própria de prazo e de cadeia, e não cabem junto com a emissão.
- A apuração de imposto e a obrigação acessória continuam fora de escopo: é trabalho de contabilidade, e o sistema entrega o documento e o XML.
- A nota da assinatura que o tenant paga à plataforma não está aqui: é decisão contábil do próprio negócio, fora do produto.
