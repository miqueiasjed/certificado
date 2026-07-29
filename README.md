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

As rotinas são executadas pelo agendador do Laravel e configuradas em `bootstrap/app.php` a partir das listas únicas de `App\Support\RotinasAgendadas`: `DIARIAS`, com hora marcada no dia, e `POR_INTERVALO`, que roda de N em N minutos.

| Rotina | Horário (America/Sao_Paulo) | O que faz |
|---|---|---|
| `certificates:update-status` | 00:10 | Atualiza o status dos certificados (ativo ou vencido) comparando a garantia com o dia de hoje. |
| `payments:update-statuses` | 00:20 | Atualiza o status de pagamento das parcelas e das ordens de serviço. Roda depois da anterior porque depende do dia de pagamento já fechado. |
| `cash:sync-daily-balances` | 00:30 | Sincroniza os saldos diários de caixa com as entradas e retiradas financeiras do dia. |
| `cash:create-missing-balances` | 00:40 | Cria o registro de saldo diário para os dias sem nenhum movimento financeiro. |
| `contratos:gerar-visitas` | 01:00 | Gera as visitas previstas dos contratos periódicos vigentes dentro da janela configurada, sem duplicar visita já existente. |
| `auditoria:purge` | 02:00 | Apaga de `audit_logs` e `access_logs` os registros mais antigos que o período de retenção. Horário fora da janela das outras porque é a mais pesada: percorre e apaga em lotes as tabelas de auditoria inteiras. |
| `notificacoes:avisos-diarios` | 07:00 | Enfileira os avisos que dependem da passagem do tempo: véspera de visita, certificado e contrato a vencer, pagamento vencido, orçamento a expirar e visita periódica não gerada. Uma hora antes das 08:00, que é a hora padrão de envio, para o lembrete da véspera já estar na fila quando o despachante passar. |
| `pesquisas:enviar` | 07:30 | Cria e enfileira a pesquisa de satisfação das ordens de serviço concluídas **no dia anterior**, no fuso do negócio. Nasce desligada: depende de `NOTIFICACOES_PESQUISA_SATISFACAO_ATIVA=true`. |
| `notificacoes:despachar` | a cada 5 minutos | Envia os avisos vencidos da fila de notificações, registra cada tentativa em `notification_logs` e reagenda as falhas temporárias. A cada 5 minutos, e não a cada minuto, porque aviso de visita não é tempo real. |
| `rotinas:verificar` | a cada 60 minutos | Descobre qual rotina desta tabela falhou ou parou de executar e avisa por e-mail o administrador de cada empresa. É a rotina que transforma "silêncio no histórico" em aviso. |

### Expurgo de auditoria

`audit_logs` e `access_logs` são imutáveis pela aplicação: o model bloqueia exclusão e alteração (`AuditLog`/`AccessLog`, em `booted()`). O único jeito permitido de remover uma linha dessas tabelas é o expurgo por retenção, e nenhuma tela, endpoint ou outro comando deve apagar auditoria.

- **Retenção:** `AUDITORIA_DIAS_DE_RETENCAO` no `.env`, lida em `config('auditoria.dias_de_retencao')`. Padrão de 730 dias (2 anos). Encurtar é decisão de negócio, não técnica.
- **Comando:** `php artisan auditoria:purge`. Aceita `--dias=N` para sobrepor a retenção configurada numa execução específica, e `--dry-run` para só contar e imprimir, sem apagar nada.
- O corte é calculado no dia de hoje do fuso do negócio (`BusinessDate::hoje()->subDays($dias)`) e a exclusão acontece em lotes de 1.000 linhas por vez, para não travar a tabela em produção.
- Rodar o comando duas vezes no mesmo dia é seguro: a segunda execução não encontra mais nada elegível e remove zero linhas.

### Avisos diários da central de notificações

`notificacoes:avisos-diarios` roda uma passada por empresa, dentro do tenant de cada uma, e coloca na fila os avisos que ninguém dispara clicando em nada. Os avisos ligados a uma ação (visita agendada, técnico a caminho, OS concluída) não passam por aqui: eles saem do observer da ordem de serviço, no momento em que a ação acontece.

- **Opções:** `--company=ID` roda em uma empresa só, e `--todas` deixa explícito o comportamento padrão.
- **Rodar duas vezes no mesmo dia não duplica nada.** A chave de idempotência da fila reconhece o aviso que já está lá, e nenhum corte desta rotina depende do instante da execução. É o que permite reprocessar um dia que falhou.
- **Erro em um registro não cala os demais:** cada disparo tem o próprio tratamento de erro, e o resumo do comando mostra quantos registros falharam. Erro em uma empresa também não interrompe as outras.
- **Certificado a vencer e pagamento vencido saem duas vezes**, uma para o cliente, com o texto do template (editável pelo tenant), e uma para a empresa, com um texto interno fixo. O template tem uma linha por evento e canal, escrita para o cliente; mandar esse mesmo texto para a equipe entregaria uma mensagem endereçada a quem não é o leitor.
- **Prazos configuráveis** em `config/notificacoes.php`: `NOTIFICACOES_DIAS_AVISO_CONTRATO_VENCER` (padrão 30) e `NOTIFICACOES_DIAS_AVISO_ORCAMENTO_A_EXPIRAR` (padrão 3). São globais da aplicação, não por empresa.

### Pesquisa de satisfação

`pesquisas:enviar` roda uma passada por empresa, dentro do tenant de cada uma, e cria a pesquisa das visitas concluídas no dia anterior. O cliente responde em `/pesquisa/{token}`, sem login: o token de 64 caracteres é a única credencial, e vale 30 dias.

