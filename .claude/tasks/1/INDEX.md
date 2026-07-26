# Tasks do Plano 1 - Rotinas agendadas, datas e correções de base

> Gerado em: 25/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 1.1 | Fuso do negócio no backend e utilitário BusinessDate | backend-estrutura | ✅ | baixa |
| 1.2 | Tabela de registro de execução das rotinas | backend-estrutura | ✅ | baixa |
| 1.3 | Agendamento das quatro rotinas com trava e registro | backend-logica | ✅ | média |
| 1.4 | Comando de diagnóstico e instrução de cron | backend-endpoint | ✅ | média |
| 1.5 | Casts de data explícitos nos models sensíveis | backend-estrutura | ✅ | baixa |
| 1.6 | Rotinas comparam vencimento pelo dia no fuso do negócio | backend-logica | ✅ | média |
| 1.7 | Utilitário de data no frontend | frontend-componente | ✅ | média |
| 1.8 | Formatação de data: certificados e contratos | frontend-pagina | ✅ | baixa |
| 1.9 | Formatação de data: ordens de serviço e orçamentos | frontend-pagina | ✅ | média |
| 1.10 | Formatação de data: telas financeiras | frontend-pagina | ✅ | baixa |
| 1.11 | Formatação de data: operação de campo e clientes | frontend-pagina | ✅ | baixa |
| 1.12 | Formatação de data: catálogo e cadastros | frontend-pagina | ✅ | média |
| 1.13 | Remover as tabelas órfãs | backend-estrutura | ✅ | baixa |
| 1.14 | Testes de virada de dia e das rotinas | teste | ⏳ | alta |
| 1.15 | Eliminar toISOString como fonte de "hoje" e "agora" | frontend-pagina | ✅ | média |
| 1.16 | Vencimento no fuso do negócio nos accessors e scopes | backend-logica | ✅ | baixa |
| 1.17 | Corrigir a regra que marca toda parcela não paga como vencida | backend-logica | ✅ | baixa |
| 1.18 | A rotina só considera paga a parcela com data de pagamento | backend-logica | ✅ | baixa |

## Ordem de execução

```
Lote 1 (paralelo): 1.1, 1.2, 1.5, 1.7, 1.13
Lote 2 (paralelo): 1.3, 1.6, 1.8, 1.9, 1.10, 1.11, 1.12
Lote 3:            1.4
Lote 4:            1.14
```

## Dependências internas

- 1.3 depende de 1.2
- 1.4 depende de 1.2 e 1.3
- 1.6 depende de 1.1 e 1.5
- 1.8, 1.9, 1.10, 1.11 e 1.12 dependem de 1.7
- 1.12 fecha a varredura do frontend e só roda depois de 1.8, 1.9, 1.10 e 1.11, porque valida o projeto inteiro
- 1.14 depende de 1.1, 1.2, 1.3 e 1.6

## Observações

- 1.13 é independente de todas as outras e pode ir a qualquer momento.
- As tasks 1.8 a 1.12 tocam muitos arquivos, mas a alteração é mecânica e idêntica em todos: trocar chamada por import do utilitário. O lote foi dividido por domínio para que uma regressão fique contida em uma área do sistema.
- Decisão registrada na 1.1: `APP_TIMEZONE` continua `UTC`. Trocar para `America/Sao_Paulo` reinterpretaria os `created_at` já gravados em produção com 3 horas de diferença.
- As tasks 1.15 e 1.16 nasceram durante a execução, de achados dos subagentes. Ambas estão dentro do escopo declarado do Plano 1 e a decomposição inicial as deixou escapar:
  - 1.15: `new Date().toISOString()` produz dia e hora em UTC. O formulário de evento de dispositivo grava 3 horas a mais todo dia.
  - 1.16: os accessors de `Certificate` e `PaymentDetail` decidem vencimento em UTC. Sem essa correção, a rotina grava `active` e a tela exibe `vencido`.
