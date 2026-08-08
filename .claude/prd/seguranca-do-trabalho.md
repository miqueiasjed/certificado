# Segurança do trabalho: EPI do técnico

> Consolidado em 08/08/2026. Fonte dos Planos 28 e 29.

## Por que isto existe

O sistema não tem nenhum controle de EPI. A palavra aparece em dois lugares e em
nenhum deles é dado:

- `resources/views/pdf/contract.blade.php` — o contrato **promete ao cliente**
  que a equipe usa EPI.
- `app/Services/Compliance/ChecklistService.php` e
  `ConformidadeDaExecucaoService.php` — o checklist da RDC 622/2022 (Plano 24)
  declara, por escrito, que EPI é parte do que "o sistema nem enxerga".

Ou seja: a empresa assina contrato afirmando algo que não consegue comprovar, e
o próprio checklist de conformidade admite o buraco.

## O que a norma exige

### NR-6 — o registro da entrega é obrigatório

- O fornecimento de EPI ao trabalhador **deve ser registrado**. Desde a Portaria
  SIT nº 107/2009 o registro pode ser físico ou eletrônico; a Portaria MTE nº
  2.175/2022 deu à NR-6, item 6.5.1, a redação que aceita explicitamente
  "sistema eletrônico, inclusive por sistema biométrico".
- **Item 6.5.1.1:** o sistema eletrônico adotado **deve permitir a extração de
  relatórios**. Isto não é conveniência de produto — é condição para o registro
  eletrônico ser aceito. Um controle que só mostra tela não cumpre a norma.
- O registro precisa dar rastreabilidade de: quem recebeu, qual equipamento,
  **o número do CA**, a data, a quantidade e a confirmação do recebimento.
- Empresa que fornece EPI e não registra a entrega está em desacordo com a NR-6
  e é autuável. O registro é a prova do empregador em reclamatória trabalhista;
  a recomendação de arquivamento é de no mínimo 20 anos.

### CA — Certificado de Aprovação

Todo EPI comercializado no Brasil tem CA emitido pelo órgão nacional de
segurança e saúde no trabalho, com prazo de validade próprio. **EPI com CA
vencido não serve como prova de proteção**: a autuação continua de pé e a
defesa do empregador cai junto.

### RDC 622/2022 — o lado sanitário

A resolução define EPI e exige que o uso e a manutenção dos equipamentos
estejam descritos nos Procedimentos Operacionais Padronizados (POP) da empresa
especializada, junto com o que trata de saúde, biossegurança e saúde do
trabalhador. Aplicador de saneante é exposição química diária: o EPI aqui não é
formalidade, é o que separa a operação de um acidente com produto registrado.

## Duas validades que não se confundem

Este é o erro clássico de quem modela EPI, e é a decisão central destes planos:

| | Validade do CA | Vida útil do item entregue |
|---|---|---|
| Pertence a | modelo de EPI cadastrado | **entrega específica** |
| Muda para | todas as entregas futuras daquele modelo | só aquele item, daquele técnico |
| Origem | certificado do fabricante | uso, desgaste, prazo do fabricante |
| Consequência de vencer | não pode mais ser entregue | precisa ser trocado |

Tratar as duas como uma só produz um sistema que ora alerta o mundo inteiro por
causa de um certificado renovado, ora nunca alerta a troca do respirador que o
técnico usa há dois anos.

## Modelo de dados

- **`PersonalProtectiveEquipment`** — o modelo de EPI: nome, tipo (respirador,
  luva, óculos, protetor auricular, calçado, vestimenta, outro), fabricante,
  número do CA, validade do CA, vida útil em dias, se é obrigatório.
- **`PpeDelivery`** — a ficha de entrega: técnico, EPI, quantidade, data,
  motivo, **CA e validade copiados no ato**, data prevista de troca, assinatura
  do recebimento, devolução.
- **`ServicePpeRequirement`** — quais EPIs cada serviço exige (Plano 29).
- **`WorkOrderPpeConfirmation`** — o que o técnico confirmou vestir naquela OS
  (Plano 29).

## Regras de negócio

1. **A entrega é imutável.** Documento oponível, arquivado por 20 anos. Erro se
   corrige com estorno justificado, nunca com `UPDATE` ou `DELETE` — mesma
   convenção do razão de estoque do Plano 17.
2. **O CA é copiado no ato da entrega.** A ficha diz o que foi entregue naquele
   dia. Renovar o CA no cadastro não pode reescrever a ficha do ano passado.
3. **EPI com CA vencido não é entregue.** A recusa é do escritório, não do
   campo: entregar EPI com CA vencido é a própria infração que o registro
   deveria evitar. Corrige-se atualizando o cadastro.
4. **Entrega sem assinatura não é ficha válida**, é pendência. É a assinatura
   que a NR-6 exige como confirmação do recebimento.
5. **A ficha sobrevive ao técnico.** Desligamento inativa o técnico; a ficha
   dele continua consultável e extraível.
6. **Toda comparação de vencimento é por dia no fuso do negócio**, via
   `App\Support\BusinessDate`. Validade é `date`, nunca sofre conversão de fuso.
7. **Nada disto bloqueia a execução da OS.** O sistema avisa, registra e
   comprova; travar o técnico em campo por pendência cadastral tira a empresa
   do ar. Mesma escolha do checklist do Plano 24.

## Fora de escopo

- Integração com estoque. EPI não é `Product` — `Product` é ficha técnica de
  saneante, com princípio ativo, grupo químico e registro em órgão. Forçar EPI
  ali polui o cadastro regulatório. Se um dia compensar, entra como plano
  próprio.
- Consulta automática ao CAEPI para validar o CA. Depende de serviço externo
  fora do controle do projeto; o número e a validade são digitados.
- Biometria. A NR-6 aceita, mas exige hardware.
- ASO, PPRA/PGR, treinamento e ficha de saúde ocupacional. São outro domínio,
  com outra norma.

## Fontes

- NR-6 (Equipamento de Proteção Individual), itens 6.5.1 e 6.5.1.1, redação da
  Portaria MTE nº 2.175/2022.
- Portaria SIT nº 107/2009 — registro eletrônico do fornecimento.
- RDC ANVISA nº 622/2022 — definição de EPI e exigência de POP.
