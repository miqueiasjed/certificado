# Handoff

Plano: 23
Task: -
Estado: Concluído e commitado (local), não publicado
Tentativas: -
Base Git: ver `git log` (commit local do Plano 23 acima deste handoff)
Feito: as 9 tasks do Plano 23 (Comissões, metas e renovação de contratos) completas. Migrations/models de comissão, meta e renovação (23.1); apuração com regra vigente na data do fato, fechamento imutável, reabertura auditada e estorno tratado (23.2); metas com projeção a partir do 5º dia útil e indicadores comerciais com corte de amostra pequena (23.3); renovação de contrato com reajuste, janela de 90/30 dias e cadeia navegável (23.4); alertas de contrato a vencer por marco configurável por tenant e pendência semanal de vencido sem tratativa (23.5); endpoints com "cada um vê só a própria comissão" e demais ações protegidas por permissão (23.6); telas de comissão/metas e painel comercial/contratos a vencer (23.7/23.8); suíte de testes ampliada, incluindo isolamento entre empresas na apuração (23.9). Suíte completa verde: 1370 testes, 11244 asserções. `npm run build` limpo.
Achados corrigidos durante a execução (registrar em `.claude/progress.txt`):
- `Address::contract()` era `HasOne` sem ordenação; após a Task 23.4, um endereço pode ter mais de um `Contract` (o renovado fica preservado), e a relação resolvia para o primeiro registro (o antigo), quebrando `ContractController::generatePDF()` (documento com valor perante fiscalização). Corrigido com `latestOfMany()` em `app/Models/Address.php`, mais `contracts()` (HasMany) para navegar o histórico. Teste de regressão em `ContractRenewalServiceTest`.
- `EnfileirarAvisosDiarios::contratosAVencer()` (Plano 14) tinha o mesmo bug de não filtrar `situacao_renovacao` (contrato renovado voltava a alertar para sempre) e prazo só global, não por tenant. Substituído inteiramente por `VerificarContratosAVencer` (Task 23.5), que corrige os dois problemas; config e testes órfãos removidos junto.
Falta: nenhuma ação pendente no Plano 23, exceto commit (feito) e push (pendente de autorização explícita do usuário).
Erro reproduzível: nenhum erro ativo
Arquivos alterados: ver `git show --stat` do commit do Plano 23 — schema (5 migrations aditivas/nullable), models, services (`Commission`, `Commercial`, `ContractRenewalService`, `ContractAlertService`), controllers e requests novos, rotas, permissões (`SyncPermissions`/`RolesAndPermissionsSeeder`), telas Vue (`Commissions/`, `Goals/`, `Comercial/Indicadores.vue`, `Contracts/AVencer.vue`, `RenovacaoModal.vue`) e testes do Plano 23 inteiro. `.claude/plans/INDEX.md` e `.claude/tasks/23/INDEX.md` marcados como concluídos.
Risco residual: `CommissionCalculationService::resolverVendedorDoRecebimento()` (base "recebido") e o mesmo cálculo em `GoalService`/`IndicadoresComerciaisService` resolvem o vendedor pelo orçamento aprovado/convertido mais recente do cliente — heurística correta no caso comum, mas pode atribuir a pessoa errada se o mesmo cliente teve orçamentos aprovados por vendedores diferentes em sequência. Corrigir de vez exige `budget_id` nullable em `work_orders` (estrutura, sem backfill), fora do escopo deste plano. `CommissionRule.service_type_id` é inerte na base "executado" (nenhuma OS expõe tipo de serviço resolvível hoje). Pagar comissão como despesa (`CommissionClosingService::marcarComoPaga`) exige `supplier_id` de um fornecedor já cadastrado que represente a pessoa, por limitação do schema do Plano 18 (`payables.supplier_id` obrigatório, sem conceito de beneficiário interno).
Próxima ação: cinco planos com dependências satisfeitas seguem liberados (23 concluído libera nada novo sozinho, mas 27 já estava liberado desde o Plano 22): 24 (Conformidade RDC 622/2022), 25 (Laudo assistido por IA), 26 (Assinatura eletrônica de contratos), 27 (Frota e veículos). Escolher pela ordem de execução recomendada em `.claude/plans/INDEX.md` ao retomar.

