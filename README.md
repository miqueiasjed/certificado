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

O sistema tem cinco rotinas diárias, executadas pelo agendador do Laravel e configuradas em `bootstrap/app.php` a partir da lista única em `App\Support\RotinasAgendadas::DIARIAS`.

| Rotina | Horário (America/Sao_Paulo) | O que faz |
|---|---|---|
| `certificates:update-status` | 00:10 | Atualiza o status dos certificados (ativo ou vencido) comparando a garantia com o dia de hoje. |
| `payments:update-statuses` | 00:20 | Atualiza o status de pagamento das parcelas e das ordens de serviço. Roda depois da anterior porque depende do dia de pagamento já fechado. |
| `cash:sync-daily-balances` | 00:30 | Sincroniza os saldos diários de caixa com as entradas e retiradas financeiras do dia. |
| `cash:create-missing-balances` | 00:40 | Cria o registro de saldo diário para os dias sem nenhum movimento financeiro. |
| `auditoria:purge` | 02:00 | Apaga de `audit_logs` e `access_logs` os registros mais antigos que o período de retenção. Horário fora da janela das outras porque é a mais pesada: percorre e apaga em lotes as tabelas de auditoria inteiras. |

### Expurgo de auditoria

`audit_logs` e `access_logs` são imutáveis pela aplicação: o model bloqueia exclusão e alteração (`AuditLog`/`AccessLog`, em `booted()`). O único jeito permitido de remover uma linha dessas tabelas é o expurgo por retenção, e nenhuma tela, endpoint ou outro comando deve apagar auditoria.

- **Retenção:** `AUDITORIA_DIAS_DE_RETENCAO` no `.env`, lida em `config('auditoria.dias_de_retencao')`. Padrão de 730 dias (2 anos). Encurtar é decisão de negócio, não técnica.
- **Comando:** `php artisan auditoria:purge`. Aceita `--dias=N` para sobrepor a retenção configurada numa execução específica, e `--dry-run` para só contar e imprimir, sem apagar nada.
- O corte é calculado no dia de hoje do fuso do negócio (`BusinessDate::hoje()->subDays($dias)`) e a exclusão acontece em lotes de 1.000 linhas por vez, para não travar a tabela em produção.
- Rodar o comando duas vezes no mesmo dia é seguro: a segunda execução não encontra mais nada elegível e remove zero linhas.

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

## A empresa fora da requisição HTTP

O sistema é multiempresa: todo dado de domínio pertence a uma empresa, e quem responde qual é a empresa corrente é `App\Support\TenantAtual`. Em requisição web ela sai do usuário autenticado. Comando artisan, seeder e job de fila não têm usuário autenticado e portanto não têm empresa nenhuma: nesses três contextos a empresa é informada explicitamente, nunca inferida.

Como o banco chega a esse estado, em cinco deploys separados, e como voltar atrás em cada um: [`docs/multiempresa-migracao.md`](docs/multiempresa-migracao.md). Leia antes de aplicar qualquer migration de `company_id` em produção, principalmente a parte do `DEFAULT 1` temporário e a do ponto sem retorno.

### Comando artisan

Rotina que percorre dado de domínio usa a trait `App\Console\Commands\Concerns\OperaPorTenant`, que dá ao comando duas opções:

```
php artisan certificates:update-status --company=1   # roda só na empresa 1
php artisan certificates:update-status --todas       # roda em todas as empresas
php artisan certificates:update-status               # igual a --todas
```

Sem opção o comando percorre todas as empresas cadastradas, uma por vez, dentro do contexto de cada uma. É esse padrão que mantém as linhas de cron atuais funcionando sem alteração. A saída traz um bloco por empresa e um resumo no fim, com a quantidade processada e o erro de cada uma. Empresa que falha não interrompe as outras: o erro é registrado no log, aparece no resumo e o comando termina com código de saída 1, que é o que `routines:status` enxerga.

### Job de fila

Todo job novo usa a trait `App\Jobs\Concerns\CarregaTenant` e chama `capturarTenantAtual()` no construtor:

```php
class EnviarCertificadoPorEmail implements ShouldQueue
{
    use Queueable, CarregaTenant;

    public function __construct(public int $certificateId)
    {
        $this->capturarTenantAtual();
    }

    public function handle(): void
    {
        // já roda dentro da empresa que despachou o job
    }
}
```

A empresa é capturada no despacho e viaja no payload da fila, na propriedade pública `$companyId`. No consumo, o middleware `App\Jobs\Middleware\AplicaTenantDoJob` envolve o `handle()` no contexto dessa empresa, com `try/finally`, então nem job que falha deixa a empresa dele valendo para o próximo job do mesmo worker. Como reforço, o worker zera o contexto antes de buscar cada job (`Queue::looping` em `AppServiceProvider`).

Três coisas que valem lembrar antes de escrever um job:

1. **Nunca resolver a empresa dentro do `handle()`.** No worker não há usuário autenticado, então o que estiver valendo ali é o que sobrou de outro job.
2. **Despachar com `dispatch()` ou `dispatchSync()`.** `dispatchNow()` não passa pelo middleware de job.
3. **Job enfileirado antes deste deploy não tem `companyId` no payload.** Ao ser processado depois, ele falha com mensagem explícita e vai para `failed_jobs`, em vez de rodar na empresa errada. Reenfileirar com `queue:retry` resolve, porque o job é reconstruído já com a empresa.

### Seeder

`DatabaseSeeder` fixa a empresa fundadora antes de semear, e os seeders chamados por ele herdam esse contexto. Rodar um seeder de domínio sozinho (`php artisan db:seed --class=ClientSeeder`) fica sem empresa definida.
