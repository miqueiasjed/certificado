# Tasks do Plano 13 - App do técnico: execução e assinatura em campo

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 13.1 | Migration e model de assinatura e travamento | backend-estrutura | ⏳ | média |
| 13.2 | Aplicadores de evento, avistamento, adequação e conclusão | backend-logica | ⏳ | alta |
| 13.3 | Service de assinatura, recusa e correção justificada | backend-logica | ⏳ | alta |
| 13.4 | Endpoints de assinatura, recusa e correção | backend-endpoint | ⏳ | média |
| 13.5 | Assinatura do cliente no PDF da OS | backend-endpoint | ⏳ | média |
| 13.6 | Tela de execução e roteiro de dispositivos | frontend-pagina | ⏳ | alta |
| 13.7 | Registro por cômodo: praga e produto | frontend-pagina | ⏳ | alta |
| 13.8 | Adequações com foto no aplicativo | frontend-pagina | ⏳ | média |
| 13.9 | Assinatura do cliente e registro de recusa | frontend-pagina | ⏳ | alta |
| 13.10 | Testes de execução, assinatura e travamento | teste | ⏳ | alta |

## Ordem de execução

```
Lote 1:             13.1
Lote 2 (paralelo):  13.2  |  13.3
Lote 3 (paralelo):  13.4  |  13.5
Lote 4 (paralelo):  13.6  |  13.7  |  13.8
Lote 5:             13.9
Lote 6:             13.10
```

## Dependências internas

- 13.2 e 13.3 dependem de 13.1
- 13.4 depende de 13.3
- 13.5 depende de 13.1
- 13.6, 13.7 e 13.8 dependem de 13.2 e do Plano 12 inteiro (fila e base local)
- 13.6 depende também da Task 11.7 (leitor de QR code)
- 13.9 depende de 13.3, 13.4 e das três telas de registro
- 13.10 depende de 13.2, 13.3 e 13.5

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `app/Services/WorkOrderService.php` | 13.2, 13.3, 13.5 | 13.3 primeiro (guarda do travamento), depois 13.2 e 13.5 |
| `routes/web.php` | 13.4 | Task única |
| `resources/views/pdf/work-order.blade.php` | 13.5 | Task única; documento emitido, conferir o PDF gerado |
| `app/Models/WorkOrder.php` | 13.1 | Task única |

## Decisões registradas

- **A assinatura do aplicativo passa pelo lote de sincronização**, e não por rota própria. Assinatura coletada sem rede precisa da mesma garantia de não duplicar que o resto.
- **Recusa é situação própria, e não ausência de assinatura.** As duas significam coisas diferentes na hora de cobrar, e a recusa registrada no PDF vale mais que espaço em branco.
- **Recusa não trava a OS.** O serviço foi prestado e o escritório ainda precisa fechar o documento.
- **`os.corrigir_assinada` só para administrador.** Técnico corrigir a própria OS assinada anularia o travamento.
- **Justificativa de no mínimo 20 caracteres.** "Ajuste" não explica nada a quem auditar depois.
- **Leitura de QR resolve no IndexedDB**, não no endpoint. Depender de rede em campo inutilizaria o QR code justamente onde não há sinal.
- **O instante que vale é o do celular.** OS executada às 9h e sincronizada às 18h consta como 9h no documento.
- **Localização é pedida com explicação e é recusável.** Coletar em silêncio ultrapassaria a fronteira registrada no Plano 22.

## Ordem de aplicação em produção

1. **Deploy 1** (13.1): colunas com padrão, sem backfill. As OS existentes ficam `nao_coletada` e nada muda para o cliente.
2. **Deploy 2** (13.2 a 13.4): backend da execução e da assinatura. O travamento passa a valer, e nenhuma OS existente está assinada, então nada trava de imediato.
3. **Deploy 3** (13.5): PDF. **Conferir os três PDFs gerados antes de subir**, com atenção ao caso sem assinatura, que precisa sair idêntico ao de hoje.
4. **Deploy 4** (13.6 a 13.9): aplicativo, liberado primeiro ao técnico de teste do Plano 12.

## Observações

- O plano estimava ~8 tasks. A decomposição chegou a 10 porque a execução tem três telas de registro distintas (dispositivo, cômodo, adequação) e nenhuma cabe junto com outra, e porque o PDF é task própria por ser documento emitido.
- A baixa de estoque a partir do produto aplicado **não** está aqui: é o Plano 17, que consome o que esta entrega passa a registrar.
- O aviso ao cliente de OS concluída é o Plano 14.
- Roteiro manual de aceite: repetir o roteiro offline do INDEX do Plano 12, agora executando uma OS inteira (40 dispositivos, 10 cômodos, 3 adequações com foto e assinatura) sem rede, do início ao fim.
