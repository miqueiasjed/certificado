# Tasks do Plano 25 - Laudo assistido por IA

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 25.1 | Migrations e models de rascunho e uso | backend-estrutura | ✅ | média |
| 25.2 | Provedor de IA com isolamento por tenant | backend-logica | ✅ | alta |
| 25.3 | Rascunho de parecer e bloqueio de emissão | backend-logica | ✅ | alta |
| 25.4 | Sugestão de preço pelo histórico do tenant | backend-logica | ✅ | média |
| 25.5 | Endpoints, medição de uso e teto por plano | backend-endpoint | ✅ | média |
| 25.6 | Editor do rascunho com aviso de não revisado | frontend-pagina | ✅ | alta |
| 25.7 | Testes de isolamento, bloqueio e medição | teste | ⏳ | alta |

## Ordem de execução

```
Lote 1:             25.1
Lote 2:             25.2
Lote 3 (paralelo):  25.3  |  25.4
Lote 4:             25.5
Lote 5:             25.6
Lote 6:             25.7
```

## Dependências internas

- 25.2 depende de 25.1
- 25.3 e 25.4 dependem de 25.2
- 25.3 depende também do Plano 21 (resumo do relatório de monitoramento)
- 25.5 depende de 25.3 e 25.4
- 25.6 depende de 25.5
- 25.7 depende de 25.2, 25.3 e 25.4

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `config/ai.php` | 25.2, 25.5 | 25.2 cria; 25.5 acrescenta a tabela de preço |
| `app/Services/WorkOrderService.php` | 25.3 | Já alterado nos Planos 13, 17 e 24; conferir o estado antes |
| `routes/web.php` | 25.5 | Task única |

## Decisões registradas

- **O texto nasce como rascunho e exige revisão humana.** Laudo técnico tem responsabilidade profissional, e o responsável técnico assina o que sai. A guarda vive nos Services de emissão, não na tela: bloqueio só no frontend é bloqueio que a próxima rota fura.
- **`conteudo_gerado` nunca é sobrescrito.** Comparar gerado e revisado é o que prova a revisão humana perante uma auditoria.
- **Isolamento absoluto por tenant.** Nenhum dado de uma empresa entra no contexto de outra, nem como exemplo, nem agregado, nem anonimizado. O teste que varre o contexto procurando strings da outra empresa é obrigatório.
- **Prefixo de sistema estável e cacheado.** É o que torna o custo viável, já que o mesmo bloco de instruções se repete em toda geração. Interpolar data ou nome do tenant nele invalida o cache e multiplica a conta.
- **Sem parâmetros de amostragem.** O modelo em uso recusa `temperature`, `top_p` e `top_k` com erro; variação de estilo, quando desejada, vem da instrução no prompt.
- **A sugestão de preço é estatística, não modelo.** Mediana e quartis sobre os orçamentos aprovados do próprio tenant resolvem melhor, de graça e de forma auditável. O modelo escreve apenas a justificativa em texto.
- **Amostra menor que 5 não vira sugestão.** Preço a partir de dois orçamentos leva a empresa a errar com confiança.
- **Nada é preenchido automaticamente.** Nem o parecer no documento, nem o valor no orçamento.
- **Medir antes de vender.** O custo é por chamada e precisa ser conhecido por tenant antes de o recurso virar item de plano, com a tabela de preço em configuração.
- **Teto atingido recusa só a geração.** Limite de um recurso opcional nunca derruba OS, certificado ou financeiro.

## Ordem de aplicação em produção

1. **Deploy 1** (25.1, 25.2): estrutura e provedor, com o módulo `laudo_ia` **desligado** para todos.
2. **Deploy 2** (25.3, 25.4, 25.5): lógica e endpoints, ainda com o módulo desligado.
3. Ligar o módulo para o tenant 1 com teto baixo (por exemplo, 50 gerações no mês) e gerar pareceres de OS reais **sem emitir nada**, apenas para o responsável técnico avaliar a qualidade do texto.
4. **Deploy 3** (25.6): tela. Liberar a revisão e a emissão só depois de o responsável técnico aprovar a qualidade dos rascunhos.
5. Acompanhar o custo por tenant por um mês antes de qualquer decisão comercial sobre o recurso.

## Observações

- O plano estimava ~6 tasks. A decomposição chegou a 7 porque a sugestão de preço é lógica independente do parecer, e porque a medição de uso é requisito próprio do plano.
- A identificação de praga por foto continua fora: depende de volume de imagem classificada que a base ainda não tem.
- O resumo do período para o relatório de monitoramento entra pelo mesmo caminho do parecer, com a mesma exigência de revisão antes de publicar (Plano 21).
