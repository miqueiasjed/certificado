# Monitoramento e conformidade

## Relatório de tendência

O sistema já registra o dado bruto certo: `device_events` e
`work_order_device_events` guardam o que foi encontrado em cada dispositivo, e
`pest_sightings` guarda avistamento por cômodo. Falta transformar isso em
informação.

Esse relatório é o entregável que cliente de indústria alimentícia, hospital e
rede de alimentação exige em auditoria. É o principal argumento para vender para
cliente grande.

- Evolução do consumo de isca e da captura por dispositivo ao longo do tempo.
- Comparação entre períodos, para mostrar se a infestação cai ou sobe.
- Ranking dos pontos críticos, ou seja, quais dispositivos concentram a
  ocorrência.
- Mapa de calor por área e por cômodo.
- Ocorrência por espécie de praga.
- Adequações recomendadas em aberto e prazo de atendimento pelo cliente, porque
  infestação que não cai costuma ser falha estrutural do cliente.
- Exportação em PDF com identidade do tenant, para o cliente arquivar.
- Disponível também no portal do cliente.

## Mapa e croqui dos pontos

Hoje a localização do dispositivo é texto livre em
`devices.default_location_note`, do tipo "atrás da geladeira". VEL e SIS
oferecem mapa de armadilhas.

- Upload da planta ou croqui do endereço.
- Posicionamento dos dispositivos sobre a planta, arrastando.
- Visualização do estado de cada ponto na planta, com a ocorrência do período.
- Impressão do croqui para o técnico e para o cliente.
- Croqui versionado, para que relatório antigo continue refletindo o layout da
  época.

## Conformidade RDC 622/2022

A RDC 52/2009 foi substituída pela RDC 622/2022, publicada em 09/03/2022 e
vigente desde 01/04/2022. Ela também revogou a RDC 20/2010.

Pontos que impactam o sistema:

- Documento emitido que cite a RDC 52 está desatualizado. Revisar os textos de
  contrato, certificado, OS e recibo.
- Responsável técnico habilitado e com registro no conselho profissional é
  exigência. `companies` já guarda dados do responsável e licenças; falta
  controle de validade com alerta de vencimento.
- Somente produto saneante desinfestante registrado na Anvisa pode ser usado.
  `products` já se liga a `organ_registrations`; falta controlar a validade do
  registro e impedir a aplicação de produto com registro vencido.
- Documentação da execução do serviço com produto, quantidade, praga-alvo e
  responsável. Já coberto pela OS.
- Checklist de conformidade indicando o que falta à empresa para estar regular.

Ressalva: o alcance exato das exigências precisa ser conferido no texto oficial
da resolução antes de implementar bloqueio, para não travar a operação do
cliente por interpretação equivocada.
