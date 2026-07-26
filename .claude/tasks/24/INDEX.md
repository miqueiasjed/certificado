# Tasks do Plano 24 - Conformidade RDC 622/2022

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 24.1 | Referência normativa configurável e validades | backend-estrutura | ⏳ | média |
| 24.2 | Revisão dos textos dos documentos emitidos | backend-endpoint | ⏳ | média |
| 24.3 | Controle de validade e alertas de vencimento | backend-logica | ⏳ | média |
| 24.4 | Aviso ao aplicar produto irregular e conferência | backend-logica | ⏳ | média |
| 24.5 | Checklist de conformidade e endpoints | backend-endpoint | ⏳ | média |
| 24.6 | Telas de conformidade, validades e referência | frontend-pagina | ⏳ | média |
| 24.7 | Testes de validade, checklist e documentos | teste | ⏳ | média |

## Ordem de execução

```
Lote 1:             24.1
Lote 2 (paralelo):  24.2  |  24.3
Lote 3:             24.4
Lote 4:             24.5
Lote 5:             24.6
Lote 6:             24.7
```

## Dependências internas

- 24.2, 24.3 e 24.4 dependem de 24.1
- 24.4 depende do Plano 13 (produto aplicado registrado em campo) e de 24.3 (situação do registro)
- 24.5 depende de 24.3 e 24.4
- 24.6 depende de 24.5
- 24.7 depende de 24.2, 24.3 e 24.5

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `resources/views/pdf/*.blade.php` | 24.2 | Task única; documento emitido, conferir os PDFs gerados |
| `app/Services/WorkOrderService.php` | 24.4 | Já alterado nos Planos 13 e 17; conferir o estado antes |
| `app/Support/EventosDeNotificacao.php` | 24.3 | Acrescenta eventos |
| `routes/web.php` | 24.5 | Task única |

## Antes de começar

**Ler o texto oficial da RDC 622/2022** e conferir o alcance das exigências. As tasks foram escritas sobre o que o sistema já documenta, sem inventar exigência. Qualquer verificação adicional que a leitura da norma indicar entra como item novo do checklist, e como aviso, nunca como bloqueio nesta entrega.

## Decisões registradas

- **Aviso primeiro, bloqueio depois.** Travar a operação do cliente por interpretação equivocada de norma é pior que o problema que se quer resolver. Nenhuma verificação desta entrega impede concluir OS, assinar ou emitir documento, e a Task 24.7 tem teste que garante isso.
- **"Não informado" nunca é "irregular".** Acusar de irregular quem não preencheu um campo destrói a confiança no checklist inteiro.
- **Referência normativa é dado do tenant, não do código.** A norma vai mudar de novo, e alterar sistema a cada resolução não se sustenta.
- **Documento antigo não é reprocessado.** Certificado emitido no ano passado continua com o texto da época: é o documento que o cliente arquivou e que o fiscal compara.
- **O checklist informa, não certifica.** Dizer "sua empresa está regular" seria assumir responsabilidade que não é da plataforma, e a ressalva fica visível no topo da tela.
- **Documento regulatório vencido avisa semanalmente**, pelo mesmo motivo do lote vencido do Plano 17: tem consequência regulatória e o silêncio depois do primeiro aviso não resolve.
- **A data que vale na aplicação de produto é a do registro em campo**, não a da sincronização.

## Ordem de aplicação em produção

1. **Deploy 1** (24.1): tabelas, colunas nullable e seeder da referência padrão. Sem efeito.
2. **Deploy 2** (24.2): documentos. **Conferir os cinco PDFs lado a lado antes de subir.** Este é o ponto de risco do plano: o documento vai para a mão de fiscal.
3. **Deploy 3** (24.3, 24.4, 24.5): validades, avisos e checklist, com a rotina de verificação desligada.
4. Preencher as validades reais do tenant 1, rodar a verificação uma vez e conferir o checklist antes de ligar os avisos.
5. **Deploy 4** (24.6): telas. Ligar a rotina.

## Observações

- O plano estimava ~6 tasks. A decomposição chegou a 7 porque a revisão dos documentos emitidos é task própria, com conferência visual obrigatória.
- A emissão de laudo com parecer técnico automático é o Plano 25. O relatório de monitoramento periódico é o Plano 21.
- Quando a decisão de transformar algum aviso em bloqueio for tomada, ela é plano novo, com o cliente ciente, e não alteração desta entrega.
