# Tasks do Plano 10 - Agenda em calendário

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 10.1 | Endpoint de dados da agenda | backend-endpoint | ⏳ | média |
| 10.2 | Conflito de horário e carga por técnico | backend-logica | ⏳ | média |
| 10.3 | Endpoints de reagendamento e atribuição | backend-endpoint | ⏳ | média |
| 10.4 | Componente de calendário: dia, semana e mês | frontend-componente | ⏳ | alta |
| 10.5 | Página da agenda com filtros e integração | frontend-pagina | ⏳ | alta |
| 10.6 | Reagendamento por arrastar e soltar | frontend-componente | ⏳ | alta |
| 10.7 | Carga por técnico e visão do dia do técnico | frontend-pagina | ⏳ | média |
| 10.8 | Testes da agenda, do conflito e do escopo | teste | ⏳ | alta |

## Ordem de execução

```
Lote 1 (paralelo):  10.1 |  10.2
Lote 2:             10.3                 (toca routes/web.php, depois de 10.1)
Lote 3:             10.4
Lote 4:             10.5
Lote 5:             10.6                 (altera CalendarioAgenda.vue, depois de 10.5)
Lote 6:             10.7
Lote 7:             10.8
```

## Dependências internas

- 10.2 é independente e pode ir junto com 10.1
- 10.3 depende de 10.1 (controller) e 10.2 (verificação de conflito)
- 10.4 é independente do backend e pode começar em paralelo com 10.1, mas a integração exige 10.1 pronta
- 10.5 depende de 10.3 e 10.4
- 10.6 depende de 10.4 e 10.5
- 10.7 depende de 10.2 e 10.5
- 10.8 depende de 10.1, 10.2 e 10.3

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/web.php` | 10.1, 10.3 | 10.1 antes de 10.3 |
| `app/Http/Controllers/AgendaController.php` | 10.1, 10.3 | 10.1 cria, 10.3 acrescenta |
| `resources/js/Components/Calendario/CalendarioAgenda.vue` | 10.4, 10.6 | 10.4 cria, 10.6 acrescenta o arrasto |
| `resources/js/Components/Sidebar.vue` | 10.5 | Já alterado nos Planos 2 e 6; conferir o estado antes |

## Decisões registradas

- **Calendário próprio, sem FullCalendar nem biblioteca equivalente.** O projeto não tem dependência de calendário hoje. Bibliotecas do gênero trazem CSS próprio que briga com o design system e peso desproporcional para três visões e arrastar e soltar. O custo é a aritmética de datas, isolada em `utils/calendario.js` e coberta por teste.
- **Arrastar e soltar com a API nativa do HTML5**, pela mesma razão. Em telas de toque, onde o arrasto nativo não funciona bem, a ação de reagendar continua disponível pelo painel lateral, em vez de emular arrasto por toque.
- **Semana começa na segunda-feira**, que é como a operação de campo pensa o trabalho.
- **Conflito só existe quando as duas OS têm horário.** Mesma data sem horário definido gera aviso, não bloqueio: travar por isso impediria o uso de quem não preenche horário, que é a maioria hoje.
- **Nenhum campo novo para "quem reagendou".** A auditoria do Plano 3 já grava autor, instante e valores antes e depois em `WorkOrder`, que é exatamente o exigido pelo plano.
- **Atualização otimista com desfazer** no arrasto. Esperar a resposta trava a interface; mover sem desfazer mostra estado que não existe no banco.

## Ordem de aplicação em produção

1. Deploy do backend (10.1 a 10.3). Nenhuma tela nova, nenhum efeito para o usuário.
2. Deploy do frontend (10.4 a 10.7). O item "Agenda" aparece no menu.
3. Conferir o desempenho da agenda com um mês cheio depois que o Plano 9 estiver gerando visitas: é o cenário de volume real.

## Observações

- O plano estimava ~6 tasks. A decomposição chegou a 8 porque o calendário, a página e o arrastar e soltar não cabem no mesmo arquivo nem na mesma execução de subagente.
- Este plano depende do 9: sem geração automática de visitas, a agenda tem pouco o que mostrar, e o ganho percebido pelo cliente atual é pequeno.
- O caso 12 do teste (consulta por linha) existe porque o Plano 9 multiplica o número de OS agendadas. Agenda lenta com um mês cheio é o modo mais provável de este plano falhar em produção.
