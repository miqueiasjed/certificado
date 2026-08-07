# Tasks do Plano 21 - Monitoramento CIP: tendência e mapa de pontos

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 21.1 | Migrations e models de planta, posição e relatório | backend-estrutura | ✅ | média |
| 21.2 | Agregação de tendência e comparação entre períodos | backend-logica | ✅ | alta |
| 21.3 | Ranking, mapa de calor e ocorrência por espécie | backend-logica | ✅ | alta |
| 21.4 | Planta versionada e posicionamento | backend-logica | ✅ | alta |
| 21.5 | Endpoints do relatório, da planta e do portal | backend-endpoint | ✅ | alta |
| 21.6 | PDF do relatório e croqui imprimível | backend-endpoint | ✅ | alta |
| 21.7 | Editor de planta com posicionamento por arrastar | frontend-componente | ✅ | alta |
| 21.8 | Telas do relatório de monitoramento | frontend-pagina | ✅ | alta |
| 21.9 | Testes de agregação, planta e publicação | teste | ✅ | alta |

## Ordem de execução

```
Lote 1:             21.1
Lote 2 (paralelo):  21.2  |  21.4
Lote 3:             21.3
Lote 4:             21.5
Lote 5 (paralelo):  21.6  |  21.7
Lote 6:             21.8
Lote 7:             21.9
```

## Dependências internas

- 21.2 e 21.4 dependem de 21.1
- 21.3 depende de 21.2
- 21.5 depende de 21.2, 21.3 e 21.4
- 21.6 depende de 21.3 e 21.4 (o croqui usa a planta da época)
- 21.7 depende de 21.5
- 21.8 depende de 21.5 e 21.6
- 21.9 depende de 21.2, 21.3, 21.4 e 21.5

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/web.php` e `routes/portal.php` | 21.5 | Task única |
| `app/Models/Device.php` | 21.4 | Herança de posição na substituição; conferir o estado do Plano 11 |
| `.claude/skills/dataviz` | 21.6, 21.8 | Carregar a skill antes de escrever gráfico, nas duas |

## Decisões registradas

- **Período sem visita nunca é zero.** É a distorção mais grave possível: sugeriria controle onde houve falta de serviço, em documento que vai para auditoria de indústria alimentícia.
- **Ranking normalizado por visita.** Sem isso o ranking mede frequência de visita, não infestação, e a empresa trata o ponto errado.
- **Mapa de calor sempre com a escala absoluta.** Intensidade relativa sozinha faz 2 capturas parecerem grave quando o máximo do período é 3.
- **Posição em fração de 0 a 1, nunca em pixel.** A mesma planta é vista em telas diferentes e impressa em papel.
- **Planta versionada, versão antiga intacta.** Relatório de um ano atrás precisa refletir o layout auditado da época.
- **Nova versão copia as posições.** Começar do zero em endereço com 40 pontos faria ninguém atualizar a planta.
- **Relatório salvo é congelado e imutável.** Documento entregue ao cliente não muda depois por correção em OS.
- **Publicação no portal é ato deliberado do responsável técnico.** Relatório sem revisão chegando ao cliente é risco profissional.
- **Gráficos do PDF em SVG no servidor.** O dompdf não executa JavaScript, e renderizar por navegador exigiria infraestrutura que o projeto não tem.
- **Nenhuma conclusão automática de texto.** A leitura interpretativa é o Plano 25 e sai marcada como gerada por modelo.

## Ordem de aplicação em produção

1. **Deploy 1** (21.1 a 21.5): estrutura, agregação e endpoints, com o módulo `monitoramento` **desligado**.
2. **Deploy 2** (21.6 a 21.8): PDF e telas.
3. Ligar o módulo para o tenant 1. Gerar o relatório de um cliente com histórico real e **conferir número a número contra o banco** antes de publicar qualquer coisa.
4. Publicar o primeiro relatório para um cliente combinado, com o responsável técnico do tenant revisando.

## Observações

- O plano estimava ~8 tasks. A decomposição chegou a 9 porque o editor de planta e o PDF são entregas independentes e nenhuma cabe junto com as telas de relatório.
- Este é o entregável que sustenta a venda para cliente grande. A qualidade do PDF importa tanto quanto a correção do número: ele é lido por fiscal e por auditor.
- A sugestão automática de tratamento é o Plano 25. O roteiro de campo sobre a planta é o Plano 22.
