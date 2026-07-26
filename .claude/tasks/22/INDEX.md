# Tasks do Plano 22 - Roteirização e rastreamento em campo

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 22.1 | Coordenadas, roteiro e local da execução | backend-estrutura | ⏳ | média |
| 22.2 | Geocodificação dos endereços e correção manual | backend-logica | ⏳ | alta |
| 22.3 | Roteiro por proximidade e estimativa de deslocamento | backend-logica | ⏳ | alta |
| 22.4 | Registro do local da execução e divergência | backend-logica | ⏳ | média |
| 22.5 | Endpoints de roteiro, mapa e coordenada | backend-endpoint | ⏳ | média |
| 22.6 | Tela de roteiro e mapa das visitas | frontend-pagina | ⏳ | alta |
| 22.7 | Roteiro e registro de local no aplicativo | frontend-componente | ⏳ | média |
| 22.8 | Testes de geocodificação, roteiro e divergência | teste | ⏳ | alta |

## Ordem de execução

```
Lote 1:             22.1
Lote 2:             22.2                  (deploy 2: geocodificação em lote)
Lote 3 (paralelo):  22.3  |  22.4
Lote 4:             22.5
Lote 5 (paralelo):  22.6  |  22.7
Lote 6:             22.8
```

## Dependências internas

- 22.2, 22.3 e 22.4 dependem de 22.1
- 22.3 depende de 22.2 (sem coordenada não há roteiro)
- 22.4 depende do Plano 13 (início e fim vêm do aplicativo)
- 22.5 depende de 22.2, 22.3 e 22.4
- 22.6 e 22.7 dependem de 22.5
- 22.8 depende de 22.2, 22.3 e 22.4

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `routes/web.php` e `routes/api.php` | 22.5 | Task única |
| `resources/js/app-tecnico/Pages/Execucao.vue` | 22.7 | Já alterado no Plano 13; conferir o estado antes |
| `app/Services/Sync/AplicadorDeExecucao.php` | 22.4 | Já criado no Plano 13 |

## Decisões registradas

- **Correção manual de coordenada nunca é sobrescrita** por processo automático. Se o trabalho some na próxima execução, ninguém corrige de novo.
- **Precisão "cidade" é pendência, não sucesso.** Coordenada de cidade não serve para roteiro.
- **OS com horário combinado é âncora.** Otimização que quebra compromisso com cliente anula a economia de combustível.
- **Sem serviço externo de rota nesta entrega.** Linha reta com fator viário e velocidade média resolve o caso real de até 15 paradas, e o custo por requisição multiplicado por técnicos e dias não se justifica.
- **A estimativa é apresentada como estimativa.** Prometer hora exata em serviço de campo gera reclamação.
- **Ordem manual prevalece.** Quem opera sabe de obra na rua e de cliente que só recebe à tarde.
- **Divergência de local é informação, não bloqueio**, e não é sinalizada quando o endereço não tem coordenada confiável: alarme falso ensina todo mundo a ignorar o aviso.
- **Rastreamento contínuo desligado por padrão, com consentimento registrado do técnico e faixa permanente no aplicativo.** Monitoramento de pessoa sem indicação visível não entra neste produto.
- **"Abrir no mapa" delega ao aplicativo de navegação do celular.** Reimplementar navegação seria pior em tudo.

## Ordem de aplicação em produção

1. **Deploy 1** (22.1): colunas e tabelas novas. Sem efeito.
2. **Deploy 2** (22.2): geocodificação. Rodar `enderecos:geocodificar --dry-run --limite=20`, conferir o relatório por precisão, e então rodar em lotes acompanhando o custo do provedor. Corrigir a mão as pendências de precisão `cidade` antes de seguir.
3. **Deploy 3** (22.3 a 22.5): roteiro e endpoints, com o módulo `roteirizacao` desligado.
4. **Deploy 4** (22.6, 22.7): telas e aplicativo. Ligar o módulo para o tenant 1.
5. Rastreamento contínuo permanece desligado. Só é ligado a pedido do tenant, com o consentimento dos técnicos registrado.

## Observações

- O plano estimava ~7 tasks. A decomposição chegou a 8 porque a geocodificação com backfill e a roteirização são lógicas independentes, e o aplicativo tem entrega própria.
- Frota, combustível e custo por quilômetro são o Plano 27, que consome o roteiro daqui.
- A otimização considerando janela de horário do cliente fica fora: só compensa com volume alto de visitas, e a âncora de horário já resolve o caso comum.