- **Chave de liga e desliga:** `NOTIFICACOES_PESQUISA_SATISFACAO_ATIVA` (padrão `false`). Desligada, a rotina roda, informa e não cria nada. Ela nasce assim para o deploy subir sem mandar e-mail para a base inteira antes de alguém conferir a fila.
- **Nunca no mesmo dia da conclusão.** Pesquisa que chega com o técnico ainda saindo do local mede o atendimento, não o resultado do serviço.
- **Um envio por visita e no máximo um por cliente a cada 30 dias.** Cliente com visita semanal receberia quatro por mês, pararia de responder e o indicador morreria junto. Cliente que desligou os canais de aviso não recebe pesquisa nenhuma.
- **Nota 1 ou 2 marca pendência de contato e avisa a empresa**, com o comentário (evento `nota_baixa_recebida`). Nenhuma resposta automática vai ao cliente: quem resolve insatisfação é pessoa.
- **Média com menos de 3 respostas é omitida** em todo corte do painel (geral, por mês, por técnico e por tipo de serviço), e a contagem continua visível. Técnico avaliado por uma nota isolada é injustiça, e o indicador perde a credibilidade.
- **Opções:** `--company=ID` roda em uma empresa só, e `--todas` deixa explícito o comportamento padrão. Rodar duas vezes no mesmo dia não duplica nada.

### Despacho das notificações

`notificacoes:despachar` roda uma passada por empresa, cada uma dentro do tenant dela, e envia o que está `pendente` na tabela `notification_queue` com a hora de envio já vencida.

- **Opções:** `--limite=N` (padrão 100) limita quantos itens cada empresa processa por passada; `--company=ID` roda em uma empresa só, e `--todas` deixa explícito o comportamento padrão.
- **Toda tentativa vira uma linha em `notification_logs`**, inclusive a que falhou. É esse histórico que responde "por que o cliente não recebeu".
- **Falha temporária** (tempo limite, provedor fora do ar, limite de taxa) reagenda com espera crescente: 5 minutos, 30 minutos e 2 horas, com teto de 4 tentativas. A quarta encerra o item em `falha`.
- **Falha permanente** (endereço inválido, caixa inexistente, recusa definitiva do provedor) encerra em `falha` na primeira tentativa, sem repetir. Repetir envio para endereço inexistente derruba a reputação do remetente da empresa e leva junto a entrega de todos os outros avisos dela.
- **Item preso em `enviando` há mais de 15 minutos** volta para a fila na passada seguinte, com a tentativa registrada. É o que impede que um processo morto no meio do envio deixe o aviso parado para sempre.
- O remetente é sempre o e-mail da empresa dona do aviso, com `reply-to` dela. Sem e-mail cadastrado na empresa, o envio falha com a instrução do que corrigir.

### Aviso automático de rotina parada

`routines:status` responde a pergunta para quem abre o terminal e pergunta. `rotinas:verificar` faz a pergunta sozinha, de hora em hora, e manda a resposta para quem pode agir.

A diferença entre as duas está no caso grave. Rotina que falha grava `failed` em `scheduled_task_runs` e aparece no diagnóstico. Rotina que deixa de executar não grava nada: o histórico dela fica idêntico ao de um sistema saudável, e ninguém descobre o problema até um certificado vencido aparecer como ativo diante da fiscalização. Foi exatamente assim que o bug de status chegou à produção.

- **Dois problemas, avisados com textos diferentes:** `falhou` (a última execução terminou com erro, e o aviso carrega a mensagem registrada) e `não rodou` (nenhuma execução com sucesso dentro da janela de tolerância da rotina).
- **Janela de tolerância** = intervalo declarado da rotina + `RotinasAgendadas::MINUTOS_DE_TOLERANCIA_EXTRA` (120 minutos). Uma diária é cobrada depois de 26 horas sem rodar, não de 24: deploy na madrugada e cron disputado atrasam a passada sem que nada esteja quebrado, e alerta que dispara por atraso normal vira ruído e para de ser lido.
- **Um aviso por rotina por dia, por administrador.** A data do dia entra no marco da chave de idempotência, então rodar a verificação 24 vezes gera um aviso, e rotina quebrada há uma semana não gera 168 e-mails.
- **Quem recebe é o administrador de cada empresa**, por e-mail (evento `rotina_agendada_falhou`), e não o técnico: quem mexe em cron e em servidor é quem administra. Administrador desativado fica de fora. Empresa sem nenhum administrador ativo não interrompe a verificação: o caso vai para o log da aplicação.
- **Rotina com problema não faz o comando falhar.** O código de saída responde "a verificação funcionou?", e não "o sistema está saudável?". Terminar em erro marcaria esta execução como `failed` e a passada seguinte acusaria a própria verificação.
- **A verificação não se acusa de não ter rodado**, porque quem estaria escrevendo o aviso é a execução que prova o contrário. A falha dela, registrada na passada anterior, é avisada normalmente.
- Rodar `php artisan rotinas:verificar` no terminal é seguro e mostra a tabela de problemas encontrados. `--company=ID` limita a quais empresas o aviso é enfileirado; a detecção é sempre da plataforma inteira, porque `scheduled_task_runs` tem uma linha por execução do comando, não por empresa processada dentro dele.

Um limite honesto: se o cron parar por completo, `rotinas:verificar` para junto e nenhum aviso sai. Ela cobre a rotina que quebrou, não o agendador que morreu. Para esse caso continua valendo o monitoramento externo em cima do código de saída de `routines:status`.

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

O `.env` de produção precisa ser conferido a cada deploy: só o ambiente local foi verificado até agora, e é lá que `CACHE_STORE=database` está confirmado. Rodar `php artisan routines:status` logo depois do deploy confirma que o cron está instalado no servidor e que as rotinas seguem executando.

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
