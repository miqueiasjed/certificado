# Conformidade RDC 622/2022 — o que mudou nos documentos emitidos

> Plano 24, Task 24.2. Documento emitido tem valor perante fiscalização, então
> esta é a lista completa e literal do que foi alterado em cada PDF.

## A norma

A RDC nº 622, de 9 de março de 2022, da Anvisa, dispõe sobre o funcionamento
das empresas especializadas na prestação de serviço de controle de vetores e
pragas urbanas. Foi publicada no DOU de 16/03/2022, está em vigor desde 1º de
abril de 2022 (art. 25) e revogou, no art. 24, a RDC nº 52, de 22 de outubro de
2009, e a RDC nº 20, de 12 de maio de 2010.

## Levantamento feito ANTES de alterar qualquer texto

Varredura por `rdc|resolu|anvisa|legisla|portaria|norma|sanit`, sem distinção
de maiúsculas, em `resources/views/pdf/*.blade.php`.

**Resultado: nenhuma das cinco views citava número de resolução.** Nem a RDC
52/2009, nem qualquer outra. O que existia eram remissões genéricas à
legislação, listadas abaixo. Todas permanecem **intactas**, com a redação
original; a alteração desta task apenas acrescenta, ao lado delas, a resolução
nomeada, lida do cadastro.

| Arquivo | Linha (antes da alteração) | Texto encontrado |
|---|---|---|
| `contract.blade.php` | 130 | "…bem como pela legislação aplicável, em especial o Código Civil e o Código de Defesa do Consumidor (Lei nº 8.078/90)…" |
| `contract.blade.php` | 201-202 | "…autorizados pela ANVISA… e pela legislação vigente." |
| `contract.blade.php` | 213 | "A empresa declara possuir as licenças sanitárias e ambientais necessárias…" |
| `contract.blade.php` | 222 | "A CONTRATADA utilizará produtos registrados na ANVISA…" |
| `contract.blade.php` | 254 | "…contendo as informações exigidas pela legislação sanitária aplicável." |
| `contract.blade.php` | 327 | "…conforme previsto na legislação sanitária." |
| `contract.blade.php` | 371 | "…aplicando-se as normas de proteção ao consumidor (Lei nº 8.078/90 – CDC)." |
| `certificate.blade.php` | 298-311 | Bloco "INFORMAÇÕES LEGAIS E DE SEGURANÇA": lista licenças e registros, sem citar a norma que os exige. |
| `work-order.blade.php` | — | Nenhuma remissão normativa. |
| `service-order.blade.php` | — | Nenhuma remissão normativa. |
| `receipt.blade.php` | — | Nenhuma remissão normativa. |

Única citação da RDC 52/2009 em todo o repositório:
`app/Support/CatalogoInicialDoTenant.php`, linha 25 — **docblock de código**,
que não sai em documento nenhum. Deixada como está: é registro histórico da
fonte consultada quando aquele catálogo foi montado.

## O que foi acrescentado, documento por documento

Em todos os casos o texto vem de `normative_references` (chave
`rdc_principal`), resolvido por `App\Services\Compliance\ReferenciaNormativaService`.
**Nenhuma outra alteração de layout, fonte, tamanho, cor, ordem de blocos ou
conteúdo foi feita.**

| Documento | Onde entrou | Frase acrescentada |
|---|---|---|
| `certificate.blade.php` | Tabela nova logo após o bloco "INFORMAÇÕES LEGAIS E DE SEGURANÇA", antes das assinaturas. Fonte 9px, o mesmo tamanho do bloco vizinho. | "Serviço prestado em conformidade com a {referência}." |
| `contract.blade.php` | Parágrafo novo dentro da seção de abertura, imediatamente abaixo da remissão à legislação aplicável (que continua igual). | "Aplica-se ainda a este Contrato, no que se refere ao controle de vetores e pragas urbanas, a {referência}." |
| `work-order.blade.php` | Primeira linha do rodapé `.footer-info`, acima de "Documento gerado automaticamente…". | "Serviço prestado em conformidade com a {referência}." |
| `service-order.blade.php` | Parágrafo novo depois do bloco de assinatura. | "Serviço prestado em conformidade com a {referência}." |
| `receipt.blade.php` | Parágrafo novo ao final do corpo, depois do bloco de totais. | "Serviço prestado em conformidade com a {referência}." |

Com a referência padrão da plataforma, a frase sai como:

> Serviço prestado em conformidade com a RDC nº 622, de 9 de março de 2022, da
> Anvisa.

## Como a referência chega até a view

Por **view composer** registrado em
`AppServiceProvider::registrarReferenciaNormativaNosDocumentos()`, aplicado ao
padrão `pdf.*`, e não por parâmetro em cada chamada. Motivo: as cinco views são
carregadas de oito lugares diferentes (`CertificateController`,
`ContractController`, `WorkOrderController` × 2, `ServiceOrderController`,
`Portal\PortalDocumentController` × 4, `Notification\DriverDeEmail`). Um
parâmetro por chamada seriam oito pontos onde alguém pode esquecer, e o
esquecido sai como documento **sem** a norma na mão do fiscal, sem erro nenhum
na tela. Além disso, `pdf.service-order` não recebe `$company`, então nem
haveria de onde tirar a empresa dentro da view.

Precedência da empresa: o `$company` já passado à view manda, porque é dele o
cabeçalho impresso (o portal do cliente e o envio por e-mail em fila resolvem a
empresa por conta própria e podem não coincidir com o tenant da requisição).
Sem `$company`, cai no tenant corrente. Sem tenant corrente, na referência
padrão da plataforma (`company_id` nulo).

## O que esta entrega deliberadamente NÃO faz

- **Não reprocessa documento antigo.** Os PDFs são gerados sob demanda e
  transmitidos; não há arquivo emitido guardado em disco nem texto legal
  copiado para dentro de `certificates`/`contracts`. Não existe job de
  reprocessamento nem migration de backfill nesta entrega, e nenhum registro
  histórico é reescrito.
- **Não bloqueia emissão.** `ReferenciaNormativaService::obter()` devolve string
  vazia em qualquer falta ou falha (tenant sem referência, plataforma sem
  referência, tabela ainda não migrada, banco fora do ar no meio da
  renderização) e registra em log. A view omite a linha inteira. Documento que
  falha de gerar é pior que documento sem a linha da referência, e é o técnico
  em campo que paga a diferença.
- **Não altera as remissões genéricas já existentes** no contrato. Elas são
  texto contratual acordado com o cliente; reescrevê-las é decisão jurídica, não
  técnica.

## Como conferir antes de subir

1. `php artisan db:seed --class=NormativeReferenceSeeder` (cria a referência
   padrão da plataforma).
2. Gerar os cinco PDFs e comparar com os do deploy anterior, lado a lado.
   A única diferença aceitável é a frase da tabela acima. Qualquer outra
   diferença é defeito.
3. Conferir também o caso sem referência nenhuma (tabela vazia): os cinco
   documentos precisam sair, apenas sem a frase.
