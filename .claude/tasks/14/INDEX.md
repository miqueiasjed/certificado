# Tasks do Plano 14 - Central de notificações

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 14.1 | Migrations e models da central de notificações | backend-estrutura | ✅ | média |
| 14.2 | Service de enfileiramento com template e idempotência | backend-logica | ✅ | alta |
| 14.3 | Driver de e-mail, despachante e retentativa | backend-logica | ✅ | alta |
| 14.4 | Disparo dos eventos do sistema | backend-logica | ✅ | alta |
| 14.5 | Aviso à empresa quando uma rotina agendada falha | backend-logica | ✅ | média |
| 14.6 | Endpoints de template, fila e preferência | backend-endpoint | ✅ | média |
| 14.7 | Telas de templates e histórico de notificações | frontend-pagina | ✅ | alta |
| 14.8 | Preferência do cliente e atalho de WhatsApp | frontend-pagina | ✅ | média |
| 14.9 | Testes de idempotência, preferência e retentativa | teste | ✅ | alta |

## Ordem de execução

```
Lote 1:             14.1
Lote 2:             14.2
Lote 3 (paralelo):  14.3  |  14.4
Lote 4 (paralelo):  14.5  |  14.6
Lote 5 (paralelo):  14.7  |  14.8
Lote 6:             14.9
```

## Dependências internas

- 14.2 depende de 14.1
- 14.3 e 14.4 dependem de 14.2
- 14.5 depende de 14.2 e do catálogo de rotinas do Plano 1
- 14.6 depende de 14.2
- 14.7 depende de 14.6
- 14.8 depende de 14.1 e de 14.2
- 14.9 depende de 14.2, 14.3 e 14.4

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `bootstrap/app.php` | 14.3, 14.4, 14.5 | Uma por vez; todas registram rotina agendada |
| `app/Support/RotinasAgendadas.php` | 14.3, 14.4, 14.5 | 14.5 altera o catálogo (janela esperada); rodar por último |
| `app/Services/WorkOrderService.php` | 14.4 | Task única |
| `routes/web.php` | 14.6 | Task única |

## Decisões registradas

- **E-mail agora, WhatsApp depois como driver da mesma fila.** Número bloqueado pela Meta em provedor não oficial vira suporte recorrente, e a API oficial exige número dedicado por tenant, o que complica o onboarding. Template e regra de disparo não mudam quando o canal entrar.
- **A chave de idempotência não leva timestamp.** Levar o instante faria a rotina diária reenviar o mesmo lembrete todo dia.
- **Cliente que recusou o canal não entra na fila.** Enfileirar e cancelar depois encheria a fila e confundiria o histórico.
- **Falha permanente não é repetida.** Insistir em endereço inexistente prejudica a reputação do remetente do tenant e derruba a entrega de todos os avisos dele.
- **"Técnico a caminho" é ação explícita**, nunca inferida por horário ou localização. Aviso automático errado faz o cliente esperar à toa.
- **Ausência de execução de rotina é falha.** É a lacuna que o Plano 1 não cobre: rotina que não roda não deixa registro de erro, e o silêncio parece sucesso.
- **Um aviso de rotina quebrada por dia.** Alerta de hora em hora vira regra de caixa de entrada e para de ser lido.

## Ordem de aplicação em produção

1. **Deploy 1** (14.1): tabelas e colunas com padrão. Sem efeito.
2. **Deploy 2** (14.2, 14.3, 14.6): fila, driver e endpoints, **com a rotina de despacho ainda desligada**. Conferir o remetente do tenant e enviar um e-mail de teste manualmente.
3. **Deploy 3** (14.4): disparo dos eventos, ainda com o despacho desligado. Rodar `notificacoes:avisos-diarios` e **conferir a fila gerada linha a linha** antes de ligar o envio. Este é o ponto de risco: um erro aqui manda dezenas de e-mails errados para os clientes do cliente.
4. **Deploy 4**: ligar a rotina de despacho.
5. **Deploy 5** (14.5, 14.7, 14.8): verificação de rotinas e telas.

## Observações

- O plano estimava ~8 tasks. A decomposição chegou a 9 porque o aviso de rotina falha é lógica própria, com catálogo e janela, e não cabe junto com o disparo dos eventos de negócio.
- O item mais delicado do plano é o Deploy 3. A fila existe justamente para permitir conferir antes de enviar, e essa conferência não é opcional.
- O driver de WhatsApp não está aqui. Quando entrar, implementa `DriverDeEnvio` e nada mais muda.
