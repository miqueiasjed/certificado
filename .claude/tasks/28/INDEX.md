# Tasks do Plano 28 - Controle de EPI: cadastro, CA e ficha de entrega

> Gerado em: 08/08/2026

## Legenda

- ✅ Concluída | 🔄 Em andamento | ⏳ Pendente

| # | Título | Tipo | Status | Complexidade |
|---|--------|------|--------|--------------|
| 28.1 | Migrations e models de EPI e entrega | backend-estrutura | ✅ | média |
| 28.2 | Service de entrega, snapshot do CA e estorno | backend-logica | ✅ | alta |
| 28.3 | Alertas de CA a vencer, CA vencido e troca vencida | backend-logica | ✅ | alta |
| 28.4 | Endpoints, permissões e módulo `epi` | backend-endpoint | ✅ | alta |
| 28.5 | Ficha de EPI em PDF e extração por período | backend-logica | ✅ | média |
| 28.6 | Telas de cadastro de EPI e ficha do técnico | frontend-pagina | ✅ | alta |
| 28.7 | Testes de snapshot do CA, estorno e alertas | teste | 🔄 parcial | alta |

## Ordem de execução

```
Lote 1:             28.1
Lote 2 (paralelo):  28.2  |  28.3
Lote 3 (paralelo):  28.4  |  28.5
Lote 4:             28.6
Lote 5:             28.7
```

## Dependências internas

- 28.2 e 28.3 dependem de 28.1
- 28.3 depende do Plano 14 (avisos)
- 28.4 depende de 28.2
- 28.5 depende de 28.1 e lê a cópia do CA gravada pela 28.2
- 28.6 depende de 28.4 e 28.5, e do quadro de assinatura do Plano 13
- 28.7 depende de 28.2, 28.3 e 28.5

## Arquivos disputados (coordenação obrigatória)

| Arquivo | Tasks | Regra |
|---|---|---|
| `app/Support/EventosDeNotificacao.php` | 28.3 | Task única; acrescenta 3 eventos |
| `app/Support/RotinasAgendadas.php` | 28.3 | Task única; acrescenta 1 rotina diária |
| `app/Support/CatalogoDeModulos.php` | 28.4 | Task única; acrescenta o módulo `epi` |
| `app/Console/Commands/SyncPermissions.php` | 28.4 | Task única; acrescenta 4 permissões |
| `routes/web.php` | 28.4 | Task única |
| `app/Http/Controllers/PpeDeliveryController.php` | 28.4 cria, 28.5 acrescenta 2 ações | Serializar: 28.5 só depois de 28.4 |
| `app/Models/Technician.php` | 28.1 | Já alterado no Plano 18 (`custo_hora`); conferir o estado antes |

Se 28.2 e 28.3 rodarem em paralelo, os pontos de extensão do 28.3 são de adição
e não colidem com os Services do 28.2. O conflito perigoso nesses arquivos é o
que divide as linhas de fechamento de um item de array — nesse caso, dar a cada
lado o próprio fechamento e conferir por execução, não por leitura.

## Decisões registradas

- **As duas validades são colunas separadas.** `validade_ca` é do certificado do
  fabricante e vale para as entregas futuras; `trocar_ate` é do item na mão
  daquele técnico. Tratar as duas como uma só produz um sistema que ora alerta o
  mundo inteiro por um certificado renovado, ora nunca alerta a troca do
  respirador usado há dois anos.
- **O CA é copiado no ato da entrega, nunca lido pela relação.** A ficha diz o
  que foi entregue naquele dia. É a mesma razão pela qual documento emitido não
  se recalcula.
- **A entrega é imutável.** Documento oponível, arquivamento recomendado de 20
  anos. Correção é estorno com motivo, na convenção do razão de estoque do
  Plano 17. Não existe rota de exclusão.
- **CA vencido recusa a entrega.** A recusa é do escritório, não do campo:
  entregar EPI com CA vencido é a própria infração que o registro deveria
  evitar. A mensagem aponta onde se corrige.
- **Vida útil ausente significa "sem troca programada"**, não um prazo padrão
  inventado. Prazo inventado vira alerta falso, e alerta falso faz o usuário
  desligar o alerta inteiro.
- **CA em branco é estado neutro, nunca vermelho.** Campo não preenchido não é
  irregularidade — mesma regra do checklist do Plano 24, onde acusar de
  irregular quem apenas não preencheu destruiria a confiança no checklist.
- **A extração por período é entregável, não enfeite.** O item 6.5.1.1 da NR-6
  só aceita o registro eletrônico se ele permitir extrair relatórios.
- **`Rule::exists()` com o escopo do tenant, nunca `exists:` cru.** É a falha
  corrigida no `d9a3a9c` e não pode voltar em código novo.
- **EPI fica fora do estoque.** `Product` é ficha técnica de saneante, com
  princípio ativo, grupo químico e registro em órgão. EPI ali polui o cadastro
  regulatório.

## Ordem de aplicação em produção

1. **Deploy 1** (28.1 a 28.5): estrutura, regras, endpoints e documentos, com o
   módulo `epi` **desligado** e a rotina `epi:verificar` parada.
2. **Deploy 2** (28.6): telas.
3. Ligar o módulo para o tenant que pedir. Cadastrar os modelos de EPI **com CA
   e validade**, registrar as entregas em aberto e só então ligar a rotina.
4. **A primeira execução de `epi:verificar` depois de ligar o módulo pode gerar
   uma leva grande de avisos**, porque toda entrega retroativa lançada com data
   antiga já nasce com a troca vencida. Conferir o volume com `--dry-run` antes
   de ligar a rotina — foi o que a Task 27.5 aprendeu com `frota:verificar`.

## Observações

- A migration é inteiramente aditiva: duas tabelas novas, nenhuma coluna em
  tabela com dado em produção. É o plano mais seguro de aplicar do roteiro
  recente, ao contrário do Plano 27.
- O plano estimava ~7 tasks e a decomposição fechou em 7. O documento ficou em
  task própria porque PDF de valor legal exige conferência visual, não só
  execução sem erro.
- A ficha de EPI é o primeiro documento do sistema cuja contraparte não é o
  cliente, e sim o próprio funcionário e o fiscal do trabalho.
