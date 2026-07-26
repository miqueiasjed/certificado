# Tasks do Plano 8 - Onboarding e provisionamento de tenant

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 8.1 | Migrations e models de convite e trilha | backend-estrutura | ⏳ | baixa |
| 8.2 | Catálogo compartilhado dos cadastros iniciais | backend-estrutura | ⏳ | média |
| 8.3 | Service de provisionamento do tenant | backend-logica | ⏳ | média |
| 8.4 | Service e endpoints de convite de usuário | backend-endpoint | ⏳ | alta |
| 8.5 | Trilha de primeiros passos | backend-logica | ⏳ | média |
| 8.6 | Período de avaliação e transição para plano pago | backend-logica | ⏳ | média |
| 8.7 | Fluxo de criação autônoma de tenant | backend-endpoint | ⏳ | média |
| 8.8 | Telas de cadastro de empresa e aceite de convite | frontend-pagina | ⏳ | alta |
| 8.9 | Trilha de primeiros passos no dashboard | frontend-componente | ⏳ | média |
| 8.10 | Teste de tenant novo emitindo OS sem ajuste manual | teste | ⏳ | alta |

## Ordem de execução

```
Lote 1 (paralelo):  8.1  |  8.2
Lote 2 (paralelo):  8.3  |  8.5
Lote 3 (paralelo):  8.4  |  8.6         (8.4 toca routes/web.php)
Lote 4:             8.7                 (toca routes/web.php, depois de 8.4)
Lote 5 (paralelo):  8.8  |  8.9
Lote 6:             8.10
```

## Dependências internas

- 8.3 depende de 8.2 (catálogo) e do `TenantService` do Plano 5
- 8.4 depende de 8.1 (model `Invitation`) e do `UserService` do Plano 2
- 8.5 depende de 8.1 (model `OnboardingStep`)
- 8.6 depende do `SubscriptionService` do Plano 7
- 8.7 depende de 8.3 e 8.6
- 8.8 depende de 8.4 e 8.7
- 8.9 depende de 8.5
- 8.10 depende de todas

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/web.php` | 8.4, 8.7 | 8.4 antes de 8.7 |
| `app/Http/Middleware/HandleInertiaRequests.php` | 8.5 | Única do plano |
| `config/assinatura.php` | 8.6 | Criado no Plano 7 (Task 7.7); aqui só acrescenta chaves |
| `app/Services/TenantService.php` | 8.3 | Altera o `criar()` do Plano 5 |
| `app/Support/RotinasAgendadas.php` | 8.6 | Única do plano |

## Decisões registradas

- **O cadastro público não pede plano.** A empresa entra em avaliação e escolhe plano depois, pela tela de assinatura do Plano 7. Menos atrito na entrada, e a decisão de plano acontece quando ela já viu o produto funcionando.
- **Cadastros regulatórios são copiados, não compartilhados.** Cada tenant edita o próprio a partir da cópia, que é a regra do PRD. Referência compartilhada faria a edição de um tenant afetar todos.
- **Passo da trilha se marca sozinho pela condição.** Trilha que depende de o usuário clicar em "concluí" nunca fecha.
- **Papel vem do convite, não da escolha de quem aceita.** O contrário entrega administrador para qualquer um com o link.
- **E-mail de convite usa `Mail` do Laravel por enquanto.** O Plano 14 substitui pela central de notificações, e o ponto de troca está anotado na Task 8.4.

## Ordem de aplicação em produção

1. Deploy das migrations (8.1). Tabelas novas, sem efeito no tenant 1.
2. Deploy do backend. Conferir que o tenant 1 não recebeu passo de trilha nenhum (ele já está configurado; a trilha só nasce em tenant novo).
3. Rodar `ProvisionamentoService::reprovisionarCadastros()` em dry-run mental no tenant 1 **não é necessário** e não deve ser feito: ele já tem os cadastros dele, e reaplicar o catálogo acrescentaria itens que ele não usa.
4. Deploy do frontend e liberação da rota `/cadastro`.
5. Criar uma empresa de teste em produção e percorrer o fluxo completo antes de divulgar a rota.

## Observações

- O plano estimava ~7 tasks. A decomposição chegou a 10 porque convite, avaliação e provisionamento são Services distintos, e o frontend tem duas telas públicas mais um componente.
- A Task 8.10 é o portão do plano: se algum passo do fluxo de OS exigir cadastro que o provisionamento não cria, a correção é no catálogo da Task 8.2, nunca no teste.
- O catálogo inicial sai dos dados reais do tenant 1, exportados e revisados. Inventar a lista produz um sistema que parece pronto e não serve.
