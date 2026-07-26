# Sistema de Gestão para Controle de Pragas

Sistema operacional para empresas de dedetização e manejo integrado de pragas. Reúne o atendimento ao cliente, a execução em campo, a documentação obrigatória e o controle financeiro.

## O que o sistema oferece

- Cadastro de clientes, endereços, ambientes e contratos.
- Orçamentos com geração de documento e conversão em serviço.
- Ordens de serviço com produtos, procedimentos, técnicos e áreas atendidas.
- Registro de dispositivos, armadilhas, eventos e avistamentos de pragas.
- Controle de adequações recomendadas e evidências fotográficas.
- Cadastro de produtos, princípios ativos, grupos químicos, antídotos e registros em órgãos competentes.
- Emissão de certificados de garantia, ordens de serviço, recibos e contratos.
- Gestão de técnicos, serviços e tipos de ocorrência.
- Acompanhamento de pagamentos e informações financeiras por ordem.
- Fluxo de caixa, entradas, retiradas e painel financeiro.
- Configuração dos dados e da identidade da empresa nos documentos.

## Para quem foi feito

O sistema atende empresas de controle de pragas que precisam padronizar a execução dos serviços, manter rastreabilidade e entregar documentação profissional aos clientes.

## Rotinas agendadas

O sistema tem quatro rotinas diárias, executadas pelo agendador do Laravel e configuradas em `bootstrap/app.php` a partir da lista única em `App\Support\RotinasAgendadas::DIARIAS`.

| Rotina | Horário (America/Sao_Paulo) | O que faz |
|---|---|---|
| `certificates:update-status` | 00:10 | Atualiza o status dos certificados (ativo ou vencido) comparando a garantia com o dia de hoje. |
| `payments:update-statuses` | 00:20 | Atualiza o status de pagamento das parcelas e das ordens de serviço. Roda depois da anterior porque depende do dia de pagamento já fechado. |
| `cash:sync-daily-balances` | 00:30 | Sincroniza os saldos diários de caixa com as entradas e retiradas financeiras do dia. |
| `cash:create-missing-balances` | 00:40 | Cria o registro de saldo diário para os dias sem nenhum movimento financeiro. |

### O cron que faz tudo isso rodar

Nenhuma dessas rotinas roda sozinha. O Laravel só as dispara quando o comando `schedule:run` é chamado a cada minuto, e isso depende de uma linha de cron cadastrada no servidor:

```
* * * * * cd /Users/miqueias/Sites/certificado && php artisan schedule:run >> /dev/null 2>&1
```

O caminho acima é o do ambiente de desenvolvimento. No servidor, troque pelo caminho real da aplicação em produção antes de cadastrar o cron.

Sem essa linha configurada no servidor, nenhuma rotina executa. Essa foi a causa do bug de status em produção mapeado em `.claude/prd/divida-tecnica.md`: os quatro comandos sempre estiveram corretos, mas nunca eram chamados, então certificado vencido continuava marcado como ativo e pagamento atrasado continuava pendente.

### Como conferir se está rodando

```
php artisan routines:status
```

O comando mostra a última execução de cada rotina, o status, a duração e se está atrasada, sempre no fuso de Brasília. Ele termina com código de saída 1 quando alguma rotina está atrasada ou falhou, para servir de sinal em monitoramento externo. A opção `--task=` filtra uma rotina específica, por exemplo `php artisan routines:status --task=certificates:update-status`.

### Dois alertas antes de confiar no cron

1. **`CACHE_STORE` precisa ser `database` em produção, nunca `array`.** A trava `withoutOverlapping` usa o cache para o mutex que impede duas execuções simultâneas da mesma rotina. Com `CACHE_STORE=array`, o cache vive só na memória do processo, some a cada chamada do `schedule:run` e a trava perde o efeito entre uma chamada de cron e a seguinte.

2. **Se o MySQL cair, a rotina morre no mutex antes de qualquer código da aplicação.** O store `database` grava a trava na tabela `cache_locks`. Com o banco fora do ar, a tentativa de adquirir a trava falha ali mesmo, e nenhuma linha de `app/Console/Commands/` chega a executar. Nesse caso, `routines:status` mostra a lacuna crescendo desde a última execução, sem nenhuma linha `failed` para apontar a causa.

### Checagem de deploy

O `.env` de produção precisa ser conferido a cada deploy: só o ambiente local foi verificado até agora, e é lá que `CACHE_STORE=database` está confirmado. Rodar `php artisan routines:status` logo depois do deploy confirma que o cron está instalado no servidor e que as quatro rotinas seguem executando.
