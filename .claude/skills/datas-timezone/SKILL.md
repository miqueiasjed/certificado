---
name: datas-timezone
description: Regras e padrões para manipulação de datas e fuso horário (America/Sao_Paulo) no Sistema de Certificados, no backend e no frontend. Use ao lidar com validade, vencimento, agendamento ou qualquer formatação de data.
---
# Skill: Datas e Timezone

Neste sistema, data errada é documento errado. Validade de certificado, vencimento
de contrato, data de execução da OS e vencimento de parcela são o produto que o
cliente entrega ao fiscal. Um dia de diferença invalida a informação.

## 1. Estado atual do projeto (leia antes de mexer)

Duas dívidas conhecidas, mapeadas em `.claude/prd/divida-tecnica.md`:

- `config/app.php` usa `'timezone' => env('APP_TIMEZONE', 'UTC')`. O backend
  opera em UTC.
- O frontend formata data com `toLocaleDateString` em várias telas
  (`Clients/Show.vue`, `ChemicalGroups/*`, `OrganRegistrations/Index.vue`,
  `WorkOrderTabContent.vue`, entre outras), o que usa o fuso do navegador.

A combinação faz uma data salva como `2026-07-25` aparecer como 24/07/2026 para
o usuário. O Plano 1 padroniza isso. Enquanto não estiver concluído, **não
propague o padrão antigo em código novo**.

## 2. Timezone oficial

- Fuso do negócio: `America/Sao_Paulo`.
- Toda exibição ao usuário é nesse fuso.
- Nunca confie no fuso do navegador nem no do servidor sem converter.

## 3. Backend

- Campo que representa **um dia**, sem hora relevante (validade, vencimento,
  data de execução, data agendada), usa `date` no banco e `Carbon` sem hora.
  Não converta fuso em campo `date`: ele não tem hora, e converter é o que
  produz o erro de um dia.
- Campo que representa **um instante** (início e fim de execução, criação,
  registro de assinatura) usa `datetime`, gravado em UTC e convertido na
  exibição.
- Em cast de model, declare explicitamente: `'scheduled_date' => 'date'`,
  `'start_time' => 'datetime'`. Deixar implícito é o que causa confusão.
- Comparação de vencimento usa o dia no fuso do negócio, não `now()` em UTC.
  Um certificado que vence hoje não está vencido às 21h de Brasília, e é
  exatamente isso que acontece comparando com UTC.
- Em rotina agendada (`UpdateCertificateStatus`, `UpdatePaymentStatuses`), o
  "hoje" é o dia em `America/Sao_Paulo`.

## 4. Frontend

### 4.1. Proibições

Nunca use:

- `new Date().toLocaleDateString()`
- `new Date().toLocaleString()`
- `new Date().toLocaleTimeString()`
- `new Date('2026-07-25')` para data sem hora, porque o JavaScript interpreta
  como UTC e volta um dia em fuso negativo

### 4.2. Utilitários do projeto

Use as funções de `resources/js/utils/formatDate.js`:

- `formatarData()` - `dd/MM/yyyy`
- `formatarDataHora()` - `dd/MM/yyyy HH:mm`
- `formatarDataExtensa()` - `25 de julho de 2026`
- `formatarHora()` - `HH:mm`
- `diasAte()` - dias restantes até uma data, para alerta de vencimento

Se o arquivo ainda não existir, ele é criado no Plano 1. Não crie uma segunda
implementação em paralelo.

### 4.3. Ao criar nova função de formatação

Trate data sem hora como texto, dividindo `yyyy-MM-dd`, em vez de instanciar
`Date`. Para instante, converta explicitamente para `America/Sao_Paulo`.

## 5. Documentos em PDF

- A data impressa no certificado, na OS, no contrato e no recibo é sempre
  formatada no backend, no fuso do negócio, e nunca no frontend.
- Data por extenso em documento segue o padrão brasileiro, em português.

## 6. Checklist antes de aprovar código com data

- [ ] Campo de dia está como `date`, e campo de instante como `datetime`?
- [ ] O cast está declarado no model?
- [ ] Comparação de vencimento usa o dia no fuso do negócio?
- [ ] O frontend usa os utilitários, sem `toLocaleDateString`?
- [ ] Data em PDF vem formatada do backend?