## Histórico

### Plano 23 (concluído)
Ver `.claude/progress.txt` para os aprendizados duráveis registrados.
Feito: Plano 22 (Roteirização e rastreamento em campo) completo, 8 tasks. Coordenada de endereço (geocodificação via Nominatim, gratuito), roteiro do dia por técnico ordenado por proximidade com âncora de horário, mapa das visitas (Leaflet), registro de local de início/fim de execução com consentimento, divergência de local e rastreamento contínuo opcional desligado por padrão. Suíte completa do projeto: 1.285 testes, 10.745 asserções, tudo verde. `npm run build` limpo. Quatro achados corrigidos durante a execução (registrados em `.claude/progress.txt`): coordenada de sede fictícia `(0,0)` inflando a distância total do roteiro; divergência de local zerada abaixo do limiar (apagava o dado que a task existe para preservar); reordenação manual não recalculava totais; rastreamento contínuo não exposto ao aplicativo.
Falta: nenhuma ação pendente no Plano 22, exceto commit (e push, se autorizado).
Erro reproduzível: nenhum erro ativo
Arquivos alterados: ver `git status` — abrange schema, models, services (Geo, Routing), controllers, rotas, permissões, seeders, telas web (painel de roteiro, mapa, pendências geo) e do app do técnico (roteiro, captura de local), e testes do Plano 22 inteiro. `.claude/plans/INDEX.md` e `.claude/tasks/22/INDEX.md` marcados como concluídos.
Risco residual: geocodificação real em produção usa Nominatim (gratuito, sem chave) — respeitar a política de uso deles (User-Agent, ~1 req/s) ao rodar o backfill em lote real. Rastreamento contínuo de técnico é dado pessoal sensível: nasce desligado, só liga com consentimento registrado, precisa de decisão do responsável do tenant antes de ativar para qualquer técnico. Coordenada de sede ainda não existe (`companies` sem lat/lng) — o roteiro funciona sem ela (primeiro trecho não entra no total), mas fica menos preciso; task futura pode resolver. Deploy em quatro etapas conforme `.claude/tasks/22/INDEX.md` ("Ordem de aplicação em produção") — módulo `roteirizacao` desligado até o Deploy 3/4, geocodificação em lote rodada com `--dry-run` primeiro e pendências de precisão "cidade" corrigidas manualmente antes de seguir.
Próxima ação: commitar localmente (sem push, sem autorização explícita do usuário para isso). Depois, cinco planos ficam com dependências satisfeitas: 23 (Comissões, metas e renovação de contratos), 24 (Conformidade RDC 622/2022), 25 (Laudo assistido por IA), 26 (Assinatura eletrônica de contratos), 27 (Frota e veículos, liberado agora que o 22 terminou). Escolher pela ordem de execução recomendada em `.claude/plans/INDEX.md` ao retomar.

Fora do escopo deste plano, deixados como estavam (não tocar sem pedido explícito):
- `.claude/skills/run-plan/SKILL.md`: modificado, não commitado (sync de skill, sessão anterior).
- `public/vendas.html`: arquivo novo não rastreado, sem relação com nenhum plano ativo.
- `.DS_Store` / `public/.DS_Store`: lixo do macOS, nunca deve ir para commit.

## Histórico

### Plano 22 (concluído)
Ver `.claude/progress.txt` para os quatro aprendizados duráveis registrados.

### Plano 21 (concluído)
Ver `.claude/progress.txt` para os quatro aprendizados duráveis registrados. Commitado localmente em 9ebd7db, não publicado (push pendente de autorização).

### Plano 20 (concluído)
Nota fiscal de serviço commitada (81 arquivos, +10339/-76) e enviada ao remoto (ddc674a..cac9ca4). Suíte completa (1.192 testes, 10.040 asserções) e build Vite confirmados antes do push.
