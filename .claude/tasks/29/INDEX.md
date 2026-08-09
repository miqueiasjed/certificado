# Tasks do Plano 29 - EPI em campo e na conformidade

> Gerado em: 08/08/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 29.1 | EPI exigido por serviço e confirmação na OS | backend-estrutura | ✅ | média |
| 29.2 | Situação de EPI do técnico e registro da confirmação | backend-logica | ✅ | alta |
| 29.3 | Endpoints do cadastro e carga offline do app do técnico | backend-endpoint | ✅ | alta |
| 29.4 | Item de EPI no checklist da RDC 622/2022 | backend-logica | ✅ | média |
| 29.5 | Confirmação de EPI na execução, no app do técnico | frontend-componente | ✅ | alta |
| 29.6 | Testes de situação, sincronização e checklist | teste | ✅ | alta |

## Ordem de execução

```
Lote 1:             29.1
Lote 2:             29.2
Lote 3 (paralelo):  29.3  |  29.4
Lote 4:             29.5
Lote 5:             29.6
```

## Dependências internas

- Todo o plano depende do **Plano 28 concluído**
- 29.2 depende de 29.1 e das entregas do Plano 28
- 29.3 depende de 29.2 e do Plano 12 (fila offline)
- 29.4 depende de 29.2 e do Plano 24 (checklist)
- 29.5 depende de 29.3 e do Plano 13 (execução em campo)
- 29.6 depende de 29.2, 29.3 e 29.4

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `app/Support/EventosDeNotificacao.php` | 29.2 | Task única; acrescenta 1 evento. Já alterado na 28.3 — conferir o estado antes |
| `app/Services/Compliance/ChecklistService.php` | 29.4 | Task única; item novo e reescrita da ressalva |
| `app/Services/AppDayLoadService.php` e `AppSyncService.php` | 29.3 | Task única; alterados nos Planos 12, 13 e 22 |
| `resources/js/app-tecnico/Pages/Execucao.vue` | 29.5 | Task única; já alterado nos Planos 13 e 22 |
| `tests/Feature/ChecklistServiceTest.php` | 29.6 | Acrescenta casos, não reescreve |

29.3 e 29.4 podem ir em paralelo: tocam camadas distintas e não compartilham
arquivo.

## Decisões registradas

- **Nada bloqueia a execução da OS.** Pendência de EPI é problema de escritório;
  travar o técnico em campo por causa dela tira a operação do ar. Mesma escolha
  do checklist do Plano 24: o sistema informa, não impede. Esta é a decisão que
  mais provavelmente será questionada, e é deliberada.
- **`sem_assinatura` é situação própria, não um caso de `em_dia`.** A entrega
  existe mas falta a confirmação de recebimento, que é exatamente a prova que a
  NR-6 cobra. Agrupar as duas esconde a única pendência que importa.
- **Uma só regra de troca vencida**, compartilhada com o alerta da Task 28.3.
  Duas implementações do mesmo vencimento divergem, e divergência de vencimento
  é bug que ninguém encontra depois.
- **"Não informado" nunca é "irregular"** no checklist. Regra herdada do Plano
  24 e a mais importante da Task 29.4: acusar de irregular quem apenas não
  preencheu destruiria a confiança no checklist inteiro.
- **A confirmação chega pela fila offline**, com idempotência no Service e
  unique composto no banco. O app reenvia quando a conexão cai no meio; sem isso
  cada reenvio viraria linha nova.
- **Nenhuma coluna nova em `work_orders`.** Tabela com dado em produção, já
  alterada nos Planos 13, 22, 24 e 27. A relação é `hasMany`.
- **A carga do dia leva o mínimo**: id, nome e tipo do EPI. CA, validade e
  fabricante não vão para o aparelho.
- **O aviso de execução sem EPI é evento, não vencimento**: sai uma vez por OS,
  não a cada rotina diária.

## Ordem de aplicação em produção

1. **Deploy 1** (29.1 a 29.4): estrutura, regras, endpoints e checklist, com o
   módulo `epi` ainda desligado.
2. **Deploy 2** (29.5): app do técnico e cadastro de serviço.
3. Cadastrar a exigência de EPI por serviço **antes** de anunciar a etapa aos
   técnicos. Serviço sem exigência não mostra a etapa, e ligar sem cadastrar
   entrega uma funcionalidade invisível.
4. **A carga do dia cresce.** Medir o tamanho antes e depois: é o ponto onde o
   app do técnico fica pesado, e quem sofre é quem está no campo com sinal ruim.

## Observações

- Este plano só faz sentido depois do 28. Sozinho, ele registra confirmação de
  uso de um EPI que ninguém provou ter entregue.
- A Task 29.4 é a que fecha o ciclo: o checklist do Plano 24 hoje **declara por
  escrito** que EPI é parte do que o sistema não enxerga, e o contrato que o
  sistema emite promete ao cliente que a equipe usa EPI. Depois desta task a
  afirmação do contrato passa a ter lastro no sistema.
- O aviso ao gestor de execução sem EPI é o único ponto do plano que produz
  ruído recorrente. Se incomodar, a saída é o gestor desligar o evento na
  central de notificações, não afrouxar a regra.
