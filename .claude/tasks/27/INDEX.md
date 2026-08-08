# Tasks do Plano 27 - Frota e veículos

> Gerado em: 26/07/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 27.1 | Migrations e models de veículo, abastecimento e manutenção | backend-estrutura | ✅ | média |
| 27.2 | Custo por quilômetro e rateio no custo da OS | backend-logica | ✅ | média |
| 27.3 | Alertas de manutenção e de documentação | backend-logica | ✅ | média |
| 27.4 | Endpoints e integração com estoque e financeiro | backend-endpoint | ✅ | alta |
| 27.5 | Telas de frota | frontend-pagina | ✅ | alta |
| 27.6 | Testes de consumo, rateio e alertas | teste | ⏳ | alta |

## Ordem de execução

```
Lote 1:             27.1
Lote 2 (paralelo):  27.2  |  27.3
Lote 3:             27.4
Lote 4:             27.5
Lote 5:             27.6
```

## Dependências internas

- 27.2 e 27.3 dependem de 27.1
- 27.2 depende do Plano 18 (margem) e do Plano 22 (quilometragem estimada)
- 27.3 depende do Plano 14 (avisos)
- 27.4 depende de 27.2, do Plano 17 (estoque do veículo) e do Plano 18 (conta a pagar)
- 27.5 depende de 27.4
- 27.6 depende de 27.2, 27.3 e 27.4

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `app/Services/Financial/WorkOrderMarginService.php` | 27.2 | Task única; substitui o deslocamento fixo do Plano 18 |
| `routes/web.php` | 27.4 | Task única |
| `app/Support/EventosDeNotificacao.php` | 27.3 | Acrescenta 3 eventos |
| `app/Models/WorkOrder.php` | 27.1 | Já alterado nos Planos 13, 22 e 24; conferir o estado antes |

## Decisões registradas

- **Consumo apenas entre tanques cheios.** Calcular sobre abastecimento parcial produz número errado que ninguém percebe, e o consumo é a base de todo o resto do módulo.
- **Amostra menor que 3 intervalos usa o custo padrão do veículo**, marcado como padrão. Consumo sobre um intervalo é ruído.
- **A origem da quilometragem sempre acompanha o resultado** (informada ou estimada), pela mesma regra da margem parcial do Plano 18: número que parece medido e não é leva a decisão errada.
- **Quilometragem retroativa é recusada** com a última registrada na mensagem. Um erro de digitação contamina todos os intervalos seguintes.
- **Alerta por data ou por quilometragem, o que vier primeiro.** Troca de óleo é 6 meses ou 10 mil quilômetros, e os dois critérios são independentes.
- **Documento vencido insiste semanalmente**, como o lote vencido do Plano 17 e o documento regulatório do Plano 24: tem consequência legal.
- **Quilometragem desatualizada tem aviso próprio.** Sem `km_atual` confiável, o alerta por quilometragem não dispara e ninguém percebe.
- **O local de estoque do veículo é criado junto com o veículo.** Pedir que o usuário crie os dois e os vincule à mão é como a integração do Plano 17 fica sem uso.
- **Título a pagar é oferecido, não criado automaticamente.** Lançamento financeiro sem o usuário pedir suja o financeiro de quem controla frota só operacionalmente.

## Ordem de aplicação em produção

1. **Deploy 1** (27.1 a 27.4): estrutura, cálculo, alertas e endpoints, com o módulo `frota` **desligado** e a rotina de verificação parada.
2. **Deploy 2** (27.5): telas.
3. Ligar o módulo para o tenant que pedir. Cadastrar os veículos, registrar ao menos 3 abastecimentos de tanque cheio por veículo, e só então ligar a rotina de alerta.
4. Conferir a margem de algumas OS antes e depois da troca do deslocamento fixo pelo calculado: os números mudam, e a empresa precisa entender por quê.

## Observações

- O plano estimava ~5 tasks. A decomposição chegou a 6 porque os alertas são lógica própria com dois critérios independentes, e o cálculo de custo por quilômetro toca a margem do Plano 18.
- Este é o último plano do roteiro por escolha, não por esquecimento: só compensa para empresa com várias equipes, e não decide venda.
- O rastreamento do veículo em tempo real e a integração com cartão de combustível continuam fora de escopo: o primeiro exige equipamento em hardware, o segundo só compensa com frota grande.
- A troca do deslocamento fixo pelo calculado altera a margem histórica exibida. Isso não reescreve dado (o cálculo é feito na leitura), mas muda o número na tela e precisa ser comunicado ao cliente.
