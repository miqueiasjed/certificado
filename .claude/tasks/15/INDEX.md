# Tasks do Plano 15 - Portal do cliente

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 15.1 | Migrations e models do acesso do cliente | backend-estrutura | ⏳ | média |
| 15.2 | Autenticação: convite, senha e recuperação | backend-logica | ⏳ | alta |
| 15.3 | Service do portal com escopo duplo | backend-logica | ⏳ | alta |
| 15.4 | Endpoints de visitas, documentos, contratos e faturas | backend-endpoint | ⏳ | média |
| 15.5 | Solicitação de atendimento e pendência na empresa | backend-endpoint | ⏳ | média |
| 15.6 | Layout com identidade do tenant e telas de acesso | frontend-componente | ⏳ | alta |
| 15.7 | Telas de visitas, documentos e contratos | frontend-pagina | ⏳ | alta |
| 15.8 | Telas de pendências, faturas e solicitação | frontend-pagina | ⏳ | alta |
| 15.9 | Testes de isolamento do portal | teste | ⏳ | alta |

## Ordem de execução

```
Lote 1:             15.1
Lote 2:             15.2
Lote 3:             15.3
Lote 4 (paralelo):  15.4  |  15.5
Lote 5:             15.6
Lote 6 (paralelo):  15.7  |  15.8
Lote 7:             15.9
```

## Dependências internas

- 15.2 depende de 15.1 (model e guard)
- 15.3 depende de 15.1
- 15.4 depende de 15.2 e 15.3
- 15.5 depende de 15.2 e do Plano 14 (avisos)
- 15.6 depende de 15.2
- 15.7 e 15.8 dependem de 15.4, 15.5 e 15.6
- 15.9 depende de todas as anteriores

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/portal.php` | 15.2, 15.4, 15.5 | Nesta ordem, uma por vez |
| `config/auth.php` | 15.1 | Task única (guard `cliente`) |
| `bootstrap/app.php` | 15.2 | Registro do arquivo de rotas e do middleware |
| `app/Support/EventosDeNotificacao.php` | 15.2, 15.5 | Acrescentam eventos; uma por vez |

## Decisões registradas

- **`client_users` é tabela separada de `users`.** Compartilhar faria toda consulta interna precisar lembrar de excluir clientes, e a primeira que esquecesse vazaria dado. O cliente final não tem papel Spatie e não existe para o sistema interno.
- **Escopo duplo, com redundância deliberada.** Toda consulta filtra por empresa e por cliente, mesmo com o escopo global do Plano 4 ativo. Se um falhar, o outro segura.
- **Lista de permissão de campos, nunca de proibição.** Campo criado meses depois nasce invisível ao cliente.
- **404 para o que não é dele, nunca 403.** A diferença entre os dois códigos já entrega a existência do registro.
- **O tenant vem do registro do cliente, nunca da URL.** Aceitar `company_id` por parâmetro é o caminho mais curto para vazamento.
- **O cliente não define prioridade da solicitação.** Tudo chegaria como alta.
- **Solicitação não vira OS automaticamente.** Só a empresa sabe se tem técnico disponível, que é a mesma fronteira do Plano 16.
- **Sem ação de pagar nesta entrega.** O pagamento é o Plano 19, e botão sem função frustra mais que a ausência.

## Ordem de aplicação em produção

1. **Deploy 1** (15.1 a 15.5): backend inteiro, com o módulo `portal_cliente` **desligado para todos os tenants**. Nenhum cliente tem acesso ainda.
2. Revisão de segurança dedicada: ler `routes/portal.php` inteiro e rodar a Task 15.9 antes de qualquer liberação. Este é o primeiro acesso externo do sistema.
3. **Deploy 2** (15.6 a 15.8): telas.
4. Liberar o módulo para o tenant 1 e convidar **um** cliente de teste. Conferir com dois clientes reais do mesmo tenant que nenhum enxerga o outro.
5. Liberação geral.

## Observações

- O plano estimava ~8 tasks. A decomposição chegou a 9 porque a autenticação com convite e o Service de escopo são responsabilidades separadas, e juntar as duas passaria do limite de task.
- O relatório de monitoramento no portal é o Plano 21, e o agendamento e a pesquisa de satisfação são o Plano 16. As telas já preveem onde eles entram.
- A Task 15.9 é condição de liberação, não item opcional de fim de plano.
