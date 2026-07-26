# Tasks do Plano 16 - Agendamento online e pesquisa de satisfação

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 16.1 | Migrations e models de solicitação e pesquisa | backend-estrutura | ⏳ | média |
| 16.2 | Grade de horários com capacidade dos técnicos | backend-logica | ⏳ | média |
| 16.3 | Página pública de pedido de horário | backend-endpoint | ⏳ | média |
| 16.4 | Confirmação e recusa do pedido pela empresa | backend-endpoint | ⏳ | média |
| 16.5 | Disparo e resposta da pesquisa de satisfação | backend-endpoint | ⏳ | alta |
| 16.6 | Páginas públicas de agendamento e de pesquisa | frontend-pagina | ⏳ | alta |
| 16.7 | Telas internas de pedidos e painel de satisfação | frontend-pagina | ⏳ | alta |
| 16.8 | Testes de rota pública, agendamento e pesquisa | teste | ⏳ | alta |

## Ordem de execução

```
Lote 1:             16.1
Lote 2:             16.2
Lote 3:             16.3
Lote 4 (paralelo):  16.4  |  16.5
Lote 5 (paralelo):  16.6  |  16.7
Lote 6:             16.8
```

## Dependências internas

- 16.2 depende de 16.1
- 16.3 depende de 16.2
- 16.4 depende de 16.3 e do Plano 10 (criação de OS com conflito bloqueado)
- 16.5 depende de 16.1 e do Plano 14
- 16.6 depende de 16.3 e 16.5
- 16.7 depende de 16.2, 16.4 e 16.5
- 16.8 depende de todas

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/publico.php` | 16.3, 16.5 | 16.3 cria o arquivo; 16.5 acrescenta |
| `routes/web.php` | 16.4, 16.7 | 16.4 antes |
| `app/Support/EventosDeNotificacao.php` | 16.3, 16.4, 16.5 | Uma por vez; todas acrescentam evento |
| `bootstrap/app.php` | 16.3 | Registro do arquivo de rotas públicas |

## Decisões registradas

- **Pedido do cliente nunca agenda direto.** Só a empresa sabe se tem técnico na região naquele dia. É a regra que evita o problema operacional descrito no plano.
- **A grade mostra período, não horário exato.** Prometer "terça às 14h" em serviço de campo gera reclamação garantida.
- **A grade não expõe contagem de vagas nem técnico.** Isso informaria o tamanho da operação a qualquer visitante.
- **Rota pública tem limite de taxa, campo armadilha e tempo mínimo.** Sem isso, a tabela vira depósito de spam em uma semana.
- **Cliente criado só na confirmação**, por decisão explícita da empresa. Cadastro vindo de formulário público sem revisão enche a base de lixo.
- **Pesquisa enviada no dia seguinte à conclusão**, e no máximo uma por cliente a cada 30 dias. Cliente com visita semanal receberia quatro por mês e pararia de responder.
- **Média com menos de 3 respostas é omitida.** Técnico avaliado por uma nota isolada é injustiça, e o indicador perde a credibilidade.
- **Nota baixa vira pendência de contato**, não resposta automática ao cliente. Quem resolve insatisfação é pessoa.

## Ordem de aplicação em produção

1. **Deploy 1** (16.1, 16.2): estrutura e cálculo. Sem efeito visível.
2. **Deploy 2** (16.3, 16.4): página pública **com `aceita_agendamento_online = false` em todos os tenants**, e sem `slug_publico` preenchido. Nada está exposto ainda.
3. Rodar a Task 16.8 e revisar `routes/publico.php` linha a linha antes de expor qualquer slug.
4. **Deploy 3** (16.5): pesquisa, com a rotina de envio desligada. Conferir a fila gerada antes de ligar.
5. **Deploy 4** (16.6, 16.7): telas. Ligar o agendamento online e a pesquisa para o tenant 1 primeiro.

## Observações

- O plano estimava ~6 tasks. A decomposição chegou a 8 porque a grade de disponibilidade é lógica própria com configuração por tenant, e porque as páginas públicas não cabem junto com as telas internas.
- Este plano cria a **segunda** superfície pública do sistema, depois do portal do Plano 15. A diferença é que aqui não há login nenhum, então limite de taxa e proteção contra robô são requisito, não melhoria.
- Pagamento no ato do agendamento é o Plano 19. Roteiro por proximidade influenciando a grade é o Plano 22.
