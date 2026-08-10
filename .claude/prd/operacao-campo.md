# Operação de campo

## Visitas recorrentes do contrato

`contracts` já guarda `start_date`, `end_date`, `visit_frequency` (meses),
`service_type` (pontual ou periódico) e `warranty_period_days`. Nada consome
esses campos: as visitas são criadas na mão, uma por uma.

Requisitos:

- Contrato periódico gera as visitas previstas do período, como OS agendada.
- Geração idempotente: rodar duas vezes não duplica visita.
- Alterar a periodicidade do contrato reprograma somente as visitas futuras que
  ainda não foram executadas. Visita executada é histórico e não se mexe.
- Cancelar ou encerrar contrato cancela as visitas futuras, com registro do
  motivo.
- Frequência em meses cobre o caso comum. Contrato com visita semanal ou
  quinzenal existe no mercado e precisa ser suportado, então a periodicidade
  ganha unidade (dias, semanas, meses).
- A RDC 622/2022 trata de visitas periódicas de inspeção. Contrato periódico sem
  visita gerada no período é uma pendência que o sistema deve apontar.

## Agenda

Hoje a OS só existe em lista. Falta a visão de calendário que todo concorrente
tem.

- Calendário por dia, semana e mês.
- Filtro por técnico, tipo de serviço, status e cidade.
- Arrastar e soltar para reagendar, com registro de quem reagendou e quando.
- Conflito de horário do mesmo técnico é bloqueado, com aviso claro.
- Visita sem técnico atribuído aparece destacada, para não ser esquecida.
- Carga de trabalho por técnico no período, para distribuir serviço.

## Compromisso avulso, sem OS

Agenda e roteirização hoje são 100% projeção de `work_orders`: `AgendaService`
lê `scheduled_date` da OS, e `route_stops.work_order_id` é `NOT NULL`. Não
existe caminho para colocar no calendário ou numa rota algo que não seja
uma OS já cadastrada.

Levantado em conversa com o usuário em 10/08/2026: existem compromissos reais
que não justificam abrir uma OS. Visita de orçamento para quem ainda não é
cliente, checagem de garantia fora do ciclo de visitas do contrato, ou
qualquer outro motivo que o técnico precisa ter no dia sem que uma OS nasça
para isso. Precisam aparecer na agenda e poder entrar num roteiro do mesmo
jeito que uma OS entra hoje.

Requisitos:

- Compromisso é entidade própria, desacoplada de `WorkOrder` desde a raiz, com
  tipo fechado (orçamento, garantia, retorno, outro), cliente e endereço
  opcionais (cobre quem ainda não é cliente cadastrado) e técnico opcional.
- Aparece na Agenda junto com as OS do período, visualmente distinto.
- Pode virar parada de um roteiro, junto com paradas de OS no mesmo dia e
  técnico.
- Pode, mais tarde, originar uma OS de verdade (ex.: orçamento aprovado), sem
  que isso seja obrigatório: compromisso que nunca vira OS continua válido.
- Não substitui nada do fluxo de OS: não gera cobrança, não entra no
  checklist de conformidade RDC 622/2022, não emite certificado.

## Identificação de dispositivos por QR code

`devices` tem `label`, `number` (código de etiqueta), `bait_type_id` e
`default_location_note`. A leitura por QR code é padrão do setor (VEL, SIS,
GorillaDesk com código de barras) e não existe.

- Cada dispositivo tem um identificador único e estável para QR code, distinto
  do id do banco, para não expor sequência interna.
- Impressão de etiquetas em folha, com nome do dispositivo, código e local.
- Leitura pela câmera do celular abre direto o registro daquele dispositivo na
  OS em execução.
- Ler dispositivo que não pertence ao endereço da OS avisa o técnico, em vez de
  registrar errado.
- Substituir dispositivo danificado preserva o histórico do ponto de instalação.

## App do técnico

O maior salto competitivo e o item mais caro. Concorrentes com app offline:
SIS, PestWase, Servicfy, Produttivo, Briostack.

PWA, sem app nativo em loja, para não pagar o custo de duas plataformas.

Requisitos de funcionamento offline:

- Técnico baixa as OS do dia antes de sair, com endereços, cômodos,
  dispositivos, produtos e serviços.
- Execução completa sem rede: leitura de dispositivo, registro de evento,
  avistamento de praga, produto aplicado, adequação, foto e observação.
- Sincronização ao recuperar sinal, com fila persistente que sobrevive a
  fechar o navegador ou reiniciar o celular.
- Conflito de sincronização nunca descarta trabalho do técnico em silêncio: a
  pendência fica visível e resolvível.
- Foto é o dado mais pesado. Compressão no dispositivo antes de enfileirar, com
  envio em segundo plano.

Assinatura do cliente em campo:

- Coleta por toque na tela, com nome e documento de quem assinou.
- Registro de data, hora e, quando autorizado, coordenada da coleta.
- A assinatura entra no PDF da OS. Hoje o PDF só traz a assinatura do
  responsável técnico da empresa, guardada em `companies`.
- OS assinada fica travada para edição. Correção posterior exige registro de
  justificativa e fica visível na auditoria.
